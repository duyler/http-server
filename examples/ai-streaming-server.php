<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Message;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\Response;

$config = new ServerConfig(
    host: '0.0.0.0',
    port: 8080,
);

$server = new Server($config);

$wsConfig = new WebSocketConfig(
    maxMessageSize: 1048576,
    pingInterval: 30,
    validateOrigin: false,
);

$ws = new WebSocketServer($wsConfig);

$activeStreams = [];

$ws->on('connect', function (Connection $conn) {
    echo "New connection: {$conn->getId()} from {$conn->getRemoteAddress()}\n";
    
    $conn->send([
        'type' => 'welcome',
        'message' => 'Connected to AI Streaming Server',
        'id' => $conn->getId(),
    ]);
});

$ws->on('message', function (Connection $conn, Message $message) use (&$activeStreams) {
    echo "Received message from {$conn->getId()}: {$message->getData()}\n";
    
    $data = $message->getJson();
    
    if ($data === null) {
        $conn->send([
            'type' => 'error',
            'message' => 'Invalid JSON',
        ]);
        return;
    }
    
    if ($data['type'] === 'ai_request') {
        $prompt = $data['prompt'] ?? '';
        
        if (empty($prompt)) {
            $conn->send([
                'type' => 'error',
                'message' => 'Empty prompt',
            ]);
            return;
        }
        
        $streamId = uniqid('stream_', true);
        
        $response = generateAiResponse($prompt);
        
        $activeStreams[$streamId] = [
            'conn' => $conn,
            'prompt' => $prompt,
            'response' => $response,
            'position' => 0,
            'started' => microtime(true),
        ];
        
        $conn->send([
            'type' => 'stream_start',
            'stream_id' => $streamId,
        ]);
        
        echo "Started stream {$streamId} for connection {$conn->getId()}\n";
    }
});

$ws->on('close', function (Connection $conn, int $code, string $reason) use (&$activeStreams) {
    echo "Connection {$conn->getId()} closed: $code - $reason\n";
    
    foreach ($activeStreams as $streamId => $stream) {
        if ($stream['conn']->getId() === $conn->getId()) {
            unset($activeStreams[$streamId]);
            echo "Cancelled stream {$streamId}\n";
        }
    }
});

$ws->on('error', function (Connection $conn, Throwable $error) {
    echo "Error on connection {$conn->getId()}: {$error->getMessage()}\n";
});

function generateAiResponse(string $prompt): string
{
    $responses = [
        'hello' => "Hello! I'm an AI assistant powered by WebSocket streaming. I can help you with various tasks, answer questions, and have conversations. The streaming feature allows me to respond in real-time, word by word, just like a human typing. How can I assist you today?",
        
        'story' => "Once upon a time, in a digital realm far beyond the clouds, there lived an AI assistant. This assistant had a special gift - the ability to communicate through streams of consciousness, delivering thoughts as they formed. Every day, curious humans would visit, asking questions and seeking wisdom. The AI would respond, not all at once, but word by word, creating a natural flow of conversation that felt wonderfully human.",
        
        'help' => "I can help you with many things! Here are some examples: I can answer questions about programming, explain complex concepts, help with creative writing, provide information on various topics, assist with problem-solving, and engage in meaningful conversations. The streaming feature you're experiencing right now makes our interaction feel more natural and responsive. Just ask me anything, and I'll do my best to help!",
        
        'default' => "That's an interesting question! Let me think about it... " . $prompt . " ... Based on my understanding, I'd say that this is a complex topic with many facets. The key thing to remember is that every question deserves thoughtful consideration. In this case, we need to look at multiple perspectives and weigh different factors. The beauty of this streaming approach is that you can see my thoughts develop in real-time, creating a more engaging and interactive experience.",
    ];
    
    $lowerPrompt = strtolower($prompt);
    
    if (str_contains($lowerPrompt, 'hello') || str_contains($lowerPrompt, 'hi') || str_contains($lowerPrompt, 'who are you')) {
        return $responses['hello'];
    }
    
    if (str_contains($lowerPrompt, 'story')) {
        return $responses['story'];
    }
    
    if (str_contains($lowerPrompt, 'help') || str_contains($lowerPrompt, 'what can you')) {
        return $responses['help'];
    }
    
    return $responses['default'];
}

function processActiveStreams(array &$activeStreams): void
{
    foreach ($activeStreams as $streamId => $stream) {
        $conn = $stream['conn'];
        $response = $stream['response'];
        $position = $stream['position'];
        
        $words = preg_split('/(\s+)/', $response, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        if ($position < count($words)) {
            $chunk = $words[$position];
            
            $conn->send([
                'type' => 'stream_chunk',
                'stream_id' => $streamId,
                'chunk' => $chunk,
            ]);
            
            $activeStreams[$streamId]['position']++;
        } else {
            $conn->send([
                'type' => 'stream_end',
                'stream_id' => $streamId,
            ]);
            
            $duration = round((microtime(true) - $stream['started']) * 1000);
            echo "Completed stream {$streamId} in {$duration}ms\n";
            
            unset($activeStreams[$streamId]);
        }
    }
}

$server->attachWebSocket('/ws', $ws);

if (!$server->start()) {
    die("Failed to start server\n");
}

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║  AI Streaming Server Running                           ║\n";
echo "╠════════════════════════════════════════════════════════╣\n";
echo "║  HTTP:      http://localhost:8080                      ║\n";
echo "║  WebSocket: ws://localhost:8080/ws                     ║\n";
echo "║  Chat UI:   Open examples/ai-chat.html in browser      ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";

while (true) {
    if ($server->hasRequest()) {
        $request = $server->getRequest();
        
        if ($request !== null) {
            $path = $request->getUri()->getPath();
            
            if ($path === '/' || $path === '/chat') {
                $html = file_get_contents(__DIR__ . '/ai-chat.html');
                $response = new Response(200, ['Content-Type' => 'text/html'], $html);
                $server->respond($response);
            } else {
                $response = new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
                $server->respond($response);
            }
        }
    }
    
    processActiveStreams($activeStreams);
    
    usleep(50000); // 50ms between iterations (20 words per second)
}

