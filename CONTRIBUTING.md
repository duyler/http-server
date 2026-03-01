# Contributing to Duyler HTTP Server

Thank you for your interest in contributing to Duyler HTTP Server! This document provides guidelines and instructions for contributing.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Development Setup](#development-setup)
- [Code Standards](#code-standards)
- [Testing Requirements](#testing-requirements)
- [Pull Request Process](#pull-request-process)
- [Reporting Issues](#reporting-issues)

## Code of Conduct

This project adheres to the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## Development Setup

### Prerequisites

- PHP 8.4 or higher
- Docker and Docker Compose
- Make (optional, for convenience)

### Installation

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/http-server.git
   cd http-server
   ```
3. Build Docker image:
   ```bash
   make build
   ```
4. Install dependencies:
   ```bash
   make update
   ```

## Code Standards

### PHP Code Style

This project follows [PER-CS](https://www.php-fig.org/per/coding-style/) coding standard.

Run code style fixer:
```bash
make cs-fix
```

### Static Analysis

This project uses [Psalm](https://psalm.dev/) at level 1.

Run static analysis:
```bash
make psalm
```

### Rector

Run automated refactoring:
```bash
make rector
```

## Testing Requirements

### Running Tests

```bash
make tests
```

### Test Coverage

All new code must have ≥95% test coverage.

Run coverage report:
```bash
make coverage
```

### Test Style

- Use `#[Test]` attribute for test methods
- Use snake_case for test method names
- One assertion per test when possible

```php
#[Test]
public function handles_valid_request(): void
{
    // Arrange
    $server = new Server($config);
    
    // Act
    $result = $server->process($request);
    
    // Assert
    $this->assertSame(200, $result->getStatusCode());
}
```

## Pull Request Process

### Before Submitting

1. Run all quality checks:
   ```bash
   make tests
   make psalm
   make cs-fix
   make rector
   ```
2. Ensure test coverage ≥95%
3. Update documentation if needed
4. Add changelog entry

### PR Requirements

- Clear description of changes
- Tests for new functionality
- Documentation updates
- No breaking changes (or documented)

### PR Checklist

- [ ] Tests pass
- [ ] Psalm level 1 passes
- [ ] PHP-CS-Fixer passes
- [ ] Rector passes
- [ ] Coverage ≥95%
- [ ] Documentation updated
- [ ] Changelog updated

### Review Process

1. Automated checks must pass
2. At least one approval required
3. No unresolved conversations
4. Squash and merge

## Reporting Issues

### Bug Reports

Use the bug report template:

- Clear description
- Steps to reproduce
- Expected behavior
- Actual behavior
- Environment details

### Feature Requests

Use the feature request template:

- Clear description
- Use case
- Proposed solution (optional)

## License

By contributing, you agree that your contributions will be licensed under the MIT License.