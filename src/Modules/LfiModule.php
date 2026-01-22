<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;

final class LfiModule extends AbstractPatternModule
{
    /**
     * @param array                $targets
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        array            $targets = ['query', 'body', 'cookies', 'uri'],
        array            $patterns = [],
        ?ActionInterface $onMatch = null,
        string           $reason = 'LFI attempt detected'
    )
    {
        parent::__construct($targets, $patterns, $onMatch, $reason);
    }

    /**
     * Get default LFI patterns.
     *
     * @return array
     */
    protected function getDefaultPatterns(): array
    {
        return [
            '/\bphp:\/\/(?:filter|input|stdin|memory|temp|fd)\b/i',
            '/\b(?:expect|data|zip|phar):\/\//i',

            '/\/etc\/passwd\b/i',
            '/\/etc\/shadow\b/i',
            '/\/proc\/self\/environ\b/i',
            '/\/proc\/(?:self|[0-9]+)\/cmdline\b/i',

            '/\b(?:\.ssh\/authorized_keys|\.ssh\/id_rsa|\.ssh\/id_ed25519)\b/i',
            '/\b(?:wp-config\.php|config\.php|configuration\.php|\.env)\b/i',

            '/\b(?:access\.log|error\.log|nginx\.log|apache2\/.*log)\b/i',
        ];
    }
}