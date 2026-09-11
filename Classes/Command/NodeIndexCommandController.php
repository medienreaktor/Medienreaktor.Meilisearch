<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\Flow\Annotations as Flow;
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
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

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


    /**
     * Create the index.
     *
     * @return void
     * @throws Exception
     */
    public function createIndexCommand(): void
    {
        $this->indexClient->createIndex();
        $this->outputLine('Index created successfully.');
    }

    /**
     * Delete the index.
     *
     * @return void
     * @throws Exception
     */
    public function deleteIndexCommand(): void
    {
        $this->indexClient->deleteIndex();
        $this->outputLine('The index has been deleted.');
    }

    /**
     * Index all nodes.
     *
     * Nodes are indexed as they are found, not collected first. The collection that
     * used to precede indexing held a hydrated node for every fulltext root of the
     * whole site in one array, and every node a Context hands out stays in that
     * Context's cache for the rest of the run - on a site of ten thousand documents
     * that alone outgrew a 3 GB limit before a single document had been sent. The
     * progress bar no longer knows its total up front; the count at the end is what
     * it always was.
     *
     * Memory is released once per write batch: the buffered documents are sent, then
     * the node caches and Doctrine's identity map are dropped and the cycle collector
     * runs. Safe in this command because it only reads nodes and writes to
     * Meilisearch - nothing it detaches is ever persisted again.
     *
     * @param bool $wait Return only once Meilisearch has finished indexing, and fail if it did not succeed
     * @param int $timeout How long to wait, in seconds; only meaningful together with --wait
     * @param bool $assumeEmptyIndex Skip deletions, which cannot match anything on an index the caller has just emptied
     * @return void
     * @throws Exception
     */
    public function buildCommand(bool $wait = false, int $timeout = self::DEFAULT_WAIT_TIMEOUT_IN_SECONDS, bool $assumeEmptyIndex = false): void
    {
        $this->indexClient->createIndex();

        if ($assumeEmptyIndex) {
            $this->nodeIndexer->assumeEmptyIndex();
        }

        $dimensionCombinations = $this->dimensionsService->getAllCombinations();

        $this->outputLine('Indexing nodes...');
        $this->output->progressStart();

        $releaseEvery = max(1, $this->nodeIndexer->batchSize());
        $sinceRelease = 0;

        foreach ($this->indexableNodes($dimensionCombinations) as $nodeToIndex) {
            $node = $nodeToIndex['node'];
            try {
                $this->nodeIndexer->indexNode(
                    $node,
                    null,
                    $nodeToIndex['indexAllDimensions'],
                    $nodeToIndex['indexFallbackDimensions'],
                    $nodeToIndex['targetDimensionCombination']
                );
            } catch (NodeException | IndexingException $exception) {
                throw new Exception(sprintf('Error during indexing of node %s (%s)', $node->findNodePath(), (string) $node->getNodeAggregateIdentifier()), 1690288327, $exception);
            }
            $this->indexedNodes++;
            $this->output->progressAdvance();

            if (++$sinceRelease >= $releaseEvery) {
                $this->releaseMemory($node);
                $sinceRelease = 0;
            }
        }

        $this->output->progressFinish();

        // Indexing buffers its writes, so nothing is guaranteed to have reached the
        // index until this returns.
        $this->nodeIndexer->flush();

        $this->outputLine('');
        $this->outputLine('Finished indexing %d nodes.', [$this->indexedNodes]);

        if ($wait) {
            $this->outputLine('Waiting up to %d seconds for Meilisearch to finish indexing...', [$timeout]);
            // The indexer's own client, which is the one holding the enqueued tasks.
            $this->nodeIndexer->getIndexClient()->waitForPendingTasks($timeout);
            $this->outputLine('Index is up to date.');
        }
    }

    /**
     * Every fulltext root of the site, one at a time, in the order the collection used
     * to list them: across all dimension combinations, or the plain live context when
     * the site has none.
     *
     * @param list<array> $dimensionCombinations
     * @return \Generator<int, array{node: NodeInterface&TraversableNodeInterface, indexAllDimensions: bool, indexFallbackDimensions: bool, targetDimensionCombination: array}>
     * @throws Exception
     */
    protected function indexableNodes(array $dimensionCombinations): \Generator
    {
        if ($dimensionCombinations === []) {
            $context = $this->contextFactory->create(['workspaceName' => 'live']);
            yield from $this->fulltextRootsBelow($this->traversableRootNode($context));
            return;
        }

        foreach ($dimensionCombinations as $dimensions) {
            $context = $this->contextFactory->create([
                'workspaceName' => 'live',
                'dimensions' => $dimensions
            ]);
            yield from $this->fulltextRootsBelow($this->traversableRootNode($context), false, false, $dimensions);
        }
    }

    /**
     * Recursively yields the given node, if it is a fulltext root, and every fulltext
     * root below it - depth first, a node before its children, which is the order
     * the collection this replaces produced.
     *
     * @param NodeInterface&TraversableNodeInterface $currentNode
     * @param bool $indexAllDimensions
     * @param bool $indexFallbackDimensions
     * @param array $targetDimensionCombination
     * @return \Generator<int, array{node: NodeInterface&TraversableNodeInterface, indexAllDimensions: bool, indexFallbackDimensions: bool, targetDimensionCombination: array}>
     * @throws Exception
     */
    protected function fulltextRootsBelow(
        NodeInterface $currentNode,
        bool $indexAllDimensions = true,
        bool $indexFallbackDimensions = true,
        array $targetDimensionCombination = []
    ): \Generator {
        if (self::isFulltextRoot($currentNode)) {
            yield [
                'node' => $currentNode,
                'indexAllDimensions' => $indexAllDimensions,
                'indexFallbackDimensions' => $indexFallbackDimensions,
                'targetDimensionCombination' => $targetDimensionCombination,
            ];
        }

        foreach ($currentNode->findChildNodes() as $childNode) {
            yield from $this->fulltextRootsBelow($this->nodeIndexer->requireTraversable($childNode), $indexAllDimensions, $indexFallbackDimensions, $targetDimensionCombination);
        }
    }

    /**
     * Sends what is buffered, then lets go of every node this run has touched.
     *
     * The walk holds one Context for a whole dimension combination, through the node
     * on every level of its recursion. The factory forgets that Context on its first
     * reset, so relying on getInstances() alone would flush it once and then never
     * again while it keeps caching every node the walk creates - it is reached here
     * through the node just indexed instead. The factory's own instances are the ones
     * the indexer creates for dimension fallbacks, and they are dropped as well.
     *
     * clearState() last among the reference cuts and the collector after it: the
     * entities point at each other, so nothing short of a cycle walk frees them.
     */
    protected function releaseMemory(NodeInterface $lastIndexedNode): void
    {
        $this->nodeIndexer->flush();

        $lastIndexedNode->getContext()->getFirstLevelNodeCache()->flush();
        foreach ($this->contextFactory->getInstances() as $context) {
            $context->getFirstLevelNodeCache()->flush();
        }
        $this->contextFactory->reset();
        $this->nodeFactory->reset();
        $this->persistenceManager->clearState();
        gc_collect_cycles();
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

    /**
     * The context hands back Model\NodeInterface, while collecting nodes needs the
     * traversal API Neos declares on a separate interface.
     *
     * @return NodeInterface&TraversableNodeInterface
     * @throws Exception
     */
    protected function traversableRootNode(Context $context)
    {
        return $this->nodeIndexer->requireTraversable($context->getRootNode());
    }
}
