<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\EventStore\Model\Event\SequenceNumber;

final readonly class LiveEventBatch
{
    /**
     * @param list<IndexingImpact> $impacts
     */
    public function __construct(
        public array $impacts,
        public SequenceNumber $lastSequenceNumber,
        public int $eventCount,
    ) {
    }
}
