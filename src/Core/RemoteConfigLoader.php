<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\RemoteRules\FileCache;
use Bespredel\Wafu\RemoteRules\HttpClient;
use Bespredel\Wafu\RemoteRules\RulesManager;

final class RemoteConfigLoader
{
    /**
     * Load remote configuration.
     *
     * @param array $local Local configuration
     *
     * @return array|null
     */
    public function load(array $local): ?array
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
        catch (\Throwable) {
            return null;
        }
    }
}
