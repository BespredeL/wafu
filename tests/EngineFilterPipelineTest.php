<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Tests\Unit\Core;

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Decision;
use Bespredel\Wafu\Core\Engine;
use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Modules\IpBlocklistModule;
use Bespredel\Wafu\Modules\RateLimitModule;
use Bespredel\Wafu\Modules\UriAllowDenyModule;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Engine::run() with module filter ($only parameter)
 * and Kernel::handle() / Kernel::handleWithContext() $only forwarding.
 */
final class EngineFilterPipelineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeKernel(): Kernel
    {
        return new Kernel([
            'enabled'                 => true,
            'mode'                    => Engine::MODE_ENFORCE,
            'trust_forwarded_headers' => false,
            'trusted_proxies'         => [],
            'pipeline'                => ['ip_blocklist', 'rate_limit', 'uri_allow_deny'],
            'actions'                 => [
                'block' => [
                    'class'     => BlockAction::class,
                    'status'    => 403,
                    'message'   => 'Blocked',
                    'terminate' => false,
                ],
            ],
            'patterns'                => [],
            'modules'                 => [
                'ip_blocklist' => [
                    'class'     => IpBlocklistModule::class,
                    'enabled'   => true,
                    'blocklist' => ['1.2.3.4'],
                    'on_match'  => 'block',
                ],
                'rate_limit'   => [
                    'class'        => RateLimitModule::class,
                    'enabled'      => true,
                    'max_requests' => 100,
                    'window'       => 60,
                    'storage'      => 'array',
                    'on_exceed'    => 'block',
                ],
                'uri_allow_deny' => [
                    'class'       => UriAllowDenyModule::class,
                    'enabled'     => true,
                    'deny_prefix' => ['/secret'],
                    'on_deny'     => 'block',
                ],
            ],
            'storage' => ['driver' => 'array'],
        ]);
    }

    private function server(string $ip = '8.8.8.8', string $uri = '/safe'): array
    {
        return [
            'REMOTE_ADDR'    => $ip,
            'REQUEST_URI'    => $uri,
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST'      => 'example.com',
            'SERVER_PORT'    => '80',
        ];
    }

    // -------------------------------------------------------------------------
    // Tests: empty $only -> full pipeline runs
    // -------------------------------------------------------------------------

    public function test_empty_only_runs_full_pipeline_blocked_ip(): void
    {
        $decision = $this->makeKernel()->handle($this->server('1.2.3.4'));
        $this->assertTrue($decision->isBlocked(), 'Blocked IP should be blocked when full pipeline runs');
    }

    public function test_empty_only_runs_full_pipeline_blocked_uri(): void
    {
        $decision = $this->makeKernel()->handle($this->server('9.9.9.9', '/secret/page'));
        $this->assertTrue($decision->isBlocked(), 'Blocked URI should be blocked when full pipeline runs');
    }

    public function test_empty_only_allows_clean_request(): void
    {
        $decision = $this->makeKernel()->handle($this->server('9.9.9.9', '/safe'));
        $this->assertFalse($decision->isBlocked(), 'Clean request should pass full pipeline');
    }

    // -------------------------------------------------------------------------
    // Tests: non-empty $only -> filtered pipeline
    // -------------------------------------------------------------------------

    public function test_only_ip_blocklist_blocks_bad_ip(): void
    {
        $decision = $this->makeKernel()->handle($this->server('1.2.3.4'), [], [], [], [], [], ['ip_blocklist']);
        $this->assertTrue($decision->isBlocked(), 'ip_blocklist should block bad IP');
    }

    public function test_only_ip_blocklist_ignores_blocked_uri(): void
    {
        // uri_allow_deny NOT in $only -> blocked URI should be allowed
        $decision = $this->makeKernel()->handle($this->server('9.9.9.9', '/secret'), [], [], [], [], [], ['ip_blocklist']);
        $this->assertFalse($decision->isBlocked(), 'uri_allow_deny should be skipped when not in $only');
    }

    public function test_only_uri_allow_deny_blocks_bad_uri(): void
    {
        $decision = $this->makeKernel()->handle($this->server('9.9.9.9', '/secret/admin'), [], [], [], [], [], ['uri_allow_deny']);
        $this->assertTrue($decision->isBlocked(), 'uri_allow_deny should block bad URI');
    }

    public function test_only_uri_allow_deny_ignores_blocked_ip(): void
    {
        // ip_blocklist NOT in $only -> blocked IP should be allowed
        $decision = $this->makeKernel()->handle($this->server('1.2.3.4', '/safe'), [], [], [], [], [], ['uri_allow_deny']);
        $this->assertFalse($decision->isBlocked(), 'ip_blocklist should be skipped when not in $only');
    }

    public function test_multiple_only_modules_first_match_wins(): void
    {
        // Both ip_blocklist and uri_allow_deny in $only; blocked IP triggers first
        $decision = $this->makeKernel()->handle($this->server('1.2.3.4', '/secret'), [], [], [], [], [], ['ip_blocklist', 'uri_allow_deny']);
        $this->assertTrue($decision->isBlocked());
    }

    public function test_unknown_alias_produces_empty_pipeline_allows(): void
    {
        // A completely unknown alias -> no matching modules -> allow
        $decision = $this->makeKernel()->handle($this->server('1.2.3.4'), [], [], [], [], [], ['nonexistent_module']);
        $this->assertFalse($decision->isBlocked(), 'Unknown alias should produce an empty pipeline -> allow');
    }

    // -------------------------------------------------------------------------
    // Tests: handleWithContext forwards $only
    // -------------------------------------------------------------------------

    public function test_handle_with_context_only_parameter(): void
    {
        $result = $this->makeKernel()->handleWithContext(
            $this->server('9.9.9.9', '/secret'),
            [], [], [], [],
            [],
            ['ip_blocklist']   // only ip_blocklist - uri_allow_deny must be skipped
        );

        $this->assertFalse($result['decision']->isBlocked(), 'uri_allow_deny should be skipped');
        $this->assertInstanceOf(Context::class, $result['context']);
    }

    // -------------------------------------------------------------------------
    // Tests: Engine::getPipeline()
    // -------------------------------------------------------------------------

    public function test_engine_get_pipeline_returns_configured_pipeline(): void
    {
        $pipeline = $this->makeKernel()->getEngine()->getPipeline();

        $this->assertContains('ip_blocklist',   $pipeline);
        $this->assertContains('rate_limit',     $pipeline);
        $this->assertContains('uri_allow_deny', $pipeline);
    }

    // -------------------------------------------------------------------------
    // Tests: snake_case alias matching for FQCN-based pipeline entries
    // -------------------------------------------------------------------------

    public function test_class_based_pipeline_matched_by_snake_case_alias(): void
    {
        // Pipeline with FQCNs instead of keys
        $kernel = new Kernel([
            'enabled'                 => true,
            'mode'                    => Engine::MODE_ENFORCE,
            'trust_forwarded_headers' => false,
            'trusted_proxies'         => [],
            'pipeline'                => [IpBlocklistModule::class],
            'actions'                 => [
                'block' => [
                    'class'     => BlockAction::class,
                    'status'    => 403,
                    'message'   => 'Blocked',
                    'terminate' => false,
                ],
            ],
            'patterns'                => [],
            'modules'                 => [
                IpBlocklistModule::class => [
                    'class'     => IpBlocklistModule::class,
                    'enabled'   => true,
                    'blocklist' => ['5.5.5.5'],
                    'on_match'  => 'block',
                ],
            ],
            'storage' => ['driver' => 'array'],
        ]);

        // alias "ip_blocklist" or "ip_blocklist_module" should match IpBlocklistModule::class
        $decision1 = $kernel->handle($this->server('5.5.5.5'), [], [], [], [], [], ['ip_blocklist_module']);
        $this->assertTrue($decision1->isBlocked(), 'snake_case with _module suffix should match');

        $decision2 = $kernel->handle($this->server('5.5.5.5'), [], [], [], [], [], ['ip_blocklist']);
        $this->assertTrue($decision2->isBlocked(), 'snake_case without _module suffix should also match');
    }
}
