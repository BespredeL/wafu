<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\Registry\ActionRegistry;
use Bespredel\Wafu\Registry\ModuleRegistry;
use Bespredel\Wafu\Registry\PatternRegistry;
use Bespredel\Wafu\RemoteRules\FileCache;
use Bespredel\Wafu\RemoteRules\HttpClient;
use Bespredel\Wafu\RemoteRules\RulesManager;

final class Kernel
{
    /**
     * Configuration.
     *
     * @var array
     */
    private array $config;

    /**
     * Action registry.
     *
     * @var ActionRegistry
     */
    private ActionRegistry $actionRegistry;

    /**
     * Module registry.
     *
     * @var ModuleRegistry
     */
    private ModuleRegistry $moduleRegistry;

    /**
     * Pattern registry.
     *
     * @var PatternRegistry
     */
    private PatternRegistry $patternRegistry;

    /**
     * Engine.
     *
     * @var Engine
     */
    private Engine $engine;

    /**
     * @param array|string $configPath
     */
    public function __construct(array|string $configPath)
    {
        // 1) local config
        $local = ConfigLoader::load($configPath);

        // 2) remote rules (optional)
        $remoteCfg = $this->loadRemoteConfigIfEnabled($local);

        // 3) merge
        $this->config = $this->mergeConfigs($local, $remoteCfg);

        // 4) registries + engine
        $this->actionRegistry = new ActionRegistry($this->config['actions'] ?? []);
        $this->patternRegistry = new PatternRegistry($this->config['patterns'] ?? []);

        $this->moduleRegistry = new ModuleRegistry(
            $this->config['modules'] ?? [],
            $this->actionRegistry,
            $this->patternRegistry
        );

        $mode = (string)($this->config['mode'] ?? Engine::MODE_ENFORCE);
        if (!in_array($mode, [Engine::MODE_ENFORCE, Engine::MODE_REPORT], true)) {
            $mode = Engine::MODE_ENFORCE;
        }

        $this->engine = new Engine(
            $this->moduleRegistry,
            $this->config['pipeline'] ?? [],
            $mode
        );
    }

    /**
     * Handle request.
     *
     * @param array $server
     * @param array $query
     * @param array $body
     * @param array $cookies
     * @param array $headers
     *
     * @return Decision
     *
     * @throws \ReflectionException
     */
    public function handle(
        array $server,
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $headers = []
    ): Decision
    {
        return $this->handleWithContext($server, $query, $body, $cookies, $headers)['decision'];
    }

    /**
     * Handle request with context.
     *
     * @param array $server
     * @param array $query
     * @param array $body
     * @param array $cookies
     * @param array $headers
     *
     * @return array
     *
     * @throws \ReflectionException
     */
    public function handleWithContext(
        array $server,
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $headers = []
    ): array
    {
        if (!($this->config['enabled'] ?? true)) {
            return [
                'decision' => Decision::allow(),
                'context'  => new Context($server, $query, $body, $cookies, $headers),
            ];
        }

        $trustedProxies = $this->config['trusted_proxies'] ?? [];
        $trustForwarded = (bool)($this->config['trust_forwarded_headers'] ?? false);

        $context = new Context(
            $server,
            $query,
            $body,
            $cookies,
            $headers,
            is_array($trustedProxies) ? $trustedProxies : [],
            $trustForwarded
        );

        $decision = $this->engine->run($context);

        return ['decision' => $decision, 'context' => $context];
    }

    /**
     * Get configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get engine.
     *
     * @return Engine
     */
    public function getEngine(): Engine
    {
        return $this->engine;
    }

    /**
     * Load remote config if enabled.
     *
     * @param array $local
     *
     * @return array|null
     */
    private function loadRemoteConfigIfEnabled(array $local): ?array
    {
        $rr = (array)($local['remote_rules'] ?? []);
        if (!($rr['enabled'] ?? false)) {
            return null;
        }

        $cacheDir = (string)($rr['cache_dir'] ?? (sys_get_temp_dir() . '/wafu-cache'));
        $cacheFile = (string)($rr['cache_file'] ?? 'ruleset.json');

        $manager = new RulesManager(
            new HttpClient(),
            new FileCache($cacheDir, $cacheFile),
            $rr
        );

        try {
            return $manager->fetchConfig();
        }
        catch (\Throwable $e) {
            // By default, if an error occurs, we continue with the local config.
            return null;
        }
    }

    /**
     * Merge local and remote configs.
     *
     * @param array      $local
     * @param array|null $remote
     *
     * @return array
     */
    private function mergeConfigs(array $local, ?array $remote): array
    {
        if ($remote === null) {
            return $local;
        }

        $rr = (array)($local['remote_rules'] ?? []);
        $strategy = (string)($rr['merge_strategy'] ?? 'remote_wins');

        // Important: Always leave remote_rules from the local config (client management)
        $localRemoteRules = $local['remote_rules'] ?? null;

        if ($strategy === 'local_wins') {
            $merged = array_replace_recursive($remote, $local);
        } else {
            $merged = array_replace_recursive($local, $remote);
        }

        if ($localRemoteRules !== null) {
            $merged['remote_rules'] = $localRemoteRules;
        }

        return $merged;
    }
}