<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer\CatchUp;

use Medienreaktor\Meilisearch\Indexer\CatchUp\CatchUpPosition;
use Medienreaktor\Meilisearch\Indexer\CatchUp\LiveEventReader;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\EventStore\Adapter\InMemoryEventStore;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * How the catch-up tells the event store its position was taken in from one that
 * replaced it.
 */
class LiveEventReaderTest extends TestCase
{
    /**
     * @param list<string> $eventIds
     */
    private function storeWith(array $eventIds): LiveEventReader
    {
        $store = new InMemoryEventStore(new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-24 12:00:00');
            }
        });
        $events = array_map(
            static fn (string $id): Event => new Event(EventId::fromString(self::uuid($id)), EventType::fromString('NodePropertiesWereSet'), EventData::fromString('{}')),
            $eventIds
        );
        if ($events !== []) {
            $store->commit(StreamName::fromString('test'), Events::fromArray($events), ExpectedVersion::ANY());
        }
        // Neither head() nor holds() reads an event's payload.
        $normalizer = (new \ReflectionClass(EventNormalizer::class))->newInstanceWithoutConstructor();

        return new LiveEventReader($store, $normalizer);
    }

    /**
     * Event ids are UUIDs; the tests name events by a letter.
     */
    private static function uuid(string $letter): string
    {
        return sprintf('00000000-0000-4000-8000-%012x', ord($letter));
    }

    private static function position(int $sequenceNumber, ?string $eventLetter): CatchUpPosition
    {
        return new CatchUpPosition(SequenceNumber::fromInteger($sequenceNumber), $eventLetter === null ? null : self::uuid($eventLetter));
    }

    public function testTheHeadIsTheNewestEvent(): void
    {
        $head = $this->storeWith(['a', 'b', 'c'])->head();

        self::assertSame(3, $head->sequenceNumber->value);
        self::assertSame(self::uuid('c'), $head->eventId);
    }

    public function testAnEmptyStoreHasNoHead(): void
    {
        self::assertSame(0, $this->storeWith([])->head()->sequenceNumber->value);
    }

    public function testTheStoreHoldsThePositionItGaveOut(): void
    {
        self::assertTrue($this->storeWith(['a', 'b', 'c'])->holds(self::position(2, 'b')));
    }

    /**
     * A restored or mirrored database numbers its own events: the same sequence number
     * names another one.
     */
    public function testAReplacedStoreDoesNotHoldThePosition(): void
    {
        self::assertFalse($this->storeWith(['x', 'y', 'z'])->holds(self::position(2, 'b')));
    }

    /**
     * Restoring an older database leaves the position beyond its newest event.
     */
    public function testAShorterStoreDoesNotHoldThePosition(): void
    {
        self::assertFalse($this->storeWith(['a', 'b'])->holds(self::position(3, 'c')));
    }

    public function testAPositionWithoutEventIdOnlyNeedsItsSequenceNumber(): void
    {
        $reader = $this->storeWith(['a', 'b']);

        self::assertTrue($reader->holds(self::position(2, null)));
        self::assertFalse($reader->holds(self::position(3, null)));
    }

    public function testThePositionBeforeAllEventsIsAlwaysHeld(): void
    {
        self::assertTrue($this->storeWith([])->holds(CatchUpPosition::none()));
    }
}
