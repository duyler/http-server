<?php

declare(strict_types=1);

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;

/**
 * Example: HTTP Server with EvIo Integration
 *
 * This example demonstrates how to use EvIo watcher
 * for reactive event loop instead of polling with EvTimer.
 *
 * TWO MODES AVAILABLE:
 *
 * 1. REACTIVE MODE (Recommended) - Uses Notification Socket Pair
 *    - Zero overhead in idle
 *    - Event Loop sleeps until request arrives
 *    - Use enableNotification() to activate
 *
 * 2. LEGACY MODE - Monitors listening socket directly
 *    - May have false wakeups (connection accepted but no data yet)
 *    - Use without enableNotification()
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

// ============================================================
// CHOOSE MODE: Uncomment ONE of the following options
// ============================================================

// OPTION 1: Reactive Mode (Recommended)
// Uses Notification Socket Pair for zero-overhead wakeup
$useReactiveMode = true;

// OPTION 2: Legacy Mode
// Monitors listening socket directly (may have false wakeups)
// $useReactiveMode = false;

// ============================================================

if ($useReactiveMode) {
    echo "Using REACTIVE mode (Notification Socket Pair)\n";
    echo "  - Zero CPU overhead in idle\n";
    echo "  - Wakes up only when request is ready\n";
    echo "\n";

    // Enable notification mechanism
    $server->enableNotification();

    // Get notification socket for EvIo
    $socketResource = $server->getSocketResource();

    if ($socketResource === null) {
        echo "Error: Notification socket not available\n";
        exit(1);
    }

    $ioWatcher = new EvIo(
        $socketResource,
        Ev::READ,
        function (EvIo $watcher, int $revents) use ($server): void {
            // Clear notification buffer
            // Non-blocking socket may have no data, suppress expected errors
            $socket = $server->getSocketResource();
            if ($socket instanceof \Socket) {
                $previousErrorReporting = error_reporting(0);
                socket_read($socket, 4096);
                error_reporting($previousErrorReporting);
            }

            // Set active flag (prevents redundant notifications)
            $server->setEventLoopActive(true);

            try {
                // Process all ready requests
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

                    $response = new Response(
                        200,
                        ['Content-Type' => 'text/plain'],
                        'Hello from reactive EvIo server!',
                    );

                    $server->respond($requestData->respond($response));
                }
            } finally {
                $server->setEventLoopActive(false);
            }
        },
    );
} else {
    echo "Using LEGACY mode (Direct socket monitoring)\n";
    echo "  - May have false wakeups\n";
    echo "  - Consider using reactive mode for production\n";
    echo "\n";

    // Get listening socket for EvIo (legacy mode)
    $socketResource = $server->getSocketResource();

    if ($socketResource === null) {
        echo "Error: Socket resource not available\n";
        exit(1);
    }

    $ioWatcher = new EvIo(
        $socketResource,
        Ev::READ,
        function (EvIo $watcher, int $revents) use ($server): void {
            echo "EvIo callback triggered\n";

            while ($server->hasRequest()) {
                $requestData = $server->getRequest();

                if ($requestData === null) {
                    break;
                }

                echo sprintf(
                    "Request: %s %s\n",
                    $requestData->request->getMethod(),
                    $requestData->request->getUri()->getPath(),
                );

                $response = new Response(
                    200,
                    ['Content-Type' => 'text/plain'],
                    'Hello from EvIo-powered server!',
                );

                $server->respond($requestData->respond($response));
            }
        },
    );
}

$termWatcher = new EvSignal(SIGTERM, function () use ($server, $ioWatcher): void {
    echo "\nShutting down...\n";

    $server->disableNotification();
    $ioWatcher->stop();
    $server->stop();
    Ev::stop(Ev::BREAK_ALL);
});

$intWatcher = new EvSignal(SIGINT, function () use ($server, $ioWatcher): void {
    echo "\nReceived SIGINT, shutting down...\n";

    $server->disableNotification();
    $ioWatcher->stop();
    $server->stop();
    Ev::stop(Ev::BREAK_ALL);
});

echo "Ready to accept connections\n";
echo "Press Ctrl+C to stop\n";

Ev::run();

echo "Server stopped\n";
