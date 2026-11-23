<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests;

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Modules\RegexMatchModule;
use PHPUnit\Framework\TestCase;

final class PatternModuleTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel(__DIR__ . '/../src/config/wafu.php');
    }

    public function testTruncationBypassIsPrevented(): void
    {
        // 12000 harmless bytes followed by an attack
        $paddedAttack = str_repeat('A', 12000) . ' UNION SELECT 1,2,3 FROM users';

        $decision = $this->kernel->handle(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/search'],
            [],
            ['q' => $paddedAttack]
        );

        self::assertTrue($decision->isBlocked(), 'Attack after 12KB padding must be detected and blocked');
    }

    public function testAttacksInParameterKeysAreDetected(): void
    {
        // Attack payload is in the key, not the value
        $decision = $this->kernel->handle(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            ['UNION SELECT 1 FROM users' => 'value']
        );

        self::assertTrue($decision->isBlocked(), 'Attack inside query parameter key must be detected');
    }

    public function testLegitimateWordsDoNotTriggerFalsePositives(): void
    {
        // Common phrases containing "select", "update", "delete", "drop" should NOT be blocked
        $safeTexts = [
            'Please select an option from the list',
            'I want to update my profile information',
            'Delete my old comments please',
            'Price drop alert for subscribers',
            'Note: font-weight: bold; color: red;',
        ];

        foreach ($safeTexts as $text) {
            $decision = $this->kernel->handle(
                ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/comment'],
                [],
                ['comment' => $text]
            );

            self::assertFalse(
                $decision->isBlocked(),
                sprintf('Legitimate phrase "%s" should not be blocked', $text)
            );
        }
    }

    public function testCookiesWithSemicolonAndPipeDoNotTriggerRce(): void
    {
        $decision = $this->kernel->handle(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            [],
            [],
            ['session_token' => 'abc123xyz;expires=tomorrow;theme=dark|compact']
        );

        self::assertFalse($decision->isBlocked(), 'Legitimate cookie with ; and | should not be blocked as RCE');
    }
}
