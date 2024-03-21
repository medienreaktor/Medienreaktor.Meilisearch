# Medienreaktor.Meilisearch

Integrates Meilisearch into Neos. 
**Compatibility tested with Meilisearch 1.3.**

This package aims for simplicity and minimal dependencies. It might therefore not be as sophisticated and extensible as packages like [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor), and to achieve this, some code parts had to be copied from these great packages (see Credits).

## ✨ Features

* ✅ Indexing the Neos Content Repository in Meilisearch
* ✅ Supports Content Dimensions for all node variants
* ✅ CLI commands for building and flushing the index
* ✅ Querying the index via Search-/Eel-Helpers and QueryBuilder
* ✅ Frontend search form, result rendering and pagination
* ✅ Faceting and snippet highlighting
* ✅ Geosearch filtering and sorting
* ✅ Vector Search for semantic search / AI search
* 🔴 No asset indexing (yet)
* 🔴 No autocomplete / autosuggest (this is currently not supported by Meilisearch)

## 🚀 Installation

Install via composer:

    composer require medienreaktor/meilisearch

There are several ways to install Meilisearch for development. If you are using DDEV, there is a [Meilisearch-snippet](https://github.com/ddev/ddev-contrib/tree/master/docker-compose-services/meilisearch).

## ⚙️ Configuration

Configure the Meilisearch client in your `Settings.yaml` and set the Endpoint and API Key:

```yaml
Medienreaktor:
  Meilisearch:
    client:
      endpoint: ''
      apiKey: ''
```

You can adjust all Meilisearch index settings to fit your needs (see [Meilisearch Documentation](https://www.meilisearch.com/docs/reference/api/settings)). All settings configured here will directly be passed to Meilisearch.

```yaml
Medienreaktor:
  Meilisearch:
    settings:
      displayedAttributes:
        - '*'
      searchableAttributes:
        - '__fulltext.text'
        - '__fulltext.h1'
        - '__fulltext.h2'
        - '__fulltext.h3'
        - '__fulltext.h4'
        - '__fulltext.h5'
        - '__fulltext.h6'
      filterableAttributes:
        - '__identifier'
        - '__dimensionsHash'
        - '__path'
        - '__parentPath'
        - '__nodeType'
        - '__nodeTypeAndSupertypes'
        - '_hidden'
        - '_hiddenBeforeDateTime'
        - '_hiddenAfterDateTime'
        - '_hiddenInIndex'
        - '_geo'
      sortableAttributes:
        - '_geo'
      rankingRules:
        - 'words'
        - 'typo'
        - 'proximity'
        - 'attribute'
        - 'sort'
        - 'exactness'
      stopWords: []
      typoTolerance:
        enabled: true
        minWordSizeForTypos:
          oneTypo: 5
          twoTypos: 9
      faceting:
        maxValuesPerFacet: 100
```

Please do not remove, only extend, above `filterableAttributes`, as they are needed for base functionality to work. After finishing or changing configuration, build the node index once via the CLI command `flow nodeindex:build`. 

Document NodeTypes should be configured as fulltext root (this comes by default for all `Neos.Neos:Document` subtypes):

```yaml
'Neos.Neos:Document':
  search:
    fulltext:
      isRoot: true
      enable: true
```

Properties of Content NodeTypes that should be included in fulltext search must also be configured appropriately:

```yaml
'Neos.NodeTypes:Text':
  search:
    fulltext:
      enable: true
  properties:
    text:
      search:
        fulltextExtractor: "${Indexing.extractHtmlTags(node.properties.text)}"
```

You will see that some properties are indexed twice, like `_path` and `__path`, `_nodeType` and `__nodeType`. This is due to the different _privacy_ of these node properties:

* `_*`-properties are default Neos node properties that are private to Neos (and may change)
* `__*`-properties are private properties that are required for the Meilisearch-integration

We have to make sure that our required properties are always there, so we better index them separately.

## 📖 Usage with Neos and Fusion

There is a built-in Content NodeType `Medienreaktor.Meilisearch:Search` for rendering the search form, results and pagination that may serve as a boilerplate for your projects. Just place it on your search page to start.

You can also use search queries, results and facets in your own Fusion components.

    prototype(Medienreaktor.Meilisearch:Search) < prototype(Neos.Neos:ContentComponent) {
        searchTerm = ${String.toString(request.arguments.search)}

        page = ${String.toInteger(request.arguments.page) || 1}
        hitsPerPage = 10

        searchQuery = ${this.searchTerm ? Search.query(site).fulltext(this.searchTerm).nodeType('Neos.Neos:Document') : null}
        searchQuery.@process {
            page = ${value.page(this.page)}
            hitsPerPage = ${value.hitsPerPage(this.hitsPerPage)}
        }

        facets = ${this.searchQuery.facets(['__nodeType', '__parentPath'])}
        totalPages = ${this.searchQuery.totalPages()}
        totalHits = ${this.searchQuery.totalHits()}
    }

If you want facet distribution for certain node properties or search in them, make sure to add them to `filterableAttributes` and/or `searchableAttributes` in your `Settings.yaml`.

The search query builder supports the following features:

| Query feature                                | Description                                                |
|----------------------------------------------|------------------------------------------------------------|
| `query(context)`                             | Sets the starting point for this query, e.g. `query(site)` |
| `nodeType(nodeTypeName)`                     | Filters by the given NodeType, e.g. `nodeType('Neos.Neos:Document')` |
| `fulltext(searchTerm)`                       | Performs a fulltext search |
| `vector(vector)`                             | Performs a vector search (see below) |
| `filter(filterString)`                       | Filters by given filter string, e.g. `filter('__nodeTypeAndSupertypes = "Neos.Neos:Document"')` (see [Meilisearch Documentation](https://www.meilisearch.com/docs/reference/api/search#filter))  |
| `exactMatch(propertyName, value)`            | Filters by a node property |
| `exactMatchMultiple(properties)`             | Filters by multiple node properties, e.g. `exactMatchMultiple(['author' => 'foo', 'date' => 'bar'])` |
| `sortAsc(propertyName)`                      | Sort ascending by property |
| `sortDesc(propertyName)`                     | Sort descending by property |
| `limit(value)`                               | Limit results, e.g. `limit(10)` |
| `from(value)`                                | Return results starting from, e.g. `from(10)` |
| `page(value)`                                | Return paged results for given page, e.g. `page(1)` |
| `hitsPerPage(value)`                         | Hits per page for paged results, e.g. `hitsPerPage(10)` |
| `count()`                                    | Get total results count for non-paged results |
| `totalHits()`                                | Get total hits for paged results |
| `totalPages()`                               | Get total pages for paged results |
| `facets(array)`                              | Return facet distribution for given facets, e.g. `facets(['__type', '__parentPath'])` |
| `highlight(properties, highlightTags)`       | Highlight search results for given properties, e.g. `highlight(['__fulltext.text'])`, highlighted with given tags (optional, default: `['<em'>, '</em>']`) |
| `crop(cropLength, cropMarker)`               | Sets the highlighting snippets length in words and the crop marker (optional, default: `'…'`) |
| `matchingStrategy(value)`                    | Sets the matching strategy `'last'` or `'all'`, (default: `'last'`) |
| `geoRadius(lat, lng, distance)`              | Filters by geo radius |
| `geoPoint(lat, lng)`                         | Sort by geo point |
| `execute()`                                  | Execute the query and return resulting nodes |
| `executeRaw()`                               | Execute the query and return raw Meilisearch result data, enriched with node data |

## ⚡ Usage with JavaScript / React / Vue

If you want to build your frontend with JavaScript, React or Vue, you can completely ignore above Neos and Fusion integration and use `instant-meilisearch`. 

Please mind these three things:

### 1. Filtering for node context and dimensions

Setup your filter to always include the following filter string:
`(__parentPath = "$nodePath" OR __path = "$nodePath") AND __dimensionsHash = "$dimensionsHash"`
where `$nodePath` is the NodePath of your context node (e.g. site) and `$dimensionHash` is the MD5-hashed JSON-encoded context dimensions array.

You can obtain these values in PHP using:

```php
$nodePath = (string) $contextNode->findNodePath();
$dimensionsHash = md5(json_encode($contextNode->getContext()->getDimensions()));
```

In Fusion, you get these values (assuming `site` is your desired context node) using:

```
nodePath = ${site.path}
dimensionsHash = ${String.md5(Json.stringify(site.context.dimensions))}
```

### 2. The node URI

The public URI to the node is in the `__uri` attribute of each Meilisearch result hit. 

It is generated at indexing time and one reason we create separate index records for each node variant, even if they are redundant due to dimension fallback behaviour. This is in contrast to Flowpack.ElasticSearch.ContentRepositoryAdaptor, where only one record is created and multiple dimensions hashes are assigned.

For the URI generation to work, it is important to have a primary domain assigned to each of your sites.

### 3. Image URIs

If you need image URIs in your frontend, this can also be configured. First, make sure to set a base URL in your `Settings.yaml`:

```
Neos:
  Flow:
    http:
      baseUri: https://example.com/
```

Then, either configure your specific properties or all image properties to be indexed:

```
Neos:
  ContentRepository:
    Search:
      defaultConfigurationPerType:
        Neos\Media\Domain\Model\ImageInterface:
          indexing: '${AssetUri.build(value, 600, 400)}'
```

You can set your desired `width`, `height` and optional `allowCropping` values in the method arguments.

## 📍 Geosearch

Meilisearch supports filtering and sorting on geographic location. For this feature to work, your nodes should supply the `__geo` property with an object of `lat`/`lng` values. An easy way to achieve this is to use a proxy property:

```
'Neos.Neos:Document':
  properties:
    latitude:
      type: 'string'
      ui:
        label: 'Latitude'
    longitude:
      type: 'string'
      ui:
        label: 'Longitude'
    __geo:
      search:
        indexing: "${{lat: node.properties.latitude, lng: node.properties.longitude}}"
```

The search query builder supports filtering with `geoRadius()` and sorting with `geoPoint()` (see above).

## 📐 Vector Search

You can use Meilisearch as a vector store with the experimental Vector Search feature. Activate it using the `/experimental-features` endpoint as [described in the release notes](https://github.com/meilisearch/meilisearch/releases/tag/v1.3.0).

Vectors for each document have to be provided by you and indexed in the `_vector`-property of your node. This can be done writing a custom Eel-helper that computes the vectors using a third-party tool like [OpenAI](https://openai.com) or [Hugging Face](https://huggingface.co).

```
'Neos.Neos:Document':
  properties:
    _vector:
      search:
        indexing: "${VectorIndexing.computeByNode(node)}"
```

The search query builder supports querying by vectors. Depending on your use case, vectors have to be computed again for the search phrase, e.g.:

    prototype(Medienreaktor.Meilisearch:Search) < prototype(Neos.Neos:ContentComponent) {
        searchTerm = ${String.toString(request.arguments.search)}
        searchVector = ${VectorIndexing.computeByString(this.searchTerm)}

        vectorSearchQuery = ${this.searchVector ? Search.query(site).vector(this.searchVector) : null}

        searchResults = ${this.vectorSearchQuery.execute()}
    }

To show similar documents to your current document (e.g. for Wikis, Knowledge Bases or News Rooms), use the current document's vector as search vector.

## 👩‍💻 Credits

This package is heavily inspired by and some smaller code parts are copied from:

+ [Sandstorm.LightweightElasticsearch](https://github.com/sandstorm/LightweightElasticsearch)
+ [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor)
+ [Flowpack.SimpleSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.SimpleSearch.ContentRepositoryAdaptor)
+ [Flowpack.SearchPlugin](https://github.com/Flowpack/Flowpack.SearchPlugin)

All credits go to the original authors of these packages.
