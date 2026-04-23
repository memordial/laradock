<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use DateTime;
use Laradock\PHPVersionManager\Models\VersionConfiguration;
use Laradock\PHPVersionManager\Models\FallbackInfo;

/**
 * Unit tests for VersionConfiguration model
 */
class VersionConfigurationTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $config = new VersionConfiguration('8.5');
        
        $this->assertEquals('8.5', $config->requestedVersion);
        $this->assertEquals('8.5', $config->actualVersion);
        $this->assertFalse($config->fallbackApplied);
        $this->assertEquals('', $config->fallbackReason);
        $this->assertEmpty($config->containerVersions);
        $this->assertInstanceOf(DateTime::class, $config->lastUpdated);
    }

    public function testConstructorWithAllParameters(): void
    {
        $lastUpdated = new DateTime('2024-01-01 12:00:00');
        $containerVersions = ['workspace' => '8.4', 'php-fpm' => '8.4'];
        
        $config = new VersionConfiguration(
            '8.5',
            '8.4',
            true,
            'Version 8.5 unavailable',
            $containerVersions,
            $lastUpdated
        );
        
        $this->assertEquals('8.5', $config->requestedVersion);
        $this->assertEquals('8.4', $config->actualVersion);
        $this->assertTrue($config->fallbackApplied);
        $this->assertEquals('Version 8.5 unavailable', $config->fallbackReason);
        $this->assertEquals($containerVersions, $config->containerVersions);
        $this->assertEquals($lastUpdated, $config->lastUpdated);
    }

    public function testIsConsistentWithEmptyContainers(): void
    {
        $config = new VersionConfiguration('8.5');
        
        $this->assertTrue($config->isConsistent());
    }

    public function testIsConsistentWithMatchingVersions(): void
    {
        $config = new VersionConfiguration(
            '8.5',
            '8.5',
            false,
            '',
            ['workspace' => '8.5', 'php-fpm' => '8.5', 'nginx' => '8.5']
        );
        
        $this->assertTrue($config->isConsistent());
    }

    public function testIsConsistentWithMismatchedVersions(): void
    {
        $config = new VersionConfiguration(
            '8.5',
            '8.5',
            false,
            '',
            ['workspace' => '8.5', 'php-fpm' => '8.4', 'nginx' => '8.5']
        );
        
        $this->assertFalse($config->isConsistent());
    }

    public function testGetFallbackInfoWhenNoFallback(): void
    {
        $config = new VersionConfiguration('8.5');
        
        $this->assertNull($config->getFallbackInfo());
    }

    public function testGetFallbackInfoWhenFallbackApplied(): void
    {
        $config = new VersionConfiguration(
            '8.5',
            '8.4',
            true,
            'Version 8.5 unavailable'
        );
        
        $fallbackInfo = $config->getFallbackInfo();
        
        $this->assertInstanceOf(FallbackInfo::class, $fallbackInfo);
        $this->assertEquals('8.5', $fallbackInfo->requestedVersion);
        $this->assertEquals('8.4', $fallbackInfo->fallbackVersion);
        $this->assertEquals('Version 8.5 unavailable', $fallbackInfo->reason);
    }

    public function testToArray(): void
    {
        $lastUpdated = new DateTime('2024-01-01 12:00:00');
        $containerVersions = ['workspace' => '8.4', 'php-fpm' => '8.4'];
        
        $config = new VersionConfiguration(
            '8.5',
            '8.4',
            true,
            'Version 8.5 unavailable',
            $containerVersions,
            $lastUpdated
        );
        
        $array = $config->toArray();
        
        $expected = [
            'requestedVersion' => '8.5',
            'actualVersion' => '8.4',
            'fallbackApplied' => true,
            'fallbackReason' => 'Version 8.5 unavailable',
            'containerVersions' => $containerVersions,
            'lastUpdated' => '2024-01-01 12:00:00'
        ];
        
        $this->assertEquals($expected, $array);
    }

    public function testFromArray(): void
    {
        $data = [
            'requestedVersion' => '8.5',
            'actualVersion' => '8.4',
            'fallbackApplied' => true,
            'fallbackReason' => 'Version 8.5 unavailable',
            'containerVersions' => ['workspace' => '8.4', 'php-fpm' => '8.4'],
            'lastUpdated' => '2024-01-01 12:00:00'
        ];
        
        $config = VersionConfiguration::fromArray($data);
        
        $this->assertEquals('8.5', $config->requestedVersion);
        $this->assertEquals('8.4', $config->actualVersion);
        $this->assertTrue($config->fallbackApplied);
        $this->assertEquals('Version 8.5 unavailable', $config->fallbackReason);
        $this->assertEquals(['workspace' => '8.4', 'php-fpm' => '8.4'], $config->containerVersions);
        $this->assertEquals('2024-01-01 12:00:00', $config->lastUpdated->format('Y-m-d H:i:s'));
    }

    public function testFromArrayWithDefaults(): void
    {
        $data = [
            'requestedVersion' => '8.5'
        ];
        
        $config = VersionConfiguration::fromArray($data);
        
        $this->assertEquals('8.5', $config->requestedVersion);
        $this->assertEquals('8.5', $config->actualVersion);
        $this->assertFalse($config->fallbackApplied);
        $this->assertEquals('', $config->fallbackReason);
        $this->assertEmpty($config->containerVersions);
        $this->assertInstanceOf(DateTime::class, $config->lastUpdated);
    }
}