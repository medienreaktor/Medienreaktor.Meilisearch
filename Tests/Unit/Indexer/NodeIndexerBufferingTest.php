<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\ExposedNodeIndexer;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingIndex;
use PHPUnit\Framework\TestCase;

/**
 * Meilisearch queues one task per write request, so a run that writes per node makes
 * the server's queue as long as the site. On a 17k-node site that queue outgrew the
 * memory of the machine holding it, which is the defect these cases exist for.
 *
 * The defect is not a wrong index - indexing per node produces exactly the same
 * documents - so nothing here asserts index contents. What is wrong is the number of
 * writes, and how it grows, so that is what is asserted.
 */
class NodeIndexerBufferingTest extends TestCase
{
    private RecordingIndex $index;

    private ExposedNodeIndexer $nodeIndexer;

    protected function setUp(): void
    {
        $this->index = new RecordingIndex();
        $this->nodeIndexer = new ExposedNodeIndexer();

        $reflection = new \ReflectionObject($this->nodeIndexer);
        $property = $reflection->getProperty('indexClient');
        $property->setAccessible(true);
        $property->setValue($this->nodeIndexer, $this->index);
    }

    private function document(string $identifier): array
    {
        return ['id' => $identifier, '__identifier' => 'node-' . $identifier];
    }

    /**
     * Buffers the given number of nodes the way indexNode() does for the common case:
     * clear the aggregate's stale variants, then add the variant it just extracted.
     */
    private function bufferNodes(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->nodeIndexer->bufferIdentifierDeletion('node-' . $i);
            $this->nodeIndexer->bufferDocument($this->document((string) $i));
        }
    }

    /**
     * The defect, stated directly. A count that is the same for ten nodes and for a
     * hundred cannot have been produced per node, and unlike a fixed expected number
     * it cannot be satisfied by tuning a constant until it matches.
     */
    public function testWriteCountDoesNotGrowWithTheNumberOfNodes(): void
    {
        $this->bufferNodes(10);
        $this->nodeIndexer->flush();
        $forTenNodes = $this->index->calledMethods();

        $this->index->calls = [];

        $this->bufferNodes(100);
        $this->nodeIndexer->flush();

        self::assertSame($forTenNodes, $this->index->calledMethods());
    }

    public function testAWholeBatchCollapsesIntoOneWritePerKindOfWork(): void
    {
        $this->bufferNodes(50);
        $this->nodeIndexer->flush();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $this->index->calledMethods());
        self::assertCount(50, $this->index->argumentOf('deleteByIdentifiers'));
        self::assertCount(50, $this->index->argumentOf('addDocuments'));
    }

    public function testNothingIsWrittenBeforeFlush(): void
    {
        $this->bufferNodes(50);

        self::assertSame([], $this->index->calls);
    }

    /**
     * flush() is reached on paths that had nothing to do - the editorial hook runs on
     * every persisted request - and an empty write would still cost a task.
     */
    public function testFlushingAnEmptyBufferWritesNothing(): void
    {
        $this->nodeIndexer->flush();

        self::assertSame([], $this->index->calls);
    }

    public function testFlushingTwiceDoesNotWriteTheBufferTwice(): void
    {
        $this->bufferNodes(5);
        $this->nodeIndexer->flush();
        $this->index->calls = [];

        $this->nodeIndexer->flush();

        self::assertSame([], $this->index->calls);
    }

    public function testTheSameVariantBufferedTwiceIsWrittenOnce(): void
    {
        $this->nodeIndexer->bufferDocument($this->document('a'));
        $this->nodeIndexer->bufferDocument($this->document('a'));
        $this->nodeIndexer->flush();

        self::assertCount(1, $this->index->argumentOf('addDocuments'));
    }

    /**
     * Grouping the buffer means replaying it in a fixed order - deletions, then
     * additions - so a document touched twice in one batch has to end up in the group
     * its last operation put it in. Otherwise a hide following an index would replay as
     * an index following a hide, and leave the document in the index.
     */
    public function testADeletionSupersedesAnEarlierAdditionOfTheSameDocument(): void
    {
        $this->nodeIndexer->bufferDocument($this->document('a'));
        $this->nodeIndexer->bufferDocumentDeletion('a');
        $this->nodeIndexer->flush();

        self::assertSame(['deleteDocuments'], $this->index->calledMethods());
        self::assertSame(['a'], $this->index->argumentOf('deleteDocuments'));
    }

    public function testAnAdditionSupersedesAnEarlierDeletionOfTheSameDocument(): void
    {
        $this->nodeIndexer->bufferDocumentDeletion('a');
        $this->nodeIndexer->bufferDocument($this->document('a'));
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
        self::assertSame([$this->document('a')], $this->index->argumentOf('addDocuments'));
    }

    public function testDeletionsAndAdditionsOfDifferentDocumentsBothSurvive(): void
    {
        $this->nodeIndexer->bufferDocumentDeletion('gone');
        $this->nodeIndexer->bufferDocument($this->document('kept'));
        $this->nodeIndexer->flush();

        self::assertSame(['deleteDocuments', 'addDocuments'], $this->index->calledMethods());
        self::assertSame(['gone'], $this->index->argumentOf('deleteDocuments'));
        self::assertSame([$this->document('kept')], $this->index->argumentOf('addDocuments'));
    }

    /**
     * The buffer holds documents in memory, so it must not grow with the site either.
     */
    public function testTheBufferIsWrittenOutOnceItReachesTheBatchSize(): void
    {
        // Each node buffers two items: the deletion of its stale variants and the
        // variant it just extracted.
        $this->setBatchSize(10);

        $this->bufferNodes(4);
        $this->nodeIndexer->flushIfBufferIsFull();
        self::assertSame([], $this->index->calls, 'eight buffered items are below the batch size');

        $this->bufferNodes(5);
        $this->nodeIndexer->flushIfBufferIsFull();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $this->index->calledMethods());
    }

    public function testAnEmptyIndexNeedsNoDeletions(): void
    {
        $this->nodeIndexer->assumeEmptyIndex();

        $this->bufferNodes(10);
        $this->nodeIndexer->bufferDocumentDeletion('a');
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
    }

    private function setBatchSize(int $batchSize): void
    {
        $reflection = new \ReflectionObject($this->nodeIndexer);
        $property = $reflection->getProperty('indexingSettings');
        $property->setAccessible(true);
        $property->setValue($this->nodeIndexer, ['batchSize' => $batchSize]);
    }
}
