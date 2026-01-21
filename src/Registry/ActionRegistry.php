<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Registry;

use Bespredel\Wafu\Contracts\ActionInterface;
use RuntimeException;
use ReflectionClass;

final class ActionRegistry
{
    /**
     * Actions config.
     *
     * @var array
     */
    private array $actionsConfig;

    /**
     * Cached Action instances.
     *
     * @var array
     */
    private array $instances = [];

    /**
     * Cache for camelCase to snake_case conversions.
     *
     * @var array
     */
    private static array $snakeCaseCache = [];

    /**
     * @param array $actionsConfig
     */
    public function __construct(array $actionsConfig)
    {
        $this->actionsConfig = $actionsConfig;
    }

    /**
     * Get Action by name from the config.
     *
     * @param string $name
     *
     * @return ActionInterface
     *
     * @throws \ReflectionException
     */
    public function get(string $name): ActionInterface
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->actionsConfig[$name])) {
            throw new RuntimeException("Action '{$name}' is not defined in config");
        }

        $cfg = $this->actionsConfig[$name];

        if (empty($cfg['class']) || !is_string($cfg['class'])) {
            throw new RuntimeException("Action '{$name}' must define valid 'class'");
        }

        $class = $cfg['class'];

        if (!class_exists($class)) {
            throw new RuntimeException("Action class '{$class}' for '{$name}' does not exist");
        }

        $instance = $this->instantiate($class, $cfg);

        if (!$instance instanceof ActionInterface) {
            throw new RuntimeException("Action '{$name}' class '{$class}' must implement ActionInterface");
        }

        return $this->instances[$name] = $instance;
    }

    /**
     * Create an Action object from the config.
     *
     * Supports:
     *  - constructor injection by parameter names
     *  - snake_case config keys (retry_after) => camelCase (retryAfter)
     *
     * @param class-string $class
     * @param array        $cfg
     *
     * @return object
     *
     * @throws \ReflectionException
     */
    private function instantiate(string $class, array $cfg): object
    {
        unset($cfg['class']); // the remaining keys are parameters

        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();

        // if there is no constructor - just new
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $params = $ctor->getParameters();
        $args = [];

        foreach ($params as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $cfg)) {
                $args[] = $cfg[$name];
                continue;
            }

            // Use cached snake_case conversion
            $snake = self::toSnakeCase($name);
            if (array_key_exists($snake, $cfg)) {
                $args[] = $cfg[$snake];
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