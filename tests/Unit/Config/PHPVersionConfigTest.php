<?php

namespace Laradock\PHPVersionManager\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\Config\PHPVersionConfig;

class PHPVersionConfigTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = new PHPVersionConfig();

        $this->assertEquals('8.4', $config->get('php_version'));
        $this->assertTrue($config->get('fallback_enabled'));
        $this->assertEquals('highest_stable', $config->get('fallback_strategy'));
        $this->assertTrue($config->get('version_check_enabled'));
        $this->assertTrue($config->get('update_notifications'));
        $this->assertEquals(3600, $config->get('cache_ttl'));
    }

    public function testCustomConfiguration(): void
    {
        $customConfig = [
            'php_version' => '8.5',
            'fallback_enabled' => false,
            'cache_ttl' => 7200
        ];

        $config = new PHPVersionConfig($customConfig);

        $this->assertEquals('8.5', $config->get('php_version'));
        $this->assertFalse($config->get('fallback_enabled'));
        $this->assertEquals(7200, $config->get('cache_ttl'));
        // Default values should still be present
        $this->assertEquals('highest_stable', $config->get('fallback_strategy'));
    }

    public function testGetWithDefault(): void
    {
        $config = new PHPVersionConfig();

        $this->assertEquals('default_value', $config->get('non_existent_key', 'default_value'));
        $this->assertNull($config->get('non_existent_key'));
    }

    public function testSetAndGet(): void
    {
        $config = new PHPVersionConfig();

        $config->set('custom_key', 'custom_value');
        $this->assertEquals('custom_value', $config->get('custom_key'));
    }

    public function testGetSupportedVersions(): void
    {
        $config = new PHPVersionConfig();
        $versions = $config->getSupportedVersions();

        $this->assertIsArray($versions);
        $this->assertContains('8.5', $versions);
        $this->assertContains('8.4', $versions);
        $this->assertContains('8.3', $versions);
    }

    public function testGetContainerTypes(): void
    {
        $config = new PHPVersionConfig();
        $types = $config->getContainerTypes();

        $this->assertIsArray($types);
        $this->assertContains('workspace', $types);
        $this->assertContains('php-fpm', $types);
        $this->assertContains('nginx', $types);
    }

    public function testIsFallbackEnabled(): void
    {
        $config = new PHPVersionConfig(['fallback_enabled' => false]);
        $this->assertFalse($config->isFallbackEnabled());

        $config = new PHPVersionConfig(['fallback_enabled' => true]);
        $this->assertTrue($config->isFallbackEnabled());
    }

    public function testGetFallbackStrategy(): void
    {
        $config = new PHPVersionConfig(['fallback_strategy' => 'exact_match']);
        $this->assertEquals('exact_match', $config->getFallbackStrategy());
    }

    public function testValidateValidConfiguration(): void
    {
        $config = new PHPVersionConfig([
            'php_version' => '8.4',
            'fallback_strategy' => 'highest_stable',
            'cache_ttl' => 3600
        ]);

        $errors = $config->validate();
        $this->assertEmpty($errors);
    }

    public function testValidateInvalidPhpVersion(): void
    {
        $config = new PHPVersionConfig(['php_version' => '7.4']);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unsupported PHP version: 7.4', implode(', ', $errors));
    }

    public function testValidateInvalidFallbackStrategy(): void
    {
        $config = new PHPVersionConfig(['fallback_strategy' => 'invalid_strategy']);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid fallback strategy: invalid_strategy', implode(', ', $errors));
    }

    public function testValidateInvalidCacheTtl(): void
    {
        $config = new PHPVersionConfig(['cache_ttl' => -1]);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Cache TTL must be a non-negative integer', implode(', ', $errors));
    }
}