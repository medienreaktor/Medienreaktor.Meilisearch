<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Ramsey\Uuid\Exception\NodeException;

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
     * @var integer
     */
    protected $indexedNodes = 0;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Index all nodes.
     *
     * @return void
     * @throws Exception
     */
    public function buildCommand(): void
    {
        $this->indexClient->createIndex();

        $context = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => 'live']);
        // TODO 9.0 migration: !! MEGA DIRTY CODE! Ensure to rewrite this; by getting rid of LegacyContextStub.
        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $workspace = $contentRepository->findWorkspaceByName(\Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName::fromString($context->workspaceName ?? 'live'));
        $rootNodeAggregate = $contentRepository->getContentGraph($workspace->workspaceName)->findRootNodeAggregateByType(\Neos\ContentRepository\Core\NodeType\NodeTypeName::fromString('Neos.Neos:Sites'));
        $subgraph = $contentRepository->getContentGraph($workspace->workspaceName)->getSubgraph(\Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint::fromLegacyDimensionArray($context->dimensions ?? []), $context->invisibleContentShown ? \Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints::withoutRestrictions() : \Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints::default());
        $rootNode = $subgraph->findNodeById($rootNodeAggregate->nodeAggregateId);
        $this->traverseNodes($rootNode);

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes.');
    }

    /**
     * @param Node $currentNode
     * @throws Exception
     */
    protected function traverseNodes(Node $currentNode): void
    {
        if ($this->isFulltextRoot($currentNode)) {
            try {
                $this->nodeIndexer->indexNode($currentNode);
            }
            catch (NodeException|IndexingException $exception) {
                throw new Exception(sprintf('Error during indexing of node %s (%s)', $currentNode->findNodePath(), (string) $currentNode->nodeAggregateId), 1690288327, $exception);
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
     * @param Node $node
     * @return bool
     */
    protected function isFulltextRoot(Node $node): bool
    {
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
}
