# Block Reference Auto-Expansion — Design

**Date:** 2026-07-03
**Status:** Approved (brainstorm 2026-07-03)
**Depends on:** page/block builder (2cd93bf), container blocks (c13fe62)

## Goal

References inside block data currently reach every consumer as raw entry uuids (the
block-builder v1 pin). This work expands them in place — same contract, batching,
depth, and scope gates as top-level reference fields — in both the delivery API and
the rendered-page path, and makes cached pages purge when an expanded target
changes. It also fixes the dormant top-level `asset` "expansion" bug.

The scope boundary agreed in brainstorm:

- **In:** block reference expansion, expansion-target cache tags (top-level AND
  block), the top-level asset bug fix, docs contract update.
- **Out:** `published_entry_references` / resync / term archives / facets (top-level
  reference fields are curation; block references are body composition — an entry
  must not join an archive because a block linked a term), block validation (already
  shipped: per-block schema validation + strict-publish dangling checks), admin
  editor UI, preview draft semantics (references resolve to PUBLISHED targets only,
  everywhere, unchanged).

## §1 Contract

One mental model: a reference field behaves identically whether it sits on the entry
or inside a block.

| Value | Result |
|---|---|
| Single `reference` uuid | expanded published item (the hydrated row `publishedByEntryUuids()` returns — identical shape to top-level expansion) or `null` when the target is unpublished, archived, or scope-gated |
| Multiple `reference` (list) | ordered list, each element resolved independently; inaccessible elements become `null` |
| Any reference at the expansion-depth cap | raw uuid(s) — matching current top-level behavior |
| `asset` field (single or multiple, any level, any depth) | **never expands** — raw blob uuid(s) always; `media(data.image)` and the image/gallery starters keep working |

Templates that only need a link write `path(data.post.uuid)` (the expanded item
carries `entry_uuid`; see §6 for the exact key surfaced — pin at implementation to
whatever top-level expansion already serves, byte-identical).

**Depth model (pinned):** expansion depth (default 2, unchanged) counts *reference
hops* only — structural block nesting is layout, not graph traversal. Walking
`section → columns → quote` consumes no depth; expanding `data.post`, then a
reference inside that target's fields, spends the budget. `BlockDepth::MAX` (3)
bounds block *structure*; expansion depth bounds the reference *graph*. Two separate
limits, never mixed.

## §2 Descent mechanics (ReferenceResolver)

`App\Content\Delivery\ReferenceResolver` gains blocks-awareness:

- Constructor gains `BlockTypeRepository` (both classes are app-side; the repo's
  `schemasBySlug()` is already memoised per instance).
- `referenceFieldNames()` keeps returning the *entry-schema* fields to expand, but
  the walk adds a parallel notion: entry-schema fields of type `blocks` are
  **descent roots** (subject to the same selector rule, §3).
- **Collection:** for a blocks value (a list of `{id, type, data}`), each item's
  `type` is looked up in `schemasBySlug()`; `reference`-type fields in that block
  schema contribute uuids from `data`; `blocks`-type fields in the block schema
  recurse structurally. Collected uuids join the SAME batch set as top-level refs —
  still one `publishedByEntryUuids()` query per expansion level.
- **Splice:** mirrors collection exactly — same walk, replacing each reference value
  via the same `splice()` semantics (scalar → row or null; list → per-element).
- **Recursion:** expanded targets' own fields already re-enter `expand()`; because
  the block registry is GLOBAL, block schemas are exact at every recursion level.
  (The existing approximation — reusing the source entry schema for a target's
  entity fields — stays as is, documented.)

**Robustness rules (delivery never explodes over data):**

- Blocks value not a list → left untouched.
- Item not an array, `type` not a string, or slug unknown to the registry → item
  left untouched, skipped silently (validation owns shape).
- `data` not an array → untouched.
- Structural walk self-caps at `BlockDepth::MAX` levels — data written around the
  API deeper than the cap is left raw (bounded work, consistent with the render
  depth guard).
- Inside block schemas only `reference` fields expand; `asset` fields are never
  collected (§5 makes top-level match).

## §3 Selector scoping (pinned)

The stable public contract is the **top-level blocks field**, not inner block paths:

- No selector / empty selector → expand refs inside ALL reference and blocks fields.
- `?fields=body` → expand refs inside `body` (including block refs at any structural
  depth within it).
- `?fields=title` → `body` is not walked at all.
- **No inner-block selector support** (`body.0.data.post` never means anything) —
  per-inner selectors would couple the public API to unstable editor structure and
  block ordering.

This is the identical rule top-level reference fields follow today
(`referenceFieldNames()` honours `$selector->requested($field->name)`).

## §4 Expansion-target cache tags (top-level AND block)

Today neither the delivery API's `Cache-Tag` (own rows + queried type) nor the
render controller's tagging (own entry + own type) carries expansion targets, so a
page embedding expanded entry B goes stale when B republishes. This work fixes that
for both reference kinds at once — the resolver/shaper/render path is already open.

- **Collector:** a small value object `App\Content\Delivery\ExpandedTargets` with
  `add(string $entryUuid, string $versionUuid)`, `entryUuids(): list<string>`
  (deduped), and `versionIdentities(): list<string>` — the SORTED, deduped-by-entry
  list of `"{entryUuid}:{versionUuid}"` strings (stable representation for ETag
  input). `ReferenceResolver::expand()` gains an optional trailing
  `?ExpandedTargets $expanded = null` parameter and records every target it
  ACTUALLY splices in, at any depth, blocks or top-level (the hydrated rows from
  `publishedByEntryUuids()` carry `version_uuid`, so both identities are on hand at
  splice time). Asset fields contribute nothing. Raw-uuid-at-depth-cap contributes
  nothing (not expanded). Unresolved targets contribute nothing (see the
  unresolved-target pin below).
- **Shaper:** `DeliveryItemShaper::shape()` and `shapePublic()` gain the same
  optional collector parameter, threaded through to `expand()`.
- **Delivery API:** controllers pass a collector into `shape()` and merge
  `lemma:entry:{target}` for each collected uuid into the response `Cache-Tag`,
  alongside the existing own-entry/own-type tags. Byte-identical tag strings to
  `InvalidateCacheTagsListener` / `PurgeCdnListener` (`'lemma:entry:' . $uuid`).
- **Render path:** `EnginePublicRouteResolver` passes a collector wherever it shapes
  content (entry, listing items, archive term) and carries the result on the
  resolver result array as `cache_tags` (a `list<string>` of full tag strings);
  `RenderController` merges them via the existing `mergeCacheTags()`. The `content`
  payload itself is never touched.
- **Purge listener:** unchanged — it already emits the right tags; pages finally
  carry them.

**ETag validators (P1 — tags alone are not enough):** the delivery ETag is computed
from only the root/list member version uuids plus the selection key
(`DeliveryEtag.php:14`, `DeliveryController.php:237`). If entry A expands target B
and B republishes, A's body changes while A's ETag stays identical — a conditional
request incorrectly gets `304 Not Modified`. Fix, in the same pass:

- show ETag input = root version uuid + the collector's sorted
  `versionIdentities()` + selection key
- list ETag input = list member version uuids + sorted `versionIdentities()` +
  selection key
- taxonomy/archive/render page caches keep purging via tags (they carry no
  validators); the DELIVERY API must fold expanded versions into its validators.
- Test: cache/show A with expanded B → republish B (A's own version unchanged) →
  A's ETag changes.

**Unresolved-target pin (P2 — intentional, documented):** only targets actually
spliced in contribute cache tags and ETag identity. An unresolved target —
unpublished, archived, or scope-gated (resolves to `null`) — contributes NOTHING:
tagging it would leak hidden/unpublished entry uuids through surrogate headers.
Consequence, accepted deliberately: if A references unpublished B and B later
publishes, A's cached `null` expansion is NOT purged by B's publish event; it
refreshes on A's own next purge or TTL expiry. Privacy of the surrogate channel
outranks eager freshness for content that was invisible when cached.

**Privacy pin (hard):** `expanded_entry_uuids` is internal metadata and must NEVER
appear in a public JSON body or template-visible context. The collector design keeps
it out of row arrays entirely — it travels only through the collector object and the
resolver-result `cache_tags` key, which the render controller consumes and does not
pass to templates. A test asserts the delivery JSON body and the rendered HTML
contain no trace.

**Fan-out bound:** tags are per distinct expanded target; expansion is capped by
depth 2 and batch-deduped, so a page gains a handful of tags, not an unbounded set.

## §5 Top-level asset bug fix

`ReferenceResolver::referenceFieldNames()` currently includes `'asset'`, but asset
values are BLOB uuids — `publishedByEntryUuids()` can never match them, so an asset
field reaching `splice()` resolves to `null`. No test covers it. Suspected live
result: delivery output nulls top-level asset fields whenever expansion runs.

- Step 1 of implementation: a failing test pinning ACTUAL current behavior
  (verify-don't-guess — if it turns out top-level asset fields survive via some path
  not read during design, the fix below still stands; the test then documents why).
- Fix: `referenceFieldNames()` stops including `'asset'`; asset uuids are never
  passed to `publishedByEntryUuids()`. The blocks walk (§2) never collects them.
- Regression pair: top-level asset field (single + multiple) passes through as raw
  blob uuid(s); asset fields inside blocks likewise.

## §6 Docs contract update

- `blocks()` docblock + lemma-render README line "Reference values inside `data` are
  raw uuids — use `path(uuid)`" becomes: reference values arrive EXPANDED (published
  item or null; raw uuid only at the expansion-depth cap); link via
  `path(data.post.uuid)`; asset values stay raw blob uuids for `media()`.
- CHANGELOG `[Unreleased]`: block reference expansion + expansion-target cache tags
  + the top-level asset fix, appended to the block-builder bullet family.
- No starter template changes (none use `reference` fields — deliberate, spec'd in
  the starter-library design).

**Implementation-time verification note:** the exact envelope keys of an expanded
item (e.g. `entry_uuid` vs `uuid`, presence of `version`) must be read off the
existing top-level expansion output and reused as-is — block expansion must be
byte-identical in shape. Consumer call sites of `shape()` beyond
`DeliveryController` / `TaxonomyController` / `shapePublic` (e.g. reader/search
paths) must be enumerated with grep before the plan freezes the signature change.

## §7 Testing

**Unit (resolver walk):**
- nested blocks (3 structural levels) collect + splice correctly
- malformed items / unknown type slugs / non-array data left untouched
- multiple reference inside a block: order preserved, per-element null
- asset fields inside blocks never collected
- structural walk caps at `BlockDepth::MAX`
- collector receives exactly the spliced targets (not depth-capped raw uuids)

**Integration (delivery):**
- block ref expands end-to-end through the delivery API (shape identical to a
  top-level expansion of the same target)
- depth cap leaves raw uuid inside a block
- scope gating: a block ref to a non-public type resolves null for anonymous callers
- `?fields=title` does not walk `body`; `?fields=body` expands block refs
- `Cache-Tag` carries `lemma:entry:{target}` for top-level AND block targets
- ETag round-trip: show A with expanded B → republish B (A's version unchanged) →
  A's ETag CHANGES (no false 304); same for a list containing A
- unresolved target (unpublished/scope-gated): no tag, no ETag identity, splices
  null — and the surrogate headers never mention its uuid
- top-level asset regression pair (§5)
- no `expanded_entry_uuids` (or collector residue) in the JSON body

**Integration (render):**
- a rendered page embedding a block ref carries the target's entry tag
- purge round-trip: republish the target → cached embedding page's tag invalidated
  (listener emits, page carries — assert the tag strings match byte-for-byte)
- rendered HTML contains no collector residue
