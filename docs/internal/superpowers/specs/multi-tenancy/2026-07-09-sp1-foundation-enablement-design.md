# Thallo Multi-Tenancy — SP1: Foundation & Enablement (Design)

**Date:** 2026-07-09
**Status:** Design — approved, pending spec review
**Track:** Multi-tenancy (this is sub-project 1 of 3)
**Home for the track:** [V1_DESIGN.md](../../../V1_DESIGN.md) §10

---

## 1. Context

Thallo is to support **multi-tenancy** in the union model — both **domain-resolved isolated SaaS tenants** and an **in-admin workspace switcher** for operators who belong to several tenants. Tenancy is **off by default**, turned on from a settings page, and turning it on requires installing the `glueful/tenancy` framework extension.

The `glueful/tenancy` extension already exists (shared-database, **row-level** tenancy). The neutral seam package `glueful/extension-contracts` already exposes `Tenancy\CurrentTenantResolver` and `Tenancy\TenantTableRegistry`. Thallo's job is **integration/adoption**, not building tenancy from scratch.

**Load-bearing fact:** Thallo has **no ORM models** — every repository issues raw `db()->table(...)` builder queries (`app/Models/` is empty). So the extension's ORM `BelongsToTenant` trait does **not** apply; Thallo's isolation rides on the framework's raw-query **table hook** + a **write-stamper** + the **guard** safety net.

**Tenant boundary (decided):** the **fully per-tenant model** — not just per-tenant *data*. Each tenant owns its own content types, block types, templates, menus/regions/settings, and the definitions' migration/provenance bookkeeping. This is a deliberate scope escalation over "shared schema + per-tenant data," chosen because Thallo is meant to host structurally-divergent client sites, not just workspaces over one schema.

## 2. Track decomposition

Multi-tenancy is delivered as three sub-projects, each with its own spec → plan → implementation cycle:

- **SP1 (this spec) — Foundation & enablement.** Backend + schema. The pack, the additive schema retrofit, scoping (hook + stamper + guard), the code→seed→sync spine, the enable/disable state machine, cache scoping, diagnostics. Mostly invisible until turned on.
- **SP2 — Tenant resolution & request wiring.** Domain/subdomain resolution for public delivery + rendered themes, the membership-based admin switcher (session-pinned current tenant), wiring the `tenant` middleware into route groups, named system/bypass paths.
- **SP3 — Tenant admin UI & membership/RBAC.** The settings-page toggle (driving SP1's enablement service), tenant CRUD, membership management, and how per-tenant membership composes with Aegis RBAC.

SP2 and SP3 depend on SP1.

## 3. SP1 scope & non-goals

**In scope:** everything in §2's SP1 line.

**Explicitly deferred:**
- Request→tenant **resolution** for public delivery, and the admin **switcher** → SP2.
- Settings-page **UI**, tenant **CRUD**, membership/RBAC **surfaces** → SP3 (SP1 ships only the backend `TenancyEnablement` contract they drive).
- `tenant:clone --from`, editable **blueprint** tenants, agency **starter packs** → post-SP1.
- Media/asset seeding → out of seed v1 (starter homepage is text/layout only).
- Destructive tenancy **rollback** (drop `tenant_uuid`, restore narrow uniques) → a separate explicit command, never part of routine disable.

## 4. Decisions ledger (pinned)

| # | Decision |
|---|---|
| D1 | Tenancy model = union (SaaS isolation + workspace switching). |
| D2 | Tenant boundary = **fully per-tenant model** (definitions + data are tenant-owned). |
| D3 | Seed source of truth = **code-level starter definitions**. Global definition tables hold no source rows. |
| D4 | Spine = **code source → seed-on-create → additive sync-on-upgrade**. |
| D5 | Enable = **in-admin guided flow, confirm-gated**, never silent; same service backs the CLI. |
| D6 | Scoping = framework **table hook for reads** + **explicit stamp on writes** + **guard as dev-throw oracle**. |
| D7 | `tenant_uuid` = `string(12)`, `NOT NULL` post-backfill, **indexed, no cross-package FK**. |
| D8 | `settings` splits into a **system-global channel** (unscoped) + **per-tenant** site settings. |
| D9 | Disable = **runtime scoping off, schema stays widened** (compatibility mode); destructive rollback is separate. |
| D10 | SP1 is **cross-repo**: contracts (`TenantContextRunner`) + tenancy binding + Thallo pack. Unreleased versions pinned at release. |

## 5. Architecture

### 5.1 The `thallo-tenancy` pack

`packages/thallo-tenancy/` — a standard Thallo capability pack registering capability `thallo.tenancy`. It **soft-depends** on `glueful/tenancy`: it codes **only** against `glueful/extension-contracts` seams, never against `Glueful\Extensions\Tenancy\*` concretes. Every integration point is guarded by the presence of the contract binding in the container (present only when the extension is installed + enabled).

Pack responsibilities (SP1): table registration, the write-stamper, the seed/sync engine, the `TenancyEnablement` service + diagnostics, and the cache-segment helper — plus the code-source starter definitions.

**Compound boot gate.** Table registration + hook reliance activate **only when both**: (a) the tenancy contract binding is present, **and** (b) `config('thallo.tenancy.enabled') === true`. A merely-installed-but-disabled extension never silently scopes a single-tenant site. Disable is boot-gated: with the flag false, the next boot registers nothing.

### 5.2 Contract addition — `TenantContextRunner`

`glueful/extension-contracts` gains `Tenancy/TenantContextRunner`:

```php
namespace Glueful\Extensions\Contracts\Tenancy;

interface TenantContextRunner
{
    /** Run $fn with the given tenant as the current context. */
    public function runAsTenant(string $tenantUuid, callable $fn): mixed;

    /** Run $fn in an explicit system/bypass context (no tenant scoping). */
    public function runAsSystem(callable $fn): mixed;

    /**
     * Run $fn once per tenant, deterministically ordered (tenant creation date,
     * then name, then uuid as final tie-breaker so ordering is stable even when
     * timestamps or names collide). Fail-fast: on the first failure, stop and surface the offending
     * tenant UUID. `--continue-on-error` is a later CLI option, never the contract default.
     *
     * @param callable(string $tenantUuid): void $fn
     */
    public function forEachTenant(callable $fn): void;
}
```

`glueful/tenancy` binds it (delegating to its concrete `Bypass\Tenancy`). The pack resolves **only** this interface — keeping it contract-pure and letting CLI seed/sync and background work establish context without referencing extension concretes.

### 5.3 Scoping mechanism (no ORM)

The extension provides, when enabled and tables are registered:
- A **`Connection` table hook** that injects `WHERE {table}.tenant_uuid = {current}` into every **builder** query (SELECT/UPDATE/DELETE) against a registered tenant table, when a current tenant is in context and no bypass is active. Thallo's repositories are all builder queries → **reads and scoped writes are scoped transparently, no per-query edits**.
- A **`TenantQueryGuard`** pre-execution interceptor: **dev/test throws**, prod logs — a conservative safety net that flags any tenant-table query the hook didn't scope. It catches *unscoped access* and *wrong-tenant writes*; it does **not** fill a *missing* `tenant_uuid`.

**Writes are Thallo's responsibility, via a new framework primitive.** The hook adds a `WHERE` (meaningless for INSERT); the guard only rejects a *wrong* uuid, never a *missing* one. And Thallo has **no shared base repository** — every one of ~20 repositories calls `db()->table(...)->insert(...)` independently, and the query builder exposes no insert-payload default mechanism. So stamping can't hook one app-level method. Resolution (decided against per-repo edits and against a fragile `Connection` subclass):

- **Framework insert-hook primitive** — `glueful/framework` gains a payload-mutating insert hook on the query builder's insert path (`insert`/`insertBatch`/`upsert`), the write-side counterpart to the existing `Connection::addTableHook` read hook. It is opt-in (fires only if a hook is registered) with near-zero blast radius when unused.
- **Tenancy registers the stamper** — the `glueful/tenancy` extension registers an insert hook that, when a current tenant is in context and no bypass is active, fills `tenant_uuid` on inserts into registered tenant-owned tables. Every no-ORM app on the framework benefits, not just Thallo.
- **Fail-closed:** with a live request context, no bypass, a tenant-owned table, but no resolved tenant, it **throws** — never silently writes an unscoped row. It also **throws on a supplied `tenant_uuid` that differs from the current tenant** (a cross-tenant write), since the hook holds the payload directly. These throw in **all** environments (an unscoped/cross-tenant write is corruption — a deliberate divergence from the read guard's prod-never-throws posture).
- **The one documented no-op exception:** when there is **no** `CurrentContext` at all (framework migrations / boot / CLI without a `runAsTenant` wrapper), the stamper is a **no-op** — it must not throw, or it would break legitimate migrations. This is safe because, post-retrofit, `tenant_uuid` is `NOT NULL`: an unstamped *application* write fails loudly at the DB rather than persisting an unscoped row. **Application writes must always carry context** (the required `tenant` middleware in a request; `runAsTenant`/`runAsSystem` for seeders/jobs/CLI) and must never rely on this branch.
- **Default-tenant compatibility mode** (state `disabled_widened`, §9): stamp the **default tenant UUID** on writes; reads stay unscoped (single tenant, no collision).

**The raw-PDO blind spot.** A few tenant-owned tables are written via raw `getPDO()->prepare()->execute()` that bypasses **both** the builder insert hook **and** the `QueryExecutor` guard: `seo_meta` (`SeoMetaRepository`), `entry_versions` (`VersionPruner`), `entry_schedules` (`ScheduleRepository`). These are **invisible to the guard-as-oracle** and must be handled explicitly — converted to the builder or given an explicit `tenant_uuid` stamp + tenant predicate. SP1 ships a **regression lint** (a test that greps for `getPDO()` writes/reads against owned tables) so new raw-PDO blind spots can't creep in.

**Verified write-path inventory (deliverable).** SP1 produces an explicit inventory of every write to an owned table — builder single inserts, row-at-a-time loops, hand-rolled read-then-write upserts, and the enumerated raw-PDO sites. Builder writes are covered by the framework hook; migrations/seeders run under `runAsTenant`/`runAsSystem` (context-scoped, not blindly stamped); raw-PDO sites get explicit handling per above.

### 5.4 Fail-closed reads at the edge

When `thallo.tenancy` is `on`, every route serving tenant-owned data carries the `tenant` middleware in **required** mode — a request with no resolvable tenant is a 404/403 **before** it reaches a repository (the resolution pipeline already fails closed on `required`). We do **not** lean on the prod-logging guard for this. Exempt paths are explicitly named: system/diagnostics/CLI and the enablement flow itself. (Route wiring proper is SP2; SP1 defines the required-mode contract and the exempt-path list.)

## 6. The `ThalloTenantTables` registry

A **single** source of truth (one class) consumed by table registration, the schema retrofit, diagnostics, and tests. **No table list is hand-maintained anywhere else.** Each entry carries operation metadata, not just a name:

- `table` — name.
- `tenant_column` — `tenant_uuid` (uniform, but explicit).
- `kind` — `definition` | `instance`.
- `widened_uniques` — list of widened unique specs (old → `(tenant_uuid, …)`).
- `indexes` — regular indexes to add (at least `tenant_uuid`).
- `special_backfill` — any non-default backfill handling.

**Owned tables (core):** `content_types`, `entries`, `entry_drafts`, `entry_versions`, `entry_publications`, `entry_routes`, `entry_references`, `published_entry_references`, `entry_redirects`, `entry_schema_migrations`, `entry_schedules`, `block_types`, `block_type_migrations`, `regions`, `form_submissions`, and the per-tenant **site** subset of `settings` (see §7.3).

**Owned tables (packs, when installed):** `navigation_menus`, `navigation_items`, `render_templates`, `render_template_versions`, `seo_meta`, `analytics_facts`, `analytics_daily`, `analytics_active_actors`, `workflow_review_states`, `workflow_transitions`.

**Added by this pack:** `starter_provenance` (tenant-owned, `kind = instance`; §8.2).

**Explicitly EXCLUDED from tenancy in SP1 — collections.** `collection_definitions`, `collection_schema_changes`, and the **dynamic collection data tables** are **not** in the registry. `collection_definitions.table_name` names a *physical* table and is globally unique; two tenants creating a "products" collection would collide at the physical-table level — a sub-design (per-tenant physical-table naming + migration lifecycle + its own cache/diagnostics/seed-sync) too large to fold into the foundation. Consequences, enforced by SP1:
- Collections stay single-tenant/global.
- Diagnostics + the enable **preflight** report "collections tenancy unsupported in SP1."
- **Enabling tenancy BLOCKS if any collection definitions exist** — shipping silent global collection data inside a tenant-enabled system is too dangerous to allow behind a warning.
- Collections tenancy is a dedicated follow-up (SP4): per-tenant physical-table naming and migration lifecycle.

> **Verified against migrations (planning):** exact existing uniques/indexes/PKs are captured in §7.2 — including that `regions` (PK `slug`), `settings` (PK `key`), and `entry_redirects` (inline `->unique()`) require **primary-key/inline-unique reconstruction** (table rebuild), not a simple column add.

## 7. Schema retrofit

### 7.1 Extension-owned tables (not Thallo's)

`glueful/tenancy` ships `tenants` (`uuid` string(12) unique, `slug` unique, `name`, `status` default `active`, `settings` JSON, timestamps, `deleted_at`) and `tenant_memberships` (`tenant_uuid` → tenants cascade, `user_uuid` indexed, `status`) via its own migrations (priority `DEFAULT-50`). The enable flow guarantees they exist + are migrated before the backfill. The **default tenant UUID uses the extension's own generator** (via `tenant:create` / the extension model), never a Thallo-invented one.

### 7.2 The `tenant_uuid` column + widened uniques

Every registry table gains `tenant_uuid` — `string(12)`, `NOT NULL` after backfill, **indexed**, **no cross-package FK** (index-only; referential integrity comes from the resolution pipeline + fail-closed stamper; tenant lifecycle is suspend/soft-delete, so there's no cascade to enforce).

Unique constraints that assumed global uniqueness become `(tenant_uuid, …existing…)` so tenants diverge freely. Widenings, verified against the actual migrations (the `uuid` nano-id uniques stay **global** — they aren't business keys):

| Table | Old unique (constraint name) | Widened | Rebuild? |
|---|---|---|---|
| `entry_routes` | `(content_type_uuid, locale, slug)` `uniq_route_type_locale_slug` | `(tenant_uuid, content_type_uuid, locale, slug)` | no |
| `entry_redirects` | `(content_type_uuid, locale, source_slug)` `uniq_redirect_type_locale_source` **+ inline** `uuid` unique | `(tenant_uuid, …source_slug)`; keep `uuid` global | **yes** (inline-unique on `uuid` forces rebuild) |
| `content_types` | `(slug)` | `(tenant_uuid, slug)` | no |
| `block_types` | `(slug)` `uniq_block_type_slug` | `(tenant_uuid, slug)` | no |
| `render_templates` | `(theme, path)` `uniq_render_template_theme_path` | `(tenant_uuid, theme, path)` | no |
| `navigation_menus` | `(slug)` `uniq_navigation_menu_slug` | `(tenant_uuid, slug)` | no |
| `seo_meta` | `(entry_uuid, locale)` | `(tenant_uuid, entry_uuid, locale)` | no |
| `regions` | **PK = `slug`** (no surrogate id) | PK → `(tenant_uuid, slug)` | **yes** (PK reconstruction) |
| `settings` (site subset) | **PK = `key`** (no surrogate id) | PK → `(tenant_uuid, key)` | **yes** (PK reconstruction) |

Most tables carry `id` (bigint) + a global `uuid` unique, so adding `tenant_uuid` + a widened business-key unique is additive. The three **rebuild** cases (`regions`, `settings`, `entry_redirects`) have no surrogate id or use an inline `->unique()`, so SchemaBuilder can only change them via a copy-table rebuild — the retrofit engine handles these with a dedicated rebuild path.

Postgres NULL-distinct is not a problem: backfill makes the column `NOT NULL` **before** the widened unique is added, so no NULL rows exist.

### 7.3 The `settings` split

Under the per-tenant model `settings` becomes tenant-owned (per-tenant site settings). But the **tenancy enablement state cannot live in a tenant-scoped table** — it must be readable *before* any tenant resolves (chicken-and-egg). So SP1 keeps a small **system-global channel** — outside `ThalloTenantTables`, never scoped — holding: `thallo.tenancy.enabled`, `schema_state` (`none | widened`), the **default-tenant pointer**, and **enable-job progress** (§9). This channel is a dedicated unscoped store (table or file), chosen so it survives partial schema changes and process restarts; it is **not** the soon-to-be-scoped `settings` table.

**Explicit key classification (verified against `GeneralSettings::DEFS` + `SetupService`).** The current `settings` table is a flat key/value store (PK `key`) mixing both concerns. Classification:
- **SYSTEM** (move to the system-global channel, never scoped): `installed`, `scheduler_enabled`, `webhooks_enabled`, `admin_url` (per-instance infra), plus the new tenancy keys (`thallo.tenancy.enabled`, `schema_state`, default-tenant pointer, enable-job progress).
- **SITE** (become per-tenant): `site_name`, `site_preview_url`, `default_locale`, `homepage_entry`, `site_logo`, `site_logo_dark`, `site_favicon`, `theme`, `theme_accent`, `theme_neutral`, `listing_types`, `default_per_page`, `max_per_page`, `cache_ttl`.

**Unknown/new keys default to site-scoped** unless declared system, so a stray key can't silently bleed cross-tenant. `SettingsStore`/`GeneralSettings` gain a system-key allowlist; system reads/writes route to the system channel, everything else to the tenant-scoped `settings` table.

### 7.4 The additive backfill (the "first tenant")

The default tenant is **never seeded** — it *inherits* the pre-existing single-tenant install's rows. Per-table sequence:

1. Add `tenant_uuid` **nullable**.
2. Create the **default tenant** (existing site → "Tenant 1"; name/slug from site settings or the operator's confirm-step input, validated against the extension's slug rules) and write the enabling operator as its first `tenant_memberships` row (owner). Then `UPDATE … SET tenant_uuid = {defaultUuid}` across all rows.
3. **Prove uniqueness:** run duplicate checks on each target widened key. If duplicates exist (from resumable/partial runs or pre-existing bad data), **fail with a table/key report** before any destructive constraint change.
4. `ALTER … NOT NULL`; drop old uniques; add widened uniques + the `tenant_uuid` index.

**Retrofit is an enable-time operation, not an ambient migration.** It must never run on a normal `migrate:run` for single-tenant installs. It lives in an **idempotent, resumable** operation (each step checks current state) invoked by `thallo:tenancy:enable` — the same background job the confirm-gated in-admin flow triggers. The pack ships **no ambient migration** that adds `tenant_uuid`.

## 8. Seed / sync spine

### 8.1 Code source of truth

Canonical starter definitions already live **in code**, in two places SP1 reuses rather than reinvents:
- **`StarterBlockTypes::definitions()`** — the existing single source for block types (data-only; the reseed migrations `020`/`021` and `thallo:blocks:seed` all read it). SP1 keeps it as the block-type source.
- **`SetupService::install()`** — the existing single first-run seeder (web + CLI, one transaction) that creates the starter **content types** (`pages`/`category`/`post`), **site settings**, and **regions** (`header`/`footer`). SP1 **refactors its content-seeding core into a reusable unit** the per-tenant seeder invokes under `runAsTenant`, so first-run install and per-tenant seed share one code path (DRY/dogfood).

**Reality of the seed surface (corrected):** today's install seeds **no** homepage entry, **no** menu rows (the header region references menu `main` by name), and **no** starter DB templates (templates are theme *files*, shared/global; per-tenant `render_templates` are DB *overrides* that start empty). So the SP1 per-tenant seed surface is: **content types, block types, site settings, regions** — matching what actually exists. Homepage/menu seeding are **optional additions**, not assumed; if added, the homepage gets a deterministic `source_id` and collision-skip.

Starters carry no version today, so SP1 adds provenance metadata at the *source* level: a **stable `source_id`** (block types use their `slug`; content types/regions use their handle/slug) and a **computed `fingerprint`** (hash of the normalized definition), independent of the row's mutable handle so a rename is distinguishable from delete+add.

### 8.2 Provenance

A tenant-owned `starter_provenance` table keyed by `{tenant_uuid, definition_kind, definition_key}` records `source_id` + last-synced `fingerprint` + `state` (`applied | customized | orphaned_source`). Sync asks it directly — "did this row come from source X at fingerprint Y?" — rather than inferring from migration logs.

### 8.3 Seed-on-create

Tenant creation invokes a `TenantSeeder` wrapped in `runAsTenant($newUuid, …)`. Context is established, so every seeded row flows through the fail-closed stamper and lands correctly scoped. The seeder is **idempotent** (skips already-present definitions by `source_id`), so a retried/resumed create is safe.

**Seed surface (SP1):** content types, block types, site settings, regions — via the refactored `SetupService` core + `StarterBlockTypes`. Homepage/menu seeding are optional follow-ons.

**Seed order** (render dependencies first): content types → block types → **site settings → regions** → (optional: menus → homepage).

**Homepage collision (if homepage seeding is added):** the starter homepage has a deterministic `source_id`; seed creates it **only when no homepage/default route exists**, else skips.

### 8.4 Sync-on-upgrade (additive, idempotent, never clobbers divergence)

Applies to **all** tenants (including the default) when Thallo ships new/changed starters. Per definition:

- **Absent** in the tenant → **add**.
- Present and **unchanged from recorded fingerprint** → **update** to the new source.
- Present but **tenant-diverged** (differs from recorded fingerprint) → **never overwrite**; set `state = customized` and **report** "skipped — customized" for manual reconciliation.

**Deletion semantics:** a starter removed from code **never deletes** tenant rows; sync marks provenance `orphaned_source` and reports. Destructive removal is a separate explicit operator action.

**Rename semantics:** rename = update the handle/slug on the row whose `source_id` matches; fingerprint detects content change. (Stable `source_id` is why rename ≠ delete+add.)

Definition **schema** changes are applied **additively** (new fields, new block types) — never destructive drops — matching Thallo's existing destructive-schema-migration discipline.

### 8.5 Commands

All context-scoped via the runner; `--all` uses `forEachTenant` (deterministic order, fail-fast, reports the offending tenant):

- `thallo:tenant:seed <tenant>` — (re)materialize a tenant's starter model; mainly a repair/backfill tool (create seeds automatically).
- `thallo:tenant:blocks:sync [<tenant>|--all]` — reconcile block types from source.
- `thallo:tenant:sync [<tenant>|--all]` — umbrella: runs all reconcilers in dependency order (content types → block types → regions).

(A `templates:seed` command is deferred with per-tenant template seeding, since templates are shared theme files in SP1.)

## 9. Enable / disable state machine

**One shared service, two front doors.** The SP3 settings UI and the `thallo:tenancy:enable` CLI drive the *same* `TenancyEnablement` service; the UI polls status. All progress lives in the **system-global channel** (§7.3).

**Runtime posture is a pair:** `enabled` (scoping on/off) + `schema_state` (`none | widened`). `off` (never enabled, no columns) is **distinct** from `disabled_widened` (columns exist, scoping off). Code and tests key off the pair — never conflate them. **`off` is an initial/destructive-rollback-only state:** it exists *before* first enable, or *after* the separate destructive-rollback command. Routine disable does **not** return to `off` — it lands in `disabled_widened`.

**States** (resumable from whichever is current):

```
off → installing → enabling-extension → migrating-extension → awaiting-confirm
    → retrofitting → on
                 ↳ (any step) → failed   (resumable/retryable, reason recorded)

on → disabled_widened   (routine disable: scoping off, schema stays widened)
disabled_widened → on    (re-enable: scoping back on)
disabled_widened → off   (destructive rollback ONLY — separate explicit command)
```

`off` is initial (pre-first-enable) or the terminus of a destructive rollback. Routine disable never reaches it.

**The runtime scoping gate** is the persisted **system-channel `enabled` flag** (read at boot), *not* a `config()` value — the state machine is richer than a boolean and must be resumable. The capability `thallo.tenancy` (in `config/thallo.php` → `capabilities`) only gates the pack's tenant-management routes; the enablement endpoints stay reachable while tenancy is off (that's how you turn it on).

**Steps:**
1. `off` — single-tenant, no scoping, no columns, nothing registered.
2. `installing` — if `glueful/tenancy` absent, install via `ExtensionInstaller::install('glueful/tenancy')`. **This is synchronous/blocking** (composer runs in the foreground; it is *not* a detached job) and leaves the package **installed-but-disabled**; the request returns when composer finishes. Requires `extensions.install.enabled` (off in production by default → prod operators use CLI) and the package being in the Packagist `type=glueful-extension` catalog under the `glueful/` prefix. If in-admin install is unavailable, the flow surfaces the CLI fallback.
3. `enabling-extension` — activate the extension by writing its provider FQCN into `config/extensions.php` (`ExtensionStateWriter` + `ExtensionManager::writeCacheNow()`), so on the **next request** its ServiceProvider boots and binds `CurrentTenantResolver` / `TenantTableRegistry` / `TenantContextRunner` + installs the read hook and registers the insert-stamper.
4. `migrating-extension` — run the extension's migrations so `tenants` + `tenant_memberships` exist.
5. `awaiting-confirm` — preflight: extension healthy; no conflicting pending migrations; **collections check — BLOCK if any `collection_definitions` rows exist** (collections tenancy is unsupported in SP1, §6); a dry-run table/row count; advisory backup reminder. Then the **hard confirm gate**: impact summary (N tables), **first-tenant name/slug input** (validated against the extension's slug rules **here**, before any Thallo schema change), and the "reversible only while single-tenant" warning. Nothing destructive yet; `cancel` allowed up to here.
6. `retrofitting` — the resumable retrofit (§7.4): add columns → create default tenant + owner membership → backfill → prove uniqueness → widen constraints. Progress streamed.
7. `on` — flip `enabled = true`, set `schema_state = widened`, set the default-tenant pointer; **rebuild** route/provider/render caches; **purge** render/query/template/media caches. Status reports `on` **only after a health probe confirms this app instance actually enforces the middleware + registration** (otherwise "enabled, reloading").

**Global lock / CAS.** `begin`, `confirm`, `retry`, `disable` all acquire the same system-global lock and **compare-and-swap on the expected current state** before transitioning. Concurrent UI+CLI or two admins cannot run competing jobs; a stale action is rejected.

**Failure & resume.** Any step can fail into `failed` with the reason recorded; `retry` re-enters from the recorded state. The retrofit is idempotent, so a mid-retrofit failure resumes without double-applying.

**Disable path.** Permitted **only** when **all** hold: tenant count == 1; no `starter_provenance` row is `customized` or `orphaned_source`; and no tenant-authored definition row **lacks** provenance (tenant-authored-without-provenance **counts as divergence** → blocks disable). Only a pristine single-tenant all-starter install disables cleanly. Disable → `disabled_widened`: scoping off, the write-stamper enters **default-tenant compatibility mode**, schema stays widened. Any `on ↔ disabled_widened` transition **purges** render/query/template/media caches. Destructive rollback (drop `tenant_uuid`, restore narrow uniques, return to `off`) is a separate explicit command.

**Contract SP3 consumes:** a **status** read (state pair, progress %, blockers) and **actions** — `begin`, `confirm` (carries first-tenant name/slug), `retry`, `cancel` (pre-retrofit only), `disable`. Each maps to a `TenancyEnablement` method; the CLI calls the identical methods.

**Guards.** The whole enablement surface is a **named system path**, exempt from the tenant-required middleware; every action requires a system/super-admin permission.

## 10. Cache-key scoping

One `tenantCacheSegment()` helper is the single source of the convention:

- Scoping `on` **and** a tenant resolves → segment `tenant:{uuid}`.
- Scoping `on` **and no** tenant resolves → **throw** (fail closed — never a shared-key fallback).
- `off` or `disabled_widened` (single tenant) → **no segment** (existing keys unchanged, zero cache migration).

**Surfaces threaded through the helper:** render page cache, render **error** cache, DB-template **compile** cache, navigation cache, SEO/sitemap cache, media/blob URL cache, and any repository/query caches. The framework **route cache stays global** (framework routes aren't tenant content; per-tenant `entry_routes` resolve at runtime against scoped data).

**Purge on transition:** every `on ↔ disabled_widened` transition (and the destructive rollback to `off`) purges render/query/template/media caches so no stale segmented-or-unsegmented entries survive.

## 11. Diagnostics

`thallo:tenancy:diagnose` (complementing the extension's `tenant:diagnose`), driven by the single `ThalloTenantTables` registry, asserts:
- Enablement-state coherence (`enabled` / `schema_state` consistent with reality).
- Every owned table registered, has `tenant_uuid NOT NULL`, and carries widened uniques.
- A probe query raises no guard warning.
- `starter_provenance` integrity (no dangling rows; `orphaned_source` report).
- Cache-segment wiring.
- **Collections guard:** if the collections pack is installed/enabled, report "collections tenancy unsupported in SP1" (and, when tenancy is `on`, that collection data remains global).
- **Raw-PDO lint:** no `getPDO()` read/write against an owned table outside the sanctioned, explicitly-scoped sites.

It is a named system path.

## 12. Testing strategy

- **Guard-as-oracle (reads):** run Thallo's *existing* suite with tenancy `on` and the guard in **throw** mode. Any unscoped **builder** read of a tenant table throws — a green suite proves the hook covers every builder read path. **Caveat (raw-PDO blind spot):** raw `getPDO()` reads bypass the `QueryExecutor` guard, so the oracle does **not** cover them — those sites are handled explicitly (§5.3) and protected by the raw-PDO lint, not the oracle. **Runs with ≥2 tenants:** the isolation harness seeds tenant A **and** tenant B rows across both definition and instance tables before asserting invisibility (single-tenant data can coincidentally match a missing predicate).
- **Write-path inventory (writes):** a test enumerates every write to an owned table and asserts builder inserts flow through the framework insert-hook stamper; an attempted unstamped write with no context **fails closed**. The enumerated raw-PDO write sites (`seo_meta`, `entry_versions`, `entry_schedules`) get direct isolation tests.
- **Framework insert-hook (unit):** the new primitive fires on `insert`/`insertBatch`/`upsert`, mutates the payload, and no-ops when no hook is registered.
- **Raw-PDO lint:** a test greps the codebase for `getPDO()` reads/writes against owned tables outside the sanctioned scoped sites, failing on new blind spots.
- **Cross-tenant isolation (money test):** under `runAsTenant(A)`, tenant B's rows are invisible to reads and unwritable — for both instance *and* definition tables.
- **Retrofit:** idempotency (run twice), resume-after-interrupt, uniqueness-preflight failure reporting, backfill correctness, default-tenant + owner-membership creation.
- **Seed/sync:** add / update-if-unchanged / skip-if-diverged classification; deletion→`orphaned_source`; rename via `source_id`; homepage collision-skip; dependency order.
- **Enable machine:** every transition; CAS-lock rejection of concurrent actions; compat-mode writes under `disabled_widened`; disable-divergence blocking; `off` vs `disabled_widened` distinction; cache-segment on/off; cache purge on transition; health-probe gating of the `on` status.

## 13. Cross-repo deliverables & release pinning

**Four repos** (sequenced contract → framework → tenancy → Thallo):

1. `glueful/extension-contracts` — add `Tenancy/TenantContextRunner`.
2. `glueful/framework` — add the **payload-mutating insert-hook primitive** on the query builder's `insert`/`insertBatch`/`upsert` path (write-side counterpart to `Connection::addTableHook`).
3. `glueful/tenancy` — bind `TenantContextRunner` (via a new `Bridge\ContractTenantRunner` delegating to the static `Bypass\Tenancy` methods, with its **own** deterministic-ordered + fail-fast `forEachTenant`, since the extension's `Scheduling\ForEachTenant` is neither), and register the insert-stamper hook in `boot()`.
4. Thallo — the `thallo-tenancy` pack (`ThalloTenantTables` registry, stamper wiring + raw-PDO fixes, seed/sync engine reusing a refactored `SetupService` core, `TenancyEnablement` service, enable-time retrofit engine incl. the rebuild path, `starter_provenance`, cache-segment helper, diagnostics, CLI commands) + the system-global channel + the `settings` split + the code-source refactor of the reseed migrations + root `composer.json` path-repo/require + `config/extensions.php` activation.

Framework floors: contracts requires `glueful/framework ^1.65.3`; Thallo pins `^1.66.3`; the insert-hook primitive lands in a new framework release that Thallo (and the contracts/tenancy versions carrying the runner + stamper) pin at release. Consistent with how `glueful/tenancy` already tracks unreleased framework seams.

## 14. Planning actions — RESOLVED during exploration

- ✅ Unique constraints/indexes/PKs confirmed against migrations (§7.2); three rebuild cases identified (`regions`, `settings`, `entry_redirects`).
- ✅ `settings` keys enumerated + classified (§7.3).
- ✅ Write path resolved (§5.3): **no** shared base repository exists → framework insert-hook primitive + explicit raw-PDO handling; inventory captured.
- Versions to pin: confirm the exact framework release number carrying the insert-hook, plus the contracts/tenancy versions, at release time.
- System-global channel implementation (dedicated unscoped table vs file): the plan defaults to a **dedicated unscoped table** created by an ambient pack migration (readable before tenant resolution, survives restarts); confirm against framework conventions in Task 0.
