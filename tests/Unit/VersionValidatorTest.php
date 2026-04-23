<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\VersionValidator;
use Laradock\PHPVersionManager\ContainerRegistryMonitorInterface;
use Laradock\PHPVersionManager\Models\ValidationResult;

/**
 * Unit tests for VersionValidator
 * 
 * Tests specific examples and edge cases for version validation logic.
 */
class VersionValidatorTest extends TestCase
{
    private VersionValidator $validator;
    private ContainerRegistryMonitorInterface $registryMonitor;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock registry monitor
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->validator = new VersionValidator($this->registryMonitor);
    }

    /**
     * Test valid PHP version formats
     */
    public function testValidateVersionWithValidFormats(): void
    {
        $validVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];
        
        foreach ($validVersions as $version) {
            $this->assertTrue(
                $this->validator->validateVersion($version),
                "Version {$version} should be valid"
            );
        }
    }

    /**
     * Test invalid PHP version formats
     */
    public function testValidateVersionWithInvalidFormats(): void
    {
        $invalidVersions = [
            '8',           // Missing minor version
            '8.',          // Missing minor number
            '.5',          // Missing major version
            '8.5.0',       // Too many version parts
            'php8.5',      // Contains text
            '8.5-dev',     // Contains suffix
            '',            // Empty string
            '8.10',        // Unsupported version
            '7.4',         // Below minimum
            '9.0'          // Above maximum
        ];
        
        foreach ($invalidVersions as $version) {
            $this->assertFalse(
                $this->validator->validateVersion($version),
                "Version '{$version}' should be invalid"
            );
        }
    }

    /**
     * Test container compatibility checking
     */
    public function testCheckContainerCompatibility(): void
    {
        // Configure mock to return specific availability
        $this->registryMonitor->method('checkAvailability')
            ->willReturnCallback(function ($version, $container) {
                // Simulate workspace not supporting PHP 8.5
                if ($version === '8.5' && $container === 'workspace') {
                    return false;
                }
                return true;
            });

        $containers = ['workspace', 'php-fpm', 'nginx'];
        
        // Test with available version
        $compatibility = $this->validator->checkContainerCompatibility('8.4', $containers);
        $this->assertEquals([
            'workspace' => true,
            'php-fpm' => true,
            'nginx' => true
        ], $compatibility);
        
        // Test with partially unavailable version
        $compatibility = $this->validator->checkContainerCompatibility('8.5', $containers);
        $this->assertEquals([
            'workspace' => false,
            'php-fpm' => true,
            'nginx' => true
        ], $compatibility);
    }

    /**
     * Test container compatibility with unsupported container types
     */
    public function testCheckContainerCompatibilityWithUnsupportedContainers(): void
    {
        $this->registryMonitor->method('checkAvailability')->willReturn(true);
        
        $containers = ['workspace', 'unsupported-container', 'php-fpm'];
        $compatibility = $this->validator->checkContainerCompatibility('8.4', $containers);
        
        $this->assertTrue($compatibility['workspace']);
        $this->assertFalse($compatibility['unsupported-container']);
        $this->assertTrue($compatibility['php-fpm']);
    }

    /**
     * Test version constraints retrieval
     */
    public function testGetVersionConstraints(): void
    {
        $constraints = $this->validator->getVersionConstraints();
        
        $this->assertIsArray($constraints);
        $this->assertArrayHasKey('minimum', $constraints);
        $this->assertArrayHasKey('maximum', $constraints);
        $this->assertArrayHasKey('recommended', $constraints);
        
        $this->assertEquals('8.1', $constraints['minimum']);
        $this->assertEquals('8.5', $constraints['maximum']);
        $this->assertEquals('8.4', $constraints['recommended']);
    }

    /**
     * Test configuration validation with valid configuration
     */
    public function testValidateConfigurationWithValidConfig(): void
    {
        $this->registryMonitor->method('checkAvailability')->willReturn(true);
        
        $config = [
            'php_version' => '8.4',
            'containers' => ['workspace', 'php-fpm', 'nginx'],
            'container_versions' => [
                'workspace' => '8.4',
                'php-fpm' => '8.4',
                'nginx' => '8.4'
            ]
        ];
        
        $result = $this->validator->validateConfiguration($config);
        
        $this->assertTrue($result->isValid);
        $this->assertEmpty($result->errors);
    }

    /**
     * Test configuration validation with missing PHP version
     */
    public function testValidateConfigurationWithMissingPhpVersion(): void
    {
        $config = [
            'containers' => ['workspace', 'php-fpm', 'nginx']
        ];
        
        $result = $this->validator->validateConfiguration($config);
        
        $this->assertFalse($result->isValid);
        $this->assertContains('PHP version not specified in configuration', $result->errors);
    }

    /**
     * Test configuration validation with invalid PHP version
     */
    public function testValidateConfigurationWithInvalidPhpVersion(): void
    {
        $config = [
            'php_version' => 'invalid-version',
            'containers' => ['workspace', 'php-fpm', 'nginx']
        ];
        
        $result = $this->validator->validateConfiguration($config);
        
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Invalid or unsupported PHP version', $result->getErrorSummary());
    }

    /**
     * Test configuration validation with container incompatibility
     */
    public function testValidateConfigurationWithContainerIncompatibility(): void
    {
        // Configure mock to simulate unavailable container
        $this->registryMonitor->method('checkAvailability')
            ->willReturnCallback(function ($version, $container) {
                return $container !== 'workspace'; // workspace unavailable
            });
        
        $config = [
            'php_version' => '8.5',
            'containers' => ['workspace', 'php-fpm', 'nginx']
        ];
        
        $result = $this->validator->validateConfiguration($config);
        
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('not available for containers: workspace', $result->getErrorSummary());
    }

    /**
     * Test configuration validation with version inconsistencies
     */
    public function testValidateConfigurationWithVersionInconsistencies(): void
    {
        $this->registryMonitor->method('checkAvailability')->willReturn(true);
        
        $config = [
            'php_version' => '8.4',
            'containers' => ['workspace', 'php-fpm', 'nginx'],
            'container_versions' => [
                'workspace' => '8.4',
                'php-fpm' => '8.3', // Inconsistent version
                'nginx' => '8.4'
            ]
        ];
        
        $result = $this->validator->validateConfiguration($config);
        
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Version mismatch: php-fpm uses PHP 8.3, expected PHP 8.4', $result->getErrorSummary());
    }

    /**
     * Test configuration validation with newer than recommended version
     */
    public function testValidateConfigurationWithNewerThanRecommendedVersion(): void
    {
        $this->registryMonitor->method('checkAvailability')->willReturn(true);
        
        $config = [
            'php_version' => '8.5', // Newer than recommended 8.4
            'containers' => ['workspace', 'php-fpm', 'nginx']
        ];
        
        $result = $this->validator->validateConfiguration($config);
        
        $this->assertTrue($result->isValid); // Should still be valid
        $this->assertTrue($result->hasWarnings()); // But should have warnings
        $this->assertStringContainsString('newer than recommended', $result->getWarningSummary());
    }

    /**
     * Test pre-flight validation with valid version
     */
    public function testPerformPreFlightValidationWithValidVersion(): void
    {
        $this->registryMonitor->method('checkAvailability')->willReturn(true);
        
        $result = $this->validator->performPreFlightValidation('8.4');
        
        $this->assertTrue($result->isValid);
        $this->assertEmpty($result->errors);
    }

    /**
     * Test pre-flight validation with invalid version format
     */
    public function testPerformPreFlightValidationWithInvalidFormat(): void
    {
        $result = $this->validator->performPreFlightValidation('invalid-version');
        
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Invalid PHP version format', $result->getErrorSummary());
    }

    /**
     * Test pre-flight validation with unavailable containers
     */
    public function testPerformPreFlightValidationWithUnavailableContainers(): void
    {
        // Configure mock to simulate unavailable containers
        $this->registryMonitor->method('checkAvailability')
            ->willReturnCallback(function ($version, $container) {
                // workspace unavailable for 8.5, but other versions available
                if ($version === '8.5' && $container === 'workspace') {
                    return false;
                }
                // Make 8.4 available for all containers to ensure suggestions
                if ($version === '8.4') {
                    return true;
                }
                return true;
            });
        
        $result = $this->validator->performPreFlightValidation('8.5', ['workspace', 'php-fpm']);
        
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Pre-flight check failed', $result->getErrorSummary());
        $this->assertStringContainsString('workspace', $result->getErrorSummary());
        
        // Should have suggestions since 8.4 is available for all containers
        $this->assertNotEmpty($result->suggestions, "Should provide suggestions when alternative versions are available");
    }

    /**
     * Test configuration conflict detection with version mismatches
     */
    public function testDetectConfigurationConflictsWithVersionMismatches(): void
    {
        $envConfig = [
            'LARADOCK_PHP_VERSION' => '8.4',
            'WORKSPACE_PHP_VERSION' => '8.5', // Different version
            'PHP_FPM_VERSION' => '8.4'
        ];
        
        $conflicts = $this->validator->detectConfigurationConflicts($envConfig);
        
        $this->assertNotEmpty($conflicts);
        
        $versionMismatchFound = false;
        foreach ($conflicts as $conflict) {
            if ($conflict['type'] === 'version_mismatch') {
                $versionMismatchFound = true;
                $this->assertStringContainsString('Conflicting PHP versions', $conflict['message']);
                $this->assertArrayHasKey('details', $conflict);
                $this->assertArrayHasKey('resolution', $conflict);
                break;
            }
        }
        
        $this->assertTrue($versionMismatchFound);
    }

    /**
     * Test configuration conflict detection with invalid version formats
     */
    public function testDetectConfigurationConflictsWithInvalidFormats(): void
    {
        $envConfig = [
            'LARADOCK_PHP_VERSION' => 'invalid-version',
            'PHP_FPM_VERSION' => '8.4'
        ];
        
        $conflicts = $this->validator->detectConfigurationConflicts($envConfig);
        
        $this->assertNotEmpty($conflicts);
        
        $invalidVersionFound = false;
        foreach ($conflicts as $conflict) {
            if ($conflict['type'] === 'invalid_version') {
                $invalidVersionFound = true;
                $this->assertStringContainsString('Invalid PHP version format', $conflict['message']);
                $this->assertStringContainsString('invalid-version', $conflict['message']);
                break;
            }
        }
        
        $this->assertTrue($invalidVersionFound);
    }

    /**
     * Test configuration conflict detection with no conflicts
     */
    public function testDetectConfigurationConflictsWithNoConflicts(): void
    {
        $envConfig = [
            'LARADOCK_PHP_VERSION' => '8.4',
            'WORKSPACE_PHP_VERSION' => '8.4',
            'PHP_FPM_VERSION' => '8.4'
        ];
        
        $conflicts = $this->validator->detectConfigurationConflicts($envConfig);
        
        $this->assertEmpty($conflicts);
    }
}