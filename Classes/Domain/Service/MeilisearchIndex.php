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
     * Delete the index.
     */
    public function deleteIndex() :void
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
            return; // nichts zu löschen
        }

        // Falls bereits das erwartete Options-Array übergeben wurde (['filter' => '...']) direkt verwenden
        if (array_key_exists('filter', $filter)) {
            $options = $filter;
        } else {
            // Liste von Bedingungen (z.B. ['__identifier = 123', '__dimensionsHash = "abc"']) in einen AND-Ausdruck umwandeln
            $options = ['filter' => implode(' AND ', $filter)];
        }

        try {
            $this->index->deleteDocuments($options);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            // Optional: Logging hier möglich. Still schlucken oder rethrow? Aktuell still, um Verhalten der anderen Methoden zu spiegeln.
        }
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
        $this->index->updateSettings($this->indexSettings);
    }
}
