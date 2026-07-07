<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Medienreaktor\Meilisearch\Indexer\WorkspaceIndexer;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Cli\CommandController;
use Ramsey\Uuid\Exception\NodeException;

/**
 * CLI commands for index building and flushing
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController {
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
     * @var integer
     */
    protected $indexedNodes = 0;
    #[Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;


    public function createIndexCommand(): void {
        $this->indexClient->createIndex();
        $this->outputLine('Created and update settings of index');
    }

    /**
     * Index all nodes in-place into the live index.
     *
     * @param int|null $limit Only index up to this many nodes per dimension (for quick testing)
     * @return void
     * @throws Exception
     */
    public function buildCommand(?int $limit = null): void {
        $startTime = microtime(true);
        $this->indexClient->createIndex();

        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());

        $this->output->progressStart($this->progressMax($contentRepositoryId, $workspace->workspaceName, $limit));
        $this->indexedNodes = $this->workspaceIndexer->index($contentRepositoryId, $workspace->workspaceName, limit: $limit, singleCallback: fn() => $this->output->progressAdvance());
        $this->output->progressFinish();

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes in ' . round(microtime(true) - $startTime, 1) . 's.');
    }

    /**
     * Zero-downtime reindex: build into a fresh temporary index, then atomically
     * swap it live. The current index keeps serving complete results the whole
     * time — no gap, no half-built state. On error the temp index is discarded
     * and the live index stays untouched.
     *
     * @param int|null $limit Only index up to this many nodes per dimension (testing — produces a PARTIAL live index)
     * @param bool $skipRemoval Skip the per-node delete (safe: the build index is fresh/empty). Disable to benchmark its cost.
     * @return void
     * @throws Exception
     */
    public function rebuildCommand(?int $limit = null, bool $skipRemoval = true): void {
        $startTime = microtime(true);
        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());

        $buildIndexName = $this->indexClient->createBuildIndex();
        $this->output->progressStart($this->progressMax($contentRepositoryId, $workspace->workspaceName, $limit));
        try {
            $this->indexedNodes = $this->workspaceIndexer->index(
                $contentRepositoryId,
                $workspace->workspaceName,
                limit: $limit,
                singleCallback: fn() => $this->output->progressAdvance(),
                skipRemoval: $skipRemoval,
                targetIndexName: $buildIndexName
            );
            $this->output->progressFinish();
            $this->indexClient->swapBuildIndex($buildIndexName);
        } catch (\Throwable $e) {
            $this->indexClient->deleteBuildIndex($buildIndexName);
            throw $e;
        }

        $this->outputLine('');
        if ($limit !== null) {
            $this->outputLine('<comment>--limit was set: the live index now holds only a PARTIAL set. Run without --limit for a full index.</comment>');
        }
        $this->outputLine('Finished zero-downtime rebuild — indexed ' . $this->indexedNodes . ' nodes and swapped the index in ' . round(microtime(true) - $startTime, 1) . 's.');
    }

    /**
     * Delete all documents from the index.
     */
    public function flushCommand(): void {
        $this->indexClient->deleteAllDocuments();
        $this->outputLine('All documents flushed from the index.');
    }

    /**
     * Number of progress steps to expect. The indexer visits every node once per
     * dimension, and $limit caps the count per dimension — so the total advances
     * are $limit times the number of dimensions.
     */
    protected function progressMax(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, ?int $limit): int {
        if ($limit === null) {
            return $this->workspaceIndexer->count($contentRepositoryId, $workspaceName);
        }
        $dimensionSpacePoints = $this->contentRepositoryRegistry->get($contentRepositoryId)->getVariationGraph()->getDimensionSpacePoints();
        return $limit * max(1, $dimensionSpacePoints->count());
    }

    /**
     * Whether the node is configured as fulltext root. Copied from AbstractIndexerDriver::isFulltextRoot().
     *
     * @param Node $node
     * @return bool
     */
    protected function isFulltextRoot(NodeAggregate $node): bool {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return false;
        }
        if ($nodeType->hasConfiguration('search')) {
            $searchSettingsForNode = $nodeType->getConfiguration('search');
            if (isset($searchSettingsForNode['fulltext']['isRoot']) && $searchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }
}
