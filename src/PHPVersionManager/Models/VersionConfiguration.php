<?php

namespace Laradock\PHPVersionManager\Models;

use DateTime;

/**
 * Model representing PHP version configuration state
 * 
 * Tracks requested vs actual versions, fallback information,
 * and consistency across containers.
 */
class VersionConfiguration
{
    public string $requestedVersion;
    public string $actualVersion;
    public bool $fallbackApplied;
    public string $fallbackReason;
    public array $containerVersions;
    public DateTime $lastUpdated;

    public function __construct(
        string $requestedVersion,
        ?string $actualVersion = null,
        bool $fallbackApplied = false,
        string $fallbackReason = '',
        array $containerVersions = [],
        ?DateTime $lastUpdated = null
    ) {
        $this->requestedVersion = $requestedVersion;
        $this->actualVersion = $actualVersion ?? $requestedVersion;
        $this->fallbackApplied = $fallbackApplied;
        $this->fallbackReason = $fallbackReason;
        $this->containerVersions = $containerVersions;
        $this->lastUpdated = $lastUpdated ?? new DateTime();
    }

    /**
     * Check if all containers are using consistent PHP versions
     * 
     * @return bool True if all container versions match
     */
    public function isConsistent(): bool
    {
        if (empty($this->containerVersions)) {
            return true;
        }

        $firstVersion = reset($this->containerVersions);
        foreach ($this->containerVersions as $version) {
            if ($version !== $firstVersion) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get fallback information if fallback was applied
     * 
     * @return FallbackInfo|null Fallback details or null if no fallback
     */
    public function getFallbackInfo(): ?FallbackInfo
    {
        if (!$this->fallbackApplied) {
            return null;
        }

        return new FallbackInfo(
            $this->requestedVersion,
            $this->actualVersion,
            $this->fallbackReason
        );
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Configuration as array
     */
    public function toArray(): array
    {
        return [
            'requestedVersion' => $this->requestedVersion,
            'actualVersion' => $this->actualVersion,
            'fallbackApplied' => $this->fallbackApplied,
            'fallbackReason' => $this->fallbackReason,
            'containerVersions' => $this->containerVersions,
            'lastUpdated' => $this->lastUpdated->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Create from array (deserialization)
     * 
     * @param array $data Configuration data
     * @return static New instance from array data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['requestedVersion'],
            $data['actualVersion'] ?? null,
            $data['fallbackApplied'] ?? false,
            $data['fallbackReason'] ?? '',
            $data['containerVersions'] ?? [],
            isset($data['lastUpdated']) ? new DateTime($data['lastUpdated']) : null
        );
    }
}