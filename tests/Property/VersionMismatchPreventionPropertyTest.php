<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\VersionValidator;
use Laradock\PHPVersionManager\ContainerRegistryMonitorInterface;
use Laradock\PHPVersionManager\PHPVersionManager;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;
use Laradock\PHPVersionManager\Config\PHPVersionConfig;

/**
 * Property-based tests for version mismatch prevention
 * 
 * **Validates: Requirements 2.4, 7.1**
 */
class VersionMismatchPreventionPropertyTest extends TestCase
{
    use TestTrait;

    private VersionValidator $validator;
    private ContainerRegistryMonitorInterface $registryMonitor;
    private PHPVersionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock registry monitor
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->registryMonitor->method('checkAvailability')
            ->willReturn(true);

        $this->validator = new VersionValidator($this->registryMonitor);

        // Create manager for integration tests
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
     * Property 3: Version Mismatch Prevention
     * 
     * For any configuration with version mismatches detected, the PHP Version Manager
     * should prevent container startup and display descriptive error messages indicating
     * the specific conflicts.
     * 
     * **Validates: Requirements 2.4, 7.1**
     */
    public function testVersionMismatchPrevention(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']), // Base version
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']), // Different version for mismatch
            Generator\elements(['workspace', 'php-fpm', 'nginx'])     // Container with mismatch
        )->then(function ($baseVersion, $mismatchVersion, $mismatchContainer) {
            // Skip if versions are the same (no mismatch to test)
            if ($baseVersion === $mismatchVersion) {
                return;
            }

            // Create configuration with version mismatch
            $containerVersions = [
                'workspace' => $baseVersion,
                'php-fpm' => $baseVersion,
                'nginx' => $baseVersion
            ];
            
            // Introduce mismatch in one container
            $containerVersions[$mismatchContainer] = $mismatchVersion;
            
            $config = [
                'php_version' => $baseVersion,
                'containers' => ['workspace', 'php-fpm', 'nginx'],
                'container_versions' => $containerVersions
            ];

            // Validate configuration - should detect mismatch
            $result = $this->validator->validateConfiguration($config);
            
            // Validation should fail due to mismatch
            $this->assertFalse($result->isValid, "Configuration with version mismatch should be invalid");
            $this->assertTrue($result->hasErrors(), "Version mismatch should generate errors");
            
            // Error message should be descriptive and mention specific conflict
            $errorSummary = $result->getErrorSummary();
            $this->assertStringContainsString('mismatch', strtolower($errorSummary), "Error should mention version mismatch");
            $this->assertStringContainsString($mismatchContainer, $errorSummary, "Error should identify conflicting container");
            $this->assertStringContainsString($baseVersion, $errorSummary, "Error should mention expected version");
            $this->assertStringContainsString($mismatchVersion, $errorSummary, "Error should mention actual version");
        });
    }

    /**
     * Property: Configuration Conflict Detection
     * 
     * For any environment configuration with conflicting PHP version settings,
     * the validator should detect and report specific conflicts with resolution guidance.
     */
    public function testConfigurationConflictDetection(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']), // First version
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])  // Second version
        )->then(function ($version1, $version2) {
            // Skip if versions are the same (no conflict to test)
            if ($version1 === $version2) {
                return;
            }

            // Create environment config with conflicting versions
            $envConfig = [
                'LARADOCK_PHP_VERSION' => $version1,
                'WORKSPACE_PHP_VERSION' => $version2,
                'PHP_FPM_VERSION' => $version1
            ];

            // Detect conflicts
            $conflicts = $this->validator->detectConfigurationConflicts($envConfig);
            
            // Should detect version mismatch conflict
            $this->assertNotEmpty($conflicts, "Should detect configuration conflicts");
            
            $versionMismatchFound = false;
            foreach ($conflicts as $conflict) {
                if ($conflict['type'] === 'version_mismatch') {
                    $versionMismatchFound = true;
                    
                    // Conflict should have descriptive message
                    $this->assertStringContainsString('Conflicting PHP versions', $conflict['message']);
                    
                    // Should include details about conflicting versions
                    $this->assertArrayHasKey('details', $conflict);
                    $this->assertArrayHasKey('resolution', $conflict);
                    
                    // Details should contain the conflicting versions
                    $details = $conflict['details'];
                    $this->assertContains($version1, $details);
                    $this->assertContains($version2, $details);
                    
                    break;
                }
            }
            
            $this->assertTrue($versionMismatchFound, "Should detect version mismatch conflict");
        });
    }

    /**
     * Property: Invalid Version Format Detection
     * 
     * For any configuration with invalid PHP version formats,
     * the validator should prevent startup and provide clear error messages.
     */
    public function testInvalidVersionFormatPrevention(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8', '8.', '.5', '8.5.0', 'php8.5', '8.5-dev', 'invalid', '7.4', '9.0'])
        )->then(function ($invalidVersion) {
            // Skip empty strings as they're handled differently
            if (empty($invalidVersion)) {
                return;
            }

            $config = [
                'php_version' => $invalidVersion,
                'containers' => ['workspace', 'php-fpm', 'nginx']
            ];

            // Validate configuration - should reject invalid version
            $result = $this->validator->validateConfiguration($config);
            
            // Validation should fail for invalid version format
            $this->assertFalse($result->isValid, "Configuration with invalid version format should be invalid");
            $this->assertTrue($result->hasErrors(), "Invalid version format should generate errors");
            
            // Error message should mention the invalid version
            $errorSummary = $result->getErrorSummary();
            $this->assertStringContainsString($invalidVersion, $errorSummary, "Error should mention the invalid version");
            $this->assertStringContainsString('Invalid', $errorSummary, "Error should indicate version is invalid");
        });
    }

    /**
     * Property: Container Availability Validation
     * 
     * When PHP version is unavailable for specific containers, validation should
     * prevent startup and identify which containers lack support.
     */
    public function testContainerAvailabilityValidation(): void
    {
        // Configure registry monitor to simulate unavailable versions
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->registryMonitor->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                // Simulate PHP 8.5 being unavailable for workspace
                return !($version === '8.5' && $containerType === 'workspace');
            });

        $validator = new VersionValidator($this->registryMonitor);

        $this->limitTo(3)->forAll(
            Generator\elements(['8.5']) // Test with version unavailable for workspace
        )->then(function ($version) use ($validator) {
            $config = [
                'php_version' => $version,
                'containers' => ['workspace', 'php-fpm', 'nginx']
            ];

            // Validate configuration - should detect unavailable container
            $result = $validator->validateConfiguration($config);
            
            // Validation should fail due to unavailable container
            $this->assertFalse($result->isValid, "Configuration with unavailable container should be invalid");
            $this->assertTrue($result->hasErrors(), "Unavailable container should generate errors");
            
            // Error should identify the specific unavailable container
            $errorSummary = $result->getErrorSummary();
            $this->assertStringContainsString('workspace', $errorSummary, "Error should identify workspace as unavailable");
            $this->assertStringContainsString($version, $errorSummary, "Error should mention the requested version");
            $this->assertStringContainsString('not available', strtolower($errorSummary), "Error should indicate unavailability");
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