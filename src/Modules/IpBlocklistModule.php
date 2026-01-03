<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Core\Net;

final class IpBlocklistModule implements ModuleInterface
{
    /**
     * @param array                $blocklist
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        private array            $blocklist = [],
        private ?ActionInterface $onMatch = null,
        private string           $reason = 'IP blocked'
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
        if ($this->onMatch === null || $this->blocklist === []) {
            return null;
        }

        $ip = $context->getIp();
        if ($ip === '' || $ip === '0.0.0.0') {
            return null;
        }

        if (Net::ipMatchesAny($ip, $this->blocklist)) {
            $context->setAttribute('wafu.match', [
                'module' => self::class,
                'ip'     => $ip,
                'rule'   => 'blocklist',
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

        return null;
    }
}