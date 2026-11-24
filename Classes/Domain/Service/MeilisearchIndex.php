<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Exception;
use Medienreaktor\Meilisearch\Domain\Model\IndexSettings;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Contracts\TasksQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\TimeOutException;
use Meilisearch\Search\SearchResult;
use Neos\Flow\Annotations as Flow;

/**
 * Meilisearch Index client
 */
class MeilisearchIndex implements IndexInterface
{
    /**
     * Marks an index as a rebuild scratch index; everything after it is the
     * per-run suffix. Also used to recognise leftovers from aborted runs.
     */
    private const BUILD_INDEX_INFIX = '-build-';

    /**
     * Used when no setting supplies a batch size, as in unit tests that construct this
     * class without the Flow proxy.
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * How often waitForPendingTasks() asks whether the index still has work queued.
     */
    private const WAIT_POLL_INTERVAL_IN_MS = 500;

    /**
     * Task uids per outcome query, so that a long run cannot build a query string too
     * long to send.
     */
    private const TASK_QUERY_CHUNK_SIZE = 500;

    /**
     * The embedder fields Meilisearch applies without regenerating any embedding.
     * Only the apiKey is listed because it is the only one verified to behave that way
     * (against Meilisearch 1.22): swapping it left every embedding of a 386,000-document
     * index in place, while a changed documentTemplate started over from the first one.
     */
    private const EMBEDDER_FIELDS_APPLIED_WITHOUT_REEMBEDDING = ['apiKey'];

    /**
     * Uids of the tasks this instance enqueued and has not yet waited for.
     *
     * @var list<int>
     */
    protected array $pendingTaskUids = [];

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
     * Set for one run by `--allow-reembedding`, on top of the `indexing.allowReembedding`
     * setting.
     *
     * @var bool
     */
    protected $reembeddingAllowedForThisRun = false;

    /**
     * What the last settings update held back, one line per embedder, for the command
     * that triggered it to report.
     *
     * @var list<string>
     */
    protected $skippedEmbedderChanges = [];

    /**
     * @param string $indexName
     * @Flow\Autowiring(false)
     */
    public function __construct(string $indexName)
    {
        $this->indexName = $indexName;
    }

    public function initializeObject(): void
    {
        $this->client = new Client($this->clientSettings['endpoint'], $this->clientSettings['apiKey']);
        $this->index = $this->client->index($this->indexName);
    }

    protected function targetIndex(?string $targetIndexName): Indexes
    {
        return $targetIndexName !== null ? $this->client->index($targetIndexName) : $this->index;
    }

    /**
     * How long to wait for a Meilisearch task before giving up. The SDK's own
     * default is 5 s, which a swap or a settings update on a production-sized
     * index can exceed — the task then still completes, but the caller sees a
     * TimeOutException and reports a successful rebuild as a failure.
     */
    protected function taskTimeout(): int
    {
        return (int)($this->indexingSettings['taskTimeout'] ?? 300000);
    }

    /**
     * Wait for a queued task and fail loudly if it did not succeed.
     *
     * @param array $task Task payload as returned by the client
     * @throws Exception
     */
    protected function awaitTask(array $task): array
    {
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
    public function createBuildIndex(): string
    {
        $this->deleteStaleBuildIndexes();

        $buildIndexName = $this->indexName . self::BUILD_INDEX_INFIX . date('YmdHis') . '-' . bin2hex(random_bytes(3));

        $this->awaitTask($this->client->createIndex($buildIndexName));
        $buildIndex = $this->client->index($buildIndexName);
        $this->awaitTask($buildIndex->update(['primaryKey' => 'id']));
        $this->awaitTask($buildIndex->updateSettings(IndexSettings::fromConfiguration($this->indexSettings)->toArray()));

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
    public function preserveDocuments(string $buildIndexName): array
    {
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
    protected function copyDocumentsByFilter(string $filter, string $buildIndexName, int $batchSize): int
    {
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
    public function swapBuildIndex(string $buildIndexName): void
    {
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
    protected function deleteStaleBuildIndexes(): void
    {
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
    public function deleteBuildIndex(string $buildIndexName): void
    {
        try {
            $this->client->deleteIndex($buildIndexName);
        } catch (\Throwable $e) {
        }
    }

    /**
     * The settings are checked against the server before the index is created, so that
     * an index which does not exist yet answers as one - with nothing embedded to lose.
     */
    public function createIndex(): void
    {
        $settings = $this->withoutReembeddingChanges(IndexSettings::fromConfiguration($this->indexSettings)->toArray());
        $this->client->createIndex($this->indexName);
        $this->index->updateSettings($settings);
        $this->index->update(['primaryKey' => 'id']);
    }

    /**
     * Let the next settings update apply embedder changes that re-embed the index.
     */
    public function allowReembedding(): void
    {
        $this->reembeddingAllowedForThisRun = true;
    }

    /**
     * @return list<string>
     */
    public function getSkippedEmbedderChanges(): array
    {
        return $this->skippedEmbedderChanges;
    }

    /**
     * @param array $documents Documents to add to the index
     * @param string|null $targetIndexName Write to this index instead of the live one (rebuild)
     * @return void
     * @throws TimeOutException
     */
    public function addDocuments(array $documents, ?string $targetIndexName = null): void
    {
        foreach (array_chunk($documents, $this->batchSize()) as $chunk) {
            $this->awaitTask($this->rememberTask($this->targetIndex($targetIndexName)->addDocuments($chunk)));
        }
    }

    /**
     * @param array $documents Documents to delete from the index
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteDocuments(array $documents, ?string $targetIndexName = null): void
    {
        foreach (array_chunk(array_values($documents), $this->batchSize()) as $chunk) {
            $this->rememberTask($this->targetIndex($targetIndexName)->deleteDocuments($chunk));
        }
    }

    /**
     * Delete every indexed variant of the given node aggregates.
     *
     * Variants are addressed by filter, as their ids carry a dimensions hash the caller
     * does not necessarily know. One `IN` expression covers a whole chunk of aggregates,
     * so a batch of nodes costs one deletion task rather than one per node.
     *
     * @param array<int,string> $nodeIdentifiers Node aggregate identifiers
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteByIdentifiers(array $nodeIdentifiers, ?string $targetIndexName = null): void
    {
        foreach (array_chunk(array_values($nodeIdentifiers), $this->batchSize()) as $chunk) {
            $this->deleteByFilter('__identifier IN ["' . implode('", "', $chunk) . '"]', $targetIndexName);
        }
    }

    /**
     * Block until the index has no unfinished task left, whoever enqueued it, then fail
     * if any task this instance enqueued did not succeed: Meilisearch drops a failed
     * indexing task silently, so an index can finish its work and still be incomplete.
     *
     * @param int $timeoutInSeconds
     * @return void
     * @throws Exception
     */
    public function waitForPendingTasks(int $timeoutInSeconds): void
    {
        $uids = $this->pendingTaskUids;
        $this->pendingTaskUids = [];

        $deadline = microtime(true) + $timeoutInSeconds;
        while (($unfinished = $this->firstUnfinishedTask()) !== null) {
            if (microtime(true) >= $deadline) {
                throw new Exception(sprintf(
                    'Index "%s" still had unfinished work after %d seconds: task %s (%s) is %s.',
                    $this->indexName,
                    $timeoutInSeconds,
                    (string)($unfinished['uid'] ?? 'unknown'),
                    (string)($unfinished['type'] ?? 'unknown type'),
                    (string)($unfinished['status'] ?? 'unknown status')
                ), 1787000101);
            }
            usleep(self::WAIT_POLL_INTERVAL_IN_MS * 1000);
        }

        foreach (array_chunk($uids, self::TASK_QUERY_CHUNK_SIZE) as $chunk) {
            $failed = $this->client->getTasks((new TasksQuery())->setUids($chunk)->setStatuses(['failed'])->setLimit(1))->getResults();
            if ($failed !== []) {
                throw new Exception(sprintf(
                    'Indexing task %s on index "%s" failed: %s',
                    (string)($failed[0]['uid'] ?? 'unknown'),
                    $this->indexName,
                    (string)($failed[0]['error']['message'] ?? 'no message reported')
                ), 1787000102);
            }
        }
    }

    /**
     * @return array<string, mixed>|null The oldest task the index still has to do
     */
    protected function firstUnfinishedTask(): ?array
    {
        $query = (new TasksQuery())
            ->setIndexUids([$this->indexName])
            ->setStatuses(['enqueued', 'processing'])
            ->setLimit(1);

        return $this->client->getTasks($query)->getResults()[0] ?? null;
    }

    /**
     * Meilisearch answers a write with the task it queued for it.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed> The task, unchanged
     */
    protected function rememberTask(array $task): array
    {
        if (isset($task['taskUid'])) {
            $this->pendingTaskUids[] = (int)$task['taskUid'];
        }
        return $task;
    }

    protected function batchSize(): int
    {
        return max(1, (int)($this->indexingSettings['batchSize'] ?? self::DEFAULT_BATCH_SIZE));
    }

    /**
     * Delete the index itself, documents and settings alike.
     */
    public function deleteIndex(): void
    {
        $this->client->deleteIndex($this->indexName);
    }

    /**
     * Delete all documents from the index.
     */
    public function deleteAllDocuments(): void
    {
        $this->index->deleteAllDocuments();
    }

    /**
     * Delete documents matching the given Meilisearch filter expression.
     *
     * @param array<int|string,mixed>|string $filter A raw filter string, a list of filter
     *     clauses joined with AND (e.g. ['__identifier = "123"', '__dimensionsHash = "abc"']),
     *     or the options Meilisearch takes (e.g. ['filter' => '__identifier = "123"'])
     * @param string|null $targetIndexName Delete from this index instead of the live one
     * @return void
     */
    public function deleteByFilter(array|string $filter, ?string $targetIndexName = null): void
    {
        if ($filter === [] || $filter === '') {
            return;
        }

        if (is_array($filter) && array_key_exists('filter', $filter)) {
            $options = $filter;
        } else {
            $options = ['filter' => is_array($filter) ? implode(' AND ', $filter) : $filter];
        }

        $this->rememberTask($this->targetIndex($targetIndexName)->deleteDocuments($options));
    }

    /**
     * Returns an index entry by identifier or NULL if it doesn't exist.
     *
     * @param string $identifier
     * @return array|FALSE
     */
    public function findOneByIdentifier(string $identifier)
    {
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
    public function findAllIdentifiersByIdentifier(string $identifier)
    {
        $results = $this->index->search('', ['filter' => ['__identifier = "' . $identifier . '"']]);

        $hits = [];
        foreach ($results->getHits() as $hit) {
            $hits[] = $hit['id'];
        }
        return $hits;
    }

    /**
     * Returns the ids of a node's documents in the given dimension space point.
     *
     * @param string $identifier
     * @param string $dimensionsHash
     * @return array<int,string>
     */
    public function findAllIdentifiersByIdentifierAndDimensionsHash(string $identifier, string $dimensionsHash): array
    {
        $results = $this->index->search('', ['filter' => [
            '__identifier = "' . $identifier . '"',
            '__dimensionsHash = "' . $dimensionsHash . '"',
        ]]);

        $hits = [];
        foreach ($results->getHits() as $hit) {
            $hits[] = $hit['id'];
        }
        return $hits;
    }

    /**
     * Replace this index's settings with the given ones and apply them, e.g. for a
     * custom index with settings of its own. Later createIndex() and
     * nodeindex:rebuild runs on this instance use them as well.
     *
     * Takes the shape of `Medienreaktor.Meilisearch.settings`; `filterableAttributes`
     * and `sortableAttributes` may also be given as the plain lists Meilisearch takes.
     *
     * @param array<string,mixed> $settings
     */
    public function updateCustomSettings(array $settings): void
    {
        foreach (['filterableAttributes', 'sortableAttributes'] as $key) {
            if (isset($settings[$key]) && is_array($settings[$key]) && array_is_list($settings[$key])) {
                $settings[$key] = array_fill_keys($settings[$key], true);
            }
        }

        $this->indexSettings = $settings;
        $this->awaitTask($this->index->updateSettings(
            $this->withoutReembeddingChanges(IndexSettings::fromConfiguration($this->indexSettings)->toArray())
        ));
    }

    /**
     * Meilisearch regenerates the embedding of every document in an index when one of
     * its embedders changes in any field but the apiKey, and it does so inside the
     * settings task. createIndex() is how deployments apply settings, so a
     * documentTemplate edited in the configuration - or one that had drifted from what
     * the server held - used to start a full re-embedding as a side effect. On a large
     * index that runs into the embedding provider's rate limit, and the whole task
     * fails and is rolled back after the tokens were spent.
     *
     * On an index that already has documents such changes are therefore held back and
     * only the apiKey is passed on, so key rotation keeps working. A new embedder
     * counts as such a change too: adding one embeds every document. Re-embedding has
     * to be asked for through the `indexing.allowReembedding` setting or
     * allowReembedding().
     *
     * @param array $settings
     * @return array
     */
    protected function withoutReembeddingChanges(array $settings): array
    {
        $this->skippedEmbedderChanges = [];

        if (empty($settings['embedders']) || $this->reembeddingAllowed()) {
            return $settings;
        }

        try {
            $documentCount = (int) ($this->index->stats()['numberOfDocuments'] ?? 0);
            // Read through getSettings() rather than getEmbedders(), which the older
            // meilisearch-php releases this package still allows do not have. Newer
            // ones wrap the embedders in an Embedders object, which an array cast
            // would turn into its properties rather than its entries.
            $embedders = $this->index->getSettings()['embedders'] ?? [];
            $liveEmbedders = $embedders instanceof \Traversable ? iterator_to_array($embedders) : (array) $embedders;
        } catch (ApiException $exception) {
            if ($exception->errorCode !== 'index_not_found') {
                // Whether the index holds documents is unknown, so nothing that might
                // re-embed them is sent.
                throw $exception;
            }
            // The index does not exist yet, so nothing has been embedded that a
            // settings update could throw away.
            return $settings;
        }

        if ($documentCount === 0) {
            return $settings;
        }

        foreach ($settings['embedders'] as $name => $embedder) {
            $embedder = (array) $embedder;
            $live = isset($liveEmbedders[$name]) ? (array) $liveEmbedders[$name] : null;

            $changedFields = [];
            foreach ($embedder as $field => $value) {
                if (in_array($field, self::EMBEDDER_FIELDS_APPLIED_WITHOUT_REEMBEDDING, true)) {
                    continue;
                }
                if ($live === null || !array_key_exists($field, $live) || $live[$field] != $value) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields === []) {
                continue;
            }

            $this->skippedEmbedderChanges[] = $live === null
                ? sprintf('Embedder "%s" of index "%s" is not on the server yet; adding it would embed every document.', $name, $this->indexName)
                : sprintf('Embedder "%s" of index "%s": %s differ from the server; applying them would re-embed every document.', $name, $this->indexName, implode(', ', $changedFields));

            $applicable = $live === null
                ? []
                : array_intersect_key($embedder, array_flip(self::EMBEDDER_FIELDS_APPLIED_WITHOUT_REEMBEDDING));
            if ($applicable === []) {
                unset($settings['embedders'][$name]);
            } else {
                $settings['embedders'][$name] = $applicable;
            }
        }

        if ($settings['embedders'] === []) {
            unset($settings['embedders']);
        }

        return $settings;
    }

    protected function reembeddingAllowed(): bool
    {
        return $this->reembeddingAllowedForThisRun || (bool) ($this->indexingSettings['allowReembedding'] ?? false);
    }

    /**
     * Performs a search.
     *
     * @param string $query
     * @param array $parameters
     * @return SearchResult
     */
    public function search(string $query, array $parameters): SearchResult
    {
        if (isset($parameters['filter']) && is_array($parameters['filter'])) {
            $parameters['filter'] = implode(' AND ', $parameters['filter']);
        }

        $results = $this->index->search($query, $parameters);
        return $results;
    }

    /**
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }
}
