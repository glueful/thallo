# De-brand: Remove all `lemma` Identifiers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename every identifier that contains `lemma`/`LEMMA` so the tracked source carries **zero traces of the product's old name** — the product name (Thallo) then lives only where a product name belongs (code namespaces + user-facing brand copy), and the data/deploy surface stays brand-neutral.

**Architecture:** A mechanical rename governed by **one split rule** (below). App package code moves out of the framework's `Glueful\` namespace into the product's own `Thallo\` namespace (separating app code from framework code); the persisted/deploy surface (DB tables, `.env` vars) is stripped to neutral names. Hard cutover — **no `LEMMA_*` / `lemma_*` back-compat shims** (a shim is itself a trace of the old name); the app is pre-production, so a `migrate:fresh` is the fast path.

**Tech Stack:** PHP 8.3 (Glueful framework 1.66.1 "Adhara"), PostgreSQL/MySQL/SQLite, Vue 3 + Nuxt UI admin SPA (Vite → `public/admin`), PHPUnit, vitest/vue-tsc/oxlint.

## Global Constraints

- **The split rule (authoritative — every task obeys it):**
  - **Rule D — Data & deploy surface → strip `lemma`, neutral name.** Applies to: **DB table names**, **`.env` variable names**, the **test DB name/role**, and the **search-index name**. These are expensive/external to rename and must not carry a brand. Example: `lemma_settings` → `settings`, `LEMMA_ADMIN_ENABLED` → `ADMIN_ENABLED`.
  - **Rule C — Source-code identifiers → `Thallo` (product) or the bare capability.** Applies to: **PHP namespaces/classes/providers/interfaces**, **composer package names**, **config files + config-tree keys**, **CLI commands**, **middleware aliases**, **route file names**, **runtime string namespaces** (cache keys/tags, cookie names, request-attribute constants), **frontend components/CSS classes**, **cross-boundary protocol strings**, and the **literal brand string** (`'Lemma'` → `'Thallo'`). Code is cheap to rename and app code should read as the product, separated from the framework.
- **Collections are already done.** Collection physical tables use the `coll_` prefix (`CollectionManager::TABLE_PREFIX = 'coll_'`), never `lemma_`. `coll_` was a deliberate grouping prefix (collection tables had no natural name). **Do not touch collection tables or the `coll_` prefix.**
- **`App\` namespace stays.** The CMS core already lives under `App\` (neutral, no `lemma`, already separate from the framework). Only the 9 `packages/lemma-*` packages carry `Glueful\Lemma\*`.
- **Composer vendor stays `glueful/`** (the publisher), product tag becomes `thallo`: `glueful/lemma` → `glueful/thallo`, `glueful/lemma-render` → `glueful/thallo-render`.
- **Collision-safe neutralization.** Stripping some names would land on a word the framework already owns. Rule-D strips must NOT collide with the framework's Aegis/Users tables (`users`, `permissions`, `roles`, …). The two watch-items are role slugs and the `permission` alias — handled explicitly in Tasks 6–7.
- No AI attribution in any commit. Work on `dev`. Batch commits by layer (per project convention), not per file.
- **Acceptance (final gate) — "zero traces" with a *bounded, explicit* allowlist.** After the sweep, `git grep -niI lemma` must return **nothing** in tracked source EXCEPT these intentional legacy references:
  1. `CHANGELOG.md` — historical release entries recording the product's past name (a record, not live code). *Default: keep.* If you'd rather scrub history too, say so and Task 9 rewrites it.
  2. `docs/superpowers/plans/2026-07-06-debrand-lemma-identifiers.md` — this plan documents the migration and necessarily names the old identifiers.
  3. `database/migrations/NNN_RenameLemmaTablesToNeutral.php` — the rename migration **must** name `lemma_*` to rename it, so it cannot pass a zero-lemma grep. **This file is only shipped if you keep the rename path.** (Not shipped — pre-prod used `migrate:fresh`.)
  4. **Dated historical planning/spec records** under `docs/plans/`, `docs/superpowers/plans/`, and `docs/superpowers/specs/` — treated as records like the CHANGELOG (they document feature work performed while the product was named Lemma; scrubbing them would rewrite history). Their content and filenames retain the old name by design. *(Chosen at execution; flip to full-scrub if you'd rather zero these too.)*
  - **Preferred path (pre-prod): `migrate:fresh`, and do NOT ship the rename migration** (Task 3 Step 4 becomes skip). Then item 3 disappears and tracked *code* is literally lemma-free — only CHANGELOG + this plan (both meta/historical, not code) carry the name.
  - Anything outside this allowlist is a missed identifier, not an "intentional legacy reference" — fix it. Note: `git grep` scans tracked file contents only, so git **commit messages** (which will say e.g. `move Glueful\Lemma\*…`) are outside the gate and need no allowlisting.

---

## Canonical Naming Map (authoritative — every task references this)

### A. PHP namespaces, packages, composer (Rule C — `Thallo\` + `glueful/thallo-*`)

The 9 packages under `packages/lemma-*` (dir → composer `name` → psr-4 namespace → provider):

```
lemma-analytics   → thallo-analytics    glueful/thallo-analytics    Thallo\Analytics    LemmaAnalyticsServiceProvider   → AnalyticsServiceProvider
lemma-collections → thallo-collections  glueful/thallo-collections  Thallo\Collections  LemmaCollectionsServiceProvider → CollectionsServiceProvider
lemma-contracts   → thallo-contracts    glueful/thallo-contracts    Thallo\Contracts    (no provider)
lemma-importers   → thallo-importers    glueful/thallo-importers    Thallo\Importers    LemmaImportersServiceProvider   → ImportersServiceProvider
lemma-navigation  → thallo-navigation   glueful/thallo-navigation   Thallo\Navigation   LemmaNavigationServiceProvider  → NavigationServiceProvider
lemma-render      → thallo-render       glueful/thallo-render       Thallo\Render       LemmaRenderServiceProvider      → RenderServiceProvider
lemma-search      → thallo-search       glueful/thallo-search       Thallo\Search       LemmaSearchServiceProvider      → SearchServiceProvider
lemma-seo         → thallo-seo          glueful/thallo-seo          Thallo\Seo          LemmaSeoServiceProvider         → SeoServiceProvider
lemma-workflow    → thallo-workflow     glueful/thallo-workflow     Thallo\Workflow     LemmaWorkflowServiceProvider    → WorkflowServiceProvider
Root app: glueful/lemma → glueful/thallo
```

### B. Other class / interface renames (Rule C — drop redundant `Lemma`, product root = `Thallo`)

```
App\Providers\LemmaServiceProvider                    → App\Providers\ThalloServiceProvider   (names the product → Thallo)
Glueful\Lemma\Contracts\Context\LemmaContext          → Thallo\Contracts\Context\Context       (namespace already scopes it)
App\Content\Context\EngineLemmaContext                → App\Content\Context\EngineContext
App\Content\ImportExport\LemmaContentExporter         → App\Content\ImportExport\ContentExporter
App\Content\ImportExport\LemmaContentImporter         → App\Content\ImportExport\ContentImporter
App\Content\Http\RequireLemmaPermission               → App\Content\Http\RequirePermission
Migration classes (database/migrations/):
  CreateLemmaSettingsTable            → CreateSettingsTable            (013)
  CreateLemmaBlockTypesTable          → CreateBlockTypesTable          (017)
  CreateLemmaBlockTypeMigrationsTable → CreateBlockTypeMigrationsTable (018)
  CreateLemmaRegionsTable             → CreateRegionsTable             (019)
Migration class (database/dependent-migrations/):
  SeedLemmaRolesAndPermissions        → SeedRolesAndPermissions        (004)
Test classes (tests/):
  LemmaTestCase → TestCase (base), LemmaBinTest → BinTest, LemmaContextContractTest → ContextContractTest,
  LemmaContentExporterTest → ContentExporterTest, LemmaContentImporterTest → ContentImporterTest,
  RequireLemmaPermissionTest → RequirePermissionTest
```

### C. DB tables — the ONLY 7 real `lemma_` tables (Rule D — strip)

```
CORE (database/migrations/):
  lemma_settings                 → settings                 (013_CreateLemmaSettingsTable.php)   ⚠ generic — see collision note
  lemma_block_types              → block_types              (017)
  lemma_block_type_migrations    → block_type_migrations    (018)
  lemma_regions                  → regions                  (019)                                 ⚠ generic — see collision note
  lemma_filter_indexes           → filter_indexes           (009_AddFilterIndexRegistry.php)
RENDER (packages/thallo-render/migrations/):
  lemma_render_templates         → render_templates         (001)
  lemma_render_template_versions → render_template_versions (002)
DYNAMIC INDEX PREFIX (not a table):
  Postgres index names 'lemma_fidx_<sha1>' (FilterIndexPlanner) → 'fidx_<sha1>'  — rows stored in filter_indexes; needs index rebuild, not a string swap
```
> **NOT tables** (the old plan mislabeled these): `lemma_permission`/`lemma_delivery_access` are middleware aliases (Task 6); `lemma_render`/`lemma_seo`/`lemma_search`/`lemma_workflow` are config-tree keys (Task 4); `lemma:entry`/`lemma:type`/`lemma_seo:sitemap`/`lemma:preview:working` are cache keys, `lemma_preview` a cookie, `lemma_preview_session` a request-attribute constant (Task 7); `lemma_editor_de/fr`/`lemma_reader_global`/`lemma_admin`/`lemma_editor`/`lemma_viewer` are RBAC role slugs (Task 7); `lemma_content` (search) is an index name (Task 7). **All package tables are already domain-named** (`seo_meta`, `workflow_*`, `navigation_*`, `analytics_*`, `collection_*`, `coll_*`) — no change.
> **Collision note:** `settings` and `regions` are generic. No framework table currently owns them (framework has `email_settings`, not `settings`), so the strip is safe today; flagged so a future core `settings`/`regions` table triggers a revisit. Do **not** brand them — Rule D keeps the data surface neutral.

### D. Env vars (27) — Rule D (strip `LEMMA_`)

```
LEMMA_SITE_NAME→SITE_NAME  LEMMA_SITE_PREVIEW_URL→SITE_PREVIEW_URL  LEMMA_MEDIA_DISK→MEDIA_DISK
LEMMA_SETUP_TOKEN→SETUP_TOKEN  LEMMA_DEFAULT_LOCALE→DEFAULT_LOCALE  LEMMA_PUBLIC_URL_BASE→PUBLIC_URL_BASE
LEMMA_PREVIEW_TTL→PREVIEW_TTL  LEMMA_CUSTOM_CSS_MAX_BYTES→CUSTOM_CSS_MAX_BYTES  LEMMA_WEBHOOKS_ENABLED→WEBHOOKS_ENABLED
LEMMA_SCHEDULER_ENABLED→SCHEDULER_ENABLED  LEMMA_VERSION_KEEP→VERSION_KEEP  LEMMA_VERSION_MAX_AGE_DAYS→VERSION_MAX_AGE_DAYS
LEMMA_CONSOLE_NAME→CONSOLE_NAME  LEMMA_CONSOLE_VERSION→CONSOLE_VERSION
LEMMA_ADMIN_API_BASE→ADMIN_API_BASE  LEMMA_ADMIN_DEFAULT_LOCALE→ADMIN_DEFAULT_LOCALE
LEMMA_ADMIN_ENABLED→ADMIN_ENABLED  LEMMA_ADMIN_BUNDLE_PATH→ADMIN_BUNDLE_PATH
LEMMA_COLLECTIONS_DEFAULT_PER_PAGE→COLLECTIONS_DEFAULT_PER_PAGE  LEMMA_COLLECTIONS_MAX_PER_PAGE→COLLECTIONS_MAX_PER_PAGE
LEMMA_COLLECTIONS_MAX_BULK→COLLECTIONS_MAX_BULK
LEMMA_DELIVERY_DEFAULT_PER_PAGE→DELIVERY_DEFAULT_PER_PAGE  LEMMA_DELIVERY_MAX_PER_PAGE→DELIVERY_MAX_PER_PAGE
LEMMA_DELIVERY_CACHE_TTL→DELIVERY_CACHE_TTL
LEMMA_SEO_ROUTE_TEMPLATE→SEO_ROUTE_TEMPLATE  LEMMA_SEO_REDIRECT_TTL→SEO_REDIRECT_TTL
```

### E. Config files + config-tree keys (Rule C)

```
Core (product = thallo):  config/lemma.php → config/thallo.php ;  config('lemma.*') → config('thallo.*')
Packages (bare capability — matches the existing analytics convention: config/analytics.php + key 'analytics'):
  packages/thallo-render/config/lemma-render.php   → config/render.php    (mergeConfig key 'lemma_render'   → 'render')
  packages/thallo-search/config/lemma-search.php   → config/search.php    (mergeConfig key 'lemma_search'   → 'search')
  packages/thallo-seo/config/lemma-seo.php         → config/seo.php       (mergeConfig key 'lemma_seo'      → 'seo')
  packages/thallo-workflow/config/lemma-workflow.php → config/workflow.php (mergeConfig key 'lemma_workflow' → 'workflow')
```

### F. CLI commands (Rule C)

```
Core (product = thallo:):
  lemma:doctor→thallo:doctor  lemma:provision→thallo:provision  lemma:resync→thallo:resync
  lemma:create-admin→thallo:create-admin  lemma:schema:backfill→thallo:schema:backfill
  lemma:schedules:run→thallo:schedules:run  lemma:blocks:seed→thallo:blocks:seed
  lemma:blocks:migration:backfill→thallo:blocks:migration:backfill  lemma:versions:prune→thallo:versions:prune
Render package (bare capability, matches existing render:* commands):
  lemma:theme:clone → render:theme:clone
```

### G. Routes, middleware aliases, runtime strings, frontend, brand

```
Route files (Rule C — strip to descriptive; loaded by explicit path):
  routes/lemma_admin.php→routes/admin.php  routes/lemma_admin_spa.php→routes/admin_spa.php
  routes/lemma_content.php→routes/content.php  routes/lemma_preview.php→routes/preview.php
  config/documentation.php doc stubs 'lemma_content.php'/'lemma_admin.php'/'lemma_preview.php' → match new names
Middleware aliases (Rule C — domain-qualified to dodge the Aegis `permission` collision):
  lemma_permission      → content_permission   (class RequireLemmaPermission → RequirePermission, in App\Content\Http)
  lemma_delivery_access → delivery_access
Cache keys/tags + cookie + attr (Rule C — product-scoped 'thallo' to stay collision-safe in shared stores):
  tags   lemma:entry:{uuid}→thallo:entry:{uuid}  lemma:type:{slug}→thallo:type:{slug}  lemma:render:page→thallo:render:page
  keys   lemma_seo:sitemap:*→thallo:seo:sitemap:*   lemma:preview:working:*→thallo:preview:working:*
  cookie lemma_preview→thallo_preview             attr const 'lemma_preview_session'→'thallo_preview_session'
Search index name (Rule D — strip; SEARCH_INDEX env already overrides):
  default 'lemma_content' → 'content'
RBAC role slugs (Rule D — strip, but Aegis-collision-checked; see Task 7):
  lemma_admin→admin?  lemma_editor→editor?  lemma_viewer→viewer?  lemma_editor_de/fr→editor_de/fr  lemma_reader_global→reader_global
  (verify each stripped slug is free of Aegis seeded slugs; domain-qualify any collision, e.g. content_editor)
Frontend admin SPA (Rule C — product 'thallo'):
  CSS classes lemma-*→thallo-* (lemma-canvas-*, lemma-block-*, lemma-edit-*, lemma-preview-block, lemma-render, lemma-workflow, lemma-navigation, lemma-blocks-context, lemma-admin-dev)
  component LemmaIcon→ThalloIcon (admin/src/components/LemmaIcon.vue)
  persist-secret default 'lemma-admin-dev'→'thallo-admin-dev' (admin/src/stores/session.ts)
Canvas bridge protocol (Rule C — must stay in sync across 3 files):
  message type 'lemma:canvas-hello' → 'thallo:canvas-hello' (SPA useCanvasBridge.ts + preview-bridge.js + RenderController.php)
Brand literal (Rule C):
  default string 'Lemma' → 'Thallo' (config/thallo.php site_name, render config site_name default, CONSOLE_NAME default)
```

---

## File-touch overview

- 9 `packages/lemma-*/` dirs renamed → `packages/thallo-*/`; each `composer.json` (`name`, psr-4, `extra.glueful.provider`) rewritten.
- Root `composer.json`: `name`, `repositories` path entries, `require` of the 9 packages, `scripts` (test DB name), `description`.
- Root `config/lemma.php` → `config/thallo.php`; 4 package configs → bare-capability files/keys; `config/extensions.php` provider FQCNs; `config/schedule.php`, `config/documentation.php` refs.
- `database/migrations/*Lemma*` + `database/dependent-migrations/004_*` classes/files; one rename migration.
- `routes/lemma_*.php` → `routes/*.php` + `App\Providers\ThalloServiceProvider` route/middleware/command wiring.
- `admin/src/**` (~10 files with `lemma-*`) CSS/components/protocol/const.
- `.env.example`, tracked docs.

---

## Tasks

### Task 0: Baseline & branch

**Files:** none (setup)

- [ ] **Step 1:** Confirm working tree clean and on `dev`.
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && git status --short && git rev-parse --abbrev-ref HEAD
```
Expected: clean, `dev`.
- [ ] **Step 2:** Capture the green baseline (the regression oracle for every later task). Record pass counts.
```bash
composer test 2>&1 | tail -15
php glueful list >/dev/null && echo "CLI OK"
(cd admin && pnpm type-check && node_modules/.bin/oxlint src && node_modules/.bin/vitest run) 2>&1 | tail -8
```
Expected: all green. Do NOT proceed if red.
- [ ] **Step 3:** Snapshot the footprint to measure progress.
```bash
git grep -icI lemma -- ':!docs/superpowers/plans/*' ':!CHANGELOG.md' | awk -F: '{s+=$2} END{print "lemma refs:",s}'
```
Expected: baseline count (~5,200).

---

### Task 1: Move PHP packages to `Thallo\*` (dirs, composer, autoload)

Highest-leverage change: once autoload maps the new namespaces, most references become mechanical.

**Files:**
- Rename: `packages/lemma-*` → `packages/thallo-*` (9 dirs)
- Modify: each `packages/thallo-*/composer.json` (`name`, psr-4 key, `extra.glueful.provider`)
- Modify: root `composer.json` (`repositories` paths, `require` keys)
- Modify: every `*.php` under the 9 packages (`namespace`/`use` lines)

**Interfaces:**
- Produces: `Thallo\<Capability>\*` classes + `glueful/thallo-<capability>` packages referenced by later tasks.

- [ ] **Step 1:** Move directories (preserve history).
```bash
for d in analytics collections contracts importers navigation render search seo workflow; do
  git mv "packages/lemma-$d" "packages/thallo-$d"
done
```
- [ ] **Step 2:** Rewrite the namespace segment in every package `.php` (macOS: use `perl -i`, not BSD `sed` — it mangles backslashes; see project memory).
```bash
grep -rl 'Glueful\\Lemma\\' packages/thallo-* | xargs perl -pi -e 's/Glueful\\Lemma\\/Thallo\\/g'
```
- [ ] **Step 3:** Rewrite each package `composer.json` (example — collections; repeat for all 9, and contracts has no provider):
```jsonc
// packages/thallo-collections/composer.json
"name": "glueful/thallo-collections",
"autoload": { "psr-4": { "Thallo\\Collections\\": "src/" } },
"extra": { "glueful": { "provider": "Thallo\\Collections\\CollectionsServiceProvider" } }
```
- [ ] **Step 4:** Update root `composer.json` path repositories + requires.
```jsonc
"repositories": [ { "type": "path", "url": "packages/thallo-*" } ],   // or 9 explicit entries — match existing style
"require": { "glueful/thallo-collections": "*", /* …8 more… */ }
```
- [ ] **Step 5:** Regenerate autoload; verify a class resolves.
```bash
composer dump-autoload 2>&1 | tail -3
php -r 'require "vendor/autoload.php"; echo class_exists("Thallo\\Collections\\CollectionsServiceProvider")?"ok\n":"MISSING\n";'
```
Expected: `ok` (this also proves Task 2's provider rename lands; if `MISSING` here, that's expected until Task 2 renames the provider class — re-run after Task 2).
- [ ] **Step 6:** Point provider FQCNs at the new namespace in `config/extensions.php` (`Glueful\Lemma\X\LemmaXServiceProvider` → `Thallo\X\...ServiceProvider` — final class name set in Task 2) and `App\Providers\LemmaServiceProvider::services()` if it lists them.
- [ ] **Step 7:** Verify no `Glueful\Lemma` remains; run PHP suite (provider class names still `LemmaXServiceProvider` here — Task 2 fixes those; suite may reference old provider names until then, so run after Task 2 if red).
```bash
git grep -n 'Glueful\\Lemma' -- '*.php'   # expect: no output
```
- [ ] **Step 8:** Commit.
```bash
git add -A && git commit -m "packages: move Glueful\\Lemma\\* to Thallo\\* (dirs + composer + namespaces)"
```

---

### Task 2: Rename providers, middleware class, context, importer/exporter classes

**Files:** provider classes (Map A), `App\Content\Http\RequireLemmaPermission`, `LemmaContext`, `EngineLemmaContext`, importer/exporter (Map B) + all references.

- [ ] **Step 1:** Rename provider classes + files (drop redundant `Lemma`). Example (collections), repeat for the 7 package providers with providers + the app provider:
```bash
git mv packages/thallo-collections/src/LemmaCollectionsServiceProvider.php packages/thallo-collections/src/CollectionsServiceProvider.php
perl -pi -e 's/\bLemmaCollectionsServiceProvider\b/CollectionsServiceProvider/g' $(git grep -rl LemmaCollectionsServiceProvider)
```
Do the same for `LemmaAnalyticsServiceProvider`, `LemmaImportersServiceProvider`, `LemmaNavigationServiceProvider`, `LemmaRenderServiceProvider`, `LemmaSearchServiceProvider`, `LemmaSeoServiceProvider`, `LemmaWorkflowServiceProvider` → drop `Lemma`.
- [ ] **Step 2:** App root provider `App\Providers\LemmaServiceProvider` → `ThalloServiceProvider` (class + `app/Providers/LemmaServiceProvider.php`); update `bootstrap/app.php` / `config/app.php` / `config/extensions.php` registrations.
```bash
git mv app/Providers/LemmaServiceProvider.php app/Providers/ThalloServiceProvider.php
perl -pi -e 's/\bLemmaServiceProvider\b/ThalloServiceProvider/g' $(git grep -rl 'LemmaServiceProvider')
```
- [ ] **Step 3:** Remaining classes (interface, context impl, importer/exporter, middleware class):
```bash
perl -pi -e 's/\bLemmaContext\b/Context/g' $(git grep -rl 'LemmaContext')            # interface Thallo\Contracts\Context\Context
git mv packages/thallo-contracts/src/Context/LemmaContext.php packages/thallo-contracts/src/Context/Context.php
perl -pi -e 's/\bEngineLemmaContext\b/EngineContext/g' $(git grep -rl EngineLemmaContext)
git mv app/Content/Context/EngineLemmaContext.php app/Content/Context/EngineContext.php
perl -pi -e 's/\bLemmaContentImporter\b/ContentImporter/g; s/\bLemmaContentExporter\b/ContentExporter/g' $(git grep -rl 'LemmaContent\(Importer\|Exporter\)')
git mv app/Content/ImportExport/LemmaContentImporter.php app/Content/ImportExport/ContentImporter.php
git mv app/Content/ImportExport/LemmaContentExporter.php app/Content/ImportExport/ContentExporter.php
perl -pi -e 's/\bRequireLemmaPermission\b/RequirePermission/g' $(git grep -rl RequireLemmaPermission)
git mv app/Content/Http/RequireLemmaPermission.php app/Content/Http/RequirePermission.php
```
> Watch `LemmaContext`→`Context`: ensure no un-namespaced `Context` collision in files that also import another `Context`; alias on import if needed.
- [ ] **Step 4:** Rename test classes + files (Map B): `LemmaTestCase→TestCase`, `LemmaBinTest→BinTest`, `LemmaContextContractTest→ContextContractTest`, `LemmaContentExporterTest→ContentExporterTest`, `LemmaContentImporterTest→ContentImporterTest`, `RequireLemmaPermissionTest→RequirePermissionTest` (same `git mv` + `perl` pattern).
- [ ] **Step 5:** Verify + test.
```bash
git grep -nI "Lemma" -- '*.php' | grep -vE "database/(migrations|dependent-migrations)"   # only migration classes remain (Task 3)
composer dump-autoload && composer test 2>&1 | tail -8
php glueful list >/dev/null && echo "CLI boots"
```
Expected: only `*Lemma*` migration class names remain; suite matches baseline.
- [ ] **Step 6:** Commit.
```bash
git commit -am "classes: drop Lemma from providers/middleware/context/importers/tests; app provider → ThalloServiceProvider"
```

---

### Task 3: Rename the 7 DB tables + migration classes (data-preserving)

**Files:** the 7 `Create*Lemma*Table` migration classes/files, table-name string literals across code, a NEW rename migration, and `FilterIndexPlanner` index prefix.

**Interfaces:** Produces the neutral table names (`settings`, `block_types`, `block_type_migrations`, `regions`, `filter_indexes`, `render_templates`, `render_template_versions`) referenced by models/repos/queries.

- [ ] **Step 1:** Rewrite table-name string literals per Map C (word-boundary anchored so `lemma_render_templates` matches before `lemma_render`).
```bash
perl -pi -e '
  s/\blemma_render_template_versions\b/render_template_versions/g;
  s/\blemma_render_templates\b/render_templates/g;
  s/\blemma_block_type_migrations\b/block_type_migrations/g;
  s/\blemma_block_types\b/block_types/g;
  s/\blemma_filter_indexes\b/filter_indexes/g;
  s/\blemma_settings\b/settings/g;
  s/\blemma_regions\b/regions/g;
' $(git grep -rl 'lemma_\(render_template\|block_type\|filter_indexes\|settings\|regions\)' -- '*.php')
```
- [ ] **Step 2:** Rename the dynamic Postgres index prefix in `app/Content/Indexing/FilterIndexPlanner.php` (`'lemma_fidx_'` → `'fidx_'`, both occurrences). Rows in `filter_indexes` store old names → the rename migration (Step 4) rebuilds them, OR `migrate:fresh` recreates them.
- [ ] **Step 3:** Rename the 4 `Create*Lemma*Table` migration classes + files and `SeedLemmaRolesAndPermissions` (Map B) so a fresh migrate creates neutral names directly.
```bash
git mv database/migrations/013_CreateLemmaSettingsTable.php database/migrations/013_CreateSettingsTable.php
perl -pi -e 's/\bCreateLemmaSettingsTable\b/CreateSettingsTable/g' database/migrations/013_CreateSettingsTable.php
# repeat: 017 CreateLemmaBlockTypesTable→CreateBlockTypesTable, 018 CreateLemmaBlockTypeMigrationsTable→CreateBlockTypeMigrationsTable,
#         019 CreateLemmaRegionsTable→CreateRegionsTable, dependent 004 SeedLemmaRolesAndPermissions→SeedRolesAndPermissions
```
- [ ] **Step 4 (OPTIONAL — skip on the preferred `migrate:fresh` path):** Only if you must preserve an existing dev DB with `lemma_*` tables, add one idempotent rename migration. **If you add it, it is the single allowlisted lemma-bearing file** (see the acceptance gate); on the preferred pre-prod path, do NOT create this file and tracked code stays literally lemma-free.
```php
// database/migrations/NNN_RenameLemmaTablesToNeutral.php
public function up(): void {
    $map = [
        'lemma_settings'=>'settings','lemma_block_types'=>'block_types',
        'lemma_block_type_migrations'=>'block_type_migrations','lemma_regions'=>'regions',
        'lemma_filter_indexes'=>'filter_indexes','lemma_render_templates'=>'render_templates',
        'lemma_render_template_versions'=>'render_template_versions',
    ];
    foreach ($map as $from => $to) {
        if ($this->schema->hasTable($from) && !$this->schema->hasTable($to)) {
            $this->db->statement("ALTER TABLE {$from} RENAME TO {$to}"); // metadata-only; rows preserved
        }
    }
    // filter index rows reference the old 'lemma_fidx_' prefix → clear & let EnsureFilterIndexesJob rebuild
}
public function down(): void { /* reverse the map */ }
```
- [ ] **Step 5:** Update the test DB name in `phpunit.xml` (`DB_PGSQL_DATABASE=lemma_test`), `scripts/reset-test-db.php`, `scripts/run-test-migrations.php`, and `tests/Integration/Content/ScheduleRepositoryTest.php` default (Rule D — strip → a neutral test DB name, e.g. `thallo_test` is a product brand and thus disallowed for data; use a neutral `app_test` or leave operator-configurable). Update `composer.json` scripts likewise.
- [ ] **Step 6:** Migrate fresh + test.
```bash
composer run test:reset-db && composer run test:migrate && composer test 2>&1 | tail -10
git grep -nI "lemma_\(settings\|block_type\|regions\|filter_indexes\|render_template\|fidx\)" -- '*.php'   # expect: no output
```
- [ ] **Step 7:** Commit.
```bash
git commit -am "db: strip lemma_ from the 7 tables + fidx_ index prefix (+ rename migration; neutral test db)"
```

---

### Task 4: Config files + config-tree keys + env vars (hard cutover, no shims)

**Files:** `config/lemma.php`→`config/thallo.php`, 4 package config files/keys, `.env.example`, every `env('LEMMA_…')`, `config('lemma…')`.

- [ ] **Step 1:** Core config file + keys.
```bash
git mv config/lemma.php config/thallo.php
perl -pi -e "s/config\\(([^,]+),\\s*'lemma\\./config(\$1, 'thallo./g; s/'lemma\\.'/'thallo.'/g" $(git grep -rl "'lemma\\." -- '*.php')
# also update App\Providers\ThalloServiceProvider where it loads/merges the core 'lemma' config tree → 'thallo'
```
- [ ] **Step 2:** Package config files + `mergeConfig` keys → bare capability (matches existing `analytics.php`).
```bash
git mv packages/thallo-render/config/lemma-render.php   packages/thallo-render/config/render.php
git mv packages/thallo-search/config/lemma-search.php   packages/thallo-search/config/search.php
git mv packages/thallo-seo/config/lemma-seo.php         packages/thallo-seo/config/seo.php
git mv packages/thallo-workflow/config/lemma-workflow.php packages/thallo-workflow/config/workflow.php
perl -pi -e "s/'lemma_render'/'render'/g;   s/lemma-render\\.php/render.php/g"     $(git grep -rl 'lemma_render\|lemma-render' -- 'packages/thallo-render/**')
perl -pi -e "s/'lemma_search'/'search'/g;   s/lemma-search\\.php/search.php/g"     $(git grep -rl 'lemma_search\|lemma-search' -- 'packages/thallo-search/**')
perl -pi -e "s/'lemma_seo'/'seo'/g;         s/lemma-seo\\.php/seo.php/g"           $(git grep -rl 'lemma_seo\|lemma-seo'     -- 'packages/thallo-seo/**')
perl -pi -e "s/'lemma_workflow'/'workflow'/g; s/lemma-workflow\\.php/workflow.php/g" $(git grep -rl 'lemma_workflow\|lemma-workflow' -- 'packages/thallo-workflow/**')
```
> The `lemma_seo:sitemap` cache KEY (contains `lemma_seo`) is NOT a config key — leave for Task 7.
- [ ] **Step 3:** Env vars — strip `LEMMA_` per Map D (specific prefixes are already covered by the bare strip; no ordering trap since we strip, not remap).
```bash
perl -pi -e 's/\bLEMMA_/ /g && 0; s/LEMMA_([A-Z0-9_]+)/$1/g' $(git grep -rl 'LEMMA_' -- '*.php' '*.example' '*.xml' '*.md')
```
- [ ] **Step 4:** Update `.env.example` (remove old keys, add new); update `config/documentation.php` titles/defaults if they read `LEMMA_*`; update the `'Lemma'` brand default string → `'Thallo'` in `config/thallo.php` and render config.
- [ ] **Step 5:** Verify + boot.
```bash
git grep -nI "LEMMA_\|'lemma\.\|\"lemma\.\|lemma-render\|lemma-seo\|lemma-search\|lemma-workflow" -- '*.php' '*.example' '*.xml'   # expect: no output
php glueful list >/dev/null && echo "boot OK"; composer test 2>&1 | tail -6
```
- [ ] **Step 6:** Commit.
```bash
git commit -am "config/env: config/thallo.php + bare-capability package configs + strip LEMMA_ env (no shims)"
```

---

### Task 5: CLI commands

**Files:** `#[AsCommand(name: 'lemma:…')]` attributes + string refs (`config/schedule.php`, tests).

- [ ] **Step 1:** Rewrite command names per Map F.
```bash
perl -pi -e "
  s/'lemma:blocks:migration:backfill'/'thallo:blocks:migration:backfill'/g;
  s/'lemma:schema:backfill'/'thallo:schema:backfill'/g;
  s/'lemma:schedules:run'/'thallo:schedules:run'/g;
  s/'lemma:versions:prune'/'thallo:versions:prune'/g;
  s/'lemma:create-admin'/'thallo:create-admin'/g;
  s/'lemma:blocks:seed'/'thallo:blocks:seed'/g;
  s/'lemma:provision'/'thallo:provision'/g;
  s/'lemma:doctor'/'thallo:doctor'/g;
  s/'lemma:resync'/'thallo:resync'/g;
  s/'lemma:theme:clone'/'render:theme:clone'/g;
" $(git grep -rl "'lemma:" -- '*.php')
```
- [ ] **Step 2:** Refresh the command manifest (stale manifest breaks CLI boot — see project memory; use `commands:cache`, not `cache:clear`).
```bash
rm -f storage/cache/glueful_commands_manifest.php; php glueful commands:cache 2>&1 | tail -3
php glueful list | grep -E "thallo:|render:" | head
git grep -n "lemma:" -- '*.php' | grep -vE "canvas-hello|entry:|type:|render:page|preview:working|sitemap"   # expect: no output (remaining lemma: are cache/protocol strings → Task 7)
```
- [ ] **Step 3:** Update `config/schedule.php` scheduled `lemma:*` entries.
- [ ] **Step 4:** Commit.
```bash
git commit -am "cli: rename lemma:* → thallo:* (+ render:theme:clone; refresh manifest)"
```

---

### Task 6: Route files + middleware aliases + provider wiring

**Files:** `routes/lemma_*.php`, alias registrations in `App\Providers\ThalloServiceProvider`, every `->middleware('lemma_…')`, `config/documentation.php` doc stubs.

- [ ] **Step 1:** Rename route files + update loaders.
```bash
git mv routes/lemma_admin.php routes/admin.php
git mv routes/lemma_admin_spa.php routes/admin_spa.php
git mv routes/lemma_content.php routes/content.php
git mv routes/lemma_preview.php routes/preview.php
perl -pi -e 's/lemma_admin_spa/admin_spa/g; s/lemma_admin/admin/g; s/lemma_content/content/g; s/lemma_preview(?!_session)/preview/g' \
  $(git grep -rl 'lemma_admin\|lemma_content\|lemma_preview' -- '*.php')
```
> `(?!_session)` guards the `lemma_preview_session` attribute constant (Task 7). The doc-stub filenames in `config/documentation.php` (`'lemma_content.php'` etc.) are caught by the same sweep.
- [ ] **Step 2:** Middleware aliases — domain-qualified (avoids the Aegis `permission` collision).
```bash
perl -pi -e 's/\blemma_permission\b/content_permission/g; s/\blemma_delivery_access\b/delivery_access/g' \
  $(git grep -rl 'lemma_permission\|lemma_delivery_access' -- '*.php')
```
Confirm the alias registrations in `App\Providers\ThalloServiceProvider` (`'alias' => ['content_permission']`, `['delivery_access']`) match.
- [ ] **Step 3:** Verify routes register + test.
```bash
git grep -n "lemma_admin\|lemma_content\|lemma_permission\|lemma_delivery_access" -- '*.php'   # expect: no output
php glueful route:list 2>/dev/null | grep -iE "admin|preview|content" | head
composer test 2>&1 | tail -8
```
- [ ] **Step 4:** Commit.
```bash
git commit -am "routes/middleware: strip lemma from route files + aliases (content_permission, delivery_access)"
```

---

### Task 7: Runtime strings (cache/cookie/attr), search index, RBAC role slugs, brand literal

**Files:** cache-key/tag builders (`DeliveryEtag`, `RenderPageCache`, listeners, SEO sitemap cache, `PreviewWorkingCopyStore`), `PreviewSessionMiddleware`, `RenderController`, search config/provider, `SeedRolesAndPermissions` + role config, locale RBAC test.

- [ ] **Step 1:** Cache tags/keys → product-scoped `thallo` (collision-safe in shared stores); cookie + attr constant.
```bash
perl -pi -e "
  s/'lemma:entry:/'thallo:entry:/g;  s/lemma:entry:/thallo:entry:/g;
  s/'lemma:type:/'thallo:type:/g;    s/lemma:type:/thallo:type:/g;
  s/'lemma:render:page/'thallo:render:page/g; s/lemma:render:page/thallo:render:page/g;
  s/'lemma:preview:working:/'thallo:preview:working:/g; s/lemma:preview:working:/thallo:preview:working:/g;
  s/lemma_seo:sitemap:/thallo:seo:sitemap:/g;
  s/'lemma_preview_session'/'thallo_preview_session'/g;
  s/'lemma_preview'/'thallo_preview'/g;
" $(git grep -rl "lemma:entry\|lemma:type\|lemma:render:page\|lemma:preview:working\|lemma_seo:sitemap\|lemma_preview" -- '*.php')
```
> `lemma_preview_session` must be replaced before `lemma_preview` — order above is correct (longer key first).
- [ ] **Step 2:** Search index default (Rule D — strip). `packages/thallo-search/config/search.php` and `SearchServiceProvider` fallback: `'lemma_content'` → `'content'`; `DocumentBuilder` doc comment likewise.
```bash
perl -pi -e "s/'lemma_content'/'content'/g" $(git grep -rl "'lemma_content'" -- 'packages/thallo-search/**' '*.php')
```
- [ ] **Step 3:** RBAC role slugs (Rule D — strip, Aegis-collision-checked). First list the framework's seeded slugs, then strip only where free; domain-qualify collisions.
```bash
grep -rhoE "'[a-z_]+'" vendor/glueful/aegis/migrations/003_SeedDefaultRoles.php | sort -u   # framework's reserved slugs
git grep -noE "lemma_[a-z_]+" -- database/dependent-migrations/004_* config/thallo.php tests/Integration/Http/LocaleRbacApiTest.php | sort -u
```
Then in `SeedRolesAndPermissions`, `config/thallo.php` (`roles` key), and `LocaleRbacApiTest`: strip `lemma_` from each slug where the result is free of the Aegis list; if a stripped slug (`admin`/`editor`/`viewer`) collides, domain-qualify to `content_admin`/`content_editor`/`content_viewer`. Record the chosen slugs.
```bash
# example after deciding (adjust per collision check):
perl -pi -e 's/\blemma_editor_de\b/editor_de/g; s/\blemma_editor_fr\b/editor_fr/g; s/\blemma_reader_global\b/reader_global/g; s/\blemma_admin\b/content_admin/g; s/\blemma_editor\b/content_editor/g; s/\blemma_viewer\b/content_viewer/g' \
  $(git grep -rl 'lemma_admin\|lemma_editor\|lemma_viewer\|lemma_reader_global' -- '*.php')
```
- [ ] **Step 4:** Any remaining brand literal `'Lemma'` (console name default, site name default) → `'Thallo'`.
```bash
git grep -n "'Lemma'\|\"Lemma\"" -- '*.php'   # review each; replace product brand with 'Thallo', leave none
```
- [ ] **Step 5:** Verify + test (fresh DB so re-seeded roles apply).
```bash
composer run test:reset-db && composer run test:migrate && composer test 2>&1 | tail -10
git grep -nI "lemma" -- '*.php' | grep -v "database/" | head   # expect: no output
```
- [ ] **Step 6:** Commit.
```bash
git commit -am "runtime: thallo cache/cookie namespaces, strip search index + role slugs (Aegis-checked), Thallo brand literal"
```

---

### Task 8: Frontend (admin SPA) — CSS, component, protocol, const

**Files:** `admin/src/**` (~10 files), the preview bundle bridge, `RenderController` protocol handshake.

- [ ] **Step 1:** CSS classes + component + persist-secret → `thallo`.
```bash
git grep -rl "lemma-" admin/src | xargs perl -pi -e 's/\blemma-/thallo-/g'
git mv admin/src/components/LemmaIcon.vue admin/src/components/ThalloIcon.vue
perl -pi -e 's/\bLemmaIcon\b/ThalloIcon/g' $(git grep -rl LemmaIcon admin/src)
perl -pi -e "s/'lemma-admin-dev'/'thallo-admin-dev'/g" $(git grep -rl "lemma-admin-dev" admin/src)
```
- [ ] **Step 2:** Canvas bridge protocol string `lemma:canvas-hello` → `thallo:canvas-hello` across ALL three surfaces (SPA + preview bundle + PHP), or the handshake breaks.
```bash
perl -pi -e "s/lemma:canvas-hello/thallo:canvas-hello/g" \
  admin/src/composables/useCanvasBridge.ts \
  packages/thallo-render/assets/preview/preview-bridge.js \
  $(git grep -rl 'lemma:canvas-hello' -- 'packages/thallo-render/**' 'app/**')
```
- [ ] **Step 3:** Verify frontend green + rebuild the admin bundle (it ships to `public/admin`).
```bash
cd admin && pnpm type-check && node_modules/.bin/oxlint src && node_modules/.bin/vitest run && pnpm build 2>&1 | tail -4
git grep -niI "lemma" -- 'admin/src/**' 'packages/thallo-render/assets/**'   # expect: no output
```
- [ ] **Step 4:** Commit (include the rebuilt `public/admin` bundle).
```bash
git commit -am "admin: thallo-* CSS, ThalloIcon, thallo:canvas-hello protocol, thallo-admin-dev; rebuild bundle"
```

---

### Task 9: Root composer identity, docs, final zero-trace sweep

**Files:** root `composer.json` (`name`, `description`), `README`, tracked `docs/**` prose, any stragglers.

- [ ] **Step 1:** Root `composer.json`: `"name": "glueful/thallo"`, description without "Lemma". Update tracked prose (leave `CHANGELOG.md` history + this plan file).
- [ ] **Step 2:** **Final acceptance — zero traces (bounded allowlist per Global Constraints):**
```bash
# Preferred path (no rename migration shipped):
git grep -niI "lemma" -- ':!docs/superpowers/plans/2026-07-06-debrand-lemma-identifiers.md' ':!CHANGELOG.md'
# If you kept the rename migration, also exclude it:
git grep -niI "lemma" -- ':!docs/superpowers/plans/2026-07-06-debrand-lemma-identifiers.md' ':!CHANGELOG.md' ':!database/migrations/*RenameLemma*'
```
Expected: **no output.** Any hit is a missed identifier — fix in the owning task's spirit and re-run. (If you also chose to scrub CHANGELOG history, drop its exclusion and confirm it's clean too.)
- [ ] **Step 3:** Full green gate (matches Task 0 baseline).
```bash
composer run test:reset-db && composer run test:migrate && composer test 2>&1 | tail -12
php glueful list >/dev/null && echo "CLI OK"
(cd admin && pnpm type-check && node_modules/.bin/vitest run && pnpm build) 2>&1 | tail -6
```
- [ ] **Step 4:** Commit.
```bash
git commit -am "de-brand: root composer glueful/thallo + docs; zero lemma traces in source"
```

---

## Rollback / safety

- Every task is an isolated commit → `git revert <sha>` backs any single layer out.
- Table renames are metadata-only (rows preserved); the rename migration's `down()` restores `lemma_*`.
- Pre-production: the fast path is `migrate:fresh` on a throwaway DB rather than the rename migration (Task 3 Step 4 is then optional).
- Riskiest coupling is autoload (Task 1) — done first, gated on `composer dump-autoload` + suite.
- The `lemma:canvas-hello` protocol (Task 8 Step 2) spans SPA + preview bundle + PHP; a partial rename silently breaks live preview — change all three together and smoke-test preview.

## Self-review notes

- **Coverage:** every category from the verified inventory has an owning task — namespaces/packages/composer (1), classes/providers/middleware/tests (2), the 7 tables + fidx prefix + test DB (3), config files/keys + env (4), CLI commands (5), route files + aliases (6), cache/cookie/attr + search index + role slugs + brand literal (7), frontend + protocol (8), root identity + docs + sweep (9). ✔
- **Rule split honored:** Rule D (strip) only on tables/env/test-DB/search-index; Rule C (thallo/capability) on all code identifiers. ✔
- **Corrections vs. the prior draft:** only 7 real `lemma_` tables (not ~30); `lemma_permission` is a middleware alias, not a table; collections already use `coll_` and are untouched; namespaces go to `Thallo\` (not `Glueful\Cms\`). ✔
- **Collision handling:** `permission` alias → `content_permission`; role slugs Aegis-checked before strip; `settings`/`regions` flagged but stripped per Rule D. ✔
- **No back-compat shims** (honors "zero traces"). ✔
- **Ordering:** autoload/namespaces first (unblocks references) → classes → DB → config/env → CLI → routes/aliases → runtime strings → frontend → sweep. ✔
