<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;

final class PathTraversalModule extends AbstractPatternModule
{
    /**
     * @param array                $targets
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        array            $targets = ['uri', 'query', 'body', 'cookies'],
        array            $patterns = [],
        ?ActionInterface $onMatch = null,
        string           $reason = 'Path traversal attempt detected'
    )
    {
        parent::__construct($targets, $patterns, $onMatch, $reason);
    }

    /**
     * Get default path traversal patterns.
     *
     * @return array
     */
    protected function getDefaultPatterns(): array
    {
        return [
            '/\.\.(\/|\\\\)/',                 // ../ or ..\
            '/%2e%2e(\/|%2f|\\\\|%5c)/i',      // encoded
            '/%252e%252e%252f/i',              // double encoded ../
            '/%c0%ae%c0%ae/i',                 // overlong UTF-8 dot variants
        ];
    }
}