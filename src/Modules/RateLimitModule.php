<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Helpers\ModuleHelperTrait;
use Random\RandomException;

final class RateLimitModule implements ModuleInterface
{
    use ModuleHelperTrait;

    /**
     * Storage directory for rate limit data.
     *
     * @var string
     */
    private string $storageDir;

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
        $this->storageDir = $storageDir
            ?? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wafu-rl';

        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
                throw new \RuntimeException('RateLimit storage directory cannot be created');
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException('RateLimit storage directory is not writable');
        }
    }

    /**
     * Handle request.
     *
     * @param Context $context
     *
     * @return Decision|null
     *
     * @throws RandomException
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onExceed === null || $this->limit <= 0 || $this->interval <= 0) {
            return null;
        }

        $this->cleanupExpiredRateLimitEntries();

        $key = $this->buildKey($context);
        $file = $this->filePath($key);

        $now = time();
        $windowStart = $now - $this->interval;
        $timestamps = [];

        $fp = fopen($file, 'cb+');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return null;
            }

            $raw = stream_get_contents($fp);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['t']) && is_array($data['t'])) {
                    foreach ($data['t'] as $ts) {
                        $ts = (int)$ts;
                        if ($ts >= $windowStart) {
                            $timestamps[] = $ts;
                        }
                    }
                }
            }

            $timestamps[] = $now;

            $cap = max($this->limit + 20, 50);
            if (count($timestamps) > $cap) {
                $timestamps = array_slice($timestamps, -$cap);
            }

            $exceeded = count($timestamps) > $this->limit;

            $this->rewrite($fp, [
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
        }
        finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return null;
    }

    /**
     * Maybe perform garbage collection.
     *
     * @return void
     *
     * @throws RandomException
     */
    private function cleanupExpiredRateLimitEntries(): void
    {
        if ($this->gcProbability <= 0 || random_int(1, $this->gcProbability) !== 1) {
            return;
        }

        $cutoff = time() - ($this->interval * max(1, $this->ttlMultiplier));

        foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['ls']) && (int)$data['ls'] < $cutoff) {
                unlink($file);
            }
        }
    }

    /**
     * Rewrite file content.
     *
     * @param       $fp
     * @param array $payload
     *
     * @return void
     */
    private function rewrite($fp, array $payload): void
    {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
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

    /**
     * Build file path based on key.
     *
     * @param string $key
     *
     * @return string
     */
    private function filePath(string $key): string
    {
        return $this->storageDir
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.json';
    }
}