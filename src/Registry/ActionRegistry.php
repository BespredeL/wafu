<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Registry;

use Bespredel\Wafu\Contracts\ActionInterface;
use RuntimeException;

/**
 * Action registry.
 * Extends AbstractRegistry to follow DRY principle.
 */
final class ActionRegistry extends AbstractRegistry
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

        $config = $this->actionsConfig[$name];

        if (empty($config['class']) || !is_string($config['class'])) {
            throw new RuntimeException("Action '{$name}' must define valid 'class'");
        }

        $class = $config['class'];

        if (!class_exists($class)) {
            throw new RuntimeException("Action class '{$class}' for '{$name}' does not exist");
        }

        $instance = $this->instantiate($class, $config);

        if (!$instance instanceof ActionInterface) {
            throw new RuntimeException("Action '{$name}' class '{$class}' must implement ActionInterface");
        }

        return $this->instances[$name] = $instance;
    }

}