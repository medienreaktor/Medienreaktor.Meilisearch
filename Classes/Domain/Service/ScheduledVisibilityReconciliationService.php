<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Search\Indexer\NodeIndexerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryInterface;

/**
 * Reconciles nodes whose scheduled visibility changed after the last index snapshot.
 *
 * The service deliberately talks to NodeIndexerInterface. Installations using a
 * queueing decorator can therefore enqueue the planned operations, while the
 * default Medienreaktor indexer performs them synchronously.
 *
 * @Flow\Scope("singleton")
 */
class ScheduledVisibilityReconciliationService
{
    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeIndexerInterface
     */
    protected $nodeIndexer;

    /**
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * Reconcile scheduled visibility transitions in the given interval.
     *
     * When $all is true, all nodes carrying a scheduled visibility boundary
     * are inspected. This is intended for initial enablement and recovery after
     * an outage longer than the configured lookback.
     *
     * @return array{scheduledNodes: int, indexOperations: int, removeOperations: int}
     */
    public function reconcile(
        \DateTimeInterface $since,
        \DateTimeInterface $until,
        bool $all = false,
        bool $dryRun = false
    ): array {
        if ($since >= $until) {
            throw new \InvalidArgumentException('The reconciliation start must be earlier than its end.');
        }

        $workspaceQuery = $this->workspaceRepository->createQuery();
        $workspaceQuery->matching($workspaceQuery->equals('name', 'live'));
        $liveWorkspace = $workspaceQuery->execute()->getFirst();
        if (!$liveWorkspace instanceof Workspace) {
            throw new Exception('The live workspace could not be found.', 1784218801);
        }

        $scheduledNodes = $this->findScheduledNodes($liveWorkspace, $since, $until, $all);
        $operations = [];

        /** @var NodeData $nodeData */
        foreach ($scheduledNodes as $nodeData) {
            $this->collectOperationsForScheduledNode($nodeData, $until, $operations);
        }

        $indexOperations = 0;
        $removeOperations = 0;

        foreach ($operations as $operation) {
            if ($operation['action'] === 'index') {
                $indexOperations++;
                if (!$dryRun) {
                    $this->nodeIndexer->indexNode($operation['node'], 'live');
                }
                continue;
            }

            $removeOperations++;
            if (!$dryRun) {
                $this->nodeIndexer->removeNode($operation['node']);
            }
        }

        if (!$dryRun) {
            $this->nodeIndexer->flush();
        }

        return [
            'scheduledNodes' => count($scheduledNodes),
            'indexOperations' => $indexOperations,
            'removeOperations' => $removeOperations,
        ];
    }

    /**
     * @return NodeData[]
     */
    protected function findScheduledNodes(
        Workspace $workspace,
        \DateTimeInterface $since,
        \DateTimeInterface $until,
        bool $all
    ): array {
        $query = $this->nodeDataRepository->createQuery();

        if ($all) {
            $scheduledConstraint = $query->logicalOr([
                $query->logicalNot($query->equals('hiddenBeforeDateTime', null)),
                $query->logicalNot($query->equals('hiddenAfterDateTime', null)),
            ]);
        } else {
            $scheduledConstraint = $query->logicalOr([
                $query->logicalAnd([
                    $query->greaterThanOrEqual('hiddenBeforeDateTime', $since),
                    $query->lessThanOrEqual('hiddenBeforeDateTime', $until),
                ]),
                $query->logicalAnd([
                    $query->greaterThanOrEqual('hiddenAfterDateTime', $since),
                    $query->lessThanOrEqual('hiddenAfterDateTime', $until),
                ]),
            ]);
        }

        $query->matching($query->logicalAnd([
            $query->equals('workspace', $workspace),
            $query->equals('removed', false),
            $scheduledConstraint,
        ]));
        $query->setOrderings([
            'path' => QueryInterface::ORDER_ASCENDING,
            'dimensionsHash' => QueryInterface::ORDER_ASCENDING,
        ]);

        return $query->execute()->toArray();
    }

    /**
     * @param array<string, array{action: string, node: NodeInterface}> $operations
     */
    protected function collectOperationsForScheduledNode(
        NodeData $nodeData,
        \DateTimeInterface $now,
        array &$operations
    ): void {
        $contextProperties = [
            'workspaceName' => 'live',
            'currentDateTime' => $now,
            'dimensions' => $nodeData->getDimensionValues(),
            'removedContentShown' => false,
        ];
        $maintenanceContext = $this->contextFactory->create(array_merge($contextProperties, [
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]));
        $visibleContext = $this->contextFactory->create(array_merge($contextProperties, [
            'invisibleContentShown' => false,
            'inaccessibleContentShown' => false,
        ]));

        $scheduledNode = $maintenanceContext->getNode($nodeData->getPath());
        if (!$scheduledNode instanceof NodeInterface) {
            return;
        }

        $affectedRoots = [];
        $closestRoot = $this->findClosestFulltextRoot($scheduledNode);
        if ($closestRoot instanceof NodeInterface) {
            $affectedRoots[$this->operationKey($closestRoot)] = $closestRoot;
        }
        $this->collectFulltextRoots($scheduledNode, $affectedRoots);

        foreach ($affectedRoots as $key => $invisibleRoot) {
            if ($this->isNodeAndAncestorsVisible($invisibleRoot)) {
                $visibleRoot = $visibleContext->getNode($invisibleRoot->getPath());
                if (!$visibleRoot instanceof NodeInterface || !self::isFulltextRoot($visibleRoot)) {
                    continue;
                }
                $operations[$key] = ['action' => 'index', 'node' => $visibleRoot];
                continue;
            }

            foreach ($this->getRemovalVariants($invisibleRoot, $now) as $removalVariant) {
                $operations[$this->operationKey($removalVariant)] = [
                    'action' => 'remove',
                    'node' => $removalVariant,
                ];
            }
        }
    }

    /**
     * @param array<string, NodeInterface> $roots
     */
    protected function collectFulltextRoots(NodeInterface $node, array &$roots): void
    {
        if (self::isFulltextRoot($node)) {
            $roots[$this->operationKey($node)] = $node;
        }

        foreach ($node->getChildNodes() as $childNode) {
            $this->collectFulltextRoots($childNode, $roots);
        }
    }

    protected function findClosestFulltextRoot(NodeInterface $node): ?NodeInterface
    {
        $currentNode = $node;
        while (true) {
            if (self::isFulltextRoot($currentNode)) {
                return $currentNode;
            }
            $currentNode = $this->getParentNode($currentNode);
            if (!$currentNode instanceof NodeInterface) {
                return null;
            }
        }
    }

    protected function isNodeAndAncestorsVisible(NodeInterface $node): bool
    {
        $currentNode = $node;
        while (true) {
            if ($currentNode->isRemoved() || !$currentNode->isVisible() || !$currentNode->isAccessible()) {
                return false;
            }
            $currentNode = $this->getParentNode($currentNode);
            if (!$currentNode instanceof NodeInterface) {
                return true;
            }
        }
    }

    protected function getParentNode(NodeInterface $node): ?NodeInterface
    {
        return $node->getParent();
    }

    /**
     * Expand a removal to every target dimension which can currently fall
     * back to this node variant. These are the document IDs created by the
     * default indexNode(..., indexAllDimensions: true) path.
     *
     * @return NodeInterface[]
     */
    protected function getRemovalVariants(NodeInterface $node, \DateTimeInterface $now): array
    {
        $dimensionCombinations = $this->dimensionsService->getDimensionCombinationsForIndexing($node);
        if ($dimensionCombinations === []) {
            return [$node];
        }

        $variants = [];
        foreach ($dimensionCombinations as $dimensionCombination) {
            $context = $this->contextFactory->create([
                'workspaceName' => 'live',
                'currentDateTime' => $now,
                'dimensions' => $dimensionCombination,
                'invisibleContentShown' => true,
                'removedContentShown' => false,
                'inaccessibleContentShown' => true,
            ]);
            $variant = $context->getNodeByIdentifier($node->getIdentifier());
            if ($variant instanceof NodeInterface) {
                $variants[$this->operationKey($variant)] = $variant;
            }
        }

        return $variants !== [] ? array_values($variants) : [$node];
    }

    protected function operationKey(NodeInterface $node): string
    {
        return $node->getIdentifier() . ':' . $this->dimensionsService->hashByNode($node);
    }

    protected static function isFulltextRoot(NodeInterface $node): bool
    {
        $searchSettings = $node->getNodeType()->getConfiguration('search');
        return is_array($searchSettings)
            && isset($searchSettings['fulltext']['isRoot'])
            && $searchSettings['fulltext']['isRoot'] === true;
    }
}
