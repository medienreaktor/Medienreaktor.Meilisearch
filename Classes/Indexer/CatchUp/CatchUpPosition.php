<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Where an index stands in the content repository's events: the sequence number of the
 * last event it covers, and that event's id.
 *
 * The id is what tells an event store that was replaced - restored from a backup,
 * mirrored from another system - from the one the position was taken in: the same
 * sequence number then names a different event, or none.
 */
final readonly class CatchUpPosition
{
    public function __construct(
        public SequenceNumber $sequenceNumber,
        public ?string $eventId,
    ) {
    }

    /**
     * Before the first event, in an event store that holds none.
     */
    public static function none(): self
    {
        return new self(SequenceNumber::none(), null);
    }

    /**
     * Reads "<sequence number>:<event id>", or a bare sequence number as stored before the
     * id was recorded.
     */
    public static function fromStored(string $stored): self
    {
        [$sequenceNumber, $eventId] = array_pad(explode(':', $stored, 2), 2, null);
        if (!ctype_digit((string)$sequenceNumber)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a stored catch-up position.', $stored), 1795000103);
        }

        return new self(SequenceNumber::fromInteger((int)$sequenceNumber), $eventId === '' ? null : $eventId);
    }

    public function toStored(): string
    {
        return $this->eventId === null
            ? (string)$this->sequenceNumber->value
            : $this->sequenceNumber->value . ':' . $this->eventId;
    }
}
