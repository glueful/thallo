# Product grid — design

**Status:** for review (revision 4: a stale page is refused on read — guards stored in the entry and checked before
serving or answering 304; one value per guard; missing generations (§3.2). Revision 3: both page-cache families, workspace isolation, empty results and
late renders (§3.2); the storefront stylesheet only through the theme layer (§3.4); resting opacity
(§7.1); every scalar filter caller (§4); exact display defaults and the title tag (§5.1).
Revision 2: no compatibility with grids saved before this release). **Date:** 2026-10-07.

## 1. Purpose

A Product grid is the storefront's general product showcase: a home page's "New in", a landing
page's "On sale in Men and Women", a row of four hand-picked products. Today it can only show one
category, one tag, a manual list or the newest products, and how its cards look is fixed in
`shop.css`: the Style tab offers spacing, width, visibility and grid placement and nothing else.

This release gives it the shape of a mature product widget (the reference was Elementor's
"Product Grid – Modern"): **what kind of list** it is, narrowed by **categories and tags** chosen
from dropdowns; **which parts of a card show**; **badges**; and a **Style tab** that reaches the
card, its image, title, price, meta, button and badges, with hover looks.

**Success:** an author drops a Product grid on the home page, picks *On sale*, chooses *Men* and
*Women* from a dropdown, sets 4 products in 4 columns, hides the rating, turns on a *Sale* badge,
gives the cards a white background with a soft shadow that lifts on hover and zooms the image —
and sees all of it on the stage, with real products, as they edit.

**Out of scope for this release:**

- **Pagination** and a rows setting (§3.4) — a grid shows its count and links to the rest.
- **Best selling, Top rated, Featured, Trending, Recently viewed** — they need sales, featured or
  browsing data the catalog does not keep yet. The source model (§2) takes them later without a
  change of shape.
- **Quick view, Compare** — modules Thallo does not have.
- **A second image on hover** — the card carries one image.
- **Showing a field only for some sources** — the inspector has no conditional fields; §5.2 says
  how the form stays clear without them.
- **"Mix evenly" across categories** — one merged list, newest first (§2.2), is this release.

## 2. The query

### 2.1 Source plus filters

A grid's query is a **source** — what kind of list — narrowed by **filters**, then **ordered** and
**cut to a count**:

| Setting | Field | Values | Default |
|---|---|---|---|
| Source | `source` | `all` (All products), `on_sale` (On sale), `manual` (Manual selection) | `all` |
| Categories | `categories` | category slugs, several | none (no restriction) |
| Tags | `tags` | tag slugs, several | none |
| Exclude out of stock | `exclude_out_of_stock` | boolean | off |
| Order by | `order_by` | `newest`, `price_asc`, `price_desc`, `name` | `newest` |
| Products to show | `limit` | 1–48 | 12 |
| Columns | `columns` | `auto`, `2`–`6` | `auto` |
| Products (manual) | `products` | one product slug per line, as today | — |

"Newest" is no longer a source: *All products* ordered by *Newest* is the same list, and keeping
both would offer two ways to say one thing.

### 2.2 What the filters mean

- **Several categories mean any of them:** Men + Women shows products in Men *or* Women. Tags
  likewise.
- **Categories and tags together must both match:** Categories Men, Women and Tags Summer shows
  products in Men or Women *that are also* tagged Summer. An empty filter does not restrict.
- **One merged list:** matching products are deduplicated (a product in both Men and Women appears
  once), ordered by *Order by*, and the first *Products to show* are shown.
- **On sale:** at least one active variant has a compare-at price above its price.
- **In stock** (what *Exclude out of stock* keeps): at least one active variant is untracked, or
  tracked with a quantity above zero.
- **Price order:** by the product's lowest active variant price; ties and *Name* break on name,
  then uuid, so the order is stable.
- **Manual:** the listed products in the listed order, as today (inactive or missing slugs
  skipped). Categories, tags and *Order by* do not apply; *Exclude out of stock* does.
- **A slug that no longer exists** (a deleted category) is ignored, as an unknown slug is today;
  when every chosen category is gone the grid shows nothing rather than everything.

### 2.3 View all

The link under the grid:

- exactly one category chosen, no tags → that category's page (as today);
- anything else → the shop page.

### 2.4 No carry-over from the old block

There are no live sites, so nothing carries grids saved before this release forward: the old
`category_slug` and `tag_slug` fields and the `category`, `tag` and `newest` source values are
**removed** from the schema, not kept as legacy fields, and nothing maps them. Missing or invalid
values read as the defaults of §2.1 (`ProductGridQuery::fromData()`, one PHP normalizer, so the
stage, the published page and the tests agree). The shop patterns that build grids are updated to
the new fields.

## 3. Rendering

### 3.1 The cards render on the server

Today the block emits an empty shell and `shop.js` fetches `/_shop/blocks/product-grid` and builds
the cards in the browser. That cannot carry this release: Style tab classes reach markup through
Twig's `style_classes()`, and nothing passes them to JS-built elements; and the stage — which never
runs `shop.js` — shows a placeholder, so no style change could be previewed.

The grid therefore **renders its cards on the server**, as the Product tile and Product list blocks
already do:

- `product-grid.twig` calls a commerce Twig function, `product_grid(data)`, which normalizes the
  data (§2.4), runs the query (§4) and returns the card view models plus the View-all URL.
- Each card is rendered by a grid card partial that adds the new display options, badges and part
  classes to the existing card markup (`shop/_product_card.twig`); the shop index and wishlist
  cards are unchanged.
- On the published page `shop.js` binds the cards' add-to-cart forms and wishlist hearts the way it
  binds the shop index's server cards, so the cards carry the same hooks.
- **Without JavaScript** every card stays usable: the image and title link to the product, a
  direct add-to-cart is a real form that posts to `/_shop/cart/add`, and the options button is a
  link to the product page. The wishlist heart, which needs JavaScript, stays hidden.
- **The stage shows the real cards**, inert (no cart or wishlist behaviour), with `shop.css`. Its
  placeholder remains only for an empty result: "Product grid — no products match".

### 3.2 Caching

A grid can sit on two kinds of cached page, and both must drop it when the catalog changes.

**The two cache families:**

| Family | Serves | Key | Today tagged with |
|---|---|---|---|
| Render page cache (`RenderPageCache`) | ordinary pages: home, landing pages, posts | `{workspace segment}render:{theme}:{appearance}:{path}` | the controller's `Cache-Tag` surrogates + `thallo:render:page` |
| Shop page cache (`ShopPageCache`) | shop routes, including shop layouts that can hold blocks | `shop:{tenant}:…` | `thallo:shop:catalog:{tenant}`, `thallo:shop:catalog`, surrogates |

**Tagging.** The render extension gains a public, render-scoped way for a pack to add a surrogate
tag (today `collectTags()` is private, used by facets). `product_grid()` adds
`thallo:shop:catalog:{tenant}` for every grid it renders — **including a grid that matches no
products**, so creating the first matching product purges the page that showed none. The tag
reaches both families through the response's `Cache-Tag`, which each already folds into its
entry's tags.

**Purging.** `PurgeShopCacheOnCatalogChange` already invalidates `thallo:shop:catalog:{tenant}` on
every storefront catalog change, which with tags covers both families. Its fallback for a driver
without tag invalidation today deletes only shop keys (`shop:*`, `tenant:*:shop:*`), across every
workspace. It becomes:

- delete the owning workspace's **render** pages (`{segment}render:*`) as well as its shop pages;
- delete **only the owning workspace's** keys — the workspace whose commerce tenant the event
  names — never another workspace's (the current `shop:*` / `tenant:*:shop:*` patterns are
  narrowed to that tenant).

**Workspace isolation.** The tag carries the commerce tenant, which is per workspace; the render
key carries the workspace segment; the generation below is per workspace. A catalog change in one
workspace never purges or blocks storing another's pages. With tenancy off there is one workspace
and the segment is empty.

**A render that finishes after a purge.** A page render can read the catalog, a change can commit
and purge, and the render can then store its now-stale page under the tag that was just purged.
Deleting it afterwards is not enough — another request could read it first, or the worker could
stop before deleting — so **a stale entry must be unusable when read**:

- **Catalog generation.** Each workspace has one (`{segment}shop:catalog-gen:{tenant}`, a random
  token, like the shop layout generations of `ShopLayoutTags`). The purge listener replaces it on
  every catalog change, before invalidating tags.
- **Reading it.** `product_grid()` reads the generation **before** it queries products. A missing
  token is initialized with `setNx` to a fresh value and **re-read** (as `ShopPageCache` does for
  layout generations); if it still cannot be read, the render is marked **uncacheable**. Two
  missing values never count as a match.
- **The render's guard.** Each read is recorded as a render-scoped **cache guard** (key, value),
  collected like tags and carried to the page caches on the request's attributes — never sent to
  the client. A render holds **one value per guard key**: the first grid's observation is kept,
  and if a later grid on the same page reads a **different** value for the same key, the render
  is marked uncacheable. A later grid never replaces an earlier grid's older value.
- **Storing.** A page cache stores nothing for an uncacheable render. Otherwise it re-reads every
  guard key first and skips the store if any value changed or is unreadable; if not, it stores the
  **guards inside the cached entry**, beside the body and headers.
- **Serving.** On every hit, **before** answering — including before deciding a 304 — the page
  cache re-reads each guard stored in the entry. If any value differs from the stored one, or
  cannot be read, the entry is treated as a **miss**: it is deleted, the page renders afresh, and
  no 304 or stale body is ever served from it. An entry with no guards (a page without a grid)
  serves as today, with no extra reads.
- So the order of the store and the purge no longer matters: an entry stored after the purge
  carries the old generation and is refused on its first read, whether or not anything deletes it.
- This holds on every driver: the generation and the guards do not depend on tag support.

The New badge (§6) depends on the date: a cached page keeps a badge until the page is purged or
its cache entry expires. Acceptable — the badge is approximate by nature — and stated in the docs.

### 3.3 The old endpoint goes

`/_shop/blocks/product-grid`, its controller action and `shop.js`'s grid hydration
(`hydrateProductGrid`, `renderProductGrid`) are **removed**, with their tests and the API schema
entry. `buildProductCard()` stays — the wishlist page builds its cards with it — and so does the
card hook parity test.

### 3.4 The storefront stylesheet comes only through the theme layer

`shop.css` already reaches every page inside the theme artifact's `@layer theme`
(`ShopStylesheetContributor`), where authored styles in `@layer settings` win over it. But the
grid template — and the Featured product, Mini cart and Wishlist link blocks, and the shop page
templates (index, product, wishlist, checkout, confirmation) — also link `/_thallo/shop/shop.css`
directly. That second copy is **unlayered**, and unlayered CSS beats every layer: on any page
holding one of those blocks (a mini cart in the header is enough), the shop's defaults would
override the grid's authored card, title, price and button styles.

- Every raw `<link … shop.css>` is **removed**; the layered contribution is the only delivery.
  The `/_thallo/shop/shop.css` route may stay for direct use but no Thallo template links it.
- `shop.css` must hold nothing that relies on being unlayered (it is already linted as part of
  the theme artifact, as every contributed stylesheet is).
- **Proof**, in the browser, on a page with a grid and a mini cart: each part's authored style
  (card background, title colour, button background) is the computed value **after** `shop.js`
  has initialized, as before; add to cart from a grid card adds the product, the wishlist heart
  toggles; with JavaScript disabled the cards render styled, link to their products and a direct
  add-to-cart form posts.

### 3.5 Count and columns

Unchanged in behaviour: *Products to show* is a count (1–48), *Columns* a desktop count that steps
down to 3 on tablets and 2 on phones, or Auto. A count describes every screen; a rows setting would
describe only the desktop. The field's help says so: "Columns step down to 3 on tablets and 2 on
phones; 4 products in 4 columns is one row on a desktop."

## 4. The commerce engine (glueful/commerce 1.14.0)

The grid's query needs engine support the storefront list does not have. One minor release of
`glueful/commerce`, in `~/Sites/glueful/extensions/commerce`, before Thallo's:

- **`ResolvedProductFilters`** takes lists: `categoryUuids` and `tagUuids` (any-of within each,
  ANDed together), plus `onSale` and `inStock` booleans. The single-value arguments are replaced,
  not kept. In the engine, the storefront list (`ProductController::index()`, one category and one
  tag from `ProductListQuery`) passes one-item lists, with no change to its API.
- **`activeFilteredQuery()`**: each list becomes one `EXISTS … IN (…)` subquery; `onSale` an
  `EXISTS` over active variants with `compare_at_price > price`; `inStock` an `EXISTS` over active
  variants left-joined to `commerce_stock` (`tracked = false OR quantity > 0`). All portable SQL
  (the engine runs on PostgreSQL, MySQL and SQLite).
- **`listActive()`** takes an optional sort: `newest` (today's `created_at DESC, uuid`),
  `price_asc` / `price_desc` (a correlated `MIN(price)` over active variants, then name, uuid),
  `name` (name, uuid).
- **Card data in batch:** `TagRepository::tagProjectionsForProducts()` (slug, name per product)
  and `CategoryRepository::categoryProjectionsForProducts()` (every category, in the existing
  `position, name, uuid` order), and the product row's `created_at` exposed to the card projection.
- Tests for each predicate and sort on the engine's own suite, and its CHANGELOG entry.

**Thallo's callers of the scalar constructor change in the same commit as the dependency bump**
(`glueful/commerce ^1.14.0` in the root and pack `composer.json`), so no Thallo revision runs one
against the other:

- `packages/thallo-commerce/src/Shop/ShopCatalogPage.php:57` — the category page's grid
  (`forCategory()`): `new ResolvedProductFilters([$category['uuid']])`.
- `packages/thallo-commerce/src/Layouts/ShopCategorySurface.php:97` — whether a category lists a
  product, for the category layout surface (`listsAProduct()`).
- `ShopBlockDataController`'s two callers go with the endpoint (§3.3).

Proved by the public category page (`/shop/category/{slug}`: lists exactly the category's
products, paginates, 404s an unknown slug) and the category layout's stage (renders the category's
products; the empty-category state), both run against the new engine.

The commerce repo has no release skill; its release, tag and push are the user's, as the
framework's are.

## 5. The block's content

### 5.1 Fields

The schema in `ShopBlockTypesContributor` becomes, in inspector order and groups:

**Query** — Source, Categories, Tags, Products (manual), Exclude out of stock, Order by, Products
to show, Columns.

**Display** — each *Show* is a boolean; these are the exact defaults, used when a value is absent:

| Field | Default |
|---|---|
| Show image (`show_image`) | on |
| Show title (`show_title`) | on |
| Title tag (`title_tag`: `h2`, `h3`, `h4`) | `h3` — the name becomes `<h3 class="shop-grid__name"><a …>…</a></h3>`; a grid usually sits under a section heading |
| Show price (`show_price`) | on |
| Show rating (`show_rating`) | on |
| Show categories (`show_categories`) | on — **every** category of the product, in category order (a deliberate change: the card showed only the first) |
| Show tags (`show_tags`) | off |
| Show add to cart (`show_add_to_cart`) | on |
| Show wishlist (`show_wishlist`) | on |

Categories and tags are labels in one meta row that **wraps** onto further lines; a card with
several long labels grows taller rather than overflowing or clipping.

**Badges** — Show sale badge (off), Sale badge text (`Sale`), Show new badge (off), New badge text
(`New`), New badge days (7, 1–365), Badge position (`top-left`, `top-right`). Badges are off by
default.

### 5.2 Fields that only apply to some sources

The inspector has no conditional fields. The form stays honest without them: *Products* says
"Manual selection only — one product slug per line", *Categories*, *Tags* and *Order by* say "Not
used by Manual selection". Adding conditional fields is a separate, general feature.

### 5.3 Multi-select dropdowns

*Categories* and *Tags* are dropdowns of the store's categories and tags, several selectable, with
search (Nuxt UI `USelectMenu` with `multiple`):

- **Options sources:** commerce registers `thallo-commerce.categories` and `thallo-commerce.tags`
  with the existing field options registry (value: slug; label: name; permission: the commerce
  catalog view permission), the mechanism the Search block's scope uses.
- **`multiple` on option-source fields:** `FieldDefinition` accepts `multiple: true` on a `string`
  field with an `options_source`; its value is a list of strings. Validation accepts a list of
  strings (each at most the string field's length), rejects anything else. The admin's
  `OptionsSourceField` renders a multi-select when `multiple` is set; a stored value that is no
  longer an option (a deleted category) stays selected and is shown as unavailable, as the single
  select does today.
- **Slugs, not uuids:** consistent with the manual list, readable in stored
  data and patterns. A renamed category slug drops out of a grid (shown unavailable in the
  dropdown); tag slugs are immutable.

## 6. Badges

- **Sale:** shown when the product is on sale by §2.2's rule (any active variant with a compare-at
  price above its price) — the same rule as the On sale source, so an On sale grid with badges on
  badges every card.
- **New:** shown when the product's `created_at` is within *New badge days* of the render.
- Both may show; Sale comes first. They sit on the image at *Badge position*; with *Show image*
  off they sit before the title.
- Text is the author's (escaped), at most 24 characters.

## 7. The Style tab

### 7.1 Parts

The block keeps its root target (spacing, width, visibility, grid item) and gains **parts**, each
applied to every card's matching element through `style_classes('<part>')`:

| Part | Element | Capabilities |
|---|---|---|
| Card | the card | colours (background, border), border, corners, shadow, padding, opacity, hover (background, border colour, opacity) |
| Image | the image frame | corners |
| Title | the product name | typography, text colour, hover (text colour) |
| Price | the price line | typography, text colour |
| Meta | categories and tags | typography, text colour |
| Button | add to cart / choose options | colours, border, corners, typography, padding, opacity, hover (text colour, background, border colour, opacity) |
| Badge | sale and new badges | colours (background, text), corners, typography |

These are existing style paths: no new `StyleSchema` property and no settings-version bump.
Parts repeat per card, as the Links block's link part does.

**Resting opacity with hover opacity.** The capability expansion offers `hover.opacity` only
beside `opacity` (hover state spec §2.2), so Card and Button declare **both**. The published
`style_paths` for `product-grid` are pinned in a test: `parts.card` and `parts.button` contain
`opacity` and `hover.opacity` (and `hover.colors.*` as declared), `parts.title` contains
`hover.colors.text` but no opacity, and the other parts no hover path.

### 7.2 Effects that are content settings

Three looks are not style paths — the style system's hover only restyles the hovered element
itself, and none of these is a property a target owns — so they are content fields in a **Card**
group, rendered as classes on the grid and drawn by `shop.css`:

| Field | Values | Default |
|---|---|---|
| Card hover effect (`card_hover`) | `none`, `lift` (rises with a deeper shadow), `shadow` (deeper shadow only) | `none` |
| Image ratio (`image_ratio`) | `square` (today), `portrait` (4:5), `landscape` (4:3) | `square` |
| Image fit (`image_fit`) | `contain` (today: whole product, padded), `cover` (fills the frame) | `contain` |
| Image hover effect (`image_hover`) | `none`, `zoom` | `none` |

They follow the hover state's rules (hover state spec §4.3): drawn only where the device can
hover, on keyboard focus within the card too, and without movement under
`prefers-reduced-motion` (lift and zoom become no-ops; the shadow change stays).

### 7.3 The stage

Because the cards render on the stage (§3.1), every Style change previews at once. The Card part's
Normal / Hover switch previews the card's hover look through the existing forced preview; the card
hover effect and image zoom preview with it (the forced-hover attribute is part of their
selectors, as the theme's hover rules are).

## 8. Documentation

- `docs/reference/04-block-library.md` — the Product grid row: fields, groups, parts.
- `docs/reference/05-style-settings.md` — the block's parts.
- A storefront guide section: building a home-page grid; what On sale and Exclude out of stock
  mean; that the New badge follows page caching.
- CHANGELOG entries with the change, and Upgrade Notes: run `thallo:provision` (the block's new
  fields and parts); a Product grid saved before this release must have its source and categories
  or tags chosen again; a theme that overrides `product-grid.twig` must be rewritten for
  server-rendered cards; the `/_shop/blocks/product-grid` endpoint is gone; Thallo's templates no
  longer link `/_thallo/shop/shop.css` (it arrives in the theme layer) — a theme template that links
  it itself should stop, or its copy will override authored styles; product cards name the product
  in an `h3` by default; requires `glueful/commerce ^1.14.0`.

## 9. Testing

**Commerce engine (its own suite):** each filter (category list, tag list, both, on sale, in
stock — tracked and untracked), each sort with ties, the storefront list's one-item lists, and the
batch projections; on SQLite and PostgreSQL.

**Thallo, PHP:**

- `ProductGridQuery::fromData()` — the defaults, valid values, and missing or invalid values
  (including the removed `category` / `tag` / `newest` sources) reading as defaults.
- Integration: grids render the right products for each source, filter combination, order and
  count; dedupe across categories; deleted slugs; View-all URL rules; empty result placeholder.
- Display toggles at their exact defaults and each turned off; title tag (`h3` default, `h2`, `h4`,
  invalid → `h3`); every category of a product shown in order; badges (sale rule, new window
  boundary, position, text escaping); `card_hover` / `image_*` classes.
- The block type's published `style_paths` (§7.1): card and button carry `opacity` and
  `hover.opacity`.
- Parts: each part's classes land on every card's element; a grid with no style renders today's
  classes.
- Cache, for **both** families (an ordinary page and a shop layout page holding a grid):
  - the page carries `thallo:shop:catalog:{tenant}` and a catalog change purges it, on a tag driver
    and through the fallback on a driver without tags;
  - a grid with **no matching products** is tagged, and creating a matching product purges it;
  - **two workspaces**: a change in one purges its own grid pages, leaves the other's cached, and
    the fallback deletes only the owning workspace's keys;
  - **late render**, each against both families:
    - a catalog change between the grid's generation read and the pre-store check leaves the page
      unstored;
    - a change **after** the pre-store check but before the store: the entry is stored, and the
      next reader — arriving before anything could delete it — gets a fresh render, not the stale
      body, and no 304 for the stale ETag;
    - **the worker stops right after storing** (simulated: the entry is written with the old guard
      and nothing else runs): the next reader still gets a fresh render;
    - **two grids straddling a change** on one page (the first reads generation A, a change
      commits, the second reads B): the render is not stored;
  - a **missing generation** is initialized with `setNx` and re-read; an unreadable generation
    leaves the page uncached rather than matching another missing value;
  - a page **without** a grid stores and serves with no guard and no extra cache read.
- Category surfaces on the new engine (§4): the public category page lists exactly its products,
  paginates and 404s an unknown slug; the category layout's stage renders the category's products
  and its empty state.
- `FieldDefinition` `multiple` on option-source strings; validation of lists; the two commerce
  options sources (permission, labels).
- The card hook parity test keeps passing (the client card's hooks remain a subset of the
  server's); the removed endpoint answers 404.

**Admin (vitest):** multi-select options field (select, deselect, unavailable value kept); the
Product grid inspector's groups and help texts; the Style tab lists the seven parts.

**Browser (runtime-browser):** a grid fixture rendered published and on the stage — card hover
lift and image zoom under hover, absent under `(hover: none)` and reduced motion; forced preview
draws them; image ratio and fit geometry; badge position; a card with several long category labels
wraps its meta row without horizontal overflow. And the stylesheet proof of §3.4: authored part
styles are the computed values after `shop.js` initializes on a page that also holds a mini cart;
add to cart and the wishlist toggle work on grid cards; with JavaScript disabled the cards render
styled and usable.

**E2E:** the stage shows real cards and updates when a part's style changes.

## 10. Delivery order

1. `glueful/commerce` 1.14.0 (§4), released by the user.
2. Thallo, starting with the dependency bump **together with** the scalar-filter callers (§4);
   then field `multiple` + options sources; the cache tag, guard and fallback (§3.2); the
   stylesheet links (§3.4); the query normalizer and server render; display and badges; parts and
   effects; docs. One Thallo beta.
