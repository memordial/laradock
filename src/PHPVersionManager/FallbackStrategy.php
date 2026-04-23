<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\FallbackResult;

/**
 * Fallback Strategy Engine for intelligent PHP version selection
 * 
 * Provides automated fallback mechanisms when requested PHP versions are unavailable,
 * with priority-based selection and automatic container rebuild logic.
 */
class FallbackStrategy
{
    /**
     * Version priority list for fallback selection (highest to lowest priority)
     */
    private array $versionPriority = ['8.4', '8.3', '8.2', '8.1'];
    
    /**
     * Fallback strategy type
     */
    private string $strategy = 'highest_stable';
    
    /**
     * Container constraints for version compatibility
     */
    private array $containerConstraints = [];
    
    /**
     * Container registry monitor for availability checking
     */
    private ContainerRegistryMonitorInterface $registryMonitor;
    
    /**
     * Container rebuild manager for automatic rebuilds
     */
    private ?ContainerRebuildManager $rebuildManager = null;
    
    /**
     * Logger for fallback notifications
     */
    private ?object $logger = null;

    public function __construct(
        ContainerRegistryMonitorInterface $registryMonitor,
        ?array $versionPriority = null,
        string $strategy = 'highest_stable',
        ?ContainerRebuildManager $rebuildManager = null
    ) {
        $this->registryMonitor = $registryMonitor;
        $this->strategy = $strategy;
        $this->rebuildManager = $rebuildManager;
        
        if ($versionPriority !== null) {
            $this->versionPriority = $versionPriority;
        }
    }

    /**
     * Select fallback version based on availability and priority
     * 
     * @param string $requestedVersion Originally requested PHP version
     * @param array $availableVersions Available versions across containers
     * @return string|null Selected fallback version or null if none suitable
     */
    public function selectFallbackVersion(string $requestedVersion, array $availableVersions): ?string
    {
        if ($this->strategy === 'disabled') {
            return null;
        }
        
        // Remove requested version from available options
        $fallbackOptions = array_filter($availableVersions, fn($v) => $v !== $requestedVersion);
        
        if (empty($fallbackOptions)) {
            return null;
        }
        
        switch ($this->strategy) {
            case 'highest_stable':
                return $this->selectHighestStableVersion($fallbackOptions);
                
            case 'exact_match':
                // Only use exact version matches, no fallback
                return null;
                
            default:
                return $this->selectHighestStableVersion($fallbackOptions);
        }
    }

    /**
     * Check if fallback should be applied for given version
     * 
     * @param string $version PHP version to check
     * @return bool True if fallback should be applied
     */
    public function shouldApplyFallback(string $version): bool
    {
        if ($this->strategy === 'disabled') {
            return false;
        }
        
        // Check if version is available across all required containers
        $containerTypes = ['workspace', 'php-fpm', 'nginx'];
        
        foreach ($containerTypes as $containerType) {
            if (!$this->registryMonitor->checkAvailability($version, $containerType)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Apply fallback strategy with automatic container rebuild
     * 
     * @param string $requestedVersion Originally requested PHP version
     * @param bool $autoRebuild Whether to automatically rebuild containers
     * @return FallbackResult Result of fallback operation
     */
    public function applyFallbackWithRebuild(string $requestedVersion, bool $autoRebuild = true): FallbackResult
    {
        $fallbackResult = $this->applyFallback($requestedVersion);
        
        if (!$fallbackResult->success || !$autoRebuild || !$this->rebuildManager) {
            return $fallbackResult;
        }
        
        // Trigger automatic container rebuild with fallback version
        $rebuildSuccess = $this->rebuildManager->executeRebuild($fallbackResult->fallbackVersion, true);
        
        if (!$rebuildSuccess->success) {
            return new FallbackResult(
                false,
                $requestedVersion,
                $fallbackResult->fallbackVersion,
                "Fallback version selected but container rebuild failed: " . $rebuildSuccess->message
            );
        }
        
        return new FallbackResult(
            true,
            $requestedVersion,
            $fallbackResult->fallbackVersion,
            $fallbackResult->message . " (containers rebuilt automatically)"
        );
    }
    public function applyFallback(string $requestedVersion): FallbackResult
    {
        if (!$this->shouldApplyFallback($requestedVersion)) {
            return new FallbackResult(
                false,
                $requestedVersion,
                $requestedVersion,
                "PHP {$requestedVersion} is available, no fallback needed"
            );
        }
        
        // Get available versions for fallback selection
        $availableVersions = $this->getAvailableVersionsForFallback();
        
        $fallbackVersion = $this->selectFallbackVersion($requestedVersion, $availableVersions);
        
        if ($fallbackVersion === null) {
            return new FallbackResult(
                false,
                $requestedVersion,
                $requestedVersion,
                "No suitable fallback version found for PHP {$requestedVersion}"
            );
        }
        
        // Log the fallback decision
        $this->logFallbackDecision($requestedVersion, $fallbackVersion);
        
        return new FallbackResult(
            true,
            $requestedVersion,
            $fallbackVersion,
            "PHP {$requestedVersion} unavailable, selected fallback version {$fallbackVersion}"
        );
    }

    /**
     * Apply fallback strategy and return result
     * 
     * @param string $requestedVersion Originally requested PHP version
     * @return FallbackResult Result of fallback operation
     */

    /**
     * Set version priority list for fallback selection
     * 
     * @param array $priority Array of PHP versions in priority order
     */
    public function setVersionPriority(array $priority): void
    {
        $this->versionPriority = $priority;
    }

    /**
     * Get current version priority list
     * 
     * @return array Version priority list
     */
    public function getVersionPriority(): array
    {
        return $this->versionPriority;
    }

    /**
     * Set fallback strategy type
     * 
     * @param string $strategy Strategy type ('highest_stable', 'exact_match', 'disabled')
     */
    public function setStrategy(string $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * Get current fallback strategy
     * 
     * @return string Current strategy type
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Set container rebuild manager
     * 
     * @param ContainerRebuildManager $rebuildManager Rebuild manager instance
     */
    public function setRebuildManager(ContainerRebuildManager $rebuildManager): void
    {
        $this->rebuildManager = $rebuildManager;
    }

    /**
     * Set logger for fallback notifications
     * 
     * @param object $logger Logger instance
     */
    public function setLogger(object $logger): void
    {
        $this->logger = $logger;
        
        if ($this->rebuildManager) {
            $this->rebuildManager->setLogger($logger);
        }
    }

    /**
     * Select highest stable version from available options
     * 
     * @param array $availableVersions Available PHP versions
     * @return string|null Highest stable version or null
     */
    private function selectHighestStableVersion(array $availableVersions): ?string
    {
        // Filter available versions by priority order
        foreach ($this->versionPriority as $priorityVersion) {
            if (in_array($priorityVersion, $availableVersions)) {
                return $priorityVersion;
            }
        }
        
        // If no priority version is available, return highest available
        if (!empty($availableVersions)) {
            usort($availableVersions, 'version_compare');
            return end($availableVersions);
        }
        
        return null;
    }

    /**
     * Get available versions suitable for fallback
     * 
     * @return array Available PHP versions across all containers
     */
    private function getAvailableVersionsForFallback(): array
    {
        $containerTypes = ['workspace', 'php-fpm', 'nginx'];
        $availableVersions = [];
        
        foreach ($this->versionPriority as $version) {
            $isAvailable = true;
            
            foreach ($containerTypes as $containerType) {
                if (!$this->registryMonitor->checkAvailability($version, $containerType)) {
                    $isAvailable = false;
                    break;
                }
            }
            
            if ($isAvailable) {
                $availableVersions[] = $version;
            }
        }
        
        return $availableVersions;
    }

    /**
     * Log fallback decision for transparency
     * 
     * @param string $requestedVersion Originally requested version
     * @param string $fallbackVersion Selected fallback version
     */
    private function logFallbackDecision(string $requestedVersion, string $fallbackVersion): void
    {
        $message = "Fallback applied: PHP {$requestedVersion} → PHP {$fallbackVersion}";
        
        if ($this->logger) {
            $this->logger->warning($message);
        } else {
            error_log($message);
        }
    }

    /**
     * Log error messages
     * 
     * @param string $message Error message to log
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        } else {
            error_log("FallbackStrategy Error: " . $message);
        }
    }

    /**
     * Log informational messages
     * 
     * @param string $message Info message to log
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            error_log("FallbackStrategy Info: " . $message);
        }
    }
}