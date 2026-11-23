<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Medienreaktor\Meilisearch\Domain\Service\IndexPathService;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * The index paths of a node, for filters written in Fusion, e.g.
 * `${IndexPath.below(site)}` for everything below the site. See IndexPathService.
 */
class IndexPathHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected IndexPathService $indexPathService;

    /**
     * The path of the node itself: the `__path` of its children
     */
    public function of(Node $node): string
    {
        return $this->indexPathService->pathOf($node);
    }

    /**
     * A filter matching every document below the node
     */
    public function below(Node $node): string
    {
        return $this->indexPathService->belowFilter($node);
    }

    /**
     * A filter matching the node's own document and every document below it
     */
    public function selfAndBelow(Node $node): string
    {
        return $this->indexPathService->selfAndBelowFilter($node);
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
