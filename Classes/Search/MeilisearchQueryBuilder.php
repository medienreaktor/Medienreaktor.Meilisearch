<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Search;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

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
        $this->parameters['sort'] = [$propertyName . ':desc'];
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
        $this->parameters['sort'] = [$propertyName . ':asc'];
        return $this;
    }

    /**
     * output only $limit records
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
     * output records starting at $from
     *
     * @param integer $from
     * @return QueryBuilderInterface
     */
    public function from($from): QueryBuilderInterface
    {
        $this->parameters['offset'] = $from;
        return $this;
    }

    /**
     * add an exact-match query for a given property
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
     * Match the searchword against the fulltext index
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
     * Execute the query and return the list of nodes as result
     *
     * @return \Traversable<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function execute(): \Traversable
    {
        $parameters = $this->parameters;
        $parameters['filter'] = implode(' AND ', $this->parameters['filter']);
        $results = $this->indexClient->search($this->query, $parameters);

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
     * Return the total number of hits for the query.
     *
     * @return int
     */
    public function count(): int
    {
        $parameters = $this->parameters;
        $parameters['filter'] = implode(' AND ', $this->parameters['filter']);

        $results = $this->indexClient->search($this->query, $parameters);
        return $results->getEstimatedTotalHits();
    }

    /**
     * Get facet distribution for given facets.
     *
     * @param array $facets
     * @return array
     */
    public function facets(array $facets): array
    {
        $parameters = $this->parameters;
        $parameters['filter'] = implode(' AND ', $this->parameters['filter']);
        $parameters['facets'] = $facets;

        $results = $this->indexClient->search($this->query, $parameters);
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
        $dimensionsHash = md5(json_encode($contextNode->getContext()->getDimensions()));

        $this->parameters['filter'][] = '(__parentPath = "' . $nodePath . '" OR __path = "' . $nodePath . '")';
        $this->parameters['filter'][] = '__dimensionshash = "' . $dimensionsHash . '"';

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
        $this->parameters['filter'][] = '__typeAndSupertypes = "' . $nodeType . '"';
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
