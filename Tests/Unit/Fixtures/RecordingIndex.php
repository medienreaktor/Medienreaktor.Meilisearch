<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Meilisearch\Search\SearchResult;

/**
 * Records the writes an indexer makes instead of performing them, so that tests about
 * how many writes a run needs can count calls.
 */
final class RecordingIndex implements IndexInterface
{
    /**
     * Every write, in order: method name, argument, target index.
     *
     * @var list<array{0: string, 1: mixed, 2: ?string}>
     */
    public array $calls = [];

    public function createIndex(): void
    {
        $this->calls[] = ['createIndex', null, null];
    }

    public function createBuildIndex(): string
    {
        return 'recording-build';
    }

    public function preserveDocuments(string $buildIndexName): array
    {
        return [];
    }

    public function swapBuildIndex(string $buildIndexName): void
    {
    }

    public function deleteBuildIndex(string $buildIndexName): void
    {
    }

    public function addDocuments(array $documents, ?string $targetIndexName = null): void
    {
        $this->calls[] = ['addDocuments', $documents, $targetIndexName];
    }

    public function deleteDocuments(array $documents, ?string $targetIndexName = null): void
    {
        $this->calls[] = ['deleteDocuments', $documents, $targetIndexName];
    }

    public function deleteByIdentifiers(array $nodeIdentifiers, ?string $targetIndexName = null): void
    {
        $this->calls[] = ['deleteByIdentifiers', $nodeIdentifiers, $targetIndexName];
    }

    public function waitForPendingTasks(int $timeoutInSeconds): void
    {
    }

    public function deleteAllDocuments(): void
    {
    }

    public function deleteByFilter(array|string $filter, ?string $targetIndexName = null): void
    {
        $this->calls[] = ['deleteByFilter', $filter, $targetIndexName];
    }

    public function findAllIdentifiersByIdentifier(string $identifier)
    {
        return [];
    }

    public function deleteIndex(): void
    {
    }

    public function getIndexName(): string
    {
        return 'recording';
    }

    public function search(string $query, array $parameters): SearchResult
    {
        throw new \LogicException('Searching is not part of what this double stands in for.', 1787000201);
    }

    /**
     * @return list<string>
     */
    public function calledMethods(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }
}
