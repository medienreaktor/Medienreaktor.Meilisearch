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

use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
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
final class WorkspaceIndexer {
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
     * Lazily computed once per run (singleton) and per content repository: the
     * node type criteria matching all fulltext roots. Iterating the node types
     * on every dimension would be wasteful.
     *
     * @var array<string,NodeTypeCriteria>
     */
    private array $fulltextRootCriteria = [];

    /**
     * @param string $workspaceName
     * @param integer $limit
     * @param callable $callback
     * @return integer
     */
    public function index(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, $limit = null, ?callable $callback = null, ?callable $singleCallback = null, bool $skipRemoval = false, ?string $targetIndexName = null): int {
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
     * Counts how many nodes index() would visit: the fulltext roots below the
     * sites root plus the sites root itself, per dimension. Cheap SQL COUNT, no
     * nodes are materialised — used to give the build a determinate progress bar.
     */
    public function count(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): int {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $dimensionSpacePoints = $contentRepository->getVariationGraph()->getDimensionSpacePoints();
        $dimensionSpacePoints = $dimensionSpacePoints->isEmpty()
            ? [DimensionSpacePoint::createWithoutDimensions()]
            : $dimensionSpacePoints;

        $contentGraph = $contentRepository->getContentGraph($workspaceName);
        $rootNodeAggregate = $contentGraph->findRootNodeAggregateByType(NodeTypeNameFactory::forSites());

        $rootNodeTypeCriteria = $this->fulltextRootNodeTypeCriteria($contentRepository);
        $total = 0;
        foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
            $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
            $total += $subgraph->countDescendantNodes($rootNodeAggregate->nodeAggregateId, CountDescendantNodesFilter::create(nodeTypes: $rootNodeTypeCriteria));
            // The sites root itself is visited before the descendants.
            $total++;
        }
        return $total;
    }

    /**
     * Node type criteria matching every fulltext root (search.fulltext.isRoot).
     * We only iterate those: each becomes one search document, and their content
     * descendants' fulltext is collected during extraction — so visiting the
     * content nodes themselves would just re-extract the same document.
     *
     * Only the types that declare isRoot themselves are named: NodeTypeCriteria
     * expands every entry to its subtypes, so naming the inheriting types as
     * well would only bloat the generated IN (...) — normally this comes down to
     * the single abstract Neos.Neos:Document.
     */
    protected function fulltextRootNodeTypeCriteria(ContentRepository $contentRepository): NodeTypeCriteria {
        $cacheKey = $contentRepository->id->value;
        if (isset($this->fulltextRootCriteria[$cacheKey])) {
            return $this->fulltextRootCriteria[$cacheKey];
        }

        $nodeTypeManager = $contentRepository->getNodeTypeManager();
        $isFulltextRoot = static function (NodeType $nodeType): bool {
            $search = $nodeType->getConfiguration('search');
            return is_array($search) && ($search['fulltext']['isRoot'] ?? false) === true;
        };

        $rootNodeTypes = [];
        foreach ($nodeTypeManager->getNodeTypes(true) as $nodeType) {
            if ($isFulltextRoot($nodeType)) {
                $rootNodeTypes[$nodeType->name->value] = $nodeType;
            }
        }
        if ($rootNodeTypes === []) {
            // An empty allow-list means "everything" to NodeTypeCriteria, which would
            // silently turn the fulltext-root filter into a full graph walk.
            throw new Exception(sprintf(
                'No node type in content repository "%s" is configured as a fulltext root (search.fulltext.isRoot). Refusing to index, because an empty criteria would match every node instead.',
                $cacheKey
            ), 1756100000);
        }

        $inheritsIsRoot = [];
        $optedOut = [];
        foreach ($rootNodeTypes as $rootNodeType) {
            foreach ($nodeTypeManager->getSubNodeTypes($rootNodeType->name, true) as $subNodeType) {
                if ($isFulltextRoot($subNodeType)) {
                    $inheritsIsRoot[$subNodeType->name->value] = true;
                } else {
                    // Switched isRoot off again — it has to be excluded explicitly,
                    // or the supertype's expansion would pull it back in.
                    $optedOut[$subNodeType->name->value] = true;
                }
            }
        }

        // An exclusion is expanded to its subtypes too and wins over an inclusion,
        // so a type that opts out while one of its own subtypes re-enables isRoot
        // cannot be expressed. Keep such a type in: visiting a few extra nodes only
        // costs time (they resolve to their nearest fulltext root), whereas
        // excluding them would drop documents from the index.
        foreach (array_keys($optedOut) as $optedOutNodeTypeName) {
            foreach ($nodeTypeManager->getSubNodeTypes($optedOutNodeTypeName, true) as $subNodeType) {
                if ($isFulltextRoot($subNodeType)) {
                    unset($optedOut[$optedOutNodeTypeName]);
                    break;
                }
            }
        }

        $declaringNodeTypeNames = array_values(array_diff(array_keys($rootNodeTypes), array_keys($inheritsIsRoot)));

        return $this->fulltextRootCriteria[$cacheKey] = NodeTypeCriteria::create(
            NodeTypeNames::fromStringArray($declaringNodeTypeNames),
            NodeTypeNames::fromStringArray(array_keys($optedOut))
        );
    }

    /**
     * @param string $workspaceName
     * @param array $dimensions
     * @param int|null $limit
     * @param callable $callback
     * @return int
     */
    public function indexWithDimensions(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, ?int $limit = null, ?callable $callback = null, ?callable $singleCallback = null, bool $skipRemoval = false, ?string $targetIndexName = null): int {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph($workspaceName);

        $rootNodeAggregate = $contentGraph->findRootNodeAggregateByType(NodeTypeNameFactory::forSites());
        $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $rootNode = $subgraph->findNodeById($rootNodeAggregate->nodeAggregateId);
        $indexedNodes = 0;

        $this->nodeIndexer->indexSingleNode($rootNode, $skipRemoval, $targetIndexName);
        $indexedNodes++;
        if ($singleCallback !== null) {
            $singleCallback($workspaceName, $indexedNodes, $dimensionSpacePoint);
        }

        foreach ($subgraph->findDescendantNodes(
            $rootNode->aggregateId,
            FindDescendantNodesFilter::create(nodeTypes: $this->fulltextRootNodeTypeCriteria($contentRepository)))
                 as $descendantNode) {
            // $indexedNodes already counts the sites root, so this caps the visited
            // nodes at exactly $limit per dimension — which is what the progress
            // bar's maximum is calculated from.
            if ($limit !== null && $indexedNodes >= $limit) {
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
