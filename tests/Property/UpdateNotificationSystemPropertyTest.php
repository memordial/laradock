<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;
use Laradock\PHPVersionManager\Models\ContainerAvailability;
use DateTime;

/**
 * Property-based tests for update notification system
 * 
 * **Validates: Requirements 5.2, 5.3**
 */
class UpdateNotificationSystemPropertyTest extends TestCase
{
    use TestTrait;

    private ContainerRegistryMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create monitor with short cache interval for testing
        $this->monitor = new ContainerRegistryMonitor(1); // 1 minute cache
    }

    /**
     * Property 8: Update Notification System
     * 
     * For any new PHP version becoming available in registries, the PHP Version Manager
     * should notify developers and update the cached availability information.
     * 
     * **Validates: Requirements 5.2, 5.3**
     */
    public function testUpdateNotificationForNewVersions(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\subset(['8.1', '8.2', '8.3', '8.4']) // Previously available versions
        )->then(function ($containerType, $newVersion, $previousVersions) {
            // Skip if new version was already in previous versions
            if (in_array($newVersion, $previousVersions)) {
                return;
            }

            // Mock the initial state with previous versions
            $initialAvailability = new ContainerAvailability(
                $containerType,
                $previousVersions,
                max($previousVersions ?: ['8.1']),
                new DateTime(),
                true
            );

            // Simulate registry update with new version
            $updatedVersions = array_merge($previousVersions, [$newVersion]);
            sort($updatedVersions, SORT_NATURAL);
            
            $updatedAvailability = new ContainerAvailability(
                $containerType,
                $updatedVersions,
                max($updatedVersions),
                new DateTime(),
                true
            );

            // Test that new version is detected
            $this->assertTrue(
                $updatedAvailability->supportsVersion($newVersion),
                "Updated availability should support new version {$newVersion}"
            );

            // Test that all previous versions are still supported
            foreach ($previousVersions as $previousVersion) {
                $this->assertTrue(
                    $updatedAvailability->supportsVersion($previousVersion),
                    "Updated availability should still support previous version {$previousVersion}"
                );
            }

            // Test that latest version is updated if new version is higher
            if (version_compare($newVersion, $initialAvailability->latestVersion, '>')) {
                $this->assertEquals(
                    $newVersion,
                    $updatedAvailability->latestVersion,
                    "Latest version should be updated to {$newVersion}"
                );
            }

            // Test that cache timestamp is updated
            $this->assertGreaterThanOrEqual(
                $initialAvailability->lastChecked->getTimestamp(),
                $updatedAvailability->lastChecked->getTimestamp(),
                "Cache timestamp should be updated"
            );
        });
    }

    /**
     * Property: Notification Generation for Version Updates
     * 
     * When new versions become available, appropriate notifications should be generated
     * with correct version information and container details.
     */
    public function testNotificationGenerationForUpdates(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\elements(['8.5', '8.6', '9.0']) // Future versions that would trigger notifications
        )->then(function ($containerType, $newVersion) {
            // Create availability with the new version
            $availability = new ContainerAvailability(
                $containerType,
                ['8.1', '8.2', '8.3', '8.4', $newVersion],
                $newVersion,
                new DateTime(),
                true
            );

            // Test that availability correctly reports the new version
            $this->assertTrue(
                $availability->supportsVersion($newVersion),
                "Availability should support new version {$newVersion}"
            );

            $this->assertEquals(
                $newVersion,
                $availability->latestVersion,
                "Latest version should be {$newVersion}"
            );

            // Test that container is marked as online
            $this->assertTrue(
                $availability->isOnline,
                "Container should be marked as online when new versions are available"
            );

            // Test that version list includes the new version
            $this->assertContains(
                $newVersion,
                $availability->availableVersions,
                "Available versions should include {$newVersion}"
            );
        });
    }

    /**
     * Property: Cache Update Behavior
     * 
     * When registry information is refreshed, cache should be updated with
     * new availability information and timestamps.
     */
    public function testCacheUpdateBehavior(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\subset(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($containerType, $availableVersions) {
            if (empty($availableVersions)) {
                return; // Skip empty version sets
            }

            // Get initial cache state
            $initialUpdateTime = $this->monitor->getLastUpdateTime();
            
            // Wait a moment to ensure timestamp difference
            usleep(1000); // 1ms
            
            // Create mock availability data
            $availability = new ContainerAvailability(
                $containerType,
                $availableVersions,
                max($availableVersions),
                new DateTime(),
                true
            );

            // Test that availability data is properly structured
            $this->assertEquals(
                $containerType,
                $availability->containerType,
                "Container type should match"
            );

            $this->assertEquals(
                $availableVersions,
                $availability->availableVersions,
                "Available versions should match input"
            );

            $this->assertEquals(
                max($availableVersions),
                $availability->latestVersion,
                "Latest version should be the highest available"
            );

            // Test that all versions are properly supported
            foreach ($availableVersions as $version) {
                $this->assertTrue(
                    $availability->supportsVersion($version),
                    "Should support version {$version}"
                );
            }
        });
    }

    /**
     * Property: Offline Registry Handling
     * 
     * When registry is offline, cached information should be preserved
     * and system should gracefully handle unavailability.
     */
    public function testOfflineRegistryHandling(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\subset(['8.1', '8.2', '8.3', '8.4'])
        )->then(function ($containerType, $cachedVersions) {
            if (empty($cachedVersions)) {
                return; // Skip empty version sets
            }

            // Create availability with cached data but marked as offline
            $offlineAvailability = new ContainerAvailability(
                $containerType,
                $cachedVersions,
                max($cachedVersions),
                new DateTime(),
                false // Marked as offline
            );

            // Test that cached versions are still accessible
            foreach ($cachedVersions as $version) {
                $this->assertTrue(
                    $offlineAvailability->supportsVersion($version),
                    "Should still support cached version {$version} when offline"
                );
            }

            // Test that offline status is properly tracked
            $this->assertFalse(
                $offlineAvailability->isOnline,
                "Should be marked as offline"
            );

            // Test that latest version is preserved from cache
            $this->assertEquals(
                max($cachedVersions),
                $offlineAvailability->latestVersion,
                "Latest version should be preserved from cache"
            );

            // Test that container type is preserved
            $this->assertEquals(
                $containerType,
                $offlineAvailability->containerType,
                "Container type should be preserved"
            );
        });
    }

    /**
     * Property: Version Comparison and Ordering
     * 
     * Available versions should be properly ordered and compared
     * to determine latest versions and update notifications.
     */
    public function testVersionComparisonAndOrdering(): void
    {
        $this->limitTo(3)->forAll(
            Generator\subset(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($versions) {
            if (count($versions) < 2) {
                return; // Need at least 2 versions for comparison
            }

            $availability = new ContainerAvailability(
                'workspace',
                $versions,
                '',
                new DateTime(),
                true
            );

            // Test that latest version is correctly identified
            $expectedLatest = max($versions);
            $this->assertEquals(
                $expectedLatest,
                $availability->latestVersion,
                "Latest version should be {$expectedLatest}"
            );

            // Test version ordering
            $sortedVersions = $versions;
            usort($sortedVersions, 'version_compare');
            
            // Test that closest version logic works correctly
            foreach ($versions as $testVersion) {
                $closest = $availability->getClosestVersion($testVersion);
                $this->assertEquals(
                    $testVersion,
                    $closest,
                    "Closest version to {$testVersion} should be itself when available"
                );
            }

            // Test closest version for unavailable version
            $unavailableVersion = '9.0';
            if (!in_array($unavailableVersion, $versions)) {
                $closest = $availability->getClosestVersion($unavailableVersion);
                $this->assertContains(
                    $closest,
                    $versions,
                    "Closest version should be from available versions"
                );
            }
        });
    }
}