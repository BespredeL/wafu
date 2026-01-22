<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;

final class RegexMatchModule extends AbstractPatternModule
{
    /**
     * @param array                $targets
     * @param array                $patterns
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        array            $targets = ['query', 'body'],
        array            $patterns = [],
        ?ActionInterface $onMatch = null,
        string           $reason = 'Attack pattern matched'
    )
    {
        parent::__construct($targets, $patterns, $onMatch, $reason);
    }
}