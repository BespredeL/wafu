<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\ContextKeys;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Modules\Support\SlidingWindowCounter;
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
     * Sliding window counter instance.
     *
     * @var SlidingWindowCounter
     */
    private SlidingWindowCounter $counter;

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
        private int              $threshold = 10,
        private int              $interval = 60,
        private ?ActionInterface $onExceed = null,
        private string           $keyBy = 'ip',
        private string           $reason = 'Excessive 404 detected',
        ?string                  $storageDir = null,
        private int              $gcProbability = 100,
        private int              $ttlMultiplier = 5
    )
    {
        $storageDir = $storageDir ?? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wafu-404';

        $this->storage = new FileStorage($storageDir, $this->gcProbability, $this->ttlMultiplier);
        $this->counter = new SlidingWindowCounter($this->storage);
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
        $statusCode = $context->getAttribute(ContextKeys::HTTP_STATUS_CODE, 200);
        if ($statusCode !== 404) {
            return null;
        }

        $this->storage->cleanup($this->interval);

        $key = $this->buildKey($context);
        $result = $this->counter->hit($key, $this->threshold, $this->interval);
        $count = $result['count'];

        if ($result['exceeded']) {
            $matchData = [
                'module'    => self::class,
                'key'       => $this->keyBy,
                'threshold' => $this->threshold,
                'interval'  => $this->interval,
                'count'     => $count,
            ];

            $context->setAttribute(ContextKeys::MATCH, $matchData);

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
