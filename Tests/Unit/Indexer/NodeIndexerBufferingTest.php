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

    /**
     * The defect, stated directly. A count that is the same for ten nodes and for a
     * hundred cannot have been produced per node, and unlike a fixed expected number
     * it cannot be satisfied by tuning a constant until it matches.
     */
    public function testWriteCountDoesNotGrowWithTheNumberOfNodes(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $this->bufferNodes($indexer, 10);
        $indexer->flush();
        $forTenNodes = $index->calledMethods();

        $index->calls = [];
        $this->bufferNodes($indexer, 100, 10);
        $indexer->flush();

        self::assertSame($forTenNodes, $index->calledMethods());
    }

    public function testNothingIsWrittenBeforeFlush(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $this->bufferNodes($indexer, 50);

        self::assertSame([], $index->calls);
    }

    public function testFlushingTwiceDoesNotWriteTheBufferTwice(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);
        $this->bufferNodes($indexer, 5);
        $indexer->flush();
        $index->calls = [];

        $indexer->flush();

        self::assertSame([], $index->calls);
    }

    public function testTheSameVariantBufferedTwiceIsWrittenOnce(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocument(['id' => 'a_de', 'title' => 'first']);
        $indexer->bufferDocument(['id' => 'a_de', 'title' => 'second']);
        $indexer->flush();

        self::assertSame([['id' => 'a_de', 'title' => 'second']], $index->argumentOf('addDocuments'));
    }

    /**
     * The buffer is replayed in a fixed order - deletions, then additions - so a
     * document touched twice in one batch has to end up in the group its last
     * operation put it in. Otherwise a hide following an index would replay as an
     * index following a hide, and leave the document in the index.
     */
    public function testADeletionSupersedesAnEarlierAdditionOfTheSameDocument(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocument(['id' => 'a_de']);
        $indexer->bufferDocumentDeletion('a_de');
        $indexer->flush();

        self::assertSame(['deleteDocuments'], $index->calledMethods());
        self::assertSame(['a_de'], $index->argumentOf('deleteDocuments'));
    }

    public function testAnAdditionSupersedesAnEarlierDeletionOfTheSameDocument(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocumentDeletion('a_de');
        $indexer->bufferDocument(['id' => 'a_de']);
        $indexer->flush();

        self::assertSame(['addDocuments'], $index->calledMethods());
        self::assertSame([['id' => 'a_de']], $index->argumentOf('addDocuments'));
    }

    public function testDeletionsAndAdditionsOfDifferentDocumentsBothSurvive(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocumentDeletion('gone_de');
        $indexer->bufferDocument(['id' => 'kept_de']);
        $indexer->flush();

        self::assertSame(['deleteDocuments', 'addDocuments'], $index->calledMethods());
        self::assertSame(['gone_de'], $index->argumentOf('deleteDocuments'));
        self::assertSame([['id' => 'kept_de']], $index->argumentOf('addDocuments'));
    }

    /**
     * Stale variants go by aggregate before single documents by id, and additions come
     * last, so that nothing a batch adds is deleted by the same batch.
     */
    public function testTheBufferIsWrittenInAFixedOrder(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocument(['id' => 'kept_de']);
        $indexer->bufferDocumentDeletion('gone_de');
        $indexer->bufferIdentifierDeletion('stale');
        $indexer->flush();

        self::assertSame(['deleteByIdentifiers', 'deleteDocuments', 'addDocuments'], $index->calledMethods());
        self::assertSame(['stale'], $index->argumentOf('deleteByIdentifiers'));
    }

    public function testAnAggregateIsDeletedOnceHoweverOftenItIsBuffered(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferIdentifierDeletion('a');
        $indexer->bufferIdentifierDeletion('a');
        $indexer->bufferIdentifierDeletion('b');
        $indexer->flush();

        self::assertSame(['a', 'b'], $index->argumentOf('deleteByIdentifiers'));
    }

    /**
     * Document ids are the aggregate id and a dimensions hash joined by an underscore,
     * so an aggregate whose id merely starts with another's keeps its variants.
     */
    public function testDeletingAnAggregateKeepsTheVariantsOfAnotherWithTheSamePrefix(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocument(['id' => 'a_de']);
        $indexer->bufferDocument(['id' => 'ab_de']);
        $indexer->bufferIdentifierDeletion('a');
        $indexer->flush();

        self::assertSame([['id' => 'ab_de']], $index->argumentOf('addDocuments'));
    }

    /**
     * Nothing has to be deleted from an empty index, but a reindex in the same batch
     * still has to replace what was buffered for the aggregate before.
     */
    public function testAnAssumedEmptyIndexStillDropsVariantsBufferedBefore(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);
        $indexer->assumeEmptyIndex();

        $indexer->bufferNode('a', 'de', 'en');
        $indexer->bufferNode('a', 'de');
        $indexer->flush();

        self::assertSame(['addDocuments'], $index->calledMethods());
        self::assertSame([['id' => 'a_de']], $index->argumentOf('addDocuments'));
    }

    /**
     * The buffer holds documents in memory, so it must not grow with the site either.
     */
    public function testTheBufferIsWrittenOutOnceItReachesTheBatchSize(): void
    {
        $index = new RecordingIndex();
        // Each node buffers two items: the deletion of its stale variants and the
        // variant it just extracted.
        $indexer = new BufferingNodeIndexer($index, 10);

        $this->bufferNodes($indexer, 4);
        self::assertSame([], $index->calls, 'eight buffered items are below the batch size');

        $this->bufferNodes($indexer, 1, 4);

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
        self::assertCount(5, $index->argumentOf('addDocuments'));
    }

    public function testTheBatchSizeFallsBackToTheDefaultWithoutASetting(): void
    {
        self::assertSame(100, (new BufferingNodeIndexer(new RecordingIndex(), null))->batchSize());
    }

    public function testABatchSizeBelowOneWritesEveryNode(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 0);

        self::assertSame(1, $indexer->batchSize());

        $indexer->bufferRemoval('a_de');
        self::assertSame(['deleteDocuments'], $index->calledMethods());
    }

    /**
     * The defect the buffer left open, stated directly: persisting after every node
     * must not write after every node.
     */
    public function testPersistingPerNodeDoesNotWritePerNode(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 10);

        $this->persistNodes($indexer, 1, 0);
        $this->persistNodes($indexer, 1, 1);
        $this->persistNodes($indexer, 1, 2);

        self::assertSame([], $index->calls, 'six buffered items are below the batch size');
    }

    public function testPersistingWritesOnceTheBufferIsFull(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 10);

        $this->persistNodes($indexer, 3, 0);
        $this->persistNodes($indexer, 3, 3);

        // The fifth node fills the buffer; the sixth waits for the next write.
        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
        self::assertCount(5, $index->argumentOf('addDocuments'));
    }

    /**
     * nodeindex:build flushes directly, outside any bulk processing, and relies on
     * that flush having written everything when it returns.
     */
    public function testFlushingOutsideBulkProcessingWritesWhatIsLeft(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);
        $this->persistNodes($indexer, 3);
        self::assertSame([], $index->calls);

        $indexer->flush();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
        self::assertCount(3, $index->argumentOf('addDocuments'));
    }

    /**
     * The last buffer of a run is written when Flow shuts the indexer down, which is
     * what makes deferring safe for a request that changes a single node.
     */
    public function testShuttingDownWritesWhatIsLeft(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);
        $this->persistNodes($indexer, 1);

        $indexer->shutdownObject();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
    }

    public function testShuttingDownWithNothingBufferedWritesNothing(): void
    {
        $index = new RecordingIndex();

        (new BufferingNodeIndexer($index, 1000))->shutdownObject();

        self::assertSame([], $index->calls);
    }

    public function testBulkProcessingEndsWhenItsCallbackThrows(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        try {
            $indexer->withBulkProcessing(static function (): void {
                throw new \RuntimeException('a node that could not be indexed', 1789000001);
            });
            self::fail('The exception was expected to propagate.');
        } catch (\RuntimeException $exception) {
            // Expected: what matters is the state left behind.
        }

        $this->bufferNodes($indexer, 1);
        $indexer->flush();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $index->calledMethods());
    }

    public function testANestedBulkProcessingDoesNotEndTheOuterOne(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->withBulkProcessing(function () use ($indexer): void {
            $indexer->withBulkProcessing(static function (): void {
            });
            $this->bufferNodes($indexer, 1);
            $indexer->flush();
        });

        self::assertSame([], $index->calls);
    }

    /**
     * nodeindex:rebuild writes into a build index; the live index must not receive
     * anything buffered for it.
     */
    public function testAFlushIntoAnotherIndexWritesThere(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->flush('build');
        $this->bufferNodes($indexer, 2);
        $indexer->flush('build');

        self::assertSame(['build', 'build'], array_map(static fn (array $call): ?string => $call[2], $index->calls));
    }

    /**
     * A buffer belongs to one index: what was buffered for the live index goes there
     * before the buffer is switched to another one.
     */
    public function testSwitchingTheTargetIndexWritesWhatWasBufferedForThePreviousOne(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferDocument(['id' => 'live_de']);
        $indexer->flush('build');

        self::assertSame([['addDocuments', [['id' => 'live_de']], null]], $index->calls);
    }

    public function testAShutdownWritesIntoTheIndexTheBufferWasFilledFor(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->withBulkProcessing(function () use ($indexer): void {
            $indexer->flush('build');
            $this->bufferNodes($indexer, 1);
            $indexer->flush('build');
        });
        $indexer->shutdownObject();

        self::assertSame(['build', 'build'], array_map(static fn (array $call): ?string => $call[2], $index->calls));
    }

    public function testADiscardedBufferIsNotWritten(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->bufferNode('a', 'de');
        $indexer->bufferRemoval('b_de');
        $indexer->discardBuffer();
        $indexer->flush();
        $indexer->shutdownObject();

        self::assertSame([], $index->calls);
    }

    /**
     * Once the index it was filled for is gone, the buffer starts over on the live
     * index.
     */
    public function testDiscardingTheBufferReturnsToTheLiveIndex(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);

        $indexer->withBulkProcessing(static function () use ($indexer): void {
            $indexer->flush('build');
        });
        $indexer->discardBuffer();
        $indexer->bufferDocument(['id' => 'a_de']);
        $indexer->flush();

        self::assertSame([['addDocuments', [['id' => 'a_de']], null]], $index->calls);
    }

    /**
     * The buffer is cleared before it is written, so a caller that flushes again while
     * handling the failure does not send the same failing batch a second time.
     */
    public function testAFailedWriteIsNotRetriedByTheNextFlush(): void
    {
        $index = new RecordingIndex();
        $indexer = new BufferingNodeIndexer($index, 1000);
        $indexer->bufferDocument(['id' => 'a_de']);
        $index->failWith = new \RuntimeException('Meilisearch is unavailable', 1789000002);

        try {
            $indexer->flush();
            self::fail('The exception was expected to propagate.');
        } catch (\RuntimeException $exception) {
            // Expected: what matters is the state left behind.
        }
        $index->failWith = null;
        $index->calls = [];

        $indexer->flush();

        self::assertSame([], $index->calls);
    }

    /**
     * Buffers the given number of nodes the way indexNode() does for the common case:
     * clear the aggregate's stale variants, then add the variant it just extracted.
     * Nodes are numbered from the offset, so that two calls can buffer distinct nodes.
     */
    private function bufferNodes(BufferingNodeIndexer $indexer, int $count, int $offset = 0): void
    {
        for ($i = $offset; $i < $offset + $count; $i++) {
            $indexer->bufferNode('node' . $i, 'de');
        }
    }

    /**
     * What Neos.ContentRepository.Search does on every persisted request: index the
     * nodes that changed, then flush - all inside withBulkProcessing().
     */
    private function persistNodes(BufferingNodeIndexer $indexer, int $count, int $offset = 0): void
    {
        $indexer->withBulkProcessing(function () use ($indexer, $count, $offset): void {
            $this->bufferNodes($indexer, $count, $offset);
            $indexer->flush();
        });
    }
}
