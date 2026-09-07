<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

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

        $this->outputLine('Collecting indexable nodes...');
        $nodes = [];

        if ($dimensionCombinations === []) {
            $context = $this->contextFactory->create(['workspaceName' => 'live']);
            $this->collectNodes($this->traversableRootNode($context), $nodes);
        } else {
            foreach ($dimensionCombinations as $dimensions) {
                $context = $this->contextFactory->create([
                    'workspaceName' => 'live',
                    'dimensions' => $dimensions
                ]);
                $this->collectNodes($this->traversableRootNode($context), $nodes, false, false, $dimensions);
            }
        }

        $total = count($nodes);

        $this->outputLine('Indexing %d nodes...', [$total]);
        $this->output->progressStart($total);

        foreach ($nodes as $nodeToIndex) {
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
     * Recursively collects all fulltext root nodes into a flat array so that
     * the total count is known before indexing begins.
     *
     * @param NodeInterface&TraversableNodeInterface $currentNode
     * @param list<array{node: NodeInterface&TraversableNodeInterface, indexAllDimensions: bool, indexFallbackDimensions: bool, targetDimensionCombination: array}> $nodes
     * @param bool $indexAllDimensions
     * @param bool $indexFallbackDimensions
     * @param array $targetDimensionCombination
     */
    protected function collectNodes(
        NodeInterface $currentNode,
        array &$nodes,
        bool $indexAllDimensions = true,
        bool $indexFallbackDimensions = true,
        array $targetDimensionCombination = []
    ): void {
        if (self::isFulltextRoot($currentNode)) {
            $nodes[] = [
                'node' => $currentNode,
                'indexAllDimensions' => $indexAllDimensions,
                'indexFallbackDimensions' => $indexFallbackDimensions,
                'targetDimensionCombination' => $targetDimensionCombination,
            ];
        }

        foreach ($currentNode->findChildNodes() as $childNode) {
            $this->collectNodes($this->nodeIndexer->requireTraversable($childNode), $nodes, $indexAllDimensions, $indexFallbackDimensions, $targetDimensionCombination);
        }
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
