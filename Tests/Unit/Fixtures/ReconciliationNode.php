<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;

/** A detached rootline and subtree for reconciliation planning, without CR persistence. */
final class ReconciliationNode extends Node
{
    private string $identifier;
    private string $path;
    private bool $visible;
    private NodeType $type;
    private ?NodeInterface $parentNode;
    private ?\Closure $parentCallback;

    /** @var NodeInterface[] */
    private array $children;

    /** @param NodeInterface[] $children */
    public function __construct(
        string $identifier,
        string $path,
        bool $visible,
        bool $fulltextRoot,
        ?NodeInterface $parent = null,
        ?callable $parentCallback = null,
        array $children = []
    ) {
        // Like DetachedNode, only implement the boundaries consulted by the service.
        $this->identifier = $identifier;
        $this->path = $path;
        $this->visible = $visible;
        $this->type = new NodeType('Test:Scheduled', [], $fulltextRoot ? ['search' => ['fulltext' => ['isRoot' => true]]] : []);
        $this->parentNode = $parent;
        $this->parentCallback = $parentCallback === null ? null : \Closure::fromCallable($parentCallback);
        $this->children = $children;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function isRemoved(): bool
    {
        return false;
    }

    public function isAccessible(): bool
    {
        return true;
    }

    public function getNodeType(): NodeType
    {
        return $this->type;
    }

    public function getParent(): ?NodeInterface
    {
        return $this->parentCallback === null ? $this->parentNode : ($this->parentCallback)();
    }

    public function getChildNodes($nodeTypeFilter = null, $limit = null, $offset = null): array
    {
        return $this->children;
    }
}
