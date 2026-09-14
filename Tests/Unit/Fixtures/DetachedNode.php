<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Exception\NodeException;

/**
 * A node without a content repository behind it: an identity, its own dimension values
 * and the context they imply, visibility, a parent and whether its type is a fulltext root.
 *
 * The indexer requires the concrete Node, since nothing else implements both halves of
 * the node contract, so this extends it and answers only what the indexer asks before it
 * hands over to the live workspace.
 */
final class DetachedNode extends Node
{
    private string $aggregateIdentifier;

    /**
     * @var array<string, array<int, string>>
     */
    private array $dimensionValues;

    private NodeType $type;

    private bool $visible;

    private ?TraversableNodeInterface $parent;

    /**
     * @param array<string, array<int, string>> $dimensionValues
     */
    public function __construct(
        string $aggregateIdentifier,
        array $dimensionValues,
        bool $fulltextRoot,
        bool $visible = true,
        ?TraversableNodeInterface $parent = null
    ) {
        // The parent constructor needs NodeData and a Context, which is exactly what
        // this double exists to do without.
        $this->aggregateIdentifier = $aggregateIdentifier;
        $this->dimensionValues = $dimensionValues;
        $this->type = new NodeType(
            $fulltextRoot ? 'Test:Document' : 'Test:Content',
            [],
            $fulltextRoot ? ['search' => ['fulltext' => ['isRoot' => true]]] : []
        );
        $this->visible = $visible;
        $this->parent = $parent;
    }

    public function getNodeAggregateIdentifier(): NodeAggregateIdentifier
    {
        return NodeAggregateIdentifier::fromString($this->aggregateIdentifier);
    }

    public function getDimensions(): array
    {
        return $this->dimensionValues;
    }

    public function getContext(): Context
    {
        return new Context(
            'live',
            new \DateTimeImmutable('2026-09-14T12:00:00+02:00'),
            $this->dimensionValues,
            array_map(static fn(array $values): string => $values[0], $this->dimensionValues),
            false,
            false,
            false
        );
    }

    public function getNodeType(): NodeType
    {
        return $this->type;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function findParentNode(): TraversableNodeInterface
    {
        if ($this->parent === null) {
            throw new NodeException('The detached node has no parent.', 1787000301);
        }

        return $this->parent;
    }
}
