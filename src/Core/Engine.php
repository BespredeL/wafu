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
     * @var ModuleRegistry
     */
    private ModuleRegistry $moduleRegistry;

    /**
     * @var array
     */
    private array $pipeline;

    /**
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

            if ($decision->getAction() !== null) {
                $decision->getAction()->execute($context);
            }

            if ($decision->isBlocked()) {
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
        }

        return Decision::allow();
    }
}