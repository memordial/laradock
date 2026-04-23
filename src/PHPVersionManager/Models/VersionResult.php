<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Model representing the result of a version setting operation
 * 
 * Contains information about the success/failure of version changes
 * and any associated configuration updates.
 */
class VersionResult
{
    public bool $success;
    public string $version;
    public string $message;
    public array $containerVersions;
    public ?FallbackInfo $fallbackInfo;

    public function __construct(
        bool $success,
        string $version,
        string $message = '',
        array $containerVersions = [],
        ?FallbackInfo $fallbackInfo = null
    ) {
        $this->success = $success;
        $this->version = $version;
        $this->message = $message;
        $this->containerVersions = $containerVersions;
        $this->fallbackInfo = $fallbackInfo;
    }

    /**
     * Check if all containers have consistent versions
     * 
     * @return bool True if versions are consistent
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
     * Get the workspace container version
     * 
     * @return string|null Workspace version or null if not set
     */
    public function getWorkspaceVersion(): ?string
    {
        return $this->containerVersions['workspace'] ?? null;
    }

    /**
     * Get the PHP-FPM container version
     * 
     * @return string|null PHP-FPM version or null if not set
     */
    public function getPhpFpmVersion(): ?string
    {
        return $this->containerVersions['php-fpm'] ?? null;
    }

    /**
     * Get the Nginx container version
     * 
     * @return string|null Nginx version or null if not set
     */
    public function getNginxVersion(): ?string
    {
        return $this->containerVersions['nginx'] ?? null;
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Version result as array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'version' => $this->version,
            'message' => $this->message,
            'containerVersions' => $this->containerVersions,
            'fallbackInfo' => $this->fallbackInfo?->toArray()
        ];
    }
}