<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use Neos\Flow\Annotations as Flow;

/**
 * Meilisearch Index client
 */
class MeilisearchIndex implements IndexInterface
{
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
    public function __construct(string $indexName)
    {
        $this->indexName = $indexName;
    }

    public function initializeObject(): void
    {
        $this->client = new Client($this->clientSettings['endpoint'], $this->clientSettings['apiKey']);
        $this->index = $this->client->index($this->indexName);
    }

    public function createIndex(): void
    {
        $this->client->createIndex($this->indexName);
        $this->index->updateSettings($this->indexSettings);
    }

    /**
     * @param array $documents Documents to add to the index
     * @return void
     */
    public function addDocuments(array $documents): void
    {
        $this->index->addDocuments($documents);
    }

    /**
     * @param array $documents Documents to delete from the index
     * @return void
     */
    public function deleteDocuments(array $documents): void
    {
        $this->index->deleteDocuments($documents);
    }

    /**
     * Delete all documents from the index.
     */
    public function deleteAllDocuments(): void
    {
        $this->index->deleteAllDocuments();
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
        $results = $this->index->search('', ['filter' => ['__identifier = '.$identifier]]);

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
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }
}
