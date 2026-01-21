<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Helpers\ModuleHelperTrait;

final class LfiModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * Compiled patterns.
     *
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
        private array            $targets = ['query', 'body', 'cookies', 'uri'],
        array                    $patterns = [],
        private ?ActionInterface $onMatch = null,
        private string           $reason = 'LFI attempt detected'
    )
    {
        $patterns = $patterns !== [] ? $patterns : self::defaultPatterns();
        $this->compiledPatterns = $this->validatePatterns($patterns);
    }

    /**
     * Handle request.
     *
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
     * Basic LFI signatures:
     * - calls to /etc/passwd, proc, env, log, keys, configs
     * - PHP wrappers (php://filter, data://, expect://)
     *
     * @return array
     */
    private static function defaultPatterns(): array
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