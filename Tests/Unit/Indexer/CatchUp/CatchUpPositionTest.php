<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer\CatchUp;

use Medienreaktor\Meilisearch\Indexer\CatchUp\CatchUpPosition;
use Neos\EventStore\Model\Event\SequenceNumber;
use PHPUnit\Framework\TestCase;

class CatchUpPositionTest extends TestCase
{
    public function testAPositionIsStoredWithItsEventId(): void
    {
        $position = CatchUpPosition::fromStored((new CatchUpPosition(SequenceNumber::fromInteger(42), 'event-42'))->toStored());

        self::assertSame(42, $position->sequenceNumber->value);
        self::assertSame('event-42', $position->eventId);
    }

    /**
     * Positions stored before the event id was recorded are a bare sequence number.
     */
    public function testABareSequenceNumberIsAPositionWithoutEventId(): void
    {
        $position = CatchUpPosition::fromStored('29100');

        self::assertSame(29100, $position->sequenceNumber->value);
        self::assertNull($position->eventId);
        self::assertSame('29100', $position->toStored());
    }

    public function testSomethingElseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CatchUpPosition::fromStored('yesterday');
    }
}
