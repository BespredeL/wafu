<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\ContextKeys;
use Bespredel\Wafu\Core\Decision;

final class MethodAllowlistModule implements ModuleInterface
{
    /**
     * Constructor.
     *
     * @param array                $allow  HTTP methods allowed (GET,POST,...)
     * @param ActionInterface|null $onDeny action when method not allowed
     * @param string               $reason reason
     */
    public function __construct(
        private array            $allow = ['GET', 'POST', 'HEAD'],
        private ?ActionInterface $onDeny = null,
        private string           $reason = 'HTTP method not allowed'
    )
    {
        $this->allow = $this->normalizeAllowlist($this->allow);
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
        if ($this->onDeny === null) {
            return null;
        }

        $method = strtoupper($context->getMethod());
        if (!isset($this->allow[$method])) {
            $context->setAttribute(ContextKeys::MATCH, [
                'module' => self::class,
                'method' => $method,
                'allow'  => array_keys($this->allow),
            ]);

            return Decision::block($this->onDeny, $this->reason);
        }

        return null;
    }

    /**
     * Normalize the allowlist.
     *
     * @param array $methods The methods to normalize
     *
     * @return array
     */
    private function normalizeAllowlist(array $methods): array
    {
        $result = [];
        foreach ($methods as $method) {
            if (!is_string($method) || $method === '') {
                continue;
            }
            $result[strtoupper($method)] = true;
        }

        return $result;
    }
}