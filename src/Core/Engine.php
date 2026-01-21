<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Registry\ModuleRegistry;

final class Engine
{
    public const MODE_ENFORCE = 'enforce';
    public const MODE_REPORT  = 'report';

    /**
     * Module registry.
     *
     * @var ModuleRegistry
     */
    private ModuleRegistry $moduleRegistry;

    /**
     * Pipeline of modules to be executed.
     *
     * @var array
     */
    private array $pipeline;

    /**
     * Mode of operation.
     *
     * @var string
     */
    private string $mode;

    /**
     * @param ModuleRegistry $moduleRegistry
     * @param array          $pipeline
     * @param string         $mode
     */
    public function __construct(
        ModuleRegistry $moduleRegistry,
        array          $pipeline,
        string         $mode = self::MODE_ENFORCE
    )
    {
        $this->moduleRegistry = $moduleRegistry;
        $this->pipeline = $pipeline;
        $this->mode = $mode;
    }

    /**
     * Run WAFU engine.
     *
     * @param Context $context
     *
     * @return Decision
     *
     * @throws \ReflectionException
     */
    public function run(Context $context): Decision
    {
        foreach ($this->pipeline as $moduleName) {
            $module = $this->moduleRegistry->get($moduleName);

            if (!$module instanceof ModuleInterface) {
                continue;
            }

            $decision = $module->handle($context);

            if ($decision === null) {
                continue;
            }

            if ($decision->isBlocked()) {
                // Execute action if present
                if ($decision->getAction() !== null) {
                    $decision->getAction()->execute($context);
                }

                // report-only: does not block, but retains auditing
                if ($this->mode === self::MODE_REPORT) {
                    $context->setAttribute('wafu.report_only', true);
                    $context->setAttribute('wafu.report_decision', [
                        'reason'  => $decision->getReason(),
                        'status'  => $decision->getStatus(),
                        'headers' => $decision->getHeaders(),
                        'meta'    => $decision->getMeta(),
                    ]);

                    return Decision::allow();
                }

                return $decision;
            }

            // Execute action for non-blocking decisions
            if ($decision->getAction() !== null) {
                $decision->getAction()->execute($context);
            }
        }

        return Decision::allow();
    }
}