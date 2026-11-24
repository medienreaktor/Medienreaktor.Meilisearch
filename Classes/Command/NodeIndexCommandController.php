<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Domain\Service\MeilisearchIndex;
use Medienreaktor\Meilisearch\Exception;
use Medienreaktor\Meilisearch\Indexer\CatchUp\IndexCatchUpOutcome;
use Medienreaktor\Meilisearch\Indexer\CatchUp\IndexCatchUpService;
use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Indexer\WorkspaceIndexer;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\SubgraphCachingInMemory\SubgraphCachePool;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * CLI commands for index building and flushing
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeIndexer
     */
    protected $nodeIndexer;
    /**
     * @Flow\Inject
     * @var WorkspaceIndexer
     */
    protected $workspaceIndexer;

    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;


    /**
     * Long enough for a full rebuild of a large site to drain, short enough that a
     * wedged queue is reported rather than waited on until the scheduler kills the
     * command.
     */
    private const DEFAULT_WAIT_TIMEOUT_IN_SECONDS = 1800;

    /**
     * @var integer
     */
    protected $indexedNodes = 0;

    #[Inject]
    protected PersistenceManagerInterface $persistenceManager;
    #[Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Inject]
    protected SubgraphCachePool $subgraphCachePool;


    #[Inject]
    protected IndexCatchUpService $indexCatchUpService;

    /**
     * Index what changed in live since the last run
     *
     * Reads the content repository's events after the stored position and reindexes the
     * documents they touch. Meant for cron, every few minutes, next to the nightly
     * nodeindex:rebuild. The first run only records the current position.
     *
     * @param int $batchSize Read at most this many events per run; the rest waits for the next run
     * @param string $contentRepository Content repository id
     * @return void
     */
    public function catchUpCommand(int $batchSize = 10000, string $contentRepository = 'default'): void
    {
        $result = $this->indexCatchUpService->catchUp(ContentRepositoryId::fromString($contentRepository), $batchSize);

        match ($result->outcome) {
            IndexCatchUpOutcome::AlreadyRunning => $this->outputLine('Another catch-up is running, nothing done.'),
            IndexCatchUpOutcome::Initialized => $this->outputLine('No position stored yet. Starting after event %d; nothing indexed.', [$result->to?->value]),
            IndexCatchUpOutcome::Reset => $this->outputLine(
                '<error>The event store no longer holds event %d the index was caught up to; it was replaced (a restored backup or a mirror). Starting after event %d; nothing indexed. Run nodeindex:rebuild to bring the index in line.</error>',
                [$result->from?->value, $result->to?->value]
            ),
            IndexCatchUpOutcome::CaughtUp => $this->outputLine(
                'Events %d to %d: %d read, %d document(s) reindexed or removed.%s',
                [$result->from?->value, $result->to?->value, $result->eventCount, $result->documentCount, $result->moreEventsPending ? ' More events pending.' : '']
            ),
        };
    }

    /**
     * Create the index and apply its settings
     *
     * Embedder changes that would re-embed an index which already has documents are
     * held back and reported; a new apiKey is applied either way.
     *
     * @param bool $allowReembedding Apply embedder changes even if Meilisearch has to re-embed every document for them
     * @return void
     */
    public function createIndexCommand(bool $allowReembedding = false): void
    {
        $this->createIndexWithSettings($allowReembedding);
        $this->outputLine('Index created successfully.');
    }

    /**
     * Delete the index
     *
     * Removes the index itself, settings included, rather than only its documents
     * (see nodeindex:flush). Run nodeindex:createindex or nodeindex:build afterwards.
     *
     * @return void
     */
    public function deleteIndexCommand(): void
    {
        $this->indexClient->deleteIndex();
        $this->outputLine('The index has been deleted.');
    }

    /**
     * Index all nodes in-place into the live index.
     *
     * The index is incomplete while this runs; nodeindex:rebuild avoids that.
     *
     * @param int|null $limit Only index up to this many nodes per dimension (for quick testing)
     * @param bool $wait Return only once Meilisearch has finished indexing, and fail if it did not succeed
     * @param int $timeout How long to wait, in seconds; only meaningful together with --wait
     * @param bool $assumeEmptyIndex Skip deletions, which cannot match anything on an index the caller has just emptied
     * @param bool $allowReembedding Apply embedder changes even if Meilisearch has to re-embed every document for them
     * @return void
     * @throws Exception
     */
    public function buildCommand(?int $limit = null, bool $wait = false, int $timeout = self::DEFAULT_WAIT_TIMEOUT_IN_SECONDS, bool $assumeEmptyIndex = false, bool $allowReembedding = false): void
    {
        $startTime = microtime(true);
        $this->createIndexWithSettings($allowReembedding);

        if ($assumeEmptyIndex) {
            $this->nodeIndexer->assumeEmptyIndex();
        }

        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());

        // Taken before indexing, so that what changes while this runs is caught up later.
        $startPosition = $this->indexCatchUpService->head($contentRepositoryId);
        $this->output->progressStart($this->progressMax($contentRepositoryId, $workspace->workspaceName, $limit));
        $this->indexedNodes = $this->workspaceIndexer->index(
            $contentRepositoryId,
            $workspace->workspaceName,
            limit: $limit,
            singleCallback: $this->progressCallback(null)
        );
        $this->nodeIndexer->flush();
        $this->output->progressFinish();
        if ($limit === null) {
            $this->indexCatchUpService->recordPosition($contentRepositoryId, $startPosition);
        }

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes in ' . round(microtime(true) - $startTime, 1) . 's.');

        if ($wait) {
            $this->outputLine('Waiting up to %d seconds for Meilisearch to finish indexing...', [$timeout]);
            // The indexer's own client is the one holding the enqueued tasks.
            $this->nodeIndexer->getIndexClient()->waitForPendingTasks($timeout);
            $this->outputLine('Index is up to date.');
        }
    }

    /**
     * Zero-downtime reindex: build into a fresh temporary index, then atomically
     * swap it live. The current index keeps serving complete results the whole
     * time — no gap, no half-built state. On error the temp index is discarded
     * and the live index stays untouched.
     *
     * Documents written by other indexers into the same index (assets, PDFs) are
     * copied over before the swap — see the "indexing.preserveOnRebuild" setting.
     *
     * @param int|null $limit Only index up to this many nodes per dimension. This builds a partial index, which is never swapped live — for testing the build itself.
     * @param bool $skipRemoval Skip the per-node delete (safe: the build index is fresh/empty). Disable to benchmark its cost.
     * @return void
     * @throws Exception
     */
    public function rebuildCommand(?int $limit = null, bool $skipRemoval = true): void
    {
        $startTime = microtime(true);
        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());

        $buildIndexName = $this->indexClient->createBuildIndex();
        // Taken before indexing, so that what changes while this runs is caught up later.
        $startPosition = $this->indexCatchUpService->head($contentRepositoryId);
        $this->output->progressStart($this->progressMax($contentRepositoryId, $workspace->workspaceName, $limit));
        try {
            $this->indexedNodes = $this->workspaceIndexer->index(
                $contentRepositoryId,
                $workspace->workspaceName,
                limit: $limit,
                singleCallback: $this->progressCallback($buildIndexName),
                skipRemoval: $skipRemoval,
                targetIndexName: $buildIndexName
            );
            $this->output->progressFinish();
            $this->outputLine('');

            if ($limit !== null) {
                // A capped run holds a fraction of the documents. Swapping it would
                // do exactly what this command exists to prevent: leave the live
                // index short of most of its content.
                $this->indexClient->deleteBuildIndex($buildIndexName);
                $this->outputLine('<comment>--limit was set: built %d nodes into a partial index and discarded it. The live index is untouched — run without --limit to actually rebuild.</comment>', [$this->indexedNodes]);
                return;
            }

            foreach ($this->indexClient->preserveDocuments($buildIndexName) as $label => $preserved) {
                $this->outputLine('Carried over %d "%s" documents from the live index.', [$preserved, $label]);
            }
            $this->indexClient->swapBuildIndex($buildIndexName);
            $this->indexCatchUpService->recordPosition($contentRepositoryId, $startPosition);
        } catch (\Throwable $e) {
            $this->nodeIndexer->discardBuffer();
            $this->indexClient->deleteBuildIndex($buildIndexName);
            throw $e;
        }

        $this->outputLine('Finished zero-downtime rebuild — indexed ' . $this->indexedNodes . ' nodes and swapped the index in ' . round(microtime(true) - $startTime, 1) . 's.');
    }

    /**
     * Delete all documents from the index.
     */
    public function flushCommand(): void
    {
        $this->indexClient->deleteAllDocuments();
        $this->outputLine('All documents flushed from the index.');
    }

    /**
     * Advances the progress bar per node and releases memory once per write batch: the
     * buffered documents are sent, then Doctrine's identity map is dropped and the
     * cycle collector runs. Entities loaded while extracting properties (assets, sites)
     * would otherwise stay for the whole run. Safe here because the commands only read
     * nodes and write to Meilisearch - nothing detached is ever persisted again.
     */
    /**
     * Only MeilisearchIndex knows about re-embedding, so the flag and the report are
     * skipped for any other implementation bound to IndexInterface.
     */
    protected function createIndexWithSettings(bool $allowReembedding): void
    {
        if ($allowReembedding && $this->indexClient instanceof MeilisearchIndex) {
            $this->indexClient->allowReembedding();
        }

        $this->indexClient->createIndex();

        if ($this->indexClient instanceof MeilisearchIndex) {
            foreach ($this->indexClient->getSkippedEmbedderChanges() as $skipped) {
                $this->outputLine('<comment>Held back: %s Pass --allow-reembedding to apply it.</comment>', [$skipped]);
            }
        }
    }

    protected function progressCallback(?string $targetIndexName): \Closure
    {
        $releaseEvery = $this->nodeIndexer->batchSize();
        $sinceRelease = 0;

        return function () use ($targetIndexName, $releaseEvery, &$sinceRelease): void {
            $this->output->progressAdvance();
            if (++$sinceRelease < $releaseEvery) {
                return;
            }
            $sinceRelease = 0;
            $this->nodeIndexer->flush($targetIndexName);
            $this->persistenceManager->clearState();
            // Every node the indexer read through subgraphForNode() - ancestors,
            // parents, content - stays in the content repository's subgraph cache for
            // the life of the process otherwise.
            $this->subgraphCachePool->reset(false);
            gc_collect_cycles();
        };
    }

    /**
     * Number of progress steps to expect. The indexer visits every node once per
     * dimension, and $limit caps the count per dimension — so the total advances
     * are $limit times the number of dimensions.
     */
    protected function progressMax(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, ?int $limit): int
    {
        if ($limit === null) {
            return $this->workspaceIndexer->count($contentRepositoryId, $workspaceName);
        }
        $dimensionSpacePoints = $this->contentRepositoryRegistry->get($contentRepositoryId)->getVariationGraph()->getDimensionSpacePoints();
        return $limit * max(1, $dimensionSpacePoints->count());
    }

    /**
     * Whether the node type of the aggregate is configured as a fulltext root.
     */
    protected function isFulltextRoot(NodeAggregate $node): bool
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return false;
        }
        $search = $nodeType->getConfiguration('search');

        return is_array($search) && ($search['fulltext']['isRoot'] ?? false) === true;
    }
}
