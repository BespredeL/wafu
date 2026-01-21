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
 * 404 abuse detection module.
 * Uses FileStorage to follow SRP - separates storage concerns from business logic.
 */
final class NotFoundAbuseModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * File storage instance.
     *
     * @var FileStorage
     */
    private FileStorage $storage;

    /**
     * @param int                  $threshold     max allowed 404 within interval
     * @param int                  $interval      seconds window
     * @param ActionInterface|null $onExceed      action to perform when exceeded
     * @param string               $keyBy         ip|ip+uri
     * @param string               $reason        reason
     * @param string|null          $storageDir    optional storage directory
     * @param int                  $gcProbability 1/N requests triggers GC (e.g. 100 => 1%)
     * @param int                  $ttlMultiplier ttl = interval * ttlMultiplier
     */
    public function __construct(
        private readonly int              $threshold = 10,
        private readonly int              $interval = 60,
        private readonly ?ActionInterface $onExceed = null,
        private readonly string           $keyBy = 'ip',
        private readonly string           $reason = 'Excessive 404 detected',
        ?string                           $storageDir = null,
        private readonly int              $gcProbability = 100,
        private readonly int              $ttlMultiplier = 5
    )
    {
        $storageDir = $storageDir ?? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wafu-404';
        $this->storage = new FileStorage($storageDir, $this->gcProbability, $this->ttlMultiplier);
    }

    /**
     * Handle the request.
     *
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onExceed === null || $this->threshold <= 0 || $this->interval <= 0) {
            return null;
        }

        // Respond ONLY to 404
        $statusCode = $context->getAttribute('http_status_code', 200);
        if ($statusCode !== 404) {
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

        // Add current 404
        $timestamps[] = $now;

        // File Growth Protection
        $cap = max($this->threshold + 20, 50);
        if (count($timestamps) > $cap) {
            $timestamps = array_slice($timestamps, -$cap);
        }

        $count = count($timestamps);

        $this->storage->write($key, [
            't'  => $timestamps,
            'ls' => $now,
        ]);

        if ($count > $this->threshold) {
            $matchData = [
                'module'    => self::class,
                'key'       => $this->keyBy,
                'threshold' => $this->threshold,
                'interval'  => $this->interval,
                'count'     => $count,
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
     * Build the key based on the configuration.
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
