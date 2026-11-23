<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * Paths of node aggregate ids, as the index uses them to place a document in the tree.
 *
 * Neos 9 nodes have no path of names, so a path lists aggregate ids from the content
 * repository root down: `/<rootId>/<siteId>/<pageId>`. A document records where it
 * hangs, not where it is: `__path` is the path of its parent, and `__parentPath` the
 * paths of the parent's ancestors, "/" first. The nodes below X are therefore those
 * with `__path` or `__parentPath` equal to the path of X - see belowFilter().
 *
 * @Flow\Scope("singleton")
 */
class IndexPathService
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * The path of the node itself, ending in its own aggregate id: the `__path` of its
     * children, and one of the `__parentPath` values of everything further below.
     */
    public function pathOf(Node $node): string
    {
        $ancestors = $this->contentRepositoryRegistry->subgraphForNode($node)->findAncestorNodes(
            $node->aggregateId,
            FindAncestorNodesFilter::create()
        );

        $aggregateIds = [$node->aggregateId->value];
        foreach ($ancestors as $ancestor) {
            $aggregateIds[] = $ancestor->aggregateId->value;
        }

        return self::pathFromAggregateIds(array_reverse($aggregateIds));
    }

    /**
     * A Meilisearch filter matching every document below the node, the node itself
     * excluded.
     */
    public function belowFilter(Node $node): string
    {
        return self::belowFilterForPath($this->pathOf($node));
    }

    /**
     * A Meilisearch filter matching the node's own document and every document below
     * it. Needs `__identifier` among the filterable attributes, as it is by default.
     */
    public function selfAndBelowFilter(Node $node): string
    {
        return '(__identifier = "' . addcslashes($node->aggregateId->value, '"\\') . '" OR '
            . substr(self::belowFilterForPath($this->pathOf($node)), 1);
    }

    /**
     * @param list<string> $aggregateIds outermost first
     */
    public static function pathFromAggregateIds(array $aggregateIds): string
    {
        return '/' . implode('/', $aggregateIds);
    }

    /**
     * A Meilisearch filter matching every document below the node with the given path.
     */
    public static function belowFilterForPath(string $path): string
    {
        $quoted = '"' . addcslashes($path, '"\\') . '"';

        return '(__parentPath = ' . $quoted . ' OR __path = ' . $quoted . ')';
    }
}
