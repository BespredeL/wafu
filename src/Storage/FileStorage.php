<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Storage;

use RuntimeException;

/**
 * File-based storage for rate limiting and tracking.
 * Follows SRP by separating storage concerns from business logic.
 */
final class FileStorage
{
    /**
     * @param string $storageDir
     * @param int    $gcProbability 1/N requests triggers GC (e.g. 100 => 1%)
     * @param int    $ttlMultiplier ttl = interval * ttlMultiplier
     */
    public function __construct(
        private readonly string $storageDir,
        private readonly int    $gcProbability = 100,
        private readonly int    $ttlMultiplier = 5
    )
    {
        $this->ensureDirectoryExists();
    }

    /**
     * Read data from storage file.
     *
     * @param string $key
     *
     * @return array{t: array<int>, ls: int}|null
     */
    public function read(string $key): ?array
    {
        $file = $this->getFilePath($key);

        $fp = @fopen($file, 'cb+');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH | LOCK_NB)) {
                fclose($fp);
                return null;
            }

            $raw = stream_get_contents($fp);
            if ($raw === false || $raw === '') {
                return null;
            }

            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['t']) || !is_array($data['t'])) {
                return null;
            }

            return [
                't'  => array_map('intval', $data['t']),
                'ls' => (int)($data['ls'] ?? 0),
            ];
        }
        finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Write data to storage file with locking.
     *
     * @param string $key
     * @param array  $data
     *
     * @return bool
     */
    public function write(string $key, array $data): bool
    {
        $file = $this->getFilePath($key);
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $fp = @fopen($file, 'cb+');
        if ($fp === false) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return false;
            }

            $this->rewrite($fp, $data);

            return true;
        }
        finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Cleanup expired entries.
     *
     * @param int $interval
     *
     * @return void
     */
    public function cleanup(int $interval): void
    {
        if ($this->gcProbability <= 0 || random_int(1, $this->gcProbability) !== 1) {
            return;
        }

        $cutoff = time() - ($interval * max(1, $this->ttlMultiplier));

        foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }

            $data = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['ls']) && (int)$data['ls'] < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Get file path for key.
     *
     * @param string $key
     *
     * @return string
     */
    private function getFilePath(string $key): string
    {
        return $this->storageDir
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.json';
    }

    /**
     * Ensure storage directory exists and is writable.
     *
     * @return void
     *
     * @throws RuntimeException
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
                throw new RuntimeException('Storage directory cannot be created: ' . $this->storageDir);
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new RuntimeException('Storage directory is not writable: ' . $this->storageDir);
        }
    }

    /**
     * Rewrite file content.
     *
     * @param resource $fp
     * @param array    $payload
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
}
