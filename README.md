# Medienreaktor.Meilisearch

Integrates Meilisearch into Neos. *This is Work-in-Progress!*

This package aims for simplicity and minimal dependencies. It might therefore not be as sophisticated and extensible as packages like [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor), and to achieve this, some code parts had to be copied from these great packages (see Credits).

## ✨ Features

* ✅ Indexing the Neos Content Repository in Meilisearch
* ✅ Supports Content Dimensions for all node variants
* ✅ CLI commands for building and flushing the index
* ✅ Querying the index via Eel-Helpers as usually
* ✅ Faceting query to get facet distribution for node properties
* ⛔️ No asset indexing
* ⛔️ No autocomplete / autosuggest
* ⛔️ No frontend plugin (but can be used with [Flowpack.SearchPlugin](https://github.com/Flowpack/Flowpack.SearchPlugin))

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

After finishing or changing configuration, build the node index once via the CLI command `flow nodeindex:build`. 

## 📖 Usage

You can use search queries, results and facets in your Fusion components as usually:

    prototype(Vendor:Content.Search) < prototype(Neos.Neos:ContentComponent) {
        searchTerm = ${String.toString(request.arguments.search)}
        searchQuery = ${this.searchTerm ? Search.query(site).fulltext(this.searchTerm).nodeType('Neos.Neos:Document') : null}

        totalSearchResults = ${this.searchQuery.count()}
        facets = ${this.searchQuery.facets(['__typeAndSupertypes'])}
    }

If you want facet distribution for certain node properties or search in them, make sure to add them to `filterableAttributes` and/or `searchableAttributes` in your `Settings.yaml`.

## 👩‍💻 Credits

This package is heavily inspired by and some smaller code parts are copied from:

+ [Sandstorm.LightweightElasticsearch](https://github.com/sandstorm/LightweightElasticsearch)
+ [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor)
+ [Flowpack.SimpleSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.SimpleSearch.ContentRepositoryAdaptor)
+ [Flowpack.SearchPlugin](https://github.com/Flowpack/Flowpack.SearchPlugin)

All credits go to the original authors of these packages.
