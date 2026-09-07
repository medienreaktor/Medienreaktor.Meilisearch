# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Everything before the unreleased section was reconstructed from the Git history after
the fact. It records what each release changed, not what its author would have written
at the time, and the wording of older entries is inferred from commit messages.

This repository also carries a Neos 9 line on the `neos9` branch. Its tags - `3.0.0`
and above - are not part of the history below.

## [Unreleased]

## [2.11.0] - 2026-09-07

### Fixed

- **Indexing no longer makes one write request per node.** Meilisearch queues exactly
  one task per write request, so writing per node made the server's task queue as long
  as the site: a 17k-node rebuild enqueued about 34,000 tasks, each carrying a single
  document. Meilisearch's memory grows with that queue, and on a 16 GB host it was
  killed by the kernel roughly once a day, taking the site down with it while the
  machine paged. `NodeIndexer` now buffers its writes and `flush()` performs them in
  batches - one deletion and one addition per batch, whatever the node count. A
  rebuild of 9,518 documents measured 15 tasks where it previously needed 19,036, and
  each batch of 1,000 documents is indexed in about the time one single-document task
  used to take.
- `flush()` used to be a no-op, so `Neos\ContentRepository\Search`'s promise that it
  "performs all changes to the index queued up" did not hold, and `nodeindex:build`
  never called it at all.
- Writes with nothing to write - an empty document list, an empty identifier list -
  are no longer sent. Each one cost a queued task and indexed nothing.

### Added

- `nodeindex:build --wait` returns only once Meilisearch reports no unfinished task
  for the index, and fails if any task the run enqueued did not succeed. Without it a
  command can finish long before the index it built is readable, which lets a
  blue/green deployment publish a half-built index. `--timeout` bounds the wait and
  defaults to 1800 seconds.
- `nodeindex:build --assume-empty-index` skips deletions, which cannot match anything
  on an index the caller has just emptied. Halves the tasks of the
  `createindex`/`flush`/`build` sequence, and is only correct for a caller that did
  empty the index first.
- `Medienreaktor.Meilisearch.indexing.batchSize`, defaulting to 1000, caps both the
  size of a write request and how many tasks an index run adds to the queue.
- `deleteByIdentifiers()` and `waitForPendingTasks()` on `IndexInterface`. The first
  clears every variant of a batch of node aggregates with one `IN` filter rather than
  one request per aggregate; the second is what `--wait` is built on. Neither raises
  the server or client floor: `IN` has been available since Meilisearch 0.29, and the
  task queries used by the wait exist throughout `meilisearch-php` `^1.2`.
- Unit tests for the write-count contract, stated as an equality between two node
  counts rather than against a fixed number, so it cannot be satisfied by tuning a
  constant. Also for batch boundaries, for the buffer's per-document coalescing, and
  for the wait loop's timeout and failed-task paths.

## [2.10.0] - 2026-08-25

### Added

- `search()` and `deleteByFilter()` are declared on `IndexInterface`. Both already
  existed on the only implementation, so anything typed against the interface was
  calling methods the contract did not promise.
- `nodeindex:build` reports progress and a node count.
- Unit test suite with PHPUnit, covering the dimensions hash contract, dimension
  fallback matching, the traversal-interface guard and the delete-by-filter request.
- Static analysis and code style with PHPStan (level 5) and PHP_CodeSniffer (PSR-12),
  exposed as `composer lint` and `composer test`.
- Continuous integration across PHP 8.0 to 8.4 and both dependency extremes, so the
  advertised floor is exercised rather than asserted.
- Dependabot for GitHub Actions and development dependencies.

### Changed

- **Requires Meilisearch server 1.2 or newer.** Stale index variants are now deleted
  by filter, which uses the `documents/delete` endpoint introduced in Meilisearch 1.2.
  Older servers accept the request against the id-list endpoint and delete nothing.
- **Requires Neos 8.3 or newer** (was 7.0 or newer). See Removed.
- `meilisearch/meilisearch-php` requires `^1.2` (was unbounded). 1.0 and 1.1 route a
  delete-by-filter to the id-list endpoint, which silently deletes nothing.
- Dependency constraints are explicit and bounded: `neos/eel` and `neos/media` are
  declared rather than relied upon transitively, `guzzlehttp/guzzle` is bounded to
  `^7.0`, `php` is declared as `^8.0`, and the `|| dev-master` alternatives are gone.
- Dimension combinations are built by Neos' `ContentDimensionCombinator` instead of a
  local cartesian product, so combinations forbidden by a site's dimension constraints
  are no longer produced.
- Dimension reasoning moved onto `DimensionsService`.
- Code style is PSR-12 throughout.

### Removed

- **Neos 7 support.** Every Neos 7 release chain is blocked by Composer's default
  security-advisory policy — `neos/media-browser` via PKSA-2dd8-45js-5fhk, and the
  remaining candidates via `enshrined/svg-sanitize` 0.16.0 and PKSA-4g5g-4rkv-myqs. The
  code still runs on Neos 7.3, but it cannot be installed without disabling advisory
  checks, so the constraint no longer claims it.

### Fixed

- Content visible through dimension fallback is indexed for every dimension it appears
  in. Previously each combination produced the same document id and `__dimensionsHash`,
  so the variants overwrote each other and a query filtered on a fallback dimension
  found nothing. (#21)
- Stale index variants are removed by filter or by their deterministic primary key
  instead of by searching for each hit's `id`. A project that restricts
  `displayedAttributes` legitimately hides `id`, which made publishing fail with
  `Undefined array key "id"`. (#21)
- Dimension fallback matching no longer assumes a dimension named `language`. A site
  with any other dimension name hit an undefined array key and then an array offset on
  null; a site with several dimensions silently matched on the first one alone; a site
  with none errored on an empty combination.
- The site node is resolved by walking the rootline rather than through FlowQuery, and
  is no longer read from a context that cannot supply one. Reading it off the indexing
  context returns null for every node and would drop `__uri` from every document.
- `NodeLinkService` no longer reads `$siteNode` before assigning it, which emitted a PHP
  warning for every indexed document, and no longer calls `getCurrentSiteNode()` on a
  plain `Context`, which does not declare it.
- Nodes are checked for the traversal interface Neos declares separately from
  `NodeInterface`, instead of assuming it. Nothing but the concrete `Node` implements
  both.
- `Site::getPrimaryDomain()` and `Thumbnail::getResource()` are null-checked again. Both
  are annotated as non-nullable upstream and both return null in practice — the first
  for a site without a primary domain, the second for a thumbnail awaiting asynchronous
  generation.
- `DimensionsService::$lastTargetDimensions` admits the null its own `reset()` assigns.
- `executeRaw()` and `executeWithoutProcessing()` document the raw hit arrays they
  actually return rather than nodes.
- `ThumbnailController` no longer carries a dead comparison that disabled its 404 guard.

## [2.6.0] - 2026-07-01

### Added

- Eel helper for Meilisearch tenant tokens, with support for non-expiring tokens.
- Thumbnail controller serving search thumbnails from the stable asset identifier, so
  indexed URIs survive `media:clearthumbnails`.

### Changed

- Search thumbnail URIs are asset-based proxy URIs, generated synchronously at indexing
  time.
- PSR-12 formatting, Flow's `UriBuilder`, and a policy cleanup.
- The project-specific index name setting was removed in favour of per-consumer
  configuration.

### Fixed

- Public access is granted to the search thumbnail controller.
- A botched conflict resolution in `AssetUriHelper::build()`.
- A missing parameter and a named parameter that limited compatibility.

## [2.5.0] - 2026-05-04

### Added

- Progress bar for indexing.

## [2.4] - 2025-10-15

### Added

- `distinct` attribute on the query builder.
- Package keys for injection, so the package can be extended via AOP.

## [2.3] - 2025-09-29

### Added

- Delete-by-filter action.

## [2.2] - 2025-09-23

### Added

- Access to unprocessed results.

## [2.1] - 2025-09-22

### Added

- Custom indices with custom settings.
- Assets in search results.

## [2.0] - 2025-09-17

### Changed

- **Breaking:** `__dimensionsHash` is calculated the way Neos calculates it, fixing a
  bug in the previous MD5 handling. Existing indexes have to be rebuilt.
- Indexing works for fallback languages.

### Fixed

- Hashing in the query builder.

## [1.6] - 2025-09-08

### Added

- `neededAttributesForIndex`, to skip documents missing required attributes.
- A separate command for deleting the index.
- An option to disable fulltext search.

### Fixed

- A node is removed from the index when it is no longer visible.
- Deleting the index now deletes it, rather than removing all documents.

## [1.5] - 2025-08-11

### Added

- Indexing API option to index either all dimensions or only the node's own. The
  default is unchanged.

## [1.4] - 2025-08-07

### Fixed

- Indexing works with the shipped default `Settings.yaml`.

## [1.3] - 2025-08-06

### Changed

- Upgraded to the hybrid search handling; the older vector search documentation was
  removed.

## [1.2] - 2024-10-09

### Changed

- Links are relative when no domain is set.
- Images are indexed even without a base URI.

## [1.1] - 2024-06-18

### Removed

- Default node type definitions for the `Neos.NodeTypes` package.

### Fixed

- Missing indexing rule for `hiddenInIndex`.

## [1.0] - 2024-06-02

### Changed

- Documented Meilisearch version compatibility.

## [0.8.2] - 2024-03-25

### Added

- Optional `allowUpScaling` and `format` parameters for thumbnail generation.

## [0.8.1] - 2024-03-21

### Fixed

- Property name of the geo coordinates.

## [0.8] - 2023-09-19

### Added

- Vector search.

## [0.7] - 2023-09-06

### Added

- Geosearch.

## [0.6] - 2023-09-05

### Added

- Asset URI building at indexing time, through an Eel helper.

## [0.5.3] - 2023-09-05

### Fixed

- Site and domain were not fetched correctly from the context.

## [0.5.2] - 2023-09-05

### Added

- Sorting is chainable.

## [0.5.1] - 2023-08-29

### Fixed

- URI generation for a site without an active primary domain.

## [0.5] - 2023-08-08

### Changed

- **Possibly breaking:** indexed node properties were renamed for consistency. Tested
  against Meilisearch 1.3.

## [0.4] - 2023-07-28

### Added

- Node URI generation at indexing time, so client-side JavaScript implementations are
  possible.
- `filter` and `matchingStrategy` on the query builder.
- Highlighting and cropping settings on the query builder.
- Search snippet highlighting.

### Changed

- Settings reflect Meilisearch's own index settings more closely.

## [0.3] - 2023-07-27

### Added

- Faceting and filtering in the frontend rendering.

## [0.2] - 2023-07-26

### Added

- Frontend rendering of the search form, results and pagination.

## [0.1] - 2023-07-25

### Added

- Initial release: Meilisearch integration for Neos, with node indexing and a query
  builder.

[Unreleased]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.11.0...HEAD
[2.11.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.10.0...2.11.0
[2.10.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.6.0...2.10.0
[2.6.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.5.0...2.6.0
[2.5.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.4...2.5.0
[2.4]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.3...2.4
[2.3]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.2...2.3
[2.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.1...2.2
[2.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/2.0...2.1
[2.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.6...2.0
[1.6]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.5...1.6
[1.5]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.4...1.5
[1.4]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.3...1.4
[1.3]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.2...1.3
[1.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.1...1.2
[1.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/1.0...1.1
[1.0]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.8.2...1.0
[0.8.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.8.1...0.8.2
[0.8.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.8...0.8.1
[0.8]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.7...0.8
[0.7]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.6...0.7
[0.6]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.5.3...0.6
[0.5.3]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.5.2...0.5.3
[0.5.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.5.1...0.5.2
[0.5.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.5...0.5.1
[0.5]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.4...0.5
[0.4]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.3...0.4
[0.3]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.2...0.3
[0.2]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/compare/0.1...0.2
[0.1]: https://github.com/medienreaktor/Medienreaktor.Meilisearch/releases/tag/0.1
