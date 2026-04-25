<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Modules\Support\SlidingWindowCounter;
use Bespredel\Wafu\Storage\FileStorage;
use PHPUnit\Framework\TestCase;

final class SlidingWindowCounterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wafu-test-' . uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testHitReturnsCountAndThresholdStatus(): void
    {
        $counter = new SlidingWindowCounter(new FileStorage($this->dir, 0));

        $r1 = $counter->hit('k', 2, 60);
        $r2 = $counter->hit('k', 2, 60);
        $r3 = $counter->hit('k', 2, 60);

        self::assertSame(1, $r1['count']);
        self::assertFalse($r2['exceeded']);
        self::assertTrue($r3['exceeded']);
    }
}
