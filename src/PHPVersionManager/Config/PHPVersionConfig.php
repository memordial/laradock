<?php

namespace Laradock\PHPVersionManager\Config;

/**
 * Base configuration class for PHP Version Manager
 * 
 * Manages configuration settings for PHP version management including
 * fallback strategies, container types, and validation rules.
 */
class PHPVersionConfig
{
    /**
     * Supported PHP versions in priority order (highest to lowest)
     */
    public const SUPPORTED_VERSIONS = ['8.5', '8.4', '8.3', '8.2', '8.1'];

    /**
     * Container types that require PHP version management
     */
    public const CONTAINER_TYPES = ['workspace', 'php-fpm', 'nginx'];

    /**
     * Default fallback strategy
     */
    public const DEFAULT_FALLBACK_STRATEGY = 'highest_stable';

    /**
     * Available fallback strategies
     */
    public const FALLBACK_STRATEGIES = [
        'highest_stable' => 'Use highest available stable version',
        'exact_match' => 'Only use exact version matches',
        'disabled' => 'Disable fallback functionality'
    ];

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Get default configuration values
     * 
     * @return array Default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'php_version' => '8.4',
            'fallback_enabled' => true,
            'fallback_strategy' => self::DEFAULT_FALLBACK_STRATEGY,
            'version_check_enabled' => true,
            'update_notifications' => true,
            'cache_ttl' => 3600, // 1 hour
            'supported_versions' => self::SUPPORTED_VERSIONS,
            'container_types' => self::CONTAINER_TYPES
        ];
    }

    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Get all configuration values
     * 
     * @return array All configuration
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Get supported PHP versions
     * 
     * @return array Supported versions
     */
    public function getSupportedVersions(): array
    {
        return $this->get('supported_versions', self::SUPPORTED_VERSIONS);
    }

    /**
     * Get container types
     * 
     * @return array Container types
     */
    public function getContainerTypes(): array
    {
        return $this->get('container_types', self::CONTAINER_TYPES);
    }

    /**
     * Check if fallback is enabled
     * 
     * @return bool True if fallback is enabled
     */
    public function isFallbackEnabled(): bool
    {
        return $this->get('fallback_enabled', true);
    }

    /**
     * Get fallback strategy
     * 
     * @return string Fallback strategy
     */
    public function getFallbackStrategy(): string
    {
        return $this->get('fallback_strategy', self::DEFAULT_FALLBACK_STRATEGY);
    }

    /**
     * Validate configuration values
     * 
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        // Validate PHP version
        $phpVersion = $this->get('php_version');
        if (!in_array($phpVersion, $this->getSupportedVersions(), true)) {
            $errors[] = "Unsupported PHP version: {$phpVersion}";
        }

        // Validate fallback strategy
        $strategy = $this->getFallbackStrategy();
        if (!array_key_exists($strategy, self::FALLBACK_STRATEGIES)) {
            $errors[] = "Invalid fallback strategy: {$strategy}";
        }

        // Validate cache TTL
        $cacheTtl = $this->get('cache_ttl');
        if (!is_int($cacheTtl) || $cacheTtl < 0) {
            $errors[] = "Cache TTL must be a non-negative integer";
        }

        return $errors;
    }
}