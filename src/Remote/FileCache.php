<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Remote;

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
     * @return string
     */
    public function path(): string
    {
        return rtrim($this->dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->fileName;
    }

    /**
     * @return bool
     */
    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
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
     * @param array $payload
     *
     * @return void
     */
    public function write(array $payload): void
    {
        if (!is_dir($this->dir)) {
            if (!mkdir($concurrentDirectory = $this->dir, 0775, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }

        $path = $this->path();

        $tmp = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        @file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, $path);
    }

    /**
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