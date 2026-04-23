<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Result of container rebuild operation
 * 
 * Contains information about the success/failure of container rebuild
 * operations including version, affected containers, and backup details.
 */
class RebuildResult
{
    /**
     * Whether the rebuild was successful
     */
    public bool $success;
    
    /**
     * PHP version used for rebuild
     */
    public string $version;
    
    /**
     * Container types that were rebuilt
     */
    public array $containerTypes;
    
    /**
     * Result message
     */
    public string $message;
    
    /**
     * Backup ID if data backup was created
     */
    public ?string $backupId;
    
    /**
     * Timestamp of rebuild operation
     */
    public string $timestamp;

    public function __construct(
        bool $success,
        string $version,
        array $containerTypes = [],
        string $message = '',
        ?string $backupId = null
    ) {
        $this->success = $success;
        $this->version = $version;
        $this->containerTypes = $containerTypes;
        $this->message = $message;
        $this->backupId = $backupId;
        $this->timestamp = date('Y-m-d H:i:s');
    }

    /**
     * Check if rebuild was successful
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Get rebuild summary
     */
    public function getSummary(): string
    {
        $status = $this->success ? 'SUCCESS' : 'FAILED';
        $containers = implode(', ', $this->containerTypes);
        
        return "[{$status}] PHP {$this->version} rebuild for containers: {$containers}";
    }

    /**
     * Check if data backup was created
     */
    public function hasBackup(): bool
    {
        return $this->backupId !== null;
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'version' => $this->version,
            'containerTypes' => $this->containerTypes,
            'message' => $this->message,
            'backupId' => $this->backupId,
            'timestamp' => $this->timestamp
        ];
    }
}