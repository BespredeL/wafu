<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Core\Net;
use Bespredel\Wafu\Traits\ModuleHelperTrait;

final class IpBlocklistModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * Hash table for exact IP matches (O(1) lookup)
     *
     * @var array
     */
    private array $exactIps = [];

    /**
     * List of CIDR blocks (for range matching)
     *
     * @var array
     */
    private array $cidrBlocks = [];

    /**
     * @param array                $blocklist
     * @param ActionInterface|null $onMatch
     * @param string               $reason
     */
    public function __construct(
        array                    $blocklist = [],
        private ?ActionInterface $onMatch = null,
        private string           $reason = 'IP blocked'
    )
    {
        $this->optimizeBlocklist($blocklist);
    }

    /**
     * Optimize blocklist by separating exact IPs and CIDR blocks.
     *
     * @param array $blocklist
     *
     * @return void
     */
    private function optimizeBlocklist(array $blocklist): void
    {
        foreach ($blocklist as $rule) {
            if (!is_string($rule) || $rule === '') {
                continue;
            }

            if (str_contains($rule, '/')) {
                // CIDR block
                $this->cidrBlocks[] = $rule;
            } else {
                // Exact IP address - use hash table for O(1) lookup
                $this->exactIps[$rule] = true;
            }
        }
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
        if ($this->onMatch === null) {
            return null;
        }

        $ip = $context->getIp();
        if ($ip === '' || $ip === '0.0.0.0') {
            return null;
        }

        // Fast O(1) lookup for exact IP matches
        if (isset($this->exactIps[$ip])) {
            $matchData = [
                'module' => self::class,
                'ip'     => $ip,
                'rule'   => 'exact_ip',
            ];
            $context->setAttribute('wafu.match', $matchData);

            return $this->createDecision($context, $this->onMatch, $this->reason, $matchData);
        }

        // Check CIDR blocks only if no exact match found
        if ($this->cidrBlocks !== []) {
            foreach ($this->cidrBlocks as $cidr) {
                if (Net::ipInCidr($ip, $cidr)) {
                    $matchData = [
                        'module' => self::class,
                        'ip'     => $ip,
                        'rule'   => $cidr,
                    ];
                    $context->setAttribute('wafu.match', $matchData);

                    return $this->createDecision($context, $this->onMatch, $this->reason, $matchData);
                }
            }
        }

        return null;
    }
}