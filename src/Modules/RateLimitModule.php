<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Traits\ModuleHelperTrait;
use Bespredel\Wafu\Storage\FileStorage;

/**
 * Rate limiting module.
 * Uses FileStorage to follow SRP - separates storage concerns from business logic.
 */
final class RateLimitModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * @param int                  $limit
     * @param int                  $interval
     * @param ActionInterface|null $onExceed
     * @param string               $keyBy
     * @param string               $reason
     * @param string|null          $storageDir
     * @param int                  $gcProbability
     * @param int                  $ttlMultiplier
     */
    public function __construct(
        private int              $limit = 100,
        private int              $interval = 60,
        private ?ActionInterface $onExceed = null,
        private string           $keyBy = 'ip', // ip|ip+uri
        private string           $reason = 'Rate limit exceeded',
        ?string                  $storageDir = null,
        private int              $gcProbability = 100,  // 1/N queries will launch GC (100 => 1%)
        private int              $ttlMultiplier = 5     // ttl = interval * ttlMultiplier
    )
    {
        $storageDir = $storageDir ?? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wafu-rl';

        $this->storage = new FileStorage($storageDir, $this->gcProbability, $this->ttlMultiplier);
    }

    /**
     * File storage instance.
     *
     * @var FileStorage
     */
    private FileStorage $storage;

    /**
     * Handle request.
     *
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onExceed === null || $this->limit <= 0 || $this->interval <= 0) {
            return null;
        }

        $this->storage->cleanup($this->interval);

        $key = $this->buildKey($context);
        $now = time();
        $windowStart = $now - $this->interval;

        $data = $this->storage->read($key);
        $timestamps = [];

        if ($data !== null) {
            foreach ($data['t'] as $ts) {
                if ($ts >= $windowStart) {
                    $timestamps[] = $ts;
                }
            }
        }

        $timestamps[] = $now;

        $cap = max($this->limit + 20, 50);
        if (count($timestamps) > $cap) {
            $timestamps = array_slice($timestamps, -$cap);
        }

        $exceeded = count($timestamps) > $this->limit;

        $this->storage->write($key, [
            't'  => $timestamps,
            'ls' => $now,
        ]);

        if ($exceeded) {
            $matchData = [
                'module'   => self::class,
                'key'      => $this->keyBy,
                'limit'    => $this->limit,
                'interval' => $this->interval,
            ];

            $context->setAttribute('wafu.match', $matchData);

            return $this->createDecision(
                $context,
                $this->onExceed,
                $this->reason,
                $matchData
            );
        }

        return null;
    }

    /**
     * Build key based on context.
     *
     * @param Context $context
     *
     * @return string
     */
    private function buildKey(Context $context): string
    {
        if ($this->keyBy === 'ip+uri') {
            return $context->getIp() . '|' . $context->getUri();
        }

        return $context->getIp();
    }
}