<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Core\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testResolveIpUsesForwardedHeadersForTrustedProxy(): void
    {
        $context = new Context(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '10.0.0.10'],
            [],
            [],
            [],
            ['x-forwarded-for' => '203.0.113.20, 10.0.0.10'],
            ['10.0.0.0/8'],
            true
        );

        self::assertSame('203.0.113.20', $context->getIp());
    }

    public function testFlattenedPayloadCollectsNestedValues(): void
    {
        $context = new Context(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x'],
            ['a' => ['b' => '1']],
            ['payload' => ['v' => '2']],
            ['cookie' => '3']
        );

        self::assertSame(['1', '2', '3'], $context->getFlattenedPayload());
    }
}
