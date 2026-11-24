# Changelog

All notable changes to the Neos 9 line of this package, 3.x, are documented in this
file. The Neos 8 line, 2.x, lives on the `neos8` branch and keeps its own changelog
there.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.2.0] - 2026-11-24

A project that configures and calls the package needs no changes. Code that implements
`IndexInterface` itself has to add the methods it gained and accept a string filter in
`deleteByFilter()` (see Added), and a subclass that overrides
`NodeIndexer::indexNode()` or `MeilisearchQueryBuilder::vector()` their new optional
parameters. Run `nodeindex:rebuild` once after updating to pick up the indexing fixes:
hidden pages leave the index, `_hidden`, `_hiddenInIndex`, `__uri` and `__sortIndex`
are filled correctly, and thumbnail URIs are stable.

### Added

- `indexing.allowReembedding` (default `false`) and `--allow-reembedding` on
  `nodeindex:createindex` and `nodeindex:build`, to apply embedder changes that
  re-embed the index on purpose. As on the Neos 8 line (2.14.0).
- The `Meilisearch` Eel helper for frontend tenant tokens
  (`Meilisearch.tenantToken()`, `searchRules()`, `frontendFilter()`) with the settings
  `client.readonlyApiKey`, `client.readonlyApiKeyUid` and `tenantToken.expiresIn`
  (0, the default, for tokens without expiry), as on the Neos 8 line. On Neos 9 the
  dimensions argument is a dimension space point or its coordinates instead of
  `site.context.dimensions`; `[]` uses the site node's own.
- The `Dimensions` Eel helper (`Dimensions.hash(dimensions)`,
  `Dimensions.hashByNode(node)`) and `DimensionsService::hash()` and `hashByNode()`,
  as on the Neos 8 line. The Neos 8 line's dimension combinations have no
  counterpart: the content repository's variation graph replaces them. On Neos 9 the
  hash is the dimension space point's own hash, which `__dimensionsHash`
  already holds; Neos 8 style values lists count with their first value. Documents
  carry the node's coordinates as `__dimensions` again.
- `NodeIndexer::indexNode()` takes `$indexAllDimensions` and
  `$indexFallbackDimensions`, as on the Neos 8 line: index every dimension space point
  (default), only the node's origin and those falling back to it, or only the one the
  node was read in.
- `nodeindex:build --wait [--timeout <seconds>]` returns only once Meilisearch has no
  unfinished task for the index and fails if a task of the run failed;
  `--assume-empty-index` skips deletions on an index the caller has just emptied.
  `IndexInterface` gains `deleteByIdentifiers()` and `waitForPendingTasks()` for them.
  As on the Neos 8 line (2.11.0).
- `enableFulltext: false` leaves `__fulltext` out of the documents, and
  `neededAttributesForIndex` lists attributes without which a node is removed from
  the index instead of indexed. Both as on the Neos 8 line.
- `MeilisearchIndex::updateCustomSettings()` replaces and applies an index's
  settings, for custom indices with settings of their own, and
  `findAllIdentifiersByIdentifierAndDimensionsHash()` lists a node's documents in one
  dimension space point. `deleteByFilter()` also takes Meilisearch's options array
  (`['filter' => '...']`) and ignores an empty filter instead of sending it. All as on
  the Neos 8 line.
- `executeWithoutProcessing()` returns the raw hits without resolving nodes, and
  `executeRaw()` returns asset documents written into the same index (`__isAsset`, or
  an `__path` containing `assets`) instead of failing on their missing
  `__aggregateId`. Both as on the Neos 8 line.
- `distinct(attribute)` on the query builder, as on the Neos 8 line.
- `hybrid(searchTerm, {embedder, semanticRatio})` on the query builder combines
  keyword and vector search (Meilisearch 1.6 or newer with an embedder).
- The `IndexPath` Eel helper and `IndexPathService` for path filters outside the query
  builder: `IndexPath.of(node)` is the node's own path, the `__path` of its children;
  `IndexPath.below(node)` and `IndexPath.selfAndBelow(node)` are ready-made filters
  for its subtree, without and with the node itself. In PHP, `pathOf()`,
  `belowFilter()` and `selfAndBelowFilter()`. The indexed `__path` and `__parentPath`
  are unchanged.
- `vector()` also takes a search term and options, as on the Neos 8 line:
  `vector(searchTerm, {embedder, semanticRatio})` searches through a Meilisearch
  embedder configured in `settings.embedders`. `vector(array $vector)` with a
  precomputed vector works as before.
- `nodeindex:deleteindex` deletes the index itself, settings included, as on the
  Neos 8 line; `nodeindex:flush` still only removes the documents.
- `search()` and `deleteIndex()` are declared on `IndexInterface`. The query builder
  called `search()` through the interface although only the implementation had it.
- `RequestService::getSiteNode()`, `RequestService::getControllerContext()` and
  `NodeLinkService::getSiteNodeFromNode()`, as on the Neos 8 line.

### Changed

- The 3.x line is developed on `main` now; the `neos9` branch is gone. The Neos 8
  line, 2.x, continues on `neos8`.

### Fixed

- Applying settings no longer re-embeds a whole index as a side effect. Meilisearch
  regenerates the embedding of every document when an embedder changes in any field
  but its `apiKey`, and `createIndex()` - run by `nodeindex:createindex` and
  `nodeindex:build` - and `updateCustomSettings()` sent the configured settings
  unchecked. On an index that already has documents they now hold back embedder
  changes that would re-embed it, adding an embedder included, and still apply a new
  `apiKey`. If the index cannot be read, no settings are sent. The commands report
  every change they held back. As on the Neos 8 line (2.14.0).
- `indexing.maxFulltextBytes` limits fulltext to that many bytes. The text was cut to
  that many characters, so text with umlauts and other non-ASCII characters stayed up to
  four times over the limit.
- `__sortIndex` holds the node's position among its siblings. It read `node.index`,
  which Neos 9 nodes do not have, and was null on every document. Reindex to fill it.
- `nodeindex:catchup` builds its documents through `NodeIndexer::extractNodeVariant()`,
  as `nodeindex:build` and real-time indexing do, so an override or aspect that
  leaves nodes out of the index (returning null there) applies to it too. It wrote
  them past that method, so such nodes were indexed until the next rebuild.
- `execute()` returns the nodes it found. It looked hits up by `__path` as a path of
  node names, which a path of aggregate ids is not, and so returned nothing; hits are
  resolved by `__aggregateId` now.
- No stray `Neos.Neos:Timable` node type. The package configured the Neos 8 mixin,
  which Neos 9 does not have, and so created a node type of that name.
- `AssetUri.build()` indexes stable proxy URIs (`/search/thumbnail/<asset id>?width=…`)
  served by the new `ThumbnailController`, which creates the thumbnail on request and
  redirects to it. The indexed URIs pointed at a thumbnail entity or a resource, and
  broke with `media:clearthumbnails` or a resource cleanup. The route is registered
  before Neos' own and open to everybody. As on the Neos 8 line (2.6.0); reindex to
  get the new URIs.
- `Search.query(node)` filters on the dimension space point's hash, as indexed in
  `__dimensionsHash`. It hashed the JSON of the coordinates in their configured order,
  which differs from the indexed hash whenever that order is not alphabetical, e.g.
  for sites with more than one dimension.
- Real-time and catch-up indexing write in batches instead of one deletion and one
  addition per node. `NodeIndexer` implements `BulkNodeIndexerInterface`: inside
  `withBulkProcessing()`, as Neos.ContentRepository.Search drains its queue, `flush()`
  writes only a full buffer, and the rest is written when Flow shuts the indexer down.
  A node reindexed twice in one batch no longer keeps a variant the second run dropped.
  As on the Neos 8 line (2.11.0, 2.13.0).
- `nodeindex:build` and `nodeindex:rebuild` release Doctrine's identity map once per
  write batch, so entities loaded while extracting properties do not pile up for the
  whole run (as on the Neos 8 line, 2.12.0).
- Hidden (disabled) nodes and everything below them are not indexed, and a node that
  becomes hidden leaves the index on its next reindex, as on the Neos 8 line.
  `nodeindex:build`, `nodeindex:rebuild` and `nodeindex:catchup` read live without
  removed nodes only, so hidden pages were indexed - or the build aborted on them,
  since Neos 9 cannot route a disabled node - and real-time indexing read without any
  constraint, removed nodes included. A page's fulltext no longer contains its hidden
  content elements either. Run `nodeindex:rebuild` to drop hidden pages.
- `_hidden` and `_hiddenInIndex` are indexed from Neos 9 node data (the `disabled`
  subtree tag and the `hiddenInMenu` property). They read `node.hidden` and
  `node.hiddenInIndex`, which Neos 9 nodes do not have, and were null on every
  document.
- No "implicitly nullable parameter" deprecations on PHP 8.4:
  `IndexFactory::create()`, `RequestService::createActionRequest()` and
  `NodeIndexer::extractPropertiesAndFulltext()` declare their nullable parameters.
- `__uri` is relative when the node's site has no active primary domain, as on the
  Neos 8 line. It was always forced absolute, so such sites indexed
  `http://domain.dummy/...`. Reindex to get the corrected URIs.
- `RequestService::createActionRequest()` without a node, as `AssetUri.build()`
  calls it, no longer fails on a null node.
- `NodeLinkService` and `RequestService` no longer reference Neos 8 classes
  (`Neos\ContentRepository\Domain\Service\Context`, `NodeInterface`).

## [3.1.0] - 2026-11-18

### Added

- `nodeindex:catchup` indexes what changed in live since its last run, from cron
  between nightly rebuilds. It reads the content repository's events after a stored
  position, so publishing stays as fast as before. Without it, changes reached the
  index only with the next `nodeindex:build` or `nodeindex:rebuild`. See "Keeping the
  index up to date" in the README.

## [3.0.3] - 2026-11-10

### Fixed

- The package no longer declares a node type named `unstructured`. Its indexing
  configuration was a Neos 8 leftover: Neos 9 has no such node type, so the block
  created a new, unprefixed one instead of configuring an existing one. Neos.Api
  rejects node type names without a package prefix, so its node type listing failed
  with a 500 and Neos Studio 2 did not load. Nothing was indexed through it, as no
  node has that type; no reindex needed.

## [3.0.2] - 2026-10-26

### Fixed

- Content node types configured as fulltext root (`search.fulltext.isRoot: true`,
  e.g. single FAQ entries) are indexed. Building their URI threw
  `NoMatchingRouteException`, because only documents have a route, so on sites with
  such a node type `nodeindex:build` and `nodeindex:rebuild` aborted and indexed
  nothing new, pages included; indexing such a node in real time failed the same way.
  These nodes are now indexed with `__uri: null`; documents keep their URI. No reindex
  needed, since only nodes that failed to index before are affected; sites that hit the
  error get them with their next `nodeindex:rebuild`.

## [3.0.1] - 2026-10-06

### Fixed

- `Search.query(node)` only searches below the given node. The path filter left the
  node itself out, so the query covered everything below its parent: a press list
  queried for its overview page also listed the articles of sibling pages, and a
  site search queried for the site node returned the other sites in the content
  repository. No reindex needed; only the query changed.

## [3.0.0] - 2026-09-29

### Changed

- **BREAKING: `settings.filterableAttributes` and `settings.sortableAttributes` are
  maps.** Write `__isAsset: true` instead of `- __isAsset`. Flow merges YAML lists by
  position, so packages extending the list overwrote each other's entries depending
  on load order; on one installation that dropped `__identifier` and every search
  using it returned nothing. A list entry now throws when the index settings are
  applied.
- Node documents carry `__identifier` (the node aggregate id), the field asset
  documents already used. Queries can deduplicate nodes and assets on one field.
  Run `flow nodeindex:createindex` and a node reindex after upgrading.

### Fixed

- Reindexing a node removes all of its indexed dimension variants. The lookup
  searched by `__identifier`, which node documents did not have, and a search returns
  at most 20 hits; the variants are now deleted by filter.
- The rebuild keeps documents listed in `indexing.preserveOnRebuild` (e.g. asset
  documents), never swaps a partial `--limit` build live, and refuses to index when
  no node type is a fulltext root. These fixes were on `feature/zero-downtime-reindex`
  and had not been released on the Neos 9 line.

### Added

- `search.fulltext.excludeChildren` on a node type skips its subtree when building
  the fulltext (released as the untracked tag 2.9.0).

[Unreleased]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.2.0...HEAD
[3.2.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.1.0...3.2.0
[3.1.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.3...3.1.0
[3.0.3]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.2...3.0.3
[3.0.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.1...3.0.2
[3.0.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.0...3.0.1
[3.0.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/releases/tag/3.0.0
