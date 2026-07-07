<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Search\Indexer\NodeIndexingManager;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * Workspace Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
final class WorkspaceIndexer
{
    /**
     * @var NodeIndexingManager
     * @Flow\Inject
     */
    protected $nodeIndexingManager;

    /**
     * @Flow\Inject
     * @var NodeIndexer
     */
    protected $nodeIndexer;


    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @param string $workspaceName
     * @param integer $limit
     * @param callable $callback
     * @return integer
     */
    public function index(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, $limit = null, ?callable $callback = null, ?callable $singleCallback = null, bool $skipRemoval = false, ?string $targetIndexName = null): int
    {
        $count = 0;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $dimensionSpacePoints = $contentRepository->getVariationGraph()->getDimensionSpacePoints();

        if ($dimensionSpacePoints->isEmpty()) {
            $count += $this->indexWithDimensions($contentRepositoryId, $workspaceName, DimensionSpacePoint::createWithoutDimensions(), $limit, $callback, $singleCallback, $skipRemoval, $targetIndexName);
        } else {
            foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
                $count += $this->indexWithDimensions($contentRepositoryId, $workspaceName, $dimensionSpacePoint, $limit, $callback, $singleCallback, $skipRemoval, $targetIndexName);
            }
        }

        return $count;
    }

    /**
     * Counts how many nodes index() would visit (all descendants of the sites
     * root across all dimensions). Cheap SQL COUNT, no nodes are materialised —
     * used to give the build a determinate progress bar.
     */
    public function count(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): int
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $dimensionSpacePoints = $contentRepository->getVariationGraph()->getDimensionSpacePoints();
        $dimensionSpacePoints = $dimensionSpacePoints->isEmpty()
            ? [DimensionSpacePoint::createWithoutDimensions()]
            : $dimensionSpacePoints;

        $contentGraph = $contentRepository->getContentGraph($workspaceName);
        $rootNodeAggregate = $contentGraph->findRootNodeAggregateByType(NodeTypeNameFactory::forSites());

        $total = 0;
        foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
            $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
            $total += $subgraph->countDescendantNodes($rootNodeAggregate->nodeAggregateId, CountDescendantNodesFilter::create());
        }
        return $total;
    }

    /**
     * @param string $workspaceName
     * @param array $dimensions
     * @param int|null $limit
     * @param callable $callback
     * @return int
     */
    public function indexWithDimensions(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, ?int $limit = null, ?callable $callback = null, ?callable $singleCallback = null, bool $skipRemoval = false, ?string $targetIndexName = null): int
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph($workspaceName);

        $rootNodeAggregate = $contentGraph->findRootNodeAggregateByType(NodeTypeNameFactory::forSites());
        $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $rootNode = $subgraph->findNodeById($rootNodeAggregate->nodeAggregateId);
        $indexedNodes = 0;

        $this->nodeIndexer->indexSingleNode($rootNode, $skipRemoval, $targetIndexName);
        $indexedNodes++;

        foreach ($subgraph->findDescendantNodes($rootNode->aggregateId, FindDescendantNodesFilter::create()) as $descendantNode) {
            if ($limit !== null && $indexedNodes > $limit) {
                break;
            }

            $this->nodeIndexer->indexSingleNode($descendantNode, $skipRemoval, $targetIndexName);
            $indexedNodes++;
            if ($singleCallback !== null) {
                $singleCallback($workspaceName, $indexedNodes, $dimensionSpacePoint);
            }

        };

        $this->nodeIndexer->flush($targetIndexName);

        if ($callback !== null) {
            $callback($workspaceName, $indexedNodes, $dimensionSpacePoint);
        }

        return $indexedNodes;
    }
}
