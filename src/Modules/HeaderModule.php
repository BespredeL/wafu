<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;

final class HeaderModule implements ModuleInterface
{
    /**
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
                    $context->setAttribute('wafu.match', [
                        'module'  => self::class,
                        'header'  => $headerName,
                        'pattern' => $pattern,
                        'value'   => $this->truncate($value, 512),
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
     * @param string $s
     * @param int    $max
     *
     * @return string
     */
    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max) . '...';
    }
}