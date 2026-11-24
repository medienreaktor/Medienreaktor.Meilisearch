<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * Reads the content repository's events after a given position and turns them into
 * indexing impacts. Built through {@see LiveEventReaderFactory}, the content repository's
 * extension point for services that need the event store.
 */
final readonly class LiveEventReader implements ContentRepositoryServiceInterface, LiveEvents
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private EventNormalizer $eventNormalizer,
    ) {
    }

    /**
     * At most $limit events after the position. Events that do not touch the index still
     * move the position forward, so the returned position is that of the last event read.
     */
    public function readAfter(CatchUpPosition $after, int $limit): LiveEventBatch
    {
        $stream = $this->eventStore->load(VirtualStreamName::all())
            ->withMinimumSequenceNumber($after->sequenceNumber->next())
            ->limit($limit);

        $impacts = [];
        $last = $after;
        $eventCount = 0;
        foreach ($stream as $eventEnvelope) {
            $eventCount++;
            $last = new CatchUpPosition($eventEnvelope->sequenceNumber, $eventEnvelope->event->id->value);
            $impact = IndexingImpact::fromEvent($this->eventNormalizer->denormalize($eventEnvelope->event));
            if ($impact !== null) {
                $impacts[] = $impact;
            }
        }

        return new LiveEventBatch($impacts, $last, $eventCount);
    }

    public function head(): CatchUpPosition
    {
        foreach ($this->eventStore->load(VirtualStreamName::all())->backwards()->limit(1) as $eventEnvelope) {
            return new CatchUpPosition($eventEnvelope->sequenceNumber, $eventEnvelope->event->id->value);
        }

        return CatchUpPosition::none();
    }

    /**
     * A position stored without an event id only has its sequence number to go by, which
     * a replaced event store still holds as long as it has as many events.
     */
    public function holds(CatchUpPosition $position): bool
    {
        if ($position->sequenceNumber->value === 0) {
            return true;
        }

        $stream = $this->eventStore->load(VirtualStreamName::all())
            ->withMinimumSequenceNumber($position->sequenceNumber)
            ->limit(1);
        foreach ($stream as $eventEnvelope) {
            return $eventEnvelope->sequenceNumber->equals($position->sequenceNumber)
                && ($position->eventId === null || $eventEnvelope->event->id->value === $position->eventId);
        }

        return false;
    }
}
