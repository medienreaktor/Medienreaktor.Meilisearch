<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

final readonly class LiveEventBatch
{
    /**
     * @param list<IndexingImpact> $impacts
     */
    public function __construct(
        public array $impacts,
        public CatchUpPosition $last,
        public int $eventCount,
    ) {
    }
}
