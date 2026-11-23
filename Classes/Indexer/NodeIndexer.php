<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\ContentRepository\Search\Indexer\BulkNodeIndexerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * Indexer for Content Repository Nodes.
 *
 * Writes are buffered and go out in batches; flush() says when.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer implements BulkNodeIndexerInterface
{
    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * Used when no setting supplies a batch size, as in unit tests that construct the
     * indexer without the Flow proxy.
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * @Flow\InjectConfiguration(path="indexing.batchSize", package="Medienreaktor.Meilisearch")
     * @var int|null
     */
    protected $batchSize;

    /**
     * Documents awaiting the next write, keyed by document id, so that indexing the same
     * variant twice in one batch writes it once.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $bufferedDocuments = [];

    /**
     * Document ids awaiting deletion, used as a set.
     *
     * @var array<string, true>
     */
    protected array $bufferedDocumentDeletions = [];

    /**
     * Node aggregate ids whose documents await deletion, used as a set.
     *
     * @var array<string, true>
     */
    protected array $bufferedIdentifierDeletions = [];

    /**
     * The index the buffer is written to; null for the live index.
     */
    protected ?string $bufferTargetIndexName = null;

    /**
     * Whether the index is known to hold no documents, which makes every deletion a
     * no-op worth skipping. Only a caller that has just emptied the index can know this.
     */
    protected bool $assumeEmptyIndex = false;

    /**
     * How many withBulkProcessing() calls are open.
     */
    protected int $bulkProcessingDepth = 0;

    /**
     * @Flow\InjectConfiguration(path="indexing.maxFulltextBytes", package="Medienreaktor.Meilisearch")
     * @var int
     */
    protected $maxFulltextBytes;

    /**
     * @Flow\InjectConfiguration(path="enableFulltext", package="Medienreaktor.Meilisearch")
     * @var bool
     */
    protected $enableFulltext = true;

    /**
     * @Flow\InjectConfiguration(path="neededAttributesForIndex", package="Medienreaktor.Meilisearch")
     * @var list<string>
     */
    protected $neededAttributesForIndex = [];

    /**
     * @Flow\Inject
     * @var NodeLinkService
     */
    protected $nodeLinkService;

    /**
     * What the index holds: live content as a visitor sees it, without soft-removed and
     * without disabled (hidden) nodes, nor anything below them. A node becoming hidden
     * so leaves the index on its next reindex, as on the Neos 8 line.
     */
    public static function visibilityConstraints(): VisibilityConstraints
    {
        return NeosVisibilityConstraints::excludeRemoved()->merge(NeosVisibilityConstraints::excludeDisabled());
    }

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
     * Add or update a node in the index.
     *
     * The node's fulltext root is indexed, e.g. the page a content element is on. By
     * default in every dimension space point; $indexAllDimensions = false limits it to
     * the node's origin and the dimension space points that fall back to it (where the
     * node shines through), and additionally $indexFallbackDimensions = false to the
     * dimension space point the node was read in. Writes are buffered; flush() says
     * when they reach the index.
     *
     * @param Node $node
     * @param WorkspaceName|null $targetWorkspaceName Ignored: the index holds live content only
     * @param bool $indexAllDimensions
     * @param bool $indexFallbackDimensions Only used when not indexing all dimensions
     * @return void
     */
    public function indexNode(
        Node $node,
        ?WorkspaceName $targetWorkspaceName = null,
        bool $indexAllDimensions = true,
        bool $indexFallbackDimensions = true
    ): void {
        $node = $this->findFulltextRoot($node);
        if ($node === null) {
            return;
        }

        $variationGraph = $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getVariationGraph();
        if ($indexAllDimensions) {
            $this->bufferIdentifierDeletion($node->aggregateId->value);
            $dimensionSpacePoints = $variationGraph->getDimensionSpacePoints();
            if ($dimensionSpacePoints->isEmpty()) {
                $dimensionSpacePoints = [DimensionSpacePoint::createWithoutDimensions()];
            }
        } elseif ($indexFallbackDimensions) {
            $dimensionSpacePoints = $variationGraph->getSpecializationSet($node->originDimensionSpacePoint->toDimensionSpacePoint());
        } else {
            $dimensionSpacePoints = [$node->dimensionSpacePoint];
        }

        foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
            if (!$indexAllDimensions) {
                $this->bufferDocumentDeletion($this->generateDocumentIdentifier($node->aggregateId->value, $dimensionSpacePoint->hash));
            }
            $document = $this->extractNodeVariant($node, $dimensionSpacePoint);
            if ($document !== null) {
                $this->bufferDocument($document);
            }
        }

        $this->flushIfBufferIsFull();
    }

    /**
     * Add or update the given variant of a node only, as nodeindex:build visits them.
     *
     * @param Node $node
     * @param bool $skipRemoval Skip deleting the previous document, which is safe on an empty index
     * @param string|null $targetIndexName Write to this index instead of the live one (rebuild)
     * @return void
     */
    public function indexSingleNode(Node $node, bool $skipRemoval = false, ?string $targetIndexName = null): void
    {
        $node = $this->findFulltextRoot($node);
        if ($node === null) {
            return;
        }

        $this->switchTargetIndex($targetIndexName);
        if (!$skipRemoval) {
            $this->bufferDocumentDeletion($this->generateUniqueNodeIdentifier($node));
        }
        $document = $this->extractNodeVariant($node, $node->dimensionSpacePoint);
        if ($document !== null) {
            $this->bufferDocument($document);
        }

        $this->flushIfBufferIsFull();
    }

    /**
     * Extract node variant properties and fulltext for a given dimension space point
     *
     * @param Node $node
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return array<string, mixed>|null Null when the node is not visible there
     */
    protected function extractNodeVariant(Node $node, DimensionSpacePoint $dimensionSpacePoint): ?array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        $subgraph = $contentRepository->getContentGraph(WorkspaceName::forLive())->getSubgraph($dimensionSpacePoint, self::visibilityConstraints());
        $node = $subgraph->findNodeById($node->aggregateId);

        return $node !== null ? $this->extractDocument($node) : null;
    }

    /**
     * The node's document, or null when it lacks one of the attributes configured in
     * `neededAttributesForIndex`. Callers remove the node's previous documents before
     * adding new ones, so a node that loses such an attribute leaves the index.
     *
     * @return array<string, mixed>|null
     */
    protected function extractDocument(Node $node): ?array
    {
        $fulltext = [];

        $document = $this->extractPropertiesAndFulltext($node, $fulltext);
        $document['id'] = $this->generateUniqueNodeIdentifier($node);
        if ($this->enableFulltext) {
            $document['__fulltext'] = $this->truncateFulltext($fulltext);
        }
        $document['title'] = $node->getProperty("title");
        $document['__uri'] = $this->getNodeUri($node);

        if (array_key_exists('__geo', $document)) {
            $document['_geo'] = $document['__geo'];
            unset($document['__geo']);
        }

        foreach ($this->neededAttributesForIndex as $attribute) {
            if (empty($document[$attribute])) {
                return null;
            }
        }

        return $document;
    }

    /**
     * Replace every document of one fulltext root with its current live variants, seen the
     * way nodeindex:build sees them: a removed or hidden variant, or one below a removed or
     * hidden node, is left out.
     */
    public function reindexFulltextRoot(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph(WorkspaceName::forLive());

        $this->bufferIdentifierDeletion($nodeAggregateId->value);
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            $node = $contentGraph->getSubgraph($dimensionSpacePoint, self::visibilityConstraints())->findNodeById($nodeAggregateId);
            $document = $node !== null ? $this->extractDocument($node) : null;
            if ($document !== null) {
                $this->bufferDocument($document);
            }
        }

        $this->flushIfBufferIsFull();
    }

    public function removeFulltextRoot(NodeAggregateId $nodeAggregateId): void
    {
        $this->bufferIdentifierDeletion($nodeAggregateId->value);
        $this->flushIfBufferIsFull();
    }

    /**
     * The node itself if it is a fulltext root, otherwise its closest fulltext root ancestor.
     */
    public function fulltextRootOf(Node $node): ?Node
    {
        return $this->findFulltextRoot($node);
    }

    /**
     * Remove a node's document in the node's dimension space point from the index.
     *
     * @param Node $node
     * @param WorkspaceName|null $targetWorkspaceName Ignored: the index holds live content only
     * @param string|null $targetIndexName Delete from this index instead of the live one (rebuild)
     * @return void
     */
    public function removeNode(Node $node, ?WorkspaceName $targetWorkspaceName = null, ?string $targetIndexName = null): void
    {
        $this->switchTargetIndex($targetIndexName);
        $this->bufferDocumentDeletion($this->generateUniqueNodeIdentifier($node));
        $this->flushIfBufferIsFull();
    }

    /**
     * Write everything buffered so far - or, while withBulkProcessing() is open, only a
     * full buffer.
     *
     * Meilisearch queues one task per write request, and writing per node turns every
     * node into two tasks. Buffering collapses a whole batch into one deletion and one
     * addition. Neos.ContentRepository.Search flushes after every draining of its
     * indexing queue, from inside withBulkProcessing(); a flush there writes nothing
     * until the buffer is full, and the last buffer goes out in shutdownObject().
     * Called directly, as the index commands and the catch-up do, it writes everything.
     *
     * @param string|null $targetIndexName Write to this index instead of the live one (rebuild)
     * @return void
     */
    public function flush(?string $targetIndexName = null): void
    {
        $this->switchTargetIndex($targetIndexName);
        if ($this->bulkProcessingDepth > 0) {
            $this->flushIfBufferIsFull();
            return;
        }

        $this->writeBuffer();
    }

    /**
     * Run the callback with flushes deferred to full buffers.
     *
     * Neos.ContentRepository.Search wraps each draining of its indexing queue in this.
     * Nothing is written when the callback returns: the buffer carries over to the next
     * call. A depth rather than a flag, so that a nested call cannot end the outer one.
     *
     * @param callable $callback
     * @return void
     */
    public function withBulkProcessing(callable $callback): void
    {
        $this->bulkProcessingDepth++;
        try {
            $callback();
        } finally {
            $this->bulkProcessingDepth--;
        }
    }

    /**
     * Flow calls this as the request or command ends: whatever deferred flushes left in
     * the buffer is written here.
     *
     * @return void
     */
    public function shutdownObject(): void
    {
        $this->flush($this->bufferTargetIndexName);
    }

    /**
     * Drop whatever is buffered without writing it, e.g. when the index it was meant
     * for has been discarded.
     */
    public function discardBuffer(): void
    {
        $this->bufferedIdentifierDeletions = [];
        $this->bufferedDocumentDeletions = [];
        $this->bufferedDocuments = [];
        $this->bufferTargetIndexName = null;
    }

    /**
     * Declare that the index holds no documents, which makes every deletion this
     * indexer would buffer a no-op. Only safe for a caller that has just emptied the
     * index itself.
     *
     * @return void
     */
    public function assumeEmptyIndex(): void
    {
        $this->assumeEmptyIndex = true;
    }

    /**
     * How many buffered writes go out together; also the request batch size.
     */
    public function batchSize(): int
    {
        return max(1, (int)($this->batchSize ?? self::DEFAULT_BATCH_SIZE));
    }

    /**
     * Writes the buffer in a fixed order: stale variants by aggregate, then documents by
     * id, then additions. Buffering coalesces per document as it goes, so that this order
     * cannot reorder a deletion and an addition of the same document.
     */
    protected function writeBuffer(): void
    {
        $identifierDeletions = array_keys($this->bufferedIdentifierDeletions);
        $documentDeletions = array_keys($this->bufferedDocumentDeletions);
        $documents = array_values($this->bufferedDocuments);
        $targetIndexName = $this->bufferTargetIndexName;

        // Cleared up front so that a failing write cannot be retried into a loop by a
        // caller that flushes again in its error handling.
        $this->bufferedIdentifierDeletions = [];
        $this->bufferedDocumentDeletions = [];
        $this->bufferedDocuments = [];

        if ($identifierDeletions !== []) {
            $this->indexClient->deleteByIdentifiers($identifierDeletions, $targetIndexName);
        }
        if ($documentDeletions !== []) {
            $this->indexClient->deleteDocuments($documentDeletions, $targetIndexName);
        }
        if ($documents !== []) {
            $this->indexClient->addDocuments($documents, $targetIndexName);
        }
    }

    /**
     * A buffer belongs to one index. Writing to another one, as nodeindex:rebuild does,
     * first sends what was buffered for the previous.
     */
    protected function switchTargetIndex(?string $targetIndexName): void
    {
        if ($targetIndexName === $this->bufferTargetIndexName) {
            return;
        }
        $this->writeBuffer();
        $this->bufferTargetIndexName = $targetIndexName;
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function bufferDocument(array $document): void
    {
        $documentIdentifier = (string)$document['id'];

        // An addition supersedes a deletion of the same document buffered before it.
        unset($this->bufferedDocumentDeletions[$documentIdentifier]);
        $this->bufferedDocuments[$documentIdentifier] = $document;
    }

    protected function bufferDocumentDeletion(string $documentIdentifier): void
    {
        // A deletion supersedes an addition of the same document buffered before it.
        unset($this->bufferedDocuments[$documentIdentifier]);
        if (!$this->assumeEmptyIndex) {
            $this->bufferedDocumentDeletions[$documentIdentifier] = true;
        }
    }

    /**
     * Deletes every variant of a node aggregate, including those buffered before:
     * the deletions are written ahead of the additions, so a buffered variant would
     * otherwise survive a later reindex that no longer produces it.
     */
    protected function bufferIdentifierDeletion(string $nodeAggregateId): void
    {
        $prefix = $nodeAggregateId . '_';
        foreach (array_keys($this->bufferedDocuments) as $documentIdentifier) {
            if (str_starts_with((string)$documentIdentifier, $prefix)) {
                unset($this->bufferedDocuments[$documentIdentifier]);
            }
        }
        if (!$this->assumeEmptyIndex) {
            $this->bufferedIdentifierDeletions[$nodeAggregateId] = true;
        }
    }

    /**
     * Writes once the buffer has reached its size, and only ever between nodes: a node
     * whose deletions and additions were split across two writes would be missing from
     * the index in between.
     */
    protected function flushIfBufferIsFull(): void
    {
        $buffered = count($this->bufferedDocuments)
            + count($this->bufferedDocumentDeletions)
            + count($this->bufferedIdentifierDeletions);

        if ($buffered >= $this->batchSize()) {
            $this->writeBuffer();
        }
    }

    /**
     * Find the node's fulltext root, e.g. Neos.Neos:Document, by recursively looking at the configuration.
     *
     * @param Node $node
     * @return Node
     */
    protected function findFulltextRoot(Node $node): ?Node
    {
        if ($this->isFulltextRoot($node)) {
            return $node;
        }

        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $currentNode = $subgraph->findParentNode($node->aggregateId);
        while ($currentNode !== null) {
            if ($this->isFulltextRoot($currentNode)) {
                return $currentNode;
            }
            $currentNode = $subgraph->findParentNode($currentNode->aggregateId);
        }

        return null;
    }

    /**
     * Whether the node is configured as fulltext root. Copied from AbstractIndexerDriver::isFulltextRoot().
     *
     * @param Node $node
     * @return bool
     */
    protected function isFulltextRoot(Node $node): bool
    {
        $nodeType = $this->getNodeType($node);
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

    /**
     * Get the public URI of the node, or null for content nodes configured as fulltext
     * root (e.g. FAQ entries), which have no route of their own.
     *
     * @param Node $node
     * @return string|null
     */
    protected function getNodeUri(Node $node): ?string
    {
        if (!$this->isDocument($node)) {
            return null;
        }
        return $this->nodeLinkService->getNodeUri($node);
    }

    /**
     * Whether the node is a document and so has a URI of its own.
     *
     * Checked by type rather than by catching the routing exception, so that a document
     * which cannot be routed still fails loudly instead of being indexed without a URI.
     *
     * @param Node $node
     * @return bool
     */
    protected function isDocument(Node $node): bool
    {
        $nodeType = $this->getNodeType($node);
        return $nodeType !== null && $nodeType->isOfType('Neos.Neos:Document');
    }

    /**
     * @param Node $node
     * @return NodeType|null
     */
    protected function getNodeType(Node $node): ?NodeType
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        return $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
    }

    protected function truncateFulltext(array $fulltext): array
    {
        foreach ($fulltext as $key => $value) {
            if (is_string($value) && strlen($value) > $this->maxFulltextBytes) {
                $fulltext[$key] = mb_substr($value, 0, $this->maxFulltextBytes);
            }
        }
        return $fulltext;
    }

    protected function extractPropertiesAndFulltext(Node $node, array &$fulltextData, ?\Closure $nonIndexedPropertyErrorHandler = null): array
    {
        $result = parent::extractPropertiesAndFulltext($node, $fulltextData, $nonIndexedPropertyErrorHandler);
        if (!$this->enableFulltext) {
            return $result;
        }

        $subgraph = $this->contentSubgraphOf($node);
        $filter = FindChildNodesFilter::create(nodeTypes: 'Neos.Neos:Content,Neos.Neos:ContentCollection');
        $childNodes = $subgraph->findChildNodes($node->aggregateId, $filter);

        foreach ($childNodes as $childNode) {
            $this->enrichWithFulltextForContentNodes($childNode, $fulltextData, $filter);
        }

        return $result;
    }


    protected function enrichWithFulltextForContentNodes(Node $node, array &$fulltextData, FindChildNodesFilter $filter): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return;
        }
        if ($this->isFulltextEnabled($node)) {
            foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
                if (isset($propertyConfiguration['search']['fulltextExtractor'])) {
                    $this->extractFulltext($node, $propertyName, $propertyConfiguration['search']['fulltextExtractor'], $fulltextData);
                }
            }
        }
        // `search.fulltext.excludeChildren: true` skips the node's whole subtree.
        $searchConfiguration = $nodeType->hasConfiguration('search') ? $nodeType->getConfiguration('search') : [];
        if (($searchConfiguration['fulltext']['excludeChildren'] ?? false) === true) {
            return;
        }
        $childNodes = $this->contentSubgraphOf($node)->findChildNodes($node->aggregateId, $filter);

        foreach ($childNodes as $childNode) {
            $this->enrichWithFulltextForContentNodes($childNode, $fulltextData, $filter);
        }
    }

    /**
     * The subgraph a node was read from: its content is collected the way the node
     * itself was seen, so a hidden content element of an indexed page stays out of its
     * fulltext.
     */
    protected function contentSubgraphOf(Node $node): ContentSubgraphInterface
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)
            ->getContentGraph($node->workspaceName)
            ->getSubgraph($node->dimensionSpacePoint, $node->visibilityConstraints);
    }

    /**
     * Generate identifier for index document based on node identifier and dimensions.
     *
     * @param Node $node
     * @return string
     */
    protected function generateUniqueNodeIdentifier(Node $node): string
    {
        return $this->generateDocumentIdentifier($node->aggregateId->value, $node->dimensionSpacePoint->hash);
    }

    protected function generateDocumentIdentifier(string $nodeIdentifier, string $dimensionsHash): string
    {
        return $nodeIdentifier . '_' . $dimensionsHash;
    }
}
