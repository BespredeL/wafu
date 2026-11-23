<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Modules\UriAllowDenyModule;
use PHPUnit\Framework\TestCase;

final class UriAllowDenyModuleTest extends TestCase
{
    private UriAllowDenyModule $module;

    protected function setUp(): void
    {
        $this->module = new UriAllowDenyModule(
            ['/^\/api/'],
            ['/\.env(\.|$)/i'],
            ['/public'],
            ['/.env', '/.git', '/vendor'],
            new BlockAction(403, 'URI denied', false)
        );
    }

    public function testDeniesDirectAndNormalizedDenyPrefix(): void
    {
        $direct = new Context(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/.env']);
        self::assertTrue($this->module->handle($direct)?->isBlocked() ?? false);

        // Multiple slashes
        $multipleSlashes = new Context(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '//.env']);
        self::assertTrue($this->module->handle($multipleSlashes)?->isBlocked() ?? false);

        // URL encoded
        $urlEncoded = new Context(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/%2e%65%6e%76']);
        self::assertTrue($this->module->handle($urlEncoded)?->isBlocked() ?? false);
    }

    public function testAllowsMatchingAllowPrefix(): void
    {
        $allowed = new Context(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/public/style.css']);
        self::assertNull($this->module->handle($allowed));
    }
}
