# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

