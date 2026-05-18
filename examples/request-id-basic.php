<?php

declare(strict_types=1);

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;

/**
 * Example: Basic HTTP Server with Request ID Mechanism
 *
 * This is a beginner-friendly example that demonstrates the basic
 * usage of HTTP Server with Request ID mechanism.
 *
 * What is Request ID?
 * -------------------
 *
 * Each HTTP request gets a unique identifier (like "req_1", "req_2").
 * This ID binds the request to its response, enabling:
 *
 * - Parallel processing (multiple requests at once)
 * - Out-of-order responses (fast requests don't wait for slow ones)
 * - Request tracking and logging
 *
 * How to Use
 * ----------
 *
 * Step 1: Get request data
 *   $requestData = $server->getRequest();
 *   // Returns: RequestData { id: "req_1", request: ..., connectionId: ... }
 *
 * Step 2: Process request
 *   $response = new Response(200, [], 'Hello World');
 *
 * Step 3: Send response with request binding
 *   $server->respond($requestData->respond($response));
 *   // OR explicitly:
 *   $server->respond(new ResponseData($requestData->id, $response));
 *
 * Simple Flow Diagram
 * -------------------
 *
 * ┌─────────────┐
 * │ HTTP Request│
 * └──────┬──────┘
 *        │
 *        ▼
 * ┌─────────────────┐
 * │ getRequest()    │
 * │                 │
 * │ Returns:        │
 * │ RequestData {   │
 * │   id: "req_1"   │
 * │   request: ...  │
 * │ }               │
 * └──────┬──────────┘
 *        │
 *        ▼
 * ┌─────────────────┐
 * │ Your Logic      │
 * │                 │
 * │ Process request │
 * │ Create response │
 * └──────┬──────────┘
 *        │
 *        ▼
 * ┌─────────────────────┐
 * │ respond()           │
 * │                     │
 * │ $requestData->      │
 * │   respond($response)│
 * │                     │
 * │ Binds response to   │
 * │ request via ID      │
 * └──────┬──────────────┘
 *        │
 *        ▼
 * ┌─────────────┐
 * │HTTP Response│
 * └─────────────┘
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
echo "\n";
echo "Basic usage example with Request ID mechanism\n";
echo "\n";
echo "Available endpoints:\n";
echo "  GET /           - Hello message\n";
echo "  GET /user/{id}  - User profile\n";
echo "  GET /health     - Health check\n";
echo "\n";
echo "Test with:\n";
echo "  curl http://localhost:8080/\n";
echo "  curl http://localhost:8080/user/123\n";
echo "  curl http://localhost:8080/health\n";
echo "\n";

while (true) {
    if (!$server->hasRequest()) {
        usleep(1000);
        continue;
    }

    $requestData = $server->getRequest();

    if ($requestData === null) {
        continue;
    }

    echo sprintf(
        "[%s] Request %s: %s %s\n",
        date('H:i:s'),
        $requestData->id,
        $requestData->request->getMethod(),
        $requestData->request->getUri()->getPath(),
    );

    $response = handleRequest($requestData->request);

    $server->respond($requestData->respond($response));

    echo sprintf(
        "[%s] Response %s sent: %d\n",
        date('H:i:s'),
        $requestData->id,
        $response->getStatusCode(),
    );
}

function handleRequest(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
{
    $path = $request->getUri()->getPath();
    $method = $request->getMethod();

    if ($method !== 'GET') {
        return new Response(
            405,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Method not allowed']),
        );
    }

    if ($path === '/') {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'message' => 'Hello from HTTP Server!',
                'timestamp' => date('c'),
            ]),
        );
    }

    if ($path === '/health') {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'status' => 'healthy',
                'timestamp' => time(),
            ]),
        );
    }

    if (preg_match('#^/user/(\d+)$#', $path, $matches)) {
        $userId = (int) $matches[1];

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'user' => [
                    'id' => $userId,
                    'name' => 'User ' . $userId,
                    'email' => 'user' . $userId . '@example.com',
                ],
            ]),
        );
    }

    return new Response(
        404,
        ['Content-Type' => 'application/json'],
        json_encode(['error' => 'Not found']),
    );
}
