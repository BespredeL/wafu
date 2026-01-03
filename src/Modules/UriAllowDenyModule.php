<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;

final class UriAllowDenyModule implements ModuleInterface
{
    /**
     * Logic:
     * - if allow is set, and the uri does not match any allow => deny
     * - if deny is set, and the uri matches any deny => deny
     *
     * @param array                $allowRegex  regex allowlist
     * @param array                $denyRegex   regex denylist
     * @param array                $allowPrefix allow prefixes (faster regex)
     * @param array                $denyPrefix  deny prefixes
     * @param ActionInterface|null $onDeny      action when denying
     * @param string               $reason      reason
     */
    public function __construct(
        private array            $allowRegex = [],
        private array            $denyRegex = [],
        private array            $allowPrefix = [],
        private array            $denyPrefix = [],
        private ?ActionInterface $onDeny = null,
        private string           $reason = 'URI is not allowed'
    )
    {
    }

    /**
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onDeny === null) {
            return null;
        }

        $uri = $context->getUri() ?: '/';

        // deny prefix
        foreach ($this->denyPrefix as $p) {
            if (is_string($p) && $p !== '' && str_starts_with($uri, $p)) {
                return $this->deny($context, $uri, 'denyPrefix', $p);
            }
        }

        // deny regex
        foreach ($this->denyRegex as $rx) {
            if (!is_string($rx) || $rx === '' || @preg_match($rx, '') === false) {
                continue;
            }

            if (preg_match($rx, $uri) === 1) {
                return $this->deny($context, $uri, 'denyRegex', $rx);
            }
        }

        // allow prefix/regex logic
        if ($this->allowPrefix !== [] || $this->allowRegex !== []) {
            foreach ($this->allowPrefix as $p) {
                if (is_string($p) && $p !== '' && str_starts_with($uri, $p)) {
                    return null;
                }
            }

            foreach ($this->allowRegex as $rx) {
                if (!is_string($rx) || $rx === '' || @preg_match($rx, '') === false) {
                    continue;
                }

                if (preg_match($rx, $uri) === 1) {
                    return null;
                }
            }

            // didn't get anywhere from allow => deny
            return $this->deny($context, $uri, 'allow', 'no-match');
        }

        return null;
    }

    /**
     * @param Context $context
     * @param string  $uri
     * @param string  $type
     * @param string  $rule
     *
     * @return Decision
     */
    private function deny(Context $context, string $uri, string $type, string $rule): Decision
    {
        $context->setAttribute('wafu.match', [
            'module' => self::class,
            'uri'    => $uri,
            'type'   => $type,
            'rule'   => $rule,
        ]);

        return Decision::block($this->onDeny, $this->reason);
    }
}