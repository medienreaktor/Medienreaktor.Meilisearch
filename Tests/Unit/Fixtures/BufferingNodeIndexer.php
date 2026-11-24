<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Indexer\NodeIndexer;

/**
 * Opens the indexer's buffer to tests, which cannot hand it real nodes without a
 * content repository.
 */
final class BufferingNodeIndexer extends NodeIndexer
{
    public function __construct(IndexInterface $indexClient, ?int $batchSize)
    {
        $this->indexClient = $indexClient;
        $this->batchSize = $batchSize;
    }

    /**
     * Buffers what indexing one fulltext root does: drop all of its variants, add the
     * given ones.
     */
    public function bufferNode(string $nodeAggregateId, string ...$dimensionsHashes): void
    {
        $this->bufferIdentifierDeletion($nodeAggregateId);
        foreach ($dimensionsHashes as $dimensionsHash) {
            $this->bufferDocument(['id' => $nodeAggregateId . '_' . $dimensionsHash]);
        }
        $this->flushIfBufferIsFull();
    }

    public function bufferRemoval(string $documentIdentifier): void
    {
        $this->bufferDocumentDeletion($documentIdentifier);
        $this->flushIfBufferIsFull();
    }

    /**
     * @param array<string, mixed> $document
     */
    public function bufferDocument(array $document): void
    {
        parent::bufferDocument($document);
    }

    public function bufferDocumentDeletion(string $documentIdentifier): void
    {
        parent::bufferDocumentDeletion($documentIdentifier);
    }

    public function bufferIdentifierDeletion(string $nodeAggregateId): void
    {
        parent::bufferIdentifierDeletion($nodeAggregateId);
    }
}
