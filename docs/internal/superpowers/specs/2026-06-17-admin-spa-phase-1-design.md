# Admin SPA — Phase 1 (editorial loop) — Design

**Goal:** Ship Lemma's first‑party editor UI — enough to **author and publish getlemma.dev on
Lemma** — covering the editorial loop (list → create → edit a schema‑driven, Markdown‑bodied
draft → preview → route → publish/schedule/rollback) for content types that **already exist**.

**Status:** Design — for review before the implementation plan.

**Acceptance test:** an editor can author `page` / `doc` / `blog_post` (and/or
`changelog_entry`) content end‑to‑end in the SPA and ship getlemma.dev from it — no API calls,
no seeds, by hand.

---

## Roadmap context (why Phase 1 stops where it does)

Lemma's admin UI is built in slices, with the author as first user and getlemma.dev as the
acceptance bar — never designed wholesale before it has carried real content.

- **Phase 1 (this spec):** editorial loop. Author/publish existing content types.
- **Phase 2:** schema / model builder — public users create content types without APIs/seeds.
- **Phase 3:** migration / import workflows — the WordPress importer as proof.
- **Public "ready" gate:** the admin covers model · author · preview · publish · schedule ·
  route/redirect · import/export, for **both** developers and editors. Phase 1 is *not* the
  shipped product — it is the dogfood slice.

## Scope

**In (the approved Phase 1 surface):**

1. **Auth shell** — login / refresh / logout, the app frame (nav, content area).
2. **Content‑type navigation** — a read‑only sidebar from `GET /v1/admin/content-types`.
3. **Draft‑inclusive admin entry list** — *requires a new backend endpoint* (see below).
4. **Create entry** — "New {type}" from the list.
5. **Schema‑driven field editor** — render the draft form from `content_types.schema`.
6. **Markdown textarea + rendered preview pane** — for `text` fields.
7. **Minimal asset upload** — upload an image and use the just‑uploaded blob (store its UUID in
   the `asset` field). **Selecting from previously‑uploaded blobs (a library picker) is *not*
   Phase 1** — the core blob routes expose no list endpoint (see Media + Backend tasks). No
   folders/transforms/cropping/library.
8. **Route/slug editor** — assign/remove the entry's slug per locale.
9. **Preview** — mint a token, open a **configured frontend preview URL** (see Preview below).
10. **Publish / unpublish / schedule**.
11. **Version history + rollback**.
12. **Redirects** — manage redirects (e.g. for renamed pages).

**Out of scope (later phases):** schema/model builder (P2), block/page builder, rich‑text /
WYSIWYG editor, multi‑locale UI (en‑only; locale stays in the data model), WordPress/MD/CSV
importers (P3), full media library (folders, transforms, usage tracking).

> **Hard boundary — the field editor *consumes* schema, never mutates it.** Phase 1 reads
> `content_types.schema` (via `GET /content-types/{slug}`) to render the form, and **must never**
> call `PATCH /content-types/{slug}/schema` or `POST /content-types/{slug}/migrations` from any
> screen — that is the Phase 2 schema builder. This boundary is **enforced by test**, not just
> stated: a screen/route test asserts no Phase 1 surface issues a schema‑mutating request.

## Architecture

Most of this is settled in [APPROACH.md](../../APPROACH.md) §"Admin Interface"; this spec
records the Phase‑1‑specific decisions.

### Stack & repo/build
- **Vue 3 + Vite + Nuxt UI** (Nuxt UI's Vue/Vite integration, *not* Nuxt the framework); **Vue
  Router** for admin navigation; **Pinia** for session + cross‑screen state.
- **Source lives in `admin/`** (SPA source, built by contributors/CI). The **release artifact
  ships only compiled `public/admin/`** — `admin/`, `node_modules/`, and the frontend
  toolchain are `export-ignore`'d so a runtime install never needs Node (APPROACH packaging
  rule). The backend serves `/admin` and `/admin/*` from the built assets.

### Runtime config (not build‑baked)
The compiled SPA must work across installs without rebuilding, so install‑specific values are
**runtime config**, not Vite `VITE_*` build vars: the backend exposes a small
`GET /admin/config.json` (or an injected `window.__LEMMA__`) with at least:
- `apiBase` — the admin API base (e.g. `/v1/admin`);
- `sitePreviewUrl` — the frontend preview URL template (see Preview);
- `defaultLocale` — `en` for Phase 1.

This keeps one compiled bundle valid for getlemma.dev and any other install.

### Auth posture (from APPROACH, restated as the contract)
- Login via the core `glueful/users` auth endpoints — registered under a literal `/auth` group
  (`vendor/glueful/users/routes/account.php`: `/auth/login`, `/auth/refresh`, `/auth/logout`,
  …) plus `/me` (users routes). **The effective paths are pinned in slice‑1, task 1** by
  reading the live route manifest / OpenAPI (`php glueful route:list`), because a global API
  prefix (`API_USE_PREFIX`/`API_PREFIX`/`API_VERSION_IN_PATH`) can shift them — note Lemma's own
  routes are hardcoded `/v1/admin` and `/v1/content`, so the admin base and the auth base may
  not share a prefix. The SPA reads them from the generated OpenAPI, not from a hardcoded guess.
- **Access token in memory only** (never `localStorage`/`sessionStorage`); short‑lived.
- **Refresh token in an `httpOnly SameSite=Strict` cookie** path‑scoped to the refresh
  endpoint. All other admin calls are pure bearer (no cookie, no CSRF surface). The API client
  handles the refresh‑on‑401 dance with rotation.

### API client — typed from OpenAPI, wrapped in domain composables
- The **generated OpenAPI contract is the source of truth** (Lemma emits it via
  `php glueful generate:openapi`). Generate TypeScript types from it (e.g. `openapi-typescript`)
  → a **thin types‑first client**. The admin surface is broad enough that a hand‑written fetch
  layer will drift from the contract.
- Wrap the raw client in **small domain composables** so screens never touch transport:
  `useAuth()`, `useContentTypes()`, `useEntries()`, `useDraft()`, `useRoutes()`, `usePublish()`,
  `useSchedules()`, `useVersions()`, `useRedirects()`, `useMedia()`, `usePreview()`. Each owns
  its endpoints + loading/error state; screens compose them.
- Regenerating types after a backend change is a build step; a contract drift surfaces as a
  TypeScript error, not a runtime 4xx.

### Schema‑driven field editor (the heart of the editor)
The editor reads the content type's `schema` (from `GET /content-types/{slug}`) and renders one
input per field by **type → component**:

| `FieldDefinition.type` | Phase‑1 component |
|---|---|
| `string` | text input |
| `text` | **Markdown textarea + live preview pane** (client‑side render, e.g. `markdown-it`) |
| `number` | number input |
| `boolean` | toggle |
| `datetime` | date‑time picker |
| `enum` | select (options from the field's `enumValues`) |
| `asset` | **media upload** (upload now → store blob UUID); no existing‑blob library in Phase 1 |
| `reference` | minimal entry picker — search entries of the referenced type via the entry‑list endpoint, store the target UUID. Depends on the entry‑list endpoint; if Phase 1 getlemma.dev types use no `reference` fields, this component can be cut |
| `json` | raw JSON textarea with parse validation (advanced/rare) |

`required` fields are marked and block save/publish per the existing validation; the draft is
saved with optimistic concurrency (`lock_version`; a `409` surfaces a "reload — someone else
edited this" message).

### Media (minimal — upload‑and‑use, no library)
The core blob routes (`framework/routes/blobs.php`) expose `POST` (upload), `GET /{uuid}`,
`GET /{uuid}/info`, `DELETE /{uuid}`, `POST /{uuid}/signed-url` — **there is no `GET` collection
route**, so "list/pick from previously‑uploaded blobs" has no backend.

Phase 1 therefore does **upload‑and‑use‑now**: the `asset` field component uploads an image to
the blob upload route, gets the **blob UUID** back, and stores it in the field. A Markdown body
can likewise reference an uploaded blob's URL inline. An **existing‑blob library picker is
deferred** — it requires a small admin **blob‑list** endpoint (see Backend tasks), which is out
of Phase 1's core. (Confirm the exact blob upload base path against the running route manifest.)

### Preview (the nuance that matters for dogfooding)
`GET /v1/preview/{token}` returns **JSON** (Lemma is headless) — opening it raw shows an editor
a JSON blob, not a page. So the Preview button:
1. mints a token via `POST /v1/admin/entries/{uuid}/preview/{locale}`, then
2. opens the **configured frontend preview URL** in a new tab —
   `sitePreviewUrl` from runtime config, with the token applied (e.g.
   `https://getlemma.dev/preview?token=…` or a template). The frontend calls the preview API
   with the token and renders. The admin never tries to render content itself (that's rendered
   delivery, a later phase).

## Backend tasks (ship with Phase 1)

Phase 1 is **not** purely frontend — it requires three backend additions (everything else binds
to existing endpoints), plus one deferred:

**1. Draft‑inclusive admin entry list** — the entries‑list screen can't exist without it.
- **`GET /v1/admin/entries?type={slug}`** — perm `lemma.entries.read`; paginated (keyset or
  page/perPage, matching the delivery list convention). Returns, per entry: `uuid`, a **display
  title**, editorial `status` (has‑draft / published / scheduled), `updated_at`, and the set of
  locales present. Optional `?q=` (display‑title filter) and `?status=` later.
- **Display title:** entries have no intrinsic title (it's a draft field). Phase 1 derives a
  label by convention — the default locale's draft `title` field if present, else the entry's
  route slug, else the short uuid. (A per‑type "title field" config can refine this in Phase 2.)
- New `EntryController::index` + `EntryRepository::listForType(...)` joining
  `entries` ⋈ `entry_drafts`/`entry_publications` for status. PostgreSQL, paged, bounded.

**2. Admin runtime config — `GET /admin/config.json`** — so the compiled SPA isn't env‑baked.
Returns at least `{ apiBase, sitePreviewUrl, defaultLocale }` (see Runtime config above). Small
controller + route; values sourced from `config('lemma.*')` / env. *(Chosen over injected‑into‑HTML
config; if the static host can't run PHP for `/admin/*`, fall back to injection — but the
endpoint is the default.)*

**3. Static admin asset serving + SPA fallback** — serve the built `public/admin/` at `/admin`
and `/admin/*`, with an **`index.html` fallback** for client‑side routes (deep links like
`/admin/entries/blog/{uuid}` must return `index.html`, not 404). A small route/handler (or the
framework's static‑mount seam, if one exists — confirm) plus the build wiring that produces
`public/admin/`.

**Deferred (not Phase 1): admin blob‑list endpoint.** The existing‑blob *library* picker needs a
`GET` blob collection (the core blob routes have none). Out of Phase 1; revisit when a media
library is scoped.

## Build order (slices within Phase 1)

Each slice is independently runnable and dogfood‑able:

0. **Backend seams:** the entry‑list endpoint, `GET /admin/config.json`, and static `/admin/*`
   serving + `index.html` fallback (Backend tasks 1–3). These unblock slice 1.
1. **Shell + nav + list (read‑only):** auth, runtime config, content‑type sidebar, entry list.
   Proves auth posture + the typed client + the `public/admin` packaging end‑to‑end.
2. **Author:** create entry, schema‑driven editor, Markdown field + preview pane, save draft.
3. **Go live:** publish / unpublish, route editor, Preview‑via‑frontend‑URL.
4. **Safety + scheduling:** version history + rollback, schedules, redirects.
5. **Media:** minimal asset **upload‑and‑use** (no library), wire the `asset` field component.

getlemma.dev can start being authored after slice 3.

## Testing

Proportionate to a frontend slice:
- **Unit/component (Vitest + Vue Test Utils):** the field‑type→component mapping (each renders +
  round‑trips its value, incl. `required`/validation surfacing), the Markdown preview, the media
  upload component, and each domain composable's request/error handling (against a mocked client).
- **Contract:** types generated from the live OpenAPI; CI fails if the SPA references a
  field/endpoint the contract doesn't have.
- **One e2e happy path (Playwright):** against a seeded backend — log in → create a `page` →
  fill fields + Markdown body → assign a slug → publish → see it in the delivery API. This is
  the getlemma.dev loop in miniature.

## Success criteria

- An editor logs in and sees content types + a draft‑inclusive entry list per type.
- They create, edit (schema‑driven fields + Markdown body), preview (via the configured site
  URL), route, and publish/schedule an entry — and roll back a version — without touching the
  API.
- getlemma.dev's `page`/`doc`/`blog_post`/`changelog_entry` content is authored and published
  from the SPA.
- The release artifact contains only compiled `public/admin/`; a runtime install needs no Node.
- The backend changes are bounded to three additions — the admin entry‑list endpoint,
  `GET /admin/config.json`, and static `/admin/*` serving + SPA fallback — with no change to the
  existing content/publishing/delivery contracts.
