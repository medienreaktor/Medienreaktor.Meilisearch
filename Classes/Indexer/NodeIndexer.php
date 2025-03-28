<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer;

use GuzzleHttp\Psr7\ServerRequest;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Domain\Service\RequestService;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Rector\ContentRepository90\Legacy\LegacyContextStub;
use Ramsey\Uuid\Exception\NodeException;
use function RectorPrefix202304\dump;

/**
 * Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer {
    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var NodeLinkService
     */
    protected $nodeLinkService;

    /**
     * @Flow\Inject
     * @var RequestService
     */
    protected $requestService;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    public function initializeObject($cause) {
        parent::initializeObject($cause);
        putenv('FLOW_REWRITEURLS=1');
    }

    /**
     * @return IndexInterface
     */
    public function getIndexClient(): IndexInterface {
        return $this->indexClient;
    }

    /**
     * Add or update a node in the index with all node variants.
     *
     * @param Node $node
     * @param string $targetWorkspace
     * @return void
     */
    public function indexNode(Node $node, ?WorkspaceName $targetWorkspaceName = null): void {
        // Make sure this is a fulltext root, e.g. Neos.Neos:Document or subtype
        $node = $this->findFulltextRoot($node);

        if ($node !== null) {
            // The node aggregate identifier is a shared node identifier across all variants
            $nodeIdentifier = (string)$node->aggregateId;

            $allIndexedVariants = $this->indexClient->findAllIdentifiersByIdentifier($nodeIdentifier);
            $this->indexClient->deleteDocuments($allIndexedVariants);

            $documents = [];

            // For each dimension combination, extract the node variant properties and fulltext
            $dimensionCombinations = $this->calculateDimensionCombinations($node);
            if ($dimensionCombinations->count() > 0) {
                foreach ($dimensionCombinations as $combination) {
                    if ($nodeVariant = $this->extractNodeVariant($node, $combination)) {
                        $documents[] = $nodeVariant;
                    }
                }
            } else {
                if ($nodeVariant = $this->extractNodeVariant($node, DimensionSpacePoint::createWithoutDimensions())) {
                    $documents[] = $nodeVariant;
                }
            }

            // Finally, send all node variant documents to the index
            $this->indexClient->addDocuments($documents);
        }
    }

    /**
     * Add or update a node in the index with all node variants.
     *
     * @param Node $node
     * @param string $targetWorkspace
     * @return void
     */
    public function indexSingleNode(Node $node): void {
        $node = $this->findFulltextRoot($node);
        if ($node !== null) {
            $this->removeNode($node);
            $nodeVariant = $this->extractNodeVariant($node, $node->dimensionSpacePoint);
            $this->indexClient->addDocuments([$nodeVariant]);
        }
    }

    /**
     * Extract node variant properties and fulltext for a given dimension combination
     *
     * @param string $nodeIdentifier
     * @param DimensionSpacePoint $dimensionCombination
     * @return array
     */
    protected function extractNodeVariant(Node $node, DimensionSpacePoint $dimensionCombination): array|null {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        $subgraph = $contentRepository->getContentGraph(WorkspaceName::forLive())->getSubgraph($dimensionCombination, VisibilityConstraints::createEmpty());
        $node = $subgraph->findNodeById($node->aggregateId);


        if ($node !== null) {
            $identifier = $this->generateUniqueNodeIdentifier($node);
            $fulltext = [];

            $document = $this->extractPropertiesAndFulltext($node, $fulltext);
            $document['id'] = $identifier;
            $document['__fulltext'] = $fulltext;
            $document['title'] = $node->getProperty("title");
            $uri = $this->nodeLinkService->getNodeUri($node);
            $document['__uri'] = $uri;

            if (array_key_exists('__geo', $document)) {
                $document['_geo'] = $document['__geo'];
                unset($document['__geo']);
            }

            return $document;
        }

        return null;
    }

    /**
     * Remove a node from the index.
     *
     * @param Node $node
     * @param WorkspaceName|null $targetWorkspaceName
     * @return void
     */
    public function removeNode(Node $node, WorkspaceName|null $targetWorkspaceName = null): void {
        $identifier = $this->generateUniqueNodeIdentifier($node);
        $this->indexClient->deleteDocuments([$identifier]);
    }

    /**
     * @return void
     */
    public function flush(): void {
        return;
    }

    /**
     * Find the node's fulltext root, e.g. Neos.Neos:Document, by recursively looking at the configuration.
     *
     * @param Node $node
     * @return Node
     */
    protected function findFulltextRoot(Node $node): ?Node {
        if ($this->isFulltextRoot($node)) {
            return $node;
        }

        try {
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
            $currentNode = $subgraph->findParentNode($node->aggregateId);
            while ($currentNode !== null) {
                if ($this->isFulltextRoot($currentNode)) {
                    return $currentNode;
                }
                $subgraph = $this->contentRepositoryRegistry->subgraphForNode($currentNode);

                $currentNode = $subgraph->findParentNode($currentNode->aggregateId);
            }
        } catch (NodeException $exception) {
            return null;
        }

        return null;
    }

    /**
     * Whether the node is configured as fulltext root. Copied from AbstractIndexerDriver::isFulltextRoot().
     *
     * @param Node $node
     * @return bool
     */
    protected function isFulltextRoot(Node $node): bool {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if ($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->hasConfiguration('search')) {
            $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
            $searchSettingsForNode = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->getConfiguration('search');
            if (isset($searchSettingsForNode['fulltext']['isRoot']) && $searchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate all dimension combinations from presets.
     *
     * @return DimensionSpacePointSet
     *
     */
    protected function calculateDimensionCombinations(Node $node): DimensionSpacePointSet {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $dimensions = $contentRepository->getVariationGraph()->getDimensionSpacePoints();
        return $dimensions;
    }

    protected function extractPropertiesAndFulltext(Node|null $node, array &$fulltextData, \Closure $nonIndexedPropertyErrorHandler = null): array {
        $result = parent::extractPropertiesAndFulltext($node, $fulltextData, $nonIndexedPropertyErrorHandler);
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $subgraph = $contentRepository->getContentGraph(WorkspaceName::forLive())->getSubgraph($node->dimensionSpacePoint, VisibilityConstraints::createEmpty());
        $filter = FindChildNodesFilter::create(nodeTypes: 'Neos.Neos:Content,Neos.Neos:ContentCollection');
        $childNodes = $subgraph->findChildNodes($node->aggregateId, $filter);

        foreach ($childNodes as $childNode) {
            $this->enrichWithFulltextForContentNodes($childNode, $fulltextData, $filter);
        }

        return $result;
    }


    protected function enrichWithFulltextForContentNodes(Node $node, array &$fulltextData, FindChildNodesFilter $filter): void {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if ($this->isFulltextEnabled($node)) {
            $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);

            foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
                if (isset($propertyConfiguration['search']['fulltextExtractor'])) {
                    $this->extractFulltext($node, $propertyName, $propertyConfiguration['search']['fulltextExtractor'], $fulltextData);
                }
            }
        }
        $subgraph = $contentRepository->getContentGraph(WorkspaceName::forLive())->getSubgraph($node->dimensionSpacePoint, VisibilityConstraints::createEmpty());
        $childNodes = $subgraph->findChildNodes($node->aggregateId, $filter);

        foreach ($childNodes as $childNode) {
            $this->enrichWithFulltextForContentNodes($childNode, $fulltextData, $filter);
        }
    }

    /**
     * Generate identifier for index document based on node identifier and dimensions.
     *
     * @param Node $node
     * @return string
     */
    protected function generateUniqueNodeIdentifier(Node $node): string {
        $nodeIdentifier = (string)$node->aggregateId;
        $dimensionsHash = md5($node->dimensionSpacePoint->toJson());

        return $nodeIdentifier . '_' . $dimensionsHash;
    }
}
