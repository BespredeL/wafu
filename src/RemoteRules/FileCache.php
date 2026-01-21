<?php

declare(strict_types=1);

namespace Bespredel\Wafu\RemoteRules;

final class FileCache
{
    /**
     * @param string $dir
     * @param string $fileName
     */
    public function __construct(
        private string $dir,
        private string $fileName = 'ruleset.json'
    )
    {
    }

    /**
     * Get cache file path.
     *
     * @return string
     */
    public function path(): string
    {
        $dir = rtrim($this->dir, DIRECTORY_SEPARATOR);
        $fileName = basename($this->fileName); // Remove any directory components

        if (preg_match('/[\/\\\\]/', $fileName) !== 0) {
            throw new \RuntimeException('Invalid cache filename: path traversal detected');
        }

        $realDir = realpath($dir);
        if ($realDir === false) {
            throw new \RuntimeException("Cache directory does not exist: {$dir}");
        }

        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        $realPath = realpath(dirname($path));
        if ($realPath === false || $realPath !== $realDir) {
            throw new \RuntimeException('Cache file path validation failed: path traversal detected');
        }

        return $path;
    }

    /**
     * Check if cache file exists.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Read cache file.
     *
     * @return array|null
     */
    public function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Write cache file.
     *
     * @param array $payload
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function write(array $payload): void
    {
        $realDir = realpath($this->dir);
        if ($realDir === false) {
            if (!is_dir($this->dir)) {
                if (!mkdir($concurrentDirectory = $this->dir, 0750, true) && !is_dir($concurrentDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }

                $realDir = realpath($this->dir);
                if ($realDir === false) {
                    throw new \RuntimeException('Failed to resolve cache directory path');
                }
            } else {
                throw new \RuntimeException('Cache directory exists but cannot be resolved');
            }
        }

        $path = $this->path();

        $realPath = realpath(dirname($path));
        if ($realPath === false || $realPath !== $realDir) {
            throw new \RuntimeException('Cache file path validation failed: path traversal detected');
        }

        $tmp = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode cache payload to JSON');
        }

        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to write cache file');
        }

        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to rename cache file');
        }

        @chmod($path, 0640);
    }

    /**
     * Check if cache is fresh.
     *
     * @param int $now
     * @param int $fetchedAt
     * @param int $ttl
     *
     * @return bool
     */
    public function isFresh(int $now, int $fetchedAt, int $ttl): bool
    {
        if ($ttl <= 0) {
            return false;
        }

        return ($now - $fetchedAt) < $ttl;
    }
}