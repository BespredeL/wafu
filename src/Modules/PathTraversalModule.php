<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Helpers\ModuleHelperTrait;

final class PathTraversalModule implements ModuleInterface
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
        private array            $targets = ['uri', 'query', 'body', 'cookies'],
        array                    $patterns = [],
        private ?ActionInterface $onMatch = null,
        private string           $reason = 'Path traversal attempt detected'
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
     * Traversal signatures:
     * - ../ and ..\\
     * - urlencoded variants: %2e%2e%2f, %2e%2e%5c
     * - double-encoding: %252e%252e%252f
     *
     * @return array
     */
    private static function defaultPatterns(): array
    {
        return [
            '/\.\.(\/|\\\\)/',                 // ../ or ..\
            '/%2e%2e(\/|%2f|\\\\|%5c)/i',      // encoded
            '/%252e%252e%252f/i',              // double encoded ../
            '/%c0%ae%c0%ae/i',                 // overlong UTF-8 dot variants
        ];
    }

}