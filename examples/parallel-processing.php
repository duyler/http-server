<?php

declare(strict_types=1);

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;

/**
 * Example: Parallel Request Processing with Request ID Mechanism
 *
 * This example demonstrates how to process multiple HTTP requests
 * in parallel using Fibers (actors) with out-of-order responses.
 *
 * Key Concepts:
 *
 * 1. REQUEST ID MECHANISM
 *    - Each request gets unique ID (e.g., "req_1", "req_2")
 *    - Response is bound to request via this ID
 *    - Allows responses in any order (not FIFO)
 *
 * 2. PARALLEL PROCESSING WITH FIBERS
 *    - Each request processed in separate Fiber
 *    - Slow request doesn't block fast requests
 *    - Responses sent immediately when ready
 *
 * 3. OUT-OF-ORDER RESPONSES
 *    - Request 1 (slow): 1.0s processing → Response sent at 1.0s
 *    - Request 2 (fast): 0.1s processing → Response sent at 0.1s ✓
 *    - Request 2 response arrives BEFORE Request 1
 *
 * Architecture:
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                          HTTP Request Arrives                            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *                                    │
 *                                    ▼
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                          Server::getRequest()                            │
 * │                                                                          │
 * │   Returns: RequestData {                                                 │
 * │       id: "req_1",              // Unique request identifier            │
 * │       request: ServerRequest,   // PSR-7 request object                 │
 * │       connectionId: 42          // Internal connection ID               │
 * │   }                                                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *                                    │
 *                                    ▼
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                          Create Actor (Fiber)                            │
 * │                                                                          │
 * │   $fiber = new Fiber(function() use ($server, $requestData): void {     │
 * │       // Simulate variable processing time                              │
 * │       usleep(random_int(100000, 1000000));                             │
 * │                                                                          │
 * │       // Create response                                                │
 * │       $response = new Response(200, [], 'Processed');                   │
 * │                                                                          │
 * │       // Send response (can be out-of-order!)                           │
 * │       $server->respond($requestData->respond($response));               │
 * │   });                                                                    │
 * │                                                                          │
 * │   $fiber->start(); // Start actor                                        │
 * └─────────────────────────────────────────────────────────────────────────┘
 *                                    │
 *                                    ▼
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                          Server::respond()                               │
 * │                                                                          │
 * │   Maps requestId back to connection and sends response                  │
 * │   Order doesn't matter - each response routed correctly                 │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Benefits:
 * - Slow requests don't block fast requests
 * - Better resource utilization
 * - Improved throughput for mixed workloads
 * - Natural backpressure handling
 *
 * Requirements:
 * - PHP 8.4+
 * - duyler/http-server
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
echo "Using PARALLEL PROCESSING with Request ID mechanism\n";
echo "\n";
echo "How it works:\n";
echo "  1. Each request gets unique ID (req_1, req_2, ...)\n";
echo "  2. Request processed in separate Fiber (actor)\n";
echo "  3. Response bound to request via ID\n";
echo "  4. Responses can be sent in ANY order\n";
echo "\n";
echo "Test with:\n";
echo "  curl http://localhost:8080/slow & curl http://localhost:8080/fast\n";
echo "  Response 2 (fast) will arrive before Response 1 (slow)\n";
echo "\n";

$actors = [];

while (true) {
    if (!$server->hasRequest()) {
        foreach ($actors as $key => $fiber) {
            if ($fiber->isTerminated()) {
                unset($actors[$key]);
                continue;
            }

            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        usleep(1000);
        continue;
    }

    $requestData = $server->getRequest();

    if ($requestData === null) {
        continue;
    }

    echo sprintf(
        "[%s] Request %s received: %s %s\n",
        date('H:i:s'),
        $requestData->id,
        $requestData->request->getMethod(),
        $requestData->request->getUri()->getPath(),
    );

    $fiber = new Fiber(function () use ($server, $requestData): void {
        $path = $requestData->request->getUri()->getPath();

        if ($path === '/slow') {
            $delay = 1000000;
            $message = 'Slow request processed';
        } elseif ($path === '/fast') {
            $delay = 100000;
            $message = 'Fast request processed';
        } else {
            $delay = random_int(100000, 500000);
            $message = 'Request processed';
        }

        usleep($delay);

        $response = new Response(
            200,
            ['Content-Type' => 'text/plain'],
            $message . ' (request ID: ' . $requestData->id . ')',
        );

        $server->respond($requestData->respond($response));

        echo sprintf(
            "[%s] Response %s sent (processing time: %.2fs)\n",
            date('H:i:s'),
            $requestData->id,
            $delay / 1000000,
        );
    });

    $fiber->start();
    $actors[] = $fiber;
}
