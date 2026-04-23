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
 * Property-based tests for pre-startup validation
 * 
 * **Validates: Requirements 2.5, 7.4**
 */
class PreStartupValidationPropertyTest extends TestCase
{
    use TestTrait;

    private VersionValidator $validator;
    private ContainerRegistryMonitorInterface $registryMonitor;
    private PHPVersionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock registry monitor with default availability
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
     * Property 4: Pre-startup Validation
     * 
     * For any development environment startup attempt, version consistency validation
     * should occur before any PHP-related services are started, preventing inconsistent
     * environments.
     * 
     * **Validates: Requirements 2.5, 7.4**
     */
    public function testPreStartupValidationPreventsInconsistentEnvironments(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']), // PHP version
            Generator\subset(['workspace', 'php-fpm', 'nginx'])       // Containers to validate
        )->then(function ($version, $containers) {
            // Skip empty container sets
            if (empty($containers)) {
                $containers = ['workspace', 'php-fpm', 'nginx'];
            }

            // Perform pre-flight validation
            $result = $this->validator->performPreFlightValidation($version, $containers);
            
            // Pre-flight validation should complete (success or failure)
            $this->assertInstanceOf(
                'Laradock\PHPVersionManager\Models\ValidationResult', 
                $result,
                "Pre-flight validation should return ValidationResult"
            );
            
            // If validation passes, environment should be consistent
            if ($result->isValid) {
                // Verify that all requested containers are compatible
                $compatibility = $this->validator->checkContainerCompatibility($version, $containers);
                
                foreach ($compatibility as $container => $compatible) {
                    $this->assertTrue(
                        $compatible, 
                        "Container {$container} should be compatible with PHP {$version} when pre-flight validation passes"
                    );
                }
                
                // Version should be valid format
                $this->assertTrue(
                    $this->validator->validateVersion($version),
                    "PHP version {$version} should be valid when pre-flight validation passes"
                );
            } else {
                // If validation fails, should have descriptive errors
                $this->assertTrue(
                    $result->hasErrors(),
                    "Failed pre-flight validation should provide error details"
                );
                
                // Errors should be informative
                $errorSummary = $result->getErrorSummary();
                $this->assertNotEmpty($errorSummary, "Pre-flight validation errors should be descriptive");
                $this->assertStringContainsString($version, $errorSummary, "Error should mention the requested version");
            }
        });
    }

    /**
     * Property: Pre-flight Validation with Unavailable Containers
     * 
     * When containers don't support the requested PHP version, pre-flight validation
     * should fail and suggest available alternatives.
     */
    public function testPreFlightValidationWithUnavailableContainers(): void
    {
        // Configure registry monitor to simulate unavailable versions
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->registryMonitor->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                // Simulate PHP 8.5 being unavailable for workspace
                if ($version === '8.5' && $containerType === 'workspace') {
                    return false;
                }
                // All other combinations are available
                return true;
            });

        $validator = new VersionValidator($this->registryMonitor);

        $this->limitTo(3)->forAll(
            Generator\elements(['8.5']), // Version unavailable for workspace
            Generator\elements([
                ['workspace'],
                ['workspace', 'php-fpm'],
                ['workspace', 'php-fpm', 'nginx']
            ])
        )->then(function ($version, $containers) use ($validator) {
            // Perform pre-flight validation
            $result = $validator->performPreFlightValidation($version, $containers);
            
            // Validation should fail due to unavailable container
            $this->assertFalse($result->isValid, "Pre-flight validation should fail when containers are unavailable");
            $this->assertTrue($result->hasErrors(), "Should provide error details for unavailable containers");
            
            // Error should mention the unavailable container
            $errorSummary = $result->getErrorSummary();
            $this->assertStringContainsString('workspace', $errorSummary, "Error should identify unavailable workspace container");
            $this->assertStringContainsString($version, $errorSummary, "Error should mention requested version");
            $this->assertStringContainsString('Pre-flight check failed', $errorSummary, "Error should indicate pre-flight failure");
            
            // Should provide suggestions for alternative versions
            $this->assertNotEmpty($result->suggestions, "Should suggest alternative versions when containers are unavailable");
            
            $suggestionText = implode(' ', $result->suggestions);
            $this->assertStringContainsString('available versions', strtolower($suggestionText), "Should suggest available versions");
        });
    }

    /**
     * Property: Environment Validation Before Service Startup
     * 
     * Environment validation should detect inconsistencies before any services start,
     * preventing partially configured environments.
     */
    public function testEnvironmentValidationBeforeServiceStartup(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']), // Base version
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])  // Environment version (potentially different)
        )->then(function ($requestedVersion, $envVersion) {
            // Set up environment with potentially different version
            $envConfig = new EnvironmentConfig('/tmp/test.env');
            $envConfig->setPhpVersion($envVersion);
            
            // Create manager with this environment
            $manager = new PHPVersionManager(
                $this->validator,
                $this->registryMonitor,
                $envConfig,
                new PHPVersionConfig()
            );
            
            // Validate environment before attempting to start services
            $validationResult = $manager->validateEnvironment();
            
            // Validation should complete
            $this->assertInstanceOf(
                'Laradock\PHPVersionManager\Models\ValidationResult',
                $validationResult,
                "Environment validation should return ValidationResult"
            );
            
            if ($requestedVersion === $envVersion) {
                // If versions match, validation should pass
                $this->assertTrue(
                    $validationResult->isValid,
                    "Environment validation should pass when versions are consistent"
                );
            }
            
            // If validation fails, should prevent service startup
            if (!$validationResult->isValid) {
                $this->assertTrue(
                    $validationResult->hasErrors(),
                    "Failed environment validation should provide error details"
                );
                
                // Errors should be actionable
                $errorSummary = $validationResult->getErrorSummary();
                $this->assertNotEmpty($errorSummary, "Environment validation errors should be descriptive");
            }
        });
    }

    /**
     * Property: Consistency Check Integration
     * 
     * Pre-startup validation should integrate with consistency checking to ensure
     * all containers will use the same PHP version before startup.
     */
    public function testConsistencyCheckIntegration(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($version) {
            // Set version through manager
            $setResult = $this->manager->setVersion($version);
            
            // If version setting succeeds, consistency check should pass
            if ($setResult->success) {
                $consistencyReport = $this->manager->checkConsistency();
                
                $this->assertTrue(
                    $consistencyReport->isConsistent,
                    "Consistency check should pass after successful version setting"
                );
                
                $this->assertEmpty(
                    $consistencyReport->mismatches,
                    "No mismatches should exist after successful version setting"
                );
                
                // Environment validation should also pass
                $envValidation = $this->manager->validateEnvironment();
                $this->assertTrue(
                    $envValidation->isValid,
                    "Environment validation should pass when consistency check passes"
                );
            }
        });
    }

    /**
     * Property: Pre-flight Validation Format Checking
     * 
     * Pre-flight validation should reject invalid version formats before
     * attempting any container operations.
     */
    public function testPreFlightValidationFormatChecking(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8', '8.', '.5', '8.5.0', 'php8.5', '8.5-dev', 'invalid', '7.4', '9.0'])
        )->then(function ($invalidVersion) {
            // Perform pre-flight validation with invalid version
            $result = $this->validator->performPreFlightValidation($invalidVersion);
            
            // Validation should fail for invalid format
            $this->assertFalse($result->isValid, "Pre-flight validation should fail for invalid version format");
            $this->assertTrue($result->hasErrors(), "Should provide error details for invalid format");
            
            // Error should mention the invalid version format
            $errorSummary = $result->getErrorSummary();
            $this->assertStringContainsString('Invalid PHP version format', $errorSummary, "Error should indicate invalid format");
            $this->assertStringContainsString($invalidVersion, $errorSummary, "Error should mention the invalid version");
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