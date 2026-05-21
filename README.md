[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=duyler_di&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=duyler_http-server)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=duyler_di&metric=coverage)](https://sonarcloud.io/summary/new_code?id=duyler_http-server)
[![type-coverage](https://shepherd.dev/github/duyler/http-server/coverage.svg)](https://shepherd.dev/github/duyler/http-server)
[![psalm-level](https://shepherd.dev/github/duyler/http-server/level.svg)](https://shepherd.dev/github/duyler/http-server)

# Duyler HTTP Server

Non-blocking HTTP server for Duyler Framework worker mode with full PSR-7 support.

## Features

### Core Features
- **Non-blocking I/O** - Works seamlessly with Duyler Event Bus MainCyclic state
- **PSR-7 Compatible** - Full support for PSR-7 HTTP messages
- **Request ID Mechanism** - Unique request identifiers for parallel processing and request tracking
- **Parallel Processing** - Concurrent request handling with Fibers and out-of-order responses
- **HTTP & HTTPS** - Support for both HTTP and HTTPS protocols
- **WebSocket Support** - RFC 6455 compliant WebSocket implementation with zero-cost abstraction
- **File Upload/Download** - Complete multipart form-data and file streaming support
- **Static Files** - Built-in static file serving with LRU caching
- **Keep-Alive** - HTTP persistent connections support
- **Range Requests** - Partial content support for large file downloads
- **Rate Limiting** - Sliding window rate limiter with configurable limits
- **Graceful Shutdown** - Clean server termination with timeout
- **Server Metrics** - Built-in performance and health monitoring
- **High Performance** - Optimized for long-running worker processes

## Requirements

- PHP 8.4 or higher
- ext-sockets (usually pre-installed)

## Installation

```bash
composer require duyler/http-server
```

## Quick Start

### Basic HTTP Server (Standalone)

```php
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Config\ServerConfig;
use Nyholm\Psr7\Response;

$config = new ServerConfig(
    host: '0.0.0.0',
    port: 8080,
);

$server = new Server($config);

// Check if server started successfully
if (!$server->start()) {
    die('Failed to start HTTP server');
}

// In your event loop
while (true) {
    if ($server->hasRequest()) {
        $requestData = $server->getRequest();
        
        // Check for null (race condition or error)
        if ($requestData === null) {
            continue;
        }
        
        // Process request
        $response = new Response(200, [], 'Hello World!');
        
        // Send response with request binding
        $server->respond($requestData->respond($response));
    }
    
    // Do other work...
}
```

## Configuration

### ServerConfig Options

```php
use Duyler\HttpServer\Config\ServerConfig;

$config = new ServerConfig(
    // Network
    host: '0.0.0.0',              // Bind address (IP or hostname)
    port: 8080,                    // Bind port (1-65535)
    socketBacklog: 511,            // TCP backlog queue size
    maxAcceptsPerCycle: 10,        // Max new connections per event cycle
    
    // SSL/TLS
    ssl: false,                    // Enable HTTPS
    sslCert: null,                 // Path to SSL certificate file
    sslKey: null,                  // Path to SSL private key file
    
    // Static Files
    publicPath: null,              // Path to public directory for static serving
    enableStaticCache: true,       // Enable in-memory static file cache
    staticCacheSize: 52428800,     // Max cache size (50MB)
    
    // Timeouts
    requestTimeout: 30,            // Request timeout in seconds
    connectionTimeout: 60,         // Connection timeout in seconds
    
    // Limits
    maxConnections: 1000,          // Maximum concurrent connections
    maxRequestSize: 10485760,      // Max request body size (10MB)
    bufferSize: 8192,              // Read buffer size in bytes
    headerCacheLimit: 100,         // Max cached header strings
    memoryLimit: 134217728,        // Memory limit in bytes (128MB)
    
    // Keep-Alive
    enableKeepAlive: true,         // Enable HTTP persistent connections
    keepAliveTimeout: 30,          // Keep-alive timeout in seconds
    keepAliveMaxRequests: 100,     // Max requests per keep-alive connection
    
    // Rate Limiting
    enableRateLimit: false,        // Enable rate limiting per IP
    rateLimitRequests: 100,        // Max requests per window
    rateLimitWindow: 60,           // Rate limit window in seconds
    
    // Security (see Security Configuration section for details)
    enableCors: false,
    contentSecurityPolicy: null,
    contentSecurityPolicyReportOnly: null,
    enableCspNonce: false,
    enableHsts: false,
    hstsMaxAge: 31536000,
    hstsIncludeSubDomains: false,
    hstsPreload: false,
    enableSecurityHeaders: true,
    frameOptions: 'DENY',
    referrerPolicy: 'strict-origin-when-cross-origin',
    permissionsPolicy: 'geolocation=(), microphone=(), camera=()',
    
    // Debug
    debugMode: false,              // Enable debug logging mode
);
```

## Security Configuration

### CORS (Cross-Origin Resource Sharing)

```php
$config = new ServerConfig(
    enableCors: true,
    corsAllowedOrigins: ['https://example.com', 'https://app.example.com'],
    corsAllowedMethods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    corsAllowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With'],
    corsAllowCredentials: false,
    corsMaxAge: 86400,
    corsExposeHeaders: ['X-Custom-Header'],
);
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enableCors` | `bool` | `false` | Enable CORS handling |
| `corsAllowedOrigins` | `list<string>` | `[]` | Allowed origin URLs (required when enabled) |
| `corsAllowedMethods` | `list<string>` | `['GET','POST','PUT','DELETE','OPTIONS']` | Allowed HTTP methods |
| `corsAllowedHeaders` | `list<string>` | `['Content-Type','Authorization']` | Allowed request headers |
| `corsAllowCredentials` | `bool` | `false` | Allow cookies/auth headers (incompatible with wildcard origin) |
| `corsMaxAge` | `int` | `86400` | Preflight cache duration in seconds |
| `corsExposeHeaders` | `list<string>` | `[]` | Headers exposed to JavaScript |

### Content Security Policy (CSP)

```php
$config = new ServerConfig(
    contentSecurityPolicy: [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'nonce-{{NONCE}}'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'https:'],
        'connect-src' => ["'self'", 'wss://example.com'],
    ],
    enableCspNonce: true,
);
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `contentSecurityPolicy` | `?array` | `null` | CSP directives as key-value map |
| `contentSecurityPolicyReportOnly` | `?array` | `null` | Report-only CSP directives |
| `enableCspNonce` | `bool` | `false` | Generate per-request CSP nonce (placeholder: `{{NONCE}}`) |

### HTTP Strict Transport Security (HSTS)

```php
$config = new ServerConfig(
    ssl: true,
    enableHsts: true,
    hstsMaxAge: 31536000,
    hstsIncludeSubDomains: true,
    hstsPreload: false,
);
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enableHsts` | `bool` | `false` | Enable HSTS header |
| `hstsMaxAge` | `int` | `31536000` | Max-age in seconds (1 year default) |
| `hstsIncludeSubDomains` | `bool` | `false` | Include all subdomains |
| `hstsPreload` | `bool` | `false` | Opt-in to browser HSTS preload lists |

### Permissions Policy

```php
$config = new ServerConfig(
    permissionsPolicy: 'geolocation=(), microphone=(), camera=(), payment=(self)',
);
```

### Other Security Headers

The server automatically adds these headers when `enableSecurityHeaders` is true (default):

| Header | Default Value |
|--------|---------------|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` (configurable: `DENY` or `SAMEORIGIN`) |
| `X-XSS-Protection` | `1; mode=block` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |

```php
$config = new ServerConfig(
    enableSecurityHeaders: true,
    frameOptions: 'SAMEORIGIN',
    referrerPolicy: 'strict-origin-when-cross-origin',
);
```

## Architecture Overview

### Request Processing Pipeline

```
Client Request
      |
      v
+-------------+     +------------------+     +----------------+
|   Server    |---->|  ConnectionPool  |---->|  HttpParser    |
|  (accept)   |     |  (manage conns)  |     |  (parse HTTP)  |
+-------------+     +------------------+     +----------------+
                                                      |
                                                      v
                         +------------------+     +----------------+
                         |  CorsService     |---->|  RateLimiter   |
                         |  (CORS check)    |     |  (throttle)    |
                         +------------------+     +----------------+
                                                      |
                                                      v
                         +------------------+     +----------------+
                         | SecurityHeaders  |---->|  AuditLogger   |
                         |  (add headers)   |     |  (log request) |
                         +------------------+     +----------------+
                                                      |
                                                      v
                         +------------------+     +----------------+
                         |  RequestQueue    |---->| ResponseSender |
                         |  (enqueue)       |     |  (send)        |
                         +------------------+     +----------------+
                                                      |
                                                      v
                                              Client Response
```

### Component Responsibilities

| Component | Responsibility |
|-----------|---------------|
| `Server` | Entry point. Accepts connections, delegates to processor |
| `HttpRequestProcessor` | Orchestrates request lifecycle: read, parse, security check, enqueue |
| `ConnectionPool` | Manages connection lifecycle, enforces max connections |
| `RequestQueue` | Thread-safe request queue with ID-based response mapping |
| `ResponseSender` | Writes HTTP responses back to client connections |
| `ClientIpResolver` | Resolves real client IP from X-Forwarded-For chain |
| `CorsService` | Validates CORS requests and adds response headers |
| `SecurityHeadersService` | Adds CSP, HSTS, X-Frame-Options, Permissions-Policy |
| `RateLimiter` | Sliding window rate limiting per client IP |
| `AuditLogger` | PSR-3 audit logging for security events |

### ServerInterface Decomposition

```
                    ServerInterface
                         |
         +---------------+---------------+
         |               |               |
RequestLifecycle   ServerLifecycle   Metrics
    Interface          Interface      Interface
         |
WorkerPoolIntegration
      Interface
```

- **RequestLifecycleInterface** -- `hasRequest()`, `getRequest()`, `respond()`, `hasPendingResponse()`
- **ServerLifecycleInterface** -- `start()`, `stop()`, `reset()`, `restart()`, `shutdown()`
- **WorkerPoolIntegrationInterface** -- `setWorkerId()`, `addExternalConnection()`, `enableNotification()`, `getSocketResource()`
- **MetricsInterface** -- `getMetrics()`

### WebSocket Pipeline

```
HTTP Upgrade Request
         |
         v
+------------------+
|    Handshake     | (validate Origin, compute accept key)
+------------------+
         |
         v
+------------------+
| WebSocketHandler | (frame parsing, message dispatch)
+------------------+
         |
         v
+------------------+
| WebSocketServer  | (connection management, broadcast)
+------------------+
         |
    +----+----+
    |         |
    v         v
  onMessage  onClose
    |         |
    v         v
 Connection  Cleanup
```

### Worker Pool Integration

```
+-------------------+
|  Worker Pool      |
|  Master Process   |
+-------------------+
         |
    +----+----+----+
    |         |    |
    v         v    v
+--------+ +--------+ +--------+
|Worker 1| |Worker 2| |Worker N|
| Server | | Server | | Server |
+--------+ +--------+ +--------+
    |         |         |
    +----Shared Socket Pool----+
              |
              v
        Client Requests
```

## Advanced Usage

### HTTPS Server

```php
$config = new ServerConfig(
    host: '0.0.0.0',
    port: 443,
    ssl: true,
    sslCert: '/path/to/certificate.pem',
    sslKey: '/path/to/private-key.pem',
);

$server = new Server($config);
$server->start();
```

### WebSocket Server

```php
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Message;

// Secure by default - origin validation is enabled
$wsConfig = new WebSocketConfig(
    maxMessageSize: 1048576,
    pingInterval: 30,
    // validateOrigin defaults to true for security
    // allowedOrigins defaults to ['*'] - customize for your domains
);

$ws = new WebSocketServer($wsConfig);

$ws->on('connect', function (Connection $conn) {
    echo "New connection: {$conn->getId()}\n";
});

$ws->on('message', function (Connection $conn, Message $message) {
    $data = $message->getJson();
    
    $conn->send([
        'type' => 'echo',
        'data' => $data,
    ]);
});

$ws->on('close', function (Connection $conn, int $code, string $reason) {
    echo "Connection closed: $code\n";
});

$server->attachWebSocket('/ws', $ws);
$server->start();

while (true) {
    if ($server->hasRequest()) {
        $requestData = $server->getRequest();
        
        if ($requestData !== null) {
            $response = new Response(200, [], 'Hello');
            $server->respond($requestData->respond($response));
        }
    }
    
    usleep(1000);
}
```

#### Public WebSocket Endpoints

For public WebSocket endpoints that accept connections from any origin:

```php
// WARNING: This configuration is insecure and exposes your WebSocket
// to CSRF attacks. Only use for truly public endpoints.
$wsConfig = new WebSocketConfig(
    validateOrigin: false, // Explicit opt-out of origin validation
    allowedOrigins: ['*'], // Explicit wildcard
);
```

See `examples/websocket-chat.php` for a complete chat application example.

### Static File Serving

```php
use Duyler\HttpServer\Handler\StaticFileHandler;

$staticHandler = new StaticFileHandler(
    publicPath: '/path/to/public',
    enableCache: true,
    maxCacheSize: 52428800, // 50MB
);

while (true) {
    if ($server->hasRequest()) {
        $request = $server->getRequest();
        
        // Try to serve static file first
        $response = $staticHandler->handle($request->request);
        
        if ($response === null) {
            // Not a static file, handle dynamically
            $response = handleDynamicRequest($request->request);
        }
        
        $server->respond($request->respond($response));
    }
}
```

### File Download

```php
use Duyler\HttpServer\Handler\FileDownloadHandler;

$fileHandler = new FileDownloadHandler();

$response = $fileHandler->download(
    filePath: '/path/to/file.pdf',
    filename: 'document.pdf',
    mimeType: 'application/pdf'
);

$server->respond($requestData->respond($response));
```

### File Upload

```php
// Uploads are automatically parsed from multipart/form-data
$request = $server->getRequest();

$uploadedFiles = $request->getUploadedFiles();

foreach ($uploadedFiles as $field => $file) {
    /** @var \Psr\Http\Message\UploadedFileInterface $file */
    
    if ($file->getError() === UPLOAD_ERR_OK) {
        $file->moveTo('/path/to/uploads/' . $file->getClientFilename());
    }
}
```

### Graceful Shutdown

```php
$server = new Server(new ServerConfig());
$server->start();

// Register shutdown handler
pcntl_signal(SIGTERM, function() use ($server) {
    $success = $server->shutdown(30); // 30 second timeout
    exit($success ? 0 : 1);
});

while (true) {
    if ($server->hasRequest()) {
        $requestData = $server->getRequest();
        $response = new Response(200, [], 'OK');
        $server->respond($requestData->respond($response));
    }
}
```

### Server Metrics

```php
$server = new Server(new ServerConfig());
$server->start();

// Get metrics periodically
$metrics = $server->getMetrics();
// [
//     'uptime_seconds' => 3600,
//     'total_requests' => 10000,
//     'successful_requests' => 9850,
//     'failed_requests' => 150,
//     'active_connections' => 5,
//     'total_connections' => 10050,
//     'closed_connections' => 10045,
//     'timed_out_connections' => 10,
//     'cache_hits' => 8500,
//     'cache_misses' => 1500,
//     'cache_hit_rate' => 85.0,
//     'avg_request_duration_ms' => 12.3,
//     'min_request_duration_ms' => 1.2,
//     'max_request_duration_ms' => 450.5,
//     'requests_per_second' => 2.78,
// ]
```

## API Reference

### Server

#### Methods

- `start(): bool` - Start the server (returns false on failure)
- `stop(): void` - Stop the server
- `shutdown(int $timeout): bool` - Graceful shutdown with timeout
- `reset(): void` - Reset the server state
- `restart(): void` - Restart the server
- `hasRequest(): bool` - Check if there's a pending request (non-blocking)
- `getRequest(): ?RequestData` - Get the next request with unique ID (null if unavailable)
- `hasPendingResponse(): bool` - Check needs respond
- `respond(ResponseData): void` - Send response bound to request via ID
- `getMetrics(): array` - Get server performance metrics
- `setLogger(LoggerInterface)` - Set external Logger
- `attachWebSocket(string $path, WebSocketServer $ws): void` - Attach WebSocketServer
- `addExternalConnection(Socket $clientSocket, array $metadata): void` - Add external connection from Worker Pool
- `getSocketResource(): mixed` - Get socket resource for Event Loop integration
- `setExternalSocketResource(mixed $resource): void` - Set external socket resource for Worker Pool mode
- `enableNotification(): void` - Enable notification socket pair for reactive Event Loop
- `disableNotification(): void` - Disable notification mechanism
- `setEventLoopActive(bool $active): void` - Set Event Loop active flag
- `isEventLoopActive(): bool` - Get Event Loop active flag

### RequestData

```php
final readonly class RequestData
{
    public string $id;                      // Unique request identifier (e.g., "req_1")
    public ServerRequestInterface $request; // PSR-7 server request
    public int $connectionId;               // Internal connection ID
    
    public function respond(ResponseInterface $response): ResponseData;
}
```

### ResponseData

```php
final readonly class ResponseData
{
    public string $requestId;             // ID of the request this response belongs to
    public ResponseInterface $response;   // PSR-7 response object
}
```
### StaticFileHandler

#### Methods

- `handle(ServerRequestInterface): ?ResponseInterface` - Handle static file request
- `getCacheStats(): array` - Get cache statistics
- `clearCache(): void` - Clear the cache

### FileDownloadHandler

#### Methods

- `download(string $filePath, ?string $filename, ?string $mimeType): ResponseInterface`
- `downloadRange(string $filePath, int $start, int $end, ...): ResponseInterface`
- `parseRangeHeader(string $rangeHeader, int $fileSize): ?array`

## Request ID Mechanism

The HTTP Server uses a Request ID mechanism that enables parallel request processing and out-of-order responses.

### Overview

Each HTTP request receives a unique identifier (e.g., `req_1`, `req_2`). This ID binds the request to its response, enabling:

- **Parallel processing** - Multiple requests can be processed concurrently using Fibers
- **Out-of-order responses** - Fast requests don't have to wait for slow ones
- **Request tracking** - Each request can be logged and traced via its unique ID

### How It Works

```
┌─────────────┐
│HTTP Request │
└──────┬──────┘
       │
       ▼
┌─────────────────────────┐
│ getRequest()            │
│                         │
│ Returns: RequestData {  │
│   id: "req_1"           │
│   request: ServerRequest│
│   connectionId: 42      │
│ }                       │
└──────┬──────────────────┘
       │
       ▼
┌─────────────────────────┐
│ Your Application Logic  │
│                         │
│ - Process request       │
│ - Create response       │
└──────┬──────────────────┘
       │
       ▼
┌─────────────────────────┐
│ respond()               │
│                         │
│ $requestData->          │
│   respond($response)    │
│                         │
│ Binds response to       │
│ request via ID          │
└──────┬──────────────────┘
       │
       ▼
┌──────────────┐
│HTTP Response │
└──────────────┘
```

### Basic Usage

```php
while ($server->hasRequest()) {
    $requestData = $server->getRequest();
    
    if ($requestData === null) {
        continue;
    }
    
    // Access request data
    $requestId = $requestData->id;              // "req_1"
    $request = $requestData->request;           // PSR-7 request
    $connectionId = $requestData->connectionId; // Internal ID
    
    // Process request
    $response = new Response(200, [], 'OK');
    
    // Send response (bound to request via ID)
    $server->respond($requestData->respond($response));
}
```

### Parallel Processing

Process multiple requests concurrently using Fibers:

```php
$actors = [];

while (true) {
    if (!$server->hasRequest()) {
        // Resume suspended actors
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
    
    // Create actor (Fiber) for parallel processing
    $fiber = new Fiber(function() use ($server, $requestData): void {
        // Simulate slow operation
        usleep(random_int(100000, 1000000));
        
        $response = new Response(200, [], 'Processed');
        
        // Response can be sent in ANY order
        $server->respond($requestData->respond($response));
    });
    
    $fiber->start();
    $actors[] = $fiber;
}
```

**Benefits:**
- Slow requests don't block fast requests
- Better resource utilization
- Improved throughput for mixed workloads

### Examples

See the following examples for different use cases:

- **Basic usage**: `examples/request-id-basic.php` - Simple sequential processing
- **Parallel processing**: `examples/parallel-processing.php` - Concurrent request handling with Fibers

### Performance

| Metric | Value |
|--------|-------|
| Request ID generation | ~0.01μs (sequential integer) |
| RequestData creation | ~0.1μs |
| Memory per request | ~164 bytes |
| Overhead for 10K requests | ~1.6ms time, ~1.6MB memory |

**Conclusion:** Negligible overhead for production use.

## Reactive Event Loop

HTTP Server supports reactive Event Loop through Notification Socket Pair. Event Loop wakes up only when an HTTP request has been accepted and parsed, without polling overhead.

### Overview

Traditional polling approach wastes CPU cycles checking for requests that may not exist. The Notification Socket Pair provides true reactive behavior:

```
Traditional (Polling):
  Event Loop → hasRequest() → false → sleep → repeat (wastes CPU)

Reactive (Notification):
  Event Loop sleeps → Request arrives → Server notifies → Event Loop wakes up
```

### How It Works

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          Event Loop Process                              │
│                                                                          │
│  EvIo watcher monitors notification socket (sleeps until notified)       │
│                                                                          │
│  notifyRead ◄─────────────────────────────────────────────────────────   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ▲
                                    │ write notification (~1μs)
                                    │
┌─────────────────────────────────────────────────────────────────────────┐
│                              Server                                      │
│                                                                          │
│  1. Accept connection                                                   │
│  2. Read HTTP data                                                      │
│  3. Parse request                                                       │
│  4. Enqueue request → NOTIFY EVENT LOOP                                 │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### Standalone Mode

```php
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Socket;

$config = new ServerConfig(host: '0.0.0.0', port: 8080);
$server = new Server($config);
$server->start();

// Enable notification mechanism
$server->enableNotification();

// Get notification socket for EvIo
$notifySocket = $server->getSocketResource();

$ioWatcher = new EvIo($notifySocket, Ev::READ, function() use ($server): void {
    // Clear notification buffer
    // Non-blocking socket may have no data, suppress expected errors
    $socket = $server->getSocketResource();
    if ($socket instanceof Socket) {
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
            
            $response = new Response(200, [], 'Hello World!');
            $server->respond($requestData->respond($response));
        }
    } finally {
        // Clear active flag
        $server->setEventLoopActive(false);
    }
});

Ev::run();
```

### Best Practices

1. **Always set active flag** - prevents redundant notifications while processing
2. **Clear notification buffer** - read all data from socket when waking up
3. **Process all requests** - use while loop until `hasRequest()` returns false
4. **Use try/finally** - guarantees active flag is cleared even on errors

### Performance

| Metric | Traditional Polling | Reactive Notification |
|--------|--------------------|-----------------------|
| CPU in idle | Periodic wakeups | Zero overhead |
| Wakeup latency | Up to polling interval | ~1μs notification |
| Scalability | Constant overhead | One watcher for any connections |

- **Zero overhead in idle** - Event Loop sleeps until request arrives
- **Minimal overhead** - single socket write (~1μs)
- **Scalability** - one watcher regardless of connection count

## Examples

The `examples/` directory contains various usage examples:

### Basic Examples
- **request-id-basic.php** - Simple HTTP server with Request ID mechanism (beginner-friendly)

### Advanced Examples
- **parallel-processing.php** - Parallel request processing with Fibers and out-of-order responses
- **reactive-event-loop.php** - Reactive Event Loop with Notification Socket Pair
- **evio-integration.php** - EvIo integration with reactive Event Loop

### Feature Examples
- **websocket-chat.php** - WebSocket chat application

## Testing

```bash
# Run all tests
composer test

# Run with coverage (requires Xdebug or pcov)
composer test:coverage

# Run PHPStan
composer phpstan
```

## Performance Tips

1. **Enable Keep-Alive** - Reduces connection overhead for multiple requests
2. **Use Static Cache** - Cache frequently accessed static files in memory
3. **Adjust Buffer Size** - Increase for high-throughput scenarios
4. **Set Appropriate Timeouts** - Balance between responsiveness and resource usage
5. **Limit Max Connections** - Prevent resource exhaustion

## Worker Pool

For production multi-process deployment with process management, load balancing, and IPC, use the separate [duyler/worker-pool](https://github.com/duyler/worker-pool) package. It integrates seamlessly with this HTTP Server.

## Roadmap

### Version 1.0 (In Progress)
- [x] WebSocket - RFC 6455 compliant implementation
- [x] PSR-3 Logger integration
- [x] Notification Socket Pair for reactive Event Loop
- [x] Enhanced documentation

### Future Versions
- [ ] HTTP/2 support
- [ ] gRPC support

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Support

-  [Issue Tracker](https://github.com/duyler/http-server/issues)
-  [Discussions](https://github.com/duyler/http-server/discussions)
-  [Duyler Framework](https://github.com/duyler)

