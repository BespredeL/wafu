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
     * Get the configured pipeline.
     *
     * @return array
     */
    public function getPipeline(): array
    {
        return $this->pipeline;
    }

    /**
     * Run WAFU engine with optional module filter.
     *
     * When $only is non-empty, only modules whose names are listed (or whose
     * short aliases match) are executed. Order follows the configured pipeline.
     *
     * @param Context  $context
     * @param string[] $only    If non-empty, restrict execution to these module names/aliases.
     *
     * @return Decision
     *
     * @throws \ReflectionException
     */
    public function run(Context $context, array $only = []): Decision
    {
        $pipeline = $only !== [] ? $this->filterPipeline($only) : $this->pipeline;

        foreach ($pipeline as $moduleName) {
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

    /**
     * Filter the configured pipeline to only include the specified modules.
     *
     * Accepts both fully-qualified class names and short snake_case aliases
     * (e.g. "ip_blocklist" matches any pipeline entry whose class basename,
     * when converted to snake_case, equals the alias).
     *
     * @param string[] $only
     *
     * @return string[]
     */
    private function filterPipeline(array $only): array
    {
        $normalised = array_map('strtolower', $only);

        return array_values(array_filter(
            $this->pipeline,
            static function (string $entry) use ($normalised): bool {
                $lowerEntry = strtolower($entry);
                if (in_array($lowerEntry, $normalised, true)) {
                    return true;
                }

                // Short alias match: convert class basename to snake_case
                $basename  = strpos($entry, '\\') !== false
                    ? substr($entry, strrpos($entry, '\\') + 1)
                    : $entry;
                $snakeCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
                if (in_array($snakeCase, $normalised, true)) {
                    return true;
                }

                // Strip trailing "_module" (e.g. "ip_blocklist_module" => "ip_blocklist")
                $short = (string) preg_replace('/_module$/', '', $snakeCase);
                if (in_array($short, $normalised, true)) {
                    return true;
                }

                // Also check if user passed "ip_blocklist_module" and entry in pipeline is "ip_blocklist"
                $entryShort = (string) preg_replace('/_module$/', '', $lowerEntry);
                foreach ($normalised as $target) {
                    $targetShort = (string) preg_replace('/_module$/', '', $target);
                    if ($entryShort === $targetShort) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }
}
