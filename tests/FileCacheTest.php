<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\RemoteRules\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    public function testNonExistentDirectoryDoesNotThrowException(): void
    {
        $nonExistentDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wafu-non-existent-' . uniqid('', true);

        $cache = new FileCache($nonExistentDir, 'ruleset.json');

        self::assertFalse($cache->exists());
        self::assertNull($cache->read());
    }

    public function testWriteAndReadFreshCache(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wafu-cache-test-' . uniqid('', true);
        $cache = new FileCache($dir, 'ruleset.json');

        $data = ['version' => '1.0', '_fetched_at' => time(), '_ttl' => 300];
        $cache->write($data);

        self::assertTrue($cache->exists());
        self::assertSame($data, $cache->read());
        self::assertTrue($cache->isFresh(time(), time(), 300));
        self::assertFalse($cache->isFresh(time() + 400, time(), 300));

        @unlink($cache->path());
        @rmdir($dir);
    }
}
