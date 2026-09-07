<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Meilisearch\Search\SearchResult;

/**
 * Records the calls an indexer makes instead of performing them.
 *
 * The indexer's job is to decide how many writes a run needs, and the index client's
 * job is to turn each of those into requests. This double sits on that boundary so
 * that tests about the first can count calls without reasoning about the second.
 */
final class RecordingIndex implements IndexInterface
{
    /**
     * Every call, in order, as a method name and its argument.
     *
     * @var list<array{0: string, 1: array}>
     */
    public array $calls = [];

    /**
     * @var int Timeout the last waitForPendingTasks() was asked for
     */
    public int $waitedForSeconds = -1;

    public function createIndex(): void
    {
        $this->calls[] = ['createIndex', []];
    }

    public function addDocuments(array $documents): void
    {
        $this->calls[] = ['addDocuments', $documents];
    }

    public function deleteDocuments(array $documents): void
    {
        $this->calls[] = ['deleteDocuments', $documents];
    }

    public function deleteByFilter(array $filter): void
    {
        $this->calls[] = ['deleteByFilter', $filter];
    }

    public function deleteByIdentifiers(array $nodeIdentifiers): void
    {
        $this->calls[] = ['deleteByIdentifiers', $nodeIdentifiers];
    }

    public function deleteAllDocuments(): void
    {
        $this->calls[] = ['deleteAllDocuments', []];
    }

    public function deleteIndex(): void
    {
        $this->calls[] = ['deleteIndex', []];
    }

    public function waitForPendingTasks(int $timeoutInSeconds): void
    {
        $this->calls[] = ['waitForPendingTasks', [$timeoutInSeconds]];
        $this->waitedForSeconds = $timeoutInSeconds;
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
     * The names of the calls recorded so far, which is what assertions about how many
     * writes a run needs are about.
     *
     * @return list<string>
     */
    public function calledMethods(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }

    /**
     * The argument of the single recorded call to the given method.
     *
     * @param string $method
     * @return array
     */
    public function argumentOf(string $method): array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                return $call[1];
            }
        }

        throw new \LogicException(sprintf('No call to %s() was recorded.', $method), 1787000202);
    }
}
