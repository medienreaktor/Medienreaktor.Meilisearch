<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraintFactory;
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraints;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\Flow\Annotations as Flow;

/**
 * Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer
{
    /**
     * Bounds how many documents may sit in the buffer before it is written out, so the
     * memory this holds does not grow with the size of the site. Mirrors the index
     * client's request batch size, since one full buffer becomes one request.
     */
    private const DEFAULT_BATCH_SIZE = 1000;

    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var NodeLinkService
     */
    protected $nodeLinkService;

    /**
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * @Flow\Inject
     * @var NodeTypeConstraintFactory
     */
    protected $nodeTypeConstraintFactory;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch", path="enableFulltext")
     * @var bool
     */
    protected $enableFulltext;

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch", path="neededAttributesForIndex")
     * @var string[]
     */
    protected $neededAttributesForIndex;

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch", path="indexing")
     * @var array
     */
    protected $indexingSettings = [];

    /**
     * Documents awaiting the next flush, keyed by document id so that indexing the same
     * variant twice in one batch writes it once.
     *
     * @var array<string, array>
     */
    protected $bufferedDocuments = [];

    /**
     * Document ids awaiting deletion, used as a set.
     *
     * @var array<string, bool>
     */
    protected $bufferedDocumentDeletions = [];

    /**
     * Node aggregate identifiers whose stale variants await deletion, used as a set.
     *
     * @var array<string, bool>
     */
    protected $bufferedIdentifierDeletions = [];

    /**
     * Whether the index is known to hold no documents, which makes every deletion a
     * no-op worth skipping. Only a caller that has just emptied the index can know
     * this, so nothing infers it.
     *
     * @var bool
     */
    protected $assumeEmptyIndex = false;

    public function initializeObject($cause)
    {
        parent::initializeObject($cause);
        putenv('FLOW_REWRITEURLS=1');
    }

    /**
     * @return IndexInterface
     */
    public function getIndexClient(): IndexInterface
    {
        return $this->indexClient;
    }

    /**
     * Add or update a node in the index with all node variants.
     *
     * @param NodeInterface $node
     * @param string $targetWorkspace
     * @param bool $indexAllDimensions
     * @param bool $indexFallbackDimensions Whether to index dimensions that fall back to the current nodes dimensions
     * @param array $targetDimensionCombination Optional: Force indexing with this dimension combination (for shine-through scenarios)
     * @return void
     */
    public function indexNode(
        NodeInterface $node,
        $targetWorkspace = null,
        $indexAllDimensions = true,
        $indexFallbackDimensions = true,
        array $targetDimensionCombination = []
    ): void {
        $node = $this->requireTraversable($node);

        // Make sure this is a fulltext root, e.g. Neos.Neos:Document or subtype
        $node = $this->findFulltextRoot($node);

        if ($node === null) {
            return;
        }

        if (!$node->isVisible()) {
            $this->removeNode($node);
            return;
        }

        // The node aggregate identifier is a shared node identifier across all variants
        $nodeIdentifier = (string) $node->getNodeAggregateIdentifier();

        $documents = [];

        // For each dimension combination, extract the node variant properties and fulltext
        $dimensionCombinations = $this->dimensionsService->getDimensionCombinationsForIndexing($node);
        if ($indexAllDimensions && $dimensionCombinations !== []) {
            $this->bufferIdentifierDeletion($nodeIdentifier);
            foreach ($dimensionCombinations as $combination) {
                if ($nodeVariant = $this->extractNodeVariant($nodeIdentifier, $combination)) {
                    $documents[] = $nodeVariant;
                }
            }
        } elseif ($indexFallbackDimensions && $dimensionCombinations !== []) {
            // Index only the current dimension and all dimensions that fall back to the current nodes dimensions.
            foreach ($dimensionCombinations as $combination) {
                // Check if current dimension and all dimensions that fall back to the current nodes dimensions
                if ($this->dimensionsService->combinationFallsBackTo($node->getContext()->getDimensions(), $combination)) {
                    // delete previously indexed variant with same dimensions
                    $dimensionsHash = $this->dimensionsService->hash($combination);
                    $this->bufferDocumentDeletion(
                        $this->generateDocumentIdentifier($nodeIdentifier, $dimensionsHash)
                    );
                    // Index the new node variant
                    if ($nodeVariant = $this->extractNodeVariant($nodeIdentifier, $combination)) {
                        $documents[] = $nodeVariant;
                    }
                }
            }
        } else {
            // Index only the current dimension combination without any fallbacks
            // Use targetDimensionCombination if provided (for shine-through/fallback scenarios)
            $effectiveDimensions = $targetDimensionCombination !== [] ? $targetDimensionCombination : [];
            $dimensionsHash = $effectiveDimensions !== []
                ? $this->dimensionsService->hash($effectiveDimensions)
                : $this->dimensionsService->hashByNode($node);

            $this->bufferDocumentDeletion(
                $this->generateDocumentIdentifier($nodeIdentifier, $dimensionsHash)
            );
            if ($nodeVariant = $this->extractNodeVariant($nodeIdentifier, $effectiveDimensions)) {
                $documents[] = $nodeVariant;
            }
        }

        // Buffer all node variant documents; flush() is what reaches the index
        foreach ($documents as $document) {
            $this->bufferDocument($document);
        }

        $this->flushIfBufferIsFull();
    }

    /**
     * Extract node variant properties and fulltext for a given dimension combination
     *
     * @param string $nodeIdentifier
     * @param array $dimensionCombination The target dimension combination to index for
     * @return array
     */
    protected function extractNodeVariant(string $nodeIdentifier, array $dimensionCombination = []): ?array
    {
        if ($dimensionCombination !== []) {
            $context = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => $dimensionCombination]);
        } else {
            $context = $this->contextFactory->create(['workspaceName' => 'live']);
        }

        $node = $context->getNodeByIdentifier($nodeIdentifier);

        if ($node !== null) {
            $node = $this->requireTraversable($node);
            // Use dimensionCombination for hash when provided (handles fallback/shine-through)
            // This ensures content visible in English is indexed with English hash even if
            // the underlying node is German
            $overrideDimensions = $dimensionCombination !== [] ? $dimensionCombination : null;
            $identifier = $this->generateUniqueNodeIdentifier($node, $overrideDimensions);
            $fulltext = [];

            $document = $this->extractPropertiesAndFulltext($node, $fulltext);
            $document['id'] = $identifier;

            // Override __dimensionsHash with the target dimension hash (not the node's internal dimensions)
            // This is critical for dimension fallback scenarios where German content appears in English
            if ($dimensionCombination !== []) {
                $document['__dimensionsHash'] = $this->dimensionsService->hash($dimensionCombination);
                $document['__dimensions'] = $dimensionCombination;
            }

            if ($this->enableFulltext) {
                $document['__fulltext'] = $fulltext;
            }

            if ($uri = $this->nodeLinkService->getNodeUri($node, $context)) {
                $document['__uri'] = $uri;
            }

            if (array_key_exists('__geo', $document)) {
                $document['_geo'] = $document['__geo'];
                unset($document['__geo']);
            }

            foreach ($this->neededAttributesForIndex as $key) {
                if (empty($document[$key])) {
                    $this->bufferDocumentDeletion($identifier);
                    return null;
                }
            }

            return $document;
        }

        return null;
    }

    /**
     * Remove a node from the index.
     *
     * @param NodeInterface $node
     * @return void
     */
    public function removeNode(NodeInterface $node): void
    {
        $identifier = $this->generateUniqueNodeIdentifier($this->requireTraversable($node));
        $this->bufferDocumentDeletion($identifier);
        $this->flushIfBufferIsFull();
    }

    /**
     * Write everything buffered so far.
     *
     * Meilisearch queues one task per write request, and a queue that grows faster than
     * the server drains it is what makes a rebuild expensive: writing per node turns
     * every node into two tasks. Buffering collapses a whole batch into one deletion
     * and one addition regardless of how many nodes it covers.
     *
     * The three groups go out in a fixed order - stale variants by aggregate, then
     * documents by id, then additions - which is why buffering coalesces per document
     * id as it goes: it keeps a delete and an add of the same id from being reordered
     * against each other.
     *
     * @return void
     */
    public function flush(): void
    {
        $identifierDeletions = array_keys($this->bufferedIdentifierDeletions);
        $documentDeletions = array_keys($this->bufferedDocumentDeletions);
        $documents = array_values($this->bufferedDocuments);

        // Cleared up front so that a failing write cannot be retried into a loop by a
        // caller that flushes again in its error handling.
        $this->bufferedIdentifierDeletions = [];
        $this->bufferedDocumentDeletions = [];
        $this->bufferedDocuments = [];

        // Each group is asked for only when it has something in it. The whole point of
        // buffering is to stop making writes nobody needs, and flush() runs on every
        // persisted request whether anything was indexed or not.
        if ($identifierDeletions !== []) {
            $this->indexClient->deleteByIdentifiers($identifierDeletions);
        }

        if ($documentDeletions !== []) {
            $this->indexClient->deleteDocuments($documentDeletions);
        }

        if ($documents !== []) {
            $this->indexClient->addDocuments($documents);
        }
    }

    /**
     * Declare that the index holds no documents, which makes every deletion this
     * indexer would buffer a no-op. Halves the work of a full rebuild, and is only safe
     * for a caller that has just emptied the index itself.
     *
     * @return void
     */
    public function assumeEmptyIndex(): void
    {
        $this->assumeEmptyIndex = true;
    }

    /**
     * @param array $document
     * @return void
     */
    protected function bufferDocument(array $document): void
    {
        $identifier = (string) $document['id'];

        // An addition supersedes a deletion of the same document buffered before it.
        unset($this->bufferedDocumentDeletions[$identifier]);
        $this->bufferedDocuments[$identifier] = $document;
    }

    /**
     * @param string $documentIdentifier
     * @return void
     */
    protected function bufferDocumentDeletion(string $documentIdentifier): void
    {
        if ($this->assumeEmptyIndex) {
            return;
        }

        // A deletion supersedes an addition of the same document buffered before it.
        unset($this->bufferedDocuments[$documentIdentifier]);
        $this->bufferedDocumentDeletions[$documentIdentifier] = true;
    }

    /**
     * @param string $nodeIdentifier
     * @return void
     */
    protected function bufferIdentifierDeletion(string $nodeIdentifier): void
    {
        if ($this->assumeEmptyIndex) {
            return;
        }

        $this->bufferedIdentifierDeletions[$nodeIdentifier] = true;
    }

    /**
     * Flushes once the buffer has reached its size, and only ever between nodes: a node
     * whose deletions and additions were split across two flushes would leave the index
     * without it in between.
     *
     * @return void
     */
    protected function flushIfBufferIsFull(): void
    {
        $buffered = count($this->bufferedDocuments)
            + count($this->bufferedDocumentDeletions)
            + count($this->bufferedIdentifierDeletions);

        if ($buffered >= $this->batchSize()) {
            $this->flush();
        }
    }

    /**
     * @return int
     */
    protected function batchSize(): int
    {
        return max(1, (int) ($this->indexingSettings['batchSize'] ?? self::DEFAULT_BATCH_SIZE));
    }

    /**
     * Find the node's fulltext root, e.g. Neos.Neos:Document, by recursively looking at the configuration.
     *
     * @param NodeInterface&TraversableNodeInterface $node
     * @return (NodeInterface&TraversableNodeInterface)|null
     */
    public function findFulltextRoot(NodeInterface $node): ?NodeInterface
    {
        if (self::isFulltextRoot($node)) {
            return $node;
        }

        // findParentNode() throws rather than returning null once the rootline is
        // exhausted, so that exception is the loop's terminating condition.
        try {
            $currentNode = $this->requireTraversable($node->findParentNode());
            while (!self::isFulltextRoot($currentNode)) {
                $currentNode = $this->requireTraversable($currentNode->findParentNode());
            }

            return $currentNode;
        } catch (NodeException $exception) {
            return null;
        }
    }

    /**
     * Whether the node is configured as fulltext root. Copied from AbstractIndexerDriver::isFulltextRoot().
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected static function isFulltextRoot(NodeInterface $node): bool
    {
        if ($node->getNodeType()->hasConfiguration('search')) {
            $searchSettingsForNode = $node->getNodeType()->getConfiguration('search');
            if (isset($searchSettingsForNode['fulltext']['isRoot']) && $searchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }

    protected function extractPropertiesAndFulltext(NodeInterface $node, array &$fulltextData, \Closure $nonIndexedPropertyErrorHandler = null): array
    {
        $result = parent::extractPropertiesAndFulltext($node, $fulltextData, $nonIndexedPropertyErrorHandler);

        if (!$this->enableFulltext) {
            return $result;
        }

        $nodeTypeConstraints = $this->nodeTypeConstraintFactory->parseFilterString('Neos.Neos:Content,Neos.Neos:ContentCollection');

        foreach ($this->requireTraversable($node)->findChildNodes($nodeTypeConstraints) as $childNode) {
            $this->enrichWithFulltextForContentNodes($this->requireTraversable($childNode), $fulltextData, $nodeTypeConstraints);
        }

        return $result;
    }

    /**
     * @param NodeInterface&TraversableNodeInterface $node
     */
    protected function enrichWithFulltextForContentNodes(NodeInterface $node, array &$fulltextData, NodeTypeConstraints $nodeTypeConstraints): void
    {
        if ($this->isFulltextEnabled($node)) {
            $nodeType = $node->getNodeType();

            foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
                if (isset($propertyConfiguration['search']['fulltextExtractor'])) {
                    $this->extractFulltext($node, $propertyName, $propertyConfiguration['search']['fulltextExtractor'], $fulltextData);
                }
            }
        }

        foreach ($node->findChildNodes($nodeTypeConstraints) as $childNode) {
            $this->enrichWithFulltextForContentNodes($this->requireTraversable($childNode), $fulltextData, $nodeTypeConstraints);
        }
    }

    /**
     * Generate identifier for index document based on node identifier and dimensions.
     *
     * @param NodeInterface&TraversableNodeInterface $node
     * @param array|null $overrideDimensions Optional dimensions to use instead of node's context dimensions
     * @return string
     */
    protected function generateUniqueNodeIdentifier(NodeInterface $node, ?array $overrideDimensions = null): string
    {
        $nodeIdentifier = (string) $node->getNodeAggregateIdentifier();

        // Use override dimensions if provided (for fallback/shine-through scenarios)
        // Otherwise fall back to node's context target dimensions
        $dimensionsHash = $overrideDimensions !== null
            ? $this->dimensionsService->hash($overrideDimensions)
            : $this->dimensionsService->hashByNode($node);

        return $this->generateDocumentIdentifier($nodeIdentifier, $dimensionsHash);
    }

    protected function generateDocumentIdentifier(string $nodeIdentifier, string $dimensionsHash): string
    {
        return $nodeIdentifier . '_' . $dimensionsHash;
    }

    /**
     * Neos splits the node contract across two unrelated interfaces: properties,
     * context and visibility live on Model\NodeInterface, while traversal and the
     * aggregate identifier live on TraversableNodeInterface. Nothing but the concrete
     * Model\Node implements both, and every node Neos produces is that class — so the
     * assumption is sound, and this is the one place it is checked rather than assumed.
     *
     * @param mixed $node
     * @return NodeInterface&TraversableNodeInterface
     * @throws Exception
     */
    public function requireTraversable($node)
    {
        if (!$node instanceof NodeInterface || !$node instanceof TraversableNodeInterface) {
            throw new Exception(sprintf(
                'Expected a node implementing both %s and %s, got %s.',
                NodeInterface::class,
                TraversableNodeInterface::class,
                is_object($node) ? get_class($node) : gettype($node)
            ), 1787000001);
        }

        return $node;
    }
}
