<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Model representing fallback operation information
 * 
 * Contains details about version fallback when requested versions
 * are unavailable.
 */
class FallbackInfo
{
    public string $requestedVersion;
    public string $fallbackVersion;
    public string $reason;

    public function __construct(
        string $requestedVersion,
        string $fallbackVersion,
        string $reason
    ) {
        $this->requestedVersion = $requestedVersion;
        $this->fallbackVersion = $fallbackVersion;
        $this->reason = $reason;
    }

    /**
     * Get formatted fallback message for user display
     * 
     * @return string Formatted fallback message
     */
    public function getMessage(): string
    {
        return sprintf(
            'PHP %s unavailable, using PHP %s instead. Reason: %s',
            $this->requestedVersion,
            $this->fallbackVersion,
            $this->reason
        );
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Fallback info as array
     */
    public function toArray(): array
    {
        return [
            'requestedVersion' => $this->requestedVersion,
            'fallbackVersion' => $this->fallbackVersion,
            'reason' => $this->reason
        ];
    }
}