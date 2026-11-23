<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Core\Context;
use PHPUnit\Framework\TestCase;

final class IpResolutionTest extends TestCase
{
    public function testSpoofedXffIsIgnoredWhenBehindTrustedProxy(): void
    {
        // 10.0.0.1 is the trusted reverse proxy
        // 198.51.100.5 is the real client IP appended by proxy
        // 203.0.113.99 is the attacker's fake spoofed header
        $context = new Context(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '10.0.0.1'],
            [],
            [],
            [],
            ['x-forwarded-for' => '203.0.113.99, 198.51.100.5'],
            ['10.0.0.0/8'],
            true
        );

        self::assertSame('198.51.100.5', $context->getIp());
    }

    public function testUntrustedProxyDoesNotTrustXff(): void
    {
        $context = new Context(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '192.0.2.1'],
            [],
            [],
            [],
            ['x-forwarded-for' => '198.51.100.5'],
            ['10.0.0.0/8'],
            true // trust enabled, but REMOTE_ADDR is not in trustedProxies
        );

        self::assertSame('192.0.2.1', $context->getIp());
    }
}
