<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

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
     * Delete all documents from the index.
     *
     * @return void
     */
    public function deleteAllDocuments(): void;

    /**
     * @return string
     */
    public function getIndexName(): string;
}
