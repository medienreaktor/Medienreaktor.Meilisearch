<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Meilisearch\Search\SearchResult;

/**
 * Interface IndexInterface
 */
interface IndexInterface
{
    /**
     * Create the index and update settings.
     */
    public function createIndex(): void;

    /**
     * @param array $documents Documents to add to the index
     * @return void
     */
    public function addDocuments(array $documents): void;

    /**
     * @param array $documents Documents to delete from the index
     * @return void
     */
    public function deleteDocuments(array $documents): void;

    /**
     * @param array $filter Filter conditions or Meilisearch filter options
     * @return void
     */
    public function deleteByFilter(array $filter): void;

    /**
     * Delete every indexed variant of the given node aggregates.
     *
     * @param array $nodeIdentifiers Node aggregate identifiers
     * @return void
     */
    public function deleteByIdentifiers(array $nodeIdentifiers): void;

    /**
     * Block until the index has no unfinished task left, failing if any task the
     * implementation enqueued did not succeed.
     *
     * @param int $timeoutInSeconds
     * @return void
     */
    public function waitForPendingTasks(int $timeoutInSeconds): void;

    /**
     * Delete all documents from the index.
     *
     * @return void
     */
    public function deleteAllDocuments(): void;

    /**
     * Delete index.
     *
     * @return void
     */
    public function deleteIndex(): void;

    /**
     * @return string
     */
    public function getIndexName(): string;

    /**
     * Perform a search.
     *
     * @param string $query
     * @param array $parameters
     * @return SearchResult
     */
    public function search(string $query, array $parameters): SearchResult;
}
