<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\PHPVersionManager;
use Laradock\PHPVersionManager\VersionValidatorInterface;
use Laradock\PHPVersionManager\ContainerRegistryMonitorInterface;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;
use Laradock\PHPVersionManager\Config\PHPVersionConfig;

/**
 * Property-based tests for version configuration consistency
 * 
 * **Validates: Requirements 2.1, 2.2, 2.3**
 */
class VersionConfigurationConsistencyPropertyTest extends TestCase
{
    use TestTrait;

    private PHPVersionManager $manager;
    private VersionValidatorInterface $validator;
    private ContainerRegistryMonitorInterface $registryMonitor;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock validator that accepts valid PHP versions
        $this->validator = $this->createMock(VersionValidatorInterface::class);
        $this->validator->method('validateVersion')
            ->willReturnCallback(fn($version) => in_array($version, ['8.1', '8.2', '8.3', '8.4', '8.5']));
        
        $this->validator->method('checkContainerCompatibility')
            ->willReturn(['workspace' => true, 'php-fpm' => true, 'nginx' => true]);

        // Create mock registry monitor that reports all versions as available
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->registryMonitor->method('checkAvailability')
            ->willReturn(true);

        // Create temporary environment config for testing
        $envConfig = new EnvironmentConfig('/tmp/test.env');
        $versionConfig = new PHPVersionConfig();

        $this->manager = new PHPVersionManager(
            $this->validator,
            $this->registryMonitor,
            $envConfig,
            $versionConfig
        );
    }

    /**
     * Property 1: Version Configuration Consistency
     * 
     * For any PHP version configuration, when applied to the development environment,
     * all PHP containers (workspace, php-fpm, nginx) should use the same PHP version.
     * 
     * **Validates: Requirements 2.1, 2.2, 2.3**
     */
    public function testVersionConsistencyAcrossContainers(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($version) {
            $result = $this->manager->setVersion($version);
            
            // The operation should succeed
            $this->assertTrue($result->success, "Setting PHP version {$version} should succeed");
            
            // All containers should use the same version
            $this->assertTrue($result->isConsistent(), "All containers should use consistent PHP versions");
            
            // Each container should use the expected version
            $this->assertEquals($version, $result->getWorkspaceVersion(), "Workspace should use PHP {$version}");
            $this->assertEquals($version, $result->getPhpFpmVersion(), "PHP-FPM should use PHP {$version}");
            $this->assertEquals($version, $result->getNginxVersion(), "Nginx should use PHP {$version}");
            
            // Consistency check should pass
            $consistencyReport = $this->manager->checkConsistency();
            $this->assertTrue($consistencyReport->isConsistent, "Consistency check should pass after setting version");
        });
    }

    /**
     * Property: Version Configuration with Partial Availability
     * 
     * When some containers don't support a requested version, fallback should
     * ensure all containers still use the same (fallback) version.
     */
    public function testVersionConsistencyWithFallback(): void
    {
        // Configure registry monitor to simulate unavailable versions
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->registryMonitor->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                // Simulate PHP 8.5 being unavailable for workspace
                if ($version === '8.5' && $containerType === 'workspace') {
                    return false;
                }
                return true;
            });

        // Recreate manager with updated registry monitor
        $envConfig = new EnvironmentConfig('/tmp/test.env');
        $versionConfig = new PHPVersionConfig();
        
        $this->manager = new PHPVersionManager(
            $this->validator,
            $this->registryMonitor,
            $envConfig,
            $versionConfig
        );

        $this->limitTo(3)->forAll(
            Generator\elements(['8.5']) // Test with unavailable version
        )->then(function ($version) {
            $result = $this->manager->setVersion($version);
            
            // The operation should succeed (with fallback)
            $this->assertTrue($result->success, "Setting PHP version {$version} should succeed with fallback");
            
            // All containers should use the same version (even if it's a fallback)
            $this->assertTrue($result->isConsistent(), "All containers should use consistent PHP versions after fallback");
            
            // All container versions should be identical
            $workspaceVersion = $result->getWorkspaceVersion();
            $phpFpmVersion = $result->getPhpFpmVersion();
            $nginxVersion = $result->getNginxVersion();
            
            $this->assertEquals($workspaceVersion, $phpFpmVersion, "Workspace and PHP-FPM should use same version");
            $this->assertEquals($phpFpmVersion, $nginxVersion, "PHP-FPM and Nginx should use same version");
            
            // Consistency check should pass
            $consistencyReport = $this->manager->checkConsistency();
            $this->assertTrue($consistencyReport->isConsistent, "Consistency check should pass after fallback");
        });
    }

    /**
     * Property: Environment Validation Consistency
     * 
     * For any valid environment configuration, validation should confirm
     * that all containers use consistent PHP versions.
     */
    public function testEnvironmentValidationConsistency(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($version) {
            // First set the version
            $setResult = $this->manager->setVersion($version);
            $this->assertTrue($setResult->success, "Version setting should succeed");
            
            // Then validate the environment
            $validationResult = $this->manager->validateEnvironment();
            
            // Validation should pass for consistent configurations
            $this->assertTrue($validationResult->isValid, "Environment validation should pass for consistent configuration");
            $this->assertEmpty($validationResult->errors, "No validation errors should exist for consistent configuration");
        });
    }

    protected function tearDown(): void
    {
        // Clean up test environment file
        if (file_exists('/tmp/test.env')) {
            unlink('/tmp/test.env');
        }
        parent::tearDown();
    }
}