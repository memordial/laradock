<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\VersionResult;
use Laradock\PHPVersionManager\Models\ValidationResult;
use Laradock\PHPVersionManager\Models\ConsistencyReport;
use Laradock\PHPVersionManager\Models\FallbackResult;

/**
 * Core interface for PHP Version Manager system
 * 
 * Provides central orchestration for all PHP version operations including
 * version selection, environment validation, and fallback management.
 */
interface PHPVersionManagerInterface
{
    /**
     * Set the PHP version for the development environment
     * 
     * @param string $version PHP version (e.g., '8.5', '8.4')
     * @return VersionResult Result of version setting operation
     */
    public function setVersion(string $version): VersionResult;

    /**
     * Validate the current development environment configuration
     * 
     * @return ValidationResult Validation results with errors and warnings
     */
    public function validateEnvironment(): ValidationResult;

    /**
     * Get list of available PHP versions across all container types
     * 
     * @return array List of available PHP versions
     */
    public function getAvailableVersions(): array;

    /**
     * Check consistency across all PHP containers
     * 
     * @return ConsistencyReport Report of version consistency status
     */
    public function checkConsistency(): ConsistencyReport;

    /**
     * Apply fallback strategy for unavailable PHP version
     * 
     * @param string $requestedVersion Originally requested PHP version
     * @return FallbackResult Result of fallback operation
     */
    public function applyFallback(string $requestedVersion): FallbackResult;
}