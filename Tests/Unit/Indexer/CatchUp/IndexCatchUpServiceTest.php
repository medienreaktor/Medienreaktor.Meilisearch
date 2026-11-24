<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer\CatchUp;

use Medienreaktor\Meilisearch\Indexer\CatchUp\CatchUpPosition;
use Medienreaktor\Meilisearch\Indexer\CatchUp\IndexCatchUpOutcome;
use Medienreaktor\Meilisearch\Indexer\CatchUp\IndexCatchUpService;
use Medienreaktor\Meilisearch\Indexer\CatchUp\LiveEvents;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\ScriptedLiveEvents;
use Neos\Cache\Backend\TransientMemoryBackend;
use Neos\Cache\Frontend\StringFrontend;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Utility\Environment;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where the catch-up starts, depending on what it finds stored and what the event store
 * holds.
 */
class IndexCatchUpServiceTest extends TestCase
{
    private StringFrontend $cache;

    protected function setUp(): void
    {
        $backend = new TransientMemoryBackend();
        $this->cache = new StringFrontend('Medienreaktor_Meilisearch_CatchUpPosition', $backend);
        $backend->setCache($this->cache);
    }

    private function service(ScriptedLiveEvents $events, string $indexName = 'neos-a'): IndexCatchUpService
    {
        $service = new class ($events, $indexName) extends IndexCatchUpService {
            public function __construct(private readonly LiveEvents $events, private readonly string $name)
            {
            }

            protected function liveEvents(ContentRepositoryId $contentRepositoryId): LiveEvents
            {
                return $this->events;
            }

            protected function indexName(): string
            {
                return $this->name;
            }
        };

        $environment = $this->createStub(Environment::class);
        $environment->method('getPathToTemporaryDirectory')->willReturn(sys_get_temp_dir() . '/');
        $properties = ['positionCache' => $this->cache, 'environment' => $environment, 'logger' => new NullLogger()];
        $reflection = new \ReflectionClass(IndexCatchUpService::class);
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($service, $value);
        }

        return $service;
    }

    private static function cr(): ContentRepositoryId
    {
        return ContentRepositoryId::fromString('default');
    }

    public function testTheFirstRunOnlyRecordsWhereLiveStands(): void
    {
        $result = $this->service(new ScriptedLiveEvents(['a', 'b']))->catchUp(self::cr(), 100);

        self::assertSame(IndexCatchUpOutcome::Initialized, $result->outcome);
        self::assertSame('2:b', $this->cache->get('position_default_neos-a'));
    }

    public function testARunReadsTheEventsAfterItsPosition(): void
    {
        $this->cache->set('position_default_neos-a', '1:a');

        $result = $this->service(new ScriptedLiveEvents(['a', 'b', 'c']))->catchUp(self::cr(), 100);

        self::assertSame(IndexCatchUpOutcome::CaughtUp, $result->outcome);
        self::assertSame(2, $result->eventCount);
        self::assertSame('3:c', $this->cache->get('position_default_neos-a'));
    }

    /**
     * A restored or mirrored database: the stored event is not the one at that number,
     * and reading on from it would skip or misread events.
     */
    public function testAReplacedEventStoreResetsThePositionInsteadOfReadingOn(): void
    {
        $this->cache->set('position_default_neos-a', '2:b');

        $result = $this->service(new ScriptedLiveEvents(['x', 'y', 'z']))->catchUp(self::cr(), 100);

        self::assertSame(IndexCatchUpOutcome::Reset, $result->outcome);
        self::assertSame(2, $result->from?->value);
        self::assertSame(3, $result->to?->value);
        self::assertSame('3:z', $this->cache->get('position_default_neos-a'));
    }

    public function testAnOlderEventStoreResetsThePosition(): void
    {
        $this->cache->set('position_default_neos-a', '29100');

        $result = $this->service(new ScriptedLiveEvents(['a', 'b']))->catchUp(self::cr(), 100);

        self::assertSame(IndexCatchUpOutcome::Reset, $result->outcome);
    }

    /**
     * Positions stored per content repository only are taken over by the index that
     * runs first.
     */
    public function testAPositionStoredBeforePositionsWerePerIndexIsTakenOver(): void
    {
        $this->cache->set('position_default', '1');

        $result = $this->service(new ScriptedLiveEvents(['a', 'b']))->catchUp(self::cr(), 100);

        self::assertSame(IndexCatchUpOutcome::CaughtUp, $result->outcome);
        self::assertSame(1, $result->eventCount);
        self::assertFalse($this->cache->get('position_default'));
        self::assertSame('2:b', $this->cache->get('position_default_neos-a'));
    }

    /**
     * Each index has seen its own events, such as the two of a blue/green deployment.
     */
    public function testEachIndexKeepsItsOwnPosition(): void
    {
        $this->cache->set('position_default_neos-a', '3:c');
        $this->cache->set('position_default_neos-b', '1:a');

        $result = $this->service(new ScriptedLiveEvents(['a', 'b', 'c']), 'neos-b')->catchUp(self::cr(), 100);

        self::assertSame(2, $result->eventCount);
        self::assertSame('3:c', $this->cache->get('position_default_neos-b'));
    }

    public function testABuildRecordsTheEventItStartedAt(): void
    {
        $this->service(new ScriptedLiveEvents([]))->recordPosition(self::cr(), new CatchUpPosition(SequenceNumber::fromInteger(7), 'g'));

        self::assertSame('7:g', $this->cache->get('position_default_neos-a'));
    }
}
