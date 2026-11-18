<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Indexer\WorkspaceIndexer;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * Brings the index up to date with a batch of live changes: resolves each change to the
 * fulltext roots (documents) it touches and replaces their documents.
 *
 * Changes are resolved without visibility constraints, so a node that was just hidden or
 * removed still leads to its document; the reindex itself then leaves removed variants out.
 *
 * Known gap: a hard-removed content node (NodeAggregateWasRemoved) no longer exists, so its
 * document cannot be found and keeps the old fulltext until the nightly rebuild. The Neos UI
 * removes softly by tag, which is covered.
 *
 * @Flow\Scope("singleton")
 */
class DocumentCatchUp
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeIndexer $nodeIndexer;

    #[Flow\Inject]
    protected WorkspaceIndexer $workspaceIndexer;

    /**
     * @param list<IndexingImpact> $impacts
     * @return int Number of documents reindexed or removed
     */
    public function apply(ContentRepositoryId $contentRepositoryId, array $impacts): int
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph(WorkspaceName::forLive());
        $dimensionSpacePoints = $contentRepository->getVariationGraph()->getDimensionSpacePoints();
        $fulltextRootCriteria = $this->workspaceIndexer->fulltextRootNodeTypeCriteria($contentRepository);

        /** @var array<string, NodeAggregateId> $toReindex */
        $toReindex = [];
        /** @var array<string, NodeAggregateId> $toRemove */
        $toRemove = [];

        foreach ($impacts as $impact) {
            if ($impact->scope === IndexingScope::Removed) {
                $toRemove[$impact->nodeAggregateId->value] = $impact->nodeAggregateId;
                continue;
            }

            foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
                $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, VisibilityConstraints::createEmpty());
                $node = $subgraph->findNodeById($impact->nodeAggregateId);
                if ($node === null) {
                    continue;
                }

                $fulltextRoot = $this->nodeIndexer->fulltextRootOf($node);
                if ($fulltextRoot !== null) {
                    $toReindex[$fulltextRoot->aggregateId->value] = $fulltextRoot->aggregateId;
                }

                if ($impact->scope !== IndexingScope::Subtree) {
                    continue;
                }
                $descendants = $subgraph->findDescendantNodes($node->aggregateId, FindDescendantNodesFilter::create(nodeTypes: $fulltextRootCriteria));
                foreach ($descendants as $descendant) {
                    $toReindex[$descendant->aggregateId->value] = $descendant->aggregateId;
                }
            }
        }

        foreach ($toRemove as $nodeAggregateId) {
            $this->nodeIndexer->removeFulltextRoot($nodeAggregateId);
        }
        foreach (array_diff_key($toReindex, $toRemove) as $nodeAggregateId) {
            $this->nodeIndexer->reindexFulltextRoot($contentRepositoryId, $nodeAggregateId);
        }

        return count($toRemove) + count(array_diff_key($toReindex, $toRemove));
    }
}
