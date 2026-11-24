<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Indexer\CatchUp\CatchUpPosition;
use Medienreaktor\Meilisearch\Indexer\CatchUp\LiveEventBatch;
use Medienreaktor\Meilisearch\Indexer\CatchUp\LiveEvents;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * An event store of numbered events with ids, as the catch-up sees one. Reading after a
 * position returns the events after it, without indexing impacts.
 */
final class ScriptedLiveEvents implements LiveEvents
{
    /**
     * @param list<string> $eventIds The id of event 1, 2, ...
     */
    public function __construct(public array $eventIds)
    {
    }

    public function head(): CatchUpPosition
    {
        $count = count($this->eventIds);

        return $count === 0 ? CatchUpPosition::none() : new CatchUpPosition(SequenceNumber::fromInteger($count), $this->eventIds[$count - 1]);
    }

    public function holds(CatchUpPosition $position): bool
    {
        $index = $position->sequenceNumber->value;
        if ($index === 0) {
            return true;
        }

        return isset($this->eventIds[$index - 1])
            && ($position->eventId === null || $this->eventIds[$index - 1] === $position->eventId);
    }

    public function readAfter(CatchUpPosition $after, int $limit): LiveEventBatch
    {
        $read = array_slice($this->eventIds, $after->sequenceNumber->value, $limit);
        $lastNumber = $after->sequenceNumber->value + count($read);
        $last = $read === [] ? $after : new CatchUpPosition(SequenceNumber::fromInteger($lastNumber), $read[count($read) - 1]);

        return new LiveEventBatch([], $last, count($read));
    }
}
