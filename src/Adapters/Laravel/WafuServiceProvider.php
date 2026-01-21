<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Adapters\Laravel;

use Bespredel\Wafu\Core\Kernel;
use Illuminate\Support\ServiceProvider;

final class WafuServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        // Merging the default package config with the application config
        $this->mergeConfigFrom(__DIR__ . '/../../config/wafu.php', 'wafu');

        // Kernel as singleton to reuse registries/pipeline
        $this->app->singleton(Kernel::class, function ($app) {
            /** @var array $cfg */
            $cfg = $app['config']->get('wafu', []);
            return new Kernel($cfg);
        });
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publishing the config to config/wafu.php
        $this->publishes([
            __DIR__ . '/../../config/wafu.php' => config_path('wafu.php'),
        ], 'wafu-config');
    }
}