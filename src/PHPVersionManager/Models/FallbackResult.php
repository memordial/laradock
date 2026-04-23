<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Model representing the result of a fallback operation
 * 
 * Contains information about fallback version selection and
 * the reasons for the fallback.
 */
class FallbackResult
{
    public bool $success;
    public string $requestedVersion;
    public string $fallbackVersion;
    public string $message;
    public array $availableVersions;

    public function __construct(
        bool $success,
        string $requestedVersion,
        string $fallbackVersion,
        string $message,
        array $availableVersions = []
    ) {
        $this->success = $success;
        $this->requestedVersion = $requestedVersion;
        $this->fallbackVersion = $fallbackVersion;
        $this->message = $message;
        $this->availableVersions = $availableVersions;
    }

    /**
     * Get formatted fallback message for user display
     * 
     * @return string Formatted fallback message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Check if fallback was applied (version changed)
     * 
     * @return bool True if fallback version differs from original
     */
    public function wasFallbackApplied(): bool
    {
        return $this->success && $this->requestedVersion !== $this->fallbackVersion;
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Fallback result as array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'requestedVersion' => $this->requestedVersion,
            'fallbackVersion' => $this->fallbackVersion,
            'message' => $this->message,
            'availableVersions' => $this->availableVersions
        ];
    }
}