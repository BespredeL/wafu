<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Contracts;

use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;

interface ModuleInterface
{
    /**
     * Analyze the current request.
     *
     * Return value:
     *  - null                  => the module did not detect anything
     *  - Decision::allow       => explicitly allow (rarely used)
     *  - Decision::action(...) => perform an action without blocking
     *  - Decision::block(...)  => block request
     *
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision;
}