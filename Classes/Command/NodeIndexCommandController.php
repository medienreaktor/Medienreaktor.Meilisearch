<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Cli\CommandController;
use Ramsey\Uuid\Exception\NodeException;

/**
 * CLI commands for index building and flushing
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController {
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
    #[Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Index all nodes.
     *
     * @return void
     * @throws Exception
     */
    public function buildCommand(): void {
        $this->indexClient->createIndex();

        $context = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => 'live']);
        // TODO 9.0 migration: !! MEGA DIRTY CODE! Ensure to rewrite this; by getting rid of LegacyContextStub.
        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'));
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());
        $rootNodeAggregate = $contentRepository->getContentGraph($workspace->workspaceName)->findRootNodeAggregateByType(NodeTypeName::fromString('Neos.Neos:Sites'));
        $subgraph = $contentRepository->getContentGraph($workspace->workspaceName)->
        getSubgraph(DimensionSpacePoint::fromLegacyDimensionArray($context->dimensions ?? []),
            VisibilityConstraints::default());
        $rootNode = $subgraph->findNodeById($rootNodeAggregate->nodeAggregateId);
        $this->traverseNodes($rootNode);

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes.');
    }

    /**
     * @param Node $currentNode
     * @throws Exception
     */
    protected function traverseNodes(Node $currentNode): void {
        if ($this->isFulltextRoot($currentNode)) {
            try {
                $this->nodeIndexer->indexNode($currentNode);
            } catch (NodeException|IndexingException $exception) {
                throw new Exception(sprintf('Error during indexing of node %s (%s)', $currentNode->findNodePath(), (string)$currentNode->nodeAggregateId), 1690288327, $exception);
            }
            $this->indexedNodes++;
        }

        $contentRepository = $this->contentRepositoryRegistry->get($currentNode->contentRepositoryId);
        $subgraph = $contentRepository->getContentSubgraph(WorkspaceName::forLive(), $currentNode->dimensionSpacePoint);
        $filter = FindChildNodesFilter::create();
        $childNodes = $subgraph->findChildNodes($currentNode->aggregateId, $filter);

        foreach ($childNodes as $childNode) {
            $this->traverseNodes($childNode);
        }
    }

    /**
     * Delete all documents from the index.
     */
    public function flushCommand(): void {
        $this->indexClient->deleteAllDocuments();
        $this->outputLine('All documents flushed from the index.');
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
}
