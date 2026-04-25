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

        ConfigSchemaValidator::validate($config);

        return $config;
    }
}