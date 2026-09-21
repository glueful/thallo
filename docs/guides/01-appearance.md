---
title: "Set your colours, fonts and logo"
slug: appearance
section: guides
order: 1
summary: "Give the site your brand colour, your own fonts and your logo, and see it before you save."
---

At the end of this the site carries your accent colour, your typefaces, your logo and your
favicon — and you will have seen each choice on your own homepage before it went live. None of it
touches a template, so it works on whichever theme the site wears.

You need an install you can sign in to, and a homepage: the preview renders the entry set as the
homepage under **Settings › General**, and [build your first page](../getting-started/03-first-page.md)
sets one. Without it the preview pane says so and links there; everything else still works.

## Open Site › Appearance

In the sidebar, open the **Site** group and press **Appearance**. Four cards run down the left —
**Theme**, **Theme colors**, **Design**, **Logos & site icon** — with the preview beside them,
staying put while the cards scroll. On a narrow window the preview moves above them.

Nothing here reaches the site until you press **Save**, at the top right. A dot appears on that
button as soon as anything changes.

## Choose a theme

The **Theme** card shows every theme the install can serve: its screenshot, title, version and
author. A theme with no screenshot is drawn in its own colours instead. The theme being served now
is badged **Live**.

Press a card to choose it, or move the choice with the arrow keys. A chosen theme that is not the
live one says **Chosen — Save to make it live**, and the preview switches to it. The card is absent
on an install whose renderer serves no theme list.

What a theme is, and how to make one: [themes](../concepts/04-themes.md) and
[make your own theme](13-make-a-theme.md).

## Set the accent and neutral colours

The **Theme colors** card re-skins the theme's tokens. **Accent** is buttons, links and small
accents; **Neutral** is the backgrounds, text and borders. The defaults, `blue` and `slate`,
reproduce the theme exactly as it ships.

Pick **Accent** from the seventeen colour families — `red`, `orange`, `amber`, `yellow`, `lime`,
`green`, `emerald`, `teal`, `cyan`, `sky`, `blue`, `indigo`, `violet`, `purple`, `fuchsia`, `pink`,
`rose` — or choose **Brand colour…** for your own. That opens a colour picker and a hex box
starting from the colour the site has now. Type a hex such as `#0a7c66`; three digits are written
out to six. Until what you typed is a colour, the field reads `Not a colour yet.` and the accent
stays as it was.

A brand colour is used exactly as given on a light page, and the card says how it will read before
you save:

- A sample button in your colour, labelled in the ink the site will use — white or black,
  whichever reads on it — with the contrast ratio. One of the two always clears WCAG AA, so the
  label is always readable.
- The colour's contrast on a white page, where it is links and small text. Below 3:1 it reads
  `Hard to read as links and text on a white page` — buttons are unaffected, but a darker shade
  would fix the links.
- On a dark page Thallo lightens the colour, keeping its hue, until it can be seen there.

**Neutral** is always one of `slate`, `gray`, `zinc`, `neutral` and `stone`. A whole grey scale
cannot be derived from one colour, so no hex is offered here.

## Set the corners, typefaces and page ground

The **Design** card holds three more choices.

- **Corners** — **Sharp** (4px corners, square buttons), **Soft** (12px corners, rounded buttons)
  or **Round** (12px corners, pill buttons), the default.
- **Typefaces** — nine choices. **Sans**, the default, is the theme's own face (Figtree)
  throughout. **Serif**, **Humanist**, **Geometric**, **Mono** and **System** set the whole site in
  fonts the visitor already has, and the theme's own face is then not downloaded at all.
  **Editorial** and **Slab** change the headings only and leave the body in the theme's face.
  **Custom** is your own files.
- **Page ground** — **Plain** (white page, tinted panels) or **Tinted** (tinted page, white
  panels). Tinted changes light mode only; dark mode keeps the theme's own ground.

Choosing **Custom** opens two uploads, **Text** and **Headings**. Each takes one `.woff2` file: it
is the one format every browser a theme supports reads, and the only one the field accepts. A
variable font covers every weight from a single file. After the upload the field sets a specimen in
the face, so you see it rather than a file name; **Replace** swaps it, **Remove** takes it off.
Upload a text font only and the headings follow it; upload neither and the theme's own font stays.

The file goes to the media library, which accepts a font only while `font/woff2` is listed in
`allowed_types` in `config/uploads.php`. An install made before that entry existed refuses it as
`Invalid file type`; add the line and upload again.

## Upload your logo and favicon

The **Logos & site icon** card has three pickers. Press one for a chooser with an **Upload** tab
and a **Media library** tab; either way it stores one file.

- **Site logo** — shown by the Logo block and by themes. When it is unset, the site name is shown
  instead.
- **Site logo (dark)** — for visitors using a dark colour scheme. It falls back to the main logo,
  and a theme without a dark scheme ignores it.
- **Favicon** — the site's icon in browser tabs and bookmarks. PNG or SVG, square, 512×512 or
  larger. Underneath, Thallo draws it as an app-icon tile and in a mock browser tab carrying the
  site's name, which is how you catch an icon that disappears at that size.

The files themselves: [manage images and files](08-media.md).

## Check the preview at three widths

The preview frames your homepage wearing everything in the form, saved or not. It re-renders a
moment after you stop changing things; the three buttons above it hand the page a desktop width,
768px or 390px, and **Open** opens the same previewed page in a new tab. If a render fails, the
badge **Preview not updated** appears and the last good frame stays.

Logos and the favicon are the one thing it cannot show before a save: a preview session carries the
look, not your uploads.

## Save, and see it on the site

Press **Save**. Thallo answers `Appearance saved — Changes apply on the next page view.` and
writes only this page's settings, so nothing on **Settings › General** is disturbed. A value
outside the lists above is refused rather than stored.

Open the site and reload. The theme, the colours and the design settings are live at once: saving
one of them clears the rendered page cache. A new logo or favicon does not clear it, so a page
already cached keeps the old image until its entry expires — an hour by default,
`RENDER_CACHE_TTL` in `.env`. To see it immediately, clear the cache:

```bash
$ php glueful render:cache:clear
```

For what these settings cannot do — CSS of your own, loaded after everything else — open
**Site › Theme editor** and edit `custom.css`.
