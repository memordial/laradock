# Laradock PHP Version Manager

A comprehensive PHP Version Manager system for Laradock development environments that provides intelligent version selection, fallback strategies, and consistency validation across all PHP-related containers.

## Features

- **Version Consistency**: Ensures all PHP containers use the same PHP version
- **Intelligent Fallbacks**: Automatically handles unavailable PHP versions gracefully  
- **Seamless Integration**: Works within existing Laradock workflows and .env patterns
- **Developer Experience**: Provides clear feedback and easy version management
- **Future-Proof**: Supports upcoming PHP versions as they become available

## Project Structure

```
src/PHPVersionManager/
├── PHPVersionManagerInterface.php          # Core interface for PHP version management
├── VersionValidatorInterface.php           # Interface for version validation
├── ContainerRegistryMonitorInterface.php   # Interface for registry monitoring
├── Config/
│   ├── PHPVersionConfig.php                # Base configuration class
│   └── EnvironmentConfig.php               # .env file parser and updater
└── Models/
    ├── VersionConfiguration.php            # Version configuration state model
    ├── ContainerAvailability.php           # Container availability model
    ├── ValidationResult.php                # Validation results model
    ├── VersionResult.php                   # Version operation results
    ├── ConsistencyReport.php               # Consistency check results
    ├── FallbackResult.php                  # Fallback operation results
    └── FallbackInfo.php                    # Fallback information model

tests/
├── Unit/                                   # Unit tests for specific functionality
├── Property/                               # Property-based tests using Eris
└── Integration/                            # Integration tests with Docker/registries
```

## Supported PHP Versions

- PHP 8.5 (latest)
- PHP 8.4
- PHP 8.3
- PHP 8.2
- PHP 8.1

## Container Types

- **workspace**: Development tools and CLI access
- **php-fpm**: PHP FastCGI Process Manager
- **nginx**: Web server with PHP integration

## Configuration

The system integrates with Laradock's existing .env configuration:

```bash
# Primary PHP version selection
LARADOCK_PHP_VERSION=8.5

# Fallback behavior configuration  
PHP_FALLBACK_ENABLED=true
PHP_FALLBACK_STRATEGY=highest_stable

# Container-specific overrides (advanced usage)
WORKSPACE_PHP_VERSION=${LARADOCK_PHP_VERSION}
PHP_FPM_VERSION=${LARADOCK_PHP_VERSION}

# Monitoring and notifications
PHP_VERSION_CHECK_ENABLED=true
PHP_UPDATE_NOTIFICATIONS=true
```

## Testing

The project uses a dual testing approach:

### Unit Tests
```bash
./vendor/bin/phpunit tests/Unit/ --testdox
```

### Property-Based Tests
```bash
./vendor/bin/phpunit tests/Property/ --testdox
```

### All Tests
```bash
./vendor/bin/phpunit --testdox
```

## Requirements

- PHP 8.1 or higher
- Composer
- PHPUnit 9.5+ (for Eris compatibility)
- Eris property-based testing library

## Installation

```bash
composer install
```

## Development Status

This is the initial implementation of Task 1: Project structure and core interfaces setup.

### Completed
- ✅ Directory structure for PHP Version Manager components
- ✅ Core interfaces (PHPVersionManagerInterface, VersionValidatorInterface, ContainerRegistryMonitorInterface)
- ✅ PHPUnit testing framework with Eris for property-based testing
- ✅ Base configuration classes and data models
- ✅ Unit tests for configuration and models
- ✅ Property-based tests demonstrating Eris integration

### Next Steps
- Implement PHP Version Manager core system (Task 2)
- Implement Version Validator component (Task 3)
- Implement Container Registry Monitor (Task 5)
- Implement Fallback Strategy Engine (Task 6)

## License

MIT License - see LICENSE file for details.