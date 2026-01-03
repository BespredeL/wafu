<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;

final class PathTraversalModule implements ModuleInterface
{
    /**
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
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onMatch === null || $this->compiledPatterns === []) {
            return null;
        }

        $values = $this->collectTargets($context);
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
                    $context->setAttribute('wafu.match', [
                        'module'  => self::class,
                        'pattern' => $pattern,
                        'value'   => $this->truncate($value, 512),
                        'targets' => $this->targets,
                    ]);

                    $resp = $context->getAttribute('wafu.response');
                    if (is_array($resp) && isset($resp['status'])) {
                        return Decision::blockWithResponse(
                            $this->onMatch,
                            $this->reason,
                            (int)$resp['status'],
                            (array)($resp['headers'] ?? []),
                            (string)($resp['body'] ?? $this->reason),
                            ['match' => $context->getAttribute('wafu.match')]
                        );
                    }

                    return Decision::block($this->onMatch, $this->reason);
                }
            }
        }

        return null;
    }

    /**
     * Traversal signatures:
     * - ../ и ..\\
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

    /**
     * @param array $patterns
     *
     * @return array
     */
    private function validatePatterns(array $patterns): array
    {
        $ok = [];
        foreach ($patterns as $p) {
            if (!is_string($p) || $p === '') {
                continue;
            }

            if (@preg_match($p, '') === false) {
                continue;
            }

            $ok[] = $p;
        }

        return $ok;
    }

    /**
     * @param Context $context
     *
     * @return array
     */
    private function collectTargets(Context $context): array
    {
        $targets = $this->targets;

        if (in_array('all', $targets, true) || in_array('payload', $targets, true)) {
            return $context->getFlattenedPayload();
        }

        $values = [];

        foreach ($targets as $t) {
            switch ($t) {
                case 'query':
                    $values = array_merge($values, $this->flatten($context->getQuery()));
                    break;
                case 'body':
                    $values = array_merge($values, $this->flatten($context->getBody()));
                    break;
                case 'cookies':
                    $values = array_merge($values, $this->flatten($context->getCookies()));
                    break;
                case 'headers':
                    $values = array_merge($values, array_values($context->getHeaders()));
                    break;
                case 'uri':
                    $values[] = $context->getUri();
                    break;
            }
        }

        return array_values(array_map('strval', $values));
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function flatten(array $data): array
    {
        $result = [];

        $walk = function ($value) use (&$result, &$walk) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $walk($v);
                }
            } else {
                $result[] = (string)$value;
            }
        };

        $walk($data);

        return $result;
    }

    /**
     * @param string $s
     * @param int    $max
     *
     * @return string
     */
    private function truncate(string $s, int $max): string
    {
        return (mb_strlen($s) <= $max) ? $s : (mb_substr($s, 0, $max) . '...');
    }
}