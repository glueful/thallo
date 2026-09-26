---
title: "Template functions"
slug: template-functions
section: reference
order: 3
summary: "Every function, filter and variable a Thallo template can use."
---

Thallo adds these functions and filters to Twig. Everything else in a template — the tags, the
loops, the standard filters — is Twig's own. Which template renders which page is
[themes](../concepts/04-themes.md); writing one is
[make your own theme](../guides/13-make-a-theme.md).

## How to read this page

A function is called `{{ name(arguments) }}`, a filter applied `{{ value|filter }}`.

Every function listed here is always registered. The ones that need a pack, a capability or a
saved setting return `null` or an empty list when it is missing, so a template never fails
because something is not installed — guard the value and render the fallback.

Three helpers return a class fragment that already starts with a space, so it drops straight
after a class list: `style_classes()`, `token_class()` and `region_style_classes()`.

## Content

| Function | Returns | Example |
|---|---|---|
| `blocks(list)` | The HTML of an ordered list of blocks, each rendered through `templates/blocks/{type}.twig`. Blocks nest five levels deep; a deeper list renders nothing. | `{{ blocks(entry.fields.body) }}` |
| `entries(type, options)` | Published entries of a content type, newest first. `options` takes `limit` (default 3, clamped to 1–12), `order` (`newest` or `oldest`) and `category` (a term slug, filtered on the type's first filterable reference field). Empty for a type that is not delivered publicly. | `{% set posts = entries('post', {limit: 6}) %}` |
| `entry_tree(type, options)` | Every published, routed entry of a type as navigation, up to 500: `groups` (each `key`, `label`, `items`) in the order of the group field's options, and `items`, the same pages flat in reading order. `options` takes `group` (default `section`) and `order` (default `order`). | `{% set tree = entry_tree(type) %}` |
| `path(uuid)` | An entry's live public path, or `null` when it is not published and routed. | `{{ path(data.post.entry_uuid) }}` |
| `facets(type, field, limit)` | The term counts for a filterable reference field: `uuid`, `slug` and `count` each. `limit` defaults to 100. | `{% for t in facets('post', 'category') %}` |
| `form_render(block)` | The render payload for a `form` block — `token`, `key`, `honeypot`, `fields`, `heading`, `intro`, `submit_label`, `submit_variant`, `submit_color`, `success_message` — or `null`, which is the template's cue to render its disabled notice. | `{% set f = form_render(block) %}` |

An item of `entries()` carries `uuid`, `locale`, `version`, `published_at`, `fields` and `href`;
`href` is `null` for an entry with no route. An item of `entry_tree()` carries `uuid`, `slug`,
`href`, `title`, `summary` and `group` — never the body.

## Media and images

| Function | Returns | Example |
|---|---|---|
| `media(uuid)` | A public URL for a file in the media library, or `null` when the file is not anonymously retrievable. | `{% set avatar = media(data.avatar) %}` |
| `media_image(uuid, widths)` | `src` and `srcset` for an image, or `null` for anything that is not a servable image. `widths` is a list of positive integers; the first eight distinct ones are used. `srcset` is `null` when no variant can be served. | `{% set img = media_image(data.image, [480, 768, 1024]) %}` |
| `media_text(uuid)` | The file's `alt` and `caption` from the media library, as strings — empty when none is set or the file is not public. For a fallback when a block's own is blank. | `alt="{{ data.alt ?: media_text(data.image).alt }}"` |
| `claim_priority_image()` | `true` for the first image on the page that asks, `false` for every one after, and always `false` inside a region. Call it only once `media_image()` has resolved. | `{% set priority = claim_priority_image() %}` |
| `video_embed(url)` | `provider` and `id` for a YouTube or Vimeo URL, `null` for anything else. The template builds the player from them; a URL is never embedded as it stands. | `{% set emb = video_embed(data.url) %}` |
| `map_embed(data)` | For the map block: `src` (Google's embed address, built from `place`, `zoom` and `view`, or taken from `embed_url` only when it is Google's own "Embed a map" link), `title`, and `directions` and `open` (Google Maps links, for a place only). `null` with neither a place nor a valid link. Nothing typed is emitted as an address of its own. | `{% set map = map_embed(data) %}` |
| `site_logo(variant)` | The site logo's URL. `variant` is `light` (the default) or `dark`; anything else, and an unset logo, give `null`. | `{% set logo = site_logo('dark') %}` |
| `site_favicon()` | The site icon's URL, or `null`. | `{% set favicon = site_favicon() %}` |
| `icon(name)` | An inline SVG, marked `aria-hidden` and classed `thallo-icon`. A Lucide name, or `brand:` and a name from the vendored Simple Icons set. `null` for an unknown or malformed name, so a template can fall back to text. | `{{ icon('download') }}` |

## Navigation and regions

| Function | Returns | Example |
|---|---|---|
| `menu(slug)` | A named menu's items: `label`, `url`, `entry` and `children` each. Empty when the navigation pack is absent or its capability is off. | `{% for item in menu('main') %}` |
| `region_blocks(slug)` | The HTML of the `header` or `footer` region, or `null` when nothing is saved. A `null` means render your own chrome: an empty region and an absent one look the same on purpose. | `{% set headerHtml = region_blocks('header') %}` |
| `region_settings(slug)` | The region's settings. `header` has `sticky` (a boolean) and `width`; `footer` has `width`. `width` is `contained` or `full`. | `{% set hs = region_settings('header') %}` |
| `region_style_classes(slug, target)` | The utility classes the region's own Style tab puts on `target` — `root`, the bar, or `inner`, its content, where the padding lands. `''` for an unstyled region. A third argument renders settings that were posted rather than saved, which is what the admin's chrome preview does. | `{{ region_style_classes('header', 'inner') }}` |

A page can hide either region; the templates read `presentation.header` and
`presentation.footer` for that. See [edit the header and footer](../guides/02-header-and-footer.md).

## Documentation pages

| Function | Returns | Example |
|---|---|---|
| `markdown(text)` | Markdown kept in a plain text field, rendered as HTML. GitHub-flavoured; every heading gets a stable id and an anchor link; raw HTML is stripped, so the output is safe to emit. A fenced code block is rendered by the theme's own `blocks/code.twig`. | `{{ markdown(entry.fields.body) }}` |
| `markdown_toc(text)` | The same render's `h2` and `h3` outline: `id`, `text` and `level` each. | `{% set toc = markdown_toc(entry.fields.body) %}` |

Both memoise per render, so asking for the body and its contents costs one render. See
[a documentation section on your site](../documentation-sites.md).

## Search

| Function | Returns | Example |
|---|---|---|
| `search_enabled()` | Whether the `thallo.search` capability is on. Offer a search box only inside it, so a visitor never gets one that cannot answer. | `{% if search_enabled() %}` |

## Theme assets and stylesheets

| Function | Returns | Example |
|---|---|---|
| `asset(rel)` | The URL of a file in the theme's `assets/` folder, with a cache-buster for the active theme and the file's own timestamp. An absolute URL, a leading `/`, a backslash or a `..` segment fails the render and names the value. | `{{ asset('blocks.js') }}` |
| `layers_stylesheet_url()` | The layer-order stylesheet. A layout links it first. | `{{ layers_stylesheet_url() }}` |
| `theme_stylesheet_url()` | The theme artifact: every stylesheet `theme.json` lists plus every pack-contributed sheet, inside `@layer theme`, served by content hash. | `{{ theme_stylesheet_url() }}` |
| `settings_stylesheet_url()` | The compiled settings artifact: the design tokens and the utilities the Design view's settings need, inside `@layer settings`. Linked after the theme artifact. | `{{ settings_stylesheet_url() }}` |
| `custom_css()` | The URL of the site's `custom.css`, or `null` when it is empty. It loads last, so it is the final override. | `{% set customCss = custom_css() %}` |
| `runtime_script()` | The URL of the pack's theme runtime, which drives the carousel, tabs, navigation, colour mode and forms. Keep it in a copied `layout.twig`. | `{{ runtime_script() }}` |
| `block_script(name)` | A deferred script tag for one block's runtime asset, once per render. The names are `animated-text`, `code`, `docs-search`, `gallery` and `motion`; anything else emits nothing. | `{{ block_script('gallery') }}` |
| `font_faces_style(family, roman, italic)` | A preload link and the `@font-face` rules for a webfont in the theme's `assets/`. `italic` is optional. Emits nothing when the file is missing, or when the site's typeface setting uses another face. | `{{ font_faces_style('Figtree', 'fonts/figtree-roman-latin.woff2') }}` |

A theme links exactly three stylesheets — the layer order, the theme artifact, the settings
artifact — and never its own files one by one.

## Style settings and slots

These four turn a block's saved settings into markup. Nothing else does.

| Function | Returns | Example |
|---|---|---|
| `style_classes(target)` | The utility classes the current block's settings resolve to for one of its declared style targets, with a leading space. `''` outside a block and for a type that declares no targets. A target the type does not declare fails the render. | `<div class="thallo-block{{ style_classes('root') }}">` |
| `style_attrs(target)` | The attributes that target owns — the anchor, the CSS classes and the attributes from the **Advanced** tab — escaped, with a leading space. | `{{ style_attrs('root') }}` |
| `token_class(property, value)` | The utility class one stored `token` or `choice` value compiles to, with a leading space. `''` for an absent or stale value. An unknown property fails the render. | `{{ token_class('colors.text', data.prefix_color.value) }}` |
| `slot_attrs(slot)` | `data-thallo-slot="<slot>"` on the canvas, nothing on the live site. Put it on the element that wraps a `blocks()` call, once per `blocks` field of the type, so the builder knows where a dragged block may land. | `<div{{ slot_attrs('body') }}>{{ blocks(data.body) }}</div>` |

Every setting and the CSS it becomes is [style settings](05-style-settings.md); which targets a
block type declares is [the block library](04-block-library.md).

## Colour mode and appearance

| Function | Returns | Example |
|---|---|---|
| `color_mode_enabled()` | Whether light/dark switching is on. When it is off, the script and the **Color mode** block render nothing. | `{% if color_mode_enabled() %}` |
| `color_mode_script()` | The no-flash resolver that stamps `data-theme` on `html` before the CSS loads. Put it first in the `head`. | `{{ color_mode_script() }}` |
| `theme_colors_style()` | The token override for the site's accent, neutral, corners, typeface and page ground. Empty for the defaults. Goes after the theme's CSS and before `custom.css`. | `{{ theme_colors_style() }}` |
| `theme_style_scope(accent, neutral)` | A scoped re-skin for a `style` block: `class`, a class fragment with a leading space, and `style`, the rules for it. Both are empty when neither colour is set. | `{% set scope = theme_style_scope(data.accent, data.neutral) %}` |

## The head

| Function | Returns | Example |
|---|---|---|
| `seo_head()` | The page's description, canonical, `hreflang` alternates, Open Graph and Twitter tags, built from the `seo` variable. Empty on a page that has none. A preview emits only `noindex, nofollow`: a draft is never canonicalised or scrapeable. | `{{ seo_head() }}` |
| `json_script(value)` | A value encoded as JSON that is safe inside a `script` element — the closing tag cannot be represented in it. This is the only sanctioned way to put structured data in a script. A value that cannot be encoded fails the render rather than emitting half of it. | `{{ json_script(structuredData) }}` |

## Preview and the canvas

| Function | Returns | Example |
|---|---|---|
| `is_canvas()` | `true` while rendering the Design view's stage. Use it to render a wrapper, or an empty-state hint, that the published page does not need. | `{% if is_canvas() %}` |
| `is_preview()` | The same flag, under its older name. | `{% elseif is_preview() %}` |
| `canvas_scope()` | Which stage is rendering: `entry` for the Design view, `regions` for the Header & footer page, or an empty string off the stage. Put it on the root element as `data-thallo-canvas`, as the default layout does: the stage scripts read it. | `<html{% if canvas_scope() %} data-thallo-canvas="{{ canvas_scope() }}"{% endif %}>` |
| `region_stage()` | `true` on the Header & footer page's stage, where only the regions are edited. Render the header and footer wrappers there even when a region is empty, so each has a place to drop into. | `{% if headerHtml or region_stage() %}` |
| `region_slot_attrs(slug)` | On the Header & footer page's stage, ` data-thallo-slot="header"` (or `footer`) for the element that wraps `region_blocks(slug)`; nothing anywhere else. | `<div{{ region_slot_attrs('header') }}>{{ headerHtml }}</div>` |

None of them is a session check: a page opened in a preview session, but not on a stage, renders
exactly as the live one. On the Header & footer page's stage, `region_blocks` annotates the
region's blocks for editing and returns an empty string, not `null`, for an empty region; the page
body renders as published and is not annotated.

## Commerce

Every one of these returns `null` when the commerce pack is absent or its capability is off, so
a template degrades to plain text instead of a dead link.

| Function | Returns | Example |
|---|---|---|
| `shop_product_url(slug)` | A product's catalogue URL. | `{% set url = shop_product_url(data.product_slug) %}` |
| `shop_category_url(slug)` | A category's catalogue URL. | `{% set url = shop_category_url(data.category) %}` |
| `shop_index_url()` | The shop index — browse all products. | `{{ shop_index_url() }}` |
| `shop_wishlist_scope()` | The opaque scope the wishlist stores under in the visitor's browser. `null` means render no wishlist control. | `{% set scope = shop_wishlist_scope() %}` |
| `shop_wishlist_url()` | The wishlist page's URL. | `{{ shop_wishlist_url() }}` |
| `plan_checkout_url(key)` | The checkout deep link for a subscription plan key. `null` when the subscriptions pack is absent or the key is malformed, and the `pricing_plan` block then uses its authored button URL instead. | `{{ plan_checkout_url(data.plan_key) }}` |

## Filters

| Filter | What it does |
|---|---|
| `\|safe_html` | Sanitises author-written rich HTML and emits it. With no sanitiser bound, or if sanitising throws, the value is escaped — there is no path that emits it unprocessed. Use it on a rich-text field. |
| `\|editable_text(field)` | Escapes the value and, on the canvas, wraps it in the region that makes the field editable in place. Plain `{{ data.title }}` renders but cannot be edited. |
| `\|safe_url` | Returns the URL when it is a site-relative path, `https:`, `http:` or `mailto:`, and `null` for anything else, including a protocol-relative `//host`. Twig's escaping does not make `javascript:` safe; this does. |
| `\|numeric_clamp(min, max)` | The number held between two bounds, or `null` when the value is not numeric — so "no value" stays distinct from "clamped to the floor". |
| `\|br_tokens` | Turns the literal tokens an author typed — `<br>`, `<br/>` and `<br />` — into real line breaks. Everything else stays escaped, because the tokens are recognised after escaping. |

Twig's own filters are available too, within the limits the sandbox sets below.

## What every template receives

| Variable | What it holds |
|---|---|
| `site` | `name`, `locale` (the locale this page rendered in), `version` (the installed Thallo version, `null` in a development checkout) and `locales`, the enabled languages' codes in their Settings › Languages order. |
| `current_path` | The normalised request path, for marking the current item in a menu. |
| `presentation` | `show_title`, `layout` (`centered` or `full`), `header` and `footer` (`default` or `hidden`), and `style_classes`, the page's own style frame as classes for `main`. Composed from the page's override, then `theme.json`'s per-type setting, then its default. |

A render inside a preview session also gets `preview` (`true`), `preview_exit` and, on an entry,
`preview_bar` with `status`, `live_path`, `editor_url` and `design_url`. The Design view's stage
adds `preview_revision`.

## What each template receives

| Template | Also receives |
|---|---|
| `index.twig` | `entry` and `seo`, when a homepage entry is configured. |
| `entry.twig`, `entry/{type}.twig` | `entry`, `type` (the content type's slug), `seo` and `rich_fields`, the names of the type's rich-text fields: render those through `safe_html`, and print every other text field as it is, escaped. |
| `listing.twig`, `listing/{type}.twig` | `items`, `pagination`, `type`, `type_name`. |
| `archive.twig`, `archive/{type}.twig` | `items`, `pagination`, `type`, `type_name`, plus `term` (the term's own entry) and `field`. |
| `terms.twig`, `terms/{type}.twig` | `terms` (`uuid`, `slug`, `count`, `href` each), `type`, `field`. |
| `404.twig`, `error.twig` | Nothing beyond the shared variables. |
| `blocks/{type}.twig` | `data`, the block's fields; `block`, with `id`, `type`, `data` and `settings`; `index`, its place in the list; `region_slug`, set when the block is in a region; and the caller's `entry`, `site` and `current_path`. |
| `region-stage.twig` | Nothing beyond the shared variables. The Header & footer page's stage renders it when no published page can be shown; the public site never does. |
| `region-session-expired.twig` | Nothing beyond the shared variables. The Header & footer page's stage renders it once its session has expired. |

`entry` is `uuid`, `locale`, `version`, `published_at` and `fields`. An item of `items` is the
same, plus `href`. `pagination` is `page`, `per_page`, `total`, `total_pages`, `prev_path` and
`next_path`; the paths are built for you, so a template never assembles a page URL. `type` is the
content type's slug and `type_name` its name, the one to print in a heading. `seo` is
`title`, `description`, `canonical`, `alternates`, `x_default`, `og`, `twitter_card` and
`robots` — `seo_head()` turns it into tags.

## What the sandbox refuses

A template saved under **Site › Theme editor** is checked before it is stored, and again before
it compiles, and a refusal names the line. A template on disk is not checked: it is your file,
and it runs as written. The two get the same functions and filters; only the checking differs.

The check is a default-deny allowlist. Anything not on it is refused, including a Twig construct
that a later version adds.

- **Tags:** `if`, `for`, `set`, `block`, `extends`, `include`, `verbatim`, `macro`, `import`.
- **Functions:** everything on this page, plus Twig's `include`, `parent`, `block`, `cycle`,
  `date`, `min` and `max`.
- **Filters:** the five above, plus `abs`, `batch`, `capitalize`, `column`, `date`,
  `date_modify`, `default`, `escape`, `e`, `first`, `format`, `join`, `json_encode`, `keys`,
  `last`, `length`, `lower`, `merge`, `nl2br`, `number_format`, `replace`, `reverse`, `round`,
  `slice`, `sort`, `split`, `striptags`, `title`, `trim`, `upper` and `url_encode`.
- **Tests:** `defined`, `empty`, `even`, `iterable`, `null`, `odd`, `true`, `same as`,
  `divisible by`, `sequence` and `mapping`.

Some refusals are worth naming, because the template that needs them has to be written another
way:

- `|raw`. Emit rich HTML through `|safe_html`, trusted markup through the function that owns it.
- Method calls — `thing.method()`. The render context is arrays and scalars.
- Arrow functions, and so `map`, `filter` and `reduce`.
- `matches`, and `range()` and the `..` operator: one is a denial-of-service risk, the others
  can allocate without bound before the call is made.
- A `style=` attribute or a `style` element written into the markup. `theme_colors_style()`,
  `theme_style_scope()` and `font_faces_style()` are the only inline style emitters.
- A computed `extends` or `include` target: both must be a constant string. `import` must be
  `_self`, as in `{% import _self as x %}`.

A block template is held to two more rules. Every style target its block type declares must be
styled somewhere in the template, no undeclared target may be, and the target that takes part in
a parent container's layout must be the first one styled — which is how the linter says
"outermost element". And every `blocks` field of the type must be named once by `slot_attrs()`,
unless the type renders its children's data inline.

Two templates can never pass, because of what they are for, and the admin marks them read-only
rather than offering a Save that would fail: `blocks/html.twig`, which is the raw-HTML escape
hatch, and `blocks/shortcode.twig`, which dispatches on a name it does not know until it runs.
Edit those on disk.
