<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;
use Laradock\PHPVersionManager\RegistryCacheManager;
use DateTime;

/**
 * Integration tests for Docker Hub API connectivity and functionality
 * 
 * Tests real API connectivity, response parsing, and cache behavior
 * with actual Docker Hub endpoints.
 */
class DockerHubApiIntegrationTest extends TestCase
{
    private ContainerRegistryMonitor $monitor;
    private RegistryCacheManager $cacheManager;
    private string $testCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary cache directory for testing
        $this->testCacheDir = sys_get_temp_dir() . '/laradock-test-cache-' . uniqid();
        
        // Create cache manager with test directory
        $this->cacheManager = new RegistryCacheManager($this->testCacheDir, 1); // 1 minute cache
        
        // Create monitor with test cache manager
        $this->monitor = new ContainerRegistryMonitor(1, $this->cacheManager);
    }

    protected function tearDown(): void
    {
        // Clean up test cache directory
        if (is_dir($this->testCacheDir)) {
            $this->removeDirectory($this->testCacheDir);
        }
        
        parent::tearDown();
    }

    /**
     * Test Docker Hub API connectivity
     * 
     * @group integration
     * @group network
     */
    public function testDockerHubApiConnectivity(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        $isAccessible = $this->monitor->isRegistryAccessible();
        
        $this->assertTrue(
            $isAccessible,
            'Docker Hub API should be accessible'
        );
    }

    /**
     * Test fetching available versions for workspace container
     * 
     * @group integration
     * @group network
     */
    public function testFetchWorkspaceVersions(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        $versions = $this->monitor->getAvailableVersions('workspace');
        
        $this->assertIsArray($versions, 'Should return array of versions');
        
        // We expect at least some PHP versions to be available
        $this->assertNotEmpty($versions, 'Should have at least some available versions');
        
        // Check that versions are in expected format
        foreach ($versions as $version) {
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+$/',
                $version,
                "Version {$version} should be in X.Y format"
            );
        }
        
        // Check that common PHP versions are present
        $commonVersions = ['8.1', '8.2', '8.3'];
        $foundCommonVersions = array_intersect($commonVersions, $versions);
        
        $this->assertNotEmpty(
            $foundCommonVersions,
            'Should find at least some common PHP versions: ' . implode(', ', $commonVersions)
        );
    }

    /**
     * Test fetching available versions for php-fpm container
     * 
     * @group integration
     * @group network
     */
    public function testFetchPhpFpmVersions(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        $versions = $this->monitor->getAvailableVersions('php-fpm');
        
        $this->assertIsArray($versions, 'Should return array of versions');
        $this->assertNotEmpty($versions, 'Should have at least some available versions');
        
        // PHP-FPM should have good version coverage
        $expectedVersions = ['8.1', '8.2', '8.3', '8.4'];
        $foundVersions = array_intersect($expectedVersions, $versions);
        
        $this->assertGreaterThanOrEqual(
            2,
            count($foundVersions),
            'Should find at least 2 expected PHP versions for php-fpm'
        );
    }

    /**
     * Test version availability checking
     * 
     * @group integration
     * @group network
     */
    public function testVersionAvailabilityChecking(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        // Test common PHP versions
        $testVersions = ['8.1', '8.2', '8.3', '8.4'];
        $containerTypes = ['workspace', 'php-fpm'];
        
        foreach ($containerTypes as $containerType) {
            foreach ($testVersions as $version) {
                $isAvailable = $this->monitor->checkAvailability($version, $containerType);
                
                // We expect at least some versions to be available
                $this->assertIsBool(
                    $isAvailable,
                    "Availability check should return boolean for {$containerType} PHP {$version}"
                );
            }
        }
        
        // Test that at least one common version is available for each container
        foreach ($containerTypes as $containerType) {
            $hasAnyVersion = false;
            foreach ($testVersions as $version) {
                if ($this->monitor->checkAvailability($version, $containerType)) {
                    $hasAnyVersion = true;
                    break;
                }
            }
            
            $this->assertTrue(
                $hasAnyVersion,
                "At least one PHP version should be available for {$containerType}"
            );
        }
    }

    /**
     * Test cache behavior with real API calls
     * 
     * @group integration
     * @group network
     */
    public function testCacheBehaviorWithRealApi(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        $containerType = 'workspace';
        
        // First call should hit the API
        $startTime = microtime(true);
        $versions1 = $this->monitor->getAvailableVersions($containerType);
        $firstCallTime = microtime(true) - $startTime;
        
        // Second call should use cache (should be faster)
        $startTime = microtime(true);
        $versions2 = $this->monitor->getAvailableVersions($containerType);
        $secondCallTime = microtime(true) - $startTime;
        
        // Results should be identical
        $this->assertEquals(
            $versions1,
            $versions2,
            'Cached results should match original API results'
        );
        
        // Second call should be significantly faster (cached)
        $this->assertLessThan(
            $firstCallTime * 0.5, // At least 50% faster
            $secondCallTime,
            'Cached call should be significantly faster than API call'
        );
        
        // Check cache statistics
        $stats = $this->monitor->getCacheStats();
        $this->assertArrayHasKey('containers', $stats);
        $this->assertArrayHasKey($containerType, $stats['containers']);
        
        $containerStats = $stats['containers'][$containerType];
        $this->assertTrue($containerStats['is_online'], 'Container should be marked as online');
        $this->assertGreaterThan(0, $containerStats['available_versions_count']);
    }

    /**
     * Test cache refresh functionality
     * 
     * @group integration
     * @group network
     */
    public function testCacheRefreshFunctionality(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        $containerType = 'workspace';
        
        // Get initial versions
        $initialVersions = $this->monitor->getAvailableVersions($containerType);
        $initialUpdateTime = $this->monitor->getLastUpdateTime();
        
        // Wait a moment
        sleep(1);
        
        // Force cache refresh
        $this->monitor->refreshCache();
        
        // Get versions after refresh
        $refreshedVersions = $this->monitor->getAvailableVersions($containerType);
        $refreshedUpdateTime = $this->monitor->getLastUpdateTime();
        
        // Update time should be newer
        $this->assertGreaterThan(
            $initialUpdateTime->getTimestamp(),
            $refreshedUpdateTime->getTimestamp(),
            'Update time should be newer after refresh'
        );
        
        // Versions should be consistent (same API, same results)
        $this->assertEquals(
            $initialVersions,
            $refreshedVersions,
            'Versions should be consistent after refresh'
        );
    }

    /**
     * Test offline mode behavior
     * 
     * @group integration
     */
    public function testOfflineModeBehavior(): void
    {
        $containerType = 'workspace';
        
        // First, populate cache with some data (if network available)
        if ($this->hasNetworkAccess()) {
            $this->monitor->getAvailableVersions($containerType);
        } else {
            // Manually create cache entry for testing
            $this->cacheManager->storeAvailability(
                new \Laradock\PHPVersionManager\Models\ContainerAvailability(
                    $containerType,
                    ['8.1', '8.2', '8.3'],
                    '8.3',
                    new DateTime(),
                    true
                )
            );
        }
        
        // Enable offline mode
        $this->monitor->setOfflineMode(true);
        
        $this->assertTrue(
            $this->monitor->isOfflineMode(),
            'Should be in offline mode'
        );
        
        // Should still return cached data
        $versions = $this->monitor->getAvailableVersions($containerType);
        $this->assertIsArray($versions, 'Should return cached versions in offline mode');
        
        // Disable offline mode
        $this->monitor->setOfflineMode(false);
        
        $this->assertFalse(
            $this->monitor->isOfflineMode(),
            'Should not be in offline mode'
        );
    }

    /**
     * Test update notifications
     * 
     * @group integration
     * @group network
     */
    public function testUpdateNotifications(): void
    {
        // Skip if no network access
        if (!$this->hasNetworkAccess()) {
            $this->markTestSkipped('No network access available');
        }

        // Populate cache
        $this->monitor->refreshCache();
        
        // Get notifications
        $notifications = $this->monitor->getUpdateNotifications();
        
        $this->assertIsArray($notifications, 'Should return array of notifications');
        
        // Each notification should have required fields
        foreach ($notifications as $notification) {
            $this->assertArrayHasKey('type', $notification);
            $this->assertArrayHasKey('container', $notification);
            $this->assertArrayHasKey('version', $notification);
            $this->assertArrayHasKey('message', $notification);
            
            $this->assertEquals('new_version_available', $notification['type']);
            $this->assertContains($notification['container'], ['workspace', 'php-fpm', 'nginx']);
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $notification['version']);
            $this->assertIsString($notification['message']);
        }
    }

    /**
     * Test error handling with invalid container types
     * 
     * @group integration
     */
    public function testErrorHandlingWithInvalidContainerTypes(): void
    {
        $invalidContainerType = 'invalid-container';
        
        $versions = $this->monitor->getAvailableVersions($invalidContainerType);
        $this->assertEmpty($versions, 'Should return empty array for invalid container type');
        
        $isAvailable = $this->monitor->checkAvailability('8.1', $invalidContainerType);
        $this->assertFalse($isAvailable, 'Should return false for invalid container type');
    }

    /**
     * Test cache persistence across instances
     * 
     * @group integration
     */
    public function testCachePersistenceAcrossInstances(): void
    {
        $containerType = 'workspace';
        $testVersions = ['8.1', '8.2', '8.3'];
        
        // Store data in first instance
        $this->cacheManager->storeAvailability(
            new \Laradock\PHPVersionManager\Models\ContainerAvailability(
                $containerType,
                $testVersions,
                '8.3',
                new DateTime(),
                true
            )
        );
        
        // Create new cache manager instance with same directory
        $newCacheManager = new RegistryCacheManager($this->testCacheDir, 1);
        $newMonitor = new ContainerRegistryMonitor(1, $newCacheManager);
        
        // Should load cached data
        $cachedVersions = $newMonitor->getAvailableVersions($containerType);
        
        $this->assertEquals(
            $testVersions,
            $cachedVersions,
            'New instance should load cached data from disk'
        );
    }

    /**
     * Check if network access is available
     */
    private function hasNetworkAccess(): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'HEAD'
            ]
        ]);
        
        return @file_get_contents('https://hub.docker.com', false, $context) !== false;
    }

    /**
     * Recursively remove directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }
}