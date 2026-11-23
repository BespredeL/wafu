<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Modules\NotFoundAbuseModule;
use PHPUnit\Framework\TestCase;

final class NotFoundAbuseModuleTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wafu-404-test-' . uniqid('', true);
        mkdir($this->storageDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storageDir);
    }

    public function testNotFoundAbuseDetectsAndBlocksExcessive404s(): void
    {
        $kernel = new Kernel([
            'enabled' => true,
            'mode' => 'enforce',
            'pipeline' => ['not_found_abuse'],
            'actions' => [
                'block' => [
                    'class' => BlockAction::class,
                    'status' => 403,
                    'message' => 'Blocked for scanning',
                    'terminate' => false,
                ],
            ],
            'patterns' => [],
            'modules' => [
                'not_found_abuse' => [
                    'class' => NotFoundAbuseModule::class,
                    'threshold' => 3,
                    'interval' => 60,
                    'on_exceed' => 'block',
                    'storage_dir' => $this->storageDir,
                    'gc_probability' => 0,
                ],
            ],
        ]);

        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/missing-page', 'REMOTE_ADDR' => '192.0.2.55'];

        $h1 = $kernel->handleWithContext($server);
        $d1 = $kernel->handleResponse($h1['context'], 404);
        self::assertFalse($d1->isBlocked());

        $h2 = $kernel->handleWithContext($server);
        $d2 = $kernel->handleResponse($h2['context'], 404);
        self::assertFalse($d2->isBlocked());

        $h3 = $kernel->handleWithContext($server);
        $d3 = $kernel->handleResponse($h3['context'], 404);
        self::assertFalse($d3->isBlocked());

        // 4th 404 request exceeds threshold of 3
        $h4 = $kernel->handleWithContext($server);
        $d4 = $kernel->handleResponse($h4['context'], 404);
        self::assertTrue($d4->isBlocked());
    }
}
