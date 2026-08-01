# Admin SPA Commerce Area — Design (Slice 3)

**Track:** Ecommerce content integration, slice 3 of 4 (slice 1: adoption + linkage; slice 2:
storefront rendering; slice 4: Woo importer).
**Date:** 2026-07-22
**Surfaces touched:** glueful/commerce (new minor, → 1.4.0), thallo-commerce pack (backend mount +
permissions + meta), thallo admin SPA (the commerce area), thallo app (permission catalog + entry-
editor panel seam).

## 1. Goal

Give admins a full commerce management area inside the Thallo admin SPA — products/variants/media/
taxonomy, inventory, orders/refunds/fulfillment, discounts, shipping/tax settings, reviews
moderation, customers (read-only), reports, and bidirectional product↔entry linking with storefront
preview — reusing Commerce's existing admin JSON API end to end. Workspace selection drives the
Commerce tenant context. Commerce remains the commercial authority; the SPA is a client, not a
second source of truth.

## 2. Why a mounted catalog (the core mechanism)

The admin SPA authenticates with session JWTs. Commerce's native admin surface (`/commerce/admin/*`)
is gated by `require_scope:commerce:read|write`, and the framework's `RequireScopeMiddleware`
**explicitly denies JWT requests** (scopes exist only on API-key auth). Commerce's native routes also
lack Thallo's admin workspace-binding middleware, so they cannot resolve the selected workspace.
Direct SPA→`/commerce/admin` calls are therefore impossible; the admin surface must be re-mounted
under Thallo's `/v1/admin` world with Thallo's middleware.

Hand-copying ~90 route declarations into the pack would create a permanent drift surface. Instead:
**Commerce owns a mountable admin route catalog**; Commerce mounts it at its native location and
thallo-commerce mounts the same definitions at `/v1/admin/commerce/*`. Controllers, DTO validation,
and response envelopes are unchanged — only registration is shared.

## 3. Commerce 1.4.0 — `AdminRouteCatalog` + `AdminMountProfile`

### 3.1 Catalog

`Glueful\Extensions\Commerce\Http\Routing\AdminRouteCatalog`: a declarative inventory of the
**non-marketplace** admin surface. Marketplace routes never enter the catalog — they stay in
`routes.php` under the `commerce.marketplace.enabled` flag (structural exclusion, plus an explicit
test). Each catalog entry:

| Field | Meaning |
|---|---|
| `key` | Stable id (`products.index`, `orders.refunds.store`, …) — the unit of allowlisting, parity fixtures, and mount-scoped route naming |
| `method`, `path` | HTTP method + prefix-relative path |
| `controller` | `[FQCN, action]` — the existing Admin controllers, untouched |
| `mode` | **Explicit** `view` \| `manage` per entry — never inferred from HTTP method |
| `kind` | Explicit classification: `json` \| `bulk` \| `binary` \| `unusual` — every endpoint consciously classified; nothing mechanically remounted |
| `domain` | `products`, `orders`, `discounts`, `shipping`, `tax`, `reviews`, `customers`, `reports`, `downloads`, `taxonomy`, `inventory` |

### 3.2 Mount profile

`AdminRouteCatalog::mount(Router $router, AdminMountProfile $profile)` where the profile carries:

- `prefix` (native: `/commerce/admin`; Thallo: `/v1/admin/commerce`)
- `routeNamePrefix` — guarantees route-name uniqueness across the two mounts
- base `middleware` stack (host-owned, ordered)
- a `mode → middleware` map — native: `view → require_scope:commerce:read`,
  `manage → require_scope:commerce:write`; Thallo: `view/manage → the pack's permission gate` (§4.2)
- an **allowlist** of entry keys (or whole domains):
  - **Native mount: `all`** — Commerce exposes its own full surface by definition.
  - **Thallo mount: explicit key-level allowlist, fail closed.** A newly added Commerce endpoint
    stays unmounted in Thallo until the allowlist is consciously updated — before any CI runs.
    The parity test (§8.2) then keeps the allowlist honest.

### 3.3 Native re-mount parity + catalog metadata completeness

Commerce's own `routes.php` admin group is refactored to mount the catalog with its native profile.
**Completeness is pinned as a route-inventory comparison, not controller reflection** (public
controller methods are not necessarily route actions). Two distinct assertions apply:

1. **Legacy route parity:** a checked-in fixture of the approved 1.3.x `/commerce/admin` route table
   must equal the catalog's native mount on **method, path, controller/action, flattened middleware,
   and route name**. These are the fields the legacy route table actually carried.
2. **Catalog metadata completeness:** every catalog entry has a unique non-empty key and a valid
   explicit `mode`, `kind`, and `domain`; no entry relies on metadata inferred from its HTTP method
   or controller.

`mode`, `kind`, and `domain` are new catalog contracts, not falsely described as legacy byte-parity
fields. Commerce-side tests cover both assertions plus marketplace exclusion.

## 4. Thallo mount (thallo-commerce pack)

### 4.1 Registration

Mounted inside the `thallo.commerce` capability gate (absent capability ⇒ 404, same as the slice-1
link routes). Middleware order pinned exactly:

```
auth → tenant_profile:admin → tenant_bootstrap → admin_tenant_binding → <commerce permission gate>
```

Authentication first, workspace resolution/bootstrap, tenant binding, permission last. Workspace
binding is what drives `CommerceTenantResolution`, so every re-mounted Commerce read/write resolves
the selected workspace.

### 4.2 Permissions — `commerce.view` / `commerce.manage` with implication

- **New permission `commerce.view`**; existing `commerce.manage` is renamed in the catalog from
  "Manage commerce product-content links" to **"Manage commerce"**.
- **Authorization modes are centralized, with implication:** `view` routes require
  `commerce.view` **OR** `commerce.manage`; `manage` routes require `commerce.manage`.
  The current `RequirePermission` middleware accepts a single permission
  (`app/Content/Http/RequirePermission.php`); it gains a **generic any-of mechanism** (accept a
  candidate set) rather than hardcoding Commerce implications inside it. Authorization is evaluated
  as one bounded intersection, never as two independent `any` checks:

  ```text
  JWT:     allowed = exists P in candidates where live_RBAC_allows(P)
  API key: allowed = exists P in candidates where key_scope_allows(P) AND live_RBAC_allows(P)
  ```

  Declared implications (here `commerce.manage -> commerce.view`) are normalized consistently for
  both the live RBAC permission set and API-key scopes before that candidate-wise intersection.
  Empty API-key scopes remain a deny; wildcard matching retains the framework's existing semantics.
  This prevents an API key satisfying one unrelated candidate while RBAC satisfies another from
  accidentally authorizing the request. `/meta.can_manage` (§4.3) uses this same authority — no
  duplicated implication logic anywhere.
- Both permissions are added to the **capability catalog, baseline matrix, seed migration, and
  policy-hash coverage**. Owner/administrator seeded with both. Nothing auto-granted to generic
  viewer roles. (Thallo is unpublished — fold into the existing seed migration; sync the dev DB
  manually.)
- **Slice-1 regrade:** the link GET lookups (`showByProduct`, `showByEntry`) move to `view` mode;
  `link`/`unlink` stay `manage`.

### 4.3 `GET /v1/admin/commerce/meta` (pack-owned, `view`)

Returns exactly:

- `currency` — ISO 4217 code from Commerce config
- `currency_exponent` — minor-unit exponent (0, 2, or 3) so the SPA never assumes `/100`
- `shop_index_url` — the **absolute**, selected-workspace storefront index URL, generated
  server-side by the pack-local preview URL composer described below
- `low_stock_threshold` — from Commerce config (reports UI)
- `can_view`, `can_manage` — **server-computed effective flags via the §4.2 authority** (the SPA
  hides mutation controls from these; the server remains the enforcer)

**No storefront URL template is exposed.** A pack-local `StorefrontPreviewUrlBuilder` composes the
existing `CanonicalPublicOriginResolver::currentOrigin()` with paths from `ShopUrlGenerator`.
`admin_tenant_binding` has already bound the selected workspace before this code runs, so the origin
comes from that workspace's verified/configured canonical origin; outside enforcement, the same
resolver returns the configured single-store app origin. It never trusts the request `Host` header.

`ShopUrlGenerator` remains the sole storefront **path** authority and
`CanonicalPublicOriginResolver` remains the sole public-origin authority. Concrete absolute URLs are
assembled only by this server-side composer and returned to the SPA; the client never concatenates
origins, prefixes, or slugs.

## 5. Money handling (SPA)

`useMoney()` formats integer minor-unit amounts using `currency` + `currency_exponent` from `/meta`,
via string-based decimal placement (no floating-point arithmetic on amounts). Zero- and
three-decimal currencies format correctly by construction.

## 6. SPA area architecture

Follows the collections exemplar throughout (file-based pages, Colada query modules, registry
module, `data-test` hooks):

- `src/registry/commerceModule.ts` — `requires: ['thallo.commerce']`. **Nav children are appended
  only as each domain's pages land** — no incomplete navigation entries, ever.
- Nav shape: **Commerce** → Overview (reports), Products, Orders, Customers, Reviews, Discounts,
  Settings (Shipping zones/classes + Tax rates). Categories/tags/attributes are tabs within the
  Products area; addons and downloads live inside product detail.
- Data layer: per-domain query modules (`commerceCatalog.ts`, `commerceOrders.ts`,
  `commerceLinking.ts`, …) + keys in `qk`; HTTP via the typed `/v1/admin` client. OpenAPI paths are
  generated from the **mounted route manifest** (the generator walks the live route table;
  controller annotations supply operation metadata but never the paths). Generation for the Thallo
  client is filtered by the Thallo mount's route-name prefix and explicit allowlist, so the native
  `/commerce/admin/*` duplicates never enter `schema.d.ts`. Operation ids are derived from the
  mount-scoped route-name prefix plus catalog key, not controller/action, preventing collisions when
  the same controller is mounted twice. A schema check asserts that approved
  `/v1/admin/commerce/*` paths are present, native `/commerce/admin/*` paths are absent, and operation
  ids are unique; hand-written normalizers remain the fallback where generation lags.
- Route gating: `requiresCapability: thallo.commerce` in each page's route block; the existing guard
  handles it unchanged.
- Mutation controls render only when `can_manage`; `view`-only sessions get a read-only area.

## 7. Product↔entry linking + preview

### 7.1 One shared workflow

A single `ProductEntryLinkPanel` component + a single `commerceLinking.ts` query module, mounted on
**both** sides:

- **Product detail (primary):** shows the linked entry, searches eligible entries (**any content
  type** — not restricted to the starter Product Page type), link/relink/unlink, opens the entry
  editor.
- **Entry editor (contextual):** shows the linked product, searches products, link/relink/unlink,
  opens Commerce product detail.

Relink is explicit: a confirm step showing what will be replaced, submitting `expected_entry_uuid`
(the slice-1 CAS); a 409 renders as "the link changed underneath you" with a refresh action. Both
panels invalidate the same by-product/by-entry cache keys. Cross-tenant/inaccessible records stay
non-revealing (the API already guarantees it; the SPA renders the 404 state).

### 7.2 Link projection carries the preview URL

`showByProduct` becomes a **product link projection**: `200` with
`{ product_uuid, storefront_url, link: null | {…} }` for any accessible product (404 only for
unknown/cross-tenant/tombstoned). `storefront_url` is an **absolute**, selected-workspace URL produced
by the same `StorefrontPreviewUrlBuilder` as `/meta`: canonical origin plus the product path from
`ShopUrlGenerator`. The preview affordance on product detail and in both panels uses this field.
(The pack is unpublished; this response evolution is fold-legal.)

### 7.3 Cross-domain authorization

Product-side entry search must not bypass `content.view`. Pinned resolution: a **pack-owned,
deliberately minimal entry-search projection** (uuid, title, content-type, status, locale — no
content bodies), authorized as part of Commerce linking (`manage` mode — it exists solely to feed
link mutations). Commerce staff don't need `content.view`; content bodies never leak through the
search. The entry-side product search uses the re-mounted `products.index` (`view` mode), which the
panel's own gating already implies.

### 7.4 Entry-editor panel seam

The editor tabs are currently hardcoded in `admin/src/pages/content/[type]/[uuid]/index.vue`.
Directly importing Commerce UI there would couple the editor to an optional pack. Instead: a static
**`entryEditorPanels` manifest** (same static-registry pattern as `adminModules`), where a
declaration carries `component`, `label`, capability requirement, and ordering. Commerce contributes
its link panel through the manifest; the editor renders manifest panels generically. The panel is
hidden entirely when Commerce permissions are absent (capability gating via the manifest;
permission gating via the shared, cached `/meta` query). The editor withholds the Commerce tab until
that query settles: `can_view=true` admits it, while a 403/`can_view=false` omits it without briefly
rendering a tab, an error panel, or a permission-denied flash. Loading is not interpreted as enabled.
All Commerce pages and editor panels consume the same meta query, so this check does not multiply
requests. Future packs get the same controlled extension point.

## 8. Testing

### 8.1 Commerce (1.4.0)

- **Native-mount route equality** (§3.3): approved legacy 1.3.x route fixture == catalog native
  mount on method, path, controller/action, flattened middleware, and route name.
- **Catalog metadata completeness** (§3.3): unique keys plus an explicit valid mode, kind, and domain
  on every entry.
- Marketplace-exclusion assertion (no marketplace key can enter the catalog).

### 8.2 Pack

- **Approved-inventory parity:** a checked-in fixture (endpoint keys + modes) diffed against both
  the catalog and the live mounted `/v1/admin/commerce` route table. A new Commerce endpoint fails
  the diff until consciously added to the fixture *and* the allowlist; a silently-dropped mount
  fails it too.
- **Authorization matrix:** unauthenticated → 401; wrong workspace → non-revealing; `view`-only →
  GET 200 + mutation 403; `manage`-only → mutation **and** GET 200 (proves implication);
  cross-tenant object → non-revealing 404. API-key cases additionally prove the candidate-wise
  scope∩RBAC intersection, implication normalization in both sets, wildcard behavior, empty-scope
  denial, and rejection when scope and RBAC satisfy different unrelated candidates.
- Mount smoke per domain (representative endpoints through the real kernel), `/meta` shape,
  permission seed/regrade migration tests, absolute link-projection (`storefront_url`) tests in both
  single-store and selected-workspace modes (including hostile-Host irrelevance), entry-search
  projection authorization tests, and an OpenAPI gate proving mount-scoped paths/operation ids.

### 8.3 SPA

Per-domain vitest specs following the collections pattern: module gating spec, query specs, linking
panel spec (both mounts, CAS conflict path), `useMoney` exponent cases (0/2/3), `entryEditorPanels`
manifest spec (including loading-withheld and denied-without-flash states) — all via `data-test`
hooks (Nuxt UI portal/stub constraints respected).

## 9. Phases (each independently green) + activation boundary

| Phase | Contents |
|---|---|
| P1 | Commerce: catalog + native re-mount, legacy route equality + catalog metadata completeness green (releasable alone) |
| P2 | Pack: Thallo mount (fail-closed allowlist) + permission any-of/intersection mechanism + `commerce.view` + catalog/matrix/seed/policy-hash + slice-1 GET regrade + absolute preview URL composer + `/meta` + link projection + parity/auth-matrix tests + mount-scoped OpenAPI regen |
| P3 | SPA scaffolding (module, queries base, `useMoney`, meta, gating) + **Products** (list/detail/variants/media/categories tabs) + **Linking** (product panel + `entryEditorPanels` seam + entry panel). **P3 is the first user-visible activation boundary: the Commerce nav appears with Products.** |
| P4 | **Orders** (+ refunds, fulfillment, notes, invoice data) — nav appends Orders |
| P5 | Discounts + Settings (Shipping zones/classes, Tax rates) |
| P6 | Reviews moderation + Customers (read-only) + Overview (reports) |
| P7 | Taxonomy extras: tags, attributes+values, addons, downloads+grants |
| P8 | Release train: commerce **1.4.0** published first, then pack allowlist/pins finalized + CHANGELOG (release-before-pinning) |

Each phase ends green (full thallo + commerce suites, SPA vitest + type-check). Nav entries appear
per completed domain — later domains land incrementally without exposing incomplete navigation.
During development the pack consumes commerce via the dev path (as slices 1+2 did), repinned at P8.

## 10. Out of scope

- Marketplace/seller surfaces (flagged off in Thallo v1; structurally excluded from the catalog).
- Capability enable/disable UI (the `thallo.capabilities` switchboard stays config-only).
- HTTP surfaces for the CLI-only maintenance commands (`thallo:commerce:diagnose`,
  `links:reconcile`, `checkout:purge-attempts`).
- Domain-level permission granularity (`commerce.orders.fulfill`, …) — deferred until real staff
  workflows require it.
- The Woo importer (slice 4).
