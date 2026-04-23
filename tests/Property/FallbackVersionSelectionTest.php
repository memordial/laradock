<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;
use Eris\Generator;
use Eris\TestTrait;
use Laradock\PHPVersionManager\FallbackStrategy;
use Laradock\PHPVersionManager\ContainerRegistryMonitorInterface;
use Laradock\PHPVersionManager\Models\FallbackResult;

/**
 * Property-based tests for fallback version selection
 * 
 * **Property 2: Fallback Version Selection**
 * **Validates: Requirements 4.2, 4.3, 4.5**
 */
class FallbackVersionSelectionTest extends TestCase
{
    use TestTrait;

    private ContainerRegistryMonitorInterface $mockRegistryMonitor;
    private array $supportedVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];
    private array $containerTypes = ['workspace', 'php-fpm', 'nginx'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRegistryMonitor = $this->createMock(ContainerRegistryMonitorInterface::class);
    }

    /**
     * Property: For any unavailable PHP version request, the fallback strategy should 
     * select the highest available stable version from the priority list
     * 
     * **Validates: Requirements 4.2, 4.3**
     */
    public function testFallbackSelectsHighestAvailableStableVersion()
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.6', '9.0', '7.4', '8.0']), // Unavailable versions
            Generator\subset($this->supportedVersions) // Available versions subset
        )->then(function ($unavailableVersion, $availableVersions) {
            // Skip if no versions are available
            if (empty($availableVersions)) {
                return;
            }

            // Configure mock to return availability based on our test data
            $this->mockRegistryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($version, $containerType) use ($availableVersions, $unavailableVersion) {
                    // Requested version is unavailable
                    if ($version === $unavailableVersion) {
                        return false;
                    }
                    // Other versions available based on our test data
                    return in_array($version, $availableVersions);
                });

            $strategy = new FallbackStrategy($this->mockRegistryMonitor);
            $result = $strategy->applyFallback($unavailableVersion);

            // Should successfully find a fallback
            $this->assertTrue($result->success, "Should find fallback for unavailable version {$unavailableVersion}");
            
            // Fallback version should be in available versions
            $this->assertContains($result->fallbackVersion, $availableVersions, 
                "Fallback version {$result->fallbackVersion} should be in available versions");
            
            // Should select highest available version according to priority
            $priorityOrder = ['8.4', '8.3', '8.2', '8.1'];
            $expectedFallback = null;
            
            foreach ($priorityOrder as $priorityVersion) {
                if (in_array($priorityVersion, $availableVersions)) {
                    $expectedFallback = $priorityVersion;
                    break;
                }
            }
            
            if ($expectedFallback !== null) {
                $this->assertEquals($expectedFallback, $result->fallbackVersion,
                    "Should select highest priority available version");
            }
        });
    }

    /**
     * Property: For any fallback operation, the system should notify the developer 
     * about the version downgrade and reasons
     * 
     * **Validates: Requirements 4.2, 4.5**
     */
    public function testFallbackProvidesNotificationWithReason()
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.6', '9.0', '7.4']), // Unavailable versions
            Generator\subset($this->supportedVersions) // Available versions
        )->then(function ($unavailableVersion, $availableVersions) {
            // Skip if no versions are available
            if (empty($availableVersions)) {
                return;
            }

            // Configure mock for availability
            $this->mockRegistryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($version, $containerType) use ($availableVersions, $unavailableVersion) {
                    if ($version === $unavailableVersion) {
                        return false;
                    }
                    return in_array($version, $availableVersions);
                });

            $strategy = new FallbackStrategy($this->mockRegistryMonitor);
            $result = $strategy->applyFallback($unavailableVersion);

            if ($result->success) {
                // Should provide notification message
                $this->assertNotEmpty($result->message, "Should provide notification message");
                
                // Message should mention the unavailable version
                $this->assertStringContainsString($unavailableVersion, $result->message,
                    "Message should mention unavailable version");
                
                // Message should mention the fallback version
                $this->assertStringContainsString($result->fallbackVersion, $result->message,
                    "Message should mention fallback version");
                
                // Message should indicate unavailability reason
                $this->assertStringContainsString('unavailable', $result->message,
                    "Message should indicate version unavailability");
            }
        });
    }

    /**
     * Property: For any version that is available across all containers, 
     * no fallback should be applied
     * 
     * **Validates: Requirements 4.1**
     */
    public function testNoFallbackForAvailableVersions()
    {
        $this->limitTo(3)->forAll(
            Generator\elements($this->supportedVersions) // Available versions
        )->then(function ($availableVersion) {
            // Configure mock to return true for all containers
            $this->mockRegistryMonitor
                ->method('checkAvailability')
                ->willReturn(true);

            $strategy = new FallbackStrategy($this->mockRegistryMonitor);
            
            // Should not apply fallback for available version
            $shouldApply = $strategy->shouldApplyFallback($availableVersion);
            $this->assertFalse($shouldApply, 
                "Should not apply fallback for available version {$availableVersion}");
        });
    }

    /**
     * Property: For any fallback strategy configuration, the priority list 
     * should determine version selection order
     * 
     * **Validates: Requirements 4.4**
     */
    public function testPriorityListDeterminesSelectionOrder()
    {
        $this->limitTo(3)->forAll(
            Generator\seq(Generator\elements(['8.1', '8.2', '8.3', '8.4']))->map(function($arr) {
                shuffle($arr);
                return $arr;
            }), // Different priority orders
            Generator\elements(['8.6', '9.0']) // Unavailable version
        )->then(function ($customPriority, $unavailableVersion) {
            // All versions in priority list are available
            $this->mockRegistryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($version, $containerType) use ($customPriority, $unavailableVersion) {
                    if ($version === $unavailableVersion) {
                        return false;
                    }
                    return in_array($version, $customPriority);
                });

            $strategy = new FallbackStrategy($this->mockRegistryMonitor, $customPriority);
            $result = $strategy->applyFallback($unavailableVersion);

            if ($result->success) {
                // Should select first version in custom priority list
                $expectedFallback = $customPriority[0];
                $this->assertEquals($expectedFallback, $result->fallbackVersion,
                    "Should select first version from custom priority list");
            }
        });
    }

    /**
     * Property: For any disabled fallback strategy, no fallback should be applied
     * regardless of version availability
     * 
     * **Validates: Requirements 4.1**
     */
    public function testDisabledStrategyPreventsAllFallbacks()
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.6', '9.0', '7.4']), // Unavailable versions
            Generator\subset($this->supportedVersions) // Available versions
        )->then(function ($unavailableVersion, $availableVersions) {
            // Configure mock for mixed availability
            $this->mockRegistryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($version, $containerType) use ($availableVersions, $unavailableVersion) {
                    if ($version === $unavailableVersion) {
                        return false;
                    }
                    return in_array($version, $availableVersions);
                });

            $strategy = new FallbackStrategy($this->mockRegistryMonitor, null, 'disabled');
            
            // Should not apply fallback when strategy is disabled
            $shouldApply = $strategy->shouldApplyFallback($unavailableVersion);
            $this->assertFalse($shouldApply, 
                "Should not apply fallback when strategy is disabled");
            
            $result = $strategy->applyFallback($unavailableVersion);
            $this->assertFalse($result->success, 
                "Fallback should fail when strategy is disabled");
        });
    }

    /**
     * Property: For any exact_match strategy, only exact version matches should be used
     * 
     * **Validates: Requirements 4.1**
     */
    public function testExactMatchStrategyRejectsAllFallbacks()
    {
        $this->limitTo(3)->forAll(
            Generator\elements(['8.6', '9.0', '7.4']), // Unavailable versions
            Generator\subset($this->supportedVersions) // Available versions
        )->then(function ($unavailableVersion, $availableVersions) {
            // Configure mock for availability
            $this->mockRegistryMonitor
                ->method('checkAvailability')
                ->willReturnCallback(function ($version, $containerType) use ($availableVersions, $unavailableVersion) {
                    if ($version === $unavailableVersion) {
                        return false;
                    }
                    return in_array($version, $availableVersions);
                });

            $strategy = new FallbackStrategy($this->mockRegistryMonitor, null, 'exact_match');
            $result = $strategy->selectFallbackVersion($unavailableVersion, $availableVersions);

            // Should not select any fallback for exact_match strategy
            $this->assertNull($result, 
                "exact_match strategy should not select any fallback version");
        });
    }
}