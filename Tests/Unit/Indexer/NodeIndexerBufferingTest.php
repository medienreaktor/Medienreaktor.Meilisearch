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
 *
 * The buffer alone did not close the defect. Neos.ContentRepository.Search drains its
 * queue and asks for a flush on every persisted request, inside withBulkProcessing(),
 * so an import that persists after every node flushed after every node and wrote per
 * node all the same - on a 17k-node import that queued tasks faster than Meilisearch
 * drained them. The bulk processing cases are about that.
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
     * Nodes are numbered from the offset, so that two calls can buffer distinct nodes.
     */
    private function bufferNodes(int $count, int $offset = 0): void
    {
        for ($i = $offset; $i < $offset + $count; $i++) {
            $this->nodeIndexer->bufferIdentifierDeletion('node-' . $i);
            $this->nodeIndexer->bufferDocument($this->document((string) $i));
        }
    }

    /**
     * What Neos.ContentRepository.Search does on every persisted request: index the
     * nodes that changed, then flush - all inside withBulkProcessing().
     */
    private function persistNodes(int $count, int $offset = 0): void
    {
        $this->nodeIndexer->withBulkProcessing(function () use ($count, $offset): void {
            $this->bufferNodes($count, $offset);
            $this->nodeIndexer->flush();
        });
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

    /**
     * Deferred indexers persist the document identifier while the node still exists,
     * so the removal must not need the node once it runs.
     */
    public function testRemovesDocumentByItsImmutableIdentifier(): void
    {
        $this->nodeIndexer->removeDocumentByIdentifier('document-aggregate_language-de-hash');
        $this->nodeIndexer->flush();

        self::assertSame(['deleteDocuments'], $this->index->calledMethods());
        self::assertSame(['document-aggregate_language-de-hash'], $this->index->argumentOf('deleteDocuments'));
    }

    public function testAnEmptyIndexNeedsNoDeletions(): void
    {
        $this->nodeIndexer->assumeEmptyIndex();

        $this->bufferNodes(10);
        $this->nodeIndexer->bufferDocumentDeletion('a');
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
    }

    /**
     * The defect the buffer left open, stated directly: persisting after every node
     * must not write after every node.
     */
    public function testPersistingPerNodeDoesNotWritePerNode(): void
    {
        $this->setBatchSize(10);

        $this->persistNodes(1, 0);
        $this->persistNodes(1, 1);
        $this->persistNodes(1, 2);

        self::assertSame([], $this->index->calls, 'six buffered items are below the batch size');
    }

    public function testPersistingWritesOnceTheBufferIsFull(): void
    {
        $this->setBatchSize(10);

        $this->persistNodes(3, 0);
        $this->persistNodes(3, 3);

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $this->index->calledMethods());
        self::assertCount(6, $this->index->argumentOf('addDocuments'));
    }

    /**
     * nodeindex:build flushes directly, outside any bulk processing, and relies on
     * that flush having written everything when it returns.
     */
    public function testFlushingOutsideBulkProcessingWritesWhatIsLeft(): void
    {
        $this->persistNodes(3);
        self::assertSame([], $this->index->calls);

        $this->nodeIndexer->flush();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $this->index->calledMethods());
        self::assertCount(3, $this->index->argumentOf('addDocuments'));
    }

    /**
     * The last buffer of a run is written when Flow shuts the indexer down, which is
     * what makes deferring safe for a request that changes a single node.
     */
    public function testShuttingDownWritesWhatIsLeft(): void
    {
        $this->persistNodes(1);

        $this->nodeIndexer->shutdownObject();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $this->index->calledMethods());
    }

    public function testShuttingDownWithNothingBufferedWritesNothing(): void
    {
        $this->nodeIndexer->shutdownObject();

        self::assertSame([], $this->index->calls);
    }

    public function testBulkProcessingEndsWhenItsCallbackThrows(): void
    {
        try {
            $this->nodeIndexer->withBulkProcessing(static function (): void {
                throw new \RuntimeException('a node that could not be indexed', 1789000001);
            });
            self::fail('The exception was expected to propagate.');
        } catch (\RuntimeException $exception) {
            // Expected: what matters is the state left behind.
        }

        $this->bufferNodes(1);
        $this->nodeIndexer->flush();

        self::assertSame(['deleteByIdentifiers', 'addDocuments'], $this->index->calledMethods());
    }

    public function testANestedBulkProcessingDoesNotEndTheOuterOne(): void
    {
        $this->nodeIndexer->withBulkProcessing(function (): void {
            $this->nodeIndexer->withBulkProcessing(static function (): void {
            });
            $this->bufferNodes(1);
            $this->nodeIndexer->flush();
        });

        self::assertSame([], $this->index->calls);
    }

    private function setBatchSize(int $batchSize): void
    {
        $reflection = new \ReflectionObject($this->nodeIndexer);
        $property = $reflection->getProperty('indexingSettings');
        $property->setAccessible(true);
        $property->setValue($this->nodeIndexer, ['batchSize' => $batchSize]);
    }
}
