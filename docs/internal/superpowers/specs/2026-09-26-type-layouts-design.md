# Type Layouts — Design

**Status:** revised after review, 2026-09-26; awaiting approval. Delivered in four releases
(§11), one beta cut each.
**Revision 1 (review):** the save and remove lifecycle is session-bound and conditional, and a
conflict offers Reload only (§5.5); site-wide layouts have a non-null target (§5.1); caching covers
the first save and the resolver's "no layout" answer (§7.4); layouts join the block-document
lifecycle and survive content-model changes (§5.7); the entry-content contract, slot cardinality
and depth boundary are defined (§4.1); loop cards have one editable representative (§5.4);
entries with a custom layout refresh the stage whole (§6.3); frame options and `_presentation`
have a precedence (§6.4); the homepage stays out (§1). The four open questions are decided (§9).
**Builds on:** `2026-09-24-regions-stage-design.md` — the session kind, baseline and working copy,
whole-stage refresh, host pattern, **its Save contract and its sample-switch and renewal
sequences**, which this spec inherits unless it says otherwise — and
`2026-09-14-visual-builder-design.md` (the stage, inspector, palette and history).
**Leaves in place:** the theme's page templates (`entry.twig`, `entry/{type}.twig`, `listing.twig`,
`archive.twig`, `index.twig`, the commerce pack's `shop/*.twig`). A surface with no layout renders
exactly as today.

## 1. Goal and scope

An editor designs, on the stage, **one layout that applies to every page of a kind**: every post,
every page of the post listing, every category archive, every product, the shop, every shop
category. The layout is ordinary blocks plus **field blocks** that show the current item's data
and **slots** where each item's own designed content goes.

**Surfaces:**

| Surface | One layout per | The page | Sample on the stage |
|---|---|---|---|
| `entry` | content type | `/{type}/{slug}` and root-mounted pages | a published entry of the type |
| `listing` | listed content type | `/{type}[/page/n]` | page 1 of the listing |
| `archive` | listed type + archived field | `/{type}/{field}/{term}` | a term with members |
| `product` | site (commerce) | the product page | an active product |
| `shop_index` | site (commerce) | the shop's home | — |
| `shop_category` | site (commerce) | a shop category | a category with products |

**Out of scope:** the homepage — an entry-backed homepage keeps rendering `index.twig`, never a
layout, as it never renders `entry.twig` today; per-entry layouts; search, cart, checkout, account
pages and 404; translatable static text inside a layout (a layout is locale-independent; field
blocks show the localized item); layout versions and history; fragment patching on the layout
stage and on the stage of an entry that uses a custom layout (both refresh whole, §5.4, §6.3).

## 2. Decisions

1. **One engine, several surfaces.** Storage, session, stage, save, validation and rendering are
   written once. A surface declares its sample data, field blocks, required blocks and render frame
   (§3). The commerce pack contributes its surfaces through a registry; core never names commerce.
2. **A layout is a block document, not a template.** It is validated, stored, migrated and rendered
   as blocks, and it is a source of the block-document walkers like any other (§5.7).
3. **Field blocks read the item, they do not store it.** A Title block holds settings (level,
   alignment) and no text.
4. **Save goes live, as the header and footer do.** Edits stay private on the stage until **Save**,
   which applies to every page of the surface; the Save button says so ("Applies to every post").
   **Remove layout** returns the surface to the theme's template. Optimistic locking guards both.
5. **Permission: `templates.manage`.** A layout is presentation, as templates are.
   `content.manage` means "Manage content models" and is not used.
6. **The page frame is not designable.** Head, SEO tags, canonical link, structured data, caching
   headers and the header and footer regions belong to a fixed frame template per surface (§7.1).
7. **Interactive commerce pieces are smart blocks.** Variant picker + Add to cart is one block with
   fixed internals; a product layout without it cannot be saved.
8. **An entry's own body stays the entry's.** The layout places slots for the entry's content
   (§4.1); in the entry's Design view the layout is inert context around them (§6.3).
9. **An entry can opt out** of its type's layout (§6.5), the escape hatch that keeps the existing
   template path in reach.
10. **Start from something.** Each surface ships a starter layout reproducing the theme's current
   design with field blocks.

## 3. Surfaces

A surface is a definition registered with `LayoutSurfaceRegistry` (contract in `thallo-contracts`,
implementation in core). It supplies:

- `key`, and its **target** kind: a content type slug (`entry`, `listing`), `{type}:{field}`
  (`archive`), or the constant `@site` (commerce surfaces) — never null (§5.1).
- `targets()` — the rows the Layouts page lists: for `entry`, every publicly delivered content type
  that §4.1 supports; for `listing` and `archive`, the listed types and archived reference fields
  (`type_listing` computes these); one row per commerce surface.
- `samples(target, query)` — the items the sample picker offers, and the default.
- `palette()` — the field blocks this surface adds.
- `required()` — blocks that must appear exactly once: for `entry`, one slot per the type's
  primary body (§4.1); `entry_loop` for `listing` and `archive`; `product_buy` for `product`;
  `product_loop` for the shop surfaces.
- `frame()` — the frame template (§7.1), and `starter(target)` — the starter layout.

Core registers `entry`, `listing`, `archive`. The commerce pack registers `product`, `shop_index`,
`shop_category` while its capability is on.

## 4. Field blocks

Layout-only block types in a **Fields** category. Each reads the item from the render context —
`entry` on an entry surface, `item` inside a loop card, `product` on the product surface.

**Entry surface** (and, where marked, inside an `entry_loop` card):

| Block | Shows | Settings | In a card |
|---|---|---|---|
| `entry_title` | the title | level (h1–h4), link to the entry | yes |
| `entry_date` | the publish date | format (long, short, relative), prefix | yes |
| `entry_cover` | an asset field's image | field, aspect, link | yes |
| `entry_excerpt` | a text field | field, line clamp | yes |
| `entry_terms` | a reference field's terms | field, style (text, badges), link to archives | yes |
| `entry_field` | any other scalar field | field, format (text, rich text, number, date) | yes |
| `entry_content` | **the entry's own content** (slot, §4.1) | field | no |
| `entry_neighbours` | previous and next entry | labels | no |
| `entry_related` | the newest other entries | count, card style | no |

An empty field renders nothing on the site; on the stage a muted placeholder names it ("Cover —
this post has none").

### 4.1 The entry-content contract

What a type's own content is, and how many slots a layout gives it:

- **Blocks fields.** Each blocks-typed field of the target type may be placed at most once, by an
  `entry_content` block naming it. The type's **primary body** — the field named `body` when it is
  blocks-typed, otherwise the first blocks field in schema order — must be placed exactly once;
  other blocks fields are optional. A blocks field left unplaced is not rendered, and the Layouts
  editor lists it under "Not shown by this layout".
- **Scalar rich-text body.** A type whose primary content is a rich text field is placed with
  `entry_field` (format rich text); it has no slot and nothing to edit on the stage.
- **No body at all.** A type with neither can still have a layout built of field blocks; it has no
  required slot.
- **Supported targets:** every publicly delivered type except the one serving the homepage entry
  (§1). Types whose frame the theme overrides with `entry/{type}.twig` are supported; the layout
  wins while it exists (§7.2).
- **Depth boundary.** Content in a slot is measured from the slot, not from the layout: the
  renderer resets the nesting depth to zero at an `entry_content` (and at `product_story`), and
  restores the layout's depth after it. An entry valid on its own at the maximum depth renders
  whole inside any layout. The layout's own tree is capped as any document is. (Today one depth
  counter runs through a render; the reset is new, and pinned by a proof, §8.)
- **Root mapping on the entry stage.** The slot's blocks keep the entry's own field name as their
  root list (`body`), so the stage editor, history and save address the entry exactly as today;
  the layout contributes markup around them, never a root.

## 5. Server

### 5.1 Storage

Table `layouts` (tenant-owned via `ThalloTenantTables`): `id`, `tenant_uuid`, `surface`, `target`
(**non-null**: a type slug, `{type}:{field}`, or `@site`), `blocks` (json), `settings` (json — the
frame options, §6.4), `lock_version` (int), `updated_by`, timestamps.

Uniqueness: a unique index on `(COALESCE(tenant_uuid, ''), surface, target)`, so neither a null
tenant (single-site installs before tenancy widening) nor a site-wide surface admits two rows.
**`lock_version` never goes backwards:** removing a layout keeps its row with `blocks = null` and a
bumped version (a tombstone), so a later save that recreates it continues from the tombstone's
version and an editor holding an old version cannot write over it. A missing row is version 0.

### 5.2 Session

A third preview kind: `POST /v1/admin/layouts/preview/session` — `{surface, target, sample?}` →
`{token, expires_at, theme_url, epoch: null, revision: null, layout: {blocks, settings,
lock_version}, samples, sample}`. `LayoutPreviewToken`: `{k: "layout", s, surface, t, sample, l,
exp}`. `PreviewSession` gains `KIND_LAYOUT`; entry and regions consumers refuse it. The session's
**baseline** is the saved layout, or the starter when there is none, with the `lock_version` read
at mint (0 for none, the tombstone's for a removed one).

### 5.3 Working copy and apply

The regions records, parameterized: `thallo:preview:layout:baseline:{s}` and
`thallo:preview:working:layout:{s}`, expiring at the token's `exp`.
`POST /v1/admin/layouts/preview/apply` — `{token, layout, epoch, base_revision, operations}` →
`{epoch, revision, baseline, style_generation, applied_at, fragments: null}`. Validation §5.6.

### 5.4 Render on the stage

`/_preview/{token}` gains `layoutStage()` beside `regionsStage()`: it resolves the sample through
the public resolver, installs a `LayoutSessionReader` so `LayoutResolver` answers the working copy,
and renders the surface's frame. Annotation scope `layout`:

- The layout's own blocks carry `data-thallo-block` and their slots.
- **Slot content is the sample's, not the layout's:** blocks rendered inside `entry_content` and
  `product_story` carry no annotation and no drop zones.
- **Loop cards:** an `entry_loop`'s children are the layout's — the card template. The **first**
  card is rendered with annotation (its blocks are the authored children, with their real ids); the
  other cards render the same children for their items **without** annotation, so no id appears
  twice. An empty sample renders one placeholder card with annotation so the template stays
  editable. Field blocks inside the card read `item`.

No fragments: every accepted apply refreshes the stage whole.

### 5.5 Save and remove

The Regions Save contract, applied to one document:

`PUT /v1/admin/layouts/{surface}/{target}` — `{token, layout, expected_lock_version,
preview_revision}`:

1. Verify a `layout` token for this surface and target; its session is the one Save advances.
2. Validate (§5.6). Under a per-`(tenant, surface, target)` lock, compare `expected_lock_version`
   with the stored version (0 for no row, the tombstone's for a removed layout). A mismatch writes
   nothing and answers 409 `LAYOUT_VERSION_CONFLICT` with the current version. **Conditional
   creation:** expected 0 creates the row only if none exists; two concurrent first saves resolve
   to one creation and one 409.
3. Write the layout with `lock_version + 1`.
4. Clear the session's working copy **only if** its `(epoch, revision)` still equals
   `preview_revision`; otherwise leave it (edits made after the save stay pending).
5. Make the saved layout the session's baseline with the new version, and purge caches (§7.4).
6. Answer `{layout, lock_version, preview_cleared}`.

`DELETE /v1/admin/layouts/{surface}/{target}` — `{token, expected_lock_version}`: the same token
check, lock and comparison; on a match the row becomes a tombstone with the version bumped, the
session's baseline becomes the starter at that version, and caches are purged.

**Admin side, as regions:** Save marks saved **only the history position it submitted**; later
edits stay dirty. On 409 the page shows "Changed by someone else" with **Reload**, which discards
unsaved edits and re-mints — there is no overwrite. Switching the sample and renewing an expired
session follow the Regions spec's restore sequences (mint, re-apply the unsaved document with a
null pair, keep the saved version), so unsaved edits survive a sample switch and an expiry.

### 5.6 Validation

`LayoutValidator`, server-side, on apply and save:

- The blocks validate as a page save would (`FieldValidator`), including style classes through
  `StyleClassReferenceGuard` (archived or job-locked classes refused).
- Every block anywhere in the tree — root, containers, loop cards — is allowed in its context: the
  general content blocks; the surface's field blocks; **item-scoped** field blocks only inside an
  `entry_loop` or `product_loop` card; slot blocks never inside a card.
- `required()` blocks appear exactly once and never inside a loop card; a blocks field is placed by
  at most one `entry_content`.
- Field references exist on the target type with a compatible type (`entry_terms` a reference field,
  `entry_cover` an asset field, `entry_field` a scalar, `entry_content` a blocks field).
- **Layout-only blocks are refused everywhere else:** entry saves, region saves and saved sections
  reject any Fields-category block in their trees (`FieldValidator` knows the layout-only types), so
  palette filtering is not the only guard.

Errors name the block and field (`blocks.3.data.field`).

### 5.7 The block-document lifecycle

Layouts are stored block documents and join every walker:

- **`LayoutsSource`** implements `BlockDocumentSource`: one document per non-tombstone layout
  (`{blocks}`), revision = `lock_version`, persist conditional on it and bumping it, then purging the
  layout's surface tag (§7.4). The block migration backfill, style-class usage (a `layouts` count),
  and detach- and remove-everywhere jobs select it explicitly, as they select regions and saved
  sections.
- **Content-model changes:** renaming a content type rewrites the `target` of its layouts (and their
  `{type}:{field}` archive targets); deleting a type tombstones its layouts. Renaming a field
  rewrites field bindings that name it; **deleting** a field a layout binds is refused by the
  content-type save with the layouts that use it named, so a binding never breaks silently. Should
  one break anyway (a raw import), the field block renders nothing on the site and a "field missing"
  placeholder on the stage, and validation flags the layout on its next save.

## 6. Admin

### 6.1 The Layouts page

**Site › Layouts** lists each surface target: **Theme template** or **Custom layout** (who saved it,
when), with **Edit** and, for custom ones, **Remove** ("Every post goes back to the theme's
design"). Commerce rows appear while its capability is on. An unlisted type's listing and archive
rows are disabled with a link to **Settings › General**.

### 6.2 The layout editor

`admin/src/pages/layouts/[surface]/[target].vue`, built like the Header & footer page on
`useStageEditor` with a `useLayoutHost` (a synthetic schema of one root `blocks` field whose
`blockTypes` is the surface palette).

- **Top bar:** the surface's name ("Posts — single post"); the sample picker; widths; Undo / Redo;
  **Save** with its reach beside it ("Applies to every post"); a menu with **Reset to starter** and
  **Remove layout**.
- **Inspector:** Block, Blocks (Fields first), **Frame** (§6.4), Outline.
- Required blocks cannot be deleted (the action says why); Save is disabled with the reason while
  the layout is invalid; leaving with unsaved edits asks first.

### 6.3 The entry's Design view

When the entry's type has a custom layout (and the entry does not opt out, §6.5), the entry's stage
renders through it: annotation on **only** the blocks inside its `entry_content` slots, the layout
inert around them. A strip above the stage reads "This post uses the Posts layout · **Edit
layout**". **The stage refreshes whole** for such an entry: fragment answers are off while a custom
layout applies, because layout blocks can change what the body renders (a cover claims the priority
image before the body does). Entries without a layout keep fragment patching exactly as today.
Fragment eligibility under a layout is a later addition, with composed-layout proofs.

### 6.4 Frame options and `_presentation`

A layout's **Frame** options set the page's width (contained, full) and whether the header and
footer show. An entry's own page settings (`_presentation`) win over the layout's where the entry
sets them — an entry that hides its footer hides it under any layout. **Show page title** does not
apply under a layout (the layout places the title); the page settings show why instead of the
switch.

### 6.5 Opting out

**Page › Layout: Type layout | Theme template** (`_presentation.use_layout`, default on). Off, the
entry renders through the theme's template as if its type had no layout, and its stage patches
fragments as today.

## 7. Rendering

### 7.1 Frames

One small template per surface — `layouts/entry.twig`, `layouts/listing.twig`,
`layouts/archive.twig` in the default theme; `layouts/product.twig`, `layouts/shop_index.twig`,
`layouts/shop_category.twig` in the commerce pack. Each extends `layout.twig`, emits what the page
must always have (title, SEO head, canonical, structured data, the add-to-cart script), and renders
`{{ blocks(layout.blocks) }}` in the content block. Themes may override a frame by file.

### 7.2 Selection

The renderer asks `LayoutResolver` for the surface and target before choosing a template. With a
layout (and, for entries, `use_layout` on): render the frame with `layout` in context, whatever
`entry/{type}.twig` the theme ships. Without one: the existing hierarchy, unchanged. The homepage is
never asked (§1).

### 7.3 Commerce

`ShopPageRenderer` gains the same selection for its three surfaces. The product frame keeps what
`shop/product.twig` guarantees: canonical from `ShopUrlGenerator`, the Product JSON-LD, the shop
stylesheet and script, and the server-built `AddToCartViewModel` behind `product_buy`'s no-JS form.
`product_story` renders the existing `enrichment_html` and keeps its entry cache tag.

### 7.4 Caching

- **Every eligible page carries its surface tag**, whether or not a layout exists:
  `thallo:layout:{surface}:{target}` on every entry page of a supported type, every listing and
  archive page of a listed type, and every commerce page of the three surfaces. So the **first**
  save purges pages cached while they rendered through the theme's template, and removal purges
  pages that rendered through the layout.
- `LayoutResolver` caches its answer — a layout or "none" — keyed by `(tenant, surface, target)` in
  the application cache; save, remove, a `LayoutsSource` persist and a content-type rename or
  delete **delete that key** (they write through the resolver), so a new version or a first layout
  is found on the next render, not after a TTL.
- Commerce: a `LayoutChange` listener purges the shop page cache beside `ThemeChange`.

## 8. Testing

- **Lifecycle:** first save creates; concurrent first saves → one 201, one 409; save with a stale
  version → 409, nothing written; remove with a stale version → 409; remove then save continues
  the version (no reset); working copy cleared only on the exact pair; Save marks only its submitted
  position; sample switch and renewal keep unsaved edits and the saved version.
- **Uniqueness:** two site-wide rows and two null-tenant rows refused.
- **Caching:** theme page cached → first save → the next request renders the layout → edit → the
  change shows → remove → the theme page returns; the resolver's cached "none" is replaced on save.
- **Documents:** a block type migration rewrites layouts; style-class usage counts them; remove-
  everywhere cleans them; content type rename retargets, field rename rebinds, field delete is
  refused naming the layout.
- **Validation:** required blocks (missing, twice, inside a card); item-scoped blocks outside a
  card; field bindings missing or wrong-typed; a Fields block refused in an entry save, a region
  save and a saved section.
- **Composition:** an entry already at maximum depth inside a layout nested several containers
  deep renders whole; a type with two blocks fields, one with a rich-text body, one with none.
- **Rendering:** each surface with and without a layout; `use_layout` off; `_presentation` over
  frame options; the product frame's canonical and JSON-LD unchanged under a redesigned layout;
  `product_buy` works without JavaScript; the homepage entry never renders a layout.
- **Stage:** layout stage annotates layout blocks and the first loop card only, never slot content,
  and no id repeats; entry stage under a layout annotates slot content only and answers no fragments.
- **Browser proofs:** a layout edited on its stage (select, move, drag a field block in, save,
  conflict → Reload); an entry with a layout edited in the Design view.

## 9. Decisions on the review questions

1. **Save goes live**, with its reach shown beside Save, the private preview and optimistic locking.
2. **`templates.manage`** for session, apply, save and remove (plus `content.view` to open).
3. **The per-entry opt-out stays** (§6.5).
4. **Commerce: product first** (release C1), shop home and categories after (C2).

## 10. Before the first release

The saved-sections gap found in review is fixed on its own first: saved sections were not a
block-document source, so block migrations and style-class jobs skipped them. That fix (a
`SavedSectionsSource`, a version column, and the reference guard on save) is the model
`LayoutsSource` follows.

## 11. Rollout

1. **A — engine and single entries.** Storage and tombstones, the `layout` session, the layout
   stage and editor, save and remove with the full lifecycle, the validator including the
   everywhere-else refusal, `LayoutsSource` and content-model handling, frames and selection,
   caching with surface tags, the Layouts page, entry field blocks and `entry_content` with the
   depth reset, the entry Design view (whole refresh), `use_layout`, starters for posts and pages.
2. **B — listings and archives.** `entry_loop` with its representative card, `pagination`,
   `listing_title`, `term_description`; the `listing` and `archive` surfaces and starters.
3. **C1 — product page.** The commerce registry contributions for `product`: its field and smart
   blocks, the protected frame, the shop page cache purge.
4. **C2 — shop home and categories.** `shop_index` and `shop_category` with `product_loop`.

Each release ships its migrations, docs (a "Design a layout" guide; the block reference) and
changelog entries, and passes the full gates before its beta cut.
