# Ecommerce Content Integration — Slice 1: Commerce Adoption + Content Linkage Foundation

**Track:** Ecommerce content integration (OUTSTANDING.md §A) — slice 1 of 4.
**Date:** 2026-07-20. **Status:** design.

The track decomposes into four sub-projects, each its own spec → plan → build:

1. **Adoption + content linkage foundation** (this spec) — install `glueful/commerce`, the
   tenancy/adoption/purge integration, and the canonical product→entry enrichment seam.
2. **Storefront rendering** — shop blocks + rendered shop routes (grid/detail/cart/checkout),
   backed directly by Commerce APIs; Thallo enrichment optional, Commerce availability
   authoritative.
3. **Admin SPA commerce area** — product/variant/inventory/order/discount/shipping/tax/report
   surfaces reusing Commerce's existing admin JSON API; product-content linking + preview;
   workspace selection drives Commerce tenant context.
4. **WooCommerce importer** — in `thallo-importers`, importing through Commerce and linkage
   services (products first, optional enrichment entries second; idempotent; never direct
   table writes).

## 1. Decisions (pinned)

- **Catalog↔content relationship: Commerce-authoritative + content enrichment.** Commerce owns
  commercial truth (products, variants, price, stock, cart, checkout, orders). Thallo adds an
  optional editorial layer: a Thallo entry (blocks, localized fields) linked to a Commerce
  product. Slice 2's product-detail route resolves the enrichment entry in O(1); a product
  with no linked entry still renders from Commerce data alone.
- **Packaging: new pack `packages/thallo-commerce`.** Pack conventions apply (flat
  `migrations/` at pack root; `loadMigrationsFrom()` in `boot()` outside the enable gate).
- **Dependency direction:** `thallo-commerce` **requires** `glueful/commerce`; the Thallo root
  requires `thallo-commerce`, which brings Commerce transitively. "Soft detection" therefore
  means *Commerce package installed but its provider inactive*, never *Composer package absent*.
  Provider order: **tenancy enforcement → Commerce → thallo-commerce**.
- **Tenancy posture: per-workspace shops.** Commerce is installed once at the application
  level; its **data, activation posture, and shop experience** are per workspace. Commerce
  tenant resolution tracks Thallo's three tenancy modes (§4). Sentinel `''` before any
  widening/enforcement, exactly like Thallo content.
- **Marketplace: present but disabled.** `commerce.marketplace.enabled = false` in Thallo v1.
  No forking or removal of marketplace-aware Commerce code. Diagnostics flag an enabled
  marketplace as an unsupported configuration in Thallo v1.
- **Linkage: canonical link table + optional starter content type** (§5). One canonical
  enrichment entry per product; any suitable content type may be linked; localization lives
  inside the linked entry.

## 2. Release surface

Three codebases change. Commerce 1.2.x is **published** — its additions are a real additive
minor release (no shipped-migration edits, immutable changelog sections). Thallo and its
packages are unpublished (fold posture applies there).

| Codebase | Additions |
| --- | --- |
| `glueful/commerce` (new minor) | `CommerceTenantResolution` seam (§4.2); `ProductDeleted` after-commit event (§6.1); `CatalogReader` read contract (§7); `CommerceTenantPurge` service (§8.2). All additive; no schema changes. |
| Thallo core + `thallo-tenancy` / `thallo-contracts` | Tenant-adoption contributor seam (§8.1); starter-content contributor seam (§9); no behavior change for installs without contributors. |
| `packages/thallo-commerce` (new) | Provider, config, link-table migration, `ProductLinkService`, lifecycle listeners, purge handler, reconcile command, diagnostics, starter Product Page kind, admin JSON API. |

## 3. Pack skeleton (`packages/thallo-commerce`)

- `composer.json` requires `glueful/thallo-contracts`, `glueful/thallo-tenancy`, and
  **`glueful/commerce`** (hard dependency, per the pinned direction).
- `CommerceIntegrationServiceProvider` registered in `config/extensions.php` **after**
  `TenancyServiceProvider` and after `CommerceServiceProvider` (provider order: tenancy
  enforcement → Commerce → thallo-commerce).
- The provider registers a standard **`thallo.commerce` capability** with
  `CapabilityRegistry`; `CapabilityRegistry::isEnabled('thallo.commerce')` gates only the
  user-facing routes and starter contribution. There is no parallel `thallo_commerce.enabled`
  switch. **Outside** the capability gate, mirroring pack conventions:
  `loadMigrationsFrom()`, tenant-table registration (§8.1), lifecycle-cleanup listeners
  (§6.2), purge-handler registration (§8.2), and diagnostics remain active — data created
  before a switch-off must remain coherent and purgeable.
- **Inactive-Commerce inertness:** if the Commerce package is installed but its provider is
  inactive (so its contracts/services are unavailable), user-facing Commerce routes and the
  Commerce-side lifecycle listener stay inert and diagnostics report the state. Pack-owned
  maintenance remains available where its dependencies exist; purge follows the fail-closed
  rule in §8.2. Pack removal (Composer-level) leaves Thallo booting cleanly: nothing in
  Thallo core references pack classes.

## 4. Tenancy: three modes, one Commerce-local seam

### 4.1 The three modes (verified against `SystemFlags` / `SingleStoreTenant`)

Thallo's persisted tenancy state (`thallo-tenancy` `SystemFlags`: `tenancyEnabled()`,
`schemaState(): 'none'|'widened'`, `enforcementActive()`, `defaultTenantUuid()`;
`SingleStoreTenant::defaultUuidOrNull()`) yields three Commerce resolution modes:

| Mode | Thallo state | Commerce tenant |
| --- | --- | --- |
| Clean single-store | schema `none` | `''` (sentinel) |
| `disabled_widened` | schema `widened`, enforcement off | Thallo's persisted default tenant UUID |
| Enforcement active | `enforcementActive()` | request-resolved tenant (`CurrentTenantResolver`) |

During RELOADING/FINALIZING the Thallo write barrier blocks requests; the resolver seam must
**not** latch a permanent sentinel resolver merely because `enforcementActive()` is still
false mid-transition — resolution is evaluated per call against live flags, not captured at
container build.

### 4.2 The Commerce seam: `CommerceTenantResolution`

Commerce's current wiring is binary and selected once
(`CommerceServiceProvider::makeTenantResolver()`: `commerce.tenancy.enabled=false` →
`SentinelTenantResolver`; `true` → the bound shared `CurrentTenantResolver` or a hard throw).
That cannot express `disabled_widened`. Commerce gains a **Commerce-local** seam:

```php
namespace Glueful\Extensions\Commerce\Tenancy;

interface CommerceTenantResolution
{
    /** The tenant uuid Commerce operates under for the current call ('' = sentinel). */
    public function tenantUuid(ApplicationContext $context): string;
}
```

`makeTenantResolver()` prefers a bound `CommerceTenantResolution` (wrapping it in the
`CurrentTenantResolver` shape Commerce consumes internally); with none bound, existing
behavior is byte-identical (sentinel or shared resolver per `commerce.tenancy.enabled`).
This stays a **Commerce-local interface** — not promoted to `glueful/extension-contracts`
unless a second consumer demonstrates the need.

`thallo-commerce` binds the implementation: mode (a) → `''`; mode (b) →
`SingleStoreTenant` default UUID (fail-closed if the flag says widened but no default is
persisted); mode (c) → delegate to the shared `CurrentTenantResolver`. It never binds or
replaces the shared `CurrentTenantResolver` contract id — that binding is reserved for the
tenancy implementation (contracts soft-binding rule).

## 5. The link table + `ProductLinkService`

### 5.1 Schema (pack migration, `thallo_commerce_product_links`)

| Column | Notes |
| --- | --- |
| `id` | autoincrement PK |
| `uuid` | link identity (nanoID) |
| `tenant_uuid` | default `''`; tenant-owned table |
| `product_uuid` | Commerce product uuid — **no DB FK** into Commerce tables |
| `entry_uuid` | Thallo entry uuid — no FK either; lifecycle events + reconciliation instead |
| `created_at` / `updated_at` | audit timestamps |

Constraints: **unique `(tenant_uuid, product_uuid)`**, **unique `(tenant_uuid, entry_uuid)`**,
index `(tenant_uuid, product_uuid)` for the detail-route lookup. Rows are **active links
only** — no status column, no retirement rows (a retained retired row would collide with the
entry unique and block relinking that entry elsewhere). History lives in append-only audit
events.

### 5.2 Service semantics

`ProductLinkService` (pack): `link`, `relink`, `unlink`, `resolveByProduct`,
`resolveByEntry`.

- **Tenant from the active Thallo context only** (via §4.2's resolution) — never from request
  input.
- **Same-tenant validation both sides** before any write: the product must exist live in
  Commerce for that tenant (via `CatalogReader`, §7) and the entry must exist in that tenant.
  Failures are non-revealing 404s.
- **Concurrency:** every mutation serializes on the `(tenant, product)` and `(tenant, entry)`
  identities in a stable order (sort the two lock keys lexicographically), then **re-reads
  before replacing**. Unique-constraint conflicts convert to a stable `409`. `relink` carries
  the **expected current entry uuid**; a mismatch on re-read is a `409` — two admins cannot
  silently overwrite each other. `link` on an already-linked product (or already-linked
  entry) without the expected-current token is a `409`, never an implicit upsert.
- **Auditing:** link / relink (with old→new entry) / unlink each schedule an append-only audit
  event through the existing audit bus via `afterCommit()` only. A rolled-back link mutation
  emits nothing. Relink is an explicit, audited replacement.
- Reads (`resolveByProduct`, `resolveByEntry`) are tenant-scoped O(1) lookups returning the
  link row or null; **fail closed** (null + diagnostics count) if the linked counterpart no
  longer resolves in the same tenant.

### 5.3 Admin JSON API (pack routes; SPA screens are slice 3)

Under the Thallo admin route group (admin auth + tenant middleware):
`PUT /commerce/products/{productUuid}/link` (body: `entry_uuid`, optional
`expected_entry_uuid` for relink), `DELETE .../link`, `GET .../link`, and
`GET /commerce/entries/{entryUuid}/link`. Error semantics pinned:

| Condition | Response |
| --- | --- |
| Unknown / cross-tenant / tombstoned product; unknown or cross-tenant entry | non-revealing `404` |
| Malformed input (missing/invalid `entry_uuid`, bad shapes) | `422` |
| Link/relink uniqueness or concurrency conflict (incl. expected-entry mismatch) | `409` |

## 6. Lifecycle: cleanup on both sides

### 6.1 Commerce side — the missing event

`CatalogService::deleteProduct()` currently tombstones with **no event** (verified), so
API/importer deletions would bypass pack cleanup entirely until reconciliation. Commerce
gains an after-commit **`ProductDeleted`** event (extends `BaseEvent`) carrying
`tenantUuid` + `productUuid`, dispatched on the tombstone path. Ordinary fault-isolated
dispatch (the link cleanup is convergent; reconciliation is the backstop — no
`dispatchOrFail` needed).

### 6.2 Pack listeners + rules

The cleanup listeners are maintenance infrastructure, not user-facing capability behavior.
They register **outside** the `thallo.commerce` capability gate whenever their source provider
is available, so disabling the pack cannot let previously-created links drift. Their unlink
audit events are also dispatched via `afterCommit()` only.

| Trigger | Effect |
| --- | --- |
| Thallo `EntryDeleted` | delete the canonical link row (audit event records it) |
| Commerce `ProductDeleted` | delete the link row; the editorial entry is **preserved** (independently recoverable) |
| Workspace purge | links removed by the pack's purge handler (§8.2), Commerce data by Commerce's purge service |
| Reconcile sweep (`thallo:commerce:links:reconcile`) | removes links whose product is tombstoned/absent or whose entry is gone; batch-limited; tenant-safe; reports counts |

Diagnostics surface stale/cross-tenant link counts and the marketplace-enabled flag (§1).

## 7. Commerce read contract: `CatalogReader`

The pack never binds to `ProductRepository` storage details. Commerce exposes a narrow
read contract (Commerce-local, additive):

```php
namespace Glueful\Extensions\Commerce\Catalog;

interface CatalogReader
{
    /** Live product for this tenant, or null (never a tombstone). */
    public function findLiveProduct(ApplicationContext $context, string $tenant, string $productUuid): ?array;

    /** True when the uuid exists for this tenant only as a tombstone. */
    public function isTombstoned(ApplicationContext $context, string $tenant, string $productUuid): bool;
}
```

Bound by Commerce's provider to a `ProductRepository`-backed implementation. The pack's
validation, reconcile sweep, and diagnostics consume only this contract — in-process PHP,
no HTTP loopback.

## 8. Adoption + purge (registration ≠ adoption ≠ purge)

Commerce already registers its tenant tables with `TenantTableRegistry` in its own `boot()`
(verified) — the pack **must not** register them again. Registry membership does not make
Commerce participate in Thallo's adoption or purge; those need explicit seams:

The pack **does** own `thallo_commerce_product_links`. Outside the capability gate, whenever
`TenantTableRegistry` is bound, its provider registers that table exactly once. The table is
part of the adoption contributor below and finalization requires
`TenantEnforcementProbe::isRegistered('thallo_commerce_product_links')` in addition to
Commerce's own table checks.

### 8.1 Adoption — a Thallo contributor seam

Thallo enforcement activation currently adopts only its own data. A generic
**tenant-adoption contributor** seam is added on the Thallo side (home:
`thallo-contracts`/`thallo-tenancy`, per ownership): an explicit registry mirroring the
`PurgeResourceRegistry` shape (`AdoptionContributorRegistry::register(contributor)`; packs
register in their providers), invoked by the activation flow as system work
(`TenantContextRunner::runAsSystem()`), **before** enforcement activation completes.
`thallo-commerce` registers a contributor that, in one system-context adoption operation,
adopts sentinel `thallo_commerce_product_links` rows and calls Commerce's existing
`TenantAdopter::adopt($context, $tenantUuid)` — each package keeps ownership of what adoption
means for its schema. The contributor runs after the default tenant exists and Thallo schema
widening has completed, but before enforcement activation. `TenantEnforcementProbe`
verification during finalization confirms both the pack link table and Commerce's tables are
registry-known. Installs with no contributors behave exactly as today.

### 8.2 Purge — a pack `PurgeHandler` + a Commerce purge service

Thallo's generic tables handler purges only Thallo-owned tables — Commerce does not ride it.
The pack registers a `CommercePurgeHandler` implementing the existing
`Thallo\Tenancy\Purge\PurgeHandler` (`id/dependsOn/prepare/purge/verify`), registered with
`PurgeResourceRegistry` **outside the capability gate** (CollectionsPurgeHandler precedent).
It purges the pack's own link rows, then delegates Commerce-table purging to a new
**`CommerceTenantPurge` service in Commerce** (additive), so the table/dependency list
stays owned by Commerce and is never duplicated in Thallo. `verify()` asserts zero remaining
rows for the tenant via the same service.

Because the purge handler remains registered while the capability is disabled, its factory
soft-resolves `CommerceTenantPurge`. If that service is unavailable and Commerce's schema is
absent, link-only cleanup may complete. If any Commerce schema marker (at minimum
`commerce_products`) exists but `CommerceTenantPurge` is unavailable, `prepare()`, `purge()`,
and `verify()` **fail closed**: the run must not report success after deleting only links and
leaving Commerce tenant data behind.

## 9. Starter "Product Page" — via a contributor seam

`StarterDefinitions` is currently a fixed app-owned kind list built in
`ThalloServiceProvider::makeStarterDefinitions()` (verified: six kinds, variadic ctor). It
gains a **contributor registry** (home: Thallo starter/contracts layer): packs contribute
`StarterKind`s, and the factory appends discovered contributions to the fixed list. This makes
the Product Page definition participate automatically in fresh provisioning, future tenant
provisioning (`TenantProvisioningRunner` path), and normal repair syncs. Zero contributors =
today's list, byte-identical.

Contributor discovery alone does not mutate existing tenants. The pack installation/enable
procedure explicitly runs the existing idempotent
`thallo:tenant:sync --all --kind=content_type` command after the contributor is available.
Failure is surfaced as an incomplete activation/deployment step and is retryable; provider
`boot()` never performs tenant-data writes.

`thallo-commerce` contributes a **Product Page content-type kind**: idempotently installed;
editorial/localized fields + a blocks region. **No SEO storage duplication** — SEO metadata
stays in `thallo-seo`'s own mechanism, applying to the entry like any other entry. Any
suitable content type remains linkable; the starter type is the batteries-included default,
not a requirement.

## 10. Testing (Postgres, per Thallo)

- **Modes:** resolution in all three modes; widened-without-default fails closed; no latched
  sentinel across a mode transition within one process (live-flag evaluation proven).
- **Linkage:** same-tenant validation both directions; cross-tenant product/entry →
  non-revealing 404; both uniques → 409; relink expected-entry mismatch → 409 under two
  interleaved admins (two-connection race, both orderings); audit events for
  link/relink/unlink.
- **Lifecycle:** cleanup listeners remain active with the capability disabled; `EntryDeleted`
  → link gone; `ProductDeleted` (real Commerce tombstone via
  `CatalogService::deleteProduct`) → link gone, entry preserved; reconcile sweep converges a
  seeded-drift state; all mutation/cleanup audit events are after-commit; purge run removes
  link rows and (via `CommerceTenantPurge`) Commerce rows, `verify()` green.
- **Adoption:** sentinel → widened → enforced walk with Commerce and link data present;
  contributor adopts both after widening/default-tenant creation and before activation;
  `commerce:tenancy:adopt` semantics preserved (refuses mixed data); finalization proves both
  Commerce tables and `thallo_commerce_product_links` registered.
- **Inertness:** pack disabled → no user-facing routes or starter contribution, while
  migrations + link-table registration + cleanup listeners + purge handler remain active;
  Commerce inactive → user-facing pack inert + diagnostic; purge fails closed when Commerce
  schema exists without its purge service; Thallo without the pack boots clean.
- **Starter adoption:** fresh/future tenants receive Product Page during provisioning; the
  explicit install/enable sync provisions existing active tenants; a failed sync is visible and
  retryable; provider boot performs no tenant-data writes.
- **Byte-parity:** Commerce with no `CommerceTenantResolution` bound behaves exactly as
  1.2.x (both `commerce.tenancy.enabled` values); Thallo starter with zero contributors
  yields today's definitions.
- **Marketplace:** `commerce.marketplace.enabled=true` in a Thallo install → diagnostics
  flag (no behavioral fork).

## 11. Out of scope (later slices)

Shop blocks, rendered routes, cart/checkout UX (slice 2); admin SPA screens (slice 3); the
Woo importer (slice 4); marketplace enablement in Thallo (not v1); a general-purpose
`commerce_product` entry field type (revisit with slice 2's block needs).
