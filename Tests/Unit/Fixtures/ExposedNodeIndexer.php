<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;

/**
 * Opens the indexer's buffer to tests.
 *
 * indexNode() cannot be driven in a unit test - it needs a content repository, a
 * context factory and a dimension preset source behind it - but the buffering it
 * relies on is independent of all three. Widening these here keeps that mechanism
 * testable without widening it in the indexer itself, where nothing outside needs it.
 */
final class ExposedNodeIndexer extends NodeIndexer
{
    public function bufferDocument(array $document): void
    {
        parent::bufferDocument($document);
    }

    public function bufferDocumentDeletion(string $documentIdentifier): void
    {
        parent::bufferDocumentDeletion($documentIdentifier);
    }

    public function bufferIdentifierDeletion(string $nodeIdentifier): void
    {
        parent::bufferIdentifierDeletion($nodeIdentifier);
    }

    public function flushIfBufferIsFull(): void
    {
        parent::flushIfBufferIsFull();
    }
}
