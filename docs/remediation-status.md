# Review Remediation

Scope: local working tree on `main`, including the unreleased Abilities API work.
No release, merge, commit, or push is part of this remediation.

## Implemented

- Nested insert/remove operations preserve `innerBlocks` and `innerContent`
  together through a dedicated `BlockTreeEditor`. Ambiguous empty containers
  and paths that exceed the output schema are rejected before saving.
- Unit tests use the actual WordPress parser/serializer. Quality, prerelease,
  and release workflows install the parser dependency before testing.
- Core collection discovery skips SVG registration; collection-specific icon
  requests skip unrelated libraries and styles.
- Bundled library listings read generated compact metadata. Runtime manifest
  filters retain the full-manifest path for compatibility.
- Query results are streamed into a bounded page cache: at most four pages of
  at most 100 rows, instead of retaining all matching icon records.
- Enabled-only searches exclude disabled variants. Abilities and Core share
  variant/legacy name-index construction and namespace-scoped lookup.
- Provider manifests are reused within a request. Stored option mutations
  invalidate derived caches; custom manifests refresh when their metadata changes.
- Load more requests use a capability- and nonce-protected grid-only AJAX
  response. Offscreen preview rendering uses progressive browser containment.
- Active and archived custom icons have independent 48-item pagination.
- Navigation cancels superseded requests where supported and rejects stale
  responses everywhere. JavaScript tests cover reversed response order and Back.

## Evidence

- Real WordPress 7.1 Abilities API proof: insert an icon between two paragraphs
  in a group, reload through a fresh WordPress process, remove it, then reload
  again. The final saved markup matched the original byte-for-byte. The sole
  temporary draft was moved to Trash; existing content/settings were unchanged.
- Source-aware independent re-review confirmed the placeholder fix. An adjacent
  path-depth issue and workflow dependency omission found by review were fixed.
- Live WordPress preview-handler probe returned page 2 of Heroicons Outline:
  72 cards, 324 total, 53,770 bytes of grid HTML, with no admin shell. An anonymous
  request was rejected with `icon_library_forbidden`. This validates the handler,
  not the browser click/render path.
- Controlled PHP catalog experiment, 20 runs per path with a cleared object
  cache and reset test harness: full-manifest summary path p50 11.421 ms / p95
  12.255 ms; compact summary path p50 0.225 ms / p95 0.401 ms. Three summaries
  were returned in both paths; actual full manifests loaded fell from three to
  zero. This isolates summary loading, not live editor or end-to-end latency.

## Remaining Work

- Review finding 8 remains open. `expected_modified_gmt` is optional and checked
  before writing; it is not atomic concurrency protection. A post-content
  version token and storage-aware conflict-safe writer need separate MySQL and
  SQLite integration proof. Do not claim concurrent AI edits are safe.
- Streaming bounds result memory, but exact totals and facets still require
  catalog scans. Persistent search indexes and cold/warm editor latency budgets
  remain future scale work, not completed by this change.
- Core metadata discovery still reads enabled manifests to determine available
  styles, although it does not open/register their SVGs.
- Browser containment reduces offscreen rendering, not accumulated DOM memory.
  Full virtualization is not implemented.
- Actual admin browser proof is pending authentication on the local site.
  API pagination and synthetic navigation race tests do not replace that proof.

## Validation Commands

```sh
composer check
node --test tests/*.test.js
composer package
```

Set `WP_CORE_DIR` for standalone clones. Run `composer catalog` after a bundled
manifest import or edit; generated metadata is included in production ZIPs.
