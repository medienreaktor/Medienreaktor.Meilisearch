# Changelog

All notable changes to the Neos 9 line of this package are documented in this file.
The Neos 8 line lives on `main` and keeps its own changelog there; its tags run up to
2.x, so the Neos 9 line continues at 3.0.0.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

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

### Changed

- **BREAKING: `__path` and `__parentPath` mean what they meant on the Neos 8 line.**
  `__path` is the node's own aggregate-id path, `/<rootId>/<siteId>/…/<nodeId>`, and
  `__parentPath` holds the path of every ancestor, the direct parent included. Both
  were one level short: `__path` was the parent's path, so a hit's `__path` did not
  address its node and `__parentPath = "<path of X>"` missed X's direct children.
  `Search.query(node)` keeps its results. Run `nodeindex:rebuild` after upgrading;
  until then `query()` misses the direct children of the context node.
- **BREAKING: `vector()` takes a search term and options, as on the Neos 8 line.**
  `vector(searchTerm, {embedder, semanticRatio})` searches through a Meilisearch
  embedder configured in `settings.embedders`; `vector(array $vector)` with
  precomputed vectors is gone, as is Meilisearch's experimental `vector` search
  parameter it set.
- `execute()` resolves hits by node aggregate id; it looked them up as node name
  paths, which aggregate-id paths are not, and so returned nothing.
- `NodeLinkService::getNodeUri()` returns the URI as a string, as on the Neos 8 line,
  instead of a `UriInterface`; `__uri` holds the same value as before.

### Added

- `executeWithoutProcessing()` returns the raw hits without resolving nodes, and
  `executeRaw()` returns asset documents written into the same index (`__isAsset`, or
  an `__path` containing `assets`) instead of failing on their missing
  `__aggregateId`. Both as on the Neos 8 line.
- `distinct(attribute)` on the query builder, as on the Neos 8 line.
- `hybrid(searchTerm, {embedder, semanticRatio})` on the query builder combines
  keyword and vector search (Meilisearch 1.6 or newer with an embedder).
- The `IndexPath` Eel helper, in Fusion and in indexing expressions:
  `IndexPath.of(node)` is the node's `__path`, `IndexPath.parents(node)` its
  `__parentPath`. In PHP, `IndexPathService::pathOf()` and `parentPathsOf()`. Use them
  to build filters for a context node outside the query builder.
- `nodeindex:deleteindex` deletes the index itself, settings included, as on the
  Neos 8 line; `nodeindex:flush` still only removes the documents.
- `search()` and `deleteIndex()` are declared on `IndexInterface`. The query builder
  called `search()` through the interface although only the implementation had it.
- `RequestService::getSiteNode()`, `RequestService::getControllerContext()` and
  `NodeLinkService::getSiteNodeFromNode()`, as on the Neos 8 line.

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

[Unreleased]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.1.0...HEAD
[3.1.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.3...3.1.0
[3.0.3]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.2...3.0.3
[3.0.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.1...3.0.2
[3.0.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/3.0.0...3.0.1
[3.0.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/releases/tag/3.0.0
