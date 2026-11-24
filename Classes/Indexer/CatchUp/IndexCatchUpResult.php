<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\EventStore\Model\Event\SequenceNumber;

final readonly class IndexCatchUpResult
{
    private function __construct(
        public IndexCatchUpOutcome $outcome,
        public ?SequenceNumber $from = null,
        public ?SequenceNumber $to = null,
        public int $eventCount = 0,
        public int $documentCount = 0,
        public bool $moreEventsPending = false,
    ) {
    }

    public static function alreadyRunning(): self
    {
        return new self(IndexCatchUpOutcome::AlreadyRunning);
    }

    public static function initialized(SequenceNumber $position): self
    {
        return new self(IndexCatchUpOutcome::Initialized, to: $position);
    }

    public static function reset(SequenceNumber $from, SequenceNumber $position): self
    {
        return new self(IndexCatchUpOutcome::Reset, $from, $position);
    }

    public static function caughtUp(SequenceNumber $from, LiveEventBatch $batch, int $documentCount, bool $moreEventsPending): self
    {
        return new self(IndexCatchUpOutcome::CaughtUp, $from, $batch->last->sequenceNumber, $batch->eventCount, $documentCount, $moreEventsPending);
    }
}
