<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Storage\FileStorage;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wafu-storage-test-' . uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testReadAndWriteCycle(): void
    {
        $storage = new FileStorage($this->dir, 0);

        self::assertTrue($storage->write('user_123', ['count' => 5, 'ls' => time()]));
        $read = $storage->read('user_123');

        self::assertIsArray($read);
        self::assertSame(5, $read['count']);
    }

    public function testNonExistentKeyReturnsNull(): void
    {
        $storage = new FileStorage($this->dir, 0);
        self::assertNull($storage->read('non_existent_key_xyz'));
    }

    public function testConcurrentLockHandlingDoesNotThrowFatalError(): void
    {
        $storage = new FileStorage($this->dir, 0);
        $key = 'concurrent_test';

        $storage->write($key, ['count' => 1, 'ls' => time()]);

        // Manually lock the file exclusively in another handle
        $reflection = new \ReflectionClass($storage);
        $getFilePath = $reflection->getMethod('getFilePath');
        $filePath = $getFilePath->invoke($storage, $key);

        $fp = fopen($filePath, 'cb+');
        self::assertNotFalse($fp);
        flock($fp, LOCK_EX);

        try {
            // read and write should return null/false without throwing fatal TypeError
            self::assertNull($storage->read($key));
            self::assertFalse($storage->write($key, ['count' => 2, 'ls' => time()]));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function testCleanupRemovesExpiredEntries(): void
    {
        $storage = new FileStorage($this->dir, 1, 1, 0, 100);

        $oldTime = time() - 3600;
        $storage->write('old_key', ['b' => [1 => 1], 'ls' => $oldTime]);
        $storage->write('new_key', ['b' => [1 => 1], 'ls' => time()]);

        $storage->cleanup(60);

        self::assertNull($storage->read('old_key'));
        self::assertNotNull($storage->read('new_key'));
    }
}
