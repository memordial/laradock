<?php

namespace Laradock\PHPVersionManager\Models;

/**
 * Model representing consistency check results across containers
 * 
 * Provides detailed information about version consistency status
 * and any detected mismatches.
 */
class ConsistencyReport
{
    public bool $isConsistent;
    public array $containerVersions;
    public array $mismatches;
    public string $expectedVersion;

    public function __construct(
        bool $isConsistent,
        array $containerVersions = [],
        array $mismatches = [],
        string $expectedVersion = ''
    ) {
        $this->isConsistent = $isConsistent;
        $this->containerVersions = $containerVersions;
        $this->mismatches = $mismatches;
        $this->expectedVersion = $expectedVersion;
    }

    /**
     * Get a formatted summary of the consistency status
     * 
     * @return string Consistency summary message
     */
    public function getSummary(): string
    {
        if ($this->isConsistent) {
            return sprintf(
                'All containers are using PHP %s consistently.',
                $this->expectedVersion
            );
        }

        $mismatchCount = count($this->mismatches);
        return sprintf(
            'Version inconsistency detected: %d container(s) have mismatched PHP versions.',
            $mismatchCount
        );
    }

    /**
     * Get detailed mismatch information
     * 
     * @return array Detailed mismatch descriptions
     */
    public function getMismatchDetails(): array
    {
        $details = [];
        foreach ($this->mismatches as $container => $version) {
            $details[] = sprintf(
                '%s container: PHP %s (expected: PHP %s)',
                ucfirst($container),
                $version,
                $this->expectedVersion
            );
        }
        return $details;
    }

    /**
     * Convert to array for serialization
     * 
     * @return array Consistency report as array
     */
    public function toArray(): array
    {
        return [
            'isConsistent' => $this->isConsistent,
            'containerVersions' => $this->containerVersions,
            'mismatches' => $this->mismatches,
            'expectedVersion' => $this->expectedVersion
        ];
    }
}