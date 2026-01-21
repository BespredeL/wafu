<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Helpers\ModuleHelperTrait;

final class HeaderModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * Compiled patterns.
     *
     * @var array
     */
    private array $compiledPatterns = [];


    /**
     * @param array                $headers
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        private array            $headers = ['User-Agent'],
        array                    $patterns = [],
        private ?ActionInterface $onMatch = null,
        private string           $reason = 'Suspicious header detected'
    )
    {
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

        foreach ($this->headers as $headerName) {
            $value = $context->getHeader($headerName);
            if ($value === null || $value === '') {
                continue;
            }

            foreach ($this->compiledPatterns as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    $matchData = [
                        'module'  => self::class,
                        'header'  => $headerName,
                        'pattern' => $pattern,
                        'value'   => $this->truncate($value, 512),
                    ];
                    $context->setAttribute('wafu.match', $matchData);

                    return $this->createDecision($context, $this->onMatch, $this->reason, $matchData);
                }
            }
        }

        return null;
    }

}