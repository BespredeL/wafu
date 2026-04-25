<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use RuntimeException;

final class ConfigSchemaValidator
{
    /**
     * Validate the configuration schema.
     *
     * @param array $config Configuration array to validate
     *
     * @return void
     *
     * @throws RuntimeException
     */
    public static function validate(array $config): void
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

        $mode = (string)($config['mode'] ?? Engine::MODE_ENFORCE);
        if (!in_array($mode, [Engine::MODE_ENFORCE, Engine::MODE_REPORT], true)) {
            throw new RuntimeException('Config key "mode" must be "enforce" or "report"');
        }

        $remoteRules = $config['remote_rules'] ?? [];
        if (!is_array($remoteRules)) {
            throw new RuntimeException('Config key "remote_rules" must be an array');
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
