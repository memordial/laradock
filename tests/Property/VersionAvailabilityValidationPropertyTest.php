<?php

namespace Tests\Property;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\PHPVersionManager;
use Laradock\PHPVersionManager\VersionValidator;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;

/**
 * Property test for version availability validation
 * 
 * **Property 7: Version Availability Validation**
 * **Validates: Requirements 3.5, 7.2**
 * 
 * For any PHP version selection, the PHP Version Manager should validate 
 * that the version is available across all required container types before 
 * proceeding with configuration.
 */
class VersionAvailabilityValidationPropertyTest extends TestCase
{
    use TestTrait;

    private PHPVersionManager $phpManager;
    private ContainerRegistryMonitor $registryMonitor;
    private VersionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock registry monitor that we can control
        $this->registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
        $this->validator = new VersionValidator($this->registryMonitor);
        
        // Create temporary .env for testing
        $tempEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
        file_put_contents($tempEnvPath, "LARADOCK_PHP_VERSION=8.4\n");
        $envConfig = new EnvironmentConfig($tempEnvPath);
        
        $this->phpManager = new PHPVersionManager(
            $this->validator,
            $this->registryMonitor,
            $envConfig
        );
    }

    /**
     * Property: Version availability validation before configuration
     * 
     * For any PHP version selection, if the version is not available across
     * all required container types, the version manager should reject the
     * configuration and provide appropriate error messages.
     */
    public function testVersionAvailabilityValidationBeforeConfiguration(): void
    {
        $this->limitTo(5)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']), // Valid PHP versions
            Generator\associative([
                'workspace' => Generator\bool(),
                'php-fpm' => Generator\bool(),
                'nginx' => Generator\bool()
            ]) // Container availability map
        )->then(function ($version, $availability) {
            // Create a fresh mock for each test iteration
            $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
            $registryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($v, $container) use ($version, $availability) {
                    return $v === $version ? ($availability[$container] ?? false) : true;
                });

            $validator = new VersionValidator($registryMonitor);
            
            // Create temporary .env for testing
            $tempEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
            file_put_contents($tempEnvPath, "LARADOCK_PHP_VERSION=8.4\n");
            $envConfig = new EnvironmentConfig($tempEnvPath);
            
            $phpManager = new PHPVersionManager($validator, $registryMonitor, $envConfig);

            // Attempt to set the version
            $result = $phpManager->setVersion($version);
            
            // Check if all containers are available
            $allAvailable = array_reduce($availability, function ($carry, $available) {
                return $carry && $available;
            }, true);
            
            if ($allAvailable) {
                // If all containers are available, operation should succeed
                $this->assertTrue($result->success, 
                    "Version {$version} should be accepted when all containers are available");
                $this->assertEquals($version, $result->version,
                    "Result version should match requested version when available");
            } else {
                // If any container is unavailable, should either fail or apply fallback
                if (!$result->success) {
                    // Failed completely - should have descriptive error
                    $this->assertNotEmpty($result->message,
                        "Failed version setting should provide error message");
                } else {
                    // Applied fallback - result version might be different or same if fallback to same version
                    $this->assertStringContainsString('fallback', strtolower($result->message),
                        "Fallback message should mention fallback when containers unavailable");
                }
            }
            
            // Clean up
            unlink($tempEnvPath);
        });
    }

    /**
     * Property: Pre-flight validation consistency
     * 
     * For any PHP version, the pre-flight validation should consistently
     * report the same availability status across multiple checks.
     */
    public function testPreFlightValidationConsistency(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\associative([
                'workspace' => Generator\bool(),
                'php-fpm' => Generator\bool(),
                'nginx' => Generator\bool()
            ])
        )->then(function ($version, $availability) {
            // Create fresh mock for this iteration
            $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
            $registryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($v, $container) use ($version, $availability) {
                    return $v === $version ? ($availability[$container] ?? false) : true;
                });

            $validator = new VersionValidator($registryMonitor);

            // Perform multiple pre-flight validations
            $validation1 = $validator->performPreFlightValidation($version);
            $validation2 = $validator->performPreFlightValidation($version);
            
            // Results should be consistent
            $this->assertEquals($validation1->isValid, $validation2->isValid,
                "Pre-flight validation should be consistent across multiple calls");
            $this->assertEquals($validation1->errors, $validation2->errors,
                "Pre-flight validation errors should be consistent");
        });
    }

    /**
     * Property: Container compatibility checking
     * 
     * For any PHP version and container set, the compatibility check should
     * accurately reflect the availability status from the registry monitor.
     */
    public function testContainerCompatibilityChecking(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\subset(['workspace', 'php-fpm', 'nginx']) // Subset of containers
        )->then(function ($version, $containers) {
            // Skip empty container sets
            if (empty($containers)) {
                return;
            }
            
            // Create availability map - some available, some not
            $availability = [];
            foreach ($containers as $i => $container) {
                $availability[$container] = ($i % 2 === 0); // Alternate availability
            }
            
            // Create fresh mock for this iteration
            $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
            $registryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($v, $container) use ($version, $availability) {
                    return $v === $version ? ($availability[$container] ?? false) : true;
                });

            $validator = new VersionValidator($registryMonitor);

            // Check compatibility
            $compatibility = $validator->checkContainerCompatibility($version, $containers);
            
            // Verify compatibility matches expected availability
            foreach ($containers as $container) {
                $expected = $availability[$container];
                $actual = $compatibility[$container];
                
                $this->assertEquals($expected, $actual,
                    "Container {$container} compatibility should match registry availability");
            }
        });
    }

    /**
     * Property: Validation error messages are descriptive
     * 
     * For any invalid version configuration, the validation should provide
     * specific error messages that identify the problematic containers.
     */
    public function testValidationErrorMessagesAreDescriptive(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\associative([
                'workspace' => Generator\bool(),
                'php-fpm' => Generator\bool(),
                'nginx' => Generator\bool()
            ])
        )->then(function ($version, $availability) {
            // Create fresh mock for this iteration
            $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
            $registryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($v, $container) use ($version, $availability) {
                    return $v === $version ? ($availability[$container] ?? false) : true;
                });

            $validator = new VersionValidator($registryMonitor);

            // Perform pre-flight validation
            $validation = $validator->performPreFlightValidation($version);
            
            // Find unavailable containers
            $unavailableContainers = [];
            foreach ($availability as $container => $available) {
                if (!$available) {
                    $unavailableContainers[] = $container;
                }
            }
            
            if (!empty($unavailableContainers)) {
                // Should have errors
                $this->assertFalse($validation->isValid,
                    "Validation should fail when containers are unavailable");
                $this->assertNotEmpty($validation->errors,
                    "Should have error messages for unavailable containers");
                
                // Error messages should mention the specific containers
                $errorText = implode(' ', $validation->errors);
                foreach ($unavailableContainers as $container) {
                    $this->assertStringContainsString($container, $errorText,
                        "Error message should mention unavailable container: {$container}");
                }
            } else {
                // All containers available - should be valid
                $this->assertTrue($validation->isValid,
                    "Validation should succeed when all containers are available");
            }
        });
    }

    /**
     * Property: Available versions list accuracy
     * 
     * For any registry state, the getAvailableVersions method should
     * accurately reflect which versions are fully available across
     * all required container types.
     */
    public function testAvailableVersionsListAccuracy(): void
    {
        $this->limitTo(3)->forAll(
            Generator\associative([
                '8.1' => Generator\associative([
                    'workspace' => Generator\bool(),
                    'php-fpm' => Generator\bool(),
                    'nginx' => Generator\bool()
                ]),
                '8.2' => Generator\associative([
                    'workspace' => Generator\bool(),
                    'php-fpm' => Generator\bool(),
                    'nginx' => Generator\bool()
                ]),
                '8.3' => Generator\associative([
                    'workspace' => Generator\bool(),
                    'php-fpm' => Generator\bool(),
                    'nginx' => Generator\bool()
                ])
            ])
        )->then(function ($versionAvailability) {
            // Create fresh mock for this iteration
            $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
            $registryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($version, $container) use ($versionAvailability) {
                    return $versionAvailability[$version][$container] ?? false;
                });

            $validator = new VersionValidator($registryMonitor);
            
            // Create temporary .env for testing
            $tempEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
            file_put_contents($tempEnvPath, "LARADOCK_PHP_VERSION=8.4\n");
            $envConfig = new EnvironmentConfig($tempEnvPath);
            
            $phpManager = new PHPVersionManager($validator, $registryMonitor, $envConfig);

            // Get available versions
            $availableVersions = $phpManager->getAvailableVersions();
            
            // Verify accuracy for each version
            foreach ($versionAvailability as $version => $containerAvailability) {
                $expectedFullyAvailable = array_reduce($containerAvailability, function ($carry, $available) {
                    return $carry && $available;
                }, true);
                
                $actualFullyAvailable = $availableVersions[$version]['fullyAvailable'];
                
                $this->assertEquals($expectedFullyAvailable, $actualFullyAvailable,
                    "Version {$version} fully available status should match registry state");
                
                // Check individual container availability
                foreach ($containerAvailability as $container => $expected) {
                    $actual = $availableVersions[$version]['containerAvailability'][$container];
                    $this->assertEquals($expected, $actual,
                        "Version {$version} availability for {$container} should match registry");
                }
            }
            
            // Clean up
            unlink($tempEnvPath);
        });
    }
}