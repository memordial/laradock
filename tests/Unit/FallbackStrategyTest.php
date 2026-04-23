<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\FallbackStrategy;
use Laradock\PHPVersionManager\ContainerRegistryMonitorInterface;
use Laradock\PHPVersionManager\ContainerRebuildManager;
use Laradock\PHPVersionManager\Models\FallbackResult;
use Laradock\PHPVersionManager\Models\RebuildResult;

/**
 * Unit tests for fallback strategies
 * 
 * Tests priority-based version selection and fallback notification logic
 * **Validates: Requirements 4.2, 4.3**
 */
class FallbackStrategyTest extends TestCase
{
    private ContainerRegistryMonitorInterface $mockRegistryMonitor;
    private ContainerRebuildManager $mockRebuildManager;
    private array $defaultPriority = ['8.4', '8.3', '8.2', '8.1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRegistryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
        $this->mockRebuildManager = $this->createMock(ContainerRebuildManager::class);
    }

    /**
     * Test priority-based version selection with default priority list
     * 
     * **Validates: Requirements 4.2, 4.3**
     */
    public function testPriorityBasedVersionSelection()
    {
        // Configure availability: 8.4 unavailable, 8.3 and 8.2 available
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                return in_array($version, ['8.3', '8.2', '8.1']);
            });

        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        $result = $strategy->selectFallbackVersion('8.5', ['8.3', '8.2', '8.1']);

        // Should select highest priority available version (8.3)
        $this->assertEquals('8.3', $result);
    }

    /**
     * Test priority-based selection with custom priority list
     * 
     * **Validates: Requirements 4.4**
     */
    public function testCustomPriorityVersionSelection()
    {
        $customPriority = ['8.2', '8.4', '8.1', '8.3']; // Custom order
        
        $strategy = new FallbackStrategy($this->mockRegistryMonitor, $customPriority);
        $result = $strategy->selectFallbackVersion('8.5', ['8.4', '8.3', '8.1']);

        // Should select first available version from custom priority (8.2 not available, so 8.4)
        $this->assertEquals('8.4', $result);
    }

    /**
     * Test fallback selection when no versions are available
     * 
     * **Validates: Requirements 4.3**
     */
    public function testNoAvailableVersionsFallback()
    {
        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        $result = $strategy->selectFallbackVersion('8.5', []);

        // Should return null when no versions are available
        $this->assertNull($result);
    }

    /**
     * Test fallback application with successful version selection
     * 
     * **Validates: Requirements 4.2, 4.5**
     */
    public function testSuccessfulFallbackApplication()
    {
        // Configure 8.5 as unavailable, 8.4 as available
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                return $version !== '8.5';
            });

        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        $result = $strategy->applyFallback('8.5');

        // Should successfully apply fallback
        $this->assertTrue($result->success);
        $this->assertEquals('8.5', $result->requestedVersion);
        $this->assertEquals('8.4', $result->fallbackVersion);
        
        // Should provide notification message
        $this->assertNotEmpty($result->message);
        $this->assertStringContainsString('8.5', $result->message);
        $this->assertStringContainsString('8.4', $result->message);
        $this->assertStringContainsString('unavailable', $result->message);
    }

    /**
     * Test fallback application when requested version is available
     * 
     * **Validates: Requirements 4.1**
     */
    public function testNoFallbackWhenVersionAvailable()
    {
        // Configure all versions as available
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturn(true);

        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        
        // Should not apply fallback for available version
        $shouldApply = $strategy->shouldApplyFallback('8.4');
        $this->assertFalse($shouldApply);
    }

    /**
     * Test fallback application failure when no suitable version found
     * 
     * **Validates: Requirements 4.3**
     */
    public function testFallbackFailureNoSuitableVersion()
    {
        // Configure all versions as unavailable
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturn(false);

        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        $result = $strategy->applyFallback('8.5');

        // Should fail to find fallback
        $this->assertFalse($result->success);
        $this->assertEquals('8.5', $result->requestedVersion);
        $this->assertEquals('8.5', $result->fallbackVersion); // Should remain unchanged
        $this->assertStringContainsString('No suitable fallback', $result->message);
    }

    /**
     * Test disabled fallback strategy
     * 
     * **Validates: Requirements 4.1**
     */
    public function testDisabledFallbackStrategy()
    {
        // Configure version as unavailable
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturn(false);

        $strategy = new FallbackStrategy($this->mockRegistryMonitor, null, 'disabled');
        
        // Should not apply fallback when disabled
        $shouldApply = $strategy->shouldApplyFallback('8.5');
        $this->assertFalse($shouldApply);
        
        $result = $strategy->selectFallbackVersion('8.5', ['8.4', '8.3']);
        $this->assertNull($result);
    }

    /**
     * Test exact match strategy
     * 
     * **Validates: Requirements 4.1**
     */
    public function testExactMatchStrategy()
    {
        $strategy = new FallbackStrategy($this->mockRegistryMonitor, null, 'exact_match');
        $result = $strategy->selectFallbackVersion('8.5', ['8.4', '8.3']);

        // Should not select any fallback for exact_match strategy
        $this->assertNull($result);
    }

    /**
     * Test fallback with automatic container rebuild
     * 
     * **Validates: Requirements 3.2**
     */
    public function testFallbackWithAutomaticRebuild()
    {
        // Configure 8.5 as unavailable, 8.4 as available
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                return $version !== '8.5';
            });

        // Configure successful rebuild
        $this->mockRebuildManager
            ->method('executeRebuild')
            ->with('8.4', true)
            ->willReturn(new RebuildResult(true, '8.4', ['workspace', 'php-fpm'], 'Rebuild successful'));

        $strategy = new FallbackStrategy($this->mockRegistryMonitor, null, 'highest_stable', $this->mockRebuildManager);
        $result = $strategy->applyFallbackWithRebuild('8.5', true);

        // Should successfully apply fallback with rebuild
        $this->assertTrue($result->success);
        $this->assertEquals('8.4', $result->fallbackVersion);
        $this->assertStringContainsString('rebuilt automatically', $result->message);
    }

    /**
     * Test fallback with failed container rebuild
     * 
     * **Validates: Requirements 3.2**
     */
    public function testFallbackWithFailedRebuild()
    {
        // Configure 8.5 as unavailable, 8.4 as available
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                return $version !== '8.5';
            });

        // Configure failed rebuild
        $this->mockRebuildManager
            ->method('executeRebuild')
            ->with('8.4', true)
            ->willReturn(new RebuildResult(false, '8.4', ['workspace', 'php-fpm'], 'Rebuild failed'));

        $strategy = new FallbackStrategy($this->mockRegistryMonitor, null, 'highest_stable', $this->mockRebuildManager);
        $result = $strategy->applyFallbackWithRebuild('8.5', true);

        // Should fail when rebuild fails
        $this->assertFalse($result->success);
        $this->assertStringContainsString('rebuild failed', $result->message);
    }

    /**
     * Test version priority configuration
     * 
     * **Validates: Requirements 4.4**
     */
    public function testVersionPriorityConfiguration()
    {
        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        
        // Test default priority
        $this->assertEquals($this->defaultPriority, $strategy->getVersionPriority());
        
        // Test custom priority setting
        $customPriority = ['8.1', '8.2', '8.3', '8.4'];
        $strategy->setVersionPriority($customPriority);
        $this->assertEquals($customPriority, $strategy->getVersionPriority());
    }

    /**
     * Test strategy type configuration
     * 
     * **Validates: Requirements 4.1**
     */
    public function testStrategyTypeConfiguration()
    {
        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        
        // Test default strategy
        $this->assertEquals('highest_stable', $strategy->getStrategy());
        
        // Test strategy setting
        $strategy->setStrategy('exact_match');
        $this->assertEquals('exact_match', $strategy->getStrategy());
        
        $strategy->setStrategy('disabled');
        $this->assertEquals('disabled', $strategy->getStrategy());
    }

    /**
     * Test fallback notification logging
     * 
     * **Validates: Requirements 4.5**
     */
    public function testFallbackNotificationLogging()
    {
        // Configure 8.5 as unavailable, 8.4 as available
        $this->mockRegistryMonitor
            ->method('checkAvailability')
            ->willReturnCallback(function ($version, $containerType) {
                return $version !== '8.5';
            });

        // Create mock logger with warning method
        $mockLogger = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['warning'])
            ->getMock();
        
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Fallback applied: PHP 8.5 → PHP 8.4'));

        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        $strategy->setLogger($mockLogger);
        
        $result = $strategy->applyFallback('8.5');
        
        // Should log fallback decision
        $this->assertTrue($result->success);
    }

    /**
     * Test rebuild manager integration
     * 
     * **Validates: Requirements 3.2**
     */
    public function testRebuildManagerIntegration()
    {
        $strategy = new FallbackStrategy($this->mockRegistryMonitor);
        
        // Test setting rebuild manager
        $strategy->setRebuildManager($this->mockRebuildManager);
        
        // Test logger propagation to rebuild manager
        $mockLogger = $this->createMock(\stdClass::class);
        
        $this->mockRebuildManager
            ->expects($this->once())
            ->method('setLogger')
            ->with($mockLogger);
        
        $strategy->setLogger($mockLogger);
    }
}