<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Helpers\ModuleHelperTrait;

final class RateLimitModule implements ModuleInterface
{
    use ModuleHelperTrait;
    /**
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
        $this->storageDir = $storageDir ?: (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wafu-rl');
    }

    /**
     * @param Context $context
     *
     * @return Decision|null
     */
    public function handle(Context $context): ?Decision
    {
        if ($this->onExceed === null) {
            return null;
        }

        if ($this->limit <= 0 || $this->interval <= 0) {
            return null;
        }

        $this->maybeGc();

        $key = $this->buildKey($context);
        $file = $this->filePath($key);
        $now = time();
        $windowStart = $now - $this->interval;

        if (!is_dir($this->storageDir)) {
            if (!mkdir($concurrentDirectory = $this->storageDir, 0775, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }

        $fp = @fopen($file, 'cb+');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return null;
            }

            $raw = stream_get_contents($fp);
            $data = $raw ? json_decode($raw, true) : null;

            $timestamps = [];
            $lastSeen = 0;

            if (is_array($data)) {
                $lastSeen = isset($data['ls']) ? (int)$data['ls'] : 0;
                if (isset($data['t']) && is_array($data['t'])) {
                    foreach ($data['t'] as $ts) {
                        $ts = (int)$ts;
                        if ($ts >= $windowStart) {
                            $timestamps[] = $ts;
                        }
                    }
                }
            }

            $timestamps[] = $now;

            // cap memory: keep only last (limit + 20) timestamps
            $cap = max($this->limit + 20, 50);
            if (count($timestamps) > $cap) {
                $timestamps = array_slice($timestamps, -$cap);
            }

            $exceeded = (count($timestamps) > $this->limit);

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

                return $this->createDecision($context, $this->onExceed, $this->reason, $matchData);
            }
        }
        finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }

        return null;
    }

    /**
     * @return void
     */
    private function maybeGc(): void
    {
        if ($this->gcProbability <= 0) {
            return;
        }

        if (random_int(1, $this->gcProbability) !== 1) {
            return;
        }

        $ttl = $this->interval * max(1, $this->ttlMultiplier);
        $cutoff = time() - $ttl;

        if (!is_dir($this->storageDir)) {
            return;
        }

        // Limit the number of files processed per GC run to avoid blocking
        $maxFilesPerGc = 100;
        $pattern = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json';
        $files = @glob($pattern) ?: [];

        // Limit processing to avoid long GC runs
        if (count($files) > $maxFilesPerGc) {
            // Shuffle to ensure we don't always process the same files
            shuffle($files);
            $files = array_slice($files, 0, $maxFilesPerGc);
        }

        $processed = 0;
        foreach ($files as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            // Check file modification time first (faster than reading content)
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            // Only read file if mtime suggests it's expired
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                @unlink($file);
                continue;
            }

            $data = json_decode($raw, true);
            $ls = is_array($data) && isset($data['ls']) ? (int)$data['ls'] : 0;

            if ($ls > 0 && $ls < $cutoff) {
                @unlink($file);
            }

            $processed++;
        }
    }

    /**
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
     * @param Context $context
     *
     * @return string
     */
    private function buildKey(Context $context): string
    {
        return match ($this->keyBy) {
            'ip+uri' => $context->getIp() . '|' . $context->getUri(),
            default => $context->getIp(),
        };
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function filePath(string $key): string
    {
        $hash = hash('sha256', $key);
        return rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.json';
    }
}