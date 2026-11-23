<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\Registry\ActionRegistry;
use Bespredel\Wafu\Registry\ModuleRegistry;
use Bespredel\Wafu\Registry\PatternRegistry;

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
     * Constructor.
     * 
     * @param array|string $configPath Path to configuration file or array
     *
     * @throws \ReflectionException
     * @throws RuntimeException
     */
    public function __construct(array|string $configPath)
    {
        $local = ConfigLoader::load($configPath);
        $remote = (new RemoteConfigLoader())->load($local);
        $boot = (new KernelBootstrapper())->bootstrap($local, $remote);

        $this->config = $boot['config'];
        $this->actionRegistry = $boot['actionRegistry'];
        $this->patternRegistry = $boot['patternRegistry'];
        $this->moduleRegistry = $boot['moduleRegistry'];
        $this->engine = $boot['engine'];
    }

    /**
     * Handle request.
     *
     * @param array    $server
     * @param array    $query
     * @param array    $body
     * @param array    $cookies
     * @param array    $headers
     * @param array    $attributes Initial context attributes (e.g. logger)
     * @param string[] $only       If non-empty, run only these module names/aliases.
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
        array $headers = [],
        array $attributes = [],
        array $only = []
    ): Decision
    {
        return $this->handleWithContext($server, $query, $body, $cookies, $headers, $attributes, $only)['decision'];
    }

    /**
     * Handle request with context.
     *
     * @param array    $server
     * @param array    $query
     * @param array    $body
     * @param array    $cookies
     * @param array    $headers
     * @param array    $attributes Initial context attributes (e.g. logger)
     * @param string[] $only       If non-empty, run only these module names/aliases.
     *
     * @return array{decision: Decision, context: Context}
     *
     * @throws \ReflectionException
     */
    public function handleWithContext(
        array $server,
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $headers = [],
        array $attributes = [],
        array $only = []
    ): array
    {
        if (!($this->config['enabled'] ?? true)) {
            return [
                'decision' => Decision::allow(),
                'context'  => new Context($server, $query, $body, $cookies, $headers, [], false, $attributes),
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
            $trustForwarded,
            $attributes
        );

        $decision = $this->engine->run($context, $only);

        return ['decision' => $decision, 'context' => $context];
    }

    /**
     * Record a response code into context and re-evaluate modules (e.g. for NotFoundAbuse).
     *
     * @param Context  $context
     * @param int      $statusCode
     * @param string[] $only       If non-empty, run only these module names/aliases.
     *
     * @return Decision
     *
     * @throws \ReflectionException
     */
    public function handleResponse(Context $context, int $statusCode, array $only = []): Decision
    {
        $context->setAttribute(ContextKeys::HTTP_STATUS_CODE, $statusCode);

        if (!($this->config['enabled'] ?? true)) {
            return Decision::allow();
        }

        return $this->engine->run($context, $only);
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

}