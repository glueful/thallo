# Navigation Block v2 (menu picker, styling, submenus) — Design

**Date:** 2026-07-04
**Status:** Draft for review

## Goal

The `navigation` block becomes a real site-nav component: the menu is picked
from existing menus (not typed as a slug), items get alignment / active-state /
hover / size controls, and nested menu items render as dropdowns — CSS-only,
with a configurable indicator icon and a hover-or-click reveal mode.

## Current state (verified)

- `menu(slug)` already returns a TREE: `{label, url, entry, children}` — the
  v1 template renders only the top level and ignores `children`.
- The admin already has `useMenus()`/`fetchMenus()` (`queries/navigation.ts`).
- The render context has **no current path** — active-state detection needs
  one (new, below).
- `blocks.js` exists as the no-JS-first escape hatch, but this design needs
  none of it: both reveal modes are native/CSS.

## Contract

### 1. Schema (additive; `menu` and `orientation` unchanged)

```php
['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
['name' => 'size', 'type' => 'enum', 'enum' => ['sm', 'md', 'lg']],
['name' => 'active_style', 'type' => 'enum', 'enum' => ['underline', 'pill', 'none']],
['name' => 'hover_style', 'type' => 'enum', 'enum' => ['color', 'underline', 'pill']],
['name' => 'submenu_icon', 'type' => 'enum', 'enum' => ['chevron-down', 'chevron-right', 'plus', 'none']],
['name' => 'submenu_trigger', 'type' => 'enum', 'enum' => ['hover', 'click']],
```

- Defaults (template/CSS-side, absent = today's behavior where one exists):
  `align: start`, `size: md`, `active_style: underline`, `hover_style: color`
  (today's muted→ink shift), `submenu_icon: chevron-down`,
  `submenu_trigger: hover`.
- `submenu_icon` is a CURATED enum, not a free Lucide name — a dropdown in
  the editor for free, closed value space, rendered via `icon()` (the names
  are all vendored Lucide). `none` hides the indicator.
- All enums are closed → style-enum → modifier-class convention
  (`lemma-block-navigation--align-{v}`, `--size-{v}`, `--active-{v}`,
  `--hover-{v}`, `--reveal-{v}`); single emission site in the template,
  schema-validated values only. (No cross-field token map needed — unlike
  columns, no value depends on another field.)

### 2. Menu picker (editor, frontend-only)

The `menu` field STAYS a string slug in the schema (structured source, data
model unchanged). The block editor special-cases it — same decision pattern
as the columns ergonomics, keyed on the immutable `navigation` type slug:
`BlockCard` renders a `USelect` fed by `useMenus()` (label = menu name,
value = slug) instead of a text input. A saved slug whose menu was deleted
still shows (as its raw slug) and still validates — the picker is cosmetic;
the pattern rule remains the contract.

### 3. Active state

- `RenderController` adds `current_path` to the base render context.
  Available to ALL templates — generally useful beyond this block.
- **Canonical-space matching (P1 review pin).** Exact string comparison is
  only sound if both sides live in the same path grammar. The two sides:
  - **Item URLs are canonical by construction**: entry menu items resolve
    through `EntryTargetResolver`, whose paths are `CanonicalPathBuilder`
    outputs (default-locale collapse, root-mount decisions applied). URL
    items are operator-typed and compared verbatim.
  - **`current_path` must be normalized the same way the render page cache
    normalizes its keys** (one normalizer, not a second implementation):
    query string stripped, trailing slash trimmed (except root). Content
    requests on non-canonical forms (`/en/about` when default-locale
    collapse says `/about`, prefixed form of a root-mounted type) already
    301 to canonical BEFORE rendering — the existing one-hop redirect rule —
    so a rendered page's `current_path` is canonical for content routes by
    construction; the normalizer covers the residue (slashes, query).
  - **Tests pin the grammar cases**: default-locale collapsed
    (`/about` item active on `/about`, NOT on `/en/about` — which never
    renders, it redirects), prefixed non-default locale (`/fr/a-propos`),
    and a root-mounted type's path.
- The template marks an item active when `item.url == current_path` —
  class `lemma-block-navigation__item--active`, styled per `active_style`
  (`underline`: text underline offset; `pill`: accent background chip;
  `none`: nothing).
- Page cache safety: the cache key is per-path already, so baking the
  current path into cached HTML is correct by construction.

### 4. Submenus (one level, CSS-only)

Items with `children` render a dropdown; **reveal mode branches the markup**:

- `submenu_trigger: hover` — `<li class="…__item--parent">` with a nested
  `<ul>` revealed via `:hover` / `:focus-within` (keyboard-accessible for
  free; the parent label stays a normal link if it has a url).
- `submenu_trigger: click` — native `<details name="nav-{{ block.id }}">`
  with the label in `<summary>` (the faq-block pattern). NOTE the trade-off,
  pinned: in click mode a parent with its own url is NOT navigable — the
  click opens the dropdown (summary swallows it); its url is repeated as the
  first child item automatically when present, so the destination stays
  reachable.
  **`name` exclusivity is progressive enhancement (P2 review pin):** in
  browsers that support the `details name` attribute, sibling dropdowns
  auto-close each other; where unsupported, multiple dropdowns may remain
  open simultaneously — ACCEPTABLE in v1. Open/close correctness itself is
  native `<details>` behavior everywhere; only the one-at-a-time nicety
  degrades. If strict single-open ever becomes a requirement, it needs JS
  (blocks.js) — deliberately excluded here.
- The indicator `icon(submenu_icon)` renders inside the parent label/summary
  for items with children only; `none` renders nothing.
- ONE level of nesting in v1: grandchildren render flattened into their
  parent's dropdown (never lost, never a second flyout). Depth-2 flyouts are
  out of scope.
- Both modes degrade correctly with no JS (there is no JS) and inside the
  canvas/preview (no blocks.js involvement).

### 5. Per-item icons (menu model — review addition)

Menu ITEMS gain an optional icon, carried through the whole chain:

- **Storage**: `navigation_items` gains a nullable `icon` varchar(64) —
  folded into `002_CreateNavigationItemsTable.php` per the pre-launch
  migration rule; already-migrated DBs (dev `lemma`, test `lemma_test`) get
  a manual `ALTER TABLE ADD COLUMN` sync and no new migration file ships.
- **Value space**: a free Lucide name validated by the icon-library grammar
  (`[a-z0-9]+(-[a-z0-9]+)*` — Lucide-only, no `brand:` in navigation), NOT a
  curated enum: per-item icons want the full set. Empty/null = no icon.
  Server-side validation in the navigation admin's item save path; an
  unknown-but-well-formed name degrades at render (`icon()` null → nothing
  renders, label alone) — never breaks the nav.
- **Resolver**: `MenuResolver`'s tree items gain `icon: ?string`; the
  `MenuReader` docblock shape updates.
- **Admin**: the navigation item form gains an optional "Icon (Lucide name)"
  input with the pattern hint; live preview chip via the admin's own
  `i-lucide-{name}` rendering (free — same set).
- **Render**: the navigation block template renders
  `{{ icon(item.icon) }}` before the label when set (the icon-library
  Markup discipline: null falls through, nothing echoes the raw name — an
  icon is decoration, not content). The default theme's FALLBACK header nav
  stays icon-less (it predates the model; region nav is the styled path).

### 6. CSS

`blocks.css` gains rules per modifier token: alignment (`justify-content` on
the nav flex row), sizes (`font-size` 0.85/0.95/1.1rem), active styles,
hover styles, dropdown positioning (`position: absolute` panel under the
parent, `--reveal-hover` display rules, `details` styling for click mode),
indicator icon sizing (rides `.lemma-icon`). Vertical orientation keeps
submenus inline (indented list, no floating panel). Chrome context: the
existing `.lemma-region` resets apply; the dropdown panel gets a surface
background + border so it reads over page content.

### 7. Reach

Seeder covers new installs; the dev instance gets the additive fields via
`updateSchema` (schema only, no content rewrite — the columns-sizing pin).
Existing navigation blocks render byte-identically until a new field is set
(all defaults preserve current markup except: items with children NOW render
their dropdowns — that is the bug being fixed, not a regression; v1 silently
dropped children).

## Out of scope

- Mobile hamburger/off-canvas menu (needs JS + design pass of its own).
- Depth-2 flyouts.
- Free-text icon names for the indicator.
- Mega-menus, badges.
- `visible_when` (the reveal/icon fields apply regardless of whether the
  selected menu currently has children — harmless no-ops).

## Testing

- Render: modifier classes per enum; active class present when
  `current_path` matches an item url and absent otherwise; hover-mode parent
  emits nested `<ul>` (no `<details>`); click-mode emits
  `<details name="nav-{id}">` with the parent url repeated as first child;
  grandchildren flatten; `submenu_icon: none` renders no svg in the parent;
  absent fields = byte-compatible markup for menus WITHOUT children.
- Context: `current_path` present and normalized in a full-page render.
- Editor (vitest): navigation block's `menu` field renders a select fed from
  the menus query; other block types keep the plain input.
- Seeder pin: new enums in `SeedBlockTypesTest`.
- Per-item icons: item save rejects a malformed icon name (422); the tree
  carries `icon`; the block template renders the SVG before the label and
  renders label-only for unknown-but-well-formed names; active-state grammar
  tests (P1): default-locale collapsed, prefixed non-default locale,
  root-mounted path.

## Files touched

`StarterBlockTypes` (navigation schema), `blocks/navigation.twig`,
`blocks.css`, `RenderController` (+`current_path`), `BlockCard.vue` (menu
picker special case), `SeedBlockTypesTest`, `StarterTemplatesTest` fixture,
`BlockLibraryRenderTest`/`RegionRenderingTest` cases, dev-DB additive update,
lemma-navigation: `002_CreateNavigationItemsTable.php` (fold-in) +
manual dev/test DB column sync, `MenuRepository`/`MenuResolver`,
`NavigationAdminController` item validation, admin navigation item form,
CHANGELOG.
