# Sections and Templates for Layouts and Shop Pages — Design

> Status: approved in conversation 2026-09-29 (all five sections). Builds on
> `2026-09-26-type-layouts-design.md` (type layouts, Releases A–C2, shipped by beta.70) and the
> pattern library (`core/src/Content/Patterns/`).

## 1. Goal and scope

Two things an editor cannot do today:

1. **Use sections and templates in the layout editor.** The page Design view has a Blocks /
   Sections / Templates palette; the layout editor (Site › Layouts) shows blocks only. An editor
   designing every post, listing, archive, product, the shop home or the shop categories should get
   ready-made sections and whole ready-made layouts for that kind of page, and be able to save
   their own sections — field blocks included — and reuse them in other layouts of the same kind.
2. **Build shop pages from ready-made parts.** With Commerce on, the page library should offer shop
   landing pages and commerce sections built from the existing commerce page blocks (Product grid,
   Featured product, Add to cart, Mini cart, Wishlist link); with Commerce off, none of them shows.

**Decisions made in review:**

| Question | Decision |
|---|---|
| Saved sections in layouts | Shipped **and** saved: "Save as section" in a layout keeps field blocks; offered back only in layouts of the same surface |
| Picking a layout template | **Replaces the whole layout** (like Header & footer templates), asking first when there are unsaved changes |
| Shop page templates | Shop landing · Product launch · Sale / collection · New arrivals |
| Architecture | **One pattern library** gaining a layout-surface place; packs contribute patterns |
| Card designs (swap only a loop's card) | Out of scope for now — every listing/archive/shop template ships its own card |
| Releases | Two: S1 shop pages, then S2 layout sections and templates |

**Out of scope:** card designs as their own templates; new block types; patterns provided by
themes; thumbnails for saved sections (they keep the plain card, as today); a template picker when
creating a page.

## 2. What exists today

- **The pattern library.** `StarterPatterns` ships page sections, five page templates
  (`page-landing`, `page-about`, `page-pricing`, `page-contact`, `page-services`), header/footer
  sections and five header/footer templates. `PatternLibrary::all()` resolves each over
  `BlockFactory`'s canonical instance of its types, hides a pattern using a type the site cannot use
  (a template is offered whole or not at all), and appends saved sections. Served by
  `GET /patterns` (`content.view`).
- **A pattern's place** is `scope` (`page` | `region`) plus `region` (`header` | `footer`); a pattern
  is a `section` (one block tree) or a `page` (a template: section slugs in order).
- **Saved sections** (`saved_sections`, `SavedSectionRepository`, `SavedSectionsSource` for block
  migrations and style-class jobs) are validated on save by `FieldValidator` over a one-field
  `blocks` schema — which refuses layout-only field blocks — and written under `content.manage`.
- **The Design view** filters patterns to `scope: page`; a template's sections are inserted at the
  insert point as one transaction. **Header & footer** replaces the region with a template.
- **The layout editor** (`admin/src/pages/layouts/[surface]/[target].vue`) mounts `BlocksPalette`
  with no `patterns` and no `pageClickable`: blocks only, Fields first. "Save as section" is shown
  but refuses any tree with field blocks. `LayoutValidator` enforces each surface's palette, card
  legality, bindings and required blocks on apply and save.
- **Commerce** registers its block types always (each gated by `requiresCapability:
  thallo.commerce`) and its starters and layout surfaces only while `thallo.commerce` is enabled.
  No pattern uses a commerce block.

## 3. Where a pattern belongs, and where patterns come from

**Place.** A pattern's place gains a third kind: `layout`, with a `surface` (`entry`, `listing`,
`archive`, `product`, `shop_index`, `shop_category`). Kinds stay `section` and `page` (template).
The client's `belongsIn` and `SectionPlace` gain `{ scope: 'layout', surface }`.

**Sources.**

- **Core** ships page and header/footer patterns (`StarterPatterns`, unchanged) and the `entry`,
  `listing` and `archive` layout patterns in a new `LayoutPatterns` class beside it.
- **Packs** contribute through a new **pattern contributor registry** (contract in
  `thallo-contracts`, implementation in core), push-registered from a pack's provider exactly like
  `StarterContributorRegistry` and the block-type contributors. A contributor returns sections and
  templates, each with its place.
- **Commerce** registers one contributor **only while `thallo.commerce` is enabled** (beside
  `registerStarterContributor` and `registerLayoutSurface`). It provides the `product`,
  `shop_index` and `shop_category` layout patterns and the shop page sections and templates.

**Filtering (server, `PatternLibrary`).** In addition to today's rule (a pattern using a type the
site cannot use is hidden):

- a layout pattern whose surface is not registered is hidden;
- a **layout template** is offered only if it passes its surface's real `LayoutValidator` rules —
  palette, card legality, its required block exactly once, bindings valid for the surface's
  defaults — so a template that stops fitting after a block change disappears rather than failing
  when chosen;
- a **layout section** is offered only if its blocks are in its surface's palette (or general) and
  its card blocks sit inside their loop's card.

`GET /patterns` returns each pattern with its place (`scope`, `region`, `surface`).

## 4. The layout editor's Sections and Templates

The layout editor passes the library to `BlocksPalette` filtered to its surface, so it gets the same
Blocks / Sections / Templates switch as the Design view.

- **Sections** lists, in this order: the shipped sections of this surface, general page sections
  (hero, FAQ, CTA, newsletter…), and saved sections of this surface. Other surfaces' and
  header/footer sections never appear.
- **Inserting a section** works as in the Design view — click, drag, or Enter at the insert point,
  fresh ids (`instantiate`) — and is preflighted with `checkInsertSubtree` under the layout's
  `legalityContext()`: a section holding the Product list cannot go into a layout that already has
  one; card-only blocks only where legal. A section that fails says "That section does not fit
  here". `LayoutValidator` re-checks on apply and save as today.
- **Templates** lists this surface's shipped templates. Choosing one **replaces the whole working
  copy** — blocks and frame options (`_presentation`: width, page title). When the working copy
  differs from what is saved (or from the starter when nothing is saved) it asks first: "Replace
  this layout with *Magazine post*? Your unsaved changes will be lost." It is one undoable step;
  nothing goes live until Save.
- **"Save as section"** in a layout saves with the place `{ scope: 'layout', surface }` (§5). A
  block inside a loop's card does not offer it (card designs are out of scope).
- **The page Design view** is unchanged, except that its Sections and Templates now include the
  commerce page patterns when Commerce is on.

## 5. Saved sections in layouts

- **Storage.** `saved_sections` gains a nullable `surface` column; the new scope value `layout`
  requires it. One core migration; existing rows are untouched. The table stays tenant-owned.
- **Validation on save.** A layout section is validated with its surface's rules instead of the
  page rules: the site's general blocks plus the surface's palette, `FieldValidator::forLayouts()`,
  and card blocks only inside their loop's card. It may hold the surface's required block; the
  insert-time check keeps it from being placed twice.
- **Where it is offered.** In every layout of its surface. For `entry`, `listing` and `archive`
  (one layout per content type) a section saved from one type may be inserted into another type's
  layout. A field block bound to a field the target type lacks refuses the insert and names the
  field ("This section shows *Subtitle*, which Pages don't have"); blocks on built-in fields
  (title, date, cover, the primary content) fit every type. The server re-checks bindings on apply.
- **Permissions.** Saving, renaming and deleting a `layout` section need `templates.manage` (the
  layout permission); page and header/footer sections keep `content.manage`. Listing stays under
  `content.view`.
- **Lifecycle.** Layout sections live in the same `SavedSectionsSource`, so block migrations and
  style-class jobs reach them. When a surface disappears (Commerce off) its saved sections are
  hidden and kept, returning with the surface — as the shop layouts themselves are kept.

## 6. What ships

**Layout patterns.** Every surface: three templates (one close to today's starter) and a few
sections, built only from existing blocks.

| Surface | Templates | Sections |
|---|---|---|
| `entry` | Classic article · Magazine (full-width cover under the title) · Minimal | Article header (title, date, terms) · Cover band · Related posts · Previous / next |
| `listing` | Card grid · Horizontal list · Compact | Listing header (title and an intro line) · Page navigation bar |
| `archive` | Term header with grid · Horizontal list · Compact | Term header (title and the term's description) |
| `product` | Gallery left · Gallery on top · Story-led (buy box, then the story at full width) | Product hero (gallery, name, rating, price, buy box) · Details band · Story band |
| `shop_index` | Adaptive grid · Banner and grid · Category-led | Shop banner (Shop title in a hero) · Category chips band |
| `shop_category` | Adaptive grid · Banner and grid · Chips on top | Category banner |

General page sections are offered in every layout on top of these.

**Shop page patterns** (Commerce on only; `scope: page`). Templates: **Shop landing**, **Product
launch**, **Sale / collection**, **New arrivals**. Built from eight commerce sections: New
arrivals grid, Category collection grid, Featured product spotlight, Add-to-cart CTA, Sale banner,
Reasons to buy, Product FAQ, Shop CTA band. Product-specific settings (a product slug, a category)
ship empty or on the "newest" source so the pattern renders on any shop; the editor fills them in.

**Thumbnails.** `scripts/build-pattern-thumbnails` renders layout patterns against their surface's
placeholder sample (the page the stage shows when nothing is published), and commerce patterns with
the sample product, so every shipped card has a picture. Saved sections keep the plain card.

## 7. Testing

- **Library.** Places are correct and each surface's list holds only its own patterns; a pattern
  using an unavailable type is hidden; a layout pattern of an unregistered surface is hidden; the
  commerce contributor registers only while Commerce is on, and with it off no commerce pattern
  appears in any place.
- **Every shipped layout template passes its surface's `LayoutValidator`** (palette, card, required
  block once) and every shipped page pattern resolves whole — a block change that breaks a shipped
  pattern fails the build instead of hiding it silently.
- **Saved layout sections.** Each surface's save rules; `templates.manage`; the migration leaves
  existing rows unchanged; a missing-field insert is refused with the field named; a vanished
  surface's sections are hidden and kept.
- **Admin (vitest).** The layout palette's three views filtered by surface; insert legality
  including "does not fit here" for a second required block; a template replacing the working
  copy with the confirm and undo; Save as section keeping the layout place and absent inside a card.
- **Browser proofs (e2e).** In the layout editor: insert a section and apply a template on the
  stage and assert the working copy. In the page Design view: shop templates present with Commerce
  on, absent with it off.
- **Renders.** Each layout template renders on its surface's placeholder sample without errors.

## 8. Docs

`docs/guides/04-sections-and-pages.md` (layout sections and templates; saving from a layout),
`docs/guides/20-layouts.md` (Templates replace the layout), `docs/guides/18-commerce.md` (shop page
templates), `docs/reference/04-block-library.md` where it lists patterns, and the changelog.

## 9. Rollout

1. **S1 — shop pages.** The pattern contributor registry (contract, core implementation, push
   registration), the commerce contributor registered while `thallo.commerce` is enabled, the shop
   page sections and four templates, their thumbnails, docs and changelog. Proves the contributor
   and the gating on the existing page flow.
2. **S2 — layout sections and templates.** The `layout` place and `surface`, `PatternLibrary`'s
   surface filtering and template validation, the layout editor's Sections and Templates, the
   shipped patterns of all six surfaces (commerce's through its contributor), saved layout sections
   (migration, surface validation, permissions), thumbnails, docs and changelog.

Each release gets its own plan, passes the full gates (PHP suite and the prefixed run, the full
tenancy proof, admin type-check/lint/format/vitest, browser proofs, distribution and skeleton
smokes; a new `tests/Integration` entry joins a CI shard) and its own beta cut.
