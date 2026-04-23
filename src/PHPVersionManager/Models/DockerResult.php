<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Result of Docker operation
 * 
 * Contains information about the success/failure of Docker commands
 * including build, start, stop, and health check operations.
 */
class DockerResult
{
    /**
     * Whether the Docker operation was successful
     */
    public bool $success;
    
    /**
     * Result message
     */
    public string $message;
    
    /**
     * Command output or additional data
     */
    public array $output;
    
    /**
     * Timestamp of Docker operation
     */
    public string $timestamp;

    public function __construct(
        bool $success,
        string $message = '',
        array $output = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->output = $output;
        $this->timestamp = date('Y-m-d H:i:s');
    }

    /**
     * Check if Docker operation was successful
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Get operation summary
     */
    public function getSummary(): string
    {
        $status = $this->success ? 'SUCCESS' : 'FAILED';
        return "[{$status}] {$this->message}";
    }

    /**
     * Get formatted output
     */
    public function getFormattedOutput(): string
    {
        if (empty($this->output)) {
            return '';
        }
        
        return implode("\n", $this->output);
    }

    /**
     * Check if output contains specific text
     */
    public function outputContains(string $text): bool
    {
        $fullOutput = $this->getFormattedOutput();
        return stripos($fullOutput, $text) !== false;
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'output' => $this->output,
            'timestamp' => $this->timestamp
        ];
    }
}