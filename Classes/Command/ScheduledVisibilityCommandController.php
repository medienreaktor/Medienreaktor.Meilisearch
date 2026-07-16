<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Domain\Service\ScheduledVisibilityReconciliationService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * CLI command for opt-in scheduled visibility reconciliation.
 *
 * @Flow\Scope("singleton")
 */
class ScheduledVisibilityCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ScheduledVisibilityReconciliationService
     */
    protected $scheduledVisibilityReconciliationService;

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch", path="scheduledVisibilityReconciliation")
     * @var array{enabled?: bool, lookbackSeconds?: int}
     */
    protected $scheduledVisibilityReconciliationSettings = [];

    /**
     * Reconcile scheduled visibility changes since the last index snapshot.
     *
     * This command is disabled by default. Enable
     * `Medienreaktor.Meilisearch.scheduledVisibilityReconciliation.enabled`
     * before scheduling it. Installations which decorate NodeIndexerInterface,
     * such as CodeQ.Meilisearch.QueueIndexer, will enqueue the resulting index
     * and removal operations automatically.
     *
     * @param string|null $since Inclusive lower boundary understood by DateTimeImmutable;
     * defaults to the configured lookback
     * @param string|null $until Inclusive upper boundary understood by DateTimeImmutable; defaults to now
     * @param bool $all Inspect every node with a scheduled visibility boundary (initial enablement and outage recovery)
     * @param bool $dryRun Plan and count operations without passing them to the indexer
     * @return void
     */
    public function reconcileCommand(
        ?string $since = null,
        ?string $until = null,
        bool $all = false,
        bool $dryRun = false
    ): void {
        if (($this->scheduledVisibilityReconciliationSettings['enabled'] ?? false) !== true) {
            $this->outputLine(
                '<comment>Scheduled visibility reconciliation is disabled. '
                . 'Set Medienreaktor.Meilisearch.scheduledVisibilityReconciliation.enabled to true to opt in.</comment>'
            );
            return;
        }

        try {
            $untilDateTime = $until !== null ? new \DateTimeImmutable($until) : new \DateTimeImmutable();
            $lookbackSeconds = max(
                1,
                (int)($this->scheduledVisibilityReconciliationSettings['lookbackSeconds'] ?? 3600)
            );
            $sinceDateTime = $since !== null
                ? new \DateTimeImmutable($since)
                : $untilDateTime->modify(sprintf('-%d seconds', $lookbackSeconds));

            $result = $this->scheduledVisibilityReconciliationService->reconcile(
                $sinceDateTime,
                $untilDateTime,
                $all,
                $dryRun
            );
        } catch (\Exception $exception) {
            $this->outputLine(
                '<error>Scheduled visibility reconciliation failed: %s</error>',
                [$exception->getMessage()]
            );
            $this->quit(1);
        }

        $this->outputLine(
            '%sInspected %d scheduled node%s; planned %d index and %d removal operation%s.',
            [
                $dryRun ? 'Dry run: ' : '',
                $result['scheduledNodes'],
                $result['scheduledNodes'] === 1 ? '' : 's',
                $result['indexOperations'],
                $result['removeOperations'],
                ($result['indexOperations'] + $result['removeOperations']) === 1 ? '' : 's',
            ]
        );
    }
}
