# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2025-11-24

### Breaking Changes

**This release contains API changes to improve fault-tolerance and application isolation.**

- **Server::start()**: Changed return type from `void` to `bool`
  - Returns `true` on success, `false` on failure (instead of throwing exception)
  - Application continues running even if server fails to start
  
- **Server::getRequest()**: Changed return type from `ServerRequestInterface` to `?ServerRequestInterface`
  - Returns `null` if no request available (instead of throwing exception)
  - Application must check for `null` before using the request

- **Server::hasRequest()**: Now catches all internal exceptions
  - Returns `false` on any error (never throws)
  - Application is fully isolated from server errors

### Migration Guide

```php
// Before (1.1.0):
$server->start(); // Could crash application
$request = $server->getRequest(); // Could crash application

// After (1.2.0):
if (!$server->start()) {
    // Server failed to start, but application continues
    $logger->warning('HTTP server disabled');
}

$request = $server->getRequest();
if ($request === null) {
    // No request or error occurred
    continue;
}
```

### Why These Changes?

The HTTP server runs inside your PHP application. Previous versions could crash the entire application (including Duyler Event Bus) if the server encountered errors. Version 1.2.0 ensures complete fault isolation - server errors never crash your application.

### Added
- **Fault-Tolerance**: Server errors are now fully isolated from the application
- **Graceful Degradation**: Application can continue running even if HTTP server fails
- **Better Error Handling**: All public methods catch and log exceptions internally

### Fixed
- **Critical**: `start()` no longer crashes application on port conflicts or SSL errors
- **Critical**: `hasRequest()` no longer crashes application if server is down
- **Critical**: `getRequest()` no longer crashes application on race conditions

---

## [1.1.0] - 2025-11-24

### Added
- **TempFileManager**: Centralized temporary file management for multipart uploads with automatic cleanup
- **Graceful Shutdown**: `shutdown(int $timeout)` method for graceful server termination
- **Rate Limiting**: Sliding window rate limiter to protect against DoS attacks
- **Server Metrics**: Comprehensive metrics collection (requests, connections, errors, cache, uptime)
- **Constants Class**: Centralized storage for all magic numbers with meaningful names
- **Multipart Boundary Validation**: RFC 2046 compliant boundary validation
- **Accept Limit**: `maxAcceptsPerCycle` configuration to prevent CPU monopolization
- **Connection Pool Thread-Safety**: Race condition protection for Fiber-based environments

### Changed
- **Static File Handler**: Now streams large files instead of loading into memory
- **LRU Cache**: Added file count limit and proper LRU eviction policy
- **Response Writer**: Optimized buffered writing to reduce syscalls and memory usage
- **HTTP Parser**: Added duplicate header validation to prevent HTTP Request Smuggling
- **Error Handling**: Removed all `@` error suppression operators in favor of explicit handling
- **Port Validation**: Improved error messages with formatted output using constants

### Fixed
- **Memory Leak**: Fixed temporary file cleanup in multipart request parser
- **HTTP Request Smuggling**: Added validation against duplicate headers
- **Out of Memory**: Large static files no longer cause OOM errors
- **Cache Limits**: Static file cache now respects both size and file count limits
- **Race Conditions**: Connection pool operations are now atomic and thread-safe
- **CPU Monopolization**: Accept loop no longer blocks other server operations

### Performance
- **Socket Writing**: Optimized to use buffering and reduce syscalls
- **Response Building**: Changed from string concatenation to array-based building
- **Cache Efficiency**: LRU policy ensures optimal memory usage

### Security
- **HTTP Request Smuggling**: Protected against duplicate header attacks
- **Rate Limiting**: Configurable protection against request floods
- **Multipart Validation**: Strict RFC 2046 boundary validation
- **Error Suppression**: All errors now explicitly handled and logged

### Testing
- Test coverage increased from ~60% to 80%+ (258 tests, 644 assertions)
- Added comprehensive unit tests for all new features
- Added integration tests for critical paths
- All tests pass with PHPStan Level 8

### Documentation
- Complete bugfix documentation in `docs/` folder
- Detailed analysis and fixes guide
- Day-by-day completion reports
- Migration guide (UPGRADE.md)

## [1.0.0] - 2025-11-22

### Added
- Non-blocking HTTP/HTTPS server with PSR-7 support
- Static file handler with in-memory caching and HTTP caching (ETag, Last-Modified)
- Keep-Alive persistent connections support
- Global error handler for catching Fatal Errors, uncaught exceptions, and OS signals
- SSL/TLS support for HTTPS
- Request body timeout protection against slowloris attacks
- Configurable server parameters via ServerConfig DTO
- Debug mode for detailed logging control
- Full Docker support with fault tolerance
- PSR-3 logger interface support
- Comprehensive test suite with 97 tests
- PHPStan level 8 compliance with strict rules

### Features
- **Non-blocking I/O**: Utilizes `stream_select()` and `socket_select()` for non-blocking operation
- **PSR-7 Compatible**: Full support for PSR-7 HTTP Message interfaces
- **Performance**: Header caching for large requests, optimized static file serving
- **Security**: Path traversal protection, request size limits, timeout mechanisms
- **Reliability**: Fault-tolerant design, graceful error handling, connection pooling
- **Observability**: PSR-3 logging with debug mode for development

### Requirements
- PHP 8.3 or higher
- ext-sockets (recommended for best performance)
- PSR-7 HTTP Message implementation (nyholm/psr7)
- PSR-3 Logger implementation (optional)

