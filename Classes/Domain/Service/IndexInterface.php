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
     * Delete documents matching the given Meilisearch filter expression(s).
     *
     * @param array<int,string>|string $filter Filter clauses (joined with AND) or raw filter string
     * @return void
     */
    public function deleteByFilter(array|string $filter): void;

    /**
     * Look up all document ids that share the same logical __identifier.
     * Used to find all dimension variants of a node or asset.
     *
     * @param string $identifier
     * @return array<int,string>
     */
    public function findAllIdentifiersByIdentifier(string $identifier);

    /**
     * @return string
     */
    public function getIndexName(): string;
}
