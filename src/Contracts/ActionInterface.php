<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Contracts;

use Bespredel\Wafu\Core\Context;

interface ActionInterface
{
    /**
     * Execute WAFU action.
     *
     * The Action should NOT decide whether to block the request or not -
     * this is Decision's responsibility.
     *
     * Examples:
     *  - send 403
     *  - write log
     *  - initiate challenge
     *
     * @param Context $context
     */
    public function execute(Context $context): void;
}