<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\ContainerRebuildManager;
use Laradock\PHPVersionManager\DataBackupManager;
use Laradock\PHPVersionManager\DockerComposeManager;
use Laradock\PHPVersionManager\Models\RebuildResult;
use Laradock\PHPVersionManager\Models\DockerResult;
use Laradock\PHPVersionManager\Models\BackupResult;

/**
 * Property-based tests for automatic container rebuild
 * 
 * **Property 5: Automatic Container Rebuild**
 * **Validates: Requirements 3.2**
 */
class AutomaticContainerRebuildTest extends TestCase
{
    use TestTrait;

    private DataBackupManager $mockBackupManager;
    private DockerComposeManager $mockDockerManager;
    private array $supportedVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];
    private array $containerTypes = ['workspace', 'php-fpm', 'nginx'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBackupManager = $this->createMock(DataBackupManager::class);
        $this->mockDockerManager = $this->createMock(DockerComposeManager::class);
    }

    /**
     * Property: For any PHP version change in configuration, the PHP Version Manager 
     * should automatically rebuild all affected containers to reflect the new version
     * 
     * **Validates: Requirements 3.2**
     */
    public function testAutomaticRebuildOnVersionChange()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions), // Current version
            Generator\elements($this->supportedVersions), // New version
            Generator\subset($this->containerTypes) // Containers to rebuild
        )->then(function ($currentVersion, $newVersion, $containerTypes) {
            // Skip if no containers selected or same version
            if (empty($containerTypes) || $currentVersion === $newVersion) {
                return;
            }

            // Create fresh mocks for this iteration
            $mockBackupManager = $this->createMock(DataBackupManager::class);
            $mockDockerManager = $this->createMock(DockerComposeManager::class);

            // Configure successful backup creation
            $backupId = 'backup-' . uniqid();
            $mockBackupManager
                ->method('createBackup')
                ->willReturn($backupId);

            $mockBackupManager
                ->method('restoreBackup')
                ->willReturn(new BackupResult(true, $backupId, 'Restore successful'));

            // Configure successful docker operations
            $mockDockerManager
                ->method('stopContainers')
                ->willReturn(new DockerResult(true, 'Containers stopped'));

            $mockDockerManager
                ->method('buildContainers')
                ->willReturn(new DockerResult(true, 'Containers built'));

            $mockDockerManager
                ->method('startContainers')
                ->willReturn(new DockerResult(true, 'Containers started'));

            $mockDockerManager
                ->method('checkContainerHealth')
                ->willReturn(new DockerResult(true, 'Containers healthy'));

            // Mock getContainerPhpVersion to always return the new version for validation
            $mockDockerManager
                ->method('getContainerPhpVersion')
                ->willReturn($newVersion);

            $rebuildManager = new ContainerRebuildManager(
                $mockBackupManager,
                $mockDockerManager
            );

            $result = $rebuildManager->executeRebuild($newVersion, true, $containerTypes);

            // Should successfully rebuild containers
            $this->assertTrue($result->success, 
                "Should successfully rebuild containers for version change {$currentVersion} → {$newVersion}");
            
            // Should use the new version
            $this->assertEquals($newVersion, $result->version,
                "Rebuild result should reflect new PHP version");
            
            // Should include all specified containers
            $this->assertEquals($containerTypes, $result->containerTypes,
                "Rebuild should affect all specified containers");
            
            // Should create backup when data preservation is enabled
            $this->assertNotNull($result->backupId,
                "Should create backup when data preservation is enabled");
        });
    }

    /**
     * Property: For any container rebuild operation, the system should validate 
     * that containers are running the expected PHP version after rebuild
     * 
     * **Validates: Requirements 3.2**
     */
    public function testRebuildValidatesExpectedVersion()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions), // Expected version
            Generator\subset($this->containerTypes) // Containers to validate
        )->then(function ($expectedVersion, $containerTypes) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            // Configure docker manager to return expected version
            $this->mockDockerManager
                ->method('getContainerPhpVersion')
                ->willReturn($expectedVersion);

            $this->mockDockerManager
                ->method('checkContainerHealth')
                ->willReturn(new DockerResult(true, 'All containers healthy'));

            $rebuildManager = new ContainerRebuildManager(
                $this->mockBackupManager,
                $this->mockDockerManager
            );

            $validationResult = $rebuildManager->validateRebuild($expectedVersion, $containerTypes);

            // Should successfully validate when versions match
            $this->assertTrue($validationResult->success,
                "Should validate successfully when container versions match expected version");
            
            $this->assertEquals($expectedVersion, $validationResult->version,
                "Validation result should reflect expected version");
        });
    }

    /**
     * Property: For any version mismatch after rebuild, validation should fail 
     * and report the specific containers with incorrect versions
     * 
     * **Validates: Requirements 3.2**
     */
    public function testRebuildValidationFailsOnVersionMismatch()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions), // Expected version
            Generator\elements($this->supportedVersions), // Actual version (different)
            Generator\subset($this->containerTypes) // Containers to validate
        )->then(function ($expectedVersion, $actualVersion, $containerTypes) {
            // Skip if no containers selected or versions are the same
            if (empty($containerTypes) || $expectedVersion === $actualVersion) {
                return;
            }

            // Configure docker manager to return different version
            $this->mockDockerManager
                ->method('getContainerPhpVersion')
                ->willReturn($actualVersion);

            $this->mockDockerManager
                ->method('checkContainerHealth')
                ->willReturn(new DockerResult(true, 'Containers healthy'));

            $rebuildManager = new ContainerRebuildManager(
                $this->mockBackupManager,
                $this->mockDockerManager
            );

            $validationResult = $rebuildManager->validateRebuild($expectedVersion, $containerTypes);

            // Should fail validation when versions don't match
            $this->assertFalse($validationResult->success,
                "Should fail validation when container versions don't match expected");
            
            // Message should mention version mismatch
            $this->assertStringContainsString('mismatch', $validationResult->message,
                "Validation message should mention version mismatch");
            
            $this->assertStringContainsString($expectedVersion, $validationResult->message,
                "Validation message should mention expected version");
            
            $this->assertStringContainsString($actualVersion, $validationResult->message,
                "Validation message should mention actual version");
        });
    }

    /**
     * Property: For any rebuild operation with data preservation enabled, 
     * a backup should be created before rebuild and restored after success
     * 
     * **Validates: Requirements 3.4**
     */
    public function testDataPreservationDuringRebuild()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions), // Version to rebuild
            Generator\subset($this->containerTypes), // Containers to rebuild
            Generator\bool() // Whether to preserve data
        )->then(function ($version, $containerTypes, $preserveData) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            $backupId = 'backup-' . uniqid();
            
            if ($preserveData) {
                // Configure backup operations
                $this->mockBackupManager
                    ->method('createBackup')
                    ->with($containerTypes)
                    ->willReturn($backupId);

                $this->mockBackupManager
                    ->method('restoreBackup')
                    ->with($backupId, $containerTypes)
                    ->willReturn(new BackupResult(true, $backupId, 'Restore successful'));
            }

            // Configure successful docker operations
            $this->mockDockerManager
                ->method('stopContainers')
                ->willReturn(new DockerResult(true, 'Stopped'));

            $this->mockDockerManager
                ->method('buildContainers')
                ->willReturn(new DockerResult(true, 'Built'));

            $this->mockDockerManager
                ->method('startContainers')
                ->willReturn(new DockerResult(true, 'Started'));

            $this->mockDockerManager
                ->method('checkContainerHealth')
                ->willReturn(new DockerResult(true, 'Healthy'));

            $this->mockDockerManager
                ->method('getContainerPhpVersion')
                ->willReturn($version);

            $rebuildManager = new ContainerRebuildManager(
                $this->mockBackupManager,
                $this->mockDockerManager
            );

            $result = $rebuildManager->executeRebuild($version, $preserveData, $containerTypes);

            if ($preserveData) {
                // Should create backup when data preservation is enabled
                $this->assertNotNull($result->backupId,
                    "Should create backup when data preservation is enabled");
                
                $this->assertEquals($backupId, $result->backupId,
                    "Should return the correct backup ID");
            } else {
                // Should not create backup when data preservation is disabled
                $this->assertNull($result->backupId,
                    "Should not create backup when data preservation is disabled");
            }
        });
    }

    /**
     * Property: For any rebuild failure, the system should attempt recovery 
     * if a backup was created
     * 
     * **Validates: Requirements 3.2**
     */
    public function testRebuildFailureRecovery()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions), // Version to rebuild
            Generator\subset($this->containerTypes) // Containers to rebuild
        )->then(function ($version, $containerTypes) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            $backupId = 'backup-' . uniqid();

            // Configure backup creation
            $this->mockBackupManager
                ->method('createBackup')
                ->willReturn($backupId);

            // Configure docker operations to fail at build step
            $this->mockDockerManager
                ->method('stopContainers')
                ->willReturn(new DockerResult(true, 'Stopped'));

            $this->mockDockerManager
                ->method('buildContainers')
                ->willReturn(new DockerResult(false, 'Build failed'));

            $rebuildManager = new ContainerRebuildManager(
                $this->mockBackupManager,
                $this->mockDockerManager
            );

            $result = $rebuildManager->executeRebuild($version, true, $containerTypes);

            // Should fail when docker operations fail
            $this->assertFalse($result->success,
                "Should fail when docker operations fail");
            
            // Should still have backup ID for recovery
            $this->assertEquals($backupId, $result->backupId,
                "Should preserve backup ID for recovery purposes");
            
            // Message should indicate failure
            $this->assertStringContainsString('failed', $result->message,
                "Failure message should indicate the operation failed");
        });
    }

    /**
     * Property: For any container type configuration, the rebuild manager 
     * should correctly determine if rebuild is needed based on version differences
     * 
     * **Validates: Requirements 3.2**
     */
    public function testRebuildNeedsDetermination()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions), // Current version
            Generator\elements($this->supportedVersions), // Target version
            Generator\subset($this->containerTypes) // Containers to check
        )->then(function ($currentVersion, $targetVersion, $containerTypes) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            // Configure docker manager to return current version
            $this->mockDockerManager
                ->method('getContainerPhpVersion')
                ->willReturn($currentVersion);

            $rebuildManager = new ContainerRebuildManager(
                $this->mockBackupManager,
                $this->mockDockerManager
            );

            $needsRebuild = $rebuildManager->needsRebuild($targetVersion, $containerTypes);

            if ($currentVersion === $targetVersion) {
                // Should not need rebuild when versions match
                $this->assertFalse($needsRebuild,
                    "Should not need rebuild when current and target versions match");
            } else {
                // Should need rebuild when versions differ
                $this->assertTrue($needsRebuild,
                    "Should need rebuild when current and target versions differ");
            }
        });
    }
}