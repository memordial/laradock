<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Model representing validation results
 * 
 * Contains errors, warnings, and suggestions from validation operations.
 */
class ValidationResult
{
    public bool $isValid;
    public array $errors;
    public array $warnings;
    public array $suggestions;

    public function __construct(
        bool $isValid = true,
        array $errors = [],
        array $warnings = [],
        array $suggestions = []
    ) {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->suggestions = $suggestions;
    }

    /**
     * Check if validation has any errors
     * 
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if validation has any warnings
     * 
     * @return bool True if warnings exist
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Get a summary of all errors
     * 
     * @return string Formatted error summary
     */
    public function getErrorSummary(): string
    {
        if (empty($this->errors)) {
            return 'No errors found.';
        }

        return 'Validation errors: ' . implode('; ', $this->errors);
    }

    /**
     * Get a summary of all warnings
     * 
     * @return string Formatted warning summary
     */
    public function getWarningSummary(): string
    {
        if (empty($this->warnings)) {
            return 'No warnings found.';
        }

        return 'Validation warnings: ' . implode('; ', $this->warnings);
    }

    /**
     * Add an error to the validation result
     * 
     * @param string $error Error message
     * @return void
     */
    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->isValid = false;
    }

    /**
     * Add a warning to the validation result
     * 
     * @param string $warning Warning message
     * @return void
     */
    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Add a suggestion to the validation result
     * 
     * @param string $suggestion Suggestion message
     * @return void
     */
    public function addSuggestion(string $suggestion): void
    {
        $this->suggestions[] = $suggestion;
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Validation result as array
     */
    public function toArray(): array
    {
        return [
            'isValid' => $this->isValid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'suggestions' => $this->suggestions
        ];
    }
}