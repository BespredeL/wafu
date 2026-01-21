<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Helpers;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;

trait ModuleHelperTrait
{
    /**
     * Create a Decision with optional response from context.
     *
     * @param Context         $context
     * @param ActionInterface $action
     * @param string          $reason
     * @param array           $matchData
     *
     * @return Decision
     */
    protected function createDecision(
        Context         $context,
        ActionInterface $action,
        string          $reason,
        array           $matchData = []
    ): Decision
    {
        $resp = $context->getAttribute('wafu.response');
        if (is_array($resp) && isset($resp['status'])) {
            return Decision::blockWithResponse(
                $action,
                $reason,
                (int)$resp['status'],
                (array)($resp['headers'] ?? []),
                (string)($resp['body'] ?? $reason),
                ['match' => $matchData]
            );
        }

        return Decision::block($action, $reason);
    }

    /**
     * Validate and filter regex patterns.
     *
     * @param array $patterns
     *
     * @return array
     */
    protected function validatePatterns(array $patterns): array
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

    /**
     * Flatten array recursively to string values.
     *
     * @param array $data
     *
     * @return array
     */
    protected function flatten(array $data): array
    {
        $result = [];

        $walk = static function ($value) use (&$result, &$walk) {
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
     * Collect target values from context based on target list.
     *
     * @param Context $context
     * @param array   $targets
     *
     * @return array
     */
    protected function collectTargets(Context $context, array $targets): array
    {
        if (in_array('all', $targets, true) || in_array('payload', $targets, true)) {
            return $context->getFlattenedPayload();
        }

        $valueArrays = [];
        $singleValues = [];

        foreach ($targets as $t) {
            switch ($t) {
                case 'query':
                    $valueArrays[] = $this->flatten($context->getQuery());
                    break;
                case 'body':
                    $valueArrays[] = $this->flatten($context->getBody());
                    break;
                case 'cookies':
                    $valueArrays[] = $this->flatten($context->getCookies());
                    break;
                case 'headers':
                    $valueArrays[] = array_values($context->getHeaders());
                    break;
                case 'uri':
                    $singleValues[] = $context->getUri();
                    break;
                case 'method':
                    $singleValues[] = $context->getMethod();
                    break;
                case 'ip':
                    $singleValues[] = $context->getIp();
                    break;
            }
        }

        $values = [];
        foreach ($valueArrays as $arr) {
            foreach ($arr as $val) {
                $values[] = $val;
            }
        }

        foreach ($singleValues as $val) {
            $values[] = (string)$val;
        }

        return array_values($values);
    }

    /**
     * Truncate string to maximum length.
     *
     * @param string $s
     * @param int    $max
     *
     * @return string
     */
    protected function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max) . '...';
    }
}
