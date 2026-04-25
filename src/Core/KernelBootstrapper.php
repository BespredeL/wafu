<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\Registry\ActionRegistry;
use Bespredel\Wafu\Registry\ModuleRegistry;
use Bespredel\Wafu\Registry\PatternRegistry;

final class KernelBootstrapper
{
    /**
     * Bootstrap the kernel.
     *
     * @param array $localConfig Local configuration
     * @param array|null $remoteConfig Remote configuration
     *
     * @return array
     */
    public function bootstrap(array $localConfig, ?array $remoteConfig): array
    {
        $config = $this->mergeConfigs($localConfig, $remoteConfig);

        $actionRegistry = new ActionRegistry($config['actions'] ?? []);
        $patternRegistry = new PatternRegistry($config['patterns'] ?? []);
        $moduleRegistry = new ModuleRegistry($config['modules'] ?? [], $actionRegistry, $patternRegistry);

        $engine = new Engine(
            $moduleRegistry,
            $config['pipeline'] ?? [],
            $this->resolveMode($config)
        );

        return [
            'config'          => $config,
            'actionRegistry'  => $actionRegistry,
            'patternRegistry' => $patternRegistry,
            'moduleRegistry'  => $moduleRegistry,
            'engine'          => $engine,
        ];
    }

    /**
     * Resolve the mode of operation.
     *
     * @param array $config Configuration array
     *
     * @return string
     */
    private function resolveMode(array $config): string
    {
        $mode = (string)($config['mode'] ?? Engine::MODE_ENFORCE);
        if (!in_array($mode, [Engine::MODE_ENFORCE, Engine::MODE_REPORT], true)) {
            return Engine::MODE_ENFORCE;
        }

        return $mode;
    }

    /**
     * Merge the configurations.
     *
     * @param array $local Local configuration
     * @param array|null $remote Remote configuration
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
        $localRemoteRules = $local['remote_rules'] ?? null;

        $merged = $strategy === 'local_wins'
            ? array_replace_recursive($remote, $local)
            : array_replace_recursive($local, $remote);

        if ($localRemoteRules !== null) {
            $merged['remote_rules'] = $localRemoteRules;
        }

        return $merged;
    }
}
