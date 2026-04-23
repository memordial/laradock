<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\Models\ConsistencyReport;

/**
 * Unit tests for ConsistencyReport model
 */
class ConsistencyReportTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $report = new ConsistencyReport(true);
        
        $this->assertTrue($report->isConsistent);
        $this->assertEmpty($report->containerVersions);
        $this->assertEmpty($report->mismatches);
        $this->assertEquals('', $report->expectedVersion);
    }

    public function testConstructorWithAllParameters(): void
    {
        $containerVersions = ['workspace' => '8.4', 'php-fpm' => '8.4', 'nginx' => '8.4'];
        $mismatches = ['workspace' => '8.5'];
        
        $report = new ConsistencyReport(
            false,
            $containerVersions,
            $mismatches,
            '8.4'
        );
        
        $this->assertFalse($report->isConsistent);
        $this->assertEquals($containerVersions, $report->containerVersions);
        $this->assertEquals($mismatches, $report->mismatches);
        $this->assertEquals('8.4', $report->expectedVersion);
    }

    public function testGetSummaryWhenConsistent(): void
    {
        $report = new ConsistencyReport(true, [], [], '8.4');
        
        $summary = $report->getSummary();
        
        $this->assertEquals('All containers are using PHP 8.4 consistently.', $summary);
    }

    public function testGetSummaryWhenInconsistent(): void
    {
        $mismatches = ['workspace' => '8.5', 'php-fpm' => '8.3'];
        $report = new ConsistencyReport(false, [], $mismatches, '8.4');
        
        $summary = $report->getSummary();
        
        $this->assertEquals('Version inconsistency detected: 2 container(s) have mismatched PHP versions.', $summary);
    }

    public function testGetMismatchDetailsWithNoMismatches(): void
    {
        $report = new ConsistencyReport(true, [], [], '8.4');
        
        $details = $report->getMismatchDetails();
        
        $this->assertEmpty($details);
    }

    public function testGetMismatchDetailsWithMismatches(): void
    {
        $mismatches = ['workspace' => '8.5', 'php-fpm' => '8.3'];
        $report = new ConsistencyReport(false, [], $mismatches, '8.4');
        
        $details = $report->getMismatchDetails();
        
        $expected = [
            'Workspace container: PHP 8.5 (expected: PHP 8.4)',
            'Php-fpm container: PHP 8.3 (expected: PHP 8.4)'
        ];
        
        $this->assertEquals($expected, $details);
    }

    public function testToArray(): void
    {
        $containerVersions = ['workspace' => '8.4', 'php-fpm' => '8.4'];
        $mismatches = ['nginx' => '8.3'];
        
        $report = new ConsistencyReport(
            false,
            $containerVersions,
            $mismatches,
            '8.4'
        );
        
        $array = $report->toArray();
        
        $expected = [
            'isConsistent' => false,
            'containerVersions' => $containerVersions,
            'mismatches' => $mismatches,
            'expectedVersion' => '8.4'
        ];
        
        $this->assertEquals($expected, $array);
    }

    public function testSingleMismatch(): void
    {
        $mismatches = ['workspace' => '8.5'];
        $report = new ConsistencyReport(false, [], $mismatches, '8.4');
        
        $summary = $report->getSummary();
        $details = $report->getMismatchDetails();
        
        $this->assertEquals('Version inconsistency detected: 1 container(s) have mismatched PHP versions.', $summary);
        $this->assertEquals(['Workspace container: PHP 8.5 (expected: PHP 8.4)'], $details);
    }
}