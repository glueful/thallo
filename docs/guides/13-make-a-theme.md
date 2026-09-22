---
title: "Make your own theme"
slug: make-a-theme
section: guides
order: 13
summary: "Start a theme from the default one, override a template, and ship your own CSS."
---

At the end of this page your site runs a theme of your own: a folder under `themes/`, copied
from the default theme, with one template and one stylesheet changed. Nothing under `vendor/` is
touched, so an upgrade leaves your work alone.

You need a shell in the project and a text editor. If you cannot deploy files, skip to
[edit templates from the admin](#edit-templates-from-the-admin), which changes the same templates
from a browser. What a theme is and which template renders which page is
[themes](../concepts/04-themes.md); this page is the work.

## Duplicate the default theme

The default theme ships inside the `glueful/thallo-render` pack. You never edit it; you copy it.

1. Copy it into your project:

   ```bash
   $ php glueful render:theme:clone my-theme
   ```

   The name must start with a lower-case letter or digit and hold only lower-case letters,
   digits, dashes and underscores. `default` is refused, and so is a name whose folder already
   exists. `--from=<theme>` copies one of your own themes instead of the default.

2. Look at what you got:

   ```text
   themes/my-theme/
     theme.json
     screenshot.jpg
     templates/
     assets/
   ```

   It is a full copy — templates, assets and `theme.json` — and it inherits nothing implicitly.
   In `theme.json`, `name` is now `my-theme`, the source theme's `title`, `author` and `tags` are
   gone, and `description` says which theme it came from. The screenshot is kept, which is true
   until you change the look.

The `themes/` directory has to be writable by whoever runs the command.

## Make the theme live

1. Open **Site › Appearance** in the admin. The **Theme** card shows a card per theme; the one
   being served is badged **Live**.
2. Press your theme's card. It says **Chosen — Save to make it live**, and the preview beside the
   cards switches to it.
3. Press **Save**. The next page view uses it.

A folder is only offered as a card when it holds `templates/` and a `theme.json` whose vocabulary
is complete and whose stylesheets all exist, so a half-finished theme cannot reach the site by
accident.

`RENDER_THEME` in `.env` names the theme to use when nothing has been chosen in the admin. The
saved choice wins over it, and a change to it takes effect when the app restarts.

## Change one template

Templates live in `themes/my-theme/templates/`, named as the
[template hierarchy](../concepts/04-themes.md#which-template-renders-which-page) names them.

1. Edit a file — `templates/entry.twig`, say, or `templates/blocks/hero.twig`.
2. Reload the site. Twig recompiles a template whose file changed, into
   `storage/cache/twig/{theme}`, so there is nothing to rebuild.
3. If the page still shows the old HTML, the rendered page cache is serving it. Clear it:

   ```bash
   $ php glueful render:cache:clear
   ```

   While you are working on a theme, set `RENDER_CACHE_ENABLED=false` in `.env` instead and the
   cache stays out of the way.

**Fallback is per template file.** Delete a template you have not changed and the default theme's
copy renders in its place, so a theme can be a handful of files. **Stylesheets do not fall back**:
the CSS a page loads is exactly the files your `theme.json` lists, and nothing of the default
theme's.

## Ship your own CSS

The theme's stylesheets are concatenated in manifest order into one artifact, served by content
hash. Add a file by putting it in `assets/` and adding it to `stylesheets` in `theme.json`; never
link it from a template.

The build refuses two things, fails the page, and names the file and line:

- `@import`, `@charset` and `@namespace`. The artifact is delivered inside `@layer theme` and
  cannot carry them; declare the stylesheet in `theme.json` instead.
- `!important` on a property the Style tab writes — padding, margin, width, colours, radius,
  border, shadow, `font-size`, `display` and their kin — in a rule whose selector holds
  `.thallo-block`. A block's settings sit in the layer above the theme and must always win.

One more rule has no error message: `var(--x)` with no fallback, where nothing defines `--x`,
makes the whole declaration invalid and the property silently takes its initial value. Define
every custom property your CSS reads.

## What `theme.json` declares

Three keys are required. `name` is the theme's own name. `vocabulary` maps every name of the
style vocabulary to a CSS value, and `stylesheets` lists the theme's CSS files in load order.
Miss a vocabulary name, or list a stylesheet that is not there, and the theme cannot load, cannot
be switched to, and is reported by `php glueful thallo:doctor` — which checks the theme chosen on
the Appearance page, or the one `RENDER_THEME` names when none is chosen.

These are the names, written `domain.name` — `spacing.lg`, `typography.size.2xl`:

| Domain | Names |
|---|---|
| `spacing` | `none` `xs` `sm` `md` `lg` `xl` `2xl` `3xl` |
| `width` | `narrow` `content` `container` `full` |
| `radius` | `none` `sm` `md` `lg` `full` |
| `color` | `background` `surface` `surface-2` `text` `muted` `line` `accent` `accent-contrast` `transparent` `white` |
| `shadow` | `none` `xs` `sm` `md` `lg` `xl` |
| `typography.size` | `xs` `sm` `md` `lg` `xl` `2xl` `3xl` |

A value is any CSS value, including a reference to a variable of your own: the default theme maps
`spacing.xs` to `var(--space-1)`, so changing one variable re-scales everything the Design view
offers. `color.white` fills itself in as `#ffffff` when a theme omits it; every other name has to
be there. A name the vocabulary does not have cannot be declared: there are no extra tokens.

The rest is optional and cannot break the theme — a wrong value is left off the card rather than
refused:

| Key | What it is |
|---|---|
| `title` | The name on the card. Without it, the folder's name. |
| `version` | Shown beside the title. |
| `description` | Up to 300 characters. |
| `author` | Who made it. |
| `tags` | Up to six short words. |
| `screenshot` | An image inside the theme's folder: `png`, `jpg` or `webp`, 2 MB at most. A `screenshot.jpg`, `.png` or `.webp` at the theme's root is found without being named. The card is 4:3; the shipped screenshot is 1200×900. |
| `colors` | `background`, `text` and `accent` as hex. When there is no screenshot, the admin draws a small page in these colours, so a card is never blank. |
| `settings` | The theme's presentation defaults: `show_title` (true or false) and `layout` (`full` or `centered`), and a `types` object keyed by content type slug carrying the same two keys for that type. An unknown key here is an error, not an omission. |

The site serves the screenshot at `/_thallo/theme-screenshot/<name>` — that one file of a theme,
and nothing else.

## The hooks a template must keep

The copy carries all of this already. Keep it as you rewrite the markup.

- **Style targets.** Each block type names the parts of its template that take settings. Put
  `{{ style_classes('root') }}` inside that element's class attribute and `{{ style_attrs('root') }}`
  on its tag, for every target the type declares — the button's `control`, the feature block's
  `marker`, the tabs block's `bar` and `tab`.
- **Slots.** Put `{{ slot_attrs('field') }}` on the element that wraps a `blocks()` call, once per
  `blocks` field, so the builder knows where a dragged block may land. Render that wrapper even
  when the list is empty; `is_canvas()` tells you when you are on the stage.
- **Editable text.** `{{ data.title|editable_text('title') }}` is what makes text editable in
  place. Plain `{{ data.title }}` renders and cannot be edited.
- **No inline style.** A template writes no `style=` attribute and no `<style>` element.
- **Leave the block wrapper alone.** The engine marks blocks with `data-thallo-block` on the
  canvas; the editor finds them by that, never by your classes, which is why the classes are
  yours to change.
- **Behaviour is the pack's.** Keep `{{ runtime_script() }}` in a copied `layout.twig`: the
  carousel, tabs, navigation, colour mode and forms all run from it. Themes ship CSS, not
  JavaScript.
- **Ken Burns.** Where a block sets a picture drifting, the picture stays the direct child of the
  element that carries the frame's target, and you set no `transform` of your own on it.
- **The container.** If you restyle `.thallo-block-container`, keep `display: flex` and
  `flex-direction: column` on its inner element, keep its `gap`, and keep the rule that clears the
  vertical margin of its children. Without them a container's children touch.

## Edit templates from the admin

**Site › Theme editor** edits the same templates without a deploy. It needs the **Manage
templates** permission.

The switcher at the top left chooses which theme you are editing, and the button beside it
duplicates that theme into a new `themes/{name}` folder — the same copy the command makes. The
list below groups the theme's files by folder, each badged with where it comes from: `db`,
`theme`, `package` or `default`.

Open a file and press **Save**: the edit is live at once, as a database override that shadows the
file and never changes it. The badge turns `db`, **History** lists every version and who saved
it, and **Delete override** drops back to the file on disk. `custom.css` is pinned at the top
under **Site**; it loads after everything else on every page, which makes it the last word in the
cascade. `theme.json`, the theme's `assets/`, `blocks/html.twig` and `blocks/shortcode.twig` are
read-only.

A saved template is checked against the template policy, and a refusal is reported line by line —
which is the one difference from editing on disk, where nothing checks your work.

## Check it worked

1. Open the site and reload. Your changed template renders, wearing your CSS.
2. Open **Site › Appearance** and confirm your theme's card is badged **Live**.
3. Run `php glueful thallo:doctor`. It reports whether the theme maps the vocabulary and whether
   the compiled style artifact is published.

Next: [make your own block type](14-make-a-block-type.md), which adds a template of its own to
the theme you just made.
