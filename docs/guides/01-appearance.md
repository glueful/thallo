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

In the sidebar, open the **Site** group and press **Appearance**. Five tabs run along the left —
**Theme**, **Colours**, **Design**, **Typefaces**, **Logos & site icon** — each on its own tab, with
the preview beside them, staying put whichever tab is open. On a narrow window the preview moves
above them. The open tab is in the address (`/appearance?tab=colours`), so a link can open a tab and
Back returns to the one before.

Nothing here reaches the site until you press **Save**, at the top right. A dot appears on that
button as soon as anything changes. **Save** saves every tab at once; a dot on a tab marks unsaved
changes there, or a field a save refused.

## Choose a theme

The **Theme** tab shows every theme the install can serve: its screenshot, title, version and
author. A theme with no screenshot is drawn in its own colours instead. The theme being served now
is badged **Live**.

Press a card to choose it, or move the choice with the arrow keys. A chosen theme that is not the
live one says **Chosen — Save to make it live**, and the preview switches to it. The tab is absent
on an install whose renderer serves no theme list, and Appearance then opens on **Colours**.

What a theme is, and how to make one: [themes](../concepts/04-themes.md) and
[make your own theme](13-make-a-theme.md).

## Set the accent and neutral colours

The **Colours** tab re-skins the theme's tokens. **Accent** is buttons, links and small
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

**Neutral** is one of `slate`, `gray`, `zinc`, `neutral` and `stone`, or **Custom — your own
colours**.

### A Custom neutral

Choose **Custom — your own colours** and six fields open, one hex each:

| Field | What it colours |
|---|---|
| Background | the page |
| Surface | panels and cards |
| Surface 2 | raised or alternate panels, bands |
| Text | body text and headings |
| Muted | secondary text |
| Line | borders and dividers |

The first time, they are filled in from the family you had, so you start from what the site shows
now and change only what you want. Switching back to a family keeps your six colours in the form;
**Reset to** the family — `Reset to slate`, say — takes them off.

Your six colours are for light mode. Dark mode is built from a family — **Dark mode base**, shown
when the theme has a dark mode — because six light colours do not say what their dark versions are.

### Brand colours

Up to three **Brand colours**, each a name and a hex: `Gold dark`, `#8a6a2a`. Every block colour
picker offers them by that name, beside the theme's colours, each with its swatch. A brand colour has
a text colour too — `Gold dark — text` — which is white or black, whichever reads on it; in dark mode
Thallo lightens the colour, keeping its hue, until it can be seen.

Renaming or re-colouring a brand colour changes every block using it, at once.

**Clear** takes a brand colour off. Thallo first checks where it is used:

- **Nowhere current:** it is cleared. If older versions of pages still name it, the dialog says how
  many; restoring one shows that colour as `Unavailable colour` — no colour applied — until you
  choose another.
- **Still used** — on a page's draft or live version, the header or footer, a layout, a saved section
  or a style class: it cannot be cleared. The dialog lists where and offers **Replace with…**: choose
  the colour that takes its place, and Thallo rewrites every one of those places, then clears it.
  Live pages get a new version, so the previous one stays in their history. When the colour you
  choose has no text colour of its own (anything but Accent or another brand colour) and something
  uses `Gold dark — text`, you also choose what that text becomes, and see the pair's contrast in
  light and dark mode.

While a replacement runs, its brand colour shows the progress in place of its fields and cannot be
renamed or cleared; editors saving a page with the old colour store the new one. A replacement that
stops part-way can be resumed, and one can be cancelled — what it already rewrote stays rewritten.

### Contrast

Below the colours, **Contrast** checks the pairs text sits on — Text and Muted on Background,
Surface and Surface 2; Accent on Background and its text on Accent; each brand colour on Background
and its text on it — in light and dark mode, as the unsaved colours stand. A pair under 4.5:1 is a
warning, not a refusal. Other combinations a block can make are not checked.

## Set the corners and page ground

The **Design** tab holds two more choices.

- **Corners** — **Sharp** (4px corners, square buttons), **Soft** (12px corners, rounded buttons)
  or **Round** (12px corners, pill buttons), the default.
- **Page ground** — **Plain** (white page, tinted panels) or **Tinted** (tinted page, white
  panels). Tinted changes light mode only; dark mode keeps the theme's own ground.

## Choose the typefaces

On the **Typefaces** tab, **Pairing** picks how the site's text is set, from nine choices.
**Sans**, the default, is the theme's own face (Figtree) throughout. **Serif**, **Humanist**,
**Geometric**, **Mono** and **System** set the whole site in fonts the visitor already has, and the
theme's own face is then not downloaded at all. **Editorial** and **Slab** change the headings only
and leave the body in the theme's face. **Custom** is two families you choose from the font library.

Choosing **Custom** opens two pickers, **Text** and **Headings**. Each lists **Not set**, the
built-ins and your own families, every one written in its own face. Choose a text family only and
the headings follow it; choose neither and the theme's own font stays. **Add a font…**, at the end
of either list, adds a family to the library (below) and picks it.

## Add your own typefaces

Below **Pairing**, the **Typefaces** tab holds the site's font library: what Custom, every block's **Typeface** and every
style class can be set in. **Built-in** lists the seven that cost a visitor nothing to download —
**Theme** (the theme's own face), **Serif**, **Humanist**, **Geometric**, **Slab**, **Mono** and
**System** — each written in its own stack. **Your fonts** lists the families you added, with their
faces, their fallback and how many places use them.

Press **Add family**, give it a name and a fallback — the kind of stack that shows while the font
loads, and for anything it lacks — and drop its `.woff2` files on the box, or choose them. Add one
file per weight and style, or one variable file for every weight. Thallo reads each file for the
weights and styles it really draws, so a label never decides it: the faces line then reads, for
example, `Faces: 400, 700, 400 italic` or `Faces: 300–900 variable`. A file it cannot read is
refused, named with the reason — `Not a WOFF2 font`, for one — and the family is not added; a file
chosen twice is added once. One family takes at most 18 files at a time — nine weights, upright and
italic — and a set that takes too long to read is refused with `These files took too long to read;
add fewer at a time`.

A family's files are served to every visitor, so adding a file to the library makes it public,
whatever the install's default upload visibility. Should one become private later, the family's
row says so and visitors see its fallback; `php glueful thallo:provision` makes it public again.

Each file goes to the media library, which accepts a font only while `font/woff2` is listed in
`allowed_types` in `config/uploads.php`. An install made before that entry existed refuses it as
`Invalid file type`; add the line and add the family again.

**Edit** renames a family or changes its fallback. **Remove** first lists everything that uses
it — pages and posts, the header and footer, layouts, saved sections, style classes and Appearance —
and says what happens there: blocks set in it inherit their parent's font, and Appearance falls back,
Text to the theme's face and Headings to Text. A removed family moves to **Removed families**, at
the bottom of the card, where **Restore** brings it back with everything that names it, and
**Delete permanently** gives its files back to the media library. While a family exists, removed or
not, its files cannot be deleted from the media library.

A family added from files an older Thallo could not read shows **Unknown faces**. **Read again**
asks Thallo to read them now; if it succeeds, the font may render differently, because it is then
declared with the faces the files really hold.

## Upload your logo and favicon

The **Logos & site icon** tab has three pickers. Press one for a chooser with an **Upload** tab
and a **Media library** tab; either way it stores one file. **Remove**, beside a chosen file,
unsets it.

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

Open the site and reload. Everything on this page is live at once: saving the theme, a colour,
a design setting, a logo or the favicon clears the rendered page cache. A page open in the editor in
another tab reloads its stage to show the change, keeping whatever you had not yet saved there.

Colours of your own no longer need CSS: a **Custom** neutral and **Brand colours** above re-skin the
theme's tokens. For what these settings cannot do — CSS of your own, loaded after everything else —
open **Site › Theme editor** and edit `custom.css`.
