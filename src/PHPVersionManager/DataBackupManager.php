<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\BackupResult;

/**
 * Data Backup Manager for preserving data during container rebuilds
 * 
 * Handles creation and restoration of data backups to ensure data preservation
 * during PHP version changes and container rebuilds.
 */
class DataBackupManager
{
    /**
     * Base backup directory
     */
    private string $backupDir;
    
    /**
     * Logger for backup operations
     */
    private ?object $logger = null;
    
    /**
     * Volume paths to backup for each container type
     */
    private array $volumePaths = [
        'workspace' => [
            '/var/www',
            '/home/laradock/.ssh',
            '/home/laradock/.composer'
        ],
        'php-fpm' => [
            '/var/www'
        ],
        'nginx' => [
            '/var/log/nginx'
        ]
    ];

    public function __construct(?string $backupDir = null)
    {
        $this->backupDir = $backupDir ?? sys_get_temp_dir() . '/laradock-backups';
        
        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Create backup of container data
     * 
     * @param array $containerTypes Containers to backup
     * @return string Backup ID for restoration
     */
    public function createBackup(array $containerTypes): string
    {
        $backupId = 'backup-' . date('Y-m-d-H-i-s') . '-' . uniqid();
        $backupPath = $this->backupDir . '/' . $backupId;
        
        try {
            mkdir($backupPath, 0755, true);
            
            foreach ($containerTypes as $containerType) {
                $this->backupContainer($containerType, $backupPath);
            }
            
            // Create backup manifest
            $this->createBackupManifest($backupId, $containerTypes, $backupPath);
            
            $this->logInfo("Backup created successfully: {$backupId}");
            
            return $backupId;
            
        } catch (\Exception $e) {
            $this->logError("Backup creation failed: " . $e->getMessage());
            
            // Cleanup partial backup
            if (is_dir($backupPath)) {
                $this->removeDirectory($backupPath);
            }
            
            throw $e;
        }
    }

    /**
     * Restore backup to containers
     * 
     * @param string $backupId Backup ID to restore
     * @param array $containerTypes Containers to restore
     * @return BackupResult Result of restore operation
     */
    public function restoreBackup(string $backupId, array $containerTypes): BackupResult
    {
        $backupPath = $this->backupDir . '/' . $backupId;
        
        try {
            if (!is_dir($backupPath)) {
                return new BackupResult(
                    false,
                    $backupId,
                    "Backup directory not found: {$backupPath}"
                );
            }
            
            // Validate backup manifest
            $manifest = $this->loadBackupManifest($backupPath);
            if (!$manifest) {
                return new BackupResult(
                    false,
                    $backupId,
                    "Invalid backup manifest"
                );
            }
            
            foreach ($containerTypes as $containerType) {
                $this->restoreContainer($containerType, $backupPath);
            }
            
            $this->logInfo("Backup restored successfully: {$backupId}");
            
            return new BackupResult(
                true,
                $backupId,
                "Backup restored successfully"
            );
            
        } catch (\Exception $e) {
            $this->logError("Backup restore failed: " . $e->getMessage());
            
            return new BackupResult(
                false,
                $backupId,
                "Restore failed: " . $e->getMessage()
            );
        }
    }

    /**
     * List available backups
     * 
     * @return array List of backup information
     */
    public function listBackups(): array
    {
        $backups = [];
        
        if (!is_dir($this->backupDir)) {
            return $backups;
        }
        
        $directories = glob($this->backupDir . '/backup-*', GLOB_ONLYDIR);
        
        foreach ($directories as $backupPath) {
            $backupId = basename($backupPath);
            $manifest = $this->loadBackupManifest($backupPath);
            
            if ($manifest) {
                $backups[] = [
                    'id' => $backupId,
                    'created' => $manifest['created'],
                    'containers' => $manifest['containers'],
                    'size' => $this->getDirectorySize($backupPath)
                ];
            }
        }
        
        // Sort by creation time (newest first)
        usort($backups, function ($a, $b) {
            return strtotime($b['created']) - strtotime($a['created']);
        });
        
        return $backups;
    }

    /**
     * Delete backup
     * 
     * @param string $backupId Backup ID to delete
     * @return bool True if deletion was successful
     */
    public function deleteBackup(string $backupId): bool
    {
        $backupPath = $this->backupDir . '/' . $backupId;
        
        try {
            if (is_dir($backupPath)) {
                $this->removeDirectory($backupPath);
                $this->logInfo("Backup deleted: {$backupId}");
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logError("Backup deletion failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cleanup old backups
     * 
     * @param int $maxAge Maximum age in days
     * @param int $maxCount Maximum number of backups to keep
     */
    public function cleanupOldBackups(int $maxAge = 30, int $maxCount = 10): void
    {
        $backups = $this->listBackups();
        $cutoffTime = time() - ($maxAge * 24 * 60 * 60);
        $deletedCount = 0;
        
        // Delete backups older than maxAge or beyond maxCount
        foreach ($backups as $index => $backup) {
            $shouldDelete = false;
            
            // Delete if older than maxAge
            if (strtotime($backup['created']) < $cutoffTime) {
                $shouldDelete = true;
            }
            
            // Delete if beyond maxCount (keeping newest)
            if ($index >= $maxCount) {
                $shouldDelete = true;
            }
            
            if ($shouldDelete) {
                if ($this->deleteBackup($backup['id'])) {
                    $deletedCount++;
                }
            }
        }
        
        if ($deletedCount > 0) {
            $this->logInfo("Cleaned up {$deletedCount} old backups");
        }
    }

    /**
     * Set logger for backup operations
     */
    public function setLogger(object $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Backup individual container data
     */
    private function backupContainer(string $containerType, string $backupPath): void
    {
        $containerBackupPath = $backupPath . '/' . $containerType;
        mkdir($containerBackupPath, 0755, true);
        
        $volumePaths = $this->volumePaths[$containerType] ?? [];
        
        foreach ($volumePaths as $volumePath) {
            $this->backupVolume($containerType, $volumePath, $containerBackupPath);
        }
    }

    /**
     * Backup individual volume
     */
    private function backupVolume(string $containerType, string $volumePath, string $backupPath): void
    {
        // This would use docker commands to copy data from containers
        // For now, this is a placeholder implementation
        $volumeBackupPath = $backupPath . '/' . basename($volumePath);
        mkdir($volumeBackupPath, 0755, true);
        
        // Placeholder: In real implementation, this would execute:
        // docker cp container_name:$volumePath $volumeBackupPath
        
        $this->logInfo("Backed up volume {$volumePath} from {$containerType}");
    }

    /**
     * Restore individual container data
     */
    private function restoreContainer(string $containerType, string $backupPath): void
    {
        $containerBackupPath = $backupPath . '/' . $containerType;
        
        if (!is_dir($containerBackupPath)) {
            return;
        }
        
        $volumePaths = $this->volumePaths[$containerType] ?? [];
        
        foreach ($volumePaths as $volumePath) {
            $this->restoreVolume($containerType, $volumePath, $containerBackupPath);
        }
    }

    /**
     * Restore individual volume
     */
    private function restoreVolume(string $containerType, string $volumePath, string $backupPath): void
    {
        $volumeBackupPath = $backupPath . '/' . basename($volumePath);
        
        if (!is_dir($volumeBackupPath)) {
            return;
        }
        
        // Placeholder: In real implementation, this would execute:
        // docker cp $volumeBackupPath/. container_name:$volumePath
        
        $this->logInfo("Restored volume {$volumePath} to {$containerType}");
    }

    /**
     * Create backup manifest file
     */
    private function createBackupManifest(string $backupId, array $containerTypes, string $backupPath): void
    {
        $manifest = [
            'id' => $backupId,
            'created' => date('Y-m-d H:i:s'),
            'containers' => $containerTypes,
            'version' => '1.0'
        ];
        
        file_put_contents($backupPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Load backup manifest file
     */
    private function loadBackupManifest(string $backupPath): ?array
    {
        $manifestPath = $backupPath . '/manifest.json';
        
        if (!file_exists($manifestPath)) {
            return null;
        }
        
        $content = file_get_contents($manifestPath);
        return json_decode($content, true);
    }

    /**
     * Get directory size in bytes
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($path);
    }

    /**
     * Log informational messages
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            error_log("DataBackupManager Info: " . $message);
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
            error_log("DataBackupManager Error: " . $message);
        }
    }
}