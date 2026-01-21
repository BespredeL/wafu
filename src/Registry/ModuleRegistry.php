<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Registry;

use Bespredel\Wafu\Contracts\ModuleInterface;
use RuntimeException;
use ReflectionClass;

final class ModuleRegistry
{
    /**
     * Modules config.
     *
     * @var array
     */
    private array $modulesConfig;

    /**
     * Action registry.
     *
     * @var ActionRegistry
     */
    private ActionRegistry $actionRegistry;

    /**
     * Pattern registry.
     *
     * @var PatternRegistry
     */
    private PatternRegistry $patternRegistry;

    /**
     * Cached module instances.
     *
     * @var array
     */
    private array $instances = [];

    /**
     * Cache for camelCase to snake_case conversions
     *
     * @var array
     */
    private static array $snakeCaseCache = [];

    /**
     * @param array                $modulesConfig
     * @param ActionRegistry       $actionRegistry
     * @param PatternRegistry|null $patternRegistry
     */
    public function __construct(
        array            $modulesConfig,
        ActionRegistry   $actionRegistry,
        ?PatternRegistry $patternRegistry = null
    )
    {
        $this->modulesConfig = $modulesConfig;
        $this->actionRegistry = $actionRegistry;
        $this->patternRegistry = $patternRegistry ?? new PatternRegistry([]);
    }

    /**
     * Get the module by name from the config.
     *
     * @param string $name
     *
     * @return ModuleInterface
     *
     * @throws \ReflectionException
     */
    public function get(string $name): ModuleInterface
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->modulesConfig[$name])) {
            throw new RuntimeException("Module '{$name}' is not defined in config");
        }

        $cfg = $this->modulesConfig[$name];

        if (empty($cfg['class']) || !is_string($cfg['class'])) {
            throw new RuntimeException("Module '{$name}' must define valid 'class'");
        }

        $class = $cfg['class'];

        if (!class_exists($class)) {
            throw new RuntimeException("Module class '{$class}' for '{$name}' does not exist");
        }

        $instance = $this->instantiate($class, $cfg);

        if (!$instance instanceof ModuleInterface) {
            throw new RuntimeException("Module '{$name}' class '{$class}' must implement ModuleInterface");
        }

        return $this->instances[$name] = $instance;
    }

    /**
     * Create a module from the config.
     *
     * Supported Features:
     *  - constructor injection by parameter names
     *  - snake_case keys (on_match) => camelCase (onMatch)
     *  - auto-resolve action aliases into real ones ActionInterface:
     *      on_match, on_exceed, on_deny, action, actions
     *  - resolve pattern sets:
     *      patterns: ['sql_keywords', 'xss_basic'] => will be expanded into real regex
     *      patterns: ['/regex/i', ...]             => remains as is
     *
     * @param class-string $class
     * @param array        $config
     *
     * @return object
     *
     * @throws \ReflectionException
     */
    private function instantiate(string $class, array $config): object
    {
        unset($config['class']);

        // 1) actions
        $config = $this->hydrateActions($config);

        // 2) patterns
        $config = $this->hydratePatterns($config);

        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if ($ctor === null) {
            return $ref->newInstance();
        }

        $params = $ctor->getParameters();
        $args = [];

        foreach ($params as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $config)) {
                $args[] = $config[$name];
                continue;
            }

            // Use cached snake_case conversion
            $snake = self::toSnakeCase($name);
            if (array_key_exists($snake, $config)) {
                $args[] = $config[$snake];
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot instantiate '{$class}': missing config value for constructor param '{$name}'"
            );
        }

        return $ref->newInstanceArgs($args);
    }

    /**
     * Convert action aliases from the config to real ActionInterface.
     *
     * Keys we support:
     *  - on_match: "block"
     *  - on_exceed: "block"
     *  - on_deny: "block"
     *  - action: "log"
     *  - actions: ["log", "block"]
     *
     * @param array $config
     *
     * @return array
     *
     * @throws \ReflectionException
     */
    private function hydrateActions(array $config): array
    {
        $singleKeys = ['on_match', 'on_exceed', 'on_deny', 'action'];
        foreach ($singleKeys as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = $this->actionRegistry->get($config[$key]);
            }
        }

        if (isset($config['actions']) && is_array($config['actions'])) {
            $resolved = [];
            foreach ($config['actions'] as $alias) {
                if (is_string($alias)) {
                    $resolved[] = $this->actionRegistry->get($alias);
                }
            }

            $config['actions'] = $resolved;
        }

        return $config;
    }

    /**
     * Expands patterns if pattern set names are specified.
     *
     * Supports:
     *  - patterns: ['sql_keywords', 'xss_basic'] -> will be expanded into a list of regex
     *  - patterns: ['/foo/i', '/bar/i'] -> remains as is
     *
     * Rule of determination:
     *  - if the element does NOT match the regex (does not start with '/' or '#'),
     *    We assume that this is the name of a set in PatternRegistry.
     *
     * @param array $config
     *
     * @return array
     */
    private function hydratePatterns(array $config): array
    {
        if (!isset($config['patterns']) || !is_array($config['patterns'])) {
            return $config;
        }

        $expanded = [];

        foreach ($config['patterns'] as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }

            // Looks like regex? (supports /.../ and #...#)
            $first = $item[0] ?? '';
            $looksLikeRegex = ($first === '/' || $first === '#');

            if ($looksLikeRegex) {
                $expanded[] = $item;
                continue;
            }

            // Otherwise it is the name of the set
            $set = $this->patternRegistry->get($item);
            foreach ($set as $rx) {
                $expanded[] = $rx;
            }
        }

        $config['patterns'] = $expanded;

        return $config;
    }

    /**
     * Convert camelCase to snake_case with caching.
     *
     * @param string $name
     *
     * @return string
     */
    private static function toSnakeCase(string $name): string
    {
        if (isset(self::$snakeCaseCache[$name])) {
            return self::$snakeCaseCache[$name];
        }

        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
        self::$snakeCaseCache[$name] = $snake;

        return $snake;
    }
}