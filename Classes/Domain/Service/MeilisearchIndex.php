<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Exception;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\TimeOutException;
use Meilisearch\Search\SearchResult;
use Neos\Flow\Annotations as Flow;

/**
 * Meilisearch Index client
 */
class MeilisearchIndex implements IndexInterface {
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
     * Create a fresh, fully-configured build index for a zero-downtime rebuild
     * and return its name. Index into it via addDocuments($docs, $name), then
     * hand the name to swapBuildIndex().
     *
     * The setup tasks are awaited: if documents arrived before the primary key /
     * settings were applied, Meilisearch could infer a wrong key or drop them.
     */
    public function createBuildIndex(): string {
        $buildIndexName = $this->indexName . '-build';

        // Drop a possible leftover build index from an aborted previous run.
        try {
            $this->client->waitForTask($this->client->deleteIndex($buildIndexName)['taskUid']);
        } catch (\Throwable $e) {
        }

        $this->client->waitForTask($this->client->createIndex($buildIndexName)['taskUid']);
        $buildIndex = $this->client->index($buildIndexName);
        $this->client->waitForTask($buildIndex->update(['primaryKey' => 'id'])['taskUid']);
        $this->client->waitForTask($buildIndex->updateSettings($this->indexSettings)['taskUid']);

        return $buildIndexName;
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
            $this->client->waitForTask($this->client->createIndex($this->indexName)['taskUid']);
        } catch (\Throwable $e) {
        }

        // Client::swapIndexes() wraps each pair in ['indexes' => ...] itself,
        // so we pass the bare [live, build] pair — not a pre-wrapped payload.
        $task = $this->client->swapIndexes([[$this->indexName, $buildIndexName]]);
        $this->client->waitForTask($task['taskUid']);
        $this->client->deleteIndex($buildIndexName);
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
        $index = $this->targetIndex($targetIndexName);
        $task = $index->addDocuments($documents);
        $result = $index->waitForTask($task['taskUid']);
        if ($result['status'] !== 'succeeded') {
            throw new Exception('Meilisearch index update failed: ' . $result['error']['message']);
        }
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
