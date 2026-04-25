<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\ContextKeys;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Traits\ModuleHelperTrait;

abstract class AbstractPatternModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * Compiled patterns.
     *
     * @var array
     */
    protected array $compiledPatterns = [];

    /**
     * @param array                $targets
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        protected array            $targets = ['query', 'body'],
        array                      $patterns = [],
        protected ?ActionInterface $onMatch = null,
        protected string           $reason = 'Attack pattern matched'
    )
    {
        $patterns = $patterns !== [] ? $patterns : $this->getDefaultPatterns();

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

                // Very long strings can cause catastrophic backtracking
                if (strlen($value) > 10000) {
                    $value = substr($value, 0, 10000);
                }

                $result = @preg_match($pattern, $value);

                if ($result === 1) {
                    $matchData = [
                        'module'  => static::class,
                        'pattern' => $pattern,
                        'value'   => $this->truncate($value, 512),
                        'targets' => $this->targets,
                    ];
                    $context->setAttribute(ContextKeys::MATCH, $matchData);

                    return $this->createDecision($context, $this->onMatch, $this->reason, $matchData);
                }
            }
        }

        return null;
    }

    /**
     * Get default patterns for the module.
     * Override in child classes to provide module-specific patterns.
     *
     * @return array
     */
    protected function getDefaultPatterns(): array
    {
        return [];
    }
}