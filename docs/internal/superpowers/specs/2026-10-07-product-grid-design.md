# Product grid — design

**Status:** for review. **Date:** 2026-10-07.

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
and sees all of it on the stage, with real products, as they edit. A grid saved before this
release renders the same products as before.

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

### 2.4 Grids saved before this release

Stored block data is not rewritten (there is no value-mapping block migration, and the block's
last schema change set the precedent of read-time defaults). The grid reads old data as:

| Stored | Read as |
|---|---|
| `source: newest` (or missing, or unknown) | `source: all`, `order_by: newest` |
| `source: category`, `category_slug: men` | `source: all`, `categories: [men]` |
| `source: tag`, `tag_slug: summer` | `source: all`, `tags: [summer]` |
| `source: category` with no slug | `source: all`, no filter — but the stage keeps today's prompt to choose one |
| `source: manual` | unchanged |

`category_slug` and `tag_slug` stay in the schema as **legacy fields**, excluded from the
inspector (`exclude`), read only when `categories` / `tags` are absent. The first save from the new
inspector writes `categories` / `tags` and the grid reads those from then on. This mapping is one
PHP normalizer (`ProductGridQuery::fromData()`), so the stage, the published page and the tests
read old data the same way.

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
- **The stage shows the real cards**, inert (no cart or wishlist behaviour), with `shop.css`. Its
  placeholder remains only for an empty result: "Product grid — no products match" (or today's
  "choose a category" prompt for an old category grid with no slug).

### 3.2 Caching

The published page around the grid is cached by the render page cache, so the grid must purge with
the catalog:

- The render extension gains a public way for a pack to add a surrogate tag to the current render
  (today `collectTags()` is private, used by facets). `product_grid()` adds
  `thallo:shop:catalog:{tenant}`.
- `PurgeShopCacheOnCatalogChange` already invalidates that tag on every storefront catalog change
  (product, variant, price, stock, media, category, tag, attribute, add-on), so a page with a grid
  is purged when anything a card shows changes.
- The New badge (§6) depends on the date: a cached page keeps a badge until the page is purged or
  its cache entry expires. Acceptable — the badge is approximate by nature — and stated in the
  docs.

### 3.3 The old endpoint

`/_shop/blocks/product-grid` and `shop.js`'s grid hydration stay this release, unchanged, so a page
cached before the upgrade still fills. The block no longer uses them. Their removal is a later
release's task.

### 3.4 Count and columns

Unchanged in behaviour: *Products to show* is a count (1–48), *Columns* a desktop count that steps
down to 3 on tablets and 2 on phones, or Auto. A count describes every screen; a rows setting would
describe only the desktop. The field's help says so: "Columns step down to 3 on tablets and 2 on
phones; 4 products in 4 columns is one row on a desktop."

## 4. The commerce engine (glueful/commerce 1.14.0)

The grid's query needs engine support the storefront list does not have. One minor release of
`glueful/commerce`, in `~/Sites/glueful/extensions/commerce`, before Thallo's:

- **`ResolvedProductFilters`** takes lists: `categoryUuids` and `tagUuids` (any-of within each,
  ANDed together), plus `onSale` and `inStock` booleans. The single-value constructor arguments
  remain as a deprecated path that maps to one-item lists, so the storefront list
  (`ProductListQuery`, one category and one tag) is unchanged.
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

Thallo then requires `glueful/commerce ^1.14.0`. The commerce repo has no release skill; its
release, tag and push are the user's, as the framework's are.

## 5. The block's content

### 5.1 Fields

The schema in `ShopBlockTypesContributor` becomes, in inspector order and groups:

**Query** — Source, Categories, Tags, Products (manual), Exclude out of stock, Order by, Products
to show, Columns.

**Display** — Show image, Show title, Title tag (`h2` / `h3` / `h4`), Show price, Show rating,
Show categories, Show tags, Show add to cart, Show wishlist. Each *Show* is a boolean; an absent
value means today's look: everything shown except tags (new) — so a saved grid is unchanged.
*Show categories* shows every category of the product (today: the first only), in category
order.

**Badges** — Show sale badge (off), Sale badge text (`Sale`), Show new badge (off), New badge text
(`New`), New badge days (7, 1–365), Badge position (`top-left`, `top-right`). Badges are off by
default so a saved grid gains none.

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
- **Slugs, not uuids:** consistent with the manual list and the legacy fields, readable in stored
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
| Card | the card | colours (background, border), border, corners, shadow, padding, hover (background, border colour, opacity) |
| Image | the image frame | corners |
| Title | the product name | typography, text colour, hover (text colour) |
| Price | the price line | typography, text colour |
| Meta | categories and tags | typography, text colour |
| Button | add to cart / choose options | colours, border, corners, typography, padding, hover (text colour, background, border colour, opacity) |
| Badge | sale and new badges | colours (background, text), corners, typography |

These are existing style paths: no new `StyleSchema` property and no settings-version bump.
Parts repeat per card, as the Links block's link part does.

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
  fields and parts); grids keep their products; a theme that overrides `product-grid.twig` must be
  rewritten for server-rendered cards; requires `glueful/commerce ^1.14.0`.

## 9. Testing

**Commerce engine (its own suite):** each filter (category list, tag list, both, on sale, in
stock — tracked and untracked), each sort with ties, the deprecated single-value path, and the
batch projections; on SQLite and PostgreSQL.

**Thallo, PHP:**

- `ProductGridQuery::fromData()` — every row of §2.4's table, plus unknown values.
- Integration: grids render the right products for each source, filter combination, order and
  count; dedupe across categories; deleted slugs; View-all URL rules; empty result placeholder.
- Display toggles and title tag; badges (sale rule, new window boundary, position, text escaping);
  `card_hover` / `image_*` classes.
- Parts: each part's classes land on every card's element; a grid with no style renders today's
  classes.
- Cache: a rendered page with a grid carries `thallo:shop:catalog:{tenant}`; a catalog change
  purges it.
- `FieldDefinition` `multiple` on option-source strings; validation of lists; the two commerce
  options sources (permission, labels).
- The card hook parity test keeps passing (the client card's hooks remain a subset of the
  server's).

**Admin (vitest):** multi-select options field (select, deselect, unavailable value kept); the
Product grid inspector's groups and help texts; the Style tab lists the seven parts.

**Browser (runtime-browser):** a grid fixture rendered published and on the stage — card hover
lift and image zoom under hover, absent under `(hover: none)` and reduced motion; forced preview
draws them; image ratio and fit geometry; badge position.

**E2E:** the stage shows real cards and updates when a part's style changes.

## 10. Delivery order

1. `glueful/commerce` 1.14.0 (§4), released by the user.
2. Thallo: field `multiple` + options sources; the query normalizer and server render; display and
   badges; parts and effects; docs. One Thallo beta.
