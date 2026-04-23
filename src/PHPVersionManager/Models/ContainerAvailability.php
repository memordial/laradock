<?php

namespace Laradock\PHPVersionManager\Models;

use DateTime;

/**
 * Model representing container availability information
 * 
 * Tracks available PHP versions for specific container types
 * and provides version matching capabilities.
 */
class ContainerAvailability
{
    public string $containerType;
    public array $availableVersions;
    public string $latestVersion;
    public DateTime $lastChecked;
    public bool $isOnline;

    public function __construct(
        string $containerType,
        array $availableVersions = [],
        string $latestVersion = '',
        ?DateTime $lastChecked = null,
        bool $isOnline = true
    ) {
        $this->containerType = $containerType;
        $this->availableVersions = $availableVersions;
        $this->latestVersion = $latestVersion ?: ($availableVersions ? max($availableVersions) : '');
        $this->lastChecked = $lastChecked ?? new DateTime();
        $this->isOnline = $isOnline;
    }

    /**
     * Check if container supports a specific PHP version
     * 
     * @param string $version PHP version to check
     * @return bool True if version is supported
     */
    public function supportsVersion(string $version): bool
    {
        return in_array($version, $this->availableVersions, true);
    }

    /**
     * Get the closest available version to the requested version
     * 
     * @param string $version Requested PHP version
     * @return string|null Closest available version or null if none found
     */
    public function getClosestVersion(string $version): ?string
    {
        if ($this->supportsVersion($version)) {
            return $version;
        }

        if (empty($this->availableVersions)) {
            return null;
        }

        // Sort versions in descending order and find the highest one below requested
        $sortedVersions = $this->availableVersions;
        usort($sortedVersions, 'version_compare');
        $sortedVersions = array_reverse($sortedVersions);

        foreach ($sortedVersions as $availableVersion) {
            if (version_compare($availableVersion, $version, '<=')) {
                return $availableVersion;
            }
        }

        // If no version is lower, return the highest available
        return $sortedVersions[0];
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Availability data as array
     */
    public function toArray(): array
    {
        return [
            'containerType' => $this->containerType,
            'availableVersions' => $this->availableVersions,
            'latestVersion' => $this->latestVersion,
            'lastChecked' => $this->lastChecked->format('Y-m-d H:i:s'),
            'isOnline' => $this->isOnline
        ];
    }

    /**
     * Create from array (deserialization)
     * 
     * @param array $data Availability data
     * @return static New instance from array data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['containerType'],
            $data['availableVersions'] ?? [],
            $data['latestVersion'] ?? '',
            isset($data['lastChecked']) ? new DateTime($data['lastChecked']) : null,
            $data['isOnline'] ?? true
        );
    }
}