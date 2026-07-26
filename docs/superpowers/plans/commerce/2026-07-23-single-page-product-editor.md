# Single-Page Product Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One scrollable product editor whose sections hydrate from six new commerce 1.5.0 per-product read endpoints and save independently under a revision-CAS guard — per the spec at `docs/superpowers/specs/commerce/2026-07-23-single-page-product-editor-design.md` (THE authority; re-read the relevant spec section before each task).

**Architecture:** Phase A adds the read surface + `expected_revision` CAS guard to glueful/commerce (1.5.0 minor). Phase B mounts the six new catalog keys in thallo (vendor overlay for pre-publish verification). Phase C rebuilds the SPA product detail page as section cards with a revision coordinator, content-aware rebasing, and explicit conflict review.

**Tech Stack:** PHP 8.3 (commerce extension, PHPUnit), Vue 3 + Nuxt UI + Pinia Colada + zod + vitest (thallo admin SPA).

## Global Constraints

- Commit on `dev` in each repo; **never push**; no AI/Claude attribution anywhere; never commit `thallo/config/extensions.php` or `thallo/docs/superpowers/**`.
- Every new read returns the **exact envelope** `{revision: int, items: [...]}` — no extra keys, no raw rows (whitelisted projections only; the 1.4.1 lesson).
- Controllers read `revision` **BEFORE** `items` (a concurrent write then yields revision older than items → a later CAS save gets a harmless 409; the reverse order can let a stale save pass).
- `expected_revision` is **optional** on the five replacement mutations: absent ⇒ today's serialize-only behavior byte-for-byte; present+stale+live product ⇒ **409** with state unchanged; unknown/cross-tenant/tombstoned product ⇒ **404** (never 409).
- Admin children reads never hide existing attachments: an attached tombstone is returned with `deleted: true`, may be retained or removed by a replacement, and may never be newly attached.
- A missing `commerce_stock` row is an integrity failure, never synthetic untracked/zero stock; the read fails loudly and diagnostics report the drift.
- No composite "save product" mutation. Sections save to their existing atomic endpoints.
- SPA section state = two axes: `phase: idle|saving|saved|error` + `dirty: boolean`. Navigation blocks while `dirty || phase === 'saving'`. No automatic conflict retries — ever.
- Product `type` renders read-only after creation (server rejects changes with 422).
- Money stays BigInt-discipline: `compare_at_price` flows through the existing `useMoney`/`parseMajorAmountToMinorUnits` helpers; never `Number()` float math.
- Gates before each phase's final commit — commerce: `vendor/bin/phpunit` (full), `vendor/bin/phpcs --standard=PSR12 src` (changed files clean; two pre-existing 1.4.0 failures are out of scope), `composer run analyze` (changed files clean). Thallo backend: `vendor/bin/phpunit tests/Integration/Commerce/`. SPA: `pnpm vitest run`, `pnpm type-check` (never piped through tail).

## File Structure

**Commerce (`/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`):**
- Modify: `src/Catalog/ProductRepository.php` (guarded claim + revision read), `src/Catalog/{CategoryService,TagService,AttributeService,ProductMediaService,ProductChildrenRepository}.php` + the children service path in `src/Catalog/CatalogService.php` (guard wiring + honest tombstone projection/retention), `src/Support/DiagnosticsReport.php` (missing-stock drift), `src/Http/Admin/{AdminCategoryController,AdminTagController,AdminAttributeController,AdminMediaController,AdminProductController}.php` (index actions + 409 mapping), `src/Http/DTOs/{SetProductCategoriesData,SetProductTagsData,SetProductAttributesData,SetProductChildrenData,ReorderMediaData}.php` (`expected_revision`), `src/Http/Routing/AdminRouteCatalog.php` (+6), `tests/Integration/Console/MaintenanceTest.php` (diagnostic projection), `CHANGELOG.md`, `composer.json`.
- Create: `src/Catalog/StaleCatalogRevisionException.php`, `src/Inventory/StockIntegrityException.php`, `tests/Integration/Http/AdminProductReadEndpointsTest.php`, `tests/Integration/Http/ReplacementRevisionGuardTest.php`.

**Thallo backend:** Modify `packages/thallo-commerce/src/Http/AdminMountAllowlist.php`, parity fixture + tests under `tests/Integration/Commerce/`, regen `docs/openapi.json`.

**Thallo admin SPA (`thallo/admin/src`):**
- Create: `queries/commerceProductSections.ts` (+ invalidation spec), `composables/useSectionState.ts`, `composables/useProductRevisionCoordinator.ts`, `utils/sectionRebase.ts` (+ specs), `pages/commerce/products/components/{EditorSectionCard,SectionNav,OrganizationCard,PricingStockCard,ChildrenCard}.vue`.
- Modify: `pages/commerce/products/[uuid]/index.vue` (card layout), `components/{ProductForm,MediaPanel,VariantsPanel,CategoriesTab→subsection,TagsTab→subsection,AttributesTab→subsection}.vue`, `queries/commerceCatalog.ts` (expected_revision on replacement mutations; invalidation), `src/__tests__/commerceProducts.spec.ts` + new spec files.

---

## Phase A — Commerce 1.5.0

### Task A1: Guarded revision claim + revision read

**Files:** Modify `src/Catalog/ProductRepository.php` (next to `claimCatalogRevision`, ~line 456); Create `src/Catalog/StaleCatalogRevisionException.php`; Test `tests/Integration/Catalog/ClaimCatalogRevisionExpectingTest.php`.

**Interfaces (Produces):**
```php
/** @return 'claimed'|'stale'|'missing' */
public function claimCatalogRevisionExpecting(ApplicationContext $context, string $tenant, string $uuid, int $expected): string;
/** Current catalog_revision for a live product, null when absent. */
public function catalogRevision(ApplicationContext $context, string $tenant, string $uuid): ?int;

final class StaleCatalogRevisionException extends \DomainException {} // message: 'Product was modified by another request.'
```

- [ ] **Step 1: Failing tests** — matching revision returns `'claimed'` and bumps the counter by exactly 1; stale revision returns `'stale'` and leaves the counter unchanged; unknown uuid and cross-tenant uuid return `'missing'`; both matching- and stale-revision tombstones return `'missing'` without bumping; `catalogRevision()` returns the live value / null. Seed via direct `commerce_products` inserts (pattern: any `tests/Integration/Catalog/*Test.php`).
- [ ] **Step 2: Implement.** Guarded claim = the existing `claimCatalogRevision` SQL plus both `AND deleted_at IS NULL` and `AND catalog_revision = ?`; on 0 affected, distinguish `'stale'` vs `'missing'` with the existing `findLiveByUuid()` (there is no `findByUuid()` on `ProductRepository`). `catalogRevision()` uses the same live predicate. **Do not touch `claimCatalogRevision` itself — 15 files call it.**
- [ ] **Step 3: Green + commit** (`feat: guarded catalog revision claim`).

### Task A2: Read endpoints — categories, tags (+ envelope convention)

**Files:** Modify `src/Http/Admin/AdminCategoryController.php`, `src/Http/Admin/AdminTagController.php`, repos as needed (`CategoryRepository` has `categoryUuidsForProduct` — add joined variants returning `{uuid, name, slug}`); Test `tests/Integration/Http/AdminProductReadEndpointsTest.php` (new; this task creates it, A3/A4 extend it).

**Interfaces (Produces):** `GET .../products/{uuid}/categories` and `/tags` → `Response::success(['revision' => int, 'items' => [{uuid, name, slug}]])`. Controller action name: `forProductIndex(Request $request, string $uuid)`. Guard: tenant-scoped `findLiveByUuid` 404 (non-revealing, identical to writes). **Read `revision` before `items`** with a comment stating why (Global Constraints).

- [ ] **Step 1: Failing tests** — assigned rows project exactly `{uuid, name, slug}` (assertEqualsCanonicalizing on keys); empty assignment → `items: []` with `revision` still present; unknown product 404; cross-tenant 404; envelope has exactly the keys `revision`, `items`.
- [ ] **Step 2: Implement** (template: `AdminAddonController::index` for shape, write endpoints for guards; single join per read, no N+1).
- [ ] **Step 3: Green + commit.**

### Task A3: Read endpoints — attributes, media

**Files:** Modify `src/Http/Admin/AdminAttributeController.php`, `src/Http/Admin/AdminMediaController.php`; extend `AdminProductReadEndpointsTest`.

**Interfaces:** attributes `items`: `[{attribute_uuid, name, values, used_for_variants, visible, position}]` (exact `commerce_product_attributes` editable columns; `values` JSON-decoded to array). Media `items` in position order: `[{uuid, blob_uuid, role, position, alt, variant_uuid}]`.

- [ ] **Step 1: Failing tests** — shapes exact-pinned; **round-trip pin**: feed the GET's `items` verbatim as `setProductAttributes`' `attributes` argument, assert re-read state unchanged (same rows, position order preserved); media ordering pinned; `values` is a decoded array, never a JSON string.
- [ ] **Step 2: Implement.** — [ ] **Step 3: Green + commit.**

### Task A4: Read endpoints — children, stock

**Files:** Modify `src/Http/Admin/AdminProductController.php`, `src/Support/DiagnosticsReport.php`, `tests/Integration/Console/MaintenanceTest.php`; Create `src/Inventory/StockIntegrityException.php`; extend `AdminProductReadEndpointsTest`.

**Interfaces:** children `items` (position order): `[{uuid, name, slug, status, deleted, position}]` (join `commerce_product_children` → `commerce_products`, deliberately including attached tombstones; child uuid = the CHILD product's uuid). Stock `items`: `[{variant_uuid, tracked, quantity}]` from `commerce_stock` joined via the product's variants. Use one left-join read so a missing stock row is detectable without N+1; if any variant lacks a row, throw `StockIntegrityException('Stock data is incomplete for this product.')` rather than returning fabricated stock. `DiagnosticsReport::build()['database']['variants_missing_stock']` is an ordered list of exact `{tenant_uuid, product_uuid, variant_uuid}` identities (empty when healthy), computed with one left join.

- [ ] Steps: failing tests (draft/live/tombstoned attached children all remain visible with exact flags and position; missing stock fails rather than defaulting; `DiagnosticsReport` reports the orphaned variant) → implement → green + commit.

### Task A5: `expected_revision` CAS guard on the five replacement mutations

**Files:** Modify the five DTOs (add `#[Rule('integer|min:0')] public readonly ?int $expected_revision = null`), the five service methods (`CategoryService::setProductCategories`, `TagService` equivalent, `AttributeService::setProductAttributes`, `ProductMediaService::reorder`, children setter in `CatalogService`), their five controller actions (catch → 409); Test `tests/Integration/Http/ReplacementRevisionGuardTest.php`.

**Interfaces (Produces):** each service method gains a trailing `?int $expectedRevision = null`. Guarded flow (replaces the unguarded claim ONLY when non-null), order pinned — tombstone 404 wins over stale 409:
```php
if ($expectedRevision === null) {
    if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) { throw new NotFoundException('Resource not found.'); }
} else {
    $claim = $this->products->claimCatalogRevisionExpecting($c, $tenant, $productUuid, $expectedRevision);
    if ($claim === 'missing') { throw new NotFoundException('Resource not found.'); }
}
if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) { throw new NotFoundException('Resource not found.'); }
if (($claim ?? 'claimed') === 'stale') { throw new StaleCatalogRevisionException('Product was modified by another request.'); }
```
Controllers add `catch (StaleCatalogRevisionException $e) { return Response::error($e->getMessage(), 409); }`. The stale path throws inside the transaction ⇒ rollback ⇒ **no state change and no revision bump on 409** (test-pinned).

- [ ] **Step 1: Failing tests, per mutation ×5** — stale → 409, assignment rows AND `catalog_revision` unchanged; matching → success + revision bumped; absent field → legacy path (still succeeds against any revision); negative revision → 422; unknown product with `expected_revision` set → 404; tombstoned product → 404 not 409. Children-specific cases: an already-attached tombstoned child survives a no-op replacement and may be removed; a tombstoned child not already attached is rejected.
- [ ] **Step 2: Implement.** For children, distinguish the pre-claim current set from newly proposed UUIDs: a current tombstone may be retained through `findIncludingDeletedByUuid()`, but every newly introduced child must still resolve through `findLiveByUuid()` and satisfy the physical/digital rule. This exception is retention-only; it never creates a new tombstoned relationship. — [ ] **Step 3: Green + commit.**

### Task A6: Catalog +6, parity fixture, OpenAPI, release prep

**Files:** Modify `src/Http/Routing/AdminRouteCatalog.php` (+6 entries: keys per spec §3 table, all `view`/`json`; domains — categories/tags/attributes → `taxonomy`, media/children → `products`, stock → `inventory` to match `stock.adjust`), `tests/Integration/Http/AdminRouteMountParityTest.php` + fixture (additive section — the shipped 98-entry fixture is immutable), `CHANGELOG.md` ([1.5.0] with Upgrade Note: restricted-mount hosts must allowlist the new keys; `expected_revision` documented), `composer.json` `extra.glueful.version` → `1.5.0`; regen `docs:openapi`.

- [ ] Steps: parity test additions failing → catalog entries → parity green → full commerce suite + phpcs/phpstan on changed files → OpenAPI regen → CHANGELOG/version → release commit `Release 1.5.0 — Per-Product Read Surface & Revision Guard`. **Stop: user publishes.**

## Phase B — Thallo backend

### Task B1: Mount the six reads

**Files:** Modify `packages/thallo-commerce/src/Http/AdminMountAllowlist.php` (+6 keys), thallo parity fixture/tests (104), authorization-matrix rows for one representative read (`products.stock.index`: `commerce.view` session 200, no-permission 403, API key scoped `commerce.view` whose subject's live role also satisfies `commerce.view` → 200; native `commerce:read` is not a Thallo permission); overlay commerce 1.5.0 files into `vendor/glueful/commerce` first (established pattern); regen `docs/openapi.json` → `admin pnpm gen:api` (idempotent check).

- [ ] Steps: overlay → failing parity → allowlist → green (`tests/Integration/Commerce/` full) → OpenAPI + gen:api → commit.

## Phase C — Admin SPA

### Task C1: Section read queries + revision-aware mutations

**Files:** Create `queries/commerceProductSections.ts` + `commerceProductSectionsInvalidation.spec.ts`; Modify `queries/commerceCatalog.ts`, `queries/keys.ts`.

**Interfaces (Produces):**
```ts
export interface SectionEnvelope<T> { revision: number; items: T[] }
export function useProductCategories(uuid: MaybeRefOrGetter<string>): ColadaQuery<SectionEnvelope<AssignedCategory>>
// ...Tags, Attributes(ProductAttributeAssignment), Media(ProductMediaItem incl variant_uuid), Children, Stock(VariantStock)
export const qk = { ...existing, commerceProductSection: (uuid: string, section: SectionKey) => [...] }
```
Strict normalizers (typeof guards, no Number() coercion — house rule). The five replacement mutations in `commerceCatalog.ts` gain `expected_revision` in their inputs. **Invalidation contract:** every product-scoped mutation (details, variants, media, organization, add-ons, downloads, children, stock adjust) invalidates `qk.commerceProduct(uuid)` + all six section keys — pinned by the colada-mock invalidation spec (house pattern).

- [ ] Steps: failing specs (normalizers + invalidation matrix) → implement → green + commit.

### Task C2: Section state + dirty registry + navigation guard

**Files:** Create `composables/useSectionState.ts` + `useSectionState.spec.ts`.

**Interfaces (Produces):**
```ts
export function createDirtyRegistry() // provide/inject key: DirtyRegistryKey
export function useSectionState(sectionId: string, label: string): {
  phase: Ref<'idle'|'saving'|'saved'|'error'>; dirty: Ref<boolean>
  markDirty(): void; beginSave(): void; saveSucceeded(): void; saveFailed(): void; markClean(): void
}
export function useUnsavedGuard(registry: DirtyRegistry): void // onBeforeRouteLeave confirm listing section labels + beforeunload; blocks while any dirty || saving
```
State rules test-pinned: `saveFailed()` ⇒ `phase='error'` AND `dirty` stays true; `saved` decays to `idle` (fake timers); registry counts `dirty || saving`.

- [ ] Steps: failing spec → implement → green + commit.

### Task C3: Rebase engine + revision coordinator (pure logic first)

**Files:** Create `utils/sectionRebase.ts` + `sectionRebase.spec.ts`, `composables/useProductRevisionCoordinator.ts` + spec.

**Interfaces (Produces):**
```ts
// Pure. B = baseline items, L = local draft, R = fresh remote items.
export function rebaseSet(B: string[], L: string[], R: string[]):
  | { kind: 'silent' }                       // setEquals(R, B): unrelated bump — keep L
  | { kind: 'merged'; result: string[] }     // (R ∪ (L∖B)) ∖ (B∖L), deterministic order: R order, then additions in L order
export function rebaseStructured(B: unknown, L: unknown, R: unknown): 'silent' | 'conflict' // deep-equal R vs B (revision excluded)

export function useProductRevisionCoordinator(): {
  register<T>(sectionId: string, s: {
    baseRevision: Ref<number|null>; dirty: Ref<boolean>
    refetch: () => Promise<SectionEnvelope<T>>
    adoptRemote: (remote: SectionEnvelope<T>) => void
    reconcileRemote: (remote: SectionEnvelope<T>) => void
  }): void
  refresh(sectionId?: string): Promise<void> // explicit refetch, awaited; clean => adoptRemote,
                                             // dirty => reconcileRemote(B/L/R), never chosen by coordinator itself
  afterMutation(): Promise<void>             // refreshes every registration after C1 invalidation
  refreshing: Readonly<Ref<boolean>>          // disables replacement saves until all requested work settles
  observedRevision: Ref<number|null> // display only — NEVER advances a dirty section's baseRevision
}
```
The coordinator orchestrates only; each section owns its typed baseline/draft and therefore owns adoption/reconciliation. Every successful mutation covered by C1's Commerce product-section invalidation matrix must await `afterMutation()` exactly once. Pack-owned linked-content mutations do not advance `catalog_revision` and do not call it. A 409 awaits `refresh(sectionId)` before presenting recovery. Conflict UX contract (consumed by C5/C6/C8): structured/ordered conflicts render **Use latest** (adopt R, markClean) vs **Replace with mine** (keep L, set baseRevision to R.revision, resubmit only on explicit confirm). No automatic retry.

- [ ] Steps: failing specs (silent-rebase, three-way merge incl. add+remove overlap and duplicate-add idempotence, structured conflict detection, dirty-baseline-never-advances, coordinator calls `adoptRemote` only for clean and `reconcileRemote` only for dirty, save-disabled-during-refresh, refresh rejection clears the flag without corrupting baselines) → implement → green + commit.

### Task C4: Editor shell — cards layout, sticky nav, draft banner

**Files:** Create `pages/commerce/products/components/EditorSectionCard.vue`, `SectionNav.vue`; Modify `pages/commerce/products/[uuid]/index.vue` (delete UTabs + `product-draft-callout`; render card sequence per spec §5.1 with type-conditional Downloads/Children; draft banner with Activate shortcut scrolling to Details); rewrite the detail-page section of `src/__tests__/commerceProducts.spec.ts`.

**Interfaces:** `EditorSectionCard` props `{sectionId, title, state?: SectionState}` renders header chip from phase×dirty; `SectionNav` props `{sections: [{id, label, indicator: 'error'|'unsaved'|'hint'|null, hint?: string}]}` — precedence error > unsaved > hint; hints draft-only, count-based ("Images · 0"); scroll-spy via IntersectionObserver (polyfill-guard in jsdom: feature-detect, no-op fallback).

- [ ] Steps: failing specs (all sections render on one page; nav indicators precedence; draft banner shows on draft only; Activate scrolls; guard dialog lists dirty sections) → implement → green + commit.

### Task C5: Details + Images cards

**Files:** Modify `ProductForm.vue` (card-ified; **type read-only** — render as text with a tooltip "Type is set at creation"; keep 422 field-error mapping), `MediaPanel.vue` (hydrate from `useProductMedia`; DELETE `knownMedia` session tracking + `media-unknown` alert; render variant attribution badge when `variant_uuid` set; attach/update/detach stay item-scoped — unguarded; reorder sends `expected_revision` and uses the C3 structured-conflict flow for order).

- [ ] Steps: failing specs (hydration renders server media; the three deleted behaviors are GONE — specs asserting them rewritten to the new contract; reorder 409 → awaited section refresh + conflict review, no auto-retry; every successful details/media mutation awaits `afterMutation()` once) → implement → green + commit.

### Task C6: Organization card (categories/tags/attributes subsections)

**Files:** Create `OrganizationCard.vue`; rework `CategoriesTab/TagsTab/AttributesTab` into subsections consuming C1 reads + C3 rebase; Modify save payloads: **server state + user edits**, always with `expected_revision`.

**Behavior pinned by specs:** categories/tags: 409 or coordinator refresh with `R != B` → three-way merge applied, banner "merged with remote changes — review and save"; `R == B` → silent rebase, no UI noise. Attributes: conflict → explicit **Use latest** / **Replace with mine** review (C3 contract), never auto-resubmit. Wipe-class regression test: off-screen assignments survive an edit+save round-trip built from hydrated state.

- [ ] Steps: failing specs, including successful category/tag/attribute saves awaiting `afterMutation()` exactly once → implement → green + commit.

### Task C7: Pricing & stock card (progressive disclosure + compare-at)

**Files:** Create `PricingStockCard.vue`; Modify `VariantsPanel.vue` (stock quantity column from `useProductStock`; `compare_at_price` field on variant create/edit forms), `ProductCreateSlideover.vue` (optional compare-at on the default variant), `queries/commerceCatalog.ts` (compare_at_price in variant inputs — API already accepts it).

**Behavior:** exactly one variant AND no option axes ⇒ compact card (SKU, price, compare-at, tracked quantity + inline adjust); "Add more variants" = **UI-only** expansion (spec §5.3 — pinned by a spec that asserts no mutation fires on expand); ≥2 variants ⇒ full table directly. Stock display comes from the read; adjust stays the only write and refreshes it. Money via existing BigInt helpers.

- [ ] Steps: failing specs (disclosure branches, compare-at round-trip in payloads, stock render + post-adjust refresh, expand-fires-no-mutation, successful variant/stock mutations await `afterMutation()` exactly once) → implement → green + commit.

### Task C8: Children card, final wiring, gates, CHANGELOG

**Files:** Create `ChildrenCard.vue` (grouped only; hydrates `useProductChildren`; `setChildren` with `expected_revision` + structured conflict review); wire Add-ons/Downloads/Linked-content cards into the sequence (existing components, card chrome only); Modify thallo `CHANGELOG.md` [Unreleased]; full gates.

- [ ] Steps: failing specs (children hydration includes existing tombstones without permitting new tombstone attachments; children conflict; successful children/add-on/download mutations await `afterMutation()` exactly once; full-page smoke: every card present for each product type matrix) → implement → green → **full gates**: `pnpm vitest run` + `pnpm type-check` + thallo `tests/Integration/Commerce/` + commerce full suite → CHANGELOG → commit.

### Task C9: Repin (after user publishes 1.5.0)

- [ ] `composer update glueful/commerce` in thallo (root + pack pins → `^1.5.0`), lock verified against the published artifact, re-run thallo Commerce integration dir, commit.

---

## Self-Review Notes

- Spec §3/§5 coverage traced task-by-task; §5.2's "save disabled during refresh/rebase" lands in C3 (coordinator flag) and is consumed by C5/C6/C8 conflict flows.
- Type consistency: `SectionEnvelope`, `useSectionState`, coordinator, and rebase signatures are defined once (C1–C3) and only consumed afterwards.
- The five service guard-wirings in A5 share one pinned flow (tombstone-404-before-stale-409) — the test file exercises all five against it.
- Admin child reads retain attached tombstones honestly; missing stock fails as integrity drift; neither case is collapsed into an apparently empty/default state.
- The coordinator owns orchestration, while typed sections own B/L/R reconciliation; successful Commerce product mutations await the same refresh path, while pack-owned linked-content mutations remain outside `catalog_revision` coordination.
