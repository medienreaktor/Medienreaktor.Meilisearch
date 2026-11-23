<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\BufferingNodeIndexer;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingIndex;
use PHPUnit\Framework\TestCase;

/**
 * Meilisearch queues one task per write request. Indexing has to collapse a batch of
 * nodes into one deletion and one addition, however many nodes the batch holds.
 */
class NodeIndexerBufferingTest extends TestCase
{
    public function testABatchOfNodesCostsOneDeletionAndOneAddition(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        for ($i = 0; $i < 50; $i++) {
            $indexer->bufferNode('node' . $i, 'de', 'en');
        }
        $indexer->flush();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
        self::assertCount(50, $index->calls[0][1]);
        self::assertCount(100, $index->calls[1][1]);
    }

    public function testAFullBufferIsWrittenBetweenNodes(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 3);

        $indexer->bufferNode('a', 'de');
        self::assertSame([], $index->calledMethods());

        // One deletion and two documents reach the batch size.
        $indexer->bufferNode('b', 'de', 'en');
        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
    }

    public function testInsideBulkProcessingOnlyAFullBufferIsWritten(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->withBulkProcessing(function () use ($indexer) {
            $indexer->bufferNode('a', 'de');
            $indexer->flush();
        });
        self::assertSame([], $index->calledMethods());

        $indexer->shutdownObject();
        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
    }

    public function testReindexingANodeDropsItsVariantsBufferedBefore(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferNode('a', 'de', 'en');
        // Reindexed in the same batch after its English variant was hidden
        $indexer->bufferNode('a', 'de');
        $indexer->flush();

        self::assertSame([['id' => 'a_de']], $index->calls[1][1]);
    }

    public function testARemovalSupersedesABufferedAddition(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferNode('a', 'de');
        $indexer->bufferRemoval('a_de');
        $indexer->flush();

        self::assertSame(['deleteByIdentifiers', 'deleteDocuments'], $index->calledMethods());
    }

    public function testAnAssumedEmptyIndexIsNotAskedToDelete(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);
        $indexer->assumeEmptyIndex();

        $indexer->bufferNode('a', 'de');
        $indexer->bufferRemoval('b_de');
        $indexer->flush();

        self::assertSame(['addDocuments'], $index->calledMethods());
    }

    public function testNothingBufferedMeansNothingWritten(): void
    {
        $index = new RecordingIndex();
        (new BufferingNodeIndexer($index, 1000))->flush();

        self::assertSame([], $index->calledMethods());
    }
}
