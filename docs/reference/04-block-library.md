---
title: "The block library"
slug: block-library
section: reference
order: 4
summary: "Every block that ships: what it is for, its fields, and its style settings."
---

Thallo ships **45 block types**. Two [capabilities](../concepts/06-capabilities.md) add more:
Accounts adds four, Commerce adds five. This page lists all of them, in the order the Blocks tab
and **Settings › Block Types** show them.

## How to read the tables

Each block type has a **slug**, and the slug names its template: a block of type `hero` renders
through `templates/blocks/hero.twig`. A [theme](../concepts/04-themes.md) overrides one by
shipping its own copy at `themes/<name>/templates/blocks/<slug>.twig`; every template it does not
ship keeps rendering through the default theme's.

**Fields** lists each field's name and type. The types are the content model's, described in
[the field types](../concepts/01-content-model.md#the-field-types). A field is optional unless
the table says `required`. A field of type `blocks` holds other blocks: that is the **Holds
blocks** column, which names the block types the picker offers inside it. That list is a picker
convenience, not a rule: a block of another type saved through the API is not rejected. The
exception is a slot the table marks **only**, where anything else is refused.

**Style settings** names what the block adds to the inspector's Style and Layout tabs beyond the
four every starter has: **Spacing**, **Visibility**, **Sizing in a parent layout** and **Motion**.
Four blocks have no **Motion**, because a visitor never watches them arrive: Tab, Accordion item,
Spacer and Animated text. [Style settings](05-style-settings.md) says what each setting does and
what it becomes in CSS.

Categories lead with Layout, Content, Media and Items; any other category follows in alphabetical
order, which is why Account, Advanced and Commerce come last. Within a category the admin puts the
active blocks first and orders each part by label; that is the order used here.

## Layout

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Container** (`container`) | Free-form wrapper: background image or video, overlay, and layout. It is the one block whose job is to arrange its children. | `element` (enum: div, section, article, aside, header, footer), `background_image` (asset), `background_video` (asset), `background_video_url` (string), `bg_size` (enum: cover, contain, auto), `bg_position` (enum: center, top, bottom, left, right), `overlay` (enum: none, light, dark), `overlay_opacity` (enum: 25, 50, 75), `content` (blocks) | `content`: any | Width, Placement, Colours, Corners, Border, Shadow, Backdrop, Minimum height, Overflow; the Layout tab's Container section (Layout, Direction, Wrap, Distribute, Align, Columns, Gap, Content width, Gutter); Stagger children and Ken Burns |
| **Footer** (`footer`) | A footer bar: copyright, links and social, over an optional top band. | `top` (blocks), `copyright` (blocks), `links` (blocks), `social` (blocks) | `top`: any; `copyright`: any; `links`: Links, Navigation; `social`: Social links | Background, Text colour |
| **Navigation** (`navigation`) | Links from a navigation menu: you pick a menu, not the links. | `menu` (string, required — a menu's slug), `orientation` (enum: horizontal, vertical), `align` (enum: start, center, end), `size` (enum: sm, md, lg), `variant` (enum: pill, link), `color` (enum: primary, neutral), `highlight` (enum: none, underline, bar), `submenu_layout` (enum: dropdown, columns), `submenu_icon` (enum: chevron-down, chevron-right, plus, none), `submenu_trigger` (enum: hover, click), `aria_label` (string) | — | Distribute |
| **Separator** (`separator`) | A horizontal rule, optionally with a centred label and icon. | `label` (string), `type` (enum: solid, dashed, dotted), `size` (enum: xs, sm, md, lg, xl), `icon` (string — a Lucide icon name) | — | Border colour |
| **Spacer** (`spacer`) | Vertical breathing room. | `size` (enum: small, medium, large) | — | — (no Motion) |
| **Style** (`style`) | Re-skins a group of blocks with a chosen accent and neutral. | `accent` (enum: `inherit` or one of the accent colours), `neutral` (enum: `inherit`, slate, gray, zinc, neutral, stone), `content` (blocks) | `content`: any | Shadow |

The Style block's accents are the site's own, listed in
[change how the site looks](../guides/01-appearance.md#set-the-accent-and-neutral-colours). Building
a header or a footer out of these is
[the header and the footer](../guides/02-header-and-footer.md); filling a Navigation block's menu
is [menus and navigation](../guides/03-navigation.md).

## Content

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Accordion** (`accordion`) | A stack of expandable question and answer items. | `title` (string), `multiple` (boolean), `items` (blocks) | `items`: Accordion item | — |
| **Animated text** (`animated_text`) | A heading with a reveal effect and an optional rotating word. | `prefix` (string), `rotate_words` (text — one alternative per line, at most five), `suffix` (string), `effect` (enum: fade, slide-up, blur), `loop` (boolean), `interval` (number, 0.5 to 10 seconds), `tag` (enum: h1, h2, h3, p), `prefix_color` / `rotate_color` / `suffix_color` (token, colour), `prefix_size` / `rotate_size` / `suffix_size` (enum: inherit, sm, lg, xl), `prefix_bold` / `rotate_bold` / `suffix_bold` (boolean), `prefix_italic` / `rotate_italic` / `suffix_italic` (boolean) | — | Text alignment, Typography, Text colour (no Motion) |
| **Blog posts** (`blog_posts`) | Lists published entries as cards. The list is built when the page renders. | `type` (string — a content type's slug), `limit` (number, 1 to 12), `order` (enum: newest, oldest), `category` (string), `columns` (enum: 1, 2, 3, 4), `variant` (enum: outline, soft, subtle, ghost, naked), `orientation` (enum: vertical, horizontal) | — | Width |
| **Button** (`button`) | A standalone action button. | `label` (string, required), `url` (string, required), `variant` (enum: solid, outline, soft, subtle, ghost, link), `color` (enum: primary, neutral), `size` (enum: xs, sm, md, lg, xl), `leading_icon` (string), `trailing_icon` (string), `block` (boolean — full width) | — | Distribute, Corners, Colours, Typography, Shadow |
| **Call to action** (`cta`) | A call-to-action band with buttons. | `title` (string, required), `description` (text), `variant` (enum: solid, outline, soft, subtle, naked), `orientation` (enum: vertical, horizontal), `reverse` (boolean), `links` (blocks), `links_align` (enum: start, center, end) | `links`: Button | Width, Corners, Shadow, Colours, Border, Typography |
| **Card** (`card`) | A content card: icon, title, description and nested blocks. | `icon` (string), `title` (string), `description` (text), `variant` (enum: outline, solid, soft, subtle, ghost, naked), `orientation` (enum: vertical, horizontal), `reverse` (boolean), `body` (blocks) | `body`: any | Corners, Shadow, Colours, Border, Typography |
| **Carousel** (`carousel`) | A swipeable slider: each child block is a slide. | `slides` (blocks), `slides_per_view` (enum: 1, 2, 3), `arrows` (boolean), `dots` (boolean), `autoplay` (boolean), `style` (enum: default, hero), `transition` (enum: slide, fade, zoom), `speed` (enum: slow, normal, fast), `height` (enum: compact, standard, tall, full) | `slides`: any | Width, Corners, Shadow |
| **Code** (`code`) | A code snippet with a language label and a copy button. | `code` (text, required), `language` (enum: text, bash, php, json, yaml, html, css, javascript, typescript, twig, sql), `label` (string), `copy` (boolean), `size` (enum: default, compact), `note` (string) | — | Width, Corners, Shadow |
| **Collapsible** (`collapsible`) | A single show/hide disclosure wrapping nested blocks. | `label` (string), `open` (boolean), `content` (blocks) | `content`: any | Corners, Background, Border |
| **Color mode** (`color_mode`) | A light / system / dark colour-mode switch for visitors. | none | — | Distribute |
| **Form** (`form`) | A contact form: stores submissions and emails a recipient. | `form_name` (string), `recipient` (string), `delivery` (enum: store_and_email, email_only), `success_message` (text), `redirect_url` (string), `submit_label` (string), `submit_variant` (enum: solid, outline, soft, subtle, ghost, link), `submit_color` (enum: primary, neutral), `heading` (string), `intro` (text), `name_label` (string), `email_label` (string), `message_label` (string), `include_subject` (boolean), `subject_label` (string), `include_phone` (boolean), `phone_label` (string), `phone_required` (boolean), `include_consent` (boolean), `consent_text` (string) | — | Width, Corners, Background, Border, Shadow |
| **Heading** (`heading`) | A single heading or label line. | `text` (string, required), `level` (enum: h1, h2, h3, h4, h5, h6) | — | Width, Placement, Text alignment, Typography, Text colour |
| **Hero** (`hero`) | Big heading, supporting copy, buttons and media. | `headline` (string), `title` (string, required), `description` (text), `links` (blocks), `image` (asset), `aside` (blocks), `orientation` (enum: vertical, horizontal), `split` (enum: equal, copy, media), `reverse` (boolean), `background` (enum: gradient, none, muted, inverted), `gradient_color` (enum: `accent` or one of the accent colours), `gradient_strength` (enum: subtle, medium, strong), `heading_level` (enum: h1, h2, h3) | `links`: Button; `aside`: any | Width, Background, Text colour, Typography, Corners, Shadow, Aside; Ken Burns |
| **Links** (`links`) | A vertical list of navigation links with an optional title. | `title` (string), `items` (json — an array of objects with `label`, `url`, and optionally `icon`, `active` and `new_tab`) | — | Title: Typography, Text colour, Text alignment; each link (its **Link** section): Typography, Text colour, Padding |
| **Pricing plan** (`pricing_plan`) | A single pricing plan card: price, features and a call to action. | `title` (string), `description` (text), `price` (string), `discount` (string), `billing_period` (string), `billing_cycle` (string), `badge` (string), `features` (text), `feature_icon` (string), `tagline` (string), `terms` (text), `button_label` (string), `button_url` (string), `plan_key` (string), `button_variant` (enum: solid, outline), `variant` (enum: outline, solid, soft, subtle), `highlight` (boolean), `orientation` (enum: vertical, horizontal) | — | Corners, Shadow, Colours, Border |
| **Pricing plans** (`pricing_plans`) | A row or stack of pricing plans, with an optional featured plan. | `plans` (blocks), `orientation` (enum: horizontal, vertical), `compact` (boolean), `scale` (boolean) | `plans`: Pricing plan | Width |
| **Pricing table** (`pricing_table`) | A feature-comparison table across pricing tiers. | `tiers` (blocks), `features` (blocks), `highlight` (boolean) | `tiers`: Pricing tier; `features`: Pricing feature | Width |
| **Rich text** (`rich_text`) | Free-form formatted text. | `body` (text, rich) | — | Width, Placement, Text alignment, Typography, Text colour |
| **Social links** (`social_links`) | A row of brand icons linking to social profiles. | `items` (blocks) | `items`: Social link | Distribute |
| **Stepper** (`stepper`) | A numbered sequence of steps, horizontal or vertical. | `title` (string), `orientation` (enum: vertical, horizontal), `color` (enum: primary, secondary, success, info, warning, error, neutral), `size` (enum: xs, sm, md, lg, xl), `items` (blocks) | `items`: Stepper item | — |
| **Tabs** (`tabs`) | Tabbed panels of blocks. | `items` (blocks), `variant` (enum: pill, underline, boxed), `align` (enum: start, center, end, stretch), `list_background` (enum: transparent, surface, surface-2, accent, text), `tab_color` (enum: accent, text, muted, accent-contrast, background), `active_background` (enum: transparent, surface, surface-2, accent, text, background), `active_color` (enum: accent, text, muted, accent-contrast, background), `panel_padding` (enum: none, sm, md, lg) | `items`: Tab | Colours, Corners, Border, Shadow, Tabs |

Setting a Form block up, including where its submissions go, is
[add a contact form](../guides/07-forms.md). The Carousel's and the Animated text block's motion,
and the Motion settings every other block has, are
[animate the page](../guides/06-animation.md).

## Media

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Audio** (`audio`) | An uploaded audio file with the browser's own controls. | `audio` (asset, required), `title` (string) | — | Width |
| **File** (`file`) | A download link to an uploaded file. | `file` (asset, required), `label` (string), `new_tab` (boolean) | — | — |
| **Gallery** (`gallery`) | A responsive image grid with an optional lightbox. | `items` (blocks), `columns` (enum: 2, 3, 4), `aspect` (enum: natural, square, landscape), `lightbox` (boolean) | `items`: Image **only** | — |
| **Icon** (`icon`) | A single decorative icon from the Lucide set, optionally linked. | `icon` (string, required), `size` (enum: small, medium, large), `align` (enum: start, center, end), `url` (string), `label` (string) | — | Text colour, Placement |
| **Image** (`image`) | A single image with a caption. | `image` (asset, required), `alt` (string), `caption` (string), `width` (number, px), `height` (number, px), `fill` (boolean) | — | Width, Placement, Corners, Shadow |
| **Logo** (`logo`) | The site logo from **Site › Appearance**; falls back to the site name. | `size` (enum: small, medium, large), `link_home` (boolean) | — | — |
| **Logos** (`logos`) | A "trusted by" strip of brand logos. | `title` (string), `images` (asset, several), `grayscale` (boolean), `scroll` (boolean) | — | — |
| **Map** (`map`) | A Google map of your address, with directions. No API key: Google's own embed. | `place` (string), `embed_url` (string), `zoom` (number, 1–21), `view` (enum: map, satellite), `height` (enum: small, medium, large), `directions` (boolean), `click_to_load` (boolean), `caption` (string) | — | Width, Corners, Border, Shadow |
| **Video** (`video`) | An uploaded video or a YouTube or Vimeo embed. | `source` (enum: upload, embed), `video` (asset), `url` (string), `poster` (asset), `caption` (string), `width` (enum: normal, wide, full) | — | Width, Corners, Shadow |

Uploading the files these blocks point at is [the media library](../guides/08-media.md).

## Items

These are single-purpose children of the collection blocks above. Each one also has a template of
its own, so a stray Accordion item outside an Accordion still renders.

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Accordion item** (`accordion_item`) | One question with a rich-text answer. | `question` (string, required), `answer` (text, rich) | — | — (no Motion) |
| **Feature** (`feature`) | One feature: icon or number, title, description, link. | `icon` (string), `title` (string, required), `description` (text), `url` (string), `marker` (enum: icon, number, none), `number` (string), `marker_background` (enum: transparent, surface, surface-2, accent, text), `marker_color` (enum: accent, text, muted, accent-contrast, background), `marker_size` (enum: sm, md, lg, xl), `variant` (enum: plain, outline, soft, subtle), `orientation` (enum: horizontal, vertical) | — | Corners, Colours, Border, Shadow, Typography, Marker |
| **Pricing feature** (`pricing_feature`) | One comparison row, or a section heading, with a value per tier. | `is_section` (boolean), `title` (string), `value_1` to `value_4` (string) | — | — |
| **Pricing tier** (`pricing_tier`) | One column of a pricing table: title, price and call to action. | `title` (string), `description` (text), `price` (string), `discount` (string), `billing_period` (string), `billing_cycle` (string), `badge` (string), `button_label` (string), `button_url` (string), `button_variant` (enum: solid, outline), `highlight` (boolean) | — | — |
| **Social link** (`social_link`) | One social profile: brand icon and URL. | `icon` (string, required — a `brand:` icon name), `url` (string, required), `label` (string) | — | Text colour |
| **Stepper item** (`stepper_item`) | One numbered step: title and description. | `title` (string, required), `description` (text) | — | — |
| **Tab** (`tab`) | One tab: label and panel blocks. | `label` (string, required), `content` (blocks) | `content`: any | — (no Motion) |

## Advanced

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Shortcode** (`shortcode`) | Renders the theme's `templates/shortcodes/<name>.twig`. | `name` (string, required), `params` (json, handed to that template as `params`) | — | Colours, Border, Corners, Shadow |
| **HTML** (`html`) | Raw HTML, rendered verbatim. | `code` (text) | — | — |

The HTML block ships **deactivated**: it is not in the Blocks tab until somebody turns it on in
**Settings › Block Types**. Raw output is an explicit choice, not a default.

A Shortcode block's style settings land on whatever element the shortcode's own template puts them
on, so a shortcode that ignores them renders as it always did. Writing one is part of
[make your own theme](../guides/13-make-a-theme.md).

## Account

Four blocks the **Accounts** capability (`thallo.accounts`) contributes. They are seeded while the
capability is on, and their rows disappear from **Settings › Block Types** while it is off —
nothing is deleted, and a page that already holds one keeps its data. Their style settings start
from **Spacing** and **Visibility** only: no **Sizing in a parent layout**, and no **Motion**.

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Account state** (`auth-state`) | Shows one set of blocks to signed-out visitors and another to signed-in ones. | `signed_out` (blocks), `signed_in` (blocks) | Both slots: Button, Links, Rich text, Logo, Navigation, Sign-in form, Registration form, Password reset request — **only** | — |
| **Password reset request** (`forgot-password-form`) | The request-a-reset-code form, embeddable on any page. Continues into the reset flow. | `heading` (string) | — | Width, Corners, Background, Border, Shadow |
| **Registration form** (`register-form`) | The create-account form, embeddable on any page. Continues into the email-verification flow. | `heading` (string) | — | Width, Corners, Background, Border, Shadow |
| **Sign-in form** (`login-form`) | The sign-in form, embeddable on any page. A failed attempt returns to this page with an inline error. | `heading` (string), `next` (string — where to go after signing in), `show_links` (boolean) | — | Width, Corners, Background, Border, Shadow |

Which pages these compose, and what the capability turns on besides blocks, is
[let visitors have accounts](../guides/17-accounts.md).

## Commerce

Five blocks the **Commerce** capability (`thallo.commerce`) contributes, on the same terms as the
Account blocks above: seeded while it is on, hidden while it is off, and starting from **Spacing**
and **Visibility** only.

| Block | What it is for | Fields | Holds blocks | Style settings adds |
|---|---|---|---|---|
| **Add to cart** (`add-to-cart`) | An add-to-cart control for a product. Left blank, it uses the product linked to the entry being rendered. | `product_slug` (string) | — | — |
| **Featured product** (`featured-product`) | Spotlight a single product. | `product_slug` (string) | — | Corners, Shadow, Colours, Border |
| **Mini cart** (`mini-cart`) | A cart count and drawer that fills in over JavaScript; a plain cart link without it. | none | — | — |
| **Product grid** (`product-grid`) | A grid of products from a category, a tag, a manual list, or the newest arrivals. | `source` (enum: category, tag, manual, newest), `category_slug` (string), `tag_slug` (string), `products` (text — one product slug per line), `page_size` (enum: small, medium, large) | — | Width |
| **Wishlist link** (`wishlist-link`) | A link to the wishlist page with a live saved-item count; a plain wishlist link without JavaScript. | `label` (string) | — | — |

Turning Commerce on and connecting a shop is [sell something](../guides/18-commerce.md).

## Checking what an install has

The tables above are what Thallo ships. An install can hold more, because anyone can make a block
type, and fewer in the picker, because any type can be switched off. **Settings › Block Types**
is the list that install actually has: each card shows the label, the slug, the category and
whether the type is active.

To add one of your own, see [make your own block type](../guides/14-make-a-block-type.md). For
what a block is and how its data is stored, see
[blocks and block types](../concepts/02-blocks.md).
