# Modern Default Theme + Starter Block Restyle — Design

**Date:** 2026-07-04
**Status:** Approved design, pending implementation
**Touches:** `packages/lemma-render/themes/default/` (layout/entry/index
templates, `site.css`, `blocks.css`, the ten `blocks/*.twig`), render-pack
README, integration tests.

## 0. Summary

The default theme is today a deliberately minimal "readable reference": a
42rem body column, 37 lines of site CSS, bare `header`/`footer`, and ten
starter blocks with structural-only styling. This cycle turns it into a
polished modern-SaaS site out of the box — real type scale, spacing tokens,
sticky translucent header, full-bleed heroes and section bands, dark mode —
while preserving every architectural contract: canvas-annotation-compatible
templates, standalone `blocks.css`, `lemma-block-{slug}--{value}` enum
classes, `editable_text`/`safe_html` regions, no build step, DB-overridable
seeded templates.

Decision pins:

- **Visual direction: Modern SaaS** (Linear/Stripe-school): confident
  fluid type scale, generous whitespace, gradient-tinted full-bleed hero,
  pill buttons, soft-shadow rounded cards, sticky backdrop-blur header,
  automatic dark mode. System font stack — no webfont fetches (CSP/no-build
  posture).
- **Editor conventions, not core machinery (user asks):** the templates
  honor two OPTIONAL schema fields any content type can adopt:
  - `show_title` (boolean): the page `<h1>` renders only when
    `entry.fields.show_title ?? true` — absent keeps today's behavior;
    `false` hides it (block-built pages whose hero owns the heading).
  - `layout` (enum `full` | `centered`, default `centered` when absent):
    mapped to a class on `<main>` (`layout--full` / `layout--centered`).
    `centered` caps main's children at the reading measure; `full` lets
    band blocks bleed edge-to-edge.
- **Homepage mirrors entry body handling (review pin):** `index.twig`
  currently renders `entry.fields.body` inside `<div class="body">`
  unconditionally — a configured homepage with a BLOCKS body would
  print/escape the array instead of rendering blocks. It gains the exact
  `entry.twig` branch: iterable body → `blocks(entry.fields.body)`;
  scalar body → escaped `.body`. Both templates also honor `show_title`
  and `layout`.
- **Shell selectors are namespaced (review pin):** `hero.twig` renders a
  `<header class="lemma-block lemma-block-hero…">`, and today's `site.css`
  styles bare `header`/`nav`/`footer` — the shell would keep leaking into
  block markup. The redesign pins `.site-header`, `.site-nav`,
  `.site-footer` classes on the layout shell and scopes ALL shell rules to
  them; no bare-element shell selectors remain.

## 1. Layout mechanics

- `body`/`main` span the viewport (the 42rem body cap is removed).
- Shared tokens in `site.css`: `--container: 72rem` (site sections),
  `--content: 46rem` (reading measure), spacing scale
  (`--space-1…--space-8`), radius tokens.
- Each block centers its OWN inner container — `blocks.css` stays
  standalone: a custom theme adopting the starter blocks by copying the
  one file gets correct layout regardless of its shell. Band blocks
  (hero, section, cta) are full-bleed elements with an inner
  `max-width: var(--container)` wrapper INSIDE the block's own markup or
  padding-box; text blocks (rich_text, quote) cap at `--content`.
- **Centered is an opt-in content wrapper, never a blanket child cap
  (review P2):** `blocks()` wrappers are `display: contents`, so block
  roots are DIRECT children of `main` — a `main.layout--centered > *` rule
  would cap every band block before its own inner container could do the
  full-bleed work. Instead:
  - `entry.twig`/`index.twig` wrap the title and any SCALAR prose body in
    a `.entry-content` container; `blocks()` output stays direct.
  - `layout--centered` constrains `.entry-content` (and only it) to
    `--content`.
  - Band blocks (hero, section, cta) respond to the layout class
    themselves: under `.layout--centered` they present as CONTAINED cards
    (`max-width: var(--container)`, auto margins, radius) instead of
    edge-to-edge bands — so the per-page choice is meaningful for
    block-built pages too. These rules live in `blocks.css` and are
    dormant in themes that never emit the layout classes (standalone-ness
    holds: full-bleed stays the classless default).
  - `main.layout--full` imposes nothing — bands bleed.

## 2. Visual system (`site.css`)

- CSS variables, light + dark via `prefers-color-scheme`: `--bg`,
  `--surface`, `--surface-2`, `--ink`, `--muted`, `--line`, `--accent`,
  `--accent-ink`, `--shadow`. Dark mode ONLY re-maps variables.
- Fluid type scale with `clamp()`: display (hero), h1–h4, body 17px,
  small. Tight letter-spacing on display sizes.
- `.site-header`: sticky, `backdrop-filter: blur` over a translucent
  `--bg`, bottom hairline; site name bold; `.site-nav` links with muted →
  ink hover transitions. The preview banner keeps its class-based styling
  inside the header.
- `.site-footer`: padded band, top hairline, muted small text.
- Buttons/links: shared `.button`-free approach — block CSS owns its own
  buttons (hero CTA, cta button) as today, restyled as pills.

## 3. Starter blocks (`blocks.css` + minimal template touches)

Class contract unchanged (`lemma-block-{slug}`, `--{value}` modifiers,
`__element` children). Restyle per block:

- **hero**: full-bleed band, subtle radial/linear gradient tint from
  `--accent`, display-size heading, muted subheading at reading measure,
  pill CTA; `--center` centers; image variant constrained + rounded.
- **cta**: rounded panel (`--surface`), `--primary` = accent gradient with
  `--accent-ink` text, pill button; generous padding.
- **section**: full-bleed band variants — `--subtle` = `--surface`,
  `--emphasis` = accent tint; inner container; `__title` styled.
- **columns**: children render as cards (surface, radius, soft shadow,
  hover lift); responsive collapse unchanged.
- **quote**: large-type pull quote, oversized decorative quotation mark,
  styled cite; no more plain left border.
- **image**: rounded + soft shadow; `--wide`/`--full` semantics kept
  (`--full` becomes natural under the full-width shell).
- **gallery**: rounded items, subtle hover zoom (`overflow: hidden` +
  transform), grid gaps from the spacing scale.
- **divider/spacer**: re-tokened only.
- Template edits are minimal and preserve: wrapper-as-direct-child (canvas
  annotation shape limit), `editable_text`/`safe_html` emission,
  conditional guards, DB-overridability. Any new inner wrapper divs go
  INSIDE the block root element.

## 4. Seeding note

Seeded block templates live in the DB per theme only after
`lemma:blocks:seed`; the seeder is idempotent and never overwrites.
Existing sites keep whatever they have (documented in the README note);
fresh sites get the new look. Disk templates (the pack fallbacks) update
immediately everywhere no DB override exists.

## 5. Testing

- PHP integration (extend existing render suites):
  - `show_title: false` omits the `<h1>` on BOTH `entry.twig` and
    `index.twig` (review pin: the homepage is judged first); absent →
    title renders (back-compat).
  - `layout: 'full'` → `main.layout--full`; absent → `layout--centered`.
  - The title/scalar body render inside `.entry-content` while a blocks
    body's wrappers remain DIRECT children of `main` (review P2 — no
    blanket cap can strangle band blocks).
  - Homepage with a BLOCKS body renders `blocks()` output (wrappers/
    annotation present in a preview session), not an escaped array
    (review pin).
  - Existing annotation/seed/render tests stay green.
- Visual: user browser pass against the seeded Test page in light and
  dark, `full` and `centered`, desktop and mobile widths.

## 5a. AMENDMENT: `_presentation` + theme settings (replaces the
## schema-field convention)

Agreed post-review, before the convention shipped anywhere. The
`show_title`/`layout` SCHEMA-FIELD convention (§0) is REMOVED and replaced
by a presentation layer with these pinned contracts:

- **The contract (load-bearing):** `_presentation` is draft/version CONTENT
  STATE — it versions with the draft, previews in the canvas working copy,
  rolls back with the page, goes live only on publish — but it is NOT
  public content: the delivery API strips it from every public payload.
  Templates never read it directly; they consume ONLY the resolved
  `presentation` context.
- **Resolution chain:** page override (`fields._presentation.*`) →
  `theme.json` per-type setting (`settings.types.{type-slug}.*`) →
  `theme.json` default (`settings.*`) → built-ins
  (`show_title: true`, `layout: 'centered'`). Composed server-side into
  one `presentation` template context variable.
- **Fixed vocabulary:** exactly `show_title` (boolean) and `layout`
  (`full` | `centered`). Arbitrary theme-declared settings are explicitly
  out of scope (schemas, UI generation, migrations — a much larger
  system).
- **Reserved system keys, globally:** content schemas may NOT define
  fields whose names start with `_` (the `_presentation` collision today;
  any future `_foo` system key tomorrow). Schema create/update rejects
  them loudly.
- **Validation:** `FieldValidator` accepts `_presentation` in draft/apply
  payloads regardless of the content type's schema and validates it
  against the fixed vocabulary (unknown subkeys/wrong types → validation
  error). `theme.json` grows a strict `settings` block (unknown keys
  rejected loudly, same posture as the rest of the file).
- **Admin (canvas):** the design view's left inspector becomes two tabs —
  **Content** (today's FieldEditor, unchanged) and **Page** (the
  per-page settings: tri-state "Show page title" and "Layout" selects
  whose "Theme default" choice REMOVES the override key so the theme
  chain shows through). Edits write `fields._presentation` through the
  same `fields` ref: the deep watcher, auto-apply, save, dirty tracking,
  and the working-copy stash all apply unchanged.
- Tests (replacing the §5 convention tests): chain precedence (override →
  per-type → default → built-in), `_presentation` accepted by save/apply
  and honored on entry + homepage renders, stripped from delivery JSON,
  leading-underscore schema names rejected, theme.json settings
  validation, admin tab edit/clear behavior + staleness.

## 6. Out of scope (recorded)

- Webfonts, theme options UI, new block types, JS interactivity (the nav
  stays CSS-only; no hamburger menu this cycle), migration of existing
  sites' seeded DB templates.
