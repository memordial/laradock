<?php

namespace Laradock\PHPVersionManager\Tests\Property;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\Models\VersionConfiguration;

/**
 * Property-based tests for VersionConfiguration
 * 
 * These tests validate universal properties that should hold
 * across all valid inputs using the Eris property testing library.
 */
class VersionConfigurationPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property: Version configuration consistency should be deterministic
     * 
     * For any container version mapping, the consistency check should
     * always return the same result when called multiple times.
     */
    public function testConsistencyCheckIsDeterministic(): void
    {
        $this->limitTo(3)->forAll(
            Generator\string(),
            Generator\associative([
                'workspace' => Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
                'php-fpm' => Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
                'nginx' => Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
            ])
        )->then(function ($version, $containerVersions) {
            $config = new VersionConfiguration($version, $version, false, '', $containerVersions);
            
            $firstCheck = $config->isConsistent();
            $secondCheck = $config->isConsistent();
            
            $this->assertEquals($firstCheck, $secondCheck, 
                'Consistency check should be deterministic');
        });
    }

    /**
     * Property: Configuration with identical container versions should always be consistent
     * 
     * For any PHP version, if all containers use the same version,
     * the configuration should be marked as consistent.
     */
    public function testIdenticalVersionsAreAlwaysConsistent(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($version) {
            $containerVersions = [
                'workspace' => $version,
                'php-fpm' => $version,
                'nginx' => $version
            ];
            
            $config = new VersionConfiguration($version, $version, false, '', $containerVersions);
            
            $this->assertTrue($config->isConsistent(), 
                "Configuration with all containers using PHP {$version} should be consistent");
        });
    }

    /**
     * Property: Serialization round-trip should preserve all data
     * 
     * For any valid configuration, converting to array and back
     * should preserve all the original data.
     */
    public function testSerializationRoundTripPreservesData(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\bool(),
            Generator\string()
        )->then(function ($requestedVersion, $actualVersion, $fallbackApplied, $fallbackReason) {
            $original = new VersionConfiguration(
                $requestedVersion,
                $actualVersion,
                $fallbackApplied,
                $fallbackReason
            );
            
            $array = $original->toArray();
            $restored = VersionConfiguration::fromArray($array);
            
            $this->assertEquals($original->requestedVersion, $restored->requestedVersion);
            $this->assertEquals($original->actualVersion, $restored->actualVersion);
            $this->assertEquals($original->fallbackApplied, $restored->fallbackApplied);
            $this->assertEquals($original->fallbackReason, $restored->fallbackReason);
        });
    }
}