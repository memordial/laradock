<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\VersionResult;
use Laradock\PHPVersionManager\Models\ValidationResult;
use Laradock\PHPVersionManager\Models\ConsistencyReport;
use Laradock\PHPVersionManager\Models\FallbackResult;
use Laradock\PHPVersionManager\Models\VersionConfiguration;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;
use Laradock\PHPVersionManager\Config\PHPVersionConfig;

/**
 * Core PHP Version Manager implementation
 * 
 * Orchestrates PHP version selection, validation, and fallback management
 * across all Laradock containers.
 */
class PHPVersionManager implements PHPVersionManagerInterface
{
    private VersionValidatorInterface $validator;
    private ContainerRegistryMonitorInterface $registryMonitor;
    private EnvironmentConfig $envConfig;
    private PHPVersionConfig $versionConfig;
    private DockerComposeManager $dockerManager;
    private array $supportedVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];
    private array $containerTypes = ['workspace', 'php-fpm', 'nginx'];

    public function __construct(
        VersionValidatorInterface $validator,
        ContainerRegistryMonitorInterface $registryMonitor,
        ?EnvironmentConfig $envConfig = null,
        ?PHPVersionConfig $versionConfig = null,
        ?DockerComposeManager $dockerManager = null
    ) {
        $this->validator = $validator;
        $this->registryMonitor = $registryMonitor;
        $this->envConfig = $envConfig ?? new EnvironmentConfig();
        $this->versionConfig = $versionConfig ?? new PHPVersionConfig();
        $this->dockerManager = $dockerManager ?? new DockerComposeManager();
    }

    /**
     * Set the PHP version for the development environment
     */
    public function setVersion(string $version): VersionResult
    {
        // Validate the requested version
        if (!$this->validator->validateVersion($version)) {
            return new VersionResult(
                false,
                $version,
                "Invalid PHP version: {$version}. Supported versions: " . implode(', ', $this->supportedVersions)
            );
        }

        // Check availability across all container types
        $availabilityResults = [];
        $allAvailable = true;
        
        foreach ($this->containerTypes as $containerType) {
            $available = $this->registryMonitor->checkAvailability($version, $containerType);
            $availabilityResults[$containerType] = $available;
            
            if (!$available) {
                $allAvailable = false;
            }
        }

        // If not all containers support the version, apply fallback
        if (!$allAvailable) {
            $fallbackResult = $this->applyFallback($version);
            if (!$fallbackResult->success) {
                return new VersionResult(
                    false,
                    $version,
                    "No suitable fallback version found for {$version}"
                );
            }
            
            $actualVersion = $fallbackResult->fallbackVersion;
        } else {
            $actualVersion = $version;
        }

        // Update environment configuration using enhanced methods
        $updateSuccess = $this->envConfig->setLaradockPhpVersion($actualVersion);
        
        if (!$updateSuccess) {
            return new VersionResult(
                false,
                $version,
                "Failed to update .env configuration"
            );
        }
        
        // Generate docker-compose override using enhanced manager
        $this->dockerManager->generateComprehensiveOverride($actualVersion, $this->containerTypes);
        
        // Create container versions array
        $containerVersions = array_fill_keys($this->containerTypes, $actualVersion);

        return new VersionResult(
            true,
            $actualVersion,
            $actualVersion !== $version ? "Applied fallback from {$version} to {$actualVersion}" : "Successfully set PHP version to {$actualVersion}",
            $containerVersions
        );
    }

    /**
     * Validate the current development environment configuration
     */
    public function validateEnvironment(): ValidationResult
    {
        $result = new ValidationResult();
        
        // Get current PHP version from environment using enhanced method
        $currentVersion = $this->envConfig->getLaradockPhpVersion();
        
        if (!$currentVersion) {
            $result->addError('No PHP version configured in environment');
            return $result;
        }

        // Validate version format
        if (!$this->validator->validateVersion($currentVersion)) {
            $result->addError("Invalid PHP version format: {$currentVersion}");
        }

        // Check container compatibility
        $compatibility = $this->validator->checkContainerCompatibility($currentVersion, $this->containerTypes);
        
        foreach ($compatibility as $container => $compatible) {
            if (!$compatible) {
                $result->addError("Container {$container} does not support PHP {$currentVersion}");
            }
        }

        // Check for version consistency using enhanced configuration validation
        $configValidation = $this->envConfig->validateConfiguration();
        
        if (!$configValidation['valid']) {
            foreach ($configValidation['errors'] as $error) {
                $result->addError($error);
            }
        }
        
        foreach ($configValidation['warnings'] as $warning) {
            $result->addWarning($warning);
        }

        // Check for version consistency
        $consistencyReport = $this->checkConsistency();
        if (!$consistencyReport->isConsistent) {
            foreach ($consistencyReport->mismatches as $container => $version) {
                $result->addError("Version mismatch: {$container} uses PHP {$version}, expected PHP {$currentVersion}");
            }
        }

        return $result;
    }

    /**
     * Get list of available PHP versions across all container types
     */
    public function getAvailableVersions(): array
    {
        $availableVersions = [];
        
        foreach ($this->supportedVersions as $version) {
            $isAvailable = true;
            $containerAvailability = [];
            
            foreach ($this->containerTypes as $containerType) {
                $available = $this->registryMonitor->checkAvailability($version, $containerType);
                $containerAvailability[$containerType] = $available;
                
                if (!$available) {
                    $isAvailable = false;
                }
            }
            
            $availableVersions[$version] = [
                'version' => $version,
                'fullyAvailable' => $isAvailable,
                'containerAvailability' => $containerAvailability
            ];
        }
        
        return $availableVersions;
    }

    /**
     * Check consistency across all PHP containers
     */
    public function checkConsistency(): ConsistencyReport
    {
        $currentVersion = $this->envConfig->getLaradockPhpVersion();
        $containerVersions = $this->getContainerVersions();
        
        $mismatches = [];
        
        foreach ($containerVersions as $container => $version) {
            if ($version !== $currentVersion) {
                $mismatches[$container] = $version;
            }
        }
        
        return new ConsistencyReport(
            empty($mismatches),
            $containerVersions,
            $mismatches,
            $currentVersion
        );
    }

    /**
     * Apply fallback strategy for unavailable PHP version
     */
    public function applyFallback(string $requestedVersion): FallbackResult
    {
        // Get fallback priority list (highest to lowest)
        $fallbackPriority = ['8.4', '8.3', '8.2', '8.1'];
        
        // Remove the requested version from fallback options if it exists
        $fallbackPriority = array_filter($fallbackPriority, fn($v) => $v !== $requestedVersion);
        
        foreach ($fallbackPriority as $fallbackVersion) {
            $allAvailable = true;
            
            // Check if this fallback version is available across all containers
            foreach ($this->containerTypes as $containerType) {
                if (!$this->registryMonitor->checkAvailability($fallbackVersion, $containerType)) {
                    $allAvailable = false;
                    break;
                }
            }
            
            if ($allAvailable) {
                return new FallbackResult(
                    true,
                    $requestedVersion,
                    $fallbackVersion,
                    "PHP {$requestedVersion} unavailable, selected highest available stable version {$fallbackVersion}"
                );
            }
        }
        
        return new FallbackResult(
            false,
            $requestedVersion,
            $requestedVersion,
            "No suitable fallback version found for PHP {$requestedVersion}"
        );
    }

    /**
     * Generate docker-compose override file for PHP version
     */
    private function generateDockerComposeOverride(string $version): void
    {
        $overrideContent = $this->buildDockerComposeOverride($version);
        file_put_contents('docker-compose.php-version.yml', $overrideContent);
    }

    /**
     * Build docker-compose override content
     */
    private function buildDockerComposeOverride(string $version): string
    {
        return <<<YAML
version: '3.8'

services:
  workspace:
    build:
      args:
        - LARADOCK_PHP_VERSION={$version}
    environment:
      - PHP_VERSION={$version}
      
  php-fpm:
    build:
      args:
        - LARADOCK_PHP_VERSION={$version}
    environment:
      - PHP_VERSION={$version}
      
  nginx:
    environment:
      - PHP_VERSION={$version}
YAML;
    }

    /**
     * Get current PHP versions from running containers
     */
    private function getContainerVersions(): array
    {
        // Get versions from environment configuration
        return [
            'workspace' => $this->envConfig->getWorkspacePhpVersion(),
            'php-fpm' => $this->envConfig->getPhpFpmVersion(),
            'nginx' => $this->envConfig->getLaradockPhpVersion() // Nginx uses the main version
        ];
    }
}