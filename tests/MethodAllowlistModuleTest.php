<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Modules\MethodAllowlistModule;
use PHPUnit\Framework\TestCase;

final class MethodAllowlistModuleTest extends TestCase
{
    public function testConstructorNormalizesAllowMethods(): void
    {
        $module = new MethodAllowlistModule(
            ['get', 'Post'],
            new BlockAction(403, 'blocked', false)
        );

        $denied = new Context(['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/']);
        self::assertNotNull($module->handle($denied));

        $allowed = new Context(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        self::assertNull($module->handle($allowed));
    }
}
