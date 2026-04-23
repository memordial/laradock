<?php

namespace Tests\Property;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\DockerComposeManager;
use Laradock\PHPVersionManager\PHPVersionManager;
use Laradock\PHPVersionManager\VersionValidator;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;

/**
 * Property test for docker-compose integration
 * 
 * **Property 17: Docker Compose Integration**
 * **Validates: Requirements 10.1, 10.2**
 * 
 * For any docker-compose command execution, the PHP Version Manager should 
 * integrate seamlessly while respecting configured PHP version settings.
 */
class DockerComposeIntegrationPropertyTest extends TestCase
{
    use TestTrait;

    private DockerComposeManager $dockerManager;
    private PHPVersionManager $phpManager;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->dockerManager = new DockerComposeManager();
        
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/docker_compose_test_' . uniqid();
        mkdir($this->tempDir);
        
        // Mock registry monitor
        $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
        $registryMonitor->method('checkAvailability')->willReturn(true);
        
        $validator = new VersionValidator($registryMonitor);
        
        // Create temporary .env
        $tempEnvPath = $this->tempDir . '/.env';
        file_put_contents($tempEnvPath, "LARADOCK_PHP_VERSION=8.4\n");
        $envConfig = new EnvironmentConfig($tempEnvPath);
        
        $this->phpManager = new PHPVersionManager(
            $validator,
            $registryMonitor,
            $envConfig,
            null,
            $this->dockerManager
        );
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        
        // Clean up generated override files
        if (file_exists('docker-compose.php-version.yml')) {
            unlink('docker-compose.php-version.yml');
        }
        
        parent::tearDown();
    }

    /**
     * Property: Docker compose override generation consistency
     * 
     * For any PHP version and container set, the generated docker-compose
     * override should consistently include the correct version configuration
     * for all specified containers.
     */
    public function testDockerComposeOverrideGenerationConsistency(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\subset(['workspace', 'php-fpm', 'nginx'])
        )->then(function ($version, $containerTypes) {
            // Skip empty container sets
            if (empty($containerTypes)) {
                return;
            }
            
            // Generate override
            $this->dockerManager->generateComprehensiveOverride($version, $containerTypes);
            
            // Verify override file was created
            $this->assertFileExists('docker-compose.php-version.yml',
                "Docker compose override file should be generated");
            
            $overrideContent = file_get_contents('docker-compose.php-version.yml');
            
            // Verify version is correctly set in override
            $this->assertStringContainsString("LARADOCK_PHP_VERSION={$version}", $overrideContent,
                "Override should contain correct LARADOCK_PHP_VERSION");
            $this->assertStringContainsString("PHP_VERSION={$version}", $overrideContent,
                "Override should contain correct PHP_VERSION");
            
            // Verify all specified containers are included
            foreach ($containerTypes as $containerType) {
                $serviceName = $containerType; // Assuming 1:1 mapping for simplicity
                $this->assertStringContainsString("{$serviceName}:", $overrideContent,
                    "Override should include service configuration for {$containerType}");
            }
            
            // Verify YAML structure is valid
            $this->assertStringContainsString('version: 3.8', $overrideContent,
                "Override should have valid docker-compose version");
            $this->assertStringContainsString('services:', $overrideContent,
                "Override should have services section");
        });
    }

    /**
     * Property: Service dependency consistency
     * 
     * For any container configuration, the docker-compose integration should
     * maintain proper service dependencies (e.g., nginx depends on php-fpm).
     */
    public function testServiceDependencyConsistency(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\subset(['workspace', 'php-fpm', 'nginx'])
        )->then(function ($version, $containerTypes) {
            if (empty($containerTypes)) {
                return;
            }
            
            // Check service dependencies
            $dependencies = $this->dockerManager->checkServiceDependencies($containerTypes);
            
            // Verify dependency structure
            foreach ($containerTypes as $containerType) {
                $this->assertArrayHasKey($containerType, $dependencies,
                    "Dependencies should include {$containerType}");
                
                $serviceDep = $dependencies[$containerType];
                $this->assertArrayHasKey('service', $serviceDep,
                    "Dependency info should include service name");
                $this->assertArrayHasKey('dependencies', $serviceDep,
                    "Dependency info should include dependencies list");
                $this->assertArrayHasKey('compatible', $serviceDep,
                    "Dependency info should include compatibility status");
                
                // Verify nginx depends on php-fpm when both are present
                if ($containerType === 'nginx' && in_array('php-fpm', $containerTypes)) {
                    $this->assertContains('php-fpm', $serviceDep['dependencies'],
                        "Nginx should depend on php-fpm when both are configured");
                }
            }
        });
    }

    /**
     * Property: Docker compose command integration
     * 
     * For any valid docker-compose command, the integration should properly
     * include the PHP version override file in the command execution.
     */
    public function testDockerComposeCommandIntegration(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['ps', 'config', 'images']), // Safe read-only commands
            Generator\subset(['workspace', 'php-fpm', 'nginx'])
        )->then(function ($command, $services) {
            // Create a basic docker-compose.yml for testing
            $composeContent = <<<YAML
version: '3.8'
services:
  workspace:
    image: laradock/workspace:latest
  php-fpm:
    image: laradock/php-fpm:latest
  nginx:
    image: nginx:alpine
YAML;
            file_put_contents($this->tempDir . '/docker-compose.yml', $composeContent);
            
            // Generate override
            $this->dockerManager->generateComprehensiveOverride('8.4', ['workspace', 'php-fpm']);
            
            // Test command execution (mock execution for safety)
            $result = $this->dockerManager->executeDockerComposeCommand($command, $services);
            
            // Verify result structure
            $this->assertInstanceOf('Laradock\PHPVersionManager\Models\DockerResult', $result,
                "Command execution should return DockerResult");
            $this->assertIsBool($result->success,
                "Result should have boolean success property");
            $this->assertIsString($result->message,
                "Result should have string message property");
            $this->assertIsArray($result->output,
                "Result should have array output property");
        });
    }

    /**
     * Property: Version change triggers override regeneration
     * 
     * For any PHP version change, the docker-compose override should be
     * regenerated with the new version configuration.
     */
    public function testVersionChangeTriggersOverrideRegeneration(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($initialVersion, $newVersion) {
            // Skip if versions are the same
            if ($initialVersion === $newVersion) {
                return;
            }
            
            // Set initial version
            $result1 = $this->phpManager->setVersion($initialVersion);
            $this->assertTrue($result1->success, "Initial version setting should succeed");
            
            // Verify initial override
            $this->assertFileExists('docker-compose.php-version.yml');
            $initialContent = file_get_contents('docker-compose.php-version.yml');
            $this->assertStringContainsString("LARADOCK_PHP_VERSION={$initialVersion}", $initialContent,
                "Initial override should contain initial version");
            
            // Change to new version
            $result2 = $this->phpManager->setVersion($newVersion);
            $this->assertTrue($result2->success, "Version change should succeed");
            
            // Verify override was updated
            $newContent = file_get_contents('docker-compose.php-version.yml');
            $this->assertStringContainsString("LARADOCK_PHP_VERSION={$newVersion}", $newContent,
                "Updated override should contain new version");
            $this->assertStringNotContainsString("LARADOCK_PHP_VERSION={$initialVersion}", $newContent,
                "Updated override should not contain old version");
        });
    }

    /**
     * Property: Container restart maintains version consistency
     * 
     * For any container restart operation, the PHP version consistency
     * should be maintained across all containers.
     */
    public function testContainerRestartMaintainsVersionConsistency(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\subset(['workspace', 'php-fpm', 'nginx'])
        )->then(function ($version, $containerTypes) {
            if (empty($containerTypes)) {
                return;
            }
            
            // Set PHP version
            $this->phpManager->setVersion($version);
            
            // Test restart with consistency (mock the actual restart)
            $result = $this->dockerManager->restartWithConsistency($containerTypes);
            
            // Verify result structure
            $this->assertInstanceOf('Laradock\PHPVersionManager\Models\DockerResult', $result,
                "Restart should return DockerResult");
            
            // Verify override file still exists and has correct version
            $this->assertFileExists('docker-compose.php-version.yml');
            $overrideContent = file_get_contents('docker-compose.php-version.yml');
            $this->assertStringContainsString("LARADOCK_PHP_VERSION={$version}", $overrideContent,
                "Override should maintain version after restart");
        });
    }

    /**
     * Property: Docker compose validation detects issues
     * 
     * For any docker-compose configuration, the validation should detect
     * compatibility issues and provide appropriate feedback.
     */
    public function testDockerComposeValidationDetectsIssues(): void
    {
        $this->limitTo(3)->forAll(
            Generator\bool(), // Whether to include PHP services
            Generator\bool()  // Whether to include PHP version variables
        )->then(function ($includePhpServices, $includePhpVersionVars) {
            // Create test docker-compose.yml
            $services = [];
            if ($includePhpServices) {
                $services[] = 'workspace:';
                $services[] = '  image: laradock/workspace';
                $services[] = 'php-fpm:';
                $services[] = '  image: laradock/php-fpm';
            }
            
            if ($includePhpVersionVars) {
                $services[] = '  environment:';
                $services[] = '    - LARADOCK_PHP_VERSION=${LARADOCK_PHP_VERSION}';
            }
            
            $composeContent = "version: '3.8'\nservices:\n" . implode("\n", $services);
            $composeFile = $this->tempDir . '/docker-compose.yml';
            file_put_contents($composeFile, $composeContent);
            
            // Validate configuration
            $validation = $this->dockerManager->validateDockerComposeCompatibility($composeFile);
            
            // Verify validation structure
            $this->assertArrayHasKey('valid', $validation);
            $this->assertArrayHasKey('errors', $validation);
            $this->assertArrayHasKey('warnings', $validation);
            $this->assertArrayHasKey('suggestions', $validation);
            
            $this->assertIsBool($validation['valid']);
            $this->assertIsArray($validation['errors']);
            $this->assertIsArray($validation['warnings']);
            $this->assertIsArray($validation['suggestions']);
            
            // If PHP services are missing, should have warnings
            if (!$includePhpServices) {
                $this->assertNotEmpty($validation['warnings'],
                    "Should warn about missing PHP services");
            }
            
            // If PHP version variables are missing, should have suggestions
            if (!$includePhpVersionVars && $includePhpServices) {
                $this->assertNotEmpty($validation['suggestions'],
                    "Should suggest using PHP version variables");
            }
        });
    }

    /**
     * Helper method to remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}