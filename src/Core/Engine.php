<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\Contracts\ModuleInterface;
use Bespredel\Wafu\Registry\ModuleRegistry;

final class Engine
{
    /**
     * Mode of operation: enforce (block) or report (audit only).
     */
    public const MODE_ENFORCE = 'enforce';

    /**
     * Mode of operation: report (audit only).
     */
    public const MODE_REPORT = 'report';

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
                $action = $decision->getAction();
                if ($action !== null) {
                    $action->execute($context);
                }

                // report-only: does not block, but retains auditing
                if ($this->mode === self::MODE_REPORT) {
                    $context->setAttribute(ContextKeys::REPORT_ONLY, true);
                    $context->setAttribute(ContextKeys::REPORT_DECISION, [
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
            $action = $decision->getAction();
            if ($action !== null) {
                $action->execute($context);
            }
        }

        return Decision::allow();
    }
}