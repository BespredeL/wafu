<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Engine;
use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Modules\RegexMatchModule;
use PHPUnit\Framework\TestCase;

final class KernelIntegrationTest extends TestCase
{
    public function testKernelBlocksOnRegexMatch(): void
    {
        $kernel = new Kernel([
            'enabled' => true,
            'mode' => Engine::MODE_ENFORCE,
            'pipeline' => ['sql_injection'],
            'actions' => [
                'block' => [
                    'class' => BlockAction::class,
                    'status' => 403,
                    'message' => 'Blocked',
                    'terminate' => false,
                ],
            ],
            'patterns' => [
                'sql_keywords' => ['/\bUNION\b/i'],
            ],
            'modules' => [
                'sql_injection' => [
                    'class' => RegexMatchModule::class,
                    'targets' => ['query'],
                    'patterns' => ['sql_keywords'],
                    'on_match' => 'block',
                    'reason' => 'SQL injection detected',
                ],
            ],
        ]);

        $decision = $kernel->handle(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            ['q' => 'UNION SELECT 1']
        );

        self::assertTrue($decision->isBlocked());
        self::assertSame('SQL injection detected', $decision->getReason());
    }
}
