<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;

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
            // Command separators followed by command binaries or execution wrappers
            '/(?:;|\|\||&&|\n)\s*(?:bash|sh|cmd|powershell|pwsh|curl|wget|nc|netcat|ncat|socat|cat|id|whoami|uname)\b/i',
            '/`[^`]+`/',
            '/\$\([^\)]+\)/',
            '/\${[A-Za-z0-9_]+}/',
            '/%60[^%]+%60/',
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