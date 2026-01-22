<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Registry;

use ReflectionClass;
use RuntimeException;

abstract class AbstractRegistry
{
    /**
     * Cache for camelCase to snake_case conversions.
     *
     * @var array
     */
    protected static array $snakeCaseCache = [];

    /**
     * Cached ReflectionClass instances.
     *
     * @var array
     */
    protected static array $reflectionCache = [];

    /**
     * Cached constructor parameters.
     *
     * @var array
     */
    protected static array $ctorParamsCache = [];

    /**
     * Create an object from the config.
     *
     * Supports:
     *  - constructor injection by parameter names
     *  - snake_case config keys (retry_after) => camelCase (retryAfter)
     *
     * @param class-string $class
     * @param array        $config
     *
     * @return object
     *
     * @throws \ReflectionException
     */
    protected function instantiate(string $class, array $config): object
    {
        unset($config['class']); // the remaining keys are parameters

        $ref = self::$reflectionCache[$class] ??= new ReflectionClass($class);
        $ctor = $ref->getConstructor();

        // if there is no constructor - just new
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $params = self::$ctorParamsCache[$class] ??= $ctor->getParameters();
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

            throw new RuntimeException("Cannot instantiate '{$class}': missing config value for constructor param '{$name}'");
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
    protected static function toSnakeCase(string $name): string
    {
        if (isset(self::$snakeCaseCache[$name])) {
            return self::$snakeCaseCache[$name];
        }

        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
        self::$snakeCaseCache[$name] = $snake;

        return $snake;
    }
}
