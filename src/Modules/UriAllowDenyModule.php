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
     * Compiled regex patterns.
     *
     * @var array
     */
    private array $validatedAllowRegex = [];

    /**
     * Compiled regex patterns.
     *
     * @var array
     */
    private array $validatedDenyRegex = [];

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
        array                    $allowRegex = [],
        array                    $denyRegex = [],
        private array            $allowPrefix = [],
        private array            $denyPrefix = [],
        private ?ActionInterface $onDeny = null,
        private string           $reason = 'URI is not allowed'
    )
    {
        $this->validatedAllowRegex = $this->validatePatterns($allowRegex);
        $this->validatedDenyRegex = $this->validatePatterns($denyRegex);
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

        $uri = $context->getUri() ?: '/';

        // deny prefix
        foreach ($this->denyPrefix as $p) {
            if (is_string($p) && $p !== '' && str_starts_with($uri, $p)) {
                return $this->deny($context, $uri, 'denyPrefix', $p);
            }
        }

        // deny regex
        foreach ($this->validatedDenyRegex as $rx) {
            if (preg_match($rx, $uri) === 1) {
                return $this->deny($context, $uri, 'denyRegex', $rx);
            }
        }

        // allow prefix/regex logic
        if ($this->allowPrefix !== [] || $this->validatedAllowRegex !== []) {
            foreach ($this->allowPrefix as $p) {
                if (is_string($p) && $p !== '' && str_starts_with($uri, $p)) {
                    return null;
                }
            }

            foreach ($this->validatedAllowRegex as $rx) {
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
     * Deny request.
     *
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

    /**
     * Validate and filter regex patterns.
     *
     * @param array $patterns
     *
     * @return array
     */
    private function validatePatterns(array $patterns): array
    {
        $validated = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            // Validate regex pattern
            if (@preg_match($pattern, '') === false) {
                continue;
            }

            $validated[] = $pattern;
        }

        return $validated;
    }
}