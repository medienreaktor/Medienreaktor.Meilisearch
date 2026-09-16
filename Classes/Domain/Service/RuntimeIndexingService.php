<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Search\Indexer\NodeIndexerInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Repairs structural changes which cannot be reconstructed from the published node alone.
 * @Flow\Scope("singleton")
 */
class RuntimeIndexingService
{
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
     * @var NodeIndexerInterface
     */
    protected $nodeIndexer;

    /** @var array<string, array> */
    protected $publishingStates = [];

    /** @var array<string, array{identifier: string, combination: array}> */
    protected $operations = [];

    /** @var array<string, array{identifier: string, combination: array}> */
    protected $subtrees = [];

    public function beforeNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        if ($targetWorkspace->getName() !== 'live') {
            return;
        }

        $states = [];
        foreach ($this->dimensionsService->getDimensionCombinationsForIndexing($node) as $combination) {
            $liveNode = $this->liveNode($node->getIdentifier(), $combination);
            $states[] = [
                'combination' => $combination,
                'path' => $liveNode?->getPath(),
                'root' => $liveNode === null ? null : $this->indexer()->findFulltextRoot($this->indexer()->requireTraversable($liveNode))?->getIdentifier(),
                'visible' => $liveNode !== null && $this->indexer()->isNodeAndAncestorsVisible($liveNode),
                'removedRoots' => $node->isRemoved() && $liveNode !== null
                    ? array_keys($this->indexer()->collectFulltextRoots($liveNode)) : [],
            ];
        }
        // Never retain live Node objects: publishing mutates their underlying NodeData.
        $this->publishingStates[spl_object_hash($node)] = $states;
    }

    public function afterNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        if ($targetWorkspace->getName() !== 'live') {
            return;
        }
        $key = spl_object_hash($node);
        $states = $this->publishingStates[$key] ?? [];
        unset($this->publishingStates[$key]);

        foreach ($states as $state) {
            $combination = $state['combination'];
            $liveNode = $this->liveNode($node->getIdentifier(), $combination);
            $newRoot = $liveNode === null ? null : $this->indexer()->findFulltextRoot($this->indexer()->requireTraversable($liveNode))?->getIdentifier();
            if ($state['root'] !== $newRoot) {
                $this->plan($state['root'], $combination);
                $this->plan($newRoot, $combination);
            }
            $visible = $liveNode !== null && $this->indexer()->isNodeAndAncestorsVisible($liveNode);
            if ($liveNode !== null && ($state['visible'] !== $visible || $state['path'] !== $liveNode->getPath())) {
                // Updated child paths are not queryable until persistence has flushed.
                $this->subtrees[$node->getIdentifier() . ':' . $this->dimensionsService->hash($combination)] = [
                    'identifier' => $node->getIdentifier(), 'combination' => $combination,
                ];
            }
            foreach ($state['removedRoots'] as $identifier) {
                $this->plan($identifier, $combination);
            }
        }
    }

    public function nodePathChanged(NodeInterface $node, string $oldPath, string $newPath, $recursion): void
    {
        if ($node->getContext()->getWorkspaceName() !== 'live' || $oldPath === $newPath || !NodeIndexer::isFulltextRoot($node)) {
            return;
        }
        foreach ($this->dimensionsService->getDimensionCombinationsForIndexing($node) as $combination) {
            $this->plan($node->getIdentifier(), $combination);
        }
    }

    public function flush(): void
    {
        $subtrees = $this->subtrees;
        $this->subtrees = [];
        foreach ($subtrees as $subtree) {
            $node = $this->liveNode($subtree['identifier'], $subtree['combination']);
            if ($node !== null) {
                foreach ($this->indexer()->collectFulltextRoots($node) as $root) {
                    $this->plan($root->getIdentifier(), $subtree['combination']);
                }
            }
        }
        $operations = $this->operations;
        $this->operations = [];
        foreach ($operations as $operation) {
            $this->indexer()->replaceVariants($operation['identifier'], [$operation['combination']]);
        }
        if ($operations !== []) {
            $this->nodeIndexer->flush();
        }
    }

    protected function liveNode(string $identifier, array $combination): ?NodeInterface
    {
        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => $combination,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
            'removedContentShown' => true,
        ]);
        $context->getFirstLevelNodeCache()->flush();
        return $context->getNodeByIdentifier($identifier);
    }

    protected function plan(?string $identifier, array $combination): void
    {
        if ($identifier !== null) {
            $this->operations[$identifier . ':' . $this->dimensionsService->hash($combination)] = [
                'identifier' => $identifier,
                'combination' => $combination,
            ];
        }
    }

    protected function indexer(): NodeIndexer
    {
        if (!$this->nodeIndexer instanceof NodeIndexer) {
            throw new \LogicException('Runtime repairs require the Meilisearch indexer or its queueing decorator.');
        }
        return $this->nodeIndexer;
    }
}
