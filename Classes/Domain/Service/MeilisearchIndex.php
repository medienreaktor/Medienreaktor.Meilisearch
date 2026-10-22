<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Exception;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;
use Neos\Flow\Annotations as Flow;

/**
 * Meilisearch Index client
 */
class MeilisearchIndex implements IndexInterface
{
    /**
     * Meilisearch queues exactly one task per write request, so the number of requests
     * a run makes and the length of the server's task queue are the same number. Writes
     * therefore go out in batches, and this is the size used when no setting supplies
     * one - as in the unit tests, which construct this class directly rather than
     * through the Flow proxy that would inject the configuration.
     */
    private const DEFAULT_BATCH_SIZE = 1000;

    /**
     * How often the wait loop asks whether the index still has work queued.
     */
    private const WAIT_POLL_INTERVAL_IN_MS = 500;

    /**
     * Uids are chunked when asking about task outcomes, so that a run which enqueued
     * very many tasks cannot build a query string too long to send.
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
    protected $indexingSettings = [];

    /**
     * Uids of the tasks this instance enqueued and has not yet waited for.
     *
     * @var array<int, int>
     */
    protected $pendingTaskUids = [];

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

    /**
     * Neither task is registered for waiting on. Creating an index that already exists
     * is how callers ask for one idempotently, and Meilisearch answers that with a
     * failed `indexCreation` task - so tracking it would make every wait on an existing
     * index report a failure.
     *
     * The settings are checked against the server before the index is created, so that
     * an index which does not exist yet answers as one - with nothing embedded to lose.
     */
    public function createIndex(): void
    {
        $settings = $this->withoutReembeddingChanges($this->indexSettings);
        $this->client->createIndex($this->indexName);
        $this->index->updateSettings($settings);
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
     * @return void
     */
    public function addDocuments(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        foreach ($this->index->addDocumentsInBatches($documents, $this->batchSize()) as $task) {
            $this->rememberTask($task);
        }
    }

    /**
     * @param array $documents Document identifiers to delete from the index
     * @return void
     */
    public function deleteDocuments(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        foreach (array_chunk(array_values($documents), $this->batchSize()) as $chunk) {
            $this->rememberTask($this->index->deleteDocuments($chunk));
        }
    }

    /**
     * Delete every indexed variant of the given node aggregates.
     *
     * Variants can only be addressed by filter, because their document ids carry a
     * dimensions hash the caller does not necessarily know. One `IN` expression covers
     * a whole chunk of aggregates, which is what keeps a rebuild from queueing one
     * deletion task per node.
     *
     * @param array $nodeIdentifiers Node aggregate identifiers
     * @return void
     */
    public function deleteByIdentifiers(array $nodeIdentifiers): void
    {
        if ($nodeIdentifiers === []) {
            return;
        }

        foreach (array_chunk(array_values($nodeIdentifiers), $this->batchSize()) as $chunk) {
            $this->deleteByFilter([
                '__identifier IN ["' . implode('", "', $chunk) . '"]'
            ]);
        }
    }

    /**
     * Delete the index.
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
     * Delete documents by a filter.
     *
     * @param array $filter List of filter conditions (e.g. ['__identifier = 123', '__dimensionsHash = "abc"'])
     *                      or an options array (e.g. ['filter' => '__identifier = 123 AND __dimensionsHash = "abc"'])
     */
    public function deleteByFilter(array $filter): void
    {
        if ($filter === []) {
            return;
        }

        if (array_key_exists('filter', $filter)) {
            $options = $filter;
        } else {
            $options = ['filter' => implode(' AND ', $filter)];
        }

        $this->rememberTask($this->index->deleteDocuments($options));
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
        $results = $this->index->search('', ['filter' => ['__identifier = ' . $identifier]]);

        $hits = [];
        foreach ($results->getHits() as $hit) {
            $hits[] = $hit['id'];
        }
        return $hits;
    }

    /**
     * Returns all index identifiers by identifier and dimensionsHash or FALSE if none exist.
     *
     * @param string $identifier
     * @param string $dimensionsHash
     * @return array|FALSE
     */
    public function findAllIdentifiersByIdentifierAndDimensionsHash(string $identifier, string $dimensionsHash)
    {
        $filter = [
            '__identifier = ' . $identifier,
            '__dimensionsHash = "' . $dimensionsHash . '"'
        ];
        $results = $this->index->search('', ['filter' => $filter]);

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
    public function search(string $query, array $parameters): SearchResult
    {
        if (isset($parameters['filter']) && is_array($parameters['filter'])) {
            $parameters['filter'] = implode(' AND ', $parameters['filter']);
        }

        $results = $this->index->search($query, $parameters);
        return $results;
    }

    /**
     * Block until the index has no unfinished task left, then fail if any task this
     * instance enqueued did not succeed.
     *
     * The wait asks about the index rather than about the individual uids: one list
     * request answers for the whole queue, where polling uid by uid would cost a
     * request per task and would apply the timeout to each task instead of to the run.
     *
     * @param int $timeoutInSeconds
     * @return void
     * @throws Exception
     */
    public function waitForPendingTasks(int $timeoutInSeconds): void
    {
        if ($this->pendingTaskUids === []) {
            return;
        }

        $uids = $this->pendingTaskUids;
        $this->pendingTaskUids = [];

        $deadline = microtime(true) + $timeoutInSeconds;
        while (true) {
            $unfinished = $this->firstUnfinishedTask();
            if ($unfinished === null) {
                break;
            }

            if (microtime(true) >= $deadline) {
                throw new Exception(sprintf(
                    'Index "%s" still had unfinished work after %d seconds: task %s (%s) is %s.',
                    $this->indexName,
                    $timeoutInSeconds,
                    (string) ($unfinished['uid'] ?? 'unknown'),
                    (string) ($unfinished['type'] ?? 'unknown type'),
                    (string) ($unfinished['status'] ?? 'unknown status')
                ), 1787000101);
            }

            usleep(self::WAIT_POLL_INTERVAL_IN_MS * 1000);
        }

        $this->failOnFailedTasks($uids);
    }

    /**
     * The oldest task the index still has to do, whoever enqueued it, or null once the
     * queue is empty. A slot must not go live on a half-built index, so waiting on our
     * own uids alone would not be enough.
     *
     * Asked for as a task rather than as a count on purpose: TasksResults::getTotal()
     * does not exist across the whole meilisearch-php range this package supports, and
     * the task that is holding things up says more about a wait that ran out than a
     * number does.
     *
     * @return array|null
     */
    protected function firstUnfinishedTask(): ?array
    {
        $query = (new TasksQuery())
            ->setIndexUids([$this->indexName])
            ->setStatuses(['enqueued', 'processing'])
            ->setLimit(1);

        $unfinished = $this->client->getTasks($query)->getResults();

        return $unfinished[0] ?? null;
    }

    /**
     * A failed indexing task is silently dropped by Meilisearch's queue, so an index
     * can finish its work and still be incomplete. Whoever asked to wait wants to hear
     * about that.
     *
     * @param array<int, int> $uids
     * @return void
     * @throws Exception
     */
    protected function failOnFailedTasks(array $uids): void
    {
        foreach (array_chunk($uids, self::TASK_QUERY_CHUNK_SIZE) as $chunk) {
            $query = (new TasksQuery())
                ->setUids($chunk)
                ->setStatuses(['failed'])
                ->setLimit(1);

            $failed = $this->client->getTasks($query)->getResults();
            if ($failed !== []) {
                throw new Exception(sprintf(
                    'Indexing task %s on index "%s" failed: %s',
                    (string) ($failed[0]['uid'] ?? 'unknown'),
                    $this->indexName,
                    (string) ($failed[0]['error']['message'] ?? 'no message reported')
                ), 1787000102);
            }
        }
    }

    /**
     * Meilisearch answers a write with the uid of the task it queued for it.
     *
     * @param array $task
     * @return void
     */
    protected function rememberTask(array $task): void
    {
        if (isset($task['taskUid'])) {
            $this->pendingTaskUids[] = (int) $task['taskUid'];
        }
    }

    /**
     * @return int
     */
    protected function batchSize(): int
    {
        return max(1, (int) ($this->indexingSettings['batchSize'] ?? self::DEFAULT_BATCH_SIZE));
    }

    /**
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * Überschreibt die aktuellen Index-Settings vollständig und wendet sie an.
     */
    public function updateCustomSettings(array $settings): void
    {
        $this->indexSettings = $settings;
        $this->index->updateSettings($this->withoutReembeddingChanges($this->indexSettings));
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
}
