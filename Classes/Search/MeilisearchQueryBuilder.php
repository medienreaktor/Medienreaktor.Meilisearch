<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Search;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;

/**
 * Meilisearch Query Builder for Content Repository searches
 */
class MeilisearchQueryBuilder implements QueryBuilderInterface, ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * The node inside which searching should happen
     *
     * @var NodeInterface
     */
    protected $contextNode;

    /**
     * @var string
     */
    protected $query = '';

    /**
     * @var array
     */
    protected $parameters = [];

    /**
     * Sort descending by $propertyName
     *
     * @param string $propertyName the property name to sort by
     * @return QueryBuilderInterface
     */
    public function sortDesc(string $propertyName): QueryBuilderInterface
    {
        $this->parameters['sort'][] = $propertyName . ':desc';
        return $this;
    }

    /**
     * Sort ascending by $propertyName
     *
     * @param string $propertyName the property name to sort by
     * @return QueryBuilderInterface
     */
    public function sortAsc(string $propertyName): QueryBuilderInterface
    {
        $this->parameters['sort'][] = $propertyName . ':asc';
        return $this;
    }

    /**
     * Limit results to $limit records
     *
     * @param int $limit
     * @return QueryBuilderInterface
     */
    public function limit($limit): QueryBuilderInterface
    {
        $this->parameters['limit'] = $limit;
        return $this;
    }

    /**
     * Fetch results starting at $from
     *
     * @param int $from
     * @return QueryBuilderInterface
     */
    public function from($from): QueryBuilderInterface
    {
        $this->parameters['offset'] = $from;
        return $this;
    }

    /**
     * Fetch results starting at $page
     *
     * @param int $page
     * @return QueryBuilderInterface
     */
    public function page($page): QueryBuilderInterface
    {
        $this->parameters['page'] = $page;
        return $this;
    }

    /**
     * Get $hitsPerPage results per page
     *
     * @param int $hitsPerPage
     * @return QueryBuilderInterface
     */
    public function hitsPerPage($hitsPerPage): QueryBuilderInterface
    {
        $this->parameters['hitsPerPage'] = $hitsPerPage;
        return $this;
    }

    /**
     * Filter results by filter string
     *
     * @param string $filterString
     * @return QueryBuilderInterface
     */
    public function filter(string $filterString): QueryBuilderInterface
    {
        $this->parameters['filter'][] = $filterString;
        return $this;
    }

    /**
     * Match a given node property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function exactMatch(string $propertyName, $propertyValue): QueryBuilderInterface
    {
        $this->parameters['filter'][] = $propertyName . ' = "' . $propertyValue . '"';
        return $this;
    }

    /**
     * Match multiple given node properties
     *
     * @param array $propertyNameValuePairs
     * @return QueryBuilderInterface
     */
    public function exactMatchMultiple(array $propertyNameValuePairs): QueryBuilderInterface
    {
        foreach ($propertyNameValuePairs as $propertyName => $propertyValue) {
            $this->parameters['filter'][] = $propertyName . ' = "' . $propertyValue . '"';
        }
        return $this;
    }

    /**
     * Match the search word against the fulltext index
     *
     * @param string $searchWord
     * @param array $options
     * @return QueryBuilderInterface
     */
    public function fulltext(string $searchWord, array $options = []): QueryBuilderInterface
    {
        $this->query = $searchWord;
        return $this;
    }

    /**
     * Use combined search capabilities of fulltext and vector search.
     *
     * @param string $searchWord
     * @param array $options
     * @return QueryBuilderInterface
     */
    public function hybrid(string $searchWord, array $options = []): QueryBuilderInterface
    {
        $this->query = $searchWord;
        $embedder = $options['embedder'] ?? 'default';
        $semanticRatio = $options['semanticRatio'] ?? 0.5;
        $this->parameters['hybrid'] = [
            'embedder' => $embedder,
            'semanticRatio' => $semanticRatio
        ];
        return $this;
    }

    /**
     * Query the search index using only the vector search capabilities.
     * @param string $searchWord
     * @param array $options
     * @return QueryBuilderInterface
     */
    public function vector(string $searchWord, array $options = []): QueryBuilderInterface
    {
        $this->query = $searchWord;
        $embedder = $options['embedder'] ?? 'default';
        $semanticRatio = $options['semanticRatio'] ?? 1.0;
        $this->parameters['hybrid'] = [
            'embedder' => $embedder,
            'semanticRatio' => $semanticRatio
        ];
        return $this;
    }

    /**
     * Select attributes to highlight
     *
     * @param array $attributes
     * @param array $highlightTags
     * @return QueryBuilderInterface
     */
    public function highlight(array $attributes, array $highlightTags = ['<em>', '</em>']): QueryBuilderInterface
    {
        $this->parameters['attributesToCrop'] = $attributes;
        $this->parameters['attributesToHighlight'] = $attributes;
        $this->parameters['cropLength'] = 20;
        $this->parameters['highlightPreTag'] = $highlightTags[0];
        $this->parameters['highlightPostTag'] = $highlightTags[1];
        return $this;
    }

    /**
     * Set highlight crop length and marker
     *
     * @param int $cropLength
     * @param string $cropMarker
     * @return QueryBuilderInterface
     */
    public function crop($cropLength, string $cropMarker = '…'): QueryBuilderInterface
    {
        $this->parameters['cropLength'] = $cropLength;
        $this->parameters['cropMarker'] = $cropMarker;
        return $this;
    }

    /**
     * Sets the matching strategy
     *
     * @param string $matchingStrategy
     * @return QueryBuilderInterface
     */
    public function matchingStrategy(string $matchingStrategy): QueryBuilderInterface
    {
        $this->parameters['matchingStrategy'] = $matchingStrategy;
        return $this;
    }
    
    /**
     * Setzt the Distinct Attribute.
     * @param string $attribute
     * @return QueryBuilderInterface
     */
    public function distinct(string $attribute): QueryBuilderInterface
    {
        $this->parameters['distinct'] = $attribute;
        return $this;
    }

    /**
     * Execute the query and return the list of nodes as result
     *
     * @return \Traversable<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function execute(): \Traversable
    {
        $results = $this->indexClient->search($this->query, $this->parameters);

        $nodes = [];
        foreach ($results->getHits() as $hit) {
            $nodePath = $hit['__path'];
            $node = $this->contextNode->getNode($nodePath);
            if ($node instanceof NodeInterface) {
                $nodes[(string) $node->getNodeAggregateIdentifier()] = $node;
            }
        }
        return (new \ArrayObject(array_values($nodes)))->getIterator();
    }

    /**
     * Execute the query and return the raw results enriched with node information
     *
     * @return \Traversable<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function executeRaw(): \Traversable
    {
        $results = $this->indexClient->search($this->query, $this->parameters);

        $hits = [];

        foreach ($results->getHits() as $hit) {
            if (isset($hit['__path']) && str_contains($hit['__path'], 'assets')) {
                $hits[$hit['__uri']] = $hit;
                continue;
            }

            $nodePath = $hit['__path'];
            $node = $this->contextNode->getNode($nodePath);
            if ($node instanceof NodeInterface) {
                $hit['__node'] = $node;
                $hits[(string) $node->getNodeAggregateIdentifier()] = $hit;
            }
        }

        return (new \ArrayObject(array_values($hits)))->getIterator();
    }

    /**
     * Execute the query and return the raw results enriched with node information
     *
     * @return \Traversable<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function executeWithoutProcessing(): \Traversable
    {
        $results = $this->indexClient->search($this->query, $this->parameters);

        $hits = [];

        foreach ($results->getHits() as $hit) {
            $hits[$hit['id']] = $hit;
        }

        return (new \ArrayObject(array_values($hits)))->getIterator();
    }

    /**
     * Return the total number of hits for the query.
     *
     * @return int
     */
    public function count(): int
    {
        $results = $this->indexClient->search($this->query, $this->parameters);
        return $results->getEstimatedTotalHits();
    }

    /**
     * Return the total number of pages for the query.
     *
     * @return int
     */
    public function totalPages(): int
    {
        $results = $this->indexClient->search($this->query, $this->parameters);
        return $results->getTotalPages();
    }

    /**
     * Return the total number of hits for the query.
     *
     * @return int
     */
    public function totalHits(): int
    {
        $results = $this->indexClient->search($this->query, $this->parameters);
        return $results->getTotalHits();
    }

    /**
     * Get facet distribution for given facets.
     *
     * @param array $facets
     * @return array
     */
    public function facets(array $facets): array
    {
        $this->parameters['facets'] = $facets;

        $results = $this->indexClient->search($this->query, $this->parameters);
        return $results->getFacetDistribution();
    }

    /**
     * Sets the starting point for this query. Search result should only contain nodes that
     * match the context of the given node and have it as parent node in their rootline.
     *
     * @param NodeInterface $contextNode
     * @return QueryBuilderInterface
     */
    public function query(NodeInterface $contextNode): QueryBuilderInterface
    {
        $this->contextNode = $contextNode;
        $nodePath = (string) $contextNode->findNodePath();
        $dimensionsHash = $this->dimensionsService->hashByNode($contextNode);

        $this->parameters['filter'][] = '(__parentPath = "' . $nodePath . '" OR __path = "' . $nodePath . '")';
        $this->parameters['filter'][] = '__dimensionsHash = "' . $dimensionsHash . '"';

        return $this;
    }

    /**
     * Filter by node type, taking inheritance into account.
     *
     * @param string $nodeType the node type to filter for
     * @return QueryBuilderInterface
     */
    public function nodeType(string $nodeType): QueryBuilderInterface
    {
        $this->parameters['filter'][] = '__nodeTypeAndSupertypes = "' . $nodeType . '"';
        return $this;
    }

    /**
     * Filter by geo radius.
     *
     * @param string $lat latitude
     * @param string $lng longitude
     * @param string $distance distance in meters
     * @return QueryBuilderInterface
     */
    public function geoRadius(string $lat, string $lng, string $distance): QueryBuilderInterface
    {
        $this->parameters['filter'][] = '_geoRadius(' . $lat . ', ' . $lng . ', ' . $distance . ')';
        return $this;
    }

    /**
     * Sort by geo point.
     *
     * @param string $lat latitude
     * @param string $lng longitude
     * @return QueryBuilderInterface
     */
    public function geoPoint(string $lat, string $lng): QueryBuilderInterface
    {
        $this->parameters['sort'][] = '_geoPoint(' . $lat . ', ' . $lng . '):asc';
        return $this;
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
