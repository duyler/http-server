[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=duyler_di&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=duyler_http-server)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=duyler_di&metric=coverage)](https://sonarcloud.io/summary/new_code?id=duyler_http-server)
[![type-coverage](https://shepherd.dev/github/duyler/http-server/coverage.svg)](https://shepherd.dev/github/duyler/http-server)
[![psalm-level](https://shepherd.dev/github/duyler/http-server/level.svg)](https://shepherd.dev/github/duyler/http-server)

# Duyler HTTP Server

Non-blocking HTTP server for Duyler Framework worker mode with full PSR-7 support and integrated Worker Pool.

## Features

### Core Features
- **Non-blocking I/O** - Works seamlessly with Duyler Event Bus MainCyclic state
- **PSR-7 Compatible** - Full support for PSR-7 HTTP messages
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

### Worker Pool Features
- **Process Management** - Fork-based worker processes with auto-restart
- **Load Balancing** - Least Connections and Round Robin algorithms
- **IPC System** - Unix domain sockets with FD passing support
- **Dual Architecture** - FD Passing (Linux) and Shared Socket (Docker/fallback)
- **Auto CPU Detection** - Automatic worker count based on CPU cores
- **Signal Handling** - Graceful shutdown via SIGTERM/SIGINT

## Requirements

- PHP 8.4 or higher
- ext-sockets (usually pre-installed)
- ext-pcntl (for Worker Pool)
- ext-posix (for Worker Pool)

## Installation

```bash
composer require duyler/http-server
```

## Quick Start

### Worker Pool HTTP Server (Recommended for Production)

**Event-Driven Worker Mode**

For long running applications with Event Bus (like Duyler Framework):

```php
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\SharedSocketMaster;
use Duyler\HttpServer\WorkerPool\Worker\EventDrivenWorkerInterface;
use Nyholm\Psr7\Response;

class MyApp implements EventDrivenWorkerInterface
{
    public function run(int $workerId, Server $server): void
    {
        // IMPORTANT: DO NOT call $server->start()!
        // Master manages the socket and passes connections to Server.
        // Server is automatically running in Worker Pool mode.
        
        // Initialize your application ONCE
        $application = new Application($workerId, $server);
        $application->run();
    }
}

$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$workerPoolConfig = WorkerPoolConfig::auto($serverConfig);

$app = new MyApp();

$master = new SharedSocketMaster(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: $app,
);

$master->start();
```

See `examples/event-driven-worker.php` for complete example.

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
        $request = $server->getRequest();
        
        // Check for null (race condition or error)
        if ($request === null) {
            continue;
        }
        
        // Process request
        $response = new Response(200, [], 'Hello World!');
        
        $server->respond($response);
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
    host: '0.0.0.0',              // Bind address
    port: 8080,                    // Bind port
    
    // SSL/TLS
    ssl: false,                    // Enable HTTPS
    sslCert: null,                 // Path to SSL certificate
    sslKey: null,                  // Path to SSL private key
    
    // Static Files
    publicPath: null,              // Path to public directory
    
    // Timeouts
    requestTimeout: 30,            // Request timeout in seconds
    connectionTimeout: 60,         // Connection timeout in seconds
    
    // Limits
    maxConnections: 1000,          // Maximum concurrent connections
    maxRequestSize: 10485760,      // Max request size (10MB)
    bufferSize: 8192,              // Read buffer size
    
    // Keep-Alive
    enableKeepAlive: true,         // Enable persistent connections
    keepAliveTimeout: 30,          // Keep-alive timeout in seconds
    keepAliveMaxRequests: 100,     // Max requests per connection
    
    // Static Cache
    enableStaticCache: true,       // Enable in-memory static file cache
    staticCacheSize: 52428800,     // Max cache size (50MB)
    
    // Rate Limiting
    enableRateLimit: false,        // Enable rate limiting
    rateLimitRequests: 100,        // Max requests per window
    rateLimitWindow: 60,           // Rate limit window in seconds
    
    // Performance
    maxAcceptsPerCycle: 10,        // Max new connections per cycle
    
    // Debug
    debugMode: false,              // Enable debug logging mode
);
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

$wsConfig = new WebSocketConfig(
    maxMessageSize: 1048576,
    pingInterval: 30,
    validateOrigin: false,
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
        $request = $server->getRequest();
        
        if ($request !== null) {
            $response = new Response(200, [], 'Hello');
            $server->respond($response);
        }
    }
    
    usleep(1000);
}
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
        $response = $staticHandler->handle($request);
        
        if ($response === null) {
            // Not a static file, handle dynamically
            $response = handleDynamicRequest($request);
        }
        
        $server->respond($response);
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

$server->respond($response);
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

### Rate Limiting

```php
$config = new ServerConfig(
    enableRateLimit: true,
    rateLimitRequests: 100,     // Max 100 requests
    rateLimitWindow: 60,        // Per 60 seconds (per IP)
);

$server = new Server($config);
$server->start();

// Rate limiting is applied automatically
// Clients exceeding limits receive 429 Too Many Requests
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
        $request = $server->getRequest();
        $response = new Response(200, [], 'OK');
        $server->respond($response);
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
- `getRequest(): ?ServerRequestInterface` - Get the next request (null if unavailable)
- `hasPendingResponse(): bool` - Check needs respond
- `respond(ResponseInterface): void` - Send response for the current request
- `getMetrics(): array` - Get server performance metrics
- `setLogger(LoggerInterface)` - Set external Logger
- `attachWebSocket(string $path, WebSocketServer $ws): void` - Attach WebSocketServer
- `addExternalConnection(Socket $clientSocket, array $metadata): void` - Add external connection from Worker Pool

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

## Roadmap

### Version 1.0 (In Progress)
- [x] Worker Pool - Dual architecture (FD Passing + Shared Socket)
- [x] WebSocket - RFC 6455 compliant implementation
- [x] MasterFactory with auto-detection
- [x] PSR-3 Logger integration
- [x] Worker Pool metrics and monitoring
- [ ] Enhanced documentation

### Future Versions
- [ ] HTTP/2 support
- [ ] gRPC support
- [ ] Advanced Worker Pool features

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Support

-  [Issue Tracker](https://github.com/duyler/http-server/issues)
-  [Discussions](https://github.com/duyler/http-server/discussions)
-  [Duyler Framework](https://github.com/duyler)

