# Changelog

All notable changes to this Glueful API application will be documented in this file.

This project is generated from `glueful/api-skeleton`. Start recording application-specific changes here after scaffolding.

## [Unreleased]

### Added
- **Rich HTML sanitization**: `RichHtmlSanitizer` contract + TipTap-scoped
  allowlist implementation over symfony/html-sanitizer (additive-only config,
  explicit 1MB input limit, task-list `data-*` preserved, checkbox inputs
  stripped, protocol-relative hrefs dropped by a custom attribute sanitizer).
  Enforced at SAVE in `FieldValidator` for `format: rich` fields — including
  rich fields inside blocks via the existing recursion — and at RENDER via the
  new `safe_html` Twig filter (fail-closed: unbound or throwing sanitizer
  escapes instead). `safe_html` joined the DB-template sandbox allowlist
  (CACHE_VERSION → 3). Unblocks the `rich_text` starter block.
- **Page/block builder**: a `blocks` content field type — ordered `{id, type, data}`
  lists inside entry JSON, so versions/publish/localization/delivery work unchanged —
  backed by a global admin-defined block-type registry (`lemma_block_types`:
  slug-immutable, deactivate-over-delete, free-form `category` grouping the block
  picker — presentation-only, nothing branches on it — and schemas reusing the
  content field vocabulary minus nesting/localization/filterable). Per-block validation with
  dot-path errors and publish-time dangling-reference checks; `block_types`
  field allowlists are picker-only by design. Structured block-list editor in the
  entry editor (picker, reorder, duplicate, collapse; nested fields reuse the
  existing widgets) + a Block Types settings screen. Rendering via a new `blocks()`
  Twig function through `blocks/{type}.twig` (theme or DB-edited templates), added
  to the DB-template sandbox allowlist with a policy cache-version bump. References
  inside blocks stay raw uuids (no auto-expansion in v1).
  Follow-up (same day): **container blocks** — block schemas may nest `blocks`
  fields (sections, columns) up to a centralized depth of 3 (`BlockDepth::MAX`,
  mirrored and test-asserted in the render pack and SPA); depth-aware validation
  via an explicit internal depth parameter; recursive block editor with a
  max-depth notice and a cycle-free async registry entry; render-scoped depth
  counter in the reset family. No sandbox-policy change.
  Follow-up (same day): **starter block library** — `lemma:blocks:seed` (idempotent,
  opt-in, never overwrites) seeds 10 starter types with default-theme templates and
  style-convention modifier classes (styling ships standalone as `blocks.css` so
  custom themes adopt it by copying one file); new `media(uuid)` helper (MediaUrlResolver
  contract — public + anonymously-retrievable blobs only, full blob-route-stack
  parity) and `safe_url` filter (scheme-allowlisted hrefs); both joined the
  DB-template sandbox allowlists (CACHE_VERSION → 4).
  Follow-up: **block reference auto-expansion** — references inside block data now
  expand in place (same batch loading, depth-2 reference-hop budget, and scope gates
  as top-level references; block structure never consumes expansion depth; asset
  fields never expand). Expansion targets now feed cache correctness everywhere:
  `Cache-Tag` carries `lemma:entry:{target}` for every expanded target (delivery API
  and rendered pages purge when an embedded target republishes) and the delivery
  ETag folds in sorted target `entry:version` identities (no more false 304 after a
  target republish). Unresolved targets contribute neither (surrogate-header
  privacy). Also fixes the dormant top-level bug where `asset` fields were passed to
  entry expansion (splicing them to null): asset values now always pass through raw.
  Follow-up: **block-schema migrations + hard-delete** — block-type schema edits
  are now additive-only; renames/deletes are declared migrations (rename/delete
  ops, one active per type) with an eager queued backfill that rewrites every
  current draft and republishes every pinned publication (non-deleted entries,
  archived included, nested to the depth cap). While a migration is active
  (running OR failed), saving/publishing entries containing that block type 409s
  — closing the unstamped-instance data-loss window. Version rollback projects
  block data once through the completed-migration timestamp suffix (microsecond
  precision) and materializes a new version when anything changed (the rollback
  response now reports the ACTUALLY pinned version); restoring a version that
  references a hard-deleted block type is blocked with a clear error. New usage
  endpoint (current drafts + publications, archived included, nested; historical
  versions excluded; picker allowlists reported, not gating) and zero-usage-gated
  hard delete (server-side re-scan, no force flag). `entry_versions.created_at`
  now persists microseconds. CLI: `lemma:blocks:migration:backfill` re-drives a
  failed backfill.
  Follow-up: **Notion-like block editor UX** — inline insert dividers with a
  searchable block picker, `/` quick-insert, drag handles with cross-container
  drag (subtree-aware depth guard: a drop that would exceed the nesting cap is
  rejected in place, never a post-hoc validation error), keyboard movement
  (⌘/Alt+↑↓ move, ⌘D duplicate, Enter expand, Delete confirm), an outline rail,
  deep-copy duplicate (fixes nested-list aliasing), and the prose seam: block
  types shaped as a single rich-text field render chromeless as flowing prose,
  an empty tail offers "Type here…", and `/` inside prose can insert a widget
  block mid-text by splitting the prose block (original id kept for the before
  half; one structured-tree operation). TipTap/UEditor remains bounded to text
  editing — the Vue block tree stays canonical. SPA-only; stored model,
  validation, and render contracts unchanged.
  Follow-up: **visual canvas (v1)** — a full-screen Design view per entry:
  theme-rendered preview iframe (real Twig through the preview session; every
  preview render now annotates blocks() output with layout-inert
  `.lemma-preview-block` wrappers and injects a token-free, nonce-correlated
  postMessage bridge), click-a-rendered-block-to-edit selection into a
  full-form inspector (the same FieldEditor/BlocksField the editor uses),
  entry-wide outline rail, responsive viewport presets, and an explicit
  Save & refresh loop (saveDraft + re-minted preview per apply; 409 handling
  mirrors the form editor). Select-only stage in v1 — structure edits stay in
  the inspector's Notion UX; no HTML/CSS editing surface. New stored-contract
  invariant: block ids are unique across the whole entry (validated).
- Canvas stage toolbar (v2): selecting a block in the Design view's stage now
  shows an in-preview toolbar — move up/down, duplicate, delete (confirmed in
  the admin), and add-after (per-list block picker). Structural edits route
  through the inspector's block tree and mirror optimistically in the stage
  until the next Save & refresh; save failures reload the stage to the
  last-applied render. The inspector's insert menus (and the prose `/` menu)
  now respect a nested container region's own `block_types` allowlist.
- Ephemeral preview render (loop C): the Design view's primary action is now
  Apply — the working block tree is validated with the exact draft-save guard
  set (block-migration gate included) and stashed in cache, and the stage
  reloads its same preview URL to render unsaved work instantly. Save draft
  persists as before and clears the stash; version-pinned preview tokens can
  neither write nor read a working copy (409 `PREVIEW_VERSION_PINNED`).
- Fixed: the render page cache built per-path keys containing raw `/`, which
  the framework's Redis cache driver rejects — every live render 500'd on
  Redis. Keys now rawurlencode the path segment.
- Edit-in-place text (canvas v3): double-click a prose block in the Design
  view's stage to type directly into the rendered page — bare contenteditable
  with native shortcuts, debounced back into the block tree, server-touched
  only at Apply/Save (existing sanitizer chain). Renderer marks prose
  rich-field output via a new soft-bound `BlockEditableFieldResolver`
  contract; non-prose blocks are never marked.
- Editable string fields (canvas v4): themes opt plain string/text fields
  into edit-in-place with `{{ data.heading|editable_text('heading') }}` —
  single-line strings commit on Enter, multiline text keeps newlines, and
  the admin's schema-derived kind matrix is the grant/patch authority. The
  starter theme adopts it across hero/section/quote/image/cta (never
  unwrapping conditional emissions; attribute values stay unfiltered).
- Auto-apply (canvas v5): the Design view re-applies the working tree
  automatically on an 800ms debounce — suppressed during in-place edit
  sessions, coalesced to one in-flight request, suspended (with one banner)
  on failure until a manual Apply succeeds, and toggleable per browser. The
  stage's scroll position now survives every reload, including manual
  Apply's.
- Free drag (canvas v6): drag the stage toolbar's grip to reorder a block
  within its list, sortable-style — the page reorders live under the
  pointer, one move lands in the block tree on drop (validated same-list by
  the inspector's ops), Escape cancels, and a rejected drop snaps the stage
  back to truth. Cross-container moves stay in the inspector.
- Stage keyboard shortcuts (canvas v7): with a block selected in the stage,
  Alt/Option+Arrows move it, Backspace/Delete opens the delete confirm,
  Cmd/Ctrl+D duplicates, Enter enters in-place editing (only when the block
  OWNS exactly one editable region — keyboard Enter stays equivalent to the
  wrapper double-click fallback, and both now share one owned-region rule,
  fixing the pointer fallback adopting a container's CHILD region), and
  Escape deselects (new `block-deselect` notification keeps outline/inspector
  selection honest). Guarded against edit sessions, drags, the bridge
  toolbar, and theme form controls.
- In-stage formatting bubble (canvas v8): rich edit sessions show a
  selection-following formatting bubble (TipTap-style, positioned off the
  selection rect) — bold, italic, underline, strikethrough, link, unlink —
  applied in place and normalized into the sanitizer's allowlist shape
  (`b/i/strike` → `strong/em/s`, styled spans unwrapped) both after each
  action and at commit. The
  commit-time pass also fixes a latent v3 bug: native Cmd+B output
  (`<b>`) was dropped WITH its text by the save/render sanitizer, so
  bolded text vanished at the next apply. Links are added through an
  inline input panel inside the bubble (no browser prompt): the edit
  session survives focus moving into the panel, the text selection is
  saved and restored around the command, URLs validate against the
  safe_url posture before execCommand runs, and invalid input keeps the
  panel open marked invalid.
  The bubble cancels pointerdown/mousedown (the edit session never blurs)
  and every action schedules the debounced commit explicitly.
  The bridge's CSP pin is reworded: appearance stays in preview.css and no
  style attributes are ever emitted, but bridge-owned UI may be positioned
  via CSSOM transform (which strict style-src does not restrict).
- Session-wide working-copy overlay: the canvas's applied-but-unsaved
  working copy now wins over the draft EVERYWHERE the preview session
  overlays the draft — canonical URLs and (new) the homepage, which
  previously never overlaid even the draft. One resolver-side overlay
  helper keyed off the verified read's own entry+locale; version-pinned
  sessions keep ignoring working copies; listings/archives/terms keep
  their published-only posture; session renders keep bypassing the page
  cache (regression-asserted with a cache sentinel). `PublicRouteResolver::
  resolveEntry` gains an optional trailing `?PreviewSession` parameter
  (additive).
- Canvas polish batch (v9): bubble buttons show active-state (via
  `queryCommandState`, treated as untrusted — missing/throwing = inactive;
  link state via region-contained-`<a>`; classes cleared whenever the
  bubble hides so stale marks can never flash); drags get a
  cursor-following ghost (stripped compact clone, built on first move,
  torn down on drop/cancel/Escape) and edge auto-scroll (48px zones, one
  interval, direction follows the zone); the outline answers the same
  keyboard scheme as the stage (Alt+Arrows move, Backspace/Delete opens
  the centered confirm, Cmd/Ctrl+D duplicates, Escape deselects both
  parent state and the stage ring) through the page's existing intent
  handlers — no new mutation paths.
- **DB-edited templates**: theme templates editable from the admin (new Templates
  screen with CodeMirror editor, per-template version history, restore, delete-with-
  fallback). Storage is per-theme + append-only (`lemma_render_templates` /
  `_versions`); a pack-owned DB-first loader (deliberately not Twig's `ChainLoader`,
  whose persistent exists-cache breaks DB-only templates) with per-render reset and
  version+policy-keyed compile-cache keys (no compiled-cache purging). Enforcement is
  a static AST policy scan (`TemplateLinter`) at save (422 with line numbers), at
  compile, and on restore — no runtime sandbox; `raw`, macros, arrow-function filters,
  dynamic include/extends targets, and method calls are denied. Active-theme mutations
  purge the render page + error caches; themed preview sessions render that theme's
  overrides. Kill-switch: `RENDER_DB_TEMPLATES`. New permission: `templates.manage`.
- **Preview sessions (preview v2)**: `/_preview/{token}` now starts a signed-cookie
  session (Secure on HTTPS; dies with the token) — full-site navigation in preview
  chrome with an Exit link, the tokened draft overlaid at its canonical URL
  (single-draft scope: everything else stays published), listing/archive/term pages
  navigable uncached, and in-session 404s rendered fresh. New contracts:
  `PreviewSession` VO + `PreviewSessionVerifier` (one verification per request via
  `PreviewSessionMiddleware` — sessions survive `cache_enabled=false`) and
  `PreviewThemeValidator` (render-owned mint validation). Optional per-preview
  `theme` is signed into the token; themed sessions render through request-local
  Twig environments with token-scoped `/_preview-assets/{token}/…` assets.
- **Taxonomy term index pages**: `/{type}/terms/{field}` renders every term of a
  filterable reference field with counts and archive links (`terms` joins `page` as a
  reserved segment, sealed at the paged 5-segment form too; allowlist-gated; 500-term
  cap). The resolver kind is THIN — the render controller fetches via
  `FacetCountsReader` and dispatches on its now-pinned invariant (empty `cache_tags` ⇔
  gate failure → themed 404; valid-but-empty → 200); index pages carry both type tags
  so publishes purge them structurally.
- **Preview-through-theme**: `GET /_preview/{token}` renders drafts/pinned versions
  through the active Twig theme (structurally uncached dedicated route; `no-store` +
  `noindex`; fail-closed themed 404s; `preview` template flag + default-theme banner).
  `PublicRouteResolver` gained `resolvePreview()` (kind `content` + `preview: true`
  flag); the mint response gained a server-decided `theme_url` (`null` when rendered
  delivery is off) and the admin editor a "Preview in theme" action.
- **`facets()` in Twig** over the new `FacetCountsReader` contract (`{items,
  cache_tags}` — a valid empty facet still tags the page); a render-scoped tag
  collector merges facet tags into `Cache-Tag`, so facet sidebars purge event-driven.
- **OpenAPI**: the render pack's HTML routes (`GET /`, `GET /{path}`, `/_preview/…`,
  `/theme-assets/*`) are excluded from the generated spec (`Default` and
  `Theme Assets` joined the tag deny-list).
- **Rendered listing & archive pages** (V2 render follow-up): `/{type}` and
  `/{type}/{field}/{term}` (+ `/page/n`, + locale prefixes) through the render
  catch-all — `PublicRouteResolver` gained `listing`/`archive` kinds with
  LIST-shaped items carrying batch-rendered `href`s; archive membership rides the
  `published_entry_references` projection; path-based pagination with `/page/1`
  canonical 301s and `total_pages = max(1, ceil(total/per_page))`; cached pages
  carry the broad `lemma:type:{type}` tag so any publish purges them. Opt-in via
  `RENDER_LISTING_TYPES` (default off). New default-theme templates
  `listing.twig`/`archive.twig`/`_pagination.twig`; `page` is reserved as an
  archive field segment.
- **Term archives + facet counts** (the taxonomy delivery surface the references spec
  deferred): a new `published_entry_references` projection (listener-maintained on
  publish/unpublish/delete, re-driven by `lemma:resync`, schema-projected so rollback
  re-pins stay correct) is the single source of "published source references published
  term". `GET /v1/content/{type}/facets?fields=…` returns global per-term counts
  (`{uuid, slug, count}`, `count DESC, slug ASC`, limit 100/max 500);
  `GET /v1/content/{type}/archive/{field}/{term}` returns the shaped term + its members
  with the list endpoint's exact pagination modes. Target-type visibility is fail-closed
  (a non-public term type 404s — no term enumeration); term liveness is a read-time
  publication join, so unpublished terms drop out immediately. Cache purging rides the
  existing surrogate tags with zero new invalidation code. `facets` becomes a reserved
  word under `/v1/content/{type}/`.
- **Render page caching** (`glueful/lemma-render` — V2 sub-project 3): `RenderPageCache`
  middleware keyed `render:{theme}:{normalizedPath}`; only `200 text/html` content
  renders cached per path; single fixed 404/410 body per theme served via
  `RenderErrorCache` BEFORE the template renders (bogus URLs cost resolver queries
  only); ETag/304 with `Cache-Control: public, max-age=0, must-revalidate`; entry/type
  purges ride the existing surrogate-tag listener; `MenuUpdated` purges broadly;
  TTL-only fallback on non-tag cache drivers. Config: `RENDER_CACHE_ENABLED` /
  `RENDER_CACHE_TTL`.
- **`php glueful render:cache:clear`** — operator purge for the rendered-page cache
  (theme file edits are not event-visible).
- Rendered entry pages (and cached 404/410 bodies) now emit `Cache-Tag` surrogate
  headers, so CDN purging composes with rendered pages via the existing
  `PurgeCdnListener`.
- **Rendered delivery core** (`glueful/lemma-render`, new capability pack — V2
  sub-project 2): Lemma serves real HTML pages from published content through filesystem
  Twig themes. One lowest-priority catch-all feeds raw paths into the new
  `PublicRouteResolver` contract (core wraps the routing/addressability layer:
  normalization-first canonical 301s, route-template parsing, anonymous visibility,
  redirect/410 passthrough, and the read-only public delivery shape + content-type slug
  for template selection — delivery item shaping extracted into a shared
  `DeliveryItemShaper`, responses byte-identical). Pack-embedded default reference theme
  (escape-by-default; `|raw` is a theme-author opt-in) with app `themes/` override and
  per-template fallback; `menu()`/`path()`/`asset()` context functions (navigation
  optional; no dead links; path-safe assets); reserved paths return standard JSON 404s;
  homepage via `index.twig` with loud-but-not-leaky config errors; Twig compile cache
  with auto-reload. Shipped uncached-first; the render page cache followed (above).
- **Navigation / menu builder** (`glueful/lemma-navigation`, new capability pack — V2
  rendered-delivery sub-project 1): menu trees as data with per-locale label maps and
  published-only resolution. New `lemma-contracts` seams: `MenuReader` (menus for
  render/frontends; null ≡ pack absent) and `EntryTargetResolver`
  (`published|unpublished|deleted|missing|routeless` + path, where `published` means
  addressable — publication AND route — and path is null otherwise), implemented by core.
  Public `GET /v1/menus/{slug}` (rate-limited); admin CRUD + atomic whole-tree
  `PUT /menus/{slug}/items` guarded by `lock_version` (stale → 409), recursive validation
  (depth 6, 500 items, URL schemes; `missing`/`deleted` targets 422, `unpublished`/
  `routeless` allowed), locale-aware editor payload (`target_status` per `?locale=`).
  `navigation.manage` permission (granted to `administrator`); `MenuUpdated` event as the
  future render-cache purge seam. Admin SPA: Navigation page with tree editor (per-locale
  labels, entry picker with target badges, up/down/indent/outdent, 409 reload handling).
- **Approval / review workflow** (`glueful/lemma-workflow`, new capability pack): a
  single-stage editorial state machine over draft/publish — submit → in_review →
  approved / changes_requested, per entry+locale. Publishing requires an approved review
  or the new `workflow.bypass` permission (409 with `details.workflow_state` otherwise);
  bypass publishes are recorded as `published_with_bypass` in the append-only
  `workflow_transitions` history. Edits invalidate active review/approval
  (`changes_requested` survives; resubmit clears it); self-review is blocked
  (`lemma_workflow.allow_self_review` escape hatch); scheduled publishes follow the same
  gate at run time with the schedule's stored creator. Core gained one seam:
  `PublishGate`/`PublishBlocked`/`DraftSummaryReader` in `lemma-contracts`, consulted by
  `PublishService` via the `lemma.publish_gate` container tag — no gates registered means
  byte-for-byte pre-seam behaviour. New permissions `workflow.review`/`workflow.bypass`
  (granted to `administrator`); admin API under `/v1/admin/workflow` (submit / approve /
  request-changes / withdraw / state+history / review queue). Admin SPA: workflow panel in
  the entry editor + a capability-gated Review queue page.

### Changed
- **lemma-contracts (BREAKING):** `MenuUpdated` moved from
  `Glueful\Lemma\Navigation\Events\MenuUpdated` to
  `Glueful\Lemma\Contracts\Navigation\MenuUpdated` (cross-pack seams live in
  contracts; lemma-render subscribes without depending on lemma-navigation). No
  deprecated alias — subscribers must re-import the contracts FQCN (none existed
  in-repo before this change).

### Security
- Admin routes (`lemma_permission` gate) now require API-key principals to carry a key scope
  satisfying the required permission slug (wildcards via fnmatch; empty scope list = deny), on top
  of the owner's RBAC. Previously any leaked key — however narrowly scoped — inherited its owner's
  full admin rights, including schema DDL.
- Collections: an API key minted with an empty scope list no longer gets full read/write/delete on
  every scoped collection (the framework's `scopeSatisfies([]) === true` legacy semantics are
  overridden to default-deny in `CollectionAccessResolver`).
- Collections capabilities are now namespaced `collections.{name}.{op}` (was the bare
  `{name}.{op}`, as `products.write`): a collection named after another scope/permission family —
  e.g. `users` — no longer fails open to that family's unrelated `users.read` grants. **Breaking
  for pre-release keys/permissions minted with the unprefixed form** — re-mint with the
  `collections.` prefix.
- Collections public data routes are now rate-limited (reads 120/min, writes/deletes 60/min,
  bulk-create 20/min; keyed by the authenticated principal, per-IP for anonymous public reads),
  matching every other public Lemma surface.
- Access-policy replacement (`PATCH /v1/admin/collections/{name}/access`) — the mutation that can
  make a collection world-readable/writable — is now fully audited: it stamps the acting admin on
  a `collection_schema_changes` row (`update_access`, policy payload) and dispatches
  `CollectionUpdated('access_updated')`.
- Importers (from the `glueful/lemma-importers` package review): WordPress (WXR) HTML bodies are
  now sanitized with `symfony/html-sanitizer` before storage (scripts, iframes, event handlers,
  and `javascript:` URLs dropped; normal markup kept) — previously the WXR `content:encoded` HTML
  was stored verbatim and served to delivery consumers, a stored-XSS vector the pack's own
  Markdown importer already defended against.
- SEO (from the `glueful/lemma-seo` package review): the public routes (`/v1/seo/meta/...`,
  `/sitemap.xml`, `/sitemap/{n}.xml`, `/robots.txt`) are now rate-limited like every other
  anonymous Lemma surface. Sitemap page numbers are bounded by the actual page count (404 beyond
  it) — previously every distinct `{n}` minted a permanent (no-TTL) cache entry plus a deep-OFFSET
  enumeration query, an anonymous cache-fill vector.

### Fixed
- Admin SPA: capability-gated nav/panels now converge WITHOUT manual reloads when a pack is
  toggled. Enable/disable on the extension detail page polls the capabilities endpoint until
  the answer actually changes (the backend serves the pre-toggle list for a few seconds — the
  dev extension-cache TTL — so a single refetch loses the race), and the capabilities store
  re-fetches on window focus for toggles made outside the UI (CLI). Background refetches keep
  the previous set on transient failure instead of blanking the gated nav.
- Importers (from the `glueful/lemma-importers` package review): import/export batch uuids are now
  random — the deterministic `hash(adapter:sequence:offset)` uuids collided with the globally
  UNIQUE `import_export_batches.uuid` column, so the SECOND import ever run with the same adapter
  (including the core snapshot import/export in `app/Content/ImportExport/`) failed on its first
  batch. Mappings and `body_field` are now validated against the target schema (and WXR keys
  against the known set) at plan time — a typo'd field previously produced a "successful" import
  that silently dropped that data. CSV user imports now reject intra-file duplicate
  emails/usernames in dry-run (both rows, case-insensitive email — dry-run and commit report
  identically) and unknown `status` values (`active`/`inactive`); the deliberate
  email-verified-on-import behavior is now documented. The triplicated source-file/coercion
  helpers were extracted to a shared `ReadsImportSource` trait.
- SEO (from the `glueful/lemma-seo` package review): admin SEO-meta upserts are now validated
  (`SeoMetaUpsertDTO`) — non-string values, over-length fields, unknown `robots`/`twitter_card`
  values, and oversize locales are 422s instead of database-driver 500s. The upsert itself is now
  an atomic `ON CONFLICT` write (find-then-insert raced concurrent PUTs into a unique-violation
  500) with UTC timestamps. An empty-string `og_title`/`og_description` override now falls back
  like `title`/`description` instead of emitting `''`.
- Analytics (from the `glueful/lemma-analytics` package review): the admin read API now normalizes
  `from`/`to` to canonical `Y-m-d` before they reach SQL or the response echo — previously any
  PHP-parseable non-ISO date (`06/10/2025`, `next tuesday`) passed validation but was string/cast-
  compared raw against the `day` date column (wrong results on SQLite, DateStyle-dependent or a
  500 on Postgres). Removed the dead `analytics.enabled` / `ANALYTICS_ENABLED` config key (and the
  identical `SEO_ENABLED` key in `lemma-seo`) — nothing ever read them; the only gate is the
  `lemma.capabilities` switchboard, and the keys' comments falsely claimed they gated
  routes/listeners. `series?dimension=subject` without a `subject` is now a 422 instead of
  silently returning `__total__` counts mislabeled as a breakdown. Metadata `json_encode` failures
  now throw into the recorder's best-effort catch (logged) instead of inserting a raw `false`; an
  empty actor-hash key (ANALYTICS_HASH_KEY and APP_KEY both unset) now logs a boot warning that
  hashes are unsalted. README/permission label now mention the third endpoint (`breakdown`).
- Collections (pre-release hardening of `glueful/lemma-collections`, from the package review):
  every schema mutation (create, add/drop field, add/remove index, drop collection) now commits
  the definition write and its DDL in ONE transaction with an optimistic `schema_version` guard —
  a mid-operation failure can no longer leave the definition and the physical table permanently
  diverged (previously un-retryable: duplicate-column/missing-table DDL errors forever), a failed
  create no longer orphans the table (making the name uncreatable), and concurrent alters now get
  a 409 instead of silently losing one admin's field. Index ops carry their kind (`unique`/`plain`)
  fixed at plan time — dropping a unique constraint used to be impossible (and one path silently
  dropped the unique while metadata still claimed it), and `settings.index` on new fields was
  silently never materialized (inline indexes are discarded/constraint-ified by the create-table
  SQL path; all indexes are now planned as explicit alter ops). Admin truncate now requires the
  same `confirm` token as the other destructive ops, resolves the actor, dispatches a
  `CollectionTruncated` audit event, and no longer uses `CASCADE`. Validation hardening turns a
  raft of raw-driver 500s into per-field 422s: field type/duplicate-name/taken-collection-name
  checks, identifier length budgets (63-char Postgres limit incl. derived index names), enum
  `values` required, string/email/url length caps, 32-bit integer range, decimal precision fit,
  JSON fields actually validated (arrays encoded instead of stored as the literal `"Array"`),
  relation/asset lists capped at 100 and batch-verified (was one query per element), soft-deleted
  blobs rejected as asset targets, LIKE metacharacters escaped in the reference check, typo'd
  field names on index/drop ops now 404 instead of phantom-succeeding (bumping `schema_version`
  and dispatching events for no-op changes), the unique-index preflight no longer false-positives
  on NULLs, and array-valued query params / `filter[f][null]=false` truthiness no longer crash or
  invert list queries. Scoped JWT writes on the public data API are now attributed: the on-demand
  session auth memoizes the principal onto the request and `ActorResolver` reads the provider-level
  `user_data`/`user_id` attributes, so rows no longer stamp `created_by_id = NULL` for session
  users. Row create/update/delete now bracket their check-then-act pairs (relation-target
  existence, restrict-delete reference check) in a transaction with events dispatched after
  commit; malformed JSON bodies are a 400 instead of silently proceeding as `{}`; corrupt
  persisted `fields` JSON fails loudly instead of degrading to "zero fields"; `?expand` of
  multi-relations tolerates legacy non-string JSON members; session admins holding
  `collections.data.manage` can expand scoped targets in the admin data browser (previously
  403'd on targets they could already read directly); admin rows stamp
  `created_by_type='admin'` via the provider-level `is_admin` flag when roles are absent; and
  the permissions seed migration skips gracefully on hosts without an RBAC `permissions` table.
- Search (pre-release hardening of the unreleased `/v1/search` feature, from the branch review):
  Meilisearch-safe document ids (`{uuid}_{locale}` — colons are invalid Meilisearch ids, so
  nothing could ever be indexed against a real server); `entry_uuid` added to the filterable
  attributes so whole-entry purges work; the event-driven reindexer now ensures the index (with
  settings) before its first upsert, so a fresh install's first publish no longer creates a
  settings-less index that rejects every filtered search; visibility is resolved from the live
  content-type store per request instead of `public_delivery` denormalized into documents —
  flipping a type private now drops it from search immediately, and wildcard API-key scopes
  (`read:content:*`) now match types exactly as delivery does; an empty-string `title` field
  falls back to the entry label like a missing one; the search backfill orders by a total order
  (`+ locale`) so multi-locale entries can't be skipped/duplicated across pages, and memoizes
  per-type schema lookups.
- API-key requests through `optional_api_key` now set the post-auth `user` request attribute, so
  `rateLimit(..., by: 'user')` actually keys per user instead of silently degrading to per-IP
  (which made keyed clients behind one NAT share a single bucket).
- Audit log now shows the acting user's email/username (not a bare uuid) for content
  create/update/delete/publish actions. Content events dispatch after-commit, so the audit layer
  has no request to resolve a display label from; `PublishEventEmitter` now resolves the actor
  uuid → email/username (via `UserProviderInterface`) and attaches it to the event before dispatch.
- Media (asset) deletions are now audited. `MediaAdminController::destroy` soft-deletes via a raw
  `blobs` status update that bypassed `BlobRepository`'s entity events, so the deletion went
  unrecorded; it now dispatches a `MediaDeleted` audit event (category `media`) attributed to the
  acting user.

### Added

#### Content Search (`/v1/search`) — `glueful/lemma-search`
- Public, delivery-parity **content search** over published content, backed by Meilisearch via the
  `glueful/meilisearch` extension. `GET /v1/search?q=&locale=&type=&limit=&offset=` returns ranked
  hits with `<mark>`-highlighted, HTML-escaped snippets (payload under the standard `data`
  envelope).
- **Delivery-parity visibility**, enforced inside the Meilisearch filter (so `total`/pagination
  stay correct): `read:content` ⇒ all types, `read:content:{slug}` ⇒ those types, anonymous ⇒
  `public_delivery` types only. `type` omitted → inaccessible types silently excluded; `type`
  provided but inaccessible → 403; unknown `type` → 404.
- Live index maintenance through Lemma's existing `ContentReindexer` seam (identity-only,
  after-commit, wrapped so a search-backend failure logs and never breaks a publish); a whole-entry
  delete (`locale = null`) purges every locale document.
- Engine-neutral `SearchBackend` port with a single Meilisearch-confined adapter
  (`LiveMeilisearchIndex`), a `DocumentBuilder` (index string/text fields by convention, with a
  per-type `title_field`/`body_fields`/`exclude_fields`/`weights` override), and operator commands
  `search:reindex` / `search:status`.
- Fail-closed: Meilisearch missing/unhealthy ⇒ `/v1/search` returns 503. **Opt-in** capability
  (`lemma.search`) — not enabled by default (it requires external Meilisearch); enable via
  `extensions:enable lemma-search`. No migrations (Meilisearch owns storage).
- Contract additions in `glueful/lemma-contracts`: `IndexableContentReader`
  (`IndexableContent`/`IndexablePage`), `ContentTypeReader::isPublicDelivery()`, and
  `ContentReindexer::reindexEntry()` locale widened to `?string` for whole-entry deletes.

#### Data Collections (`/v1/collections`) — `glueful/lemma-collections`
- Developer-defined **data collections**: a JSON collection definition drives runtime DDL to
  materialize a real per-collection table (`collection_<hash>`), PocketBase-style — not a shared
  key/value store. Field types, validation, and filter/sort capabilities come from a shared
  `FieldTypeRegistry` (the `collections.*` type set).
- Public CRUD + query API at `/v1/collections/{name}`: list (filter/sort/offset pagination,
  field selection, one-level relation `expand`), get, create, patch, delete, and strict
  all-or-nothing bulk create — behind API-key scopes `collections.{name}.{read|write|delete}`,
  **default-deny** (no key, or a wrong/cross-collection scope → 403).
- Soft collection↔collection relations (validate-on-write target existence, bounded one-level
  expand, restrict-delete while a row is still referenced) and
  `CollectionRow{Created,Updated,Deleted}` change events.
- Auditable, recoverable schema lifecycle: every DDL op is bracketed by a
  `collection_schema_changes` row (pending → applied/failed), a unique pre-flight runs before any
  write, and destructive drops require an empty table or an explicit confirmation token.
- Removable capability (`lemma.collections`): disabling it removes the public routes but preserves
  every table; the pack depends only on the framework + `glueful/lemma-contracts`.
- **v1 limits:** `storage_mode` is `table` only; in-place field-definition changes are blocked
  (remodel via drop + add); `json`/multi-value columns are stored as `TEXT` for cross-driver
  portability; no rename/retype/bulk-patch, realtime, or row-level rules yet.

#### Delivery API (`/v1/content`)
- Public, read-only delivery of **published content only**. `DeliveryRepository` reads
  exclusively through `entry_publications ⋈ entry_versions ⋈ entries[status=active]` — there is
  no draft/status column on the read path, so drafts physically cannot leak.
- Admin deletion and routing endpoints for content types and entries: discard working drafts,
  soft-delete content types/entries, list published versions, and assign/list/remove entry routes.
- Delivery access gate with both global (`read:content`) and per-content-type
  (`read:content:{type}`) API-key scopes, plus per-type public delivery opt-in via
  `content_types.public_delivery`. Invalid supplied API keys still fail 401 and never fall
  through to public access.
- `FilterCompiler`: safe, typed, filterable-only JSONB filter predicates
  (`?filter[field][op]=value`) with always-bound values, sharing a `FieldSqlExpression` helper
  with the expression-index planner so predicates always hit their index.
- Filterable-field expression-index lifecycle: a queued `EnsureFilterIndexesJob` builds Postgres
  expression indexes (`CREATE INDEX CONCURRENTLY`) out-of-band; a registry table tracks them.
- `SortCompiler` + keyset (cursor) pagination, stable under publish churn (`v.id` tiebreaker).
  Sorting on an optional filterable field pins missing-value rows last (`NULLS LAST`, both
  directions) and the keyset predicate mirrors that, so rows missing the sorted field are never
  skipped across page boundaries. Framework offset pagination backs the `?page`/`?perPage` path.
- `ReferenceResolver`: batch-loaded, published-only resolution of entry-UUID references at read
  time (unpublished/archived targets resolve to `null`; depth-bounded).
- Field selection / `ETag` / `Cache-Tag` (`lemma:entry:{uuid}`, `lemma:type:{slug}`) /
  `Cache-Control` on delivery responses.
- SEO/routing module: route slug changes auto-capture 301 redirects, admins can manage manual
  internal/external redirects, single-entry delivery emits canonical/hreflang metadata, and moved
  paths return a headless redirect descriptor (`data.redirect`) instead of serving duplicate
  content at the old path.

#### Import/export
- `lemma.content` export adapter for `glueful/import-export`, registered through
  `import_export.exporter`. It writes deterministic NDJSON batch files containing content types,
  entries, drafts, versions, publications, routes, and reference/asset projections as
  `{kind, data}` records.
- `lemma.content` import adapter for `glueful/import-export`, registered through
  `import_export.importer`. It supports dry-run validation and commit-mode idempotent upserts of
  Lemma content NDJSON bundles by each record kind's natural key.
- Content-import adapters that create entries of a chosen content type from foreign formats, each
  driven by a field-mapping wizard on the Import/Export settings page (dry-run validates without
  writing; commit creates drafts and optionally publishes):
  - `csv.content` — one entry per CSV row, fields mapped to columns.
  - `markdown.content` — a Markdown/MDX document with front matter; the body is converted to HTML
    into a chosen field.
  - `wordpress.content` — a WordPress export (WXR); each `post`/`page` `<item>` becomes an entry,
    with scalar WXR keys (`title`, `excerpt`, `slug`, `date`, `status`, `author`) mapped to fields
    and `content:encoded` HTML routed into a chosen body field. Items with WXR status `publish` are
    published on commit. Attachments and custom post types are skipped.
- `csv.users` import adapter (and a bulk-import modal on the Users page) for creating users and
  their profiles from a CSV. Built on a reusable `AbstractCsvImporter` base that the content and
  user CSV importers share.
- Multi-valued + filterable references: `reference`/`asset` fields can be declared `multiple`
  (ordered uuid array, optional `max_items`), and `reference`/`asset` fields can be `filterable`.
  Delivery filters published entries by a reference target via JSONB array containment —
  `?filter[category][eq|in]=<uuid|slug>` — with slug→uuid resolution against the target type
  (`reference_slug_field`, default `slug`), GIN-indexed, and correct across single/multi/flipped
  fields. Admin gains builder controls and ordered multi-pickers. (Unblocks taxonomies + a future
  WordPress categories/tags importer.)

#### Localization
- i18n-backed content locale validation through `ContentLocaleService`: when `glueful/i18n` is
  installed, authoring, publishing, routing, and preview-mint locale params must be enabled i18n
  locales; without i18n, Lemma falls back to `lemma.default_locale`.
- Entry locale variant workflow endpoints: `GET /v1/admin/entries/{uuid}/locales` summarizes each
  locale's draft/publication/route state, and `POST /v1/admin/entries/{uuid}/locales/{locale}`
  creates a target-locale draft, optionally copied from a source locale draft.
- Field-level localization automation: source-locale copy now preserves non-localized/shared fields
  by key presence while leaving `localized: true` fields empty for translation.
- Per-locale RBAC support through Aegis resource-filtered grants: locale-targeted admin routes
  now authorize against `locale:<code>` while locale-agnostic routes keep the coarse `lemma`
  resource. Seeded unscoped roles remain backward compatible.
- Localization editor UX: per-locale publish/draft/scheduled status in the entry-editor locale
  switcher, locale-aware versions page, copy-into-existing-locale (overwrite), translation-coverage
  progress in the entry list, cross-locale route management, and bulk create/publish across locales.
  Disabling a language now warns when it still has published or draft content, backed by a new
  `GET /v1/admin/locales/{locale}/usage` endpoint.

#### Publishing pipeline
- A frozen PSR-14 content-event taxonomy (`entry.created/updated/published/unpublished/deleted`,
  `model.created/updated/deleted`, `asset.attached/detached`) with identity-only payloads
  (never full field content).
- Events dispatched from `db()->afterCommit(...)` — fire once, on the outermost commit only,
  never on rollback. Asset `attached`/`detached` deltas diffed on draft save.
- Listeners (registered in `LemmaServiceProvider::boot()`): `InvalidateCacheTagsListener`
  (invalidates the delivery cache tags), `DispatchWebhookListener` (core `WebhookDispatcher`,
  identity-only, gated by `pipeline.webhooks_enabled`), and capability-gated `PurgeCdnListener`
  / `ReindexSearchListener` (clean no-ops without `glueful/cdn` / a bound content reindexer).
- `lemma:resync` command: re-drives the idempotent effects (cache invalidation + search reindex;
  webhooks opt-in via `--webhooks`) for an entry, a type, or everything — published content only,
  bounded/keyset-paged.
- Scheduled publish/unpublish: `POST /v1/admin/entries/{uuid}/schedules/{locale}` creates or
  reschedules a pending publish/unpublish action, `GET /schedules` lists pending/history rows,
  `DELETE /schedules/{scheduleUuid}` cancels pending rows, and the every-minute
  `RunDueSchedulesJob` fires due rows through the normal `PublishService` path.

#### Version retention
- `lemma:versions:prune` operator command for manual, opt-in pruning of non-pinned
  `entry_versions` history, with `--dry-run`, `--keep`, and `--max-age-days` controls. Pinned
  publications are protected by a delete-time guard, and unset retention config preserves
  unlimited history.

#### Schema migrations
- Explicit destructive schema migrations for content types: `POST /v1/admin/content-types/{slug}/migrations`
  accepts tracked `rename` and `delete` field operations, records progress in
  `entry_schema_migrations`, flips the canonical schema immediately, and queues
  `lemma:schema:backfill` materialization.
- Read-time schema projection now replays pending migration operations for lagging drafts,
  versions, preview tokens, and delivery rows, so partially materialized catalogs still serve
  the current schema shape. Published backfills append and re-pin new migrated versions while
  preserving historical version rows.

#### Preview tokens
- HMAC-signed (`APP_KEY`) `PreviewToken` bound to `{entry, locale, version?}` with a minutes-scale
  TTL — signature verified constant-time before any payload is trusted; `exp` is inside the signed
  payload (no lifetime extension).
- Admin mint endpoint `POST /v1/admin/entries/{uuid}/preview/{locale}` (auth + `lemma_permission`);
  public `GET /v1/preview/{token}` — unauthenticated by design (the token is the capability),
  rate-limited by IP, fail-closed (invalid/malformed → 403, expired → 410, target gone → 404).
  Serves the entry's current draft, or a specific pinned version (bound to the token's entry+locale).
  This is the **only** door to a draft; the public delivery API can never see drafts.

### Changed
- `FieldValidator` normalizes `datetime` field values to canonical ISO-8601 UTC
  (`YYYY-MM-DDTHH:MM:SSZ`) on write, keeping stored values lexicographically comparable
  as text for the datetime expression index and filter range comparisons.
- `PublishService` now rebuilds the `entry_references` projection from the published version
  snapshot on publish/rollback, so draft edits never affect delete protection or delivery-time
  reference resolution until they are actually published.
- `EntryRepository::saveDraft` debounces `entry.updated`: successful saves that write the same
  field payload no longer emit redundant update events.
- `PublishService` now reserves the next immutable version number under a transaction-scoped
  advisory lock per entry+locale before appending the version row.
- `FieldValidator` validates `asset` fields against active core `blobs` on the configured
  `lemma.media_disk`, instead of accepting any non-empty UUID-shaped string.
- `RequireLemmaPermission` resolves the authenticated principal from the post-auth `user` request
  attribute set by `AuthMiddleware` (falling back from the optional `auth.user` enricher), so every
  `lemma_permission`-gated admin route authorizes correctly in a lean install — still fail-closed
  (no principal or missing grant → 403).
- Content types now carry an optional `cache_ttl` override, and delivery responses use it for
  `Cache-Control` max-age before falling back to `lemma.delivery.cache_ttl`.
- Single-entry delivery now uses `glueful/i18n` locale fallback chains when available: `show`
  resolves route slugs and entry UUIDs through the requested locale's fallback chain while
  preserving the actual served locale in the response payload.
- `EntryRepository::softDelete` now emits `AssetDetached` for the deleted entry's current asset
  references before emitting `EntryDeleted`, keeping asset usage webhooks consistent with draft-save
  asset deltas.
- `ReindexSearchListener` now calls Lemma's provider-neutral `ContentReindexerInterface` seam, so
  any search extension can bind the reindexer and own its own queueing/document shape without Lemma
  referencing a vendor-specific job class.
- PHPUnit now pins `DB_DRIVER=pgsql`, and the repository ships a GitHub Actions CI workflow that
  runs the Composer CI gate against Postgres.
