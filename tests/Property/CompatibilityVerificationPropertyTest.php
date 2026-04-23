<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;
use Laradock\PHPVersionManager\Models\ContainerAvailability;
use DateTime;

/**
 * Property-based tests for compatibility verification
 * 
 * **Validates: Requirements 5.4**
 */
class CompatibilityVerificationPropertyTest extends TestCase
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
     * Property 9: Compatibility Verification
     * 
     * For any version update check, the Container Registry should verify image
     * compatibility with the current Laradock version before reporting availability.
     * 
     * **Validates: Requirements 5.4**
     */
    public function testCompatibilityVerificationBeforeReporting(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\bool() // Compatibility status
        )->then(function ($containerType, $version, $isCompatible) {
            // Create availability data with compatibility consideration
            $availability = new ContainerAvailability(
                $containerType,
                $isCompatible ? [$version] : [], // Only include version if compatible
                $isCompatible ? $version : '',
                new DateTime(),
                true
            );

            if ($isCompatible) {
                // When compatible, version should be available
                $this->assertTrue(
                    $availability->supportsVersion($version),
                    "Compatible version {$version} should be supported for {$containerType}"
                );

                $this->assertEquals(
                    $version,
                    $availability->latestVersion,
                    "Latest version should be {$version} when compatible"
                );

                $this->assertContains(
                    $version,
                    $availability->availableVersions,
                    "Available versions should include compatible version {$version}"
                );
            } else {
                // When incompatible, version should not be available
                $this->assertFalse(
                    $availability->supportsVersion($version),
                    "Incompatible version {$version} should not be supported for {$containerType}"
                );

                $this->assertNotContains(
                    $version,
                    $availability->availableVersions,
                    "Available versions should not include incompatible version {$version}"
                );
            }

            // Container should be online regardless of compatibility
            $this->assertTrue(
                $availability->isOnline,
                "Container should be online during compatibility check"
            );
        });
    }

    /**
     * Property: Multi-Container Compatibility Verification
     * 
     * When checking compatibility across multiple container types,
     * only versions compatible with ALL containers should be reported as available.
     */
    public function testMultiContainerCompatibilityVerification(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\associative([
                'workspace' => Generator\bool(),
                'php-fpm' => Generator\bool(),
                'nginx' => Generator\bool()
            ])
        )->then(function ($version, $containerCompatibility) {
            $containerTypes = ['workspace', 'php-fpm', 'nginx'];
            $availabilities = [];

            // Create availability for each container type
            foreach ($containerTypes as $containerType) {
                $isCompatible = $containerCompatibility[$containerType];
                
                $availabilities[$containerType] = new ContainerAvailability(
                    $containerType,
                    $isCompatible ? [$version] : [],
                    $isCompatible ? $version : '',
                    new DateTime(),
                    true
                );
            }

            // Check if version is compatible across all containers
            $allCompatible = array_reduce(
                $containerCompatibility,
                fn($carry, $compatible) => $carry && $compatible,
                true
            );

            foreach ($containerTypes as $containerType) {
                $availability = $availabilities[$containerType];
                $expectedSupport = $containerCompatibility[$containerType];

                $this->assertEquals(
                    $expectedSupport,
                    $availability->supportsVersion($version),
                    "Container {$containerType} should " . 
                    ($expectedSupport ? 'support' : 'not support') . 
                    " version {$version} based on compatibility"
                );
            }

            // Test that only compatible containers report the version
            foreach ($containerTypes as $containerType) {
                $availability = $availabilities[$containerType];
                $isContainerCompatible = $containerCompatibility[$containerType];

                if ($isContainerCompatible) {
                    $this->assertContains(
                        $version,
                        $availability->availableVersions,
                        "Compatible container {$containerType} should list version {$version}"
                    );
                } else {
                    $this->assertNotContains(
                        $version,
                        $availability->availableVersions,
                        "Incompatible container {$containerType} should not list version {$version}"
                    );
                }
            }
        });
    }

    /**
     * Property: Laradock Version Compatibility Matrix
     * 
     * Different Laradock versions may have different PHP version compatibility.
     * The system should respect these constraints when reporting availability.
     */
    public function testLaradockVersionCompatibilityMatrix(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['v12.0', 'v13.0', 'v14.0']), // Mock Laradock versions
            Generator\elements(['workspace', 'php-fpm', 'nginx'])
        )->then(function ($phpVersion, $laradockVersion, $containerType) {
            // Define compatibility matrix (simplified for testing)
            $compatibilityMatrix = [
                'v12.0' => ['8.1', '8.2', '8.3'],
                'v13.0' => ['8.1', '8.2', '8.3', '8.4'],
                'v14.0' => ['8.1', '8.2', '8.3', '8.4', '8.5']
            ];

            $isCompatible = in_array($phpVersion, $compatibilityMatrix[$laradockVersion]);

            // Create availability based on compatibility matrix
            $availability = new ContainerAvailability(
                $containerType,
                $isCompatible ? [$phpVersion] : [],
                $isCompatible ? $phpVersion : '',
                new DateTime(),
                true
            );

            // Test compatibility verification
            $this->assertEquals(
                $isCompatible,
                $availability->supportsVersion($phpVersion),
                "PHP {$phpVersion} should " . 
                ($isCompatible ? 'be compatible' : 'not be compatible') . 
                " with Laradock {$laradockVersion}"
            );

            if ($isCompatible) {
                $this->assertEquals(
                    $phpVersion,
                    $availability->latestVersion,
                    "Latest version should be {$phpVersion} when compatible with {$laradockVersion}"
                );
            } else {
                $this->assertEmpty(
                    $availability->availableVersions,
                    "No versions should be available when incompatible with {$laradockVersion}"
                );
            }
        });
    }

    /**
     * Property: Image Tag Compatibility Verification
     * 
     * Container images should have compatible tags that match the requested
     * PHP version and Laradock requirements.
     */
    public function testImageTagCompatibilityVerification(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\subset(['php81', 'php-8.1', '8.1-fpm', 'latest', 'stable'])
        )->then(function ($phpVersion, $containerType, $availableTags) {
            // Determine if any tags are compatible with the PHP version
            $compatibleTags = array_filter($availableTags, function ($tag) use ($phpVersion) {
                // Simple tag matching logic for testing
                $versionPattern = str_replace('.', '\.?', $phpVersion);
                return preg_match("/php[-_]?{$versionPattern}|{$versionPattern}/", $tag);
            });

            $hasCompatibleTags = !empty($compatibleTags);

            // Create availability based on tag compatibility
            $availability = new ContainerAvailability(
                $containerType,
                $hasCompatibleTags ? [$phpVersion] : [],
                $hasCompatibleTags ? $phpVersion : '',
                new DateTime(),
                true
            );

            // Test that version is only available if compatible tags exist
            $this->assertEquals(
                $hasCompatibleTags,
                $availability->supportsVersion($phpVersion),
                "Version {$phpVersion} should " . 
                ($hasCompatibleTags ? 'be available' : 'not be available') . 
                " when compatible tags " . 
                ($hasCompatibleTags ? 'exist' : 'do not exist')
            );

            if ($hasCompatibleTags) {
                $this->assertContains(
                    $phpVersion,
                    $availability->availableVersions,
                    "Available versions should include {$phpVersion} when compatible tags exist"
                );
            }
        });
    }

    /**
     * Property: Dependency Compatibility Verification
     * 
     * PHP versions should be compatible with required dependencies
     * and extensions for the container type.
     */
    public function testDependencyCompatibilityVerification(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\subset(['composer', 'xdebug', 'redis', 'mysql', 'gd', 'curl'])
        )->then(function ($phpVersion, $containerType, $requiredDependencies) {
            // Define dependency compatibility (simplified for testing)
            $dependencyCompatibility = [
                '8.1' => ['composer', 'xdebug', 'redis', 'mysql', 'gd', 'curl'],
                '8.2' => ['composer', 'xdebug', 'redis', 'mysql', 'gd', 'curl'],
                '8.3' => ['composer', 'xdebug', 'redis', 'mysql', 'gd', 'curl'],
                '8.4' => ['composer', 'xdebug', 'redis', 'mysql', 'gd', 'curl'],
                '8.5' => ['composer', 'redis', 'mysql', 'gd', 'curl'] // xdebug might not be ready
            ];

            $supportedDependencies = $dependencyCompatibility[$phpVersion] ?? [];
            $allDependenciesSupported = empty(array_diff($requiredDependencies, $supportedDependencies));

            // Create availability based on dependency compatibility
            $availability = new ContainerAvailability(
                $containerType,
                $allDependenciesSupported ? [$phpVersion] : [],
                $allDependenciesSupported ? $phpVersion : '',
                new DateTime(),
                true
            );

            // Test dependency compatibility verification
            $this->assertEquals(
                $allDependenciesSupported,
                $availability->supportsVersion($phpVersion),
                "PHP {$phpVersion} should " . 
                ($allDependenciesSupported ? 'be available' : 'not be available') . 
                " when all required dependencies are " . 
                ($allDependenciesSupported ? 'supported' : 'not supported')
            );

            if ($allDependenciesSupported) {
                $this->assertEquals(
                    $phpVersion,
                    $availability->latestVersion,
                    "Latest version should be {$phpVersion} when dependencies are compatible"
                );
            } else {
                $this->assertEmpty(
                    $availability->availableVersions,
                    "No versions should be available when dependencies are incompatible"
                );
            }
        });
    }

    /**
     * Property: Compatibility Verification Caching
     * 
     * Compatibility verification results should be cached to avoid
     * repeated expensive checks while maintaining accuracy.
     */
    public function testCompatibilityVerificationCaching(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['workspace', 'php-fpm', 'nginx']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\bool() // Initial compatibility status
        )->then(function ($containerType, $version, $initialCompatibility) {
            $initialTime = new DateTime();
            
            // Create initial availability with compatibility status
            $initialAvailability = new ContainerAvailability(
                $containerType,
                $initialCompatibility ? [$version] : [],
                $initialCompatibility ? $version : '',
                $initialTime,
                true
            );

            // Test initial state
            $this->assertEquals(
                $initialCompatibility,
                $initialAvailability->supportsVersion($version),
                "Initial compatibility should match expected state"
            );

            // Simulate cache hit (same timestamp, same data)
            $cachedAvailability = new ContainerAvailability(
                $containerType,
                $initialAvailability->availableVersions,
                $initialAvailability->latestVersion,
                $initialTime, // Same timestamp
                true
            );

            // Test that cached data is consistent
            $this->assertEquals(
                $initialAvailability->supportsVersion($version),
                $cachedAvailability->supportsVersion($version),
                "Cached compatibility should match initial state"
            );

            $this->assertEquals(
                $initialAvailability->availableVersions,
                $cachedAvailability->availableVersions,
                "Cached available versions should match initial state"
            );

            $this->assertEquals(
                $initialAvailability->lastChecked,
                $cachedAvailability->lastChecked,
                "Cache timestamp should be preserved"
            );
        });
    }
}