<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

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
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @var integer
     */
    protected $indexedNodes = 0;


    /**
     * Index all nodes.
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
     * Index all nodes.
     *
     * @return void
     * @throws Exception
     */
    public function buildCommand(): void
    {
        $this->indexClient->createIndex();

        $context = $this->contextFactory->create(['workspaceName' => 'live']);
        $rootNode = $context->getRootNode();
        $this->traverseNodes($rootNode);

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes.');
    }

    /**
     * @param NodeInterface $currentNode
     * @throws Exception
     */
    protected function traverseNodes(NodeInterface $currentNode): void
    {
        if (self::isFulltextRoot($currentNode)) {
            try {
                $this->nodeIndexer->indexNode($currentNode);
            }
            catch (NodeException|IndexingException $exception) {
                throw new Exception(sprintf('Error during indexing of node %s (%s)', $currentNode->findNodePath(), (string) $currentNode->getNodeAggregateIdentifier()), 1690288327, $exception);
            }
            $this->indexedNodes++;
        }

        foreach ($currentNode->findChildNodes() as $childNode) {
            $this->traverseNodes($childNode);
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
}
