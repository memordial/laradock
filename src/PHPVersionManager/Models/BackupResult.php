<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Result of backup operation
 * 
 * Contains information about the success/failure of backup creation
 * or restoration operations.
 */
class BackupResult
{
    /**
     * Whether the backup operation was successful
     */
    public bool $success;
    
    /**
     * Backup ID
     */
    public string $backupId;
    
    /**
     * Result message
     */
    public string $message;
    
    /**
     * Timestamp of backup operation
     */
    public string $timestamp;

    public function __construct(
        bool $success,
        string $backupId,
        string $message = ''
    ) {
        $this->success = $success;
        $this->backupId = $backupId;
        $this->message = $message;
        $this->timestamp = date('Y-m-d H:i:s');
    }

    /**
     * Check if backup operation was successful
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Get backup summary
     */
    public function getSummary(): string
    {
        $status = $this->success ? 'SUCCESS' : 'FAILED';
        return "[{$status}] Backup {$this->backupId}: {$this->message}";
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'backupId' => $this->backupId,
            'message' => $this->message,
            'timestamp' => $this->timestamp
        ];
    }
}