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

$ws->on('connect', function (Connection $conn) {
    echo "New WebSocket connection: {$conn->getId()} from {$conn->getRemoteAddress()}\n";
    
    $conn->send([
        'type' => 'welcome',
        'message' => 'Welcome to the chat!',
        'id' => $conn->getId(),
    ]);
});

$ws->on('message', function (Connection $conn, Message $message) {
    echo "Received message from {$conn->getId()}: {$message->getData()}\n";
    
    $data = $message->getJson();
    
    if ($data === null) {
        $conn->send([
            'type' => 'error',
            'message' => 'Invalid JSON',
        ]);
        return;
    }
    
    match ($data['type'] ?? null) {
        'join' => handleJoin($conn, $data),
        'chat' => handleChat($conn, $data),
        'leave' => handleLeave($conn, $data),
        default => $conn->send([
            'type' => 'error',
            'message' => 'Unknown message type',
        ]),
    };
});

$ws->on('close', function (Connection $conn, int $code, string $reason) {
    echo "Connection {$conn->getId()} closed: $code - $reason\n";
    
    if ($conn->hasData('username')) {
        $conn->broadcast([
            'type' => 'user_left',
            'username' => $conn->getData('username'),
        ], excludeSelf: true);
    }
});

$ws->on('error', function (Connection $conn, Throwable $error) {
    echo "Error on connection {$conn->getId()}: {$error->getMessage()}\n";
});

function handleJoin(Connection $conn, array $data): void
{
    $username = $data['username'] ?? 'Anonymous';
    
    $conn->setData('username', $username);
    $conn->joinRoom('chat');
    
    $conn->send([
        'type' => 'joined',
        'username' => $username,
        'users_count' => count($conn->getServer()->getRoomConnections('chat')),
    ]);
    
    $conn->broadcast([
        'type' => 'user_joined',
        'username' => $username,
    ], excludeSelf: true);
}

function handleChat(Connection $conn, array $data): void
{
    if (!$conn->hasData('username')) {
        $conn->send([
            'type' => 'error',
            'message' => 'You must join first',
        ]);
        return;
    }
    
    $conn->sendToRoom('chat', [
        'type' => 'message',
        'username' => $conn->getData('username'),
        'text' => $data['text'] ?? '',
        'timestamp' => time(),
    ]);
}

function handleLeave(Connection $conn, array $data): void
{
    if ($conn->hasData('username')) {
        $username = $conn->getData('username');
        $conn->leaveRoom('chat');
        
        $conn->send([
            'type' => 'left',
            'message' => 'You left the chat',
        ]);
        
        $conn->broadcast([
            'type' => 'user_left',
            'username' => $username,
        ], excludeSelf: true);
    }
}

$server->attachWebSocket('/ws', $ws);

$server->start();

echo "WebSocket Chat Server running on http://0.0.0.0:8080\n";
echo "WebSocket endpoint: ws://0.0.0.0:8080/ws\n";

while (true) {
    if ($server->hasRequest()) {
        $request = $server->getRequest();
        
        if ($request !== null) {
            if ($request->getUri()->getPath() === '/') {
                $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>WebSocket Chat</title>
    <style>
        body { font-family: Arial; max-width: 600px; margin: 50px auto; }
        #messages { border: 1px solid #ccc; height: 400px; overflow-y: scroll; padding: 10px; }
        input { padding: 10px; width: 80%; }
        button { padding: 10px; width: 18%; }
    </style>
</head>
<body>
    <h1>WebSocket Chat</h1>
    <div id="messages"></div>
    <input id="username" placeholder="Username" />
    <button onclick="join()">Join</button>
    <br><br>
    <input id="message" placeholder="Message" />
    <button onclick="send()">Send</button>
    <button onclick="leave()">Leave</button>
    
    <script>
        const ws = new WebSocket('ws://localhost:8080/ws');
        const messages = document.getElementById('messages');
        
        ws.onmessage = (e) => {
            const data = JSON.parse(e.data);
            messages.innerHTML += `<p><strong>${data.type}:</strong> ${JSON.stringify(data)}</p>`;
            messages.scrollTop = messages.scrollHeight;
        };
        
        function join() {
            const username = document.getElementById('username').value;
            ws.send(JSON.stringify({ type: 'join', username }));
        }
        
        function send() {
            const text = document.getElementById('message').value;
            ws.send(JSON.stringify({ type: 'chat', text }));
            document.getElementById('message').value = '';
        }
        
        function leave() {
            ws.send(JSON.stringify({ type: 'leave' }));
        }
    </script>
</body>
</html>
HTML;
                
                $response = new Response(200, ['Content-Type' => 'text/html'], $html);
                $server->respond($response);
            } else {
                $response = new Response(404, [], 'Not Found');
                $server->respond($response);
            }
        }
    }
    
    usleep(1000);
}

