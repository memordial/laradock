<?php

namespace Laradock\PHPVersionManager;

use DateTime;

/**
 * Interface for monitoring container registry availability
 * 
 * Tracks available PHP versions in Docker registries and provides
 * update notifications when new versions become available.
 */
interface ContainerRegistryMonitorInterface
{
    /**
     * Check if a specific PHP version is available for a container type
     * 
     * @param string $version PHP version to check
     * @param string $containerType Container type (workspace, php-fpm, nginx)
     * @return bool True if version is available
     */
    public function checkAvailability(string $version, string $containerType): bool;

    /**
     * Get all available PHP versions for a specific container type
     * 
     * @param string $containerType Container type to check
     * @return array List of available PHP versions
     */
    public function getAvailableVersions(string $containerType): array;

    /**
     * Refresh the cached registry information
     * 
     * @return void
     */
    public function refreshCache(): void;

    /**
     * Get the timestamp of the last registry update check
     * 
     * @return DateTime Last update check time
     */
    public function getLastUpdateTime(): DateTime;
}