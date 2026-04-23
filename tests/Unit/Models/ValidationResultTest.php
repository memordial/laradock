<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Laradock\PHPVersionManager\Models\ValidationResult;

/**
 * Unit tests for ValidationResult model
 */
class ValidationResultTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $result = new ValidationResult();
        
        $this->assertTrue($result->isValid);
        $this->assertEmpty($result->errors);
        $this->assertEmpty($result->warnings);
        $this->assertEmpty($result->suggestions);
    }

    public function testConstructorWithAllParameters(): void
    {
        $errors = ['Error 1', 'Error 2'];
        $warnings = ['Warning 1'];
        $suggestions = ['Suggestion 1'];
        
        $result = new ValidationResult(false, $errors, $warnings, $suggestions);
        
        $this->assertFalse($result->isValid);
        $this->assertEquals($errors, $result->errors);
        $this->assertEquals($warnings, $result->warnings);
        $this->assertEquals($suggestions, $result->suggestions);
    }

    public function testHasErrorsWithNoErrors(): void
    {
        $result = new ValidationResult();
        
        $this->assertFalse($result->hasErrors());
    }

    public function testHasErrorsWithErrors(): void
    {
        $result = new ValidationResult(false, ['Error 1']);
        
        $this->assertTrue($result->hasErrors());
    }

    public function testHasWarningsWithNoWarnings(): void
    {
        $result = new ValidationResult();
        
        $this->assertFalse($result->hasWarnings());
    }

    public function testHasWarningsWithWarnings(): void
    {
        $result = new ValidationResult(true, [], ['Warning 1']);
        
        $this->assertTrue($result->hasWarnings());
    }

    public function testGetErrorSummaryWithNoErrors(): void
    {
        $result = new ValidationResult();
        
        $this->assertEquals('No errors found.', $result->getErrorSummary());
    }

    public function testGetErrorSummaryWithErrors(): void
    {
        $result = new ValidationResult(false, ['Error 1', 'Error 2']);
        
        $this->assertEquals('Validation errors: Error 1; Error 2', $result->getErrorSummary());
    }

    public function testGetWarningSummaryWithNoWarnings(): void
    {
        $result = new ValidationResult();
        
        $this->assertEquals('No warnings found.', $result->getWarningSummary());
    }

    public function testGetWarningSummaryWithWarnings(): void
    {
        $result = new ValidationResult(true, [], ['Warning 1', 'Warning 2']);
        
        $this->assertEquals('Validation warnings: Warning 1; Warning 2', $result->getWarningSummary());
    }

    public function testAddError(): void
    {
        $result = new ValidationResult();
        
        $result->addError('New error');
        
        $this->assertFalse($result->isValid);
        $this->assertEquals(['New error'], $result->errors);
        $this->assertTrue($result->hasErrors());
    }

    public function testAddWarning(): void
    {
        $result = new ValidationResult();
        
        $result->addWarning('New warning');
        
        $this->assertTrue($result->isValid); // Adding warning doesn't change validity
        $this->assertEquals(['New warning'], $result->warnings);
        $this->assertTrue($result->hasWarnings());
    }

    public function testAddSuggestion(): void
    {
        $result = new ValidationResult();
        
        $result->addSuggestion('New suggestion');
        
        $this->assertTrue($result->isValid);
        $this->assertEquals(['New suggestion'], $result->suggestions);
    }

    public function testToArray(): void
    {
        $errors = ['Error 1'];
        $warnings = ['Warning 1'];
        $suggestions = ['Suggestion 1'];
        
        $result = new ValidationResult(false, $errors, $warnings, $suggestions);
        
        $array = $result->toArray();
        
        $expected = [
            'isValid' => false,
            'errors' => $errors,
            'warnings' => $warnings,
            'suggestions' => $suggestions
        ];
        
        $this->assertEquals($expected, $array);
    }

    public function testMultipleErrorsAndWarnings(): void
    {
        $result = new ValidationResult();
        
        $result->addError('Error 1');
        $result->addError('Error 2');
        $result->addWarning('Warning 1');
        $result->addWarning('Warning 2');
        $result->addSuggestion('Suggestion 1');
        
        $this->assertFalse($result->isValid);
        $this->assertEquals(['Error 1', 'Error 2'], $result->errors);
        $this->assertEquals(['Warning 1', 'Warning 2'], $result->warnings);
        $this->assertEquals(['Suggestion 1'], $result->suggestions);
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasWarnings());
    }
}