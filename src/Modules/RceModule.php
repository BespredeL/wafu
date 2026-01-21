<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;

/**
 * Remote Code Execution (RCE) detection module.
 * Extends AbstractPatternModule to follow DRY principle.
 */
final class RceModule extends AbstractPatternModule
{
    /**
     * @param array                $targets
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        array            $targets = ['query', 'body', 'cookies', 'headers', 'uri'],
        array            $patterns = [],
        ?ActionInterface $onMatch = null,
        string           $reason = 'RCE attempt detected'
    )
    {
        parent::__construct($targets, $patterns, $onMatch, $reason);
    }

    /**
     * Get default RCE patterns.
     *
     * @return array
     */
    protected function getDefaultPatterns(): array
    {
        return [
            // dividers/bypasses
            '/(;|\|\||&&|\||`|\$\(|\${|\%60)/',
            '/\b(?:bash|sh|cmd|powershell|pwsh)\b/i',

            // typical utilities for downloading/executing
            '/\b(?:curl|wget|fetch|tftp)\b/i',
            '/\b(?:nc|netcat|ncat|socat)\b/i',

            // inline execution
            '/\bpython\s*-c\b/i',
            '/\bperl\s*-e\b/i',
            '/\bphp\s*-r\b/i',

            // /bin/sh -c
            '/\/bin\/(?:ba)?sh\b.*\s-c\b/i',
        ];
    }
}