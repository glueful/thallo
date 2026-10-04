# A Search block, and products in search — design

**Status:** design approved section by section (2026-10-04); written spec in review · **Date:** 2026-10-04 ·
**Release:** one beta, cut when asked

## 1. Why

A storefront header wants a search icon, and no search block ships today. The block must carry no
brand: one generic Search block, whose display, placeholder and scope are settings. On a shop, a
visitor searching expects products as well as pages, so the same search has to cover what Commerce
sells without the block, or core search, learning anything about Commerce.

Approach A was chosen: **one query path, with packs contributing searchable result kinds.** Entries and
products are both contributions to the search pack's index, and the block, its suggestions and the
results page all ask one query service.

## 2. What exists today (checked in code)

- **`thallo-search`** (capability `thallo.search`) answers `GET /v1/search` over PostgreSQL full-text
  search or Meilisearch (`SearchEngineChoice`). Everything is entry-specific: `DocumentBuilder` builds
  one document per published entry and locale, id `{entryUuid}_{locale}`; `Hit` carries `entryUuid` and
  `contentTypeSlug`; `search:reindex` pages through `IndexableContentReader`.
- **Visibility** is resolved per request from the live type store (`VisibilityResolver`) and enforced
  inside the backend filter, so `total` is exact today.
- **Workspaces.** `search_documents` is registered as workspace-owned (`ThalloTenantTables`), so Postgres
  reads and writes are row-scoped. **The Meilisearch path is not:** `SearchServiceProvider` passes the
  configured `search.index`, and the client only prepends a configured prefix — one index shared by
  every workspace.
- **Meilisearch writes** (`LiveMeilisearchIndex`) discard the task results, so an accepted batch is
  treated as done.
- **Commerce** fires `StorefrontCatalogChanged` (`glueful/commerce`) after commit for every storefront-
  visible write: product create/update/status/delete, variants, prices, stock, media, categories, tags,
  attributes, add-ons. Taxonomy changes carry no product uuid. Products are not localised.
- **Capability state** lives in `thallo_system_flags`. `CapabilityStateStore::put()` writes the switch
  and advances `capability.state_version` in one transaction. Commerce's `CapabilityFlipPurge` purges
  rendered pages at boot when Commerce's *evaluated* enabled state changes, configuration included.
- **Rendered pages** are cached under `render:{theme}:{appearance}:{path}` (`RenderPageCache`) and sent
  with `Cache-Control: public, max-age=0, must-revalidate` plus an ETag.
- **Settings › General** has a "Content search" toggle whose help text tells the operator to run
  `php glueful search:reindex` after enabling — a hidden requirement this design removes.
- **The block inspector** renders schema fields by type only; no field can draw its choices from the
  server. `InvalidChoiceNotice` belongs to style validation (it needs a breakpoint and says the value
  cannot be saved).
- **Permissions:** Settings › General is guarded by `content.manage`; editing content by `content.edit`.

## 3. Decisions

### 3.1 The Search block

The search pack owns the block (`thallo-search`, `requiresCapability: 'thallo.search'`). Its markup is
visitor-independent, like the mini-cart's.

**Settings**

- **display:** `field` | `icon`.
- **placeholder:** default "Search".
- **scope:** a string, empty for all available kinds, or one kind (`entries`, `products`, …). Not a
  fixed enum: the choices come from the available contributors through the inspector's
  `options_source` (3.9).
- **live_results:** boolean, default on.

**Rendering**

- **Field mode:** a real `<form method="get" action="/search">` from the shared partial
  `search/_form.twig`: the input with an accessible name (a visually hidden label; the placeholder alone
  is not a name), hidden `scope` (empty means all) and hidden `locale` (the page's locale), so both
  survive submission without JavaScript.
- **Icon mode:** a link `<a href="/search?scope=…&locale=…">` with an accessible name ("Search"). With
  JavaScript, activating it opens a panel holding the same form, anchored under the icon and full-width
  on phones, and focus moves into the input. Without JavaScript it reaches the results page, which
  shows a form when `q` is absent (3.7).
- **Unique ids** per rendered block (`block.dom_key`) for the input, listbox and options.

**Keyboard and accessibility (WAI-ARIA combobox pattern)**

- The input is `role="combobox"` with `aria-expanded`, `aria-controls` and `aria-activedescendant`; focus
  stays in the input while the arrow keys move the active option.
- The list opens with **no option active**, so Enter submits the typed query.
- **Enter** opens the active suggestion; with none active it submits to `/search`.
- **Escape** closes the suggestion list; with the list closed, it closes the icon panel and returns focus
  to the icon.
- "See all results for '…'" is the list's last item: a keyboard-selectable action. "No results" and
  group headings are status text and labels, never selectable options.
- Tab moves on normally. Enter does nothing while an input-method composition is active
  (`isComposing`).

**Asynchronous suggestions**

- With `live_results: false`, no suggestion request is ever sent.
- Each request carries a sequence number; a response older than the latest request is dropped, and a
  response arriving after Escape dismissed the list does not reopen it.
- The active option is cleared whenever the query or the results change.
- A failed request shows "Suggestions are unavailable" — distinct from "No results" — and ordinary form
  submission keeps working. A workspace still rebuilding (3.5.6) shows "Search is being rebuilt. Please
  try again later."; `/_search/suggest` reports both states distinctly from an empty result.

**Availability and caching**

- An empty index or a query with no matches never hides the block.
- When its scope is unavailable (for example `products` with Commerce off), the public page renders
  nothing; the editor shows a selectable placeholder: "Products search isn't available: Commerce is
  off." A saved scope never silently widens to everything.
- Because the markup depends on which kinds are available, a change in availability must reach cached
  pages: turning Commerce off removes a products-scoped block from an already-cached page, and turning it
  on restores it (3.6). An empty index never triggers this.

**Assets:** `search.js` and `search.css`, fingerprinted under `/_thallo/search/`, deduplicated per page
like the shop assets.

### 3.2 The contributor contract (`Thallo\Contracts\Search`)

A `SearchSourceContributor` provides one kind of result:

- **`kind(): string`** — `[a-z][a-z0-9]{0,15}`; registering a kind twice is refused.
- **`label(): string`** — "Pages & posts", "Products".
- **`requiredCapabilities(): list<string>`** — products return `['thallo.commerce']`; `thallo.search` is
  implied. A kind is **available** only when all of them are on in the live capability state; the search
  pack computes this, never the contributor.
- **`schemaVersion(): int`** — bumping it creates rebuild demand for that kind.
- **`documents(string $sourceId): list<SearchDocument>`** — the item's current documents, one per
  locale; empty means remove.
- **`enumerate(?string $after, int $size): SearchDocumentPage`** — every publicly listed item, ordered by
  `sourceId`.
- **`visibilityFilter(SearchAudience $audience): KindFilter`** — backend-neutral: `None`, `All`, or
  `Subtypes(list<string>)`; applied inside the backend query.
- **`present(SearchAudience $audience, string $locale, list<string> $sourceIds): array<string,
  ?ResultDisplay>`** — the authority on what is shown, from current records; `null` drops the candidate.

**`SearchDocument`:** `kind`, `sourceId` (`[A-Za-z0-9]{1,64}`), `locale` (a BCP 47 tag,
`[A-Za-z0-9-]{1,12}`, or `*` for every locale), `subtype` (the content type for entries; none for
products), `href`, `title`, `body`, optional `meta` (display `image`, display `price`).

**`ResultDisplay`:** `title`, `href`, optional `image` and `price`, and `text` — the contributor-
approved current plain text, capped at 20 KB, from which snippets are made (3.7). Contributors own the
meaning and freshness of everything in it.

**The two contributors**

- **Entries** — shipped by the search pack, built from today's `DocumentBuilder` and
  `IndexableContentReader`. `visibilityFilter` returns the content types the audience may see;
  `present()` checks published state and type visibility through the delivery reader.
- **Products** — shipped by Commerce. Listed means the storefront's own rule (live, buyer-available,
  active), the one the product route uses. The body is the name, the description as text, and the
  category and tag names; `href` comes from `ShopUrlGenerator`; locale `*`. `visibilityFilter` returns
  `All`; `present()` re-checks the storefront rule and supplies the current title, image and price.

### 3.3 Identity, storage and workspace isolation

**Document identity:** `{kind}_{sourceId}_{loc}`, where `loc` is `L` + the locale (`Len-US`) or `A` for
every locale. Neither `kind` nor `sourceId` may contain `_`, so splitting on the first two underscores
is unambiguous, and every character is a letter, digit, `-` or `_` — valid as a Meilisearch primary key.
At most 95 bytes. The `locale` attribute stays `*` for querying.

**Postgres storage:** a migration widens `doc_id` to 128 and adds `kind` (16), `source_id` (64),
`subtype` (64), `meta` (JSON) and `generation` (integer). `locale` stays 12. Existing rows are rebuilt,
not patched (3.5.6).

**Workspace isolation**

- **`SearchIndexLocator::current()`** is the only resolver of "which index". It reads the workspace from
  the server's tenant context, never from the request. Every query, upsert, delete, sweep and rebuild
  goes through it.
- **Meilisearch:** one index per workspace and kind and build attempt —
  `{index}_v2_{workspace}_{kind}_g{G}` (a single-store site omits `{workspace}`). The state rows name the
  active index per kind; a query across kinds uses federated multi-search.
- **Postgres:** the existing row ownership of `search_documents`.
- **Queued jobs** carry the workspace uuid and run inside `TenantContextRunner::runAsTenant()`.

**Meilisearch version:** readiness reads the running server's version. Below 1.10 (no federated
search), the backend is unavailable with: "Meilisearch 1.10 or newer is required for search across
kinds (the server reports 1.x). Upgrade the server, or set `SEARCH_ENGINE=postgres`." No silent
fallback. The plan confirms the `meilisearch-php` client exposes federated multi-search and bumps it if
needed.

### 3.4 The shared query service and its surfaces

`SearchQueryService::search(SearchInput $input, SearchAudience $audience, int $limit, ?Cursor $cursor)`:

1. Resolve the available kinds.
2. Check the scope: all available kinds, or one available kind. An unknown or unavailable kind returns
   `ScopeUnavailable`; it never widens.
3. Ask each kind for its `visibilityFilter`, and query with
   `OR over kinds (kind = k AND subtype ∈ S)` and `locale ∈ {L, *}`, inside the workspace from 3.3.
4. Batch the candidates per kind into `present()`; drop `null`s; build the display (3.7).
5. Refill: if candidates were dropped, fetch further batches — at most 3 extra — until `limit` is
   filled.

**Normalisation — `SearchInput::from(array $query, Surface $surface)`**, used by the block, suggestions,
the results page and `/v1/search`:

- **`q`:** a string only (an array-valued input is absent); NFC-normalised when ext-intl is present;
  control characters removed; Unicode whitespace trimmed and runs collapsed; capped at 200 code points
  (`mb_substr`).
- **Scope parameter mapping:**
  - *Public surfaces* (block, `/_search/suggest`, `/search`) read **`scope`**. Absent or the empty
    string means all kinds — the form's hidden empty `scope` works. A supplied value that is malformed
    (non-string, invalid kind name) or unknown or unavailable is **unavailable**.
  - *`/v1/search`* reads **`kind`**: absent means `entries` (the existing default); `all`, `entries`,
    `products`, or another registered kind. `type` (a content type slug) implies `kind=entries`; a
    conflicting combination such as `type=post&kind=products` is 422. A malformed or unknown `kind` is
    422.
- **`locale`:** a string only, matched case-insensitively against the site's locales and returned in
  canonical form; otherwise the site default on public surfaces, and the existing 422 on `/v1/search`.
- **`cursor`:** see below.

**Pagination and totals**

- **Cursor:** opaque, HMAC-signed, binding a hash of the normalised `q`, the normalised scope or kind,
  the locale, the workspace, the audience, and the raw backend offset. It advances to just past the last
  candidate actually examined; fetched but unexamined candidates are not consumed. A cursor is a
  continuation position, not a snapshot: live edits and generation changes can reorder results between
  requests.
- **`/v1/search` with `offset`** (no cursor) keeps fixed raw windows with no refill:
  `offset=10&limit=10` examines raw candidates 10–19, rejected ones are dropped and the page may be
  short. This avoids the duplicates refill would cause; it does not prevent reordering by live changes.
  `cursor` is opt-in; `offset` with `cursor` is 422; an invalid or mismatched cursor is 400.
- **Totals:** every response carries the backend's `total` with `total_approximate: true`, whether or
  not this window dropped anything.

**Surfaces**

- **`GET /_search/suggest?q=&scope=&locale=`** — a thin adapter: public audience always (a signed-in
  session grants nothing), server-resolved workspace, `Cache-Control: no-store`, refill, a fixed small
  limit, no paging.
- **`GET /search`** — the results page (3.7).
- **`GET /v1/search`** — keeps its API-key visibility (`VisibilityResolver`), its `type` filter and its
  entries-only default, and gains `kind`, `cursor`, `next`, a `kind` field on each hit, and
  `total_approximate`.

### 3.5 The index lifecycle

All state is per workspace and kind.

#### 3.5.1 State, journal, acknowledgements, demand

- **`search_index_state`:** `generation` (active), `building_generation`, `owner`, `lease_until`,
  `cursor`, `journal_start_seq`, `satisfied_seq`, `reconciled_version`, `status` (pending, building,
  ready, out_of_date, failed), `format` (legacy | v2), counts, last success, last error, and the drainer's
  token and lease.
- **`search_index_changes`** (the journal): `(kind, source_id, seq)`.
- **`search_index_acks`:** `(entry_seq, target, task_uid, status)` — which physical index (Meilisearch)
  or generation (Postgres) received each entry.
- **`search_index_demand`:** `(kind, seq, reason, created_at)`, reasons: capability, schema version,
  taxonomy change, manual rebuild, full reconcile, new workspace.

#### 3.5.2 Live changes

- Entries keep their existing listener; Commerce listens to `StorefrontCatalogChanged` and calls
  `SearchIndex::changed(kind, sourceId)`, bound to a no-op when Search is off. A taxonomy change with no
  product uuid writes a demand row instead.
- **Appending a change locks the workspace/kind state row, allocates its seq and commits the journal
  entry while holding that lock.** Promotion takes the same lock (3.5.4).
- **The drainer** — one per workspace, holding a lease — applies entries: it reads the current targets
  (the active index, plus the claimed build index while one exists), re-reads `documents(sourceId)` at
  apply time, writes to each target, and records an acknowledgement per target once the write is
  confirmed. After each acknowledgement it checks the targets again and applies to any it missed.
- **An entry is applied when it is acknowledged for every current target.** "Applied somewhere" does not
  count.
- **Confirmation:** on Postgres a write is confirmed by its commit. On Meilisearch `LiveMeilisearchIndex`
  returns task uids, and the backend waits for `succeeded`; a failed or timed-out task leaves the entry
  unacknowledged for that target, and it is retried.
- A failed live update marks the kind `out_of_date` rather than only logging.

#### 3.5.3 Rebuild (reconcile)

1. **Claim.** A conditional UPDATE succeeds only with no owner or an expired lease, and allocates a new
   generation G from the state row's counter — unique to this ownership attempt.
2. **Build.** Record `journal_start_seq` and the highest demand seq. Enumerate from the start; upsert in
   batches stamped G — on Meilisearch into this attempt's own index `…_g{G}`. Persist `cursor` only after
   a batch is confirmed. The same owner retrying a failed batch keeps its index and cursor.
3. **Replay** every journal entry with `seq > journal_start_seq`, **plus every entry still unapplied or
   failed whatever its seq**, by re-reading `documents()` and stamping G. A failed deletion from before
   the rebuild resolves here: `documents()` returns nothing and the document is deleted.
4. **Promote** (3.5.4).
5. **Sweep** (Postgres): delete this kind's rows with `generation < G`. On Meilisearch, delete the build
   indexes for this workspace and kind that are neither active nor claimed.
6. **Satisfy:** set `satisfied_seq` to the demand seq recorded at step 2, never the current one; demand
   arriving mid-build keeps the kind pending and it runs again.

**Takeover restarts.** A replacement claims a new G and enumerates from the start into its own index; it
never resumes an abandoned index or cursor.

#### 3.5.4 Promotion

One transaction:

1. Lock the state row (the lock journal appends take).
2. Read the highest journal seq, S.
3. Require every relevant entry with seq ≤ S — `seq > journal_start_seq`, or unresolved — to have a
   successful acknowledgement for the build target.
4. If any lacks one, abort, replay the missing entries into the build target, and retry.
5. Otherwise make the build target active (conditional on the owner token and G) and clear the build
   target.

Both orders hold: an append holding the lock first is committed before S is read and is included; a
promotion holding the lock first makes the append wait, take a seq above S, and become pending for the
new active target. A drainer waiting on a task when promotion happens records an acknowledgement for the
old target only, so the entry stays pending for the new active target.

#### 3.5.5 Fencing

- **Postgres:** every write — batch, replay, sweep, live apply — runs in a transaction that locks the
  state row (`SELECT … FOR UPDATE`) and checks the writer's fence (owner token and G for builders, the
  drainer token for live applies). A stale writer rolls back.
- **Meilisearch builders** write only to their own attempt's index; promotion is a fenced database
  update. A stale builder's late write lands in an index that is not active.
- **Meilisearch live applies:** the drainer sends only while its lease (database time) has more than
  `request_timeout + margin` left; a takeover waits until `lease_until + request_timeout + margin`, then
  resolves the old drainer's recorded task uids before writing (succeeded acknowledges; failed or
  unknown stays pending). Meilisearch processes an index's tasks in enqueue order, so the old drainer's
  sends precede the new one's.
- **Acknowledgements and status writes** are conditional on the writer's token (drainer token, or owner
  token and G). A stale drainer cannot acknowledge newer journal work, and a stale builder cannot
  overwrite the current owner's status.
- **Residual case:** a process paused without bound between its check and its send can land one stale
  document on Meilisearch. Display and visibility stay correct through `present()`; matching is repaired
  by the next write to that item or by the next successful full reconcile after the stale write.

#### 3.5.6 Cutover from the legacy index

- **`format=legacy`** reads the old documents: Meilisearch's existing shared index, or Postgres rows with
  `kind IS NULL`; entries only.
- **Enforced workspaces never read the shared legacy Meilisearch index**: its results cannot be
  attributed to a workspace. Until a workspace's isolated index is ready it is **rebuilding**. A
  single-store site keeps serving the legacy index until its cutover.
- Entries build first. Once confirmed `ready`, `format` flips to `v2` in one update and queries move in
  one step.
- **Postgres** legacy rows are deleted per workspace after its flip, idempotently, retried until done.
- **The legacy Meilisearch index is retired installation-wide**: deleted only when a check run as the
  system over every workspace finds none on `format=legacy`. The check runs after each cutover and on
  every reconcile, so a failed delete is retried.
- Products become searchable after the flip, once their own build finishes; until then a products scope
  is available and returns nothing.

#### 3.5.7 Rebuild demand and recovery

- **Capability changes:** `CapabilityStateStore::put()` also writes `capability.{id}.changed_at` = the new
  version in the same transaction. Demand exists for an available kind when the highest `changed_at`
  across Search and its required capabilities exceeds its `reconciled_version`. An off/on cycle with no
  Search boot in between still advances `changed_at`.
- **Other demand:** a workspace with no state row; a changed `schemaVersion`; rows in
  `search_index_demand`.
- **Manual Rebuild** writes its demand row in the request's own transaction; the wake-up job is
  dispatched **after commit**. A rolled-back request dispatches nothing. If queueing fails the demand
  stays recorded and the response says so truthfully ("Rebuild requested; it will start when background
  processing runs").
- **Recovery:** the queued job is only a wake-up. The scheduled `search:reconcile` and a boot recovery
  (over every workspace, also comparing evaluated availability against a marker for config-only flips)
  pick up any outstanding demand.
- **Periodic full reconcile:** `search:reconcile --full`, scheduled daily by default (configurable),
  rebuilds every available kind in every workspace, even ones reporting `ready`. This is what catches a
  catalog change lost to a crash between commit and its after-commit listener.
- **Consistency statement (docs):** matching is eventually consistent — a stale document is repaired by
  the next write to that item or by the next successful full reconcile after the stale write. A failed
  reconcile leaves the kind `out_of_date` in the panel. Visibility and display are never stale.

**Availability depends on capabilities alone, never on the index's state.** A kind still building is
searched with whatever is indexed.

### 3.6 Page-cache invalidation on availability changes (core)

- **Availability fingerprint:** core computes a hash of the evaluated enabled state of every registered
  capability, after configuration and provider resolution, from the snapshot the request renders with.
  This covers configuration-only deployments and provider changes with no switch written.
- **Origin cache key:** `render:{theme}:{appearance}:{availability}:{path}`. An old render finishing after
  a change writes under its old key, which no current request reads — immediate protection at the origin.
- **Purges:** on a fingerprint change, core purges `thallo:render:page` and the edge. A **second edge
  purge** is a retry, stored as a durable obligation (a row with a due time) so a restart cannot lose it;
  the scheduled tick or a boot completes it, and it is cleared only after it succeeds. The last-purged
  marker advances only after the required purges succeed.
- **The edge guarantee** comes from the cache policy rendered pages already send:
  `public, max-age=0, must-revalidate` with an ETag. A compliant shared cache revalidates every request
  with the origin, whose ETag comes from the availability-keyed entry, so an obsolete response reaching
  the edge late is replaced at its next revalidation. A CDN configured to override origin headers with
  its own edge TTL falls outside this; there, removal depends on the purges and a finite TTL, and the
  docs state it as a deployment condition. During the window before revalidation or purge, an edge that
  ignores the policy may serve the old page.
- This runs whether Search is on or not, so Search itself becoming unavailable purges too.
- **Commerce's `CapabilityFlipPurge`** is removed only in the change that adds a parity test: flipping
  Commerce in configuration alone, with no switch write, purges, and pages render without the shop.

### 3.7 The `/search` results page

**Route:** `thallo-search` registers `GET /search`, reserved as an exact path even when Search is off
(like Commerce's `/cart`); off returns the themed 404. It renders `search/results.twig`, extending the
theme's `layout.twig`; themes can override it; the default passes the template lint gate. The form is
`search/_form.twig`, shared with the block; with JavaScript the page's input is the same combobox.

**Query:** public audience, server workspace, `SearchQueryService` with refill and cursor; 10 per page
(`search.page_size`). An invalid or mismatched cursor renders page one of the current query.

**States**

| State | HTTP | Shows |
|---|---|---|
| No `q` | 200 | "Search" heading and the form, scope and locale kept; no query. |
| Results | 200 | "Results for "q"", "About N matches" (only when this window shows at least one result), the list, "More results" when a `next` cursor exists. |
| Batch empty, backend not exhausted | 200 | "No available results in this batch", "More results". |
| No matches (backend exhausted) | 200 | "No results for "q"", the form holding q; a narrowed scope adds an explicit "Search everything" link. |
| Scope unavailable | 200 | "Products search isn't available right now", the form, and a "Search everything instead" link. |
| Rebuilding | 503 + `Retry-After` | "Search is being rebuilt. Please try again later." The form stays. |
| Backend failure / Meilisearch < 1.10 | 503 + `Retry-After` | "Search is temporarily unavailable." The form stays. |
| Rate limited | 429 + `Retry-After` | The page with "Too many searches". |

**Each result:** title linking to `href`, image and price — all from `present()`; a kind label
("Product", "Page") when the scope is all; and a snippet.

**Snippets** come from `ResultDisplay::text`, never from the index. Highlight spans are found in the plain
text by best-effort literal matching (case-insensitive, Unicode-aware, whole words and prefixes); each
segment is then escaped and trusted `<mark>` tags are inserted between segments. No match gives the
opening excerpt without highlight.

**Headers and markup:** `Cache-Control: no-store`; the page cache never stores `/search`;
`<meta name="robots" content="noindex">` on every variant; `<title>` "Search results for "q" — {site}" or
"Search — {site}", q escaped; the results list is a labelled region of `<article>`s with heading links.

### 3.8 The admin Search panel (Settings › Search)

Visible when Search is on.

- **Engine:** Postgres, or Meilisearch with the server version and health; a failed readiness check shows
  its message verbatim.
- **Per kind, for this workspace:** label, status, document count, progress while building, last
  success, last error (sanitised: no credentials or hosts), pending demand. Unavailable kinds show why
  ("Requires Commerce") and have no Rebuild.
- **Cutover:** a notice while the workspace is on the legacy format.
- **Rebuild** per kind and **Rebuild all**: demand committed, wake-up dispatched after commit (3.5.7),
  202. Repeated presses add demand, never a second builder.
- **When automatic processing cannot run** — demand pending past a threshold with no build started, or
  the wake-up failed to queue — the panel says so: "Background processing hasn't picked this up. Make sure
  the queue worker and scheduler are running, or run `php glueful search:reconcile`."
- **Updates:** poll every 5 s while something is pending or building; every 60 s while idle; refresh on
  window focus. An open panel therefore learns of a later failure.
- **Settings › General** keeps the toggle with new help text: "Indexing runs automatically; progress and
  problems appear in Settings › Search."
- **Extensions › Capabilities:** the Search card shows a status pill (Ready, Rebuilding, Needs attention)
  linking to the panel.
- **Permissions:** viewing the panel and Rebuild require `content.manage`, as Settings › General does.
- **CLI:** `search:status` shows the same table; `--all` covers every workspace and the installation-wide
  legacy-index state.

### 3.9 Dynamic field options (`options_source`)

- A string field in a block schema may declare `options_source` (for example `'thallo-search.scopes'`).
- The inspector loads its choices from `GET /admin/field-options/{source}`. Packs register sources
  through a `FieldOptionSource` contract in `thallo-contracts`.
- **Access is separate from Search administration.** The endpoint resolves only registered source ids
  (unknown is 404), runs in the server's workspace context, and enforces the source's own access policy.
  The search scopes source requires `content.edit`, so an author who can edit a page gets the picker
  without `content.manage`.
- **The search source** returns "All results" (the empty value) plus each registered kind, each marked
  available or "Requires Commerce".
- **A dedicated unavailable-choice state** (not `InvalidChoiceNotice`, whose style-validation semantics
  don't apply; its visual elements may be reused) keeps the stored value and lets it be saved. It
  distinguishes:
  - a **disabled** contributor — "Products (requires Commerce)";
  - a **removed** contributor — "'reviews' is no longer provided by any installed feature";
  - **loading or a failed load** — "Couldn't load the choices"; the stored value is shown as-is.
- Loading or failure never replaces the value with "All results".
- This change adds only the search source; the mechanism is generic.

## 4. Testing

**Block**

- Schema; both display modes; hidden `scope` and `locale`; accessible names; unique ids per block.
- Unavailable scope: nothing on the public page, the placeholder in the editor.
- Warmed page cache: Commerce off removes a products-scoped block from a cached page; on restores it; an
  empty index hides nothing.
- e2e: arrows and Enter; Enter with no active option submits; Escape closing the list, then the panel,
  returning focus; "See all results" selectable; "No results" not selectable; no Enter during
  composition; Tab unaffected; out-of-order responses dropped; dismissal while loading not reopened;
  `live_results: false` sends nothing; a failed request shown apart from no results; no-JS form and link.

**Contract and identity:** duplicate kinds refused; ids unambiguous and Meilisearch-valid, including
locale `*`.

**Isolation:** the same `sourceId` in two workspaces on both engines — each sees only its own; rebuilding
or sweeping A leaves B unchanged.

**Lifecycle**

- Update, deletion and insertion during a rebuild.
- Overlapping rebuild requests coalesce.
- A killed rebuild: the replacement claims a new G and restarts.
- Paused builder: A pauses before a write, B takes over, builds and promotes; A and B have different
  index names; A's resumed write lands only in `g{G_A}`; B's promoted index is unchanged; newer content
  survives — both engines.
- A failed deletion from before a rebuild is replayed and resolved.
- A failed live update outlives an older rebuild; status stays `out_of_date`.
- Meilisearch asynchronous batch failure blocks the sweep and leaves the rebuild failed.
- Promotion: the exact sequence "replay done → live update acknowledged on the old target → promote"
  is refused until replayed; a drainer waiting on a task during promotion leaves the entry pending for
  the new target; a build starting between a drainer's target read and its acknowledgement.
- Journal append versus promotion in both lock orders, forced with a barrier, both engines.
- Stale drainer acknowledgement and status writes refused; Meilisearch takeover resolves outstanding
  tasks; a paused live writer on Postgres is rejected; a late stale live write on Meilisearch displays
  current data and its matching is restored by the next reconcile.
- Demand: an off/on cycle with no Search boot; queue failure leaves demand pending; a new workspace;
  taxonomy and manual demand; completion acknowledges only the demand captured at start.
- Cutover: every published entry appears exactly once after an upgrade on both engines; entries keep
  appearing through an interrupted upgrade on a single-store site; two workspaces where A finishes and B
  is interrupted — the legacy index survives, B shows rebuilding and never legacy results, A's Postgres
  legacy rows are gone and B's intact; after B finishes the legacy index is deleted.
- Meilisearch server older than 1.10 gives the readiness failure.

**Visibility and display**

- Commerce off excludes products without deleting them; Commerce back on reconciles edits and deletions
  made while off.
- A withdrawn product whose index update failed never shows.
- Text removed from a still-published entry, with indexing failed, appears nowhere in the response or
  the page.
- An unavailable scope never widens; a signed-in visitor's suggestions and results are public only.

**Query surfaces**

- Normalisation on every surface: array-valued `q`, `scope`, `kind`, `locale`, `cursor`; a multibyte query
  at and over the limit; the hidden empty `scope`; none gives a 5xx.
- `/v1/search`: entries-only default; `kind=all` and `kind=products`; `type` with a conflicting kind is
  422; fixed offset windows return no refill duplicates; `offset` with `cursor` is 422; a bad cursor is
  400; `total_approximate` always present.
- Cursors bound to every input; a tampered cursor rejected by the API and recovered by the page.
- A run of withdrawn candidates longer than the refill limit followed by a valid result: the batch
  message and "More results" on page one, the result reachable.
- Snippet highlighting with `&`, `<`, quotes and multibyte text.
- Results page: every state's status, headers and copy; scope and locale surviving every link and form;
  `noindex`; `no-store`; the page cache never storing `/search`; `Retry-After` on 503 and 429; the lint
  gate.

**Core invalidation:** a config-only Commerce flip purges and pages render without the shop; an old
render finishing after invalidation is not served; a failed edge purge leaves the marker unadvanced and
retries; the delayed purge survives a restart; turning Search off purges.

**Admin**

- Panel: per-kind table, Rebuild returning 202 with demand committed before dispatch, a rolled-back
  request dispatching nothing, queue failure reported truthfully, the "background processing" notice,
  idle polling and refresh on focus.
- Options: an author with `content.edit` but not `content.manage` loads the scope options and gets 403 on
  Rebuild; an unknown source is 404; the unavailable-choice state for disabled, removed and failed
  loading keeps and saves the stored value.

**CI:** new integration test files join an `INTEGRATION_SHARD_*` list; shards stay under about five
minutes locally; `composer test:distribution` runs because capability-adjacent defaults change.

## 5. Docs and changelog

- **`docs/reference/04-block-library.md`:** the Search block.
- **A search guide** (`docs/guides/`): turning Search on; the panel and Rebuild; the reconcile schedule
  and the consistency statement (3.5.7); Meilisearch 1.10+ and its per-workspace, per-kind indexes; the
  CDN deployment condition (3.6).
- **Pack-author reference:** `SearchSourceContributor`, `present()`, identity rules, `FieldOptionSource`.
- **`/v1/search`:** `kind`, `cursor`, `next`, the 422 and 400 cases, `total_approximate`. OpenAPI
  regenerated with `CACHE_DRIVER=array`, changed operations spliced by hand.
- **Commerce guide:** products appear in search when both features are on.
- **Changelog:** each commit's bullets under `[Unreleased]`.

## 6. Out of scope

- Builder-editable layouts for the results page (a theme template override covers v1).
- Facets, filters and sorting on the results page.
- Other contributors (reviews, collections); the contract allows them later.
- Other `options_source` users (for example `category_slug`).

## 7. Open questions for the plan

- The order of work across packs (contracts, core, search, commerce, admin) so each commit is green.
- Whether the drainer runs on the queue or inline after commit with the queue as fallback, given which
  Thallo deployments run a worker.
- The exact shard placement of the new integration tests.
