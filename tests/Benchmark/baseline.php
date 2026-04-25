<?php

declare(strict_types=1);

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Modules\RateLimitModule;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$iterations = 200;
$kernel = new Kernel([
    'enabled'  => true,
    'mode'     => 'enforce',
    'pipeline' => ['rate_limit'],
    'actions'  => [
        'block' => [
            'class'     => BlockAction::class,
            'status'    => 429,
            'message'   => 'Too many requests',
            'terminate' => false,
        ],
    ],
    'patterns' => [],
    'modules'  => [
        'rate_limit' => [
            'class'          => RateLimitModule::class,
            'limit'          => 100000,
            'interval'       => 60,
            'on_exceed'      => 'block',
            'gc_probability' => 0,
        ],
    ],
]);

$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $kernel->handle(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/bench', 'REMOTE_ADDR' => '127.0.0.1']);
}
$elapsedNs = hrtime(true) - $start;
$perRequestMs = ($elapsedNs / 1_000_000) / $iterations;

echo json_encode([
        'iterations'         => $iterations,
        'avg_ms_per_request' => round($perRequestMs, 3),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
