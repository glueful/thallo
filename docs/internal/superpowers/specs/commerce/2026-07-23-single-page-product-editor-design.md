# Single-Page Product Editor — Design Spec

**Date:** 2026-07-23
**Track:** Ecommerce content integration — post-slice-3 authoring UX
**Repos:** glueful/commerce (1.5.0 minor), thallo backend (mount), thallo admin SPA

## 1. Problem

The product authoring surface is invisible. After creating a product (draft-first,
`e2ecec7`), the author lands on a tabbed editor where images, pricing, stock,
categories, tags, and attributes each hide behind a tab click. Merchants arriving
from Shopify/WooCommerce — where the whole authoring surface is one visible page —
immediately ask "where do I add images? categories?". A helper sentence cannot fix
a surface you cannot see.

Underneath the UX problem sits an API gap: commerce has **no per-product read
endpoints** for category/tag/attribute/media assignments, grouped children, or
stock levels. The slice-3 SPA compensated with "write-honest" behavior (unknown-state
alerts, session-only tracking, wholesale-replace payloads built from edits) — the
direct cause of both the T19b wipe-risk class and the blind-replacement warnings.

## 2. Product decision (user-pinned constraints)

The product is **one commercial object**; authors should not have to understand
which backend resource owns categories, media, variants, or add-ons. A single
scrollable editor with section navigation is the authoring model. Binding
constraints, verbatim:

1. Each section **loads its existing state before editing**. No blind replacement
   warnings.
2. Each section **saves independently to its current atomic endpoint**. Do not
   create one giant product mutation.
3. Show section-level **Saving, Saved, Error, and unsaved-change** states.
4. Keep the right-hand **section navigation sticky**; indicate incomplete/error
   sections.
5. **Progressive disclosure**: simple products see a compact pricing/stock
   section; variant-heavy controls expand when needed.
6. **Preserve draft creation** (name/type/price → land in editor).
7. **Prevent navigation away** when any section has unsaved changes.
8. Backend read endpoints are **part of the feature**, not a later enhancement.

## 3. Commerce 1.5.0 — per-product read surface

Six additive admin GET endpoints, all `view` mode. Every endpoint returns the
exact envelope `{revision: int, items: [...]}`; `revision` is the product's current
`catalog_revision`, and `items` is a whitelisted editable/display projection from
which the existing write payload can be built without losing state. Verified
current state:
`GET /products/{uuid}` embeds only `variants` (with resolved shipping-class slugs);
nothing else is readable per-product. Add-ons already have `products.addons.index`
— the template to follow.

| Catalog key | Route | `items` projection |
|---|---|---|
| `products.categories.index` | `GET /products/{uuid}/categories` | assigned categories: `[{uuid, name, slug}]` |
| `products.tags.index` | `GET /products/{uuid}/tags` | assigned tags: `[{uuid, name, slug}]` |
| `products.attributes.index` | `GET /products/{uuid}/attributes` | the complete editable shape `setForProduct` accepts, per assignment: `[{attribute_uuid, name, values[], used_for_variants, visible, position}]` — hydrate → edit → replace must round-trip losslessly |
| `products.media.index` | `GET /products/{uuid}/media` | attached media in position order: `[{uuid, blob_uuid, role, position, alt, variant_uuid}]` (`variant_uuid` carries variant-specific media attribution — `AttachMediaData` accepts it, so the read must return it) |
| `products.children.index` | `GET /products/{uuid}/children` | grouped composition: `[{uuid, name, slug, status, deleted, position}]`; attached tombstones remain visible with `deleted: true` |
| `products.stock.index` | `GET /products/{uuid}/stock` | per-variant: `[{variant_uuid, tracked, quantity}]` |

Design notes:

- **Dedicated stock GET**, not an embed in product show — show's wire shape stays
  untouched, and the stock card lazy-loads it.
- All six read the assignment tables directly (tenant-scoped, live-product 404
  guard identical to the write endpoints), no N+1 (single join per endpoint).
- **Wire discipline**: every response is a whitelisted projection from day one
  (the 1.4.1 lesson) — never raw rows.
- **Existing tombstoned children remain honest admin state.** Product deletion
  does not remove `commerce_product_children` rows, and the existing admin child
  repository deliberately reads every attachment. The children read therefore
  includes attached tombstones rather than hiding them. A replacement may retain
  an already-attached tombstoned child or remove it, but may never newly attach a
  tombstoned child. This preserves round-trip state without weakening new-link
  validation; the storefront continues filtering tombstones.
- **Missing stock is an integrity failure.** Commerce creates one stock row for
  every variant and distinguishes a missing row from a legitimate untracked row.
  The stock read throws a stable `StockIntegrityException` when a variant lacks
  its row, and `DiagnosticsReport::build()` lists the affected tenant/product/
  variant identities under `database.variants_missing_stock`; it never
  fabricates `{tracked: false, quantity: 0}`.
- **Revision-guarded replacement writes.** Hydration alone only fixes same-session
  blind replacement — two editors can still overwrite each other. The existing
  `catalog_revision` (`ProductRepository::claimCatalogRevision`) serializes
  concurrent set-list writes but never rejects stale clients ("the counter itself
  is just evidence"). 1.5.0 upgrades it to a CAS guard:
  - every per-product read above returns `{revision, items}` exactly;
  - the five **replacement** mutations (`categories.set`, `tags.set`,
    `attributes.set`, `children.set`, `media.reorder`) accept an optional
    `expected_revision`; when present, the claim becomes
    `... AND catalog_revision = ?` — zero affected rows with a live product ⇒
    **409 conflict** (unknown/cross-tenant product stays 404);
  - `expected_revision` absent ⇒ today's serialize-only behavior (existing API
    clients unaffected — minor-safe). The SPA always sends it.
  - Item-scoped media mutations (attach/update/detach) are not wholesale
    replacements and stay unguarded.
- `AdminRouteCatalog` grows 98 → 104 entries. Restricted mounts stay fail-closed
  by design: the new keys are **unmounted in hosts until explicitly allowlisted**
  (this is the catalog working as intended, and gets a parity-test story below).
- The native-mount 1.3.x byte-parity fixture pins the original 98; the parity test
  gains an additive assertion set for the six new entries (new fixture section,
  not a mutation of the shipped one).
- OpenAPI: `@queryParam`-style docblocks + reflect generation; `docs:openapi`
  regenerated.
- Release: **1.5.0 minor** (additive routes only; no schema changes — all six read
  existing tables). CHANGELOG + Upgrade Note ("hosts using restricted mounts must
  allowlist the new keys to adopt them").

## 4. Thallo backend

- `AdminMountAllowlist`: +6 keys (conscious approval, per the fail-closed design).
- 3-way parity tests (catalog ↔ fixture ↔ mounted) updated for 104.
- All six mount as `view` mode → `content_permission:commerce.view,commerce.manage`
  (existing mode map; no authorization changes).
- OpenAPI regen → admin `pnpm gen:api` → schema types.

## 5. Admin SPA — the single-page editor

### 5.1 Information architecture

`/commerce/products/{uuid}` becomes one scrollable page of **section cards**, in
merchant-priority order:

1. **Details** — name, slug, description, status, tax class (existing
   ProductForm, recast as a card). **Type is conditionally editable** (revised
   2026-07-24 — supersedes the earlier read-only pin): Commerce's real guard is
   `productHasStrandableReferences` — a type change is REJECTED (422, field
   `type`) only while the product carries variants, grouped-children membership
   in either direction, or cart/order references. The card mirrors the
   client-visible half of that honestly: a variant-carrying product renders
   type as locked text ("Locked — products with variants can't change type")
   and the payload never includes `type`; a variant-free product gets a real
   select, with `type` sent ONLY when actually changed and the rarer
   child-membership lock arriving as the server's own field-mapped 422. The
   DRAFT type (not the saved product) drives the external-link fieldset, so
   switching to `external` reveals the required URL in the same save — the
   server validates external metadata against the incoming effective type.
2. **Images** — existing MediaPanel, hydrated from `products.media.index`;
   unknown-state alert and session-only `knownMedia` tracking **deleted**
3. **Pricing & stock** — progressive disclosure (5.3)
4. **Organization** — Categories, Tags, Attributes as one card with three
   subsections. Each subsection keeps its **own** save control, state chip, and
   atomic endpoint (constraint 2 applies at the subsection level here); the nav
   indicator aggregates the three (worst state wins). Each hydrates from its read
   endpoint; replacement payloads are built from **server state + user edits**
   (the wipe class dies here)
5. **Add-ons** — existing AddonsPanel (already has a real GET)
6. **Downloads** — existing DownloadsPanel (per-variant GET exists), rendered for
   digital products only
7. **Linked content** — existing ProductEntryLinkPanel
8. **Grouped products** — children card, hydrated from `products.children.index`,
   rendered for grouped type only

A sticky **right-hand section nav** (desktop; collapses to a top anchor strip on
narrow viewports) scroll-spies the active section and per-section shows, in
precedence order: **error > unsaved > empty-hint**. Empty-hints appear on drafts
only, count-based and factual ("Images · 0", "Categories · 0") — computable now
that reads exist; never a judgmental "incomplete" (an imageless active product is
legitimate).

### 5.2 Section save model

- Every card keeps its **own atomic endpoint** and its own save button — no
  composite mutation, no page-level save.
- A shared `useSectionState` composable per card models **two independent axes**
  (a single enum loses "still unsaved" after a failed save):
  - `phase: idle | saving | saved | error`
  - `dirty: boolean`
  A failed save is `error` **and still `dirty`**. The chip renders from both
  (e.g. "Save failed — unsaved changes"); `saved` decays to `idle` after a few
  seconds; `error` persists until retried or re-edited.
- A page-level **dirty registry** (cards register `dirty || phase === 'saving'`)
  drives the navigation guard: `onBeforeRouteLeave` confirm dialog + native
  `beforeunload` for hard navigation. Navigation blocks while any section is
  dirty **or mid-save** — an in-flight save never silently loses its outcome.
  The guard lists *which* sections are unsaved.
- A page-level **product revision coordinator** tracks two values rather than one
  unsafe global token: the latest observed product revision and each replacement
  section's own `baseRevision` from the server state it actually rendered. A
  replacement save sends that section's `baseRevision`; merely observing a newer
  revision elsewhere never advances a dirty section's baseline and therefore
  cannot hide a stale overwrite.
- After **every successful product-scoped mutation** on this page (details,
  variants, media, organization, add-ons, downloads, or children), invalidate the
  product show plus all six per-product reads. Clean sections adopt the refreshed
  server state and revision. Dirty sections retain their local draft separately,
  rebase it against the refreshed server state as described below, and remain
  dirty. A replacement save is disabled while its section's refresh/rebase is in
  flight. This removes same-user false conflicts without weakening the server CAS;
  an external write after the refresh still produces 409.
- **409 conflict handling** (revision guard, §3): on a stale replacement write
  preserve three snapshots: original baseline `B`, local draft `L`, and freshly
  hydrated remote state `R`. The section stays dirty and never blindly resubmits
  `L`. The same rebase algorithm runs when another successful page mutation
  refreshes a dirty section. First compare the section content (excluding the
  revision): when `R == B`, only an unrelated mutation advanced the shared
  product revision, so retain `L`, adopt `R.revision` as the new base revision,
  and show no conflict. Only when `R != B` did this section's server state change:
  - categories/tags use a deterministic three-way set merge:
    `additions = L - B`, `removals = B - L`, result = `(R union additions) - removals`;
  - attributes, ordered children, and media order are structured/ordered data and
    do not auto-merge. They show an explicit conflict review with **Use latest**
    (adopt `R`, clear dirty) and **Replace with mine** (retain `L`, update the
    expected revision to `R.revision`, then resubmit only after confirmation).
  The message remains "changed elsewhere — review and save again," and no retry is
  automatic.

### 5.3 Progressive disclosure — pricing & stock

- **Simple product** (exactly one variant, no option axes): a compact card —
  SKU, price, compare-at price (closing that gap as part of this work), and, when
  stock-tracked, current quantity (from `products.stock.index`) with an inline
  adjust control (delta + reason, as today). One "Add more variants" affordance
  — **UI-only expansion, no domain mutation**: it reveals the full variants
  table; the product *becomes* multi-variant only when a second variant is
  successfully created through the existing variant endpoint. Collapsing back
  is equally free while the product still has one variant.
- **Multi-variant product**: the full VariantsPanel table (with per-variant stock
  quantity column, now readable) directly.
- The stock adjust endpoint stays the only stock write; the new read makes the
  displayed quantity real instead of unknowable.

### 5.4 Create flow — the Omnibox Launcher (approved 2026-07-23)

**Design references (approved by the user):**
- Concept gallery (all four explored directions + comparison):
  https://claude.ai/code/artifact/f0e460bd-9006-4d05-8b73-b041d57ac866
- Interactive standalone mock of THIS design (authoritative for look & behavior):
  https://claude.ai/code/artifact/9bd944d6-28b2-4309-ba81-943a5a035169

`/commerce/products/new` renders the **Omnibox Launcher** as the create surface:
one smart input plus a four-card type row, where typing and tapping write the
same draft state. It replaces the create-mode essentials form and **stands
alone** (user decision 2026-07-23): the earlier dormant-section cards were
removed for now — the editor's sections first appear on the page the create
lands in.

**The omnibox.** A single input ("What are you selling?"). A conservative parse
lifts ONLY a clean trailing money token as the price — CURRENCY-NEUTRAL forms:
`89.99` (bare decimal), `$89`/`$89.99` ("$" kept as a generic marker), and the
TENANT's currency code as suffix or prefix (`89 GHS` / `GHS 89`,
case-insensitive) — the neutral whole-number path, and the only whole-number
path for zero-decimal currencies. Converted through the BigInt major-unit
parser with the tenant meta's currency exponent (never `Number()` float math);
everything else is the name. A bare unmarked integer stays in the name (model
numbers are names); a money token with no name is a name. A one-line hint under
the omnibox teaches these rules using the tenant's own currency code — never
"$" — for purchasable types only. Slug and SKU derive from the name; they
surface as chips, not inputs — corrections happen in the editor.

**The type row.** Four cards (Physical "shipped, stocked" · Digital "downloads"
· External "sold elsewhere" · Grouped "a bundle") — OUR type vocabulary, never
Woo's — using Lucide icons (package / download / external-link / boxes), never
emojis, with no visible number badges. Clickable, keyboard 1–4 still works
(outside inputs), aria radiogroup. The selected card morphs the surface:
- **physical/digital**: price affordance active; a missing price shows an
  honest "no price yet → $0.00 draft" chip and never blocks creation.
- **external**: the price affordance disappears; a **Link field appears and is
  required** — `metadata.external_url` (valid http/https) is API-mandatory at
  create (`CatalogService::assertExternalMetadata`). Create stays disabled
  until valid.
- **grouped**: name only; a "bundle — collect products after create" chip.

**Chips are the parse made honest**: name, formatted price, derived slug/SKU,
type-specific state — visible BEFORE anything exists.

**Retained semantics from the prior revision** (all still binding): one
page-level atomic create-draft action (never "the first save"; the button
LABEL is "Create" — user decision 2026-07-23 — with the draft promise carried
by the helper line beneath it and the "Draft created" toast); no database
row until it succeeds; type editable only here, read-only after; single-flight,
no automatic retry; `router.replace()` to the created product;
dirty-navigation-guard participation. Server validation errors retain every
entered value; slug/SKU errors map to the omnibox (there is no slug input)
plus the form banner. (The "dormant sections visible below" pin from the prior
revision was RETIRED with the launcher — see above.)

**Gap fixes shipped with this revision** (found auditing type→field effects):
1. External products were UNCREATABLE from the SPA (the form never collected
   the API-required `metadata.external_url`) — the launcher's Link field fixes
   create.
2. The editor had no surface to edit an external product's link — Details
   gains, for external products only, an "External link" fieldset editing
   `metadata.external_url` (required) + `metadata.button_label` (optional),
   merged into existing metadata (never wholesale-replacing other keys).

**Future doorway**: the muted "Already selling somewhere else? — CSV import"
card ships disabled until the slice-4 importer exists. When commerce 1.6.0
lands instant drafts, this same screen gets faster (Enter creates earlier);
it is not replaced.

### 5.4b The composed edit page (approved 2026-07-24)

**Design references (approved by the user):**
- Edit-concepts gallery (four directions + comparison):
  https://claude.ai/code/artifact/9f584b43-85c8-4cf6-ba31-78e46ff6709f
- Interactive composed recommendation (authoritative for look & behavior):
  https://claude.ai/code/artifact/07f49d95-b8ec-4faf-88c2-7783bcd2b1c5

One page, one spine, two optional panes — nothing shipped is thrown away (cards,
chips, conflict flows, rail all stay):

- **The identity bar (spine, always present)**: thumbnail (first media item,
  placeholder otherwise), product name, slug · type · formatted price line,
  status pill, and the primary action — **Activate** for drafts: a REAL
  one-click status mutation (revised 2026-07-24, superseding the scroll-shortcut
  pin — on the condensed page Details is already in view, so the shortcut read
  as "nothing happens"). It awaits the coordinator's post-mutation refresh
  (activation bumps the catalog revision) and is reversible via Details'
  Status select; storefront affordance for active products once the
  Mirror phase lands. Replaces the C4 draft banner. The bar DISPLAYS identity;
  clicking the name jumps to Details' name input rather than editing in place
  (one source of truth — no dual-bound name draft).
- **The Command Center strip (active products only)**: opens the page for
  active products, ABOVE the cards — return visits are check-ups. Phase 1 ships
  the **Health card only**, computed from already-shipped reads: image count
  (media read), category count (categories read), low-stock (stock read vs
  meta.low_stock_threshold; stock-read failure renders an honest unavailable
  row, never fabricated zeros). Each warning row deep-links (scrolls) to its
  owning section. Facts and counts only — never judgmental wording. Drafts
  never show the strip; they lead with the editor.
- **The Live Mirror (both states, toggle in the bar)**: the REAL storefront
  render beside the editor via slice 3's authenticated preview URLs, refreshed
  on save; the rail steps aside while the Mirror is open. Drafts render only
  through the authenticated preview — never public.
- Everything is fully editable in every state; edits to an ACTIVE product go
  live on each section save (existing semantics — no staging layer).

**Phasing status:**
1. Phase 1 (SPA-only): identity bar + Health card — SHIPPED 2026-07-24.
2. Phase 2: SHIPPED 2026-07-24 on commerce 1.6.0 ("Per-Product Order
   Activity": `products.orders.index` — recent orders deduped through the
   lines→variants join, projected through OrderProjection::forAdmin, plus a
   windowed product-attributed summary mirroring the products report's
   discipline). The Command Center gains the "Last N days" tile + Recent
   orders panel; both degrade to GRACEFUL ABSENCE (never an error banner)
   against an older commerce.
3. Phase 3: the Live Mirror — SHIPPED 2026-07-24, thallo-only, with two
   honesty amendments to the original sketch:
   - The frame policy lands on the PUBLIC shop product route (shop pages
     previously sent NO frame headers — frameable by anyone), as
     `ShopFrameEmbedding` middleware: `frame-ancestors 'self' <admin-origin>`
     from `render.admin_url` (origin only; unconfigured ⇒ untouched, the
     Mirror simply can't embed — fail-closed, never a wildcard). Placed BEFORE
     ShopPageCache so cache hits carry the policy too. This is a security
     HARDENING of those pages, not a loosening.
   - The storefront refuses drafts (`status === 'active'` at render), so the
     Mirror shows drafts an honest placeholder ("can't preview drafts yet —
     activate to see it live"), never a fake preview. A TRUE draft-preview
     mode (authenticated render + cache bypass) is a noted follow-on.
   The Mirror's URL is the product-link projection's server-built absolute
   `storefront_url` (always present for accessible products — zero new
   endpoints); the pane refreshes when `updated_at` moves (every
   catalog-revision claim bumps it) plus a manual refresh; the bar gains the
   Mirror toggle (all states) and View-in-store (active + URL only).

### 5.4c Condensed section cards (shipped 2026-07-24)

Closes the gap between the approved composed mock and the first shipped page:
the mock rendered section cards as one-line digests, but phases 1–3 shipped the
new spine AROUND the always-expanded editor cards. This pass makes the mock's
resting state real:

- **Cards rest collapsed** as a summary row — title, one-line digest, state
  chip — for Details / Images / Pricing & stock / Organization / Grouped
  products. Expansion is controlled by the shell; collapse hides the body with
  CSS only (`ui.body: 'hidden'`), never unmounting: panels keep their queries,
  section states, and coordinator registrations, so expanding is instant and a
  dirty draft can never be lost to a remount.
- **Digests are honest** (same discipline as the nav hints): counts, formatted
  prices, and stock quantities appear only once their read has resolved —
  otherwise a muted field roster ("categories · tags · attributes"). The
  Pricing digest never shows a fabricated stock number (stock read error ⇒
  quantity simply absent). The Images digest leads with real thumbnails.
- **Attention beats collapse**: a card holding unsaved edits, an in-flight
  save, or a failed save refuses to collapse. Organization (whose states live
  in its three subsections) gets the same rule via a shell-computed
  `force-expanded`.
- **Every jump expands first, then scrolls**: the section nav, the identity
  bar's thumb/name/Activate shortcuts, and the Health strip's deep links all
  route through one shell handler (`onNavigate`) — a raw anchor jump would
  land on a summary row.
- **The quiet tail**: Add-ons / Downloads (digital) / Linked content condense
  into ONE row ("Add-ons · Linked content …") until clicked; their cards stay
  mounted behind `v-show`. Grouped products (a stateful card) moves AHEAD of
  the tail in both card and nav order.
- **One spine, not two title bars** (2026-07-24): the dashboard navbar's title is
  the static back-context "Products" — the identity bar below owns the product's
  name; repeating it in the navbar read as two stacked headers. The Mirror
  toggle's user-visible label is **"Preview" / "Preview on"** (mock-faithful;
  internal names/data-tests keep the mirror vocabulary).
- **The rail nav** (mock-faithful): a continuous hairline down the nav's left; the
  active item is a primary accent segment + bolder text, never a filled background.
  Attention (error/unsaved) renders as a tinted pill with a filled dot on the right;
  draft-only empty hints compact to the mock's "· n" form (the item label already names
  the section; card digests carry the verbose breakdown). Small screens keep the
  horizontal chip list.
- **Major-unit money inputs** (the mock's "$700.00", not "70000 · Minor
  units"): every price field — compact pricing card, variant add/edit,
  bulk price, compare-at — takes a major-unit decimal string, hydrated via
  `minorToMajorInputString` and parsed via the BigInt
  `parseMajorAmountToMinorUnits` (both in `useMoney.ts`; no float arithmetic
  ever touches an amount). Hydration AND parsing gate on
  `/commerce/meta`'s `currency_exponent` — a fallback exponent would silently
  rescale amounts, so until meta resolves the fields stay unhydrated and
  parsing reports invalid. Help text shows the tenant currency code.

### 5.5 What gets deleted

- `ProductCreateSlideover.vue` and its specs (superseded by §5.4's create route).
- The `UTabs` product-detail layout and tab-state plumbing.
- MediaPanel's `media-unknown` alert and session `knownMedia` tracking.
- Categories/Tags/Attributes "existing assignments can't be shown" warnings and
  the edit-buffer-only payload building (replaced by server-state hydration).
- The `product-draft-callout` from `e2ecec7` (superseded by 5.4's banner).

## 6. Non-goals

- No composite "save product" mutation (pinned constraint 2).
- No storefront/checkout changes.
- No changes to Orders/Discounts/Settings/Reviews/Customers surfaces.
- No entry-editor changes (`entryEditorPanels` manifest untouched).
- Marketplace remains out of scope (thallo v1 posture).

## 7. Testing

- **Commerce**: per-endpoint integration tests (assigned/empty/cross-tenant-404/
  unknown-product-404, exact `{revision, items}` envelopes and projection
  whitelists pinned; attributes round-trip: GET `items` fed verbatim as the
  setter's `attributes` list is a no-op; attached tombstoned children are returned
  and survive a no-op replacement while a new tombstoned child is rejected;
  missing stock rows fail and surface in diagnostics), revision-guard tests per
  replacement mutation (stale `expected_revision` → 409 with state unchanged;
  matching revision → success; absent field → legacy serialize-only path; unknown
  product stays 404), catalog/fixture parity additions, OpenAPI snapshot.
- **Thallo backend**: mount parity (104), authorization matrix rows for one
  representative new read (view-scope reachable, no-permission 403).
- **SPA**: per-card hydration specs (server state renders; payload = server state
  + edit, pinned against the wipe class), section state-machine spec (phase ×
  dirty axes: failed save stays dirty; navigation blocked while saving), 409
  conflict-recovery specs (set-list three-way merge; structured/ordered conflict
  review; no automatic retry), revision-coordinator specs (clean sections advance;
  dirty baselines never advance merely because another section observed a newer
  revision; dirty sections with unchanged remote content rebase silently;
  replacement saves wait for refresh/rebase),
  dirty-registry/navigation-guard spec, progressive-disclosure branch specs
  (simple vs multi-variant vs grouped vs digital), scroll-spy nav smoke. Existing
  tab-based detail specs rewritten to the card layout.

## 8. Rollout order

1. Commerce 1.5.0: read endpoints + catalog + tests → release (user publishes).
2. Thallo backend: allowlist + parity + OpenAPI/gen:api (vendor overlay for
   pre-publish verification, as established).
3. SPA: editor restructure on the new reads.
4. Thallo repin `^1.5.0` + lock bump after publish.
