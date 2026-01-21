<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Registry;

use RuntimeException;

final class PatternRegistry
{
    /**
     * Original patterns config
     *
     * @var array
     */
    private array $patternsConfig;

    /**
     * Validated and cached patterns
     *
     * @var array
     */
    private array $validatedPatterns = [];

    /**
     * @param array $patternsConfig
     */
    public function __construct(array $patternsConfig)
    {
        $this->patternsConfig = $patternsConfig;
        $this->validateAllPatterns();
    }

    /**
     * Validate all patterns once during construction.
     *
     * @return void
     */
    private function validateAllPatterns(): void
    {
        foreach ($this->patternsConfig as $name => $set) {
            if (!is_array($set)) {
                throw new RuntimeException("Pattern set '{$name}' must be an array of regex strings");
            }

            $validated = [];
            foreach ($set as $i => $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    throw new RuntimeException("Pattern '{$name}' at index {$i} must be a non-empty string");
                }

                // Validate regex pattern
                if (@preg_match($pattern, '') === false) {
                    // Skip invalid patterns but don't fail - allow graceful degradation
                    continue;
                }

                $validated[] = $pattern;
            }

            $this->validatedPatterns[$name] = $validated;
        }
    }

    /**
     * Get a list of regex patterns by name.
     *
     * @param string $name
     *
     * @return array
     */
    public function get(string $name): array
    {
        if (!isset($this->validatedPatterns[$name])) {
            throw new RuntimeException("Pattern set '{$name}' is not defined in config");
        }

        return $this->validatedPatterns[$name];
    }

    /**
     * Get all pattern sets (for debug/inspection).
     *
     * @return array
     */
    public function all(): array
    {
        return $this->validatedPatterns;
    }
}