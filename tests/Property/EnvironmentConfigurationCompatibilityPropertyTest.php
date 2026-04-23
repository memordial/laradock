<?php

namespace Tests\Property;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\Config\EnvironmentConfig;
use Laradock\PHPVersionManager\PHPVersionManager;
use Laradock\PHPVersionManager\VersionValidator;
use Laradock\PHPVersionManager\ContainerRegistryMonitor;

/**
 * Property test for environment configuration compatibility
 * 
 * **Property 18: Environment Configuration Compatibility**
 * **Validates: Requirements 10.3**
 * 
 * For any existing .env configuration pattern, the development environment 
 * should maintain compatibility while supporting PHP version management features.
 */
class EnvironmentConfigurationCompatibilityPropertyTest extends TestCase
{
    use TestTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/env_config_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        
        parent::tearDown();
    }

    /**
     * Property: Backward compatibility with existing .env patterns
     * 
     * For any existing .env configuration using PHP_VERSION, the system
     * should maintain compatibility while supporting new LARADOCK_PHP_VERSION.
     */
    public function testBackwardCompatibilityWithExistingEnvPatterns(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\bool(), // Whether to include LARADOCK_PHP_VERSION
            Generator\bool()  // Whether to include legacy PHP_VERSION
        )->then(function ($version, $includeLaradockVersion, $includeLegacyVersion) {
            // Skip if neither version is included
            if (!$includeLaradockVersion && !$includeLegacyVersion) {
                return;
            }
            
            // Create .env content with different patterns
            $envContent = "# Test .env file\n";
            $envContent .= "APP_CODE_PATH_HOST=../\n";
            $envContent .= "COMPOSE_PROJECT_NAME=laradock\n\n";
            
            if ($includeLaradockVersion) {
                $envContent .= "LARADOCK_PHP_VERSION={$version}\n";
            }
            
            if ($includeLegacyVersion) {
                $envContent .= "PHP_VERSION={$version}\n";
            }
            
            $envContent .= "WORKSPACE_PHP_VERSION=\${PHP_VERSION}\n";
            $envContent .= "PHP_FPM_VERSION=\${PHP_VERSION}\n";
            
            $envPath = $this->tempDir . '/.env';
            file_put_contents($envPath, $envContent);
            
            // Load configuration
            $envConfig = new EnvironmentConfig($envPath);
            
            // Should be able to read PHP version regardless of which variable is used
            $detectedVersion = $envConfig->getLaradockPhpVersion();
            $this->assertEquals($version, $detectedVersion,
                "Should detect PHP version from available configuration");
            
            // Should resolve variable references correctly
            $workspaceVersion = $envConfig->getWorkspacePhpVersion();
            $phpFpmVersion = $envConfig->getPhpFpmVersion();
            
            $this->assertEquals($version, $workspaceVersion,
                "Workspace version should resolve to main PHP version");
            $this->assertEquals($version, $phpFpmVersion,
                "PHP-FPM version should resolve to main PHP version");
        });
    }

    /**
     * Property: Configuration validation handles various patterns
     * 
     * For any .env configuration pattern, the validation should correctly
     * identify valid configurations and detect conflicts.
     */
    public function testConfigurationValidationHandlesVariousPatterns(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['${LARADOCK_PHP_VERSION}', '${PHP_VERSION}', '8.3', '8.4'])
        )->then(function ($laradockVersion, $phpVersion, $workspaceVersion) {
            // Create .env with potentially conflicting versions
            $envContent = <<<ENV
LARADOCK_PHP_VERSION={$laradockVersion}
PHP_VERSION={$phpVersion}
WORKSPACE_PHP_VERSION={$workspaceVersion}
PHP_FPM_VERSION=\${LARADOCK_PHP_VERSION}
ENV;
            
            $envPath = $this->tempDir . '/.env';
            file_put_contents($envPath, $envContent);
            
            $envConfig = new EnvironmentConfig($envPath);
            $validation = $envConfig->validateConfiguration();
            
            // Validation should always return proper structure
            $this->assertArrayHasKey('valid', $validation);
            $this->assertArrayHasKey('errors', $validation);
            $this->assertArrayHasKey('warnings', $validation);
            $this->assertArrayHasKey('suggestions', $validation);
            
            // Check for conflicts
            $conflicts = $envConfig->checkVersionConflicts();
            
            if ($laradockVersion !== $phpVersion) {
                // Should detect version conflicts
                $this->assertFalse($validation['valid'] && empty($validation['warnings']),
                    "Should detect conflicts when LARADOCK_PHP_VERSION != PHP_VERSION");
            }
            
            // If workspace version is hardcoded and different, should detect conflict
            if (!str_starts_with($workspaceVersion, '${') && $workspaceVersion !== $laradockVersion) {
                $this->assertArrayHasKey('workspace', $conflicts,
                    "Should detect workspace version conflict");
            }
        });
    }

    /**
     * Property: Variable reference resolution
     * 
     * For any .env configuration with variable references, the system should
     * correctly resolve references to their actual values.
     */
    public function testVariableReferenceResolution(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['${LARADOCK_PHP_VERSION}', '${PHP_VERSION}', 'hardcoded'])
        )->then(function ($baseVersion, $referencePattern) {
            $workspaceValue = $referencePattern === 'hardcoded' ? '8.2' : $referencePattern;
            
            $envContent = <<<ENV
LARADOCK_PHP_VERSION={$baseVersion}
PHP_VERSION={$baseVersion}
WORKSPACE_PHP_VERSION={$workspaceValue}
PHP_FPM_VERSION=\${LARADOCK_PHP_VERSION}
ENV;
            
            $envPath = $this->tempDir . '/.env';
            file_put_contents($envPath, $envContent);
            
            $envConfig = new EnvironmentConfig($envPath);
            
            // Test resolution
            $resolvedWorkspace = $envConfig->getWorkspacePhpVersion();
            $resolvedPhpFpm = $envConfig->getPhpFpmVersion();
            
            if ($referencePattern === '${LARADOCK_PHP_VERSION}' || $referencePattern === '${PHP_VERSION}') {
                $this->assertEquals($baseVersion, $resolvedWorkspace,
                    "Variable reference should resolve to base version");
            } else {
                $this->assertEquals('8.2', $resolvedWorkspace,
                    "Hardcoded value should be returned as-is");
            }
            
            $this->assertEquals($baseVersion, $resolvedPhpFpm,
                "PHP-FPM should always resolve to LARADOCK_PHP_VERSION");
        });
    }

    /**
     * Property: Configuration update preserves structure
     * 
     * For any .env file structure, updating PHP version should preserve
     * comments, formatting, and non-PHP related configuration.
     */
    public function testConfigurationUpdatePreservesStructure(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($initialVersion, $newVersion) {
            // Skip if versions are the same
            if ($initialVersion === $newVersion) {
                return;
            }
            
            // Create .env with comments and structure
            $envContent = <<<ENV
###########################################################
###################### General Setup ######################
###########################################################

# Point to the path of your applications code on your host
APP_CODE_PATH_HOST=../

### PHP Version ###########################################

# Select a PHP version of the Workspace and PHP-FPM containers
LARADOCK_PHP_VERSION={$initialVersion}
PHP_VERSION={$initialVersion}

# Container-specific overrides
WORKSPACE_PHP_VERSION=\${LARADOCK_PHP_VERSION}
PHP_FPM_VERSION=\${LARADOCK_PHP_VERSION}

### Other Configuration ###################################

COMPOSE_PROJECT_NAME=laradock
ENV;
            
            $envPath = $this->tempDir . '/.env';
            file_put_contents($envPath, $envContent);
            
            $envConfig = new EnvironmentConfig($envPath);
            
            // Update version
            $success = $envConfig->setLaradockPhpVersion($newVersion);
            $this->assertTrue($success, "Version update should succeed");
            
            // Read updated content
            $updatedContent = file_get_contents($envPath);
            
            // Verify structure is preserved
            $this->assertStringContainsString('General Setup', $updatedContent,
                "Comments should be preserved");
            $this->assertStringContainsString('APP_CODE_PATH_HOST=../', $updatedContent,
                "Non-PHP configuration should be preserved");
            $this->assertStringContainsString('COMPOSE_PROJECT_NAME=laradock', $updatedContent,
                "Other configuration should be preserved");
            
            // Verify version was updated
            $this->assertStringContainsString("LARADOCK_PHP_VERSION={$newVersion}", $updatedContent,
                "LARADOCK_PHP_VERSION should be updated");
            $this->assertStringContainsString("PHP_VERSION={$newVersion}", $updatedContent,
                "PHP_VERSION should be updated");
            
            // Verify old version is not present
            $this->assertStringNotContainsString("LARADOCK_PHP_VERSION={$initialVersion}", $updatedContent,
                "Old LARADOCK_PHP_VERSION should be removed");
        });
    }

    /**
     * Property: Fallback configuration compatibility
     * 
     * For any fallback configuration settings, the system should maintain
     * compatibility with existing Laradock patterns.
     */
    public function testFallbackConfigurationCompatibility(): void
    {
        $this->limitTo(3)->forAll(
            Generator\bool(), // PHP_FALLBACK_ENABLED
            Generator\elements(['highest_stable', 'exact_match', 'disabled']), // Strategy
            Generator\bool(), // PHP_VERSION_CHECK_ENABLED
            Generator\bool()  // PHP_UPDATE_NOTIFICATIONS
        )->then(function ($fallbackEnabled, $fallbackStrategy, $versionCheckEnabled, $updateNotifications) {
            $envContent = <<<ENV
LARADOCK_PHP_VERSION=8.4
PHP_FALLBACK_ENABLED={$this->boolToString($fallbackEnabled)}
PHP_FALLBACK_STRATEGY={$fallbackStrategy}
PHP_VERSION_CHECK_ENABLED={$this->boolToString($versionCheckEnabled)}
PHP_UPDATE_NOTIFICATIONS={$this->boolToString($updateNotifications)}
ENV;
            
            $envPath = $this->tempDir . '/.env';
            file_put_contents($envPath, $envContent);
            
            $envConfig = new EnvironmentConfig($envPath);
            
            // Test configuration reading
            $this->assertEquals($fallbackEnabled, $envConfig->isFallbackEnabled(),
                "Fallback enabled setting should be read correctly");
            $this->assertEquals($fallbackStrategy, $envConfig->getFallbackStrategy(),
                "Fallback strategy should be read correctly");
            $this->assertEquals($versionCheckEnabled, $envConfig->isVersionCheckEnabled(),
                "Version check setting should be read correctly");
            $this->assertEquals($updateNotifications, $envConfig->areUpdateNotificationsEnabled(),
                "Update notifications setting should be read correctly");
        });
    }

    /**
     * Property: Integration with PHP Version Manager
     * 
     * For any .env configuration, the PHP Version Manager should be able to
     * work with the configuration seamlessly.
     */
    public function testIntegrationWithPhpVersionManager(): void
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5']),
            Generator\elements(['8.1', '8.2', '8.3', '8.4', '8.5'])
        )->then(function ($initialVersion, $targetVersion) {
            // Create .env
            $envContent = <<<ENV
LARADOCK_PHP_VERSION={$initialVersion}
PHP_VERSION={$initialVersion}
WORKSPACE_PHP_VERSION=\${LARADOCK_PHP_VERSION}
PHP_FPM_VERSION=\${LARADOCK_PHP_VERSION}
PHP_FALLBACK_ENABLED=true
PHP_FALLBACK_STRATEGY=highest_stable
ENV;
            
            $envPath = $this->tempDir . '/.env';
            file_put_contents($envPath, $envContent);
            
            // Create PHP Version Manager
            $registryMonitor = $this->createMock(ContainerRegistryMonitor::class);
            $registryMonitor->method('checkAvailability')->willReturn(true);
            
            $validator = new VersionValidator($registryMonitor);
            $envConfig = new EnvironmentConfig($envPath);
            
            $phpManager = new PHPVersionManager($validator, $registryMonitor, $envConfig);
            
            // Test version change
            $result = $phpManager->setVersion($targetVersion);
            
            $this->assertTrue($result->success, "Version change should succeed");
            $this->assertEquals($targetVersion, $result->version, "Result should reflect target version");
            
            // Verify .env was updated
            $this->assertEquals($targetVersion, $envConfig->getLaradockPhpVersion(),
                "Environment config should be updated");
            
            // Test environment validation
            $validation = $phpManager->validateEnvironment();
            $this->assertTrue($validation->isValid, "Environment should be valid after update");
        });
    }

    /**
     * Helper method to convert boolean to string
     */
    private function boolToString(bool $value): string
    {
        return $value ? 'true' : 'false';
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