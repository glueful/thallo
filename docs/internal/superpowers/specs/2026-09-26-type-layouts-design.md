# Type Layouts — Design

**Status:** draft for review, 2026-09-26. Delivered in three releases (§10), one beta cut each.
**Builds on:** `2026-09-24-regions-stage-design.md` (the session kind, baseline and working copy,
whole-stage refresh and host pattern this reuses) and `2026-09-14-visual-builder-design.md` (the
stage, inspector, palette and history).
**Leaves in place:** the theme's page templates (`entry.twig`, `entry/{type}.twig`, `listing.twig`,
`archive.twig`, the commerce pack's `shop/*.twig`). A surface with no layout renders exactly as
today.

## 1. Goal and scope

An editor designs, on the stage, **one layout that applies to every page of a kind**: every post,
every page of the post listing, every category archive, the shop, every shop category, every
product. The layout is ordinary blocks plus **field blocks** that show the current item's data — a
post's title, date and cover; a product's gallery, price and Add to cart — and **slots** where each
item's own designed content goes.

**Surfaces** (the kinds of page a layout can describe):

| Surface | One layout per | The page | Sample on the stage |
|---|---|---|---|
| `entry` | content type | `/{type}/{slug}` (and root-mounted pages) | a published entry of the type |
| `listing` | listed content type | `/{type}[/page/n]` | page 1 of the listing |
| `archive` | listed type + archived field | `/{type}/{field}/{term}` | a term with members |
| `product` | site (commerce) | `/shop/{product}` | an active product |
| `shop_index` | site (commerce) | the shop's home | — |
| `shop_category` | site (commerce) | `/shop/category/{slug}` | a category with products |

**Out of scope:** per-entry layouts (an entry already designs its own body); layouts for the
homepage, search, cart, checkout, account pages or 404; translatable static text inside a layout
(a layout is locale-independent; field blocks show the localized item); layout versions and
history; fragment patching on the layout stage (it refreshes whole, as the Regions stage does,
§5.4). Each is a later addition that this design does not block.

## 2. Decisions

1. **One engine, several surfaces.** Storage, session, stage, save, validation and rendering are
   written once. A surface declares its sample data, its field blocks, its required blocks and its
   render frame (§3). The commerce pack contributes its three surfaces through a registry, so core
   never names commerce (pack boundaries hold).
2. **A layout is a block document, not a template.** It is validated, stored and rendered as blocks.
   A theme's templates still render every block; a layout decides only which blocks and in what
   arrangement.
3. **Field blocks read the item, they do not store it.** A Title block holds settings (heading level,
   alignment) and no text. Data always comes from the item being rendered.
4. **Save goes live, as the header and footer do.** Edits stay on the stage until **Save**, which
   applies to every page of the surface at once. **Remove layout** returns the surface to the
   theme's template. No draft or publish step (§9, question 1).
5. **The page frame is not designable.** The document head, SEO tags, canonical link, structured
   data, caching headers and the header and footer regions belong to a fixed frame template per
   surface. A layout fills the frame's body. On a product page this keeps the canonical address,
   the Product JSON-LD and cache behaviour correct however the page is designed (§7.3).
6. **Interactive commerce pieces are smart blocks.** Variant picker + Add to cart is one block with
   fixed internals; the editor places and styles it but cannot take it apart. A product layout
   without it cannot be saved.
7. **An entry's own body stays the entry's.** The layout places an **Entry content** slot; each
   entry's blocks render there. In the entry's Design view the layout renders around the body and
   only the body is editable (§6.3).
8. **Start from something.** Each surface ships a starter layout that reproduces the theme's current
   design with field blocks, so the first edit is a change, never a blank page.

## 3. Surfaces

A surface is a PHP definition registered with `LayoutSurfaceRegistry` (contract in
`thallo-contracts`, implementation in core). It supplies:

- `key` (`entry`, `listing`, …) and whether it takes a **target** (a content type slug for
  `entry` and `listing`; `{type}:{field}` for `archive`; none for the commerce surfaces).
- `targets()` — what the Layouts page lists: for `entry`, every publicly delivered content type; for
  `listing` and `archive`, the listed types and their archived reference fields
  (`type_listing` already computes these); one row for each commerce surface.
- `samples(target, query)` — the items the stage's sample picker offers, and the default one.
- `palette()` — the field blocks this surface adds (§4), on top of the general content blocks.
- `required()` — blocks a layout must contain exactly once: `entry_content` for `entry`;
  `entry_loop` for `listing` and `archive`; `product_buy` for `product`; `product_loop` for the
  shop surfaces.
- `frame()` — the frame template that renders around the layout (§7.1).
- `starter(target)` — the starter layout (§2.8).

Core registers `entry`, `listing` and `archive`. The commerce pack registers `product`,
`shop_index` and `shop_category` when its capability is on.

## 4. Field blocks

New block types in a **Fields** category, offered in the palette only on a layout stage of a
surface that lists them. Each is an ordinary block type (schema, style targets, one template); its
template reads the item from the render context — `entry` for entry surfaces, `item` inside a loop,
`product` on the product surface.

**Entry surface** (and inside an `entry_loop` card):

| Block | Shows | Settings |
|---|---|---|
| `entry_title` | the title | level (h1–h4), link to the entry (loop cards) |
| `entry_date` | the publish date | format (long, short, relative), prefix text |
| `entry_cover` | the cover image | aspect (natural, 16:9, 4:3, 1:1), link (loop cards) |
| `entry_excerpt` | the excerpt | line clamp (loop cards) |
| `entry_terms` | a reference field's terms | field, style (text, badges), link to archives |
| `entry_field` | any other scalar field | field, format (text, rich text, number, date) |
| `entry_content` | **the entry's own body blocks** (slot) | which blocks field |
| `entry_neighbours` | previous and next entry of the type | labels |
| `entry_related` | the newest other entries (as `entry/post.twig` does today) | count, card style |

A field block whose field is empty renders nothing on the site; on the stage it shows a muted
placeholder naming the field ("Cover — this post has none").

**Listing and archive surfaces:** `entry_loop` (a card grid or list: its children form the card
and render once per item, reading `item`; settings: columns, gap, style), `pagination` (previous,
next and numbered pages from the listing's own paths), `listing_title` (the type's name, or the
term's title on an archive), `term_description` (the term's description field, archives only).

**Product surface:** `product_gallery`, `product_title`, `product_price` (with compare-at),
`product_buy` (**smart**: variant picker, quantity, Add to cart — the existing add-to-cart island),
`product_description`, `product_rating`, `product_breadcrumbs`, `product_story` (slot: the linked
entry's blocks, today's `enrichment_html`), `product_related` (same category).

**Shop surfaces:** `product_loop` (the product card grid for the page, with the shop's existing
card template), `pagination`, `category_title`, `category_nav` (the category list).

## 5. Server

### 5.1 Storage

Table `layouts` (migration, tenant-owned via `ThalloTenantTables`):
`id`, `tenant_uuid`, `surface`, `target` (nullable), `blocks` (json), `settings` (json — the
frame's options: width and whether to show the header and footer), `lock_version`, `updated_by`,
`created_at`, `updated_at`; unique `(tenant_uuid, surface, target)`.
`LayoutRepository` reads and writes it; `LayoutResolver::for(surface, target)` answers the saved
layout or null, cached by `lock_version`.

### 5.2 Session

A third preview kind, alongside entries and regions (§4.1 of the regions spec):
`POST /v1/admin/layouts/preview/session` — body `{surface, target, sample?}` → `{token, expires_at,
theme_url, epoch: null, revision: null, layout: {blocks, settings, lock_version|null}, samples,
sample}`. The token is `LayoutPreviewToken` with claims `{k: "layout", s, surface, t, sample, l,
exp}`. `PreviewSession` gains `KIND_LAYOUT`; every consumer that reads entry or regions sessions
refuses it. Minting stores the session's **baseline**: the saved layout, or the surface's starter
when there is none.

### 5.3 Working copy and apply

The regions pattern, parameterized: `thallo:preview:layout:baseline:{s}` and
`thallo:preview:working:layout:{s}`, both expiring at the token's `exp`.
`POST /v1/admin/layouts/preview/apply` — `{token, layout, epoch, base_revision, operations}` →
`{epoch, revision, baseline, style_generation, applied_at, fragments: null}`. Validation is
`LayoutValidator` (§5.6). Payload cap 1 MB.

### 5.4 Render on the stage

`/_preview/{token}` gains a `layoutStage()` branch beside `regionsStage()`: it resolves the sample
through the same resolver the public page uses (a published entry, a listing page, a product),
installs a `LayoutSessionReader` override so `LayoutResolver` answers the working copy, and renders
the surface's frame. Annotation scope `layout` (a fourth value for `setAnnotationScope`): the
layout's blocks carry `data-thallo-block` and slots; blocks rendered **inside** `entry_content`,
`product_story` and loop cards do not (they are the sample's content, not the layout's). No
fragments: every accepted apply refreshes the stage. An expired session renders the "editing
session expired" page, as regions.

### 5.5 Save and remove

`PUT /v1/admin/layouts/{surface}/{target?}` — `{layout, expected_lock_version, preview_revision}` →
the saved layout. Compare-and-set on `lock_version` (a stale save answers 409 with the current
version; the admin offers reload or overwrite, as regions). On success the session's working copy
is cleared if its pair still matches, the baseline becomes the saved layout, and caches are purged
(§7.4). `DELETE /v1/admin/layouts/{surface}/{target?}` removes the layout (the surface returns to
the theme's template). Permission for save and delete: `templates.manage`; session and apply
`content.view` and `templates.manage`.

### 5.6 Validation

`LayoutValidator`: the blocks validate as a page save would (`FieldValidator`); every root block is
in the surface's palette plus the general content blocks; each `required()` block appears exactly
once, and never nested inside a loop; field blocks name fields that exist on the target type with a
compatible type (`entry_terms` a reference field, `entry_field` a scalar); loop children use only
item-scoped field blocks. Errors name the block and field (`blocks.3.data.field`).

## 6. Admin

### 6.1 The Layouts page

**Site › Layouts** lists each surface target with its state — **Theme template** or **Custom
layout** (with who saved it and when) — and **Edit** and, for custom ones, **Remove** (asks first:
"Every post goes back to the theme's design"). Commerce rows appear when the commerce capability is
on. A type that is not listed shows its listing and archive rows disabled, with a link to
**Settings › General**.

### 6.2 The layout editor

`admin/src/pages/layouts/[surface]/[[target]].vue`, a stage page built like the Header & footer page
on `useStageEditor` with a `useLayoutHost` (a synthetic schema of one root `blocks` field whose
`blockTypes` is the surface palette, as `useRegionHost` does for regions).

- **Top bar:** the surface's name ("Posts — single post"); a **sample** picker (which post, product,
  category or page number to preview); Desktop / Tablet / Mobile; Undo / Redo; **Save**; a menu with
  **Reset to starter** and **Remove layout**.
- **Inspector:** Block, Blocks (the Fields category first, then the general blocks), **Frame** (the
  frame's width and header/footer options), Outline.
- A required block cannot be deleted from the stage or Outline (the delete action explains why);
  Save stays disabled with the reason while the layout is invalid.
- Leaving with unsaved edits asks first (the app's guard, as regions).

### 6.3 The entry's Design view

When an entry's type has a saved layout, the entry's stage renders the page through it:
`/_preview/{token}?canvas=1` for an entry session renders the layout frame, with annotation on
**only** the blocks inside `entry_content` (the entry's own body) — the layout's blocks are context,
inert, as a region session makes the page body inert. The Design view shows a strip above the stage:
"This post uses the Posts layout · **Edit layout**". The entry's page settings keep header, footer
and width; **Show page title** is replaced by the note that the layout places the title.
Fragment patching keeps working for the body: the verification record gains the layout frame
templates (§8).

### 6.4 Opting out

An entry can use the theme's template instead of its type's layout: **Page › Layout: Type layout |
Theme template** (a `use_layout` key in `_presentation`, default on). For the rare post that
needs to break the mould.

## 7. Rendering

### 7.1 Frames

A frame is a small theme template per surface — `layouts/entry.twig`, `layouts/listing.twig`,
`layouts/archive.twig` in the default theme; `layouts/product.twig`, `layouts/shop_index.twig`,
`layouts/shop_category.twig` in the commerce pack. Each extends `layout.twig`, emits what the page
must always have (title, SEO head, canonical, structured data, the add-to-cart script, noindex rules
already in force), and renders `{{ blocks(layout.blocks) }}` in the content block. Themes may
override a frame by file, as any template.

### 7.2 Selection

Before choosing a template, the renderer asks `LayoutResolver` for the surface and target. With a
layout (and, for entries, `use_layout` not off): render the surface's frame with `layout` in
context. Without one: the existing template hierarchy, unchanged. The listing and archive surfaces
pass their `items` and `pagination` to the frame; `entry_loop` renders one card per item and
`pagination` reads the ready paths.

### 7.3 Commerce

`ShopPageRenderer` gains the same selection for `product`, `shop_index` and `shop_category`. The
frame keeps everything `shop/product.twig` must guarantee today: `<link rel=canonical>` from
`ShopUrlGenerator`, the Product JSON-LD, the shop stylesheet and script, and the server-built
`AddToCartViewModel` behind `product_buy`'s no-JS form. `product_story` renders the existing
`enrichment_html` and keeps its entry cache tag.

### 7.4 Caching

Every page rendered through a layout carries `Cache-Tag: thallo:layout:{surface}:{target|site}`.
Saving or removing a layout purges that tag (render cache) and, for commerce surfaces, the shop page
cache (a new `LayoutChange` listener beside `ThemeChange`). A theme change already purges both.

## 8. Testing

- **Validation:** each surface's required blocks (missing, twice, nested in a loop), palettes, field
  references to missing or wrong-typed fields, loop children.
- **Session and apply:** token kind isolation (a layout token opens no entry or regions data and
  vice versa), baseline from the starter, compare-and-set, expiry.
- **Save:** lock version conflict, purge tags, remove returns the theme template.
- **Rendering:** each surface with and without a layout; field blocks with missing data; `use_layout`
  off; locale fallback of field data; the product frame's canonical and JSON-LD unchanged under a
  redesigned layout; `product_buy` form works without JavaScript.
- **Stage:** annotation marks layout blocks only on the layout stage and body blocks only on the
  entry stage.
- **Fragment verification:** fixtures rendered through `layouts/entry.twig` byte-match block by
  block, so an entry with a layout keeps in-place patching.
- **Browser proofs:** a layout edited on its stage (select, move, drag a field block in, save); an
  entry with a layout edited in the Design view with the layout inert around its body.

## 9. Questions for review

1. **Save goes live** (recommended, as the header and footer) — or a draft with **Publish**? A draft
   doubles the storage and adds a publish step; the stage already gives a private preview.
2. **Permission:** `templates.manage` (recommended: a layout is the site's design, as templates are)
   — or `content.manage`?
3. **Opting out per entry** (§6.4): keep it, or leave it for later?
4. **Order of the commerce surfaces:** product page before the shop index and categories
   (recommended — it is what shops most want to shape), or all three together?

## 10. Rollout

1. **Release A — engine and single entries.** Storage, the `layout` session kind, the layout stage
   and editor, save and remove, the validator, frames and selection, caching, the Layouts page, the
   entry field blocks, `entry_content`, the entry Design view integration, `use_layout`, starter
   layouts for posts and pages. After this, "design your own post page" is real.
2. **Release B — listings and archives.** `entry_loop`, `pagination`, `listing_title`,
   `term_description`; the `listing` and `archive` surfaces and their starters. This also retires
   the Blog posts block's twelve-post ceiling for anyone who designs a listing.
3. **Release C — commerce.** The registry contributions from the commerce pack: `product` with its
   smart and field blocks, then `shop_index` and `shop_category` with `product_loop`; the shop page
   cache purge.

Each release ships its migrations, docs (a "Design a layout" guide; the block reference) and
changelog entries, and passes the full gates before its beta cut.
