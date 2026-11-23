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
     * Last cleanup at timestamp.
     *
     * @var int
     */
    private int $lastCleanupAt = 0;

    /**
     * Constructor.
     *
     * @param string $storageDir
     * @param int    $gcProbability    1/N requests triggers GC (e.g. 100 => 1%)
     * @param int    $ttlMultiplier    ttl = interval * ttlMultiplier
     * @param int    $cleanupCooldown  Cleanup cooldown in seconds
     * @param int    $cleanupBatchSize Cleanup batch size
     */
    public function __construct(
        private string $storageDir,
        private int    $gcProbability = 100,
        private int    $ttlMultiplier = 5,
        private int    $cleanupCooldown = 15,
        private int    $cleanupBatchSize = 50
    )
    {
        $this->ensureDirectoryExists();
    }

    /**
     * Read data from storage file.
     *
     * @param string $key The key to read
     *
     * @return array|null
     */
    public function read(string $key): ?array
    {
        $file = $this->getFilePath($key);

        $fp = @fopen($file, 'cb+');
        if ($fp === false) {
            return null;
        }

        $locked = false;
        try {
            if (!flock($fp, LOCK_SH | LOCK_NB)) {
                return null;
            }
            $locked = true;

            $raw = stream_get_contents($fp);
            if ($raw === false || $raw === '') {
                return null;
            }

            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return null;
            }
            return $data;
        }
        finally {
            if ($locked && is_resource($fp)) {
                @flock($fp, LOCK_UN);
            }
            if (is_resource($fp)) {
                @fclose($fp);
            }
        }
    }

    /**
     * Write data to storage file with locking.
     *
     * @param string $key  The key to write
     * @param array  $data The data to write
     *
     * @return bool True if the data was written successfully, false otherwise
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

        $locked = false;
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $locked = true;

            $this->rewrite($fp, $data);

            return true;
        }
        finally {
            if ($locked && is_resource($fp)) {
                @flock($fp, LOCK_UN);
            }
            if (is_resource($fp)) {
                @fclose($fp);
            }
        }
    }

    /**
     * Cleanup expired entries.
     *
     * @param int $interval The interval to cleanup
     *
     * @return void
     */
    public function cleanup(int $interval): void
    {
        if (!$this->shouldRunCleanup()) {
            return;
        }

        $this->lastCleanupAt = time();
        $cutoff = time() - ($interval * max(1, $this->ttlMultiplier));
        $processed = 0;

        $handle = @opendir($this->storageDir);
        if ($handle === false) {
            return;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($processed >= $this->cleanupBatchSize) {
                    break;
                }

                if (!str_ends_with($entry, '.json')) {
                    continue;
                }

                $file = $this->storageDir . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($file)) {
                    continue;
                }

                $raw = @file_get_contents($file);
                if ($raw !== false && $raw !== '') {
                    $data = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($data['ls']) && (int)$data['ls'] < $cutoff) {
                        @unlink($file);
                    }
                } else {
                    @unlink($file);
                }
                $processed++;
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * Get file path for key.
     *
     * @param string $key The key to get the file path for
     *
     * @return string
     */
    private function getFilePath(string $key): string
    {
        $hashed = hash('sha256', $key);

        // Strict hexadecimal format check guarantees no directory traversal characters
        if (!preg_match('/\A[a-f0-9]{64}\z/', $hashed)) {
            throw new RuntimeException('Invalid key hash generated');
        }

        return $this->storageDir . DIRECTORY_SEPARATOR . $hashed . '.json';
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
        $realPath = realpath($this->storageDir);
        if ($realPath === false) {
            if (!is_dir($this->storageDir)) {
                if (!mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
                    throw new RuntimeException('Storage directory cannot be created: ' . $this->storageDir);
                }
                $realPath = realpath($this->storageDir);
                if ($realPath === false) {
                    throw new RuntimeException('Storage directory created but cannot be resolved: ' . $this->storageDir);
                }
            } else {
                throw new RuntimeException('Storage directory exists but cannot be resolved: ' . $this->storageDir);
            }
        }

        if (!is_dir($realPath)) {
            throw new RuntimeException('Storage path is not a directory: ' . $this->storageDir);
        }

        if (!is_writable($realPath)) {
            throw new RuntimeException('Storage directory is not writable: ' . $this->storageDir);
        }

        $this->storageDir = $realPath;
    }

    /**
     * Rewrite file content.
     *
     * @param resource $fp      The file pointer
     * @param array    $payload The data to write
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
     * Check if cleanup should run.
     *
     * @return bool True if cleanup should run, false otherwise
     */
    private function shouldRunCleanup(): bool
    {
        if ($this->gcProbability <= 0 || random_int(1, $this->gcProbability) !== 1) {
            return false;
        }

        $now = time();
        if ($this->lastCleanupAt > 0 && ($now - $this->lastCleanupAt) < $this->cleanupCooldown) {
            return false;
        }

        $this->lastCleanupAt = $now;
        return true;
    }
}
