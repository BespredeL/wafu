<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Registry;

use RuntimeException;

final class PatternRegistry
{
    /**
     * @var array
     */
    private array $patternsConfig;

    /**
     * @param array $patternsConfig
     */
    public function __construct(array $patternsConfig)
    {
        $this->patternsConfig = $patternsConfig;
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
        if (!isset($this->patternsConfig[$name])) {
            throw new RuntimeException("Pattern set '{$name}' is not defined in config");
        }

        $set = $this->patternsConfig[$name];

        if (!is_array($set)) {
            throw new RuntimeException("Pattern set '{$name}' must be an array of regex strings");
        }

        foreach ($set as $i => $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new RuntimeException("Pattern '{$name}' at index {$i} must be a non-empty string");
            }
        }

        return array_values($set);
    }

    /**
     * Get all pattern sets (for debug/inspection).
     *
     * @return array
     */
    public function all(): array
    {
        return $this->patternsConfig;
    }
}