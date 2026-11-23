<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * The `__dimensionsHash` of a node or a set of dimension values, e.g. for filters
 * built outside the query builder: `${Dimensions.hashByNode(site)}`.
 */
class DimensionsHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * @param DimensionSpacePoint|array<string, mixed> $dimensionsValues
     */
    public function hash(DimensionSpacePoint|array $dimensionsValues): string
    {
        return $this->dimensionsService->hash($dimensionsValues);
    }

    public function hashByNode(Node $node): string
    {
        return $this->dimensionsService->hashByNode($node);
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
