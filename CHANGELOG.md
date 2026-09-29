# Changelog

All notable changes to the Neos 9 line of this package are documented in this file.
The Neos 8 line lives on `main` and keeps its own changelog there; its tags run up to
2.x, so the Neos 9 line continues at 3.0.0.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
