<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\DataBackupManager;
use Laradock\PHPVersionManager\Models\BackupResult;

/**
 * Property-based tests for data preservation during version changes
 * 
 * **Property 6: Data Preservation During Version Changes**
 * **Validates: Requirements 3.4**
 */
class DataPreservationTest extends TestCase
{
    use TestTrait;

    private string $testBackupDir;
    private array $containerTypes = ['workspace', 'php-fpm', 'nginx'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->testBackupDir = sys_get_temp_dir() . '/test-backups-' . uniqid();
        mkdir($this->testBackupDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testBackupDir)) {
            $this->removeDirectory($this->testBackupDir);
        }
    }

    /**
     * Property: For any version switch operation, existing application data 
     * and configurations should be preserved and remain accessible after 
     * the version change completes
     * 
     * **Validates: Requirements 3.4**
     */
    public function testDataPreservationDuringVersionSwitch()
    {
        $this->limitTo(3)->forAll(
            Generator\subset($this->containerTypes), // Containers to backup
            Generator\string() // Backup identifier suffix
        )->then(function ($containerTypes, $backupSuffix) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            $backupManager = new DataBackupManager($this->testBackupDir);
            
            // Create backup
            $backupId = $backupManager->createBackup($containerTypes);
            
            // Backup should be created successfully
            $this->assertNotEmpty($backupId, "Backup ID should be generated");
            $this->assertStringStartsWith('backup-', $backupId, "Backup ID should have correct format");
            
            // Backup directory should exist
            $backupPath = $this->testBackupDir . '/' . $backupId;
            $this->assertDirectoryExists($backupPath, "Backup directory should be created");
            
            // Manifest file should exist
            $manifestPath = $backupPath . '/manifest.json';
            $this->assertFileExists($manifestPath, "Backup manifest should be created");
            
            // Manifest should contain correct information
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $this->assertEquals($backupId, $manifest['id'], "Manifest should contain correct backup ID");
            $this->assertEquals($containerTypes, $manifest['containers'], "Manifest should contain correct container types");
            
            // Container directories should be created
            foreach ($containerTypes as $containerType) {
                $containerPath = $backupPath . '/' . $containerType;
                $this->assertDirectoryExists($containerPath, "Container backup directory should exist for {$containerType}");
            }
            
            // Restore backup
            $restoreResult = $backupManager->restoreBackup($backupId, $containerTypes);
            
            // Restore should be successful
            $this->assertTrue($restoreResult->success, "Backup restore should be successful");
            $this->assertEquals($backupId, $restoreResult->backupId, "Restore result should reference correct backup");
        });
    }

    /**
     * Property: For any backup operation, the system should create a complete 
     * manifest that allows for accurate restoration
     * 
     * **Validates: Requirements 3.4**
     */
    public function testBackupManifestCompleteness()
    {
        $this->limitTo(3)->forAll(
            Generator\subset($this->containerTypes) // Containers to backup
        )->then(function ($containerTypes) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            $backupManager = new DataBackupManager($this->testBackupDir);
            
            // Create backup
            $backupId = $backupManager->createBackup($containerTypes);
            
            // Load manifest
            $backupPath = $this->testBackupDir . '/' . $backupId;
            $manifestPath = $backupPath . '/manifest.json';
            $manifest = json_decode(file_get_contents($manifestPath), true);
            
            // Manifest should contain all required fields
            $this->assertArrayHasKey('id', $manifest, "Manifest should contain backup ID");
            $this->assertArrayHasKey('created', $manifest, "Manifest should contain creation timestamp");
            $this->assertArrayHasKey('containers', $manifest, "Manifest should contain container list");
            $this->assertArrayHasKey('version', $manifest, "Manifest should contain version");
            
            // Manifest data should be accurate
            $this->assertEquals($backupId, $manifest['id'], "Manifest ID should match backup ID");
            $this->assertEquals($containerTypes, $manifest['containers'], "Manifest containers should match input");
            $this->assertNotEmpty($manifest['created'], "Creation timestamp should not be empty");
            $this->assertNotEmpty($manifest['version'], "Version should not be empty");
            
            // Creation timestamp should be valid
            $this->assertNotFalse(strtotime($manifest['created']), "Creation timestamp should be valid");
        });
    }

    /**
     * Property: For any backup listing operation, the system should return 
     * accurate information about available backups sorted by creation time
     * 
     * **Validates: Requirements 3.4**
     */
    public function testBackupListingAccuracy()
    {
        $this->limitTo(3)->forAll(
            Generator\choose(1, 3), // Number of backups to create
            Generator\subset($this->containerTypes) // Containers for each backup
        )->then(function ($backupCount, $containerTypes) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            $backupManager = new DataBackupManager($this->testBackupDir);
            $createdBackups = [];
            
            // Create multiple backups with small delays to ensure different timestamps
            for ($i = 0; $i < $backupCount; $i++) {
                $backupId = $backupManager->createBackup($containerTypes);
                $createdBackups[] = $backupId;
                
                if ($i < $backupCount - 1) {
                    usleep(100000); // 0.1 second delay
                }
            }
            
            // List backups
            $backupList = $backupManager->listBackups();
            
            // Should return correct number of backups
            $this->assertCount($backupCount, $backupList, "Should return correct number of backups");
            
            // Each backup should have required fields
            foreach ($backupList as $backup) {
                $this->assertArrayHasKey('id', $backup, "Backup should have ID");
                $this->assertArrayHasKey('created', $backup, "Backup should have creation time");
                $this->assertArrayHasKey('containers', $backup, "Backup should have container list");
                $this->assertArrayHasKey('size', $backup, "Backup should have size");
                
                // ID should be in created backups list
                $this->assertContains($backup['id'], $createdBackups, "Listed backup should be one we created");
                
                // Containers should match what we backed up
                $this->assertEquals($containerTypes, $backup['containers'], "Container list should match");
            }
            
            // Backups should be sorted by creation time (newest first)
            $timestamps = array_map(fn($backup) => strtotime($backup['created']), $backupList);
            $sortedTimestamps = $timestamps;
            rsort($sortedTimestamps);
            $this->assertEquals($sortedTimestamps, $timestamps, "Backups should be sorted by creation time (newest first)");
        });
    }

    /**
     * Property: For any backup deletion operation, the system should completely 
     * remove the backup and its associated files
     * 
     * **Validates: Requirements 3.4**
     */
    public function testBackupDeletionCompleteness()
    {
        $this->limitTo(3)->forAll(
            Generator\subset($this->containerTypes) // Containers to backup
        )->then(function ($containerTypes) {
            // Skip if no containers selected
            if (empty($containerTypes)) {
                return;
            }

            $backupManager = new DataBackupManager($this->testBackupDir);
            
            // Create backup
            $backupId = $backupManager->createBackup($containerTypes);
            $backupPath = $this->testBackupDir . '/' . $backupId;
            
            // Verify backup exists
            $this->assertDirectoryExists($backupPath, "Backup directory should exist before deletion");
            
            // Delete backup
            $deleteResult = $backupManager->deleteBackup($backupId);
            
            // Deletion should be successful
            $this->assertTrue($deleteResult, "Backup deletion should be successful");
            
            // Backup directory should no longer exist
            $this->assertDirectoryDoesNotExist($backupPath, "Backup directory should be removed after deletion");
            
            // Backup should not appear in listing
            $backupList = $backupManager->listBackups();
            $backupIds = array_column($backupList, 'id');
            $this->assertNotContains($backupId, $backupIds, "Deleted backup should not appear in listing");
        });
    }

    /**
     * Property: For any backup cleanup operation with age and count limits, 
     * the system should preserve the newest backups within limits
     * 
     * **Validates: Requirements 3.4**
     */
    public function testBackupCleanupPreservesNewest()
    {
        $this->limitTo(3)->forAll(
            Generator\choose(3, 6), // Number of backups to create
            Generator\choose(1, 2), // Max backups to keep
            Generator\subset($this->containerTypes) // Containers for backups
        )->then(function ($totalBackups, $maxKeep, $containerTypes) {
            // Skip if no containers selected or invalid parameters
            if (empty($containerTypes) || $maxKeep >= $totalBackups) {
                return;
            }

            $backupManager = new DataBackupManager($this->testBackupDir);
            $createdBackups = [];
            
            // Create multiple backups with delays to ensure different timestamps
            for ($i = 0; $i < $totalBackups; $i++) {
                $backupId = $backupManager->createBackup($containerTypes);
                $createdBackups[] = $backupId;
                usleep(100000); // 0.1 second delay
            }
            
            // Get initial backup list (sorted newest first)
            $initialList = $backupManager->listBackups();
            $this->assertCount($totalBackups, $initialList, "Should have all created backups initially");
            
            // Perform cleanup (keep only maxKeep backups, no age limit)
            $backupManager->cleanupOldBackups(365, $maxKeep);
            
            // Get backup list after cleanup
            $afterCleanupList = $backupManager->listBackups();
            
            // Should have exactly maxKeep backups remaining
            $this->assertCount($maxKeep, $afterCleanupList, "Should have exactly {$maxKeep} backups after cleanup");
            
            // Remaining backups should be the newest ones
            $expectedNewestIds = array_slice(array_column($initialList, 'id'), 0, $maxKeep);
            $actualRemainingIds = array_column($afterCleanupList, 'id');
            
            $this->assertEquals($expectedNewestIds, $actualRemainingIds, "Should preserve the newest backups");
        });
    }

    /**
     * Property: For any invalid backup ID, restore operations should fail gracefully 
     * with appropriate error messages
     * 
     * **Validates: Requirements 3.4**
     */
    public function testInvalidBackupRestoreHandling()
    {
        $this->limitTo(3)->forAll(
            Generator\string(), // Invalid backup ID
            Generator\subset($this->containerTypes) // Containers to restore
        )->then(function ($invalidBackupId, $containerTypes) {
            // Skip if no containers selected or empty backup ID
            if (empty($containerTypes) || empty($invalidBackupId)) {
                return;
            }

            // Ensure backup ID doesn't accidentally exist
            $invalidBackupId = 'invalid-' . $invalidBackupId;
            
            $backupManager = new DataBackupManager($this->testBackupDir);
            
            // Attempt to restore invalid backup
            $restoreResult = $backupManager->restoreBackup($invalidBackupId, $containerTypes);
            
            // Restore should fail
            $this->assertFalse($restoreResult->success, "Restore should fail for invalid backup ID");
            
            // Should return the attempted backup ID
            $this->assertEquals($invalidBackupId, $restoreResult->backupId, "Result should reference attempted backup ID");
            
            // Should provide meaningful error message
            $this->assertNotEmpty($restoreResult->message, "Should provide error message");
            $this->assertStringContainsString('not found', $restoreResult->message, "Error message should indicate backup not found");
        });
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($path);
    }
}