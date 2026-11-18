<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * Reads the content repository's events after a given position and turns them into
 * indexing impacts. Built through {@see LiveEventReaderFactory}, the content repository's
 * extension point for services that need the event store.
 */
final readonly class LiveEventReader implements ContentRepositoryServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private EventNormalizer $eventNormalizer,
    ) {
    }

    /**
     * At most $limit events after $after. Events that do not touch the index still move the
     * position forward, so the returned position is that of the last event read.
     */
    public function readAfter(SequenceNumber $after, int $limit): LiveEventBatch
    {
        $stream = $this->eventStore->load(VirtualStreamName::all())
            ->withMinimumSequenceNumber($after->next())
            ->limit($limit);

        $impacts = [];
        $lastSequenceNumber = $after;
        $eventCount = 0;
        foreach ($stream as $eventEnvelope) {
            $eventCount++;
            $lastSequenceNumber = $eventEnvelope->sequenceNumber;
            $impact = IndexingImpact::fromEvent($this->eventNormalizer->denormalize($eventEnvelope->event));
            if ($impact !== null) {
                $impacts[] = $impact;
            }
        }

        return new LiveEventBatch($impacts, $lastSequenceNumber, $eventCount);
    }

    public function head(): SequenceNumber
    {
        foreach ($this->eventStore->load(VirtualStreamName::all())->backwards()->limit(1) as $eventEnvelope) {
            return $eventEnvelope->sequenceNumber;
        }

        return SequenceNumber::none();
    }
}
