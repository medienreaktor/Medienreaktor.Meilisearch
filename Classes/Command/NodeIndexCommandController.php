<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Medienreaktor\Meilisearch\Indexer\WorkspaceIndexer;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
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
     * @var WorkspaceIndexer
     */
    protected $workspaceIndexer;

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


    public function createIndexCommand(): void {
        $this->indexClient->createIndex();
        $this->outputLine('Created and update settings of index');
    }

    /**
     * Index all nodes.
     *
     * @return void
     * @throws Exception
     */
    public function buildCommand(): void {
        $this->indexClient->createIndex();

        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());

        $contentGraph = $contentRepository->getContentGraph($workspace->workspaceName);
        $rootNodeAggregate = $contentGraph->findRootNodeAggregateByType(NodeTypeName::fromString('Neos.Neos:Sites'));


        $this->output->progressStart();
        $this->indexedNodes = $this->workspaceIndexer->index($contentRepositoryId, $workspace->workspaceName, singleCallback: fn() => $this->output->progressAdvance());

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes.');
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
    protected function isFulltextRoot(NodeAggregate $node): bool {
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
