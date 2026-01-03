<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Helpers\ModuleHelperTrait;

final class RceModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * @var array
     */
    private array $compiledPatterns = [];

    /**
     * @param array                $targets
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        private array            $targets = ['query', 'body', 'cookies', 'headers', 'uri'],
        array                    $patterns = [],
        private ?ActionInterface $onMatch = null,
        private string           $reason = 'RCE attempt detected'
    )
    {
        $patterns = $patterns !== [] ? $patterns : self::defaultPatterns();
        $this->compiledPatterns = $this->validatePatterns($patterns);
    }

    /**
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onMatch === null || $this->compiledPatterns === []) {
            return null;
        }

        $values = $this->collectTargets($context, $this->targets);
        if ($values === []) {
            return null;
        }

        foreach ($this->compiledPatterns as $pattern) {
            foreach ($values as $value) {
                $value = (string)$value;
                if ($value === '') {
                    continue;
                }

                if (preg_match($pattern, $value) === 1) {
                    $matchData = [
                        'module'  => self::class,
                        'pattern' => $pattern,
                        'value'   => $this->truncate($value, 512),
                        'targets' => $this->targets,
                    ];
                    $context->setAttribute('wafu.match', $matchData);

                    return $this->createDecision($context, $this->onMatch, $this->reason, $matchData);
                }
            }
        }

        return null;
    }

    /**
     * Basic RCE signatures:
     * - command injection separators ; | && || ` $()
     * - dangerous utilities (bash/sh/cmd/powershell, curl/wget, nc, python -c, perl -e, php -r)
     *
     * @return array
     */
    private static function defaultPatterns(): array
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