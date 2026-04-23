<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\ValidationResult;

/**
 * Interface for PHP version validation and compatibility checking
 * 
 * Ensures consistency across all PHP containers and validates version
 * availability before builds.
 */
interface VersionValidatorInterface
{
    /**
     * Validate if a PHP version string is valid and supported
     * 
     * @param string $version PHP version to validate
     * @return bool True if version is valid
     */
    public function validateVersion(string $version): bool;

    /**
     * Check container compatibility for a specific PHP version
     * 
     * @param string $version PHP version to check
     * @param array $containers List of container types to check
     * @return array Compatibility results per container
     */
    public function checkContainerCompatibility(string $version, array $containers): array;

    /**
     * Get version constraints for the current Laradock installation
     * 
     * @return array Version constraints and requirements
     */
    public function getVersionConstraints(): array;

    /**
     * Validate complete configuration for consistency and compatibility
     * 
     * @param array $config Configuration array to validate
     * @return ValidationResult Validation results with detailed feedback
     */
    public function validateConfiguration(array $config): ValidationResult;
}