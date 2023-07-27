# Medienreaktor.Meilisearch

Integrates Meilisearch into Neos. *This is Work-in-Progress!*

This package aims for simplicity and minimal dependencies. It might therefore not be as sophisticated and extensible as packages like [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor), and to achieve this, some code parts had to be copied from these great packages (see Credits).

## ✨ Features

* ✅ Indexing the Neos Content Repository in Meilisearch
* ✅ Supports Content Dimensions for all node variants
* ✅ CLI commands for building and flushing the index
* ✅ Querying the index via Search-/Eel-Helpers and QueryBuilder
* ✅ Frontend search form, result rendering and pagination
* ✅ Faceting and snippet highlighting
* 🟠 Only indexing the Live-Workspace for now
* 🟠 Documentation (this README) just covers the basics
* 🔴 No asset indexing (yet)
* 🔴 No autocomplete / autosuggest (this is currently not supported by Meilisearch)

## 🚀 Installation

Install via composer:

    composer require medienreaktor/meilisearch

## ⚙️ Configuration

Configure the Meilisearch client in your `Settings.yaml` and set the Endpoint and API Key:

```yaml
Medienreaktor:
  Meilisearch:
    client:
      endpoint: ''
      apiKey: ''
```

You can adjust all Meilisearch index settings to fit your needs (see [Meilisearch Documentation](https://www.meilisearch.com/docs/reference/api/settings)):

```yaml
Medienreaktor:
  Meilisearch:
    settings:
      filterableAttributes:
        - '__identifier'
        - '__dimensionshash'
        - '__path'
        - '__parentPath'
        - '__type'
        - '__typeAndSupertypes'
        - '_hidden'
        - '_hiddenBeforeDateTime'
        - '_hiddenAfterDateTime'
        - '_hiddenInIndex'
      searchableAttributes:
        - '__fulltext.text'
        - '__fulltext.h1'
        - '__fulltext.h2'
        - '__fulltext.h3'
        - '__fulltext.h4'
        - '__fulltext.h5'
        - '__fulltext.h6'
```

Please do not remove, only extend, above `filterableAttributes`, as they are needed for base functionality to work.

After finishing or changing configuration, build the node index once via the CLI command `flow nodeindex:build`. 

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

## 📖 Usage

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

        facets = ${this.searchQuery.facets(['__type', '__parentPath'])}
        totalPages = ${this.searchQuery.totalPages()}
        totalHits = ${this.searchQuery.totalHits()}
    }

If you want facet distribution for certain node properties or search in them, make sure to add them to `filterableAttributes` and/or `searchableAttributes` in your `Settings.yaml`.

The search query builder currently supports the following features:

| Query feature                                | Description                                                |
|----------------------------------------------|------------------------------------------------------------|
| `query(context)`                             | Sets the starting point for this query, e.g. `query(site)` |
| `nodeType(nodeTypeName)`                     | Filters by the given NodeType, e.g. `nodeType('Neos.Neos:Document')` |
| `fulltext(searchTerm)`                       | Performs a fulltext search |
| `exactMatch(propertyName, value)`            | Filters by a node property |
| `exactMatchMultiple(array)`                  | Filters by multiple node properties, e.g. `exactMatchMultiple(['author' => 'foo', 'date' => 'bar'])` |
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
| `highlight(array)`                           | Highlight search results for given properties, e.g. `highlight(['__fulltext.text'])` |
| `execute()`                                  | Execute the query and return resulting nodes |
| `executeRaw()`                               | Execute the query and return raw Meilisearch result data, enriched with node data |

## 👩‍💻 Credits

This package is heavily inspired by and some smaller code parts are copied from:

+ [Sandstorm.LightweightElasticsearch](https://github.com/sandstorm/LightweightElasticsearch)
+ [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor)
+ [Flowpack.SimpleSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.SimpleSearch.ContentRepositoryAdaptor)
+ [Flowpack.SearchPlugin](https://github.com/Flowpack/Flowpack.SearchPlugin)

All credits go to the original authors of these packages.
