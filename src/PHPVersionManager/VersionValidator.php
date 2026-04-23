<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\ValidationResult;

/**
 * Version Validator implementation
 * 
 * Ensures consistency across all PHP containers, validates version availability,
 * and performs pre-flight checks before container builds.
 */
class VersionValidator implements VersionValidatorInterface
{
    private ContainerRegistryMonitorInterface $registryMonitor;
    private array $supportedVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];
    private array $containerTypes = ['workspace', 'php-fpm', 'nginx'];
    private array $versionConstraints = [
        'minimum' => '8.1',
        'maximum' => '8.5',
        'recommended' => '8.4'
    ];

    public function __construct(ContainerRegistryMonitorInterface $registryMonitor)
    {
        $this->registryMonitor = $registryMonitor;
    }

    /**
     * Validate if a PHP version string is valid and supported
     */
    public function validateVersion(string $version): bool
    {
        // Check if version matches supported format (X.Y)
        if (!preg_match('/^\d+\.\d+$/', $version)) {
            return false;
        }

        // Check if version is in supported list
        return in_array($version, $this->supportedVersions, true);
    }

    /**
     * Check container compatibility for a specific PHP version
     */
    public function checkContainerCompatibility(string $version, array $containers): array
    {
        $compatibility = [];
        
        foreach ($containers as $container) {
            // Check if container type is supported
            if (!in_array($container, $this->containerTypes, true)) {
                $compatibility[$container] = false;
                continue;
            }

            // Check if version is available for this container type
            $compatibility[$container] = $this->registryMonitor->checkAvailability($version, $container);
        }
        
        return $compatibility;
    }

    /**
     * Get version constraints for the current Laradock installation
     */
    public function getVersionConstraints(): array
    {
        return $this->versionConstraints;
    }

    /**
     * Validate complete configuration for consistency and compatibility
     */
    public function validateConfiguration(array $config): ValidationResult
    {
        $result = new ValidationResult();
        
        // Check if PHP version is specified
        if (!isset($config['php_version'])) {
            $result->addError('PHP version not specified in configuration');
            return $result;
        }
        
        $phpVersion = $config['php_version'];
        
        // Validate version format and support
        if (!$this->validateVersion($phpVersion)) {
            $result->addError("Invalid or unsupported PHP version: {$phpVersion}");
            return $result;
        }
        
        // Check container compatibility
        $containers = $config['containers'] ?? $this->containerTypes;
        $compatibility = $this->checkContainerCompatibility($phpVersion, $containers);
        
        $incompatibleContainers = [];
        foreach ($compatibility as $container => $compatible) {
            if (!$compatible) {
                $incompatibleContainers[] = $container;
            }
        }
        
        if (!empty($incompatibleContainers)) {
            $result->addError(
                "PHP {$phpVersion} is not available for containers: " . 
                implode(', ', $incompatibleContainers)
            );
        }
        
        // Check for version consistency across containers
        if (isset($config['container_versions'])) {
            $inconsistencies = $this->detectVersionInconsistencies($config['container_versions'], $phpVersion);
            foreach ($inconsistencies as $inconsistency) {
                $result->addError($inconsistency);
            }
        }
        
        // Add warnings for edge cases
        if (version_compare($phpVersion, $this->versionConstraints['recommended'], '>')) {
            $result->addWarning("PHP {$phpVersion} is newer than recommended version {$this->versionConstraints['recommended']}");
        }
        
        return $result;
    }

    /**
     * Detect version inconsistencies across containers
     */
    private function detectVersionInconsistencies(array $containerVersions, string $expectedVersion): array
    {
        $inconsistencies = [];
        
        foreach ($containerVersions as $container => $version) {
            if ($version !== $expectedVersion) {
                $inconsistencies[] = "Version mismatch: {$container} uses PHP {$version}, expected PHP {$expectedVersion}";
            }
        }
        
        return $inconsistencies;
    }

    /**
     * Perform pre-flight validation before container builds
     */
    public function performPreFlightValidation(string $version, ?array $containers = null): ValidationResult
    {
        $containers = $containers ?? $this->containerTypes;
        $result = new ValidationResult();
        
        // Validate version format
        if (!$this->validateVersion($version)) {
            $result->addError("Invalid PHP version format: {$version}");
            return $result;
        }
        
        // Check availability across all required containers
        $compatibility = $this->checkContainerCompatibility($version, $containers);
        $unavailableContainers = [];
        
        foreach ($compatibility as $container => $available) {
            if (!$available) {
                $unavailableContainers[] = $container;
            }
        }
        
        if (!empty($unavailableContainers)) {
            $result->addError(
                "Pre-flight check failed: PHP {$version} images not available for containers: " . 
                implode(', ', $unavailableContainers)
            );
            
            // Suggest fallback versions
            $availableVersions = $this->findAvailableVersionsForContainers($unavailableContainers);
            if (!empty($availableVersions)) {
                $result->addSuggestion(
                    "Consider using one of these available versions: " . 
                    implode(', ', $availableVersions)
                );
            }
        }
        
        return $result;
    }

    /**
     * Find available PHP versions for specific containers
     */
    private function findAvailableVersionsForContainers(array $containers): array
    {
        $availableVersions = [];
        
        foreach ($this->supportedVersions as $version) {
            $allAvailable = true;
            
            foreach ($containers as $container) {
                if (!$this->registryMonitor->checkAvailability($version, $container)) {
                    $allAvailable = false;
                    break;
                }
            }
            
            if ($allAvailable) {
                $availableVersions[] = $version;
            }
        }
        
        return $availableVersions;
    }

    /**
     * Detect configuration conflicts in environment settings
     */
    public function detectConfigurationConflicts(array $envConfig): array
    {
        $conflicts = [];
        
        // Check for conflicting PHP version settings
        $phpVersionKeys = [
            'LARADOCK_PHP_VERSION',
            'WORKSPACE_PHP_VERSION', 
            'PHP_FPM_VERSION',
            'PHP_VERSION'
        ];
        
        $versions = [];
        foreach ($phpVersionKeys as $key) {
            if (isset($envConfig[$key]) && !empty($envConfig[$key])) {
                $versions[$key] = $envConfig[$key];
            }
        }
        
        // Check if all versions are the same
        $uniqueVersions = array_unique($versions);
        if (count($uniqueVersions) > 1) {
            $conflicts[] = [
                'type' => 'version_mismatch',
                'message' => 'Conflicting PHP versions detected in environment configuration',
                'details' => $versions,
                'resolution' => 'Set all PHP version variables to the same value or use LARADOCK_PHP_VERSION only'
            ];
        }
        
        // Check for invalid version formats
        foreach ($versions as $key => $version) {
            if (!$this->validateVersion($version)) {
                $conflicts[] = [
                    'type' => 'invalid_version',
                    'message' => "Invalid PHP version format in {$key}: {$version}",
                    'details' => ['key' => $key, 'value' => $version],
                    'resolution' => "Use a valid PHP version format (e.g., '8.4', '8.5')"
                ];
            }
        }
        
        return $conflicts;
    }
}