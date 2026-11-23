<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

/**
 * The dimensions hash documents are indexed with as `__dimensionsHash`: the hash of the
 * dimension space point the node was read in. Content shining through from a fallback
 * is read in, and so hashed with, the dimension space point it shows up in.
 *
 * @Flow\Scope("singleton")
 */
class DimensionsService
{
    /**
     * @param DimensionSpacePoint|array<string, string|int|array<int, string|int>> $dimensions
     *     A dimension space point, its coordinates (['language' => 'de']), or a Neos 8
     *     style dimension array (['language' => ['de', 'en']]), of which each first value
     *     counts
     */
    public function hash(DimensionSpacePoint|array $dimensions): string
    {
        if ($dimensions instanceof DimensionSpacePoint) {
            return $dimensions->hash;
        }

        $coordinates = [];
        foreach ($dimensions as $dimensionName => $value) {
            if (is_array($value)) {
                $value = reset($value);
                if ($value === false) {
                    continue;
                }
            }
            $coordinates[(string)$dimensionName] = (string)$value;
        }

        return DimensionSpacePoint::fromArray($coordinates)->hash;
    }

    public function hashByNode(Node $node): string
    {
        return $node->dimensionSpacePoint->hash;
    }
}
