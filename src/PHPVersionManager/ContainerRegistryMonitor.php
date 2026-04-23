<?php

namespace Laradock\PHPVersionManager;

use DateTime;
use Laradock\PHPVersionManager\Models\ContainerAvailability;
use Exception;

/**
 * Container Registry Monitor implementation
 * 
 * Tracks available PHP versions in Docker registries, provides caching,
 * and monitors for updates. Integrates with Docker Hub API to check
 * image availability for Laradock containers.
 */
class ContainerRegistryMonitor implements ContainerRegistryMonitorInterface
{
    private RegistryCacheManager $cacheManager;
    private string $dockerHubApiUrl = 'https://hub.docker.com/v2/repositories';
    private array $containerImageMappings = [
        'workspace' => 'laradock/workspace',
        'php-fpm' => 'laradock/php-fpm', 
        'nginx' => 'laradock/nginx'
    ];
    private array $supportedVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    public function __construct(int $cacheIntervalMinutes = 60, ?RegistryCacheManager $cacheManager = null)
    {
        $this->cacheManager = $cacheManager ?? new RegistryCacheManager(
            '/tmp/laradock-registry-cache',
            $cacheIntervalMinutes
        );
    }

    /**
     * Check if a specific PHP version is available for a container type
     */
    public function checkAvailability(string $version, string $containerType): bool
    {
        if (!isset($this->containerImageMappings[$containerType])) {
            return false;
        }

        // Check if cache is expired and refresh if needed
        if ($this->cacheManager->isCacheExpired($containerType) && !$this->cacheManager->isOfflineMode()) {
            $this->updateContainerCache($containerType);
        }

        $availability = $this->cacheManager->getCachedAvailability($containerType);
        return $availability ? $availability->supportsVersion($version) : false;
    }

    /**
     * Get all available PHP versions for a specific container type
     */
    public function getAvailableVersions(string $containerType): array
    {
        if (!isset($this->containerImageMappings[$containerType])) {
            return [];
        }

        // Check if cache is expired and refresh if needed
        if ($this->cacheManager->isCacheExpired($containerType) && !$this->cacheManager->isOfflineMode()) {
            $this->updateContainerCache($containerType);
        }

        $availability = $this->cacheManager->getCachedAvailability($containerType);
        return $availability ? $availability->availableVersions : [];
    }

    /**
     * Refresh the cached registry information
     */
    public function refreshCache(): void
    {
        foreach (array_keys($this->containerImageMappings) as $containerType) {
            $this->updateContainerCache($containerType);
        }
        
        $this->cacheManager->markGlobalRefreshCompleted();
    }

    /**
     * Get the timestamp of the last registry update check
     */
    public function getLastUpdateTime(): DateTime
    {
        // Return the most recent update time from any container
        $latestTime = new DateTime('1970-01-01');
        
        foreach (array_keys($this->containerImageMappings) as $containerType) {
            $availability = $this->cacheManager->getCachedAvailability($containerType);
            if ($availability && $availability->lastChecked > $latestTime) {
                $latestTime = $availability->lastChecked;
            }
        }
        
        return $latestTime;
    }

    /**
     * Update cache for a specific container type
     */
    private function updateContainerCache(string $containerType): void
    {
        try {
            $imageName = $this->containerImageMappings[$containerType];
            $availableVersions = $this->fetchAvailableVersionsFromDockerHub($imageName);
            
            $availability = new ContainerAvailability(
                $containerType,
                $availableVersions,
                $this->getLatestVersion($availableVersions),
                new DateTime(),
                true
            );
            
            $this->cacheManager->storeAvailability($availability);
        } catch (Exception $e) {
            // Log error and mark as offline, but keep existing cache
            error_log("Failed to update cache for {$containerType}: " . $e->getMessage());
            
            $existing = $this->cacheManager->getCachedAvailability($containerType);
            if ($existing) {
                $offlineAvailability = new ContainerAvailability(
                    $existing->containerType,
                    $existing->availableVersions,
                    $existing->latestVersion,
                    $existing->lastChecked,
                    false // Mark as offline
                );
                $this->cacheManager->storeAvailability($offlineAvailability);
            }
        }
    }

    /**
     * Fetch available PHP versions from Docker Hub API
     */
    private function fetchAvailableVersionsFromDockerHub(string $imageName): array
    {
        $url = "{$this->dockerHubApiUrl}/{$imageName}/tags";
        
        // Use curl for HTTP request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Laradock-PHP-Version-Manager/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            throw new Exception("Failed to fetch tags from Docker Hub for {$imageName}");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['results'])) {
            throw new Exception("Invalid response format from Docker Hub API");
        }
        
        return $this->extractPhpVersionsFromTags($data['results']);
    }

    /**
     * Extract PHP versions from Docker Hub tag results
     */
    private function extractPhpVersionsFromTags(array $tags): array
    {
        $availableVersions = [];
        
        foreach ($tags as $tag) {
            $tagName = $tag['name'] ?? '';
            
            // Look for PHP version patterns in tag names
            // Common patterns: php81, php-8.1, 8.1, php8.1, etc.
            if (preg_match('/(?:php[-_]?)?(\d+)\.?(\d+)/', $tagName, $matches)) {
                $version = $matches[1] . '.' . $matches[2];
                
                // Only include supported versions
                if (in_array($version, $this->supportedVersions, true)) {
                    $availableVersions[] = $version;
                }
            }
        }
        
        // Remove duplicates and sort
        $availableVersions = array_unique($availableVersions);
        usort($availableVersions, 'version_compare');
        
        return $availableVersions;
    }

    /**
     * Get the latest version from available versions
     */
    private function getLatestVersion(array $versions): string
    {
        if (empty($versions)) {
            return '';
        }
        
        usort($versions, 'version_compare');
        return end($versions);
    }

    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        return $this->cacheManager->getCacheStatistics();
    }

    /**
     * Check if registry is currently accessible
     */
    public function isRegistryAccessible(): bool
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->dockerHubApiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $result !== false && $httpCode === 200;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get notification about new versions
     */
    public function getUpdateNotifications(): array
    {
        $notifications = [];
        
        foreach (array_keys($this->containerImageMappings) as $containerType) {
            $availability = $this->cacheManager->getCachedAvailability($containerType);
            
            if (!$availability || !$availability->isOnline) {
                continue;
            }
            
            $latestVersion = $availability->latestVersion;
            
            // Check if there are newer versions than what we had before
            // This is a simplified implementation - in practice, you'd compare
            // against previously stored "last known" versions
            if (!empty($latestVersion) && version_compare($latestVersion, '8.4', '>')) {
                $notifications[] = [
                    'type' => 'new_version_available',
                    'container' => $containerType,
                    'version' => $latestVersion,
                    'message' => "New PHP version {$latestVersion} is now available for {$containerType} container"
                ];
            }
        }
        
        return $notifications;
    }

    /**
     * Set offline mode
     */
    public function setOfflineMode(bool $offlineMode): void
    {
        $this->cacheManager->setOfflineMode($offlineMode);
    }

    /**
     * Check if in offline mode
     */
    public function isOfflineMode(): bool
    {
        return $this->cacheManager->isOfflineMode();
    }

    /**
     * Clear cache for specific container or all containers
     */
    public function clearCache(?string $containerType = null): void
    {
        if ($containerType) {
            $this->cacheManager->clearContainerCache($containerType);
        } else {
            $this->cacheManager->clearAllCache();
        }
    }

    /**
     * Get cache manager instance
     */
    public function getCacheManager(): RegistryCacheManager
    {
        return $this->cacheManager;
    }
}