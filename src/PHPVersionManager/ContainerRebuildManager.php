<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\RebuildResult;

/**
 * Container Rebuild Manager for automatic container rebuilds
 * 
 * Handles automatic container rebuilding with data preservation
 * when PHP versions change or fallback strategies are applied.
 */
class ContainerRebuildManager
{
    /**
     * Data backup manager
     */
    private DataBackupManager $backupManager;
    
    /**
     * Docker compose manager
     */
    private DockerComposeManager $dockerManager;
    
    /**
     * Logger for rebuild operations
     */
    private ?object $logger = null;
    
    /**
     * Container types that need rebuilding
     */
    private array $containerTypes = ['workspace', 'php-fpm', 'nginx'];

    public function __construct(
        ?DataBackupManager $backupManager = null,
        ?DockerComposeManager $dockerManager = null
    ) {
        $this->backupManager = $backupManager ?? new DataBackupManager();
        $this->dockerManager = $dockerManager ?? new DockerComposeManager();
    }

    /**
     * Execute automatic container rebuild with data preservation
     * 
     * @param string $newVersion PHP version to rebuild with
     * @param bool $preserveData Whether to preserve existing data
     * @param array $containerTypes Specific containers to rebuild
     * @return RebuildResult Result of rebuild operation
     */
    public function executeRebuild(
        string $newVersion, 
        bool $preserveData = true, 
        ?array $containerTypes = null
    ): RebuildResult {
        $containerTypes = $containerTypes ?? $this->containerTypes;
        $backupId = null;
        
        try {
            $this->logInfo("Starting container rebuild for PHP {$newVersion}");
            
            // Step 1: Create data backup if preservation is requested
            if ($preserveData) {
                $backupId = $this->backupManager->createBackup($containerTypes);
                $this->logInfo("Data backup created with ID: {$backupId}");
            }
            
            // Step 2: Stop running containers
            $this->dockerManager->stopContainers($containerTypes);
            $this->logInfo("Containers stopped successfully");
            
            // Step 3: Generate new docker-compose override
            $this->dockerManager->generateOverride($newVersion, $containerTypes);
            $this->logInfo("Docker compose override generated for PHP {$newVersion}");
            
            // Step 4: Build containers with new version
            $buildResult = $this->dockerManager->buildContainers($containerTypes);
            
            if (!$buildResult->success) {
                throw new \Exception("Container build failed: " . $buildResult->message);
            }
            
            $this->logInfo("Containers built successfully");
            
            // Step 5: Start containers
            $startResult = $this->dockerManager->startContainers($containerTypes);
            
            if (!$startResult->success) {
                throw new \Exception("Container start failed: " . $startResult->message);
            }
            
            $this->logInfo("Containers started successfully");
            
            // Step 6: Restore data if backup was created
            if ($preserveData && $backupId) {
                $restoreResult = $this->backupManager->restoreBackup($backupId, $containerTypes);
                
                if (!$restoreResult->success) {
                    $this->logWarning("Data restore failed: " . $restoreResult->message);
                } else {
                    $this->logInfo("Data restored successfully");
                }
            }
            
            // Step 7: Validate rebuild success
            $validationResult = $this->validateRebuild($newVersion, $containerTypes);
            
            if (!$validationResult->success) {
                throw new \Exception("Rebuild validation failed: " . $validationResult->message);
            }
            
            $this->logInfo("Container rebuild completed successfully");
            
            return new RebuildResult(
                true,
                $newVersion,
                $containerTypes,
                "Container rebuild completed successfully for PHP {$newVersion}",
                $backupId
            );
            
        } catch (\Exception $e) {
            $this->logError("Container rebuild failed: " . $e->getMessage());
            
            // Attempt recovery if backup exists
            if ($preserveData && $backupId) {
                $this->attemptRecovery($backupId, $containerTypes);
            }
            
            return new RebuildResult(
                false,
                $newVersion,
                $containerTypes,
                "Container rebuild failed: " . $e->getMessage(),
                $backupId
            );
        }
    }

    /**
     * Validate that rebuild was successful
     * 
     * @param string $expectedVersion Expected PHP version
     * @param array $containerTypes Containers to validate
     * @return RebuildResult Validation result
     */
    public function validateRebuild(string $expectedVersion, array $containerTypes): RebuildResult
    {
        try {
            foreach ($containerTypes as $containerType) {
                $actualVersion = $this->dockerManager->getContainerPhpVersion($containerType);
                
                if ($actualVersion !== $expectedVersion) {
                    return new RebuildResult(
                        false,
                        $expectedVersion,
                        $containerTypes,
                        "Version mismatch in {$containerType}: expected {$expectedVersion}, got {$actualVersion}"
                    );
                }
            }
            
            // Check if containers are running and healthy
            $healthCheck = $this->dockerManager->checkContainerHealth($containerTypes);
            
            if (!$healthCheck->success) {
                return new RebuildResult(
                    false,
                    $expectedVersion,
                    $containerTypes,
                    "Container health check failed: " . $healthCheck->message
                );
            }
            
            return new RebuildResult(
                true,
                $expectedVersion,
                $containerTypes,
                "Rebuild validation successful"
            );
            
        } catch (\Exception $e) {
            return new RebuildResult(
                false,
                $expectedVersion,
                $containerTypes,
                "Validation failed: " . $e->getMessage()
            );
        }
    }

    /**
     * Check if containers need rebuilding for version change
     * 
     * @param string $newVersion Target PHP version
     * @param array $containerTypes Containers to check
     * @return bool True if rebuild is needed
     */
    public function needsRebuild(string $newVersion, ?array $containerTypes = null): bool
    {
        $containerTypes = $containerTypes ?? $this->containerTypes;
        
        try {
            foreach ($containerTypes as $containerType) {
                $currentVersion = $this->dockerManager->getContainerPhpVersion($containerType);
                
                if ($currentVersion !== $newVersion) {
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            // If we can't determine current version, assume rebuild is needed
            $this->logWarning("Could not determine current PHP version, assuming rebuild needed: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Get estimated rebuild time
     * 
     * @param array $containerTypes Containers to rebuild
     * @return int Estimated time in seconds
     */
    public function getEstimatedRebuildTime(?array $containerTypes = null): int
    {
        $containerTypes = $containerTypes ?? $this->containerTypes;
        
        // Base time estimates per container type (in seconds)
        $timeEstimates = [
            'workspace' => 180, // 3 minutes
            'php-fpm' => 120,   // 2 minutes
            'nginx' => 60       // 1 minute
        ];
        
        $totalTime = 0;
        
        foreach ($containerTypes as $containerType) {
            $totalTime += $timeEstimates[$containerType] ?? 90; // Default 1.5 minutes
        }
        
        // Add overhead for backup/restore operations
        $totalTime += 60; // 1 minute overhead
        
        return $totalTime;
    }

    /**
     * Set logger for rebuild operations
     * 
     * @param object $logger Logger instance
     */
    public function setLogger(object $logger): void
    {
        $this->logger = $logger;
        $this->backupManager->setLogger($logger);
        $this->dockerManager->setLogger($logger);
    }

    /**
     * Attempt recovery from failed rebuild
     * 
     * @param string $backupId Backup ID to restore from
     * @param array $containerTypes Containers to recover
     */
    private function attemptRecovery(string $backupId, array $containerTypes): void
    {
        try {
            $this->logInfo("Attempting recovery from backup {$backupId}");
            
            // Stop any running containers
            $this->dockerManager->stopContainers($containerTypes);
            
            // Restore from backup
            $restoreResult = $this->backupManager->restoreBackup($backupId, $containerTypes);
            
            if ($restoreResult->success) {
                $this->logInfo("Recovery successful");
            } else {
                $this->logError("Recovery failed: " . $restoreResult->message);
            }
            
        } catch (\Exception $e) {
            $this->logError("Recovery attempt failed: " . $e->getMessage());
        }
    }

    /**
     * Log informational messages
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            error_log("ContainerRebuildManager Info: " . $message);
        }
    }

    /**
     * Log warning messages
     */
    private function logWarning(string $message): void
    {
        if ($this->logger) {
            $this->logger->warning($message);
        } else {
            error_log("ContainerRebuildManager Warning: " . $message);
        }
    }

    /**
     * Log error messages
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        } else {
            error_log("ContainerRebuildManager Error: " . $message);
        }
    }
}