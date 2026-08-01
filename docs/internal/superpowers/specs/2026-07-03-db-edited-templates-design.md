# DB-Edited Templates — Design

**Date:** 2026-07-03
**Track:** V2 §6 "DB-edited templates / Twig-sandbox admin overrides" (deferred from v2 core in §3)
**Depends on:** the shipped render pack (themes, `TwigFactory`/`ThemeLocator`, `RenderPageCache`/`RenderErrorCache`, preview sessions incl. `themedEnv()`); the navigation pack's admin-pack precedent (in-pack migrations, controllers, permission seeding, purge listener).

## 0. Decisions (from brainstorm)

| Decision | Choice |
|---|---|
| Scope | Override any template the active theme resolves AND create new hierarchy templates that don't exist on disk. No full theme authoring — the filesystem theme stays the base. |
| Enforcement | **Static AST scan** at save and at compile. No runtime sandbox. |
| Versioning | Append-only history + restore. DELETE deactivates, never destroys history. |
| Workflow | Save = live, validated at save (422 with line numbers). No draft state in v1. |
| Theme scope | Per-theme: overrides keyed `(theme, path)`, only applied while that theme renders. |
| Admin UI | Full template manager (hierarchy listing, copy-from-disk starting point, history, code editor). |

## 1. Pack ownership & boundaries

Everything lives in **`packages/lemma-render`** — migrations, repository, loader, linter, admin controller, routes, purge listener — following the navigation pack's shape. No `App\` dependencies; DB access via the framework `Connection`. **No new contracts**: DB templates are a render-pack internal; core and the other packs never see them.

Admin surface is triple-gated like navigation:

1. **capability** — the admin routes file loads only when `lemma.render` is enabled;
2. **auth** — group middleware;
3. **`lemma_permission:templates.manage`** on every route (permission seeded by a pack migration).

## 2. Data model

Two tables, flat `migrations/` at the pack root (pack conventions), plus a permission seed:

- `lemma_render_templates`: `uuid`, `theme` (string), `path` (e.g. `entry/interview.twig`), `current_version_uuid` (nullable), `active` (bool, default true), `created_at`, `updated_at`. **Unique `(theme, path)`.**
- `lemma_render_template_versions`: `uuid`, `template_uuid`, `source` (text), `created_by` (bare user uuid string — no cross-package FK), `created_at`. **Append-only; version rows are immutable and never deleted in v1.**
- `003_SeedTemplatesPermission`: seeds `templates.manage` (navigation's `003_SeedNavigationPermissions` pattern).

Lifecycle pins:

- **Save** (create or update): insert a version row, upsert the template row (`current_version_uuid` → new version, `active` = true) in one transaction.
- **DELETE = deactivate, preserve history**: set `active` = false. The loader ignores inactive rows; rendering falls back to the filesystem. Version rows remain. Re-creating the same `(theme, path)` **reactivates the existing row with a new current version** — history continues on the old row. (A hard-prune command can come later if storage ever matters; not in v1.)
- **Restore**: copy an old version's source into a **new** version row and repoint `current_version_uuid` (restore is itself an append).

## 3. Loader chain & freshness

A pack-owned **`RenderTemplateLoader implements Twig\Loader\LoaderInterface`** that checks the DB first and the filesystem second:

```
RenderTemplateLoader( DatabaseTemplateLoader(theme), FilesystemLoader([app theme, pack default]) )
```

Precedence: **DB override → app theme file → pack default.** `TwigFactory` gains the optional DB loader; when present it wraps both in `RenderTemplateLoader`, otherwise today's pure filesystem behavior (byte-identical when the feature is off or no overrides exist).

**Why not `Twig\Loader\ChainLoader` (pinned):** `ChainLoader` memoizes `exists()` results in its own persistent `$hasSourceCache` with no invalidation path — once a template has returned false, it stays false for the loader's lifetime. That breaks the DB-only hierarchy case exactly (render before the override exists caches `exists('entry/interview.twig') === false`; the save can never surface in that process). The pack-owned composite keeps **no persistent exists-cache of its own**.

Mechanics (all pinned):

- **Request-scoped memo, reset by the controller.** `RenderTemplateLoader::resetForRender()` clears the DB override map (`path → current_version_uuid`, active rows only; reloaded lazily with one query) **and any exists-caching in the composite** — one method clears all loader state that could go stale. `RenderController::render()` calls it alongside `resetTags()`/`setAssetBase()` — the same render-scoped-state family. `TemplateUpdated` may additionally clear same-process state as a convenience, but it is **not** the freshness mechanism: freshness = reload-per-render + version-keyed compiled cache. (No assumption that process-local events reach every worker.)
- **Compiled-cache keys carry the version AND the policy version**: `getCacheKey()` returns `db:{theme}:{path}:{version_uuid}:policy:{TemplatePolicy::CACHE_VERSION}`. Every save is a new compiled-cache entry — **no compiled-cache purging exists in this design**. `isFresh()` returns true (versions are immutable). The policy segment closes the tightening gap: the compile-time lint (§4) only runs on compile, so **every allowlist/enforcement change must bump `TemplatePolicy::CACHE_VERSION`** — the bump orphans all previously compiled DB templates, forcing recompile-and-re-lint under the new policy. Without it, a template compiled under an older, looser policy would keep executing unchecked.
- `exists()`/`getCacheKey()` answer from the memoized map; `getSourceContext()` fetches the one source row **and runs the linter first** (§4).
- **Hierarchy for free**: the render controller's template-candidate checks already go through the environment loader's `exists()`, so a DB-only `entry/interview.twig` joins the hierarchy with zero controller changes.
- **Preview sessions**: `themedEnv()` builds its request-local `TwigFactory` with a `DatabaseTemplateLoader` scoped to the **session's** theme — per-theme overrides apply in themed previews. This is the intended authoring loop for inactive themes: save overrides for theme B, mint a preview with `theme: B`, review, then switch.
- Page-cache HITs never construct Twig, so the loader adds **zero queries to cached responses**.

## 4. Enforcement: policy + linter (no runtime sandbox)

**The `TemplateLinter` AST scan is the enforcement engine.** `Twig\Sandbox\SecurityPolicy` may be reused as an allowlist data holder, but **nothing relies on Twig's sandbox at runtime** — `SandboxExtension` is never registered. A reader of this spec must not conclude Twig's sandbox is active; it is not.

- `TemplatePolicy` (pack-owned, **not configurable** in v1) holds the allowlists.
- `TemplateLinter` tokenizes + parses the source in a scratch environment carrying the same extension set (so `menu()`/`asset()`/`facets()` parse), then **walks the AST** checking: used tags, filters, functions, tests; attribute access (array/property access allowed, **explicit method-call syntax denied**); `include`/`extends` targets — **constant string targets only in v1** (a dynamic template-name expression is a violation; constant targets are safe regardless of origin, since filesystem targets are trusted code and DB targets are themselves scanned); and **default-deny on unknown node types** — any AST node class outside the known-safe list is a violation, so new Twig features stay closed until reviewed.
- The linter reports **all violations with line numbers** in one pass.

It runs at two points:

1. **Save** → HTTP 422, `errors: [{line, message}, …]` (the editor UX).
2. **Compile** — `DatabaseTemplateLoader::getSourceContext()` scans before the source reaches the compiler → `LoaderError` → the existing render try/catch (themed 500 → plain-text fallback) + log. Rows written around the API (SQL injection, rogue migration) are therefore still enforced; compile-cache keying amortizes the scan to zero after first render.

**Soundness invariant (pinned):** the render template context contains **arrays and scalars only — never objects** — and the render extension functions return arrays/strings. This is a standing constraint on future context additions; anyone adding an object to the render context must revisit this design. With no objects reachable, Twig's runtime-only sandbox checks (method calls, `__toString`) have no surface, and the static scan covers the whole policy.

**v1 allowlist (pinned):**

- **Tags:** `if`, `for`, `set`, `block`, `extends`, `include`, `verbatim`.
- **Filters:** `abs`, `batch`, `capitalize`, `column`, `date`, `date_modify`, `default`, `escape`, `e`, `first`, `format`, `join`, `json_encode`, `keys`, `last`, `length`, `lower`, `merge`, `nl2br`, `number_format`, `replace`, `reverse`, `round`, `slice`, `sort`, `split`, `striptags`, `title`, `trim`, `upper`, `url_encode`. **No `raw`** — DB templates are the deliberately-safer authoring surface and `|raw` is the easy path from a content field to stored XSS; filesystem themes (deployed code) keep it. Allowing `raw` later requires an explicit "`templates.manage` can emit arbitrary HTML" product decision. **No `filter`/`map`/`reduce`** (arrow-function filters) in v1 — scanner support first.
- **Functions:** `menu`, `path`, `asset`, `facets` (the render extension), plus `include`, `parent`, `block`, `cycle`, `date`, `min`, `max`, `range`.
- **Tests:** `defined`, `empty`, `even`, `iterable`, `null`, `odd`, `same as`, `divisible by`, `sequence`, `mapping`.
- **Denied explicitly** (non-exhaustive; the allowlist is the rule): `attribute()`, `constant()`, `source()`, `template_from_string()`, `dump()`; the `sandbox`/`macro`/`import`/`from`/`apply`/`embed`/`use`/`autoescape` tags; the `raw` filter; the `constant` test; method-call syntax; macros/imports stay out until scanner support is proven.

## 5. Save pipeline & purges

`PUT` upsert order:

1. **Path validation** (422): template paths are **conservative and URL-safe by construction** — slash-separated segments, each matching `[A-Za-z0-9._-]+` and not `.`/`..`, no empty segments, **must end `.twig`**. The charset makes `?`, `#`, spaces, `\`, `:`/schemes, and every other URL-significant character unrepresentable — required because the admin client substitutes `{path}` into URLs raw (slash-preserving), so a saved path must always be addressable and deterministically routed. Validation is syntactic only: any conforming `.twig` path is allowed (hierarchy templates AND partials an override includes). DB-only paths are explicitly allowed — that's the "create in hierarchy" scope; "unknown template" never means "not on the filesystem".
2. **Theme validation** (404): the theme must pass the existing `RenderThemeValidator` (`default` or a valid installed theme). Editing an **inactive** theme is allowed by design (preview-session authoring loop, §3).
3. **Lint** (422, all violations with lines).
4. **Transaction**: version insert + template upsert/reactivate.
5. **Dispatch `TemplateUpdated(theme, path)`** via `EventService`. (DELETE/deactivate and restore dispatch the same event — every mutation that changes what renders.)

**Purge listener** (`PurgeRenderCacheOnTemplateUpdate`, menu-listener shape):

- Purges the render **page cache** and the **`RenderErrorCache`** (a `404.twig`/`error.twig` override changes the fixed bodies) — **only when the edited theme is the active theme**. Inactive themes never populate the shared caches (preview sessions are uncached), so their edits purge nothing.
- Fires on save, delete (deactivate), and restore. Broad purge over cleverness.
- Compiled Twig cache: untouched (version-keyed, §3).

## 6. Admin API

Routes in `packages/lemma-render/routes/admin-routes.php`, prefix `/v1/admin/render`, triple-gated (§1). `{path}` spans slashes.

| Route | Action |
|---|---|
| `GET /templates?theme=` | Merged listing: pack-default files + app-theme files + active DB rows → `{path, origin: db\|theme\|default, overridden, updated_at}`. `theme` defaults to the active theme. |
| `GET /templates/{path}?theme=` | Current source + origin. Filesystem sources are returned read-only (the copy-from-disk starting point). **404 for a path with no override and no file** (nothing to show). |
| `PUT /templates/{path}?theme=` | Save override, body `{source}` (§5). Creates DB-only paths. |
| `DELETE /templates/{path}?theme=` | Deactivate the override (history preserved), fall back to filesystem. UI label "Delete override". |
| `GET /templates/{path}/versions?theme=` | History (newest first): `{uuid, created_by, created_at, current}`. |
| `GET /templates/{path}/versions/{uuid}?theme=` | One version's source. |
| `POST /templates/{path}/versions/{uuid}/restore?theme=` | Restore = new version (§2). |

**Route-grammar pins** (the slash-spanning `{path}` must not swallow subroutes — same determinism class as the render catch-all and term-index grammar):

- **Version routes register before the generic `{path}` routes.**
- Generic source routes constrain `{path}` to **end in `.twig`** (`->where('path', '.+\.twig')`); version routes match `{path}` ending `.twig` **followed by** the `/versions…` suffix.
- The restore/show `{uuid}` is constrained to the project's UUID shape.
- **Characterization test (required):** `GET /templates/entry/blog.twig/versions` hits the history action — never the generic show with `path = "entry/blog.twig/versions"`.

Error semantics: unknown/invalid theme → 404; invalid path or lint failure → 422; missing override where one is required (DELETE/versions/restore on a path with no DB row) → 404; permission failures → the `lemma_permission` middleware's standard 403.

## 7. Kill-switch & ops

`lemma_render.db_templates` (env `RENDER_DB_TEMPLATES`, **default `true`**). When `false`:

- `TwigFactory` gets no DB loader (pure filesystem chain — pre-feature behavior, byte-identical);
- the template admin routes are not registered (404).

This is the ops escape hatch when an override breaks something beyond the API's own reach. Failure containment that already exists and is relied on: a broken `error.twig` override degrades through the render recursion guard to the plain-text 500 — the site never render-loops; and the admin API lives on non-render routes, so a broken page template never blocks the fix.

## 8. Admin SPA

A **Templates** screen in the admin SPA at `src/pages/templates/` (its own top-level nav item alongside Navigation/Settings, gated on the `templates.manage` permission like the other managed sections):

- **List**: templates grouped by family (layout/entry/listing/archive/terms/partials), origin badge (`db` / `theme` / `default`), overridden state, updated-at. Theme selector (installed themes; defaults to active).
- **Editor**: CodeMirror 6 with the legacy Twig/Jinja mode; opening a filesystem template shows its source read-only with an **Edit** action that copies it into the editor; save calls `PUT` and renders 422 lint violations inline at their line numbers; "Delete override" (with confirm) falls back to filesystem.
- **History panel**: version list, view a version's source, restore (confirm).
- Conventions: Pinia setup store, `UForm`+zod where forms exist, `data-test` hooks for tests, regenerated API types (`pnpm gen:api`).

## 9. Error handling (consolidated)

| Failure | Behavior |
|---|---|
| Lint violation at save | 422 with `[{line, message}]`, all violations at once |
| Invalid path syntax | 422 |
| Unknown theme | 404 |
| No source at path (GET) / no override (DELETE, versions, restore) | 404 |
| Missing permission | 403 (middleware) |
| Policy violation at compile (row written around the API) | `LoaderError` → themed 500 → plain-text fallback; logged with theme+path; never executes |
| Broken `error.twig` override | plain-text 500 via the existing recursion guard |
| DB unreachable in loader | render fails (moot — the site's DB is down) |

## 10. Testing

Integration tests in the existing suite env, plus SPA checks:

- **Loader**: override shadows the filesystem template; DB-only hierarchy template (`entry/interview.twig`) resolves through the candidate checks; deactivated override falls back; inactive rows invisible.
- **Freshness**: save → the very next request renders the new version (no restart); two renders in one process see a save between them (request-scoped memo, controller reset). **Exists-cache regression test (required)**: render a path where `entry/interview.twig` does NOT yet exist (miss), save a DB-only `entry/interview.twig`, and the next render in the same process must resolve it — the case `ChainLoader`'s persistent `$hasSourceCache` would break.
- **Purges**: active-theme save/delete/restore purge page cache + error cache (a cached page re-renders; the fixed 404 body changes after a `404.twig` override); inactive-theme save purges nothing.
- **Linter**: denied tag / filter (`raw`) / function (`constant`) / method-call syntax / unknown-node each 422 with correct line numbers; a representative valid template (extends + blocks + for + filters + render functions) saves clean.
- **Compile-time enforcement**: a malicious row inserted directly via SQL renders themed 500 and never executes.
- **History**: versions accumulate; restore creates a new version and repoints; delete preserves rows; re-create reactivates with continued history.
- **API**: 403 without `templates.manage`; path traversal → 422; the §6 route-grammar characterization test (`…/blog.twig/versions` hits history).
- **Preview sessions**: a themed session renders that theme's overrides; the boot environment stays unpoisoned.
- **Kill-switch**: `db_templates=false` → filesystem-only rendering, admin routes 404.
- **OpenAPI**: regenerate; new admin endpoints present; `cd admin && pnpm gen:api && pnpm type-check`.

## Out of scope (v1)

- Full theme authoring (theme.json, assets) in the DB; theme export.
- Draft/preview-before-activate workflow for template edits (layers on preview sessions later).
- App-extendable policy; `raw`, macros/imports, arrow-function filters (`filter`/`map`/`reduce`).
- Hard-pruning version history.
