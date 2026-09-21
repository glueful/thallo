---
title: "Themes"
slug: themes
section: concepts
order: 4
summary: "What a theme is, what it controls, and what it leaves to the site's settings."
---

A theme is what the public site looks like. It holds the Twig templates that turn published
entries and their blocks into HTML, and the CSS those templates paint with. It holds no content,
so changing a site's theme changes every page's look and rewrites nothing.

Thallo ships one theme, named `default`. It comes with the `glueful/thallo-render` pack, styles
every block that ships, and is what a new install serves.

## A theme is a folder

```text
themes/my-theme/
  theme.json
  screenshot.jpg
  templates/
  assets/
```

`theme.json` is the manifest. Two of its keys are required: `vocabulary`, which maps every name
of the style vocabulary — the `spacing`, `width`, `radius`, `color`, `shadow` and
`typography.size` scales the Design view offers — to a CSS value, and `stylesheets`, which lists
the theme's CSS files in load order. A theme missing a vocabulary name, or listing a stylesheet
that is not there, fails to load, cannot be switched to, and is reported by
`php glueful thallo:doctor`. The rest of `theme.json` is optional and describes the theme's card
in the admin: `title`, `description`, `author`, `tags`, `screenshot` and `colors`. A wrong value
there is left off the card rather than treated as an error.

A site's own themes live in `themes/` at the root of the project, one folder each. The default
theme lives inside the pack; you never edit it, and you never need to copy all of it — see
[make your own theme](../guides/13-make-a-theme.md).

## Which template renders which page

Every URL the site serves picks one template out of `templates/`.

| The page | The template |
|---|---|
| The homepage, `/` | `index.twig` |
| An entry, `/{type}/{slug}` | `entry/{type}.twig`, else `entry.twig` |
| A type's listing, `/{type}` | `listing/{type}.twig`, else `listing.twig` |
| A term's archive, `/{type}/{field}/{term}` | `archive/{type}.twig`, else `archive.twig` |
| A field's term index, `/{type}/terms/{field}` | `terms/{type}.twig`, else `terms.twig` |
| Nothing found | `404.twig` |
| A removed URL, or a failed render | `error.twig` |

The pattern in the middle four rows is the whole hierarchy: **a template named after a content
type wins over the general one.** Add `entry/recipe.twig` and every recipe renders through it;
every other type keeps using `entry.twig`. Entry templates are also handed the content type's
slug as `type`, so a template that needs to know its type does not have to be named after one.

`layout.twig` is the shell every page template extends: the `<head>`, the header and footer, and
the `{% block content %}` the page template fills.

**Fallback is per file.** When the active theme has no copy of a template, the default theme's
copy renders instead. A theme ships only the files it changes.

## Regions and blocks

Two parts of the page are not the page template's: the **header** and the **footer**. They are
regions — chrome rendered around every page, edited under **Site › Header & footer**. A template
asks for a region's saved blocks, its settings and its style classes, and falls back to its own
hardcoded header or footer when nothing is bound. A page can hide either one. See
[edit the header and footer](../guides/02-header-and-footer.md).

Everything between them is blocks. Each block type has one template at
`templates/blocks/<slug>.twig`, which receives that block's fields as `data`. A theme overrides a
block by shipping its own copy of that file. See
[blocks and block types](02-blocks.md#one-template-per-block-type).

## What the owner changes without touching the theme

**Site › Appearance** re-skins the active theme. It changes the theme's design tokens and never a
template, so it works on any theme that reads them.

- **Theme colors.** **Accent** is one of seventeen colour families, or the site's own brand
  colour as a hex. **Neutral** — the backgrounds, text and borders — is one of `slate`, `gray`,
  `zinc`, `neutral` and `stone`; a whole grey scale cannot be derived from a single colour, so a
  hex is not offered here. The defaults are `blue` and `slate`.
- **Design.** **Corners** (`round`, `soft` or `sharp`), **Typefaces** and **Page ground**
  (`plain` or `tinted`). **Typefaces** offers nine choices: the theme's own face, seven pairings
  built from fonts the visitor already has, and **Custom**, which uses `.woff2` files you upload
  into the media library.
- **Logos & site icon.** The site logo, a dark-scheme variant, and the favicon.

Each choice is emitted as a small `:root` override plus its dark-mode counterpart, after the
theme's own CSS. The defaults emit nothing at all, so a site that changes none of this serves the
theme exactly as written. The page shows your homepage wearing the pending settings at three
device widths; nothing reaches the site until you press **Save**. See
[set your colours, fonts and logo](../guides/01-appearance.md).

Last in the cascade is `custom.css`, edited under **Site › Theme editor**. It loads after
everything else, which makes it the final override.

## Light and dark mode

A visitor chooses light, dark or system. The choice is stored in the browser, under
`thallo.colorMode`, and a small script in the `<head>` stamps `data-theme="light"` or
`data-theme="dark"` on `<html>` before the CSS loads. The rendered HTML carries no mode of its
own, so one cached page serves every visitor.

Dark mode is a token re-map and nothing more: the theme's CSS declares its light values under
`:root` and its dark values under `html[data-theme="dark"]`, and every block paints from those
variables. No block needs a dark rule.

Put the **Color mode** block in the header to give visitors the switch. To turn the whole thing
off, set `THALLO_COLOR_MODE_ENABLED=false` in `.env`: the script and the block stop rendering,
and the site serves its light tokens to everyone.

## Choosing the theme

**Site › Appearance** lists every theme as a card — the default first, then each folder under
`themes/` that loads. Pick one and press **Save**; the next page view uses it.

A folder becomes a card only when it holds `templates/` and a `theme.json` whose vocabulary is
complete and whose stylesheets exist. A folder that fails any of that is not offered, which is
how a half-finished theme stays off the live site.

`RENDER_THEME` in `.env` names the theme to use when nothing has been chosen in the admin. The
saved choice wins over it, and both fall back to `default`.

Next: [make your own theme](../guides/13-make-a-theme.md), which starts from the default one.
