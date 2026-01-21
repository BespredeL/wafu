<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;

final class MethodAllowlistModule implements ModuleInterface
{
    /**
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
        $allow = array_map('strtoupper', $this->allow);

        if (!in_array($method, $allow, true)) {
            $context->setAttribute('wafu.match', [
                'module' => self::class,
                'method' => $method,
                'allow'  => $allow,
            ]);

            return Decision::block($this->onDeny, $this->reason);
        }

        return null;
    }
}