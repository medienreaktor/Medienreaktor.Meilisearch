<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Medienreaktor\Meilisearch\Domain\Service\IndexPathService;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * The paths a node is indexed under, for indexing configuration and for building
 * filters in Fusion, e.g. `__parentPath = "${IndexPath.of(site)}"`.
 */
class IndexPathHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected IndexPathService $indexPathService;

    /**
     * The node's own path, as indexed in `__path`
     */
    public function of(Node $node): string
    {
        return $this->indexPathService->pathOf($node);
    }

    /**
     * The paths of all the node's ancestors, as indexed in `__parentPath`
     *
     * @return list<string>
     */
    public function parents(Node $node): array
    {
        return $this->indexPathService->parentPathsOf($node);
    }

    /**
     * @param string $methodName
     * @return bool
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
