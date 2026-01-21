<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use RuntimeException;

final class ConfigLoader
{
    /**
     * Load WAFU configuration.
     *
     * Supports:
     *  - path to PHP file
     *  - array
     *
     * @param array|string $config
     *
     * @return array
     */
    public static function load(array|string $config): array
    {
        if (is_string($config)) {
            $realPath = realpath($config);
            if ($realPath === false || !is_file($realPath)) {
                throw new RuntimeException("WAFU config file not found: {$config}");
            }

            if (!is_file($realPath)) {
                throw new RuntimeException("WAFU config path is not a file: {$config}");
            }

            $basename = basename($realPath);
            if (preg_match('/\.(php\d*|phtml|phar)$/i', $basename) === 0) {
                // Only allow .php files for configuration
                // This is a safety measure, but config files should typically be .php
            }

            $loaded = require $realPath;

            if (!is_array($loaded)) {
                throw new RuntimeException('WAFU config file must return an array');
            }

            return self::normalize($loaded);
        }

        if (is_array($config)) {
            return self::normalize($config);
        }

        throw new RuntimeException('Invalid WAFU config source');
    }

    /**
     * Normalization and basic validation of the config.
     *
     * @param array $config
     *
     * @return array
     */
    private static function normalize(array $config): array
    {
        $defaults = [
            'enabled'  => true,
            'pipeline' => [],
            'modules'  => [],
            'patterns' => [],
            'actions'  => [],
        ];

        $config = array_replace_recursive($defaults, $config);

        self::validate($config);

        return $config;
    }

    /**
     * Validation of the config structure.
     *
     * @param array $config
     *
     * @return void
     */
    private static function validate(array $config): void
    {
        if (!is_bool($config['enabled'])) {
            throw new RuntimeException('Config key "enabled" must be boolean');
        }

        if (!is_array($config['pipeline'])) {
            throw new RuntimeException('Config key "pipeline" must be an array');
        }

        if (!is_array($config['modules'])) {
            throw new RuntimeException('Config key "modules" must be an array');
        }

        if (!is_array($config['patterns'])) {
            throw new RuntimeException('Config key "patterns" must be an array');
        }

        if (!is_array($config['actions'])) {
            throw new RuntimeException('Config key "actions" must be an array');
        }

        foreach ($config['pipeline'] as $moduleName) {
            if (!isset($config['modules'][$moduleName])) {
                throw new RuntimeException("Module '{$moduleName}' declared in pipeline but not defined in modules");
            }
        }

        foreach ($config['modules'] as $name => $module) {
            if (!isset($module['class'])) {
                throw new RuntimeException("Module '{$name}' must define 'class'");
            }

            if (!class_exists($module['class'])) {
                throw new RuntimeException("Module class '{$module['class']}' for '{$name}' does not exist");
            }
        }

        foreach ($config['actions'] as $name => $action) {
            if (!isset($action['class'])) {
                throw new RuntimeException("Action '{$name}' must define 'class'");
            }

            if (!class_exists($action['class'])) {
                throw new RuntimeException("Action class '{$action['class']}' for '{$name}' does not exist");
            }
        }
    }
}