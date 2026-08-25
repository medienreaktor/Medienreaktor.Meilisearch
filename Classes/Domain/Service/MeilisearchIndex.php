<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Exception;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\TimeOutException;
use Meilisearch\Search\SearchResult;
use Neos\Flow\Annotations as Flow;

/**
 * Meilisearch Index client
 */
class MeilisearchIndex implements IndexInterface {
    /**
     * Marks an index as a rebuild scratch index; everything after it is the
     * per-run suffix. Also used to recognise leftovers from aborted runs.
     */
    private const BUILD_INDEX_INFIX = '-build-';

    /**
     * @var string
     */
    protected $indexName;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Indexes
     */
    protected $index;

    /**
     * @Flow\InjectConfiguration(path="client", package="Medienreaktor.Meilisearch")
     * @var array
     */
    protected $clientSettings;

    /**
     * @Flow\InjectConfiguration(path="settings", package="Medienreaktor.Meilisearch")
     * @var array
     */
    protected $indexSettings;

    /**
     * @Flow\InjectConfiguration(path="indexing", package="Medienreaktor.Meilisearch")
     * @var array
     */
    protected $indexingSettings;

    /**
     * @param string $indexName
     * @Flow\Autowiring(false)
     */
    public function __construct(string $indexName) {
        $this->indexName = $indexName;
    }

    public function initializeObject(): void {
        $this->client = new Client($this->clientSettings['endpoint'], $this->clientSettings['apiKey']);
        $this->index = $this->client->index($this->indexName);
    }

    protected function targetIndex(?string $targetIndexName): Indexes {
        return $targetIndexName !== null ? $this->client->index($targetIndexName) : $this->index;
    }

    /**
     * How long to wait for a Meilisearch task before giving up. The SDK's own
     * default is 5 s, which a swap or a settings update on a production-sized
     * index can exceed — the task then still completes, but the caller sees a
     * TimeOutException and reports a successful rebuild as a failure.
     */
    protected function taskTimeout(): int {
        return (int)($this->indexingSettings['taskTimeout'] ?? 300000);
    }

    /**
     * Wait for a queued task and fail loudly if it did not succeed.
     *
     * @param array $task Task payload as returned by the client
     * @throws Exception
     */
    protected function awaitTask(array $task): array {
        $result = $this->client->waitForTask($task['taskUid'], $this->taskTimeout());
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new Exception('Meilisearch task ' . ($task['type'] ?? 'unknown') . ' failed: ' . ($result['error']['message'] ?? 'no error message'));
        }
        return $result;
    }

    /**
     * Create a fresh, fully-configured build index for a zero-downtime rebuild
     * and return its name. Index into it via addDocuments($docs, $name), then
     * hand the name to swapBuildIndex().
     *
     * The name carries a per-run suffix so two rebuilds running at once cannot
     * write into — or delete — each other's index.
     *
     * The setup tasks are awaited: if documents arrived before the primary key /
     * settings were applied, Meilisearch could infer a wrong key or drop them.
     */
    public function createBuildIndex(): string {
        $this->deleteStaleBuildIndexes();

        $buildIndexName = $this->indexName . self::BUILD_INDEX_INFIX . date('YmdHis') . '-' . bin2hex(random_bytes(3));

        $this->awaitTask($this->client->createIndex($buildIndexName));
        $buildIndex = $this->client->index($buildIndexName);
        $this->awaitTask($buildIndex->update(['primaryKey' => 'id']));
        $this->awaitTask($buildIndex->updateSettings($this->indexSettings));

        return $buildIndexName;
    }

    /**
     * Copy documents that this rebuild does not produce itself from the live
     * index into the build index, so the swap does not drop them.
     *
     * Other packages write into the same index (the asset indexer stores its
     * PDF/media documents next to the node documents). A node rebuild never
     * touches those, so without this step every swap would silently wipe them.
     * Each configured filter is copied under its own label; see the
     * "indexing.preserveOnRebuild" setting.
     *
     * @param string $buildIndexName
     * @return array<string,int> Number of copied documents per label
     * @throws Exception
     */
    public function preserveDocuments(string $buildIndexName): array {
        $filters = $this->indexingSettings['preserveOnRebuild'] ?? [];
        if (!is_array($filters) || $filters === []) {
            return [];
        }

        $batchSize = (int)($this->indexingSettings['batchSize'] ?? 100);
        $copied = [];
        foreach ($filters as $label => $filter) {
            if (!is_string($filter) || trim($filter) === '') {
                continue;
            }
            $copied[$label] = $this->copyDocumentsByFilter($filter, $buildIndexName, $batchSize);
        }
        return $copied;
    }

    /**
     * Page through the live index and re-add every matching document to the
     * build index. Uses the documents endpoint rather than search, because
     * search paging is capped by maxTotalHits.
     *
     * @throws Exception
     */
    protected function copyDocumentsByFilter(string $filter, string $buildIndexName, int $batchSize): int {
        $copied = 0;
        $offset = 0;
        do {
            $query = (new DocumentsQuery())->setFilter([$filter])->setLimit($batchSize)->setOffset($offset);
            $documents = $this->index->getDocuments($query)->getResults();
            if ($documents === []) {
                break;
            }
            $this->addDocuments($documents, $buildIndexName);
            $copied += count($documents);
            $offset += count($documents);
        } while (count($documents) === $batchSize);

        return $copied;
    }

    /**
     * Atomically swap a freshly-built index into the live index (native
     * Meilisearch swap), then drop the old data. Aborts — leaving live
     * untouched — if the build index is empty, so a broken build can never
     * wipe search.
     */
    public function swapBuildIndex(string $buildIndexName): void {
        $builtDocuments = $this->client->index($buildIndexName)->stats()['numberOfDocuments'] ?? 0;
        if ($builtDocuments < 1) {
            throw new Exception('Zero-downtime rebuild aborted: build index "' . $buildIndexName . '" is empty — live index left untouched.');
        }

        // Make sure the live index exists so the swap has two indexes.
        try {
            $this->awaitTask($this->client->createIndex($this->indexName));
        } catch (\Throwable $e) {
        }

        // Client::swapIndexes() wraps each pair in ['indexes' => ...] itself,
        // so we pass the bare [live, build] pair — not a pre-wrapped payload.
        $this->awaitTask($this->client->swapIndexes([[$this->indexName, $buildIndexName]]));
        // The swap exchanged the contents, so this name now holds the old data.
        $this->client->deleteIndex($buildIndexName);
    }

    /**
     * Drop build indexes left behind by an aborted run. Only indexes older than
     * the configured TTL are removed — a younger one may belong to a rebuild
     * that is still running.
     */
    protected function deleteStaleBuildIndexes(): void {
        $prefix = $this->indexName . self::BUILD_INDEX_INFIX;
        $ttl = (int)($this->indexingSettings['staleBuildIndexTtl'] ?? 86400);
        $threshold = time() - $ttl;

        try {
            foreach ($this->client->getIndexes((new IndexesQuery())->setLimit(1000))->getResults() as $index) {
                $uid = (string)$index->getUid();
                if (!str_starts_with($uid, $prefix)) {
                    continue;
                }
                $createdAt = $index->getCreatedAt();
                if ($createdAt !== null && $createdAt->getTimestamp() > $threshold) {
                    continue;
                }
                $this->client->deleteIndex($uid);
            }
        } catch (\Throwable $e) {
            // Cleanup is best-effort; a leftover index costs disk, not correctness.
        }
    }

    /**
     * Discard a build index (error cleanup); live is left untouched.
     */
    public function deleteBuildIndex(string $buildIndexName): void {
        try {
            $this->client->deleteIndex($buildIndexName);
        } catch (\Throwable $e) {
        }
    }

    public function createIndex(): void {
        $this->client->createIndex($this->indexName);
        $this->index->updateSettings($this->indexSettings);
        $this->index->update(['primaryKey' => 'id']);
    }

    /**
     * @param array $documents Documents to add to the index
     * @param string|null $targetIndexName Write to this index instead of the live one (rebuild)
     * @return void
     * @throws TimeOutException
     */
    public function addDocuments(array $documents, ?string $targetIndexName = null): void {
        $this->awaitTask($this->targetIndex($targetIndexName)->addDocuments($documents));
    }

    /**
     * @param array $documents Documents to delete from the index
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteDocuments(array $documents, ?string $targetIndexName = null): void {
        $this->targetIndex($targetIndexName)->deleteDocuments($documents);
    }

    /**
     * Delete all documents from the index.
     */
    public function deleteAllDocuments(): void {
        $this->index->deleteAllDocuments();
    }

    /**
     * Delete documents matching the given Meilisearch filter expression.
     *
     * @param array<int,string>|string $filter
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteByFilter(array|string $filter, ?string $targetIndexName = null): void {
        $filterString = is_array($filter) ? implode(' AND ', $filter) : $filter;
        $this->targetIndex($targetIndexName)->deleteDocuments(['filter' => $filterString]);
    }

    /**
     * Returns an index entry by identifier or NULL if it doesn't exist.
     *
     * @param string $identifier
     * @return array|FALSE
     */
    public function findOneByIdentifier(string $identifier) {
        try {
            $document = $this->index->getDocument($identifier);
            if ($document) {
                return $document['properties'];
            }
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            return false;
        }

        return false;
    }

    /**
     * Returns all index identifiers by identifier or NULL if it doesn't exist.
     *
     * @param string $identifier
     * @return array|FALSE
     */
    public function findAllIdentifiersByIdentifier(string $identifier) {
        $results = $this->index->search('', ['filter' => ['__identifier = ' . $identifier]]);

        $hits = [];
        foreach ($results->getHits() as $hit) {
            $hits[] = $hit['id'];
        }
        return $hits;
    }

    /**
     * Performs a search.
     *
     * @param string $query
     * @param array $parameters
     * @return SearchResult
     */
    public function search(string $query, array $parameters): SearchResult {
        if (isset($parameters['filter']) && is_array($parameters['filter'])) {
            $parameters['filter'] = implode(' AND ', $parameters['filter']);
        }

        $results = $this->index->search($query, $parameters);
        return $results;
    }

    /**
     * @return string
     */
    public function getIndexName(): string {
        return $this->indexName;
    }
}
