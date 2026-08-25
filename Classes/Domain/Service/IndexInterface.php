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
     * Create a fresh, fully-configured build index for a zero-downtime rebuild
     * and return its name. Index into it via addDocuments($docs, $name), then
     * hand the name to swapBuildIndex().
     */
    public function createBuildIndex(): string;

    /**
     * Copy documents this rebuild does not produce itself (e.g. the asset
     * indexer's) from the live index into the build index, so the swap does not
     * drop them. Driven by the "indexing.preserveOnRebuild" setting.
     *
     * @return array<string,int> Number of copied documents per configured label
     */
    public function preserveDocuments(string $buildIndexName): array;

    /**
     * Atomically swap a freshly-built index into the live index, then drop the
     * old data. Aborts (leaving live untouched) if the build index is empty.
     */
    public function swapBuildIndex(string $buildIndexName): void;

    /**
     * Discard a build index (error cleanup); live is left untouched.
     */
    public function deleteBuildIndex(string $buildIndexName): void;

    /**
     * @param array $documents Documents to add to the index
     * @param string|null $targetIndexName Write to this index instead of the live one
     * @return void
     */
    public function addDocuments(array $documents, ?string $targetIndexName = null): void;

    /**
     * @param array $documents Documents to delete from the index
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteDocuments(array $documents, ?string $targetIndexName = null): void;

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
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteByFilter(array|string $filter, ?string $targetIndexName = null): void;

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
