<?php

namespace Laradock\PHPVersionManager\Config;

/**
 * Environment configuration parser for .env files
 * 
 * Handles parsing and updating of Laradock .env configuration files
 * for PHP version management.
 */
class EnvironmentConfig
{
    private string $envPath;
    private array $envVars;

    public function __construct(string $envPath = '.env')
    {
        $this->envPath = $envPath;
        $this->envVars = [];
        $this->load();
    }

    /**
     * Load environment variables from .env file
     * 
     * @return void
     */
    private function load(): void
    {
        if (!file_exists($this->envPath)) {
            return;
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Parse key=value pairs
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $this->envVars[trim($key)] = trim($value);
            }
        }
    }

    /**
     * Get environment variable value
     * 
     * @param string $key Variable name
     * @param string|null $default Default value
     * @return string|null Variable value
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->envVars[$key] ?? $default;
    }

    /**
     * Set environment variable value
     * 
     * @param string $key Variable name
     * @param string $value Variable value
     * @return void
     */
    public function set(string $key, string $value): void
    {
        $this->envVars[$key] = $value;
    }

    /**
     * Get PHP version from environment
     * 
     * @return string PHP version
     */
    public function getPhpVersion(): string
    {
        return $this->get('LARADOCK_PHP_VERSION', '8.4');
    }

    /**
     * Set PHP version in environment
     * 
     * @param string $version PHP version
     * @return void
     */
    public function setPhpVersion(string $version): void
    {
        $this->set('LARADOCK_PHP_VERSION', $version);
        $this->set('WORKSPACE_PHP_VERSION', '${LARADOCK_PHP_VERSION}');
        $this->set('PHP_FPM_VERSION', '${LARADOCK_PHP_VERSION}');
    }

    /**
     * Check if fallback is enabled
     * 
     * @return bool True if fallback is enabled
     */
    public function isFallbackEnabled(): bool
    {
        return $this->get('PHP_FALLBACK_ENABLED', 'true') === 'true';
    }

    /**
     * Get fallback strategy
     * 
     * @return string Fallback strategy
     */
    public function getFallbackStrategy(): string
    {
        return $this->get('PHP_FALLBACK_STRATEGY', 'highest_stable');
    }

    /**
     * Check if version checking is enabled
     * 
     * @return bool True if version checking is enabled
     */
    public function isVersionCheckEnabled(): bool
    {
        return $this->get('PHP_VERSION_CHECK_ENABLED', 'true') === 'true';
    }

    /**
     * Check if update notifications are enabled
     * 
     * @return bool True if update notifications are enabled
     */
    public function areUpdateNotificationsEnabled(): bool
    {
        return $this->get('PHP_UPDATE_NOTIFICATIONS', 'true') === 'true';
    }

    /**
     * Get all environment variables
     * 
     * @return array All environment variables
     */
    public function all(): array
    {
        return $this->envVars;
    }

    /**
     * Update environment variable in .env file while preserving structure
     * 
     * @param string $key Variable name
     * @param string $value Variable value
     * @return bool True if update was successful
     */
    public function updateInFile(string $key, string $value): bool
    {
        if (!file_exists($this->envPath)) {
            return false;
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        $updated = false;
        
        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);
            
            // Skip comments and empty lines
            if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            // Check if this line contains our key
            if (str_contains($trimmedLine, '=')) {
                [$lineKey] = explode('=', $trimmedLine, 2);
                if (trim($lineKey) === $key) {
                    $lines[$index] = "{$key}={$value}";
                    $updated = true;
                    break;
                }
            }
        }
        
        // If key wasn't found, add it at the end
        if (!$updated) {
            $lines[] = "{$key}={$value}";
        }
        
        $content = implode("\n", $lines) . "\n";
        $success = file_put_contents($this->envPath, $content) !== false;
        
        if ($success) {
            $this->envVars[$key] = $value;
        }
        
        return $success;
    }

    /**
     * Get Laradock PHP version (primary version setting)
     * 
     * @return string PHP version
     */
    public function getLaradockPhpVersion(): string
    {
        return $this->get('LARADOCK_PHP_VERSION', $this->get('PHP_VERSION', '8.4'));
    }

    /**
     * Set Laradock PHP version and update related variables
     * 
     * @param string $version PHP version
     * @return bool True if update was successful
     */
    public function setLaradockPhpVersion(string $version): bool
    {
        $success = true;
        
        // Update LARADOCK_PHP_VERSION (primary setting)
        $success &= $this->updateInFile('LARADOCK_PHP_VERSION', $version);
        
        // Update PHP_VERSION for backward compatibility
        $success &= $this->updateInFile('PHP_VERSION', $version);
        
        // Update container-specific versions to reference LARADOCK_PHP_VERSION
        $success &= $this->updateInFile('WORKSPACE_PHP_VERSION', '${LARADOCK_PHP_VERSION}');
        $success &= $this->updateInFile('PHP_FPM_VERSION', '${LARADOCK_PHP_VERSION}');
        
        return $success;
    }

    /**
     * Get workspace PHP version
     * 
     * @return string PHP version
     */
    public function getWorkspacePhpVersion(): string
    {
        $workspaceVersion = $this->get('WORKSPACE_PHP_VERSION', '${LARADOCK_PHP_VERSION}');
        
        // Resolve variable references
        if ($workspaceVersion === '${LARADOCK_PHP_VERSION}' || $workspaceVersion === '${PHP_VERSION}') {
            return $this->getLaradockPhpVersion();
        }
        
        return $workspaceVersion;
    }

    /**
     * Get PHP-FPM version
     * 
     * @return string PHP version
     */
    public function getPhpFpmVersion(): string
    {
        $phpFpmVersion = $this->get('PHP_FPM_VERSION', '${LARADOCK_PHP_VERSION}');
        
        // Resolve variable references
        if ($phpFpmVersion === '${LARADOCK_PHP_VERSION}' || $phpFpmVersion === '${PHP_VERSION}') {
            return $this->getLaradockPhpVersion();
        }
        
        return $phpFpmVersion;
    }

    /**
     * Check if configuration has version conflicts
     * 
     * @return array Array of conflicts found
     */
    public function checkVersionConflicts(): array
    {
        $conflicts = [];
        $laradockVersion = $this->get('LARADOCK_PHP_VERSION');
        $phpVersion = $this->get('PHP_VERSION');
        $workspaceVersion = $this->getWorkspacePhpVersion();
        $phpFpmVersion = $this->getPhpFpmVersion();
        
        // Check if LARADOCK_PHP_VERSION and PHP_VERSION differ (when both are set)
        if ($laradockVersion && $phpVersion && $laradockVersion !== $phpVersion) {
            $conflicts['php_version'] = [
                'expected' => $laradockVersion,
                'actual' => $phpVersion
            ];
        }
        
        if ($workspaceVersion !== $this->getLaradockPhpVersion()) {
            $conflicts['workspace'] = [
                'expected' => $this->getLaradockPhpVersion(),
                'actual' => $workspaceVersion
            ];
        }
        
        if ($phpFpmVersion !== $this->getLaradockPhpVersion()) {
            $conflicts['php-fpm'] = [
                'expected' => $this->getLaradockPhpVersion(),
                'actual' => $phpFpmVersion
            ];
        }
        
        return $conflicts;
    }

    /**
     * Validate .env configuration for PHP version management
     * 
     * @return array Validation results
     */
    public function validateConfiguration(): array
    {
        $results = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => []
        ];
        
        // Check if LARADOCK_PHP_VERSION is set
        if (!$this->get('LARADOCK_PHP_VERSION')) {
            $results['errors'][] = 'LARADOCK_PHP_VERSION is not set';
            $results['valid'] = false;
        }
        
        // Check for version conflicts
        $conflicts = $this->checkVersionConflicts();
        if (!empty($conflicts)) {
            foreach ($conflicts as $container => $conflict) {
                $results['warnings'][] = "Version mismatch in {$container}: expected {$conflict['expected']}, got {$conflict['actual']}";
            }
        }
        
        // Check fallback configuration
        if (!in_array($this->getFallbackStrategy(), ['highest_stable', 'exact_match', 'disabled'])) {
            $results['warnings'][] = 'Invalid fallback strategy: ' . $this->getFallbackStrategy();
        }
        
        return $results;
    }

    /**
     * Save environment variables back to .env file
     * 
     * @return bool True if save was successful
     */
    public function save(): bool
    {
        $content = '';
        foreach ($this->envVars as $key => $value) {
            $content .= "{$key}={$value}\n";
        }

        return file_put_contents($this->envPath, $content) !== false;
    }
}