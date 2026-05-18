# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- `ServerInterface::getSocketResource()` - Get socket resource for Event Loop integration
- `Server::setExternalSocketResource()` - Set external socket resource for Worker Pool mode
- Support for EvIo integration in Worker Pool mode
- Documentation for Event Loop integration
- Example `examples/evio-integration.php` for EvIo usage

### Changed

- `SharedSocketMaster` now passes socket resource to Server
- `CentralizedMaster` now passes Unix socket to Server

### Notes

- For CentralizedMaster mode, EvTimer fallback is recommended (EvIo detects only IPC activity)
