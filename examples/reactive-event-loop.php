<?php

declare(strict_types=1);

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;

/**
 * Example: Reactive Event Loop with Notification Socket Pair
 *
 * This example demonstrates the recommended approach for Event Loop
 * integration using Notification Socket Pair for zero-overhead wakeup.
 *
 * How it works:
 * 1. Server creates notification socket pair via enableNotification()
 * 2. EvIo monitors notification socket (sleeps until notified)
 * 3. When HTTP request is parsed, Server writes to notification socket
 * 4. EvIo wakes up Event Loop
 * 5. Event Loop processes all ready requests
 *
 * Benefits over traditional polling:
 * - Zero CPU overhead in idle (no periodic wakeups)
 * - ~1μs wakeup latency when request arrives
 * - Single watcher regardless of connection count
 *
 * Requirements:
 * - PHP 8.4+
 * - ext-ev extension
 * - duyler/http-server
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('ev')) {
    echo "Error: ext-ev extension is required for this example\n";
    exit(1);
}

$config = new ServerConfig(
    host: '0.0.0.0',
    port: 8080,
);

$server = new Server($config);

if (!$server->start()) {
    echo "Failed to start server\n";
    exit(1);
}

echo "Server started on http://0.0.0.0:8080\n";
echo "Using Notification Socket Pair for reactive event loop\n";

// Enable notification mechanism
$server->enableNotification();

// Get notification socket for EvIo
$notifySocket = $server->getSocketResource();

if ($notifySocket === null) {
    echo "Error: Notification socket not available\n";
    exit(1);
}

// Create EvIo watcher on notification socket
$ioWatcher = new EvIo(
    $notifySocket,
    Ev::READ,
    function (EvIo $watcher, int $revents) use ($server): void {
        // Step 1: Clear notification buffer
        // Server may have sent multiple notifications, read all at once
        // Non-blocking socket may have no data, suppress expected errors
        $socket = $server->getSocketResource();
        if ($socket instanceof \Socket) {
            $previousErrorReporting = error_reporting(0);
            socket_read($socket, 4096);
            error_reporting($previousErrorReporting);
        }

        // Step 2: Set active flag
        // This prevents Server from sending redundant notifications
        // while we're already processing requests
        $server->setEventLoopActive(true);

        try {
            // Step 3: Process all ready requests
            // Important: loop until hasRequest() returns false
            // Multiple requests may have accumulated
            while ($server->hasRequest()) {
                $requestData = $server->getRequest();

                if ($requestData === null) {
                    break;
                }

                echo sprintf(
                    "[%s] %s %s\n",
                    date('H:i:s'),
                    $requestData->request->getMethod(),
                    $requestData->request->getUri()->getPath(),
                );

                // Process request
                $response = new Response(
                    200,
                    ['Content-Type' => 'text/plain'],
                    'Hello from reactive server!',
                );

                $server->respond($requestData->respond($response));
            }
        } finally {
            // Step 4: Clear active flag
            // Now Server can send notifications again for new requests
            $server->setEventLoopActive(false);
        }
    },
);

// Graceful shutdown handler
$signalWatcher = new EvSignal(SIGTERM, function () use ($server, $ioWatcher): void {
    echo "\nShutting down...\n";

    // Disable notifications first
    $server->disableNotification();

    // Stop watcher
    $ioWatcher->stop();

    // Stop server
    $server->stop();

    Ev::stop(Ev::BREAK_ALL);
});

// Also handle SIGINT (Ctrl+C)
$intWatcher = new EvSignal(SIGINT, function () use ($server, $ioWatcher): void {
    echo "\nReceived SIGINT, shutting down...\n";

    $server->disableNotification();
    $ioWatcher->stop();
    $server->stop();
    Ev::stop(Ev::BREAK_ALL);
});

echo "Ready to accept connections\n";
echo "Press Ctrl+C to stop\n";
echo "\n";
echo "Architecture:\n";
echo "  EvIo monitors notification socket (sleeping)\n";
echo "  Server → writes to socket when request is ready\n";
echo "  EvIo → wakes up → processes all requests → sleeps again\n";
echo "\n";

Ev::run();

echo "Server stopped\n";
