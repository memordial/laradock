<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;
use Laradock\PHPVersionManager\DockerComposeManager;
use Laradock\PHPVersionManager\PHPVersionManager;
use Laradock\PHPVersionManager\VersionValidator;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;

/**
 * Integration tests for .env and docker-compose functionality
 * 
 * Tests the complete workflow of PHP version management including
 * .env configuration updates and docker-compose override generation.
 */
class EnvDockerComposeIntegrationTest extends TestCase
{
    private string $testEnvPath;
    private string $testComposePath;
    private EnvironmentConfig $envConfig;
    private DockerComposeManager $dockerManager;
    private PHPVersionManager $phpManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary test files
        $this->testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
        $this->testComposePath = tempnam(sys_get_temp_dir(), 'test_compose_');
        
        // Create test .env content
        $testEnvContent = <<<ENV
# Test .env file
APP_CODE_PATH_HOST=../
COMPOSE_PROJECT_NAME=laradock

### PHP Version ###########################################
PHP_VERSION=8.4
LARADOCK_PHP_VERSION=8.4

### PHP Version Manager Configuration #####################
PHP_FALLBACK_ENABLED=true
PHP_FALLBACK_STRATEGY=highest_stable
WORKSPACE_PHP_VERSION=\${LARADOCK_PHP_VERSION}
PHP_FPM_VERSION=\${LARADOCK_PHP_VERSION}
PHP_VERSION_CHECK_ENABLED=true
PHP_UPDATE_NOTIFICATIONS=true
ENV;
        
        file_put_contents($this->testEnvPath, $testEnvContent);
        
        // Initialize components
        $this->envConfig = new EnvironmentConfig($this->testEnvPath);
        $this->dockerManager = new DockerComposeManager();
        
        // Mock registry monitor for testing
        $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
        $registryMonitor->method('checkAvailability')->willReturn(true);
        
        $validator = new VersionValidator($registryMonitor);
        
        $this->phpManager = new PHPVersionManager(
            $validator,
            $registryMonitor,
            $this->envConfig
        );
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testEnvPath)) {
            unlink($this->testEnvPath);
        }
        if (file_exists($this->testComposePath)) {
            unlink($this->testComposePath);
        }
        if (file_exists('docker-compose.php-version.yml')) {
            unlink('docker-compose.php-version.yml');
        }
        
        parent::tearDown();
    }

    /**
     * Test .env configuration parsing and updating
     */
    public function testEnvConfigurationParsing(): void
    {
        // Test reading configuration
        $this->assertEquals('8.4', $this->envConfig->getLaradockPhpVersion());
        $this->assertEquals('8.4', $this->envConfig->getWorkspacePhpVersion());
        $this->assertEquals('8.4', $this->envConfig->getPhpFpmVersion());
        $this->assertTrue($this->envConfig->isFallbackEnabled());
        $this->assertEquals('highest_stable', $this->envConfig->getFallbackStrategy());
    }

    /**
     * Test .env configuration updating
     */
    public function testEnvConfigurationUpdating(): void
    {
        // Update PHP version
        $success = $this->envConfig->setLaradockPhpVersion('8.5');
        $this->assertTrue($success);
        
        // Verify updates
        $this->assertEquals('8.5', $this->envConfig->getLaradockPhpVersion());
        $this->assertEquals('8.5', $this->envConfig->getWorkspacePhpVersion());
        $this->assertEquals('8.5', $this->envConfig->getPhpFpmVersion());
        
        // Verify file was updated
        $fileContent = file_get_contents($this->testEnvPath);
        $this->assertStringContainsString('LARADOCK_PHP_VERSION=8.5', $fileContent);
        $this->assertStringContainsString('PHP_VERSION=8.5', $fileContent);
    }

    /**
     * Test version conflict detection
     */
    public function testVersionConflictDetection(): void
    {
        // Create a conflict by manually setting different versions
        $this->envConfig->updateInFile('WORKSPACE_PHP_VERSION', '8.3');
        
        $conflicts = $this->envConfig->checkVersionConflicts();
        $this->assertNotEmpty($conflicts);
        $this->assertArrayHasKey('workspace', $conflicts);
        $this->assertEquals('8.4', $conflicts['workspace']['expected']);
        $this->assertEquals('8.3', $conflicts['workspace']['actual']);
    }

    /**
     * Test configuration validation
     */
    public function testConfigurationValidation(): void
    {
        $validation = $this->envConfig->validateConfiguration();
        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
        
        // Test with missing LARADOCK_PHP_VERSION
        $this->envConfig->updateInFile('LARADOCK_PHP_VERSION', '');
        $validation = $this->envConfig->validateConfiguration();
        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }

    /**
     * Test docker-compose override generation
     */
    public function testDockerComposeOverrideGeneration(): void
    {
        $containerTypes = ['workspace', 'php-fpm', 'nginx'];
        $this->dockerManager->generateOverride('8.5', $containerTypes);
        
        $this->assertFileExists('docker-compose.php-version.yml');
        
        $overrideContent = file_get_contents('docker-compose.php-version.yml');
        $this->assertStringContainsString('version: 3.8', $overrideContent);
        $this->assertStringContainsString('LARADOCK_PHP_VERSION=8.5', $overrideContent);
        $this->assertStringContainsString('PHP_VERSION=8.5', $overrideContent);
        $this->assertStringContainsString('workspace:', $overrideContent);
        $this->assertStringContainsString('php-fpm:', $overrideContent);
        $this->assertStringContainsString('nginx:', $overrideContent);
    }

    /**
     * Test service dependency checking
     */
    public function testServiceDependencyChecking(): void
    {
        $containerTypes = ['workspace', 'php-fpm', 'nginx'];
        $dependencies = $this->dockerManager->checkServiceDependencies($containerTypes);
        
        $this->assertArrayHasKey('workspace', $dependencies);
        $this->assertArrayHasKey('php-fpm', $dependencies);
        $this->assertArrayHasKey('nginx', $dependencies);
        
        $this->assertEquals('workspace', $dependencies['workspace']['service']);
        $this->assertContains('mysql', $dependencies['workspace']['dependencies']);
        $this->assertTrue($dependencies['workspace']['compatible']);
    }

    /**
     * Test complete PHP version change workflow
     */
    public function testCompleteVersionChangeWorkflow(): void
    {
        // Change PHP version through the manager
        $result = $this->phpManager->setVersion('8.5');
        
        $this->assertTrue($result->success);
        $this->assertEquals('8.5', $result->version);
        
        // Verify .env was updated
        $this->assertEquals('8.5', $this->envConfig->getLaradockPhpVersion());
        
        // Verify docker-compose override was generated
        $this->assertFileExists('docker-compose.php-version.yml');
        
        $overrideContent = file_get_contents('docker-compose.php-version.yml');
        $this->assertStringContainsString('LARADOCK_PHP_VERSION=8.5', $overrideContent);
    }

    /**
     * Test environment validation after changes
     */
    public function testEnvironmentValidationAfterChanges(): void
    {
        // Make a valid change
        $this->phpManager->setVersion('8.3');
        
        // Validate environment
        $validation = $this->phpManager->validateEnvironment();
        $this->assertTrue($validation->isValid);
        $this->assertEmpty($validation->errors);
        
        // Create an inconsistency
        $this->envConfig->updateInFile('WORKSPACE_PHP_VERSION', '8.2');
        
        // Validate again
        $validation = $this->phpManager->validateEnvironment();
        $this->assertFalse($validation->isValid);
        $this->assertNotEmpty($validation->errors);
    }

    /**
     * Test consistency checking across containers
     */
    public function testConsistencyCheckingAcrossContainers(): void
    {
        // Initially should be consistent
        $consistency = $this->phpManager->checkConsistency();
        $this->assertTrue($consistency->isConsistent);
        
        // Create inconsistency
        $this->envConfig->updateInFile('PHP_FPM_VERSION', '8.2');
        
        // Check consistency again
        $consistency = $this->phpManager->checkConsistency();
        $this->assertFalse($consistency->isConsistent);
        $this->assertArrayHasKey('php-fpm', $consistency->mismatches);
    }

    /**
     * Test backward compatibility with existing .env patterns
     */
    public function testBackwardCompatibilityWithExistingEnvPatterns(): void
    {
        // Test with old PHP_VERSION only (no LARADOCK_PHP_VERSION)
        $oldEnvContent = <<<ENV
PHP_VERSION=8.3
WORKSPACE_PHP_VERSION=\${PHP_VERSION}
PHP_FPM_VERSION=\${PHP_VERSION}
ENV;
        
        file_put_contents($this->testEnvPath, $oldEnvContent);
        $envConfig = new EnvironmentConfig($this->testEnvPath);
        
        // Should still work with fallback to PHP_VERSION
        $this->assertEquals('8.3', $envConfig->getLaradockPhpVersion());
        $this->assertEquals('8.3', $envConfig->getWorkspacePhpVersion());
        $this->assertEquals('8.3', $envConfig->getPhpFpmVersion());
    }
}