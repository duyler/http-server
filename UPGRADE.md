# Upgrade Guide

## Upgrading from 1.1.0 to 1.2.0

Version 1.2.0 contains **BREAKING CHANGES** to improve fault-tolerance and ensure the HTTP server cannot crash your application.

### Critical Changes

#### 1. Server::start() now returns bool

**Before (1.1.0):**
```php
$server = new Server($config);
$server->start(); // Could throw exception and crash application
```

**After (1.2.0):**
```php
$server = new Server($config);
if (!$server->start()) {
    // Server failed to start, but application continues running
    $logger->warning('HTTP server failed to start', [
        'reason' => 'Port already in use or SSL configuration error'
    ]);
    // You can continue without HTTP, try again later, or take other actions
}
```

**Why?** In Duyler Framework, the HTTP server runs inside your Event Bus worker. If `start()` throws an exception, it kills the entire application. Now the application can handle server startup failures gracefully.

#### 2. Server::getRequest() now returns nullable

**Before (1.1.0):**
```php
if ($server->hasRequest()) {
    $request = $server->getRequest(); // Could throw on race conditions
    $response = handleRequest($request);
    $server->respond($response);
}
```

**After (1.2.0):**
```php
if ($server->hasRequest()) {
    $request = $server->getRequest();
    
    if ($request === null) {
        // Request was consumed by timeout, error, or race condition
        continue;
    }
    
    $response = handleRequest($request);
    $server->respond($response);
}
```

**Why?** Race conditions between `hasRequest()` and `getRequest()` could crash the application. Now errors are handled gracefully.

#### 3. Server::hasRequest() never throws

**Before (1.1.0):**
```php
// Could throw HttpServerException if server not running
if ($server->hasRequest()) {
    // ...
}
```

**After (1.2.0):**
```php
// Always safe - returns false on any error
if ($server->hasRequest()) {
    // ...
}
```

**Why?** Complete isolation. No server error should ever crash your application.

### Complete Migration Example

**Before (1.1.0) - Unsafe:**
```php
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\HttpServerException;

$server = new Server(new ServerConfig(port: 8080));

try {
    $server->start(); // ❌ Can crash application
} catch (HttpServerException $e) {
    // Handle error - but why should application know about server internals?
}

while (true) {
    try {
        if ($server->hasRequest()) { // ❌ Can crash if server dies
            $request = $server->getRequest(); // ❌ Can crash on race condition
            $response = handleRequest($request);
            $server->respond($response);
        }
    } catch (HttpServerException $e) {
        // Application must handle server exceptions
    }
    
    // Other event bus work...
}
```

**After (1.2.0) - Safe:**
```php
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Config\ServerConfig;

$server = new Server(new ServerConfig(port: 8080));

// ✅ Safe - doesn't crash application
if (!$server->start()) {
    $logger->warning('HTTP server disabled - continuing without HTTP');
    // Application continues, can retry later or work without HTTP
}

while (true) {
    // ✅ Always safe - never throws
    if ($server->hasRequest()) {
        $request = $server->getRequest();
        
        // ✅ Check for null
        if ($request !== null) {
            $response = handleRequest($request);
            $server->respond($response);
        }
    }
    
    // ✅ Other event bus work continues regardless of HTTP server issues
}
```

### Updated ServerInterface

```php
interface ServerInterface
{
    public function start(): bool; // Changed from: void
    
    public function getRequest(): ?ServerRequestInterface; // Changed from: ServerRequestInterface
    
    // Unchanged:
    public function hasRequest(): bool; // Already bool, now with internal error handling
    public function stop(): void;
    public function reset(): void;
    public function restart(): bool;
    public function shutdown(int $timeout): bool;
    public function respond(ResponseInterface $response): void;
    public function hasPendingResponse(): bool;
    public function getMetrics(): array;
    public function setLogger(?LoggerInterface $logger): void;
}
```

### What Hasn't Changed

- All configuration options remain the same
- All other methods work identically
- Performance characteristics unchanged
- All features from 1.1.0 still available:
  - Rate limiting
  - Graceful shutdown
  - Server metrics
  - LRU caching
  - All security fixes

### Testing Your Code

1. **Update start() calls:**
   ```bash
   # Find all start() calls
   grep -r "->start()" your-app/
   ```
   
2. **Update getRequest() calls:**
   ```bash
   # Find all getRequest() calls
   grep -r "->getRequest()" your-app/
   ```

3. **Run your tests:**
   ```bash
   ./vendor/bin/phpunit
   ```

4. **Check PHPStan:**
   ```bash
   ./vendor/bin/phpstan analyse
   ```
   PHPStan will show all places where you need to handle the new return types.

### Troubleshooting

#### "Why does my application still crash?"

Make sure you're checking return values:

```php
// ❌ Still crashes
$request = $server->getRequest();
$method = $request->getMethod(); // Fatal if $request is null

// ✅ Safe
$request = $server->getRequest();
if ($request !== null) {
    $method = $request->getMethod();
}
```

#### "Can I still catch exceptions?"

You don't need to anymore! That's the point. But internal errors are still logged via your PSR-3 logger.

#### "What about restart()?"

`restart()` already returned `bool` in 1.1.0, so no changes needed.

### Benefits of 1.2.0

1. **✅ Application Isolation**: Server errors never crash your application
2. **✅ Graceful Degradation**: Application continues even if HTTP fails
3. **✅ Better Monitoring**: All errors are logged, easier to debug
4. **✅ Simpler Code**: No try-catch blocks needed around server calls
5. **✅ Production Ready**: Truly fault-tolerant for long-running workers

### Need Help?

- See `docs/FAULT-TOLERANCE-AUDIT.md` for detailed analysis
- Check examples in `README.md`
- Report issues on GitHub

---

## Upgrading from 1.0.0 to 1.1.0

Version 1.1.0 is a **backward-compatible** bugfix release. No breaking changes to the public API.

### What's New

#### 1. Graceful Shutdown

```php
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Config\ServerConfig;

$server = new Server(new ServerConfig());
$server->start();

// Graceful shutdown with 30 second timeout
$success = $server->shutdown(30);
```

#### 2. Rate Limiting

```php
$config = new ServerConfig(
    enableRateLimit: true,
    rateLimitRequests: 100,      // Max 100 requests
    rateLimitWindow: 60,          // Per 60 seconds
);

$server = new Server($config);
```

#### 3. Server Metrics

```php
$server = new Server(new ServerConfig());
$server->start();

// Get metrics
$metrics = $server->getMetrics();
// [
//     'uptime_seconds' => 3600,
//     'total_requests' => 10000,
//     'successful_requests' => 9850,
//     'failed_requests' => 150,
//     'active_connections' => 5,
//     'cache_hit_rate' => 85.5,
//     'avg_request_duration_ms' => 12.3,
//     'requests_per_second' => 2.78,
//     ...
// ]
```

#### 4. Accept Limit Configuration

```php
$config = new ServerConfig(
    maxAcceptsPerCycle: 10,  // Accept max 10 connections per cycle
);
```

### Behavioral Changes

#### Static File Handling

**Before 1.1.0:**
- Large files loaded entirely into memory
- Could cause OOM errors

**After 1.1.0:**
- Large files streamed directly from disk
- LRU cache eviction prevents memory issues
- Configurable file count limit

No code changes required - works automatically.

#### Multipart File Uploads

**Before 1.1.0:**
- Temporary files might not be cleaned up
- Potential memory leaks

**After 1.1.0:**
- Automatic cleanup on server reset
- `TempFileManager` handles all temp files
- No memory leaks

No code changes required - works automatically.

#### Error Handling

**Before 1.1.0:**
- Some errors suppressed with `@` operator
- Hard to debug socket issues

**After 1.1.0:**
- All errors explicitly handled
- Better error messages
- Easier debugging

No code changes required - better error visibility.

### New Configuration Options

```php
$config = new ServerConfig(
    // Rate limiting (new in 1.1.0)
    enableRateLimit: false,
    rateLimitRequests: 100,
    rateLimitWindow: 60,
    
    // Accept limit (new in 1.1.0)
    maxAcceptsPerCycle: 10,
    
    // Existing options (unchanged)
    host: '0.0.0.0',
    port: 8080,
    maxConnections: 1000,
    requestTimeout: 30,
    connectionTimeout: 60,
    maxRequestSize: 10485760,
    bufferSize: 8192,
    enableKeepAlive: true,
    keepAliveTimeout: 30,
    keepAliveMaxRequests: 100,
    enableStaticCache: true,
    staticCacheSize: 52428800,
    debugMode: false,
);
```

### Security Improvements

#### 1. HTTP Request Smuggling Protection

Automatic - no code changes needed. Server now rejects requests with duplicate headers.

#### 2. Multipart Boundary Validation

Automatic - no code changes needed. Server validates boundaries according to RFC 2046.

#### 3. Rate Limiting

Enable in configuration:

```php
$config = new ServerConfig(
    enableRateLimit: true,
    rateLimitRequests: 100,
    rateLimitWindow: 60,
);
```

### Performance Improvements

All performance improvements are automatic:

- **Socket Writing**: Buffered writes reduce syscalls
- **Response Building**: Optimized string operations
- **Static Files**: Large files streamed efficiently
- **Cache**: LRU eviction prevents memory bloat

Expected improvements:
- 20-30% reduction in memory usage for static files
- 15-20% faster response writing for large responses
- Better CPU utilization under high load

### Testing

Run your existing test suite - no changes needed:

```bash
./vendor/bin/phpunit
```

All 1.0.0 tests should pass without modifications.

### Compatibility

- **PHP Version**: Still requires PHP 8.4+
- **Dependencies**: Same as 1.0.0
- **API**: Fully backward compatible
- **Configuration**: All 1.0.0 configs work in 1.1.0

### Recommended Actions

1. **Update composer.json**:
   ```json
   {
       "require": {
           "duyler/http-server": "^1.1"
       }
   }
   ```

2. **Run composer update**:
   ```bash
   composer update duyler/http-server
   ```

3. **Run tests**:
   ```bash
   ./vendor/bin/phpunit
   ```

4. **Consider enabling rate limiting** for production:
   ```php
   $config = new ServerConfig(
       enableRateLimit: true,
       rateLimitRequests: 1000,
       rateLimitWindow: 60,
   );
   ```

5. **Monitor metrics** in production:
   ```php
   // Periodically log metrics
   $metrics = $server->getMetrics();
   $logger->info('Server metrics', $metrics);
   ```

### Troubleshooting

#### Issue: "Too Many Requests" errors

If you see 429 errors after upgrade, rate limiting might be too strict:

```php
// Increase limits
$config = new ServerConfig(
    enableRateLimit: true,
    rateLimitRequests: 1000,  // Increase from 100
    rateLimitWindow: 60,
);
```

Or disable temporarily:

```php
$config = new ServerConfig(
    enableRateLimit: false,
);
```

#### Issue: Connection warnings in tests

Socket bind warnings in tests are expected for error cases. They don't affect functionality.

### Getting Help

- **Documentation**: See `docs/` folder for detailed guides
- **Issues**: Report on GitHub
- **Changes**: See `CHANGELOG.md` for complete list

### Next Steps

After upgrading to 1.1.0, the next major release (2.0.0) will include:

- HTTP/2 support
- WebSocket support
- Additional performance optimizations

Stay tuned!

