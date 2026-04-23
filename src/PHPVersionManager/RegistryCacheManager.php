<?php

namespace Laradock\PHPVersionManager;

use DateTime;
use Laradock\PHPVersionManager\Models\ContainerAvailability;
use Exception;

/**
 * Registry Cache Manager
 * 
 * Manages caching of container registry responses with configurable intervals,
 * offline mode support, and manual refresh capabilities.
 */
class RegistryCacheManager
{
    private string $cacheDirectory;
    private int $cacheIntervalMinutes;
    private bool $offlineMode;
    private array $memoryCache = [];
    private DateTime $lastGlobalRefresh;

    public function __construct(
        string $cacheDirectory = '/tmp/laradock-registry-cache',
        int $cacheIntervalMinutes = 60,
        bool $offlineMode = false
    ) {
        $this->cacheDirectory = $cacheDirectory;
        $this->cacheIntervalMinutes = $cacheIntervalMinutes;
        $this->offlineMode = $offlineMode;
        $this->lastGlobalRefresh = new DateTime('1970-01-01');
        
        $this->ensureCacheDirectoryExists();
        $this->loadMemoryCache();
    }

    /**
     * Get cached availability for a container type
     */
    public function getCachedAvailability(string $containerType): ?ContainerAvailability
    {
        // Check memory cache first
        if (isset($this->memoryCache[$containerType])) {
            return $this->memoryCache[$containerType];
        }

        // Try to load from disk cache
        $diskCache = $this->loadFromDiskCache($containerType);
        if ($diskCache) {
            $this->memoryCache[$containerType] = $diskCache;
            return $diskCache;
        }

        return null;
    }

    /**
     * Store availability data in cache
     */
    public function storeAvailability(ContainerAvailability $availability): void
    {
        $containerType = $availability->containerType;
        
        // Store in memory cache
        $this->memoryCache[$containerType] = $availability;
        
        // Store in disk cache
        $this->saveToDiskCache($availability);
    }

    /**
     * Check if cache is expired for a container type
     */
    public function isCacheExpired(string $containerType): bool
    {
        $cached = $this->getCachedAvailability($containerType);
        
        if (!$cached) {
            return true; // No cache means expired
        }

        $now = new DateTime();
        $cacheAge = $now->getTimestamp() - $cached->lastChecked->getTimestamp();
        
        return $cacheAge > ($this->cacheIntervalMinutes * 60);
    }

    /**
     * Check if global cache refresh is needed
     */
    public function isGlobalRefreshNeeded(): bool
    {
        $now = new DateTime();
        $timeSinceLastRefresh = $now->getTimestamp() - $this->lastGlobalRefresh->getTimestamp();
        
        return $timeSinceLastRefresh > ($this->cacheIntervalMinutes * 60);
    }

    /**
     * Mark global refresh as completed
     */
    public function markGlobalRefreshCompleted(): void
    {
        $this->lastGlobalRefresh = new DateTime();
        $this->saveGlobalRefreshTimestamp();
    }

    /**
     * Clear cache for a specific container type
     */
    public function clearContainerCache(string $containerType): void
    {
        // Remove from memory cache
        unset($this->memoryCache[$containerType]);
        
        // Remove from disk cache
        $cacheFile = $this->getCacheFilePath($containerType);
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Clear all cache data
     */
    public function clearAllCache(): void
    {
        // Clear memory cache
        $this->memoryCache = [];
        
        // Clear disk cache
        $this->clearDiskCache();
        
        // Reset global refresh timestamp
        $this->lastGlobalRefresh = new DateTime('1970-01-01');
        $this->saveGlobalRefreshTimestamp();
    }

    /**
     * Get cache statistics
     */
    public function getCacheStatistics(): array
    {
        $stats = [
            'cache_directory' => $this->cacheDirectory,
            'cache_interval_minutes' => $this->cacheIntervalMinutes,
            'offline_mode' => $this->offlineMode,
            'last_global_refresh' => $this->lastGlobalRefresh->format('Y-m-d H:i:s'),
            'memory_cache_size' => count($this->memoryCache),
            'containers' => []
        ];

        foreach ($this->memoryCache as $containerType => $availability) {
            $stats['containers'][$containerType] = [
                'available_versions_count' => count($availability->availableVersions),
                'latest_version' => $availability->latestVersion,
                'last_checked' => $availability->lastChecked->format('Y-m-d H:i:s'),
                'is_online' => $availability->isOnline,
                'cache_age_minutes' => $this->getCacheAgeMinutes($availability)
            ];
        }

        return $stats;
    }

    /**
     * Set offline mode
     */
    public function setOfflineMode(bool $offlineMode): void
    {
        $this->offlineMode = $offlineMode;
    }

    /**
     * Check if in offline mode
     */
    public function isOfflineMode(): bool
    {
        return $this->offlineMode;
    }

    /**
     * Set cache interval
     */
    public function setCacheInterval(int $minutes): void
    {
        $this->cacheIntervalMinutes = max(1, $minutes); // Minimum 1 minute
    }

    /**
     * Get cache interval
     */
    public function getCacheInterval(): int
    {
        return $this->cacheIntervalMinutes;
    }

    /**
     * Validate cache integrity
     */
    public function validateCacheIntegrity(): array
    {
        $issues = [];
        
        // Check if cache directory exists and is writable
        if (!is_dir($this->cacheDirectory)) {
            $issues[] = "Cache directory does not exist: {$this->cacheDirectory}";
        } elseif (!is_writable($this->cacheDirectory)) {
            $issues[] = "Cache directory is not writable: {$this->cacheDirectory}";
        }

        // Check memory cache consistency
        foreach ($this->memoryCache as $containerType => $availability) {
            if ($availability->containerType !== $containerType) {
                $issues[] = "Memory cache inconsistency for {$containerType}";
            }
            
            if (empty($availability->availableVersions) && !empty($availability->latestVersion)) {
                $issues[] = "Invalid availability data for {$containerType}: latest version set but no available versions";
            }
        }

        // Check disk cache files
        $diskFiles = glob($this->cacheDirectory . '/container_*.json');
        foreach ($diskFiles as $file) {
            if (!is_readable($file)) {
                $issues[] = "Cache file not readable: " . basename($file);
            } else {
                $content = file_get_contents($file);
                if (!json_decode($content)) {
                    $issues[] = "Invalid JSON in cache file: " . basename($file);
                }
            }
        }

        return $issues;
    }

    /**
     * Repair cache integrity issues
     */
    public function repairCacheIntegrity(): array
    {
        $repairs = [];
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDirectory)) {
            if (mkdir($this->cacheDirectory, 0755, true)) {
                $repairs[] = "Created cache directory: {$this->cacheDirectory}";
            }
        }

        // Remove invalid cache files
        $diskFiles = glob($this->cacheDirectory . '/container_*.json');
        foreach ($diskFiles as $file) {
            $content = file_get_contents($file);
            if (!json_decode($content)) {
                if (unlink($file)) {
                    $repairs[] = "Removed invalid cache file: " . basename($file);
                }
            }
        }

        // Fix memory cache inconsistencies
        foreach ($this->memoryCache as $containerType => $availability) {
            if ($availability->containerType !== $containerType) {
                unset($this->memoryCache[$containerType]);
                $repairs[] = "Removed inconsistent memory cache entry for {$containerType}";
            }
        }

        return $repairs;
    }

    /**
     * Ensure cache directory exists
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0755, true);
        }
    }

    /**
     * Load memory cache from disk
     */
    private function loadMemoryCache(): void
    {
        $this->loadGlobalRefreshTimestamp();
        
        $diskFiles = glob($this->cacheDirectory . '/container_*.json');
        foreach ($diskFiles as $file) {
            $containerType = $this->extractContainerTypeFromFilename($file);
            if ($containerType) {
                $availability = $this->loadFromDiskCache($containerType);
                if ($availability) {
                    $this->memoryCache[$containerType] = $availability;
                }
            }
        }
    }

    /**
     * Load availability from disk cache
     */
    private function loadFromDiskCache(string $containerType): ?ContainerAvailability
    {
        $cacheFile = $this->getCacheFilePath($containerType);
        
        if (!file_exists($cacheFile)) {
            return null;
        }

        try {
            $content = file_get_contents($cacheFile);
            $data = json_decode($content, true);
            
            if (!$data) {
                return null;
            }

            return ContainerAvailability::fromArray($data);
        } catch (Exception $e) {
            error_log("Failed to load cache for {$containerType}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save availability to disk cache
     */
    private function saveToDiskCache(ContainerAvailability $availability): void
    {
        $cacheFile = $this->getCacheFilePath($availability->containerType);
        
        try {
            $data = $availability->toArray();
            $content = json_encode($data, JSON_PRETTY_PRINT);
            file_put_contents($cacheFile, $content);
        } catch (Exception $e) {
            error_log("Failed to save cache for {$availability->containerType}: " . $e->getMessage());
        }
    }

    /**
     * Get cache file path for container type
     */
    private function getCacheFilePath(string $containerType): string
    {
        return $this->cacheDirectory . "/container_{$containerType}.json";
    }

    /**
     * Extract container type from cache filename
     */
    private function extractContainerTypeFromFilename(string $filename): ?string
    {
        $basename = basename($filename, '.json');
        if (preg_match('/^container_(.+)$/', $basename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Clear disk cache
     */
    private function clearDiskCache(): void
    {
        $diskFiles = glob($this->cacheDirectory . '/container_*.json');
        foreach ($diskFiles as $file) {
            unlink($file);
        }
        
        // Also remove global refresh timestamp
        $timestampFile = $this->cacheDirectory . '/global_refresh.timestamp';
        if (file_exists($timestampFile)) {
            unlink($timestampFile);
        }
    }

    /**
     * Save global refresh timestamp
     */
    private function saveGlobalRefreshTimestamp(): void
    {
        $timestampFile = $this->cacheDirectory . '/global_refresh.timestamp';
        file_put_contents($timestampFile, $this->lastGlobalRefresh->format('Y-m-d H:i:s'));
    }

    /**
     * Load global refresh timestamp
     */
    private function loadGlobalRefreshTimestamp(): void
    {
        $timestampFile = $this->cacheDirectory . '/global_refresh.timestamp';
        
        if (file_exists($timestampFile)) {
            $content = file_get_contents($timestampFile);
            try {
                $this->lastGlobalRefresh = new DateTime($content);
            } catch (Exception $e) {
                $this->lastGlobalRefresh = new DateTime('1970-01-01');
            }
        }
    }

    /**
     * Get cache age in minutes
     */
    private function getCacheAgeMinutes(ContainerAvailability $availability): int
    {
        $now = new DateTime();
        $ageSeconds = $now->getTimestamp() - $availability->lastChecked->getTimestamp();
        return (int) ($ageSeconds / 60);
    }
}