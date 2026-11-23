<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * The paths a node is indexed under, as `__path` and `__parentPath`.
 *
 * Neos 9 nodes have no path of names, so the path is built from node aggregate ids,
 * from the content repository root down to and including the node itself:
 * `/<rootId>/<siteId>/<pageId>`. `__parentPath` holds every ancestor's path, the direct
 * parent's included, so that one filter on it finds a whole subtree:
 * `__parentPath = "<path of X>"` matches every node below X.
 *
 * @Flow\Scope("singleton")
 */
class IndexPathService
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * The path the node is indexed under as `__path`
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
     * The paths of all ancestors, outermost first, as indexed in `__parentPath`.
     * Begins with "/", as on the Neos 8 line.
     *
     * @return list<string>
     */
    public function parentPathsOf(Node $node): array
    {
        return self::parentPathsFromPath($this->pathOf($node));
    }

    /**
     * @param list<string> $aggregateIds outermost first
     */
    public static function pathFromAggregateIds(array $aggregateIds): string
    {
        return '/' . implode('/', $aggregateIds);
    }

    /**
     * Every proper prefix of the given path, "/" first; empty for an empty path.
     *
     * @return list<string>
     */
    public static function parentPathsFromPath(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment) => $segment !== ''));
        if ($segments === []) {
            return [];
        }
        array_pop($segments);

        $parentPaths = ['/'];
        $currentPath = '';
        foreach ($segments as $segment) {
            $currentPath .= '/' . $segment;
            $parentPaths[] = $currentPath;
        }

        return $parentPaths;
    }
}
