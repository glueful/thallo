# Navigation / Menu Builder (`glueful/lemma-navigation`) — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** `docs/V2_DESIGN.md` §5 (sub-project 1 of the rendered-delivery sequence)

Menus as data: a small removable capability pack that stores navigation trees,
serves them headless, and exposes the `MenuReader` contract that
`lemma-render` will later consume optionally (`menu('main')` → `[]` when this
pack is absent). Independently shippable and valuable before any HTML renders.

## 1. Boundary

- Pack `glueful/lemma-navigation`, namespace `Glueful\Lemma\Navigation\`,
  capability id **`lemma.navigation`** (enabled by default; switchboard
  disable). Depends on `lemma-contracts` + framework only; no `App\*`
  (`composer boundaries`). No `enabled` config key. Standard pack invariants
  throughout (flat `migrations/`, `DEPENDENT` priority, triple-gated routes).

## 2. Contracts (two additions to `lemma-contracts`)

- **`Navigation\MenuReader`** — the seam render consumes:

  ```php
  interface MenuReader
  {
      /**
       * Resolved, published-only menu tree for a locale; null when no such menu.
       * @return list<array{label:string, url:string, entry:?string,
       *   children:list<mixed>}>|null
       */
      public function menu(string $slug, string $locale): ?array;
  }
  ```

- **`Delivery\EntryTargetResolver`** — the missing read this pack needs and
  render's `path()` helper will reuse. Status must be richer than a boolean:
  the admin editor badges *unpublished*, *deleted*, and *missing* targets
  differently, and only App-side code can tell those apart:

  ```php
  interface EntryTargetResolver
  {
      /**
       * @return array{status: 'published'|'unpublished'|'deleted'|'missing'|'routeless',
       *   path: ?string}  path is non-null iff status is 'published'
       */
      public function resolve(string $entryUuid, string $locale): array;
  }
  ```

  Semantics — `published` means **addressable**: a pinned publication for the
  locale AND a public route (path resolved via route + `PathRenderer`).
  `routeless` = a pinned publication exists but no route — the content is
  live yet cannot be linked until a route is assigned (actionable editor
  state, distinct from unpublished). `unpublished` = the entry exists (draft)
  but has no publication in that locale; `deleted` = soft-deleted entry;
  `missing` = no such entry. **`path` is null for every non-`published`
  status** — draft-only, unpublished, deleted, missing, and
  published-but-routeless entries all resolve to no URL, which is what keeps
  menus (and render's future `path(entry)` helper) from ever producing dead
  links. Core implements it (`App\Content\Delivery\EngineEntryTargetResolver`)
  — the `DraftSummaryReader` precedent. Public reads use
  `status === 'published'`; the admin tree read surfaces the full status.

## 3. Storage (pack-owned)

- **`navigation_menus`** — id, uuid(12), slug (unique, e.g. `main`), name,
  lock_version (int, default 0 — optimistic concurrency for tree writes),
  created_at, updated_at.
- **`navigation_items`** — id, uuid(12), menu_uuid(12), parent_uuid(12,
  nullable), position (int, sibling order), kind (`entry` | `url`),
  entry_uuid(12, nullable — soft reference, no cross-package FK), url(1024,
  nullable), labels (json: locale → string), created_at, updated_at; index
  (menu_uuid, parent_uuid, position).
- **Permissions seed** — `navigation.manage` ("Manage navigation menus");
  host app grants to `administrator` via dependent migration (the established
  pattern).

## 4. Resolution semantics (the product decisions)

- **Labels are a per-locale map** with default-locale fallback: one shared
  tree, localized text (`{en: "About", fr: "À propos"}`); an item with no
  label for the requested locale falls back to the default locale, then to
  any available label.
- **Non-published entry targets are OMITTED from reads** — `MenuReader` and
  the public endpoint drop the item *and its subtree* (dropped, not promoted:
  predictable shape) whenever `status !== 'published'`. `url`-kind items
  always serve. The admin read is NOT filtered: it returns the full tree with
  `target_status: published | unpublished | deleted | missing | routeless`
  and `target_url: string|null` per entry item so the editor can badge each
  case distinctly (a boolean cannot tell an editor whether to publish the
  entry, assign it a route, restore it, or remove the item). No dead links
  can ever render.
- Entry items resolve `url` at read time via `EntryTargetResolver` (slug
  changes propagate automatically; nothing stores rendered paths).

## 5. HTTP API

Public (capability-gated, **`rate_limit`** like every anonymous Lemma
surface):

| Route | Returns |
|---|---|
| `GET /v1/menus/{slug}?locale=en` | resolved published-only tree (the `MenuReader` shape) — 404 unknown menu |

Admin (capability → `auth` → `lemma_permission:navigation.manage`), under
`/v1/admin/navigation`:

| Route | |
|---|---|
| `GET /menus` | list menus (slug, name, item count) |
| `POST /menus` | create (slug, name) — DTO-validated; slug `[a-z0-9-]{1,64}`, unique → 409 |
| `GET /menus/{slug}?locale=en` | full unfiltered tree + per-item `target_status`/`target_url` **for that locale** (status is locale-sensitive: the same entry can be published in `en` and routeless in `fr`) + the menu's `lock_version` (editor payload; locale defaults to the site default) |
| `PUT /menus/{slug}` | rename |
| `DELETE /menus/{slug}` | delete menu + items |
| `PUT /menus/{slug}/items` | **replace the whole tree atomically** (single transaction), guarded by `lock_version` |

Tree replacement (not per-item CRUD) matches how a tree editor actually saves
and eliminates ordering/parenting races within one editor's save. **Between
editors, the write is optimistically locked:** the request body carries the
`lock_version` from the editor's `GET`; the transaction increments it and a
stale version is a **409** (the second editor reloads instead of silently
overwriting the first — the collections `schema_version` precedent). The DTO
validates recursively:
`kind ∈ {entry, url}`; `entry` items require a 12-char `entry_uuid` (target
checked via `EntryTargetResolver`: `missing`/`deleted` → 422; `unpublished`
and `routeless` are allowed — editors build menus while content is still in
draft or awaiting a route); `url` items require a
non-empty `url` ≤ 1024 (absolute `http(s)://` or site-relative `/...` —
`javascript:` etc. rejected); `labels` map of locale → string ≤ 200; depth cap
6; total item cap 500. Illegal payloads → 422 with dot-path field errors.

**Event:** `MenuUpdated(menuSlug)` (BaseEvent) dispatched on any menu/tree
mutation — the seam the render-cache sub-project purges on, and audit can
consume.

## 6. Admin SPA

Nav module `navigation` (requires `lemma.navigation`, label "Navigation",
`/navigation`). One page: menu list (create/rename/delete) + tree editor for
the selected menu — add entry-link (entry picker reusing the entries list
query) or URL items, edit per-locale labels (locale switcher, same pattern as
the content editor), reorder/reparent via up/down/indent/outdent buttons
(deliberately no drag-drop dependency — testable via `data-test` hooks),
unpublished items badged. Save = one `PUT .../items`. Query module
`queries/navigation.ts` follows the established authFetch conventions.

## 7. Testing

Pack-convention integration tests (`tests/Integration/Navigation/`):
capability + migration smoke; `MenuReader` semantics (locale fallback chain,
published-only filtering, subtree drop, url-kind passthrough, unknown menu →
null); `EntryTargetResolver` (published → status+path, draft-only →
unpublished/null, published-without-route → routeless/null, soft-deleted →
deleted, unknown → missing); tree replace
validation matrix (bad kind/depth/caps/URL schemes → 422, missing/deleted
entry → 422, stale `lock_version` → 409, concurrent-save round trip);
public endpoint (rate-limited, 404 unknown, omits non-published subtrees);
route gating + removability (disabled capability: routes 404, MenuReader
unbound — render's future `menu()` yields `[]`); boundary guard (no `App\*`
in pack src). SPA specs: module gating, tree editor behaviors, save payload
shape.

## 8. Out of scope

Menu-item visibility rules (auth-based), mega-menu metadata (icons, badges),
menu locations/regions beyond the theme's declared names (themes consume by
slug), drag-drop editing polish, and per-item target/rel attributes — all can
layer on the `labels`-style json columns without schema breaks.
