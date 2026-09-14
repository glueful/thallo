# Website Phase 1b Design Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** the thallo.dev homepage blueprint can be built and styled from the admin with no custom CSS: a hero that carries any block beside its copy and can drop its gradient, buttons whose shape is a choice, and three site-wide design settings (corner radius, typeface pairing, page ground) next to the existing theme colours.

**Architecture:** every control is a closed enum. Block-level choices ride on the block schema and become BEM modifiers the theme's CSS already knows how to read (the `section.background` pattern). Site-wide choices are settings rows read by `ThemeAppearanceSource`, emitted as CSS custom-property overrides in the same inline `<style>` block as the theme colours (`theme_colors_style()`), and folded into the render cache's appearance fingerprint so a change re-keys every cached page. The theme CSS is refactored to read tokens (`--font-body`, `--font-display`, `--radius-btn`) where it hard-coded values; defaults reproduce today's look exactly, so existing sites do not change.

**Tech Stack:** PHP 8.3 / Glueful 1.85.6, Twig under `TemplatePolicy`, Nuxt UI admin (vitest), PHPUnit.

**Spec:** `docs/internal/plans/2026-09-11-website-and-docs.md` ("log every block or template need as a product gap and fix it in the library"; done when the homepage is published with no `html` block and no custom template). The gaps were found building the hero on thallo.dev on 2026-09-14.

## Global Constraints

- Defaults are today's rendering: `hero.background` defaults to `gradient`, `button.shape` to `pill`, `theme_radius` to `round`, `theme_font` to `sans`, `theme_background` to `plain`. `ThemeDesign::css()` for all defaults is `''` (the pattern of `ThemeColors::css()`).
- Fonts: the site's CSP is `'self'`, so the serif options are system stacks (no new font files, no third-party hosts).
- Nothing new in the admin without a vitest assertion; `pnpm exec oxfmt` on touched admin files only.
- Starter block count stays 47 (no new block); every enum is guarded with `?? default` in Twig so a stored unknown value degrades to the default modifier.
- `ThemeAppearanceProvider` (thallo-contracts) grows three methods; the render pack never imports core.

---

### Task 1: hero `aside` slot and `background` choice

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (hero schema: `aside` blocks, `background` enum `gradient|none|muted|inverted`), `packages/thallo-render/themes/default/templates/blocks/hero.twig`, `packages/thallo-render/themes/default/assets/blocks.css`
- Test: `tests/Integration/Render/HeroBlockOptionsTest.php`

**Interfaces:** hero data `aside: list<block>` rendered inside `.thallo-block-hero__media` (replacing the image when present); root modifiers `thallo-block-hero--bg-{gradient|none|muted|inverted}`.

- [ ] **Step 1: failing tests** — a hero with `aside: [code block]` renders `thallo-block-code` inside `thallo-block-hero__media` and no `<img>`; a hero without `background` carries `--bg-gradient`; `background: none` carries `--bg-none`; an unknown background degrades to `--bg-gradient`; blocks.css contains `.thallo-block-hero--bg-none` and `.thallo-block-hero--bg-inverted`.
- [ ] **Step 2: RED. Step 3: implement.** Template: `{% set background = {gradient:'gradient', none:'none', muted:'muted', inverted:'inverted'}[data.background|default('gradient')] ?? 'gradient' %}`; media slot: `{% if data.aside|default([]) is not empty %}<div class="thallo-block-hero__media thallo-block-hero__media--blocks">{{ blocks(data.aside) }}</div>{% elseif img %}…{% endif %}`. CSS: move the gradient from the base rule to `--bg-gradient`; `--bg-none { background: transparent }`, `--bg-muted { background: var(--surface-2) }`, `--bg-inverted { background: var(--ink); color: var(--accent-ink) }` with title/description colours as in `section--inverted`; `__media--blocks > .thallo-block { max-width: none; padding-inline: 0; }` so a nested block fills the column.
- [ ] **Step 4: GREEN**, phpcs, `ShippedTemplatesLintGateTest`, `StarterTemplatesTest`, `BlockLibraryRenderTest`.
- [ ] **Step 5: commit** `feat(blocks): hero carries any block beside its copy and can drop its gradient`.

### Task 2: button `shape`

**Files:**
- Modify: `StarterBlockTypes.php` (button schema `shape` enum `pill|rounded|square`), `blocks/button.twig`, `blocks.css`
- Test: `tests/Integration/Render/ButtonShapeTest.php`

**Interfaces:** link modifier `thallo-block-button__link--shape-{pill|rounded|square}`; token `--radius-btn` (default `999px`, set by `theme_radius`).

- [ ] **Step 1: failing tests** — default renders `--shape-pill`; `shape: rounded` renders `--shape-rounded`; blocks.css base rule uses `border-radius: var(--radius-btn, 999px)` and defines `--shape-rounded { border-radius: var(--radius) }`, `--shape-square { border-radius: 2px }`, `--shape-pill { border-radius: 999px }`.
- [ ] **Step 2: RED. Step 3: implement. Step 4: GREEN**, phpcs, lint gate.
- [ ] **Step 5: commit** `feat(blocks): button shape — pill, rounded or square`.

### Task 3: site design settings (radius, typeface, ground)

**Files:**
- Create: `packages/thallo-render/src/Theme/ThemeDesign.php`, `tests/Unit/Render/ThemeDesignTest.php`
- Modify: `packages/thallo-contracts/src/Settings/ThemeAppearanceProvider.php` (+ `radius(): ?string`, `font(): ?string`, `background(): ?string`), core's implementation of it, `core/src/Settings/GeneralSettings.php` (`theme_radius`, `theme_font`, `theme_background`), `GeneralSettingsController.php` (enum validation), the general settings DTOs, `packages/thallo-render/src/ThemeAppearanceSource.php` (memoised, normalised accessors), `RenderServiceProvider.php` (fingerprint `accent-neutral-radius-font-background`), `RenderContextExtension::themeColorsStyle()` (emits colours + design), `site.css` (tokens `--font-body`, `--font-display`, `--radius-btn`; body and headings read them)
- Test: `ThemeDesignTest` (unit), `tests/Integration/Render/ThemeColorsLayoutTest.php` (design vars in the inline style, order kept), general settings API test (rejects `theme_radius: huge`, stores `sharp`)

**Interfaces:**
- `ThemeDesign::RADII = ['sharp','soft','round']`, `FONTS = ['sans','editorial','serif']`, `BACKGROUNDS = ['plain','tinted']`; `normalizeRadius/Font/Background(string): ?string`; `css(string $radius, string $font, string $background, string $neutral): string` ('' for defaults).
- Emitted declarations: radius `sharp` → `--radius:4px;--radius-lg:8px;--radius-btn:4px`; `soft` → `--radius:12px;--radius-lg:20px;--radius-btn:8px`; font `editorial` → `--font-display: "Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif`; `serif` → both display and body serif; background `tinted` → light `--bg` = the neutral's surface value and `--surface` = its bg value (swap, from `ThemeColors::tokens()`), dark unchanged.

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement. Step 4: GREEN**, phpcs, `composer boundaries`.
- [ ] **Step 5: commit** `feat(theme): site design settings — corner radius, typeface pairing, page ground`.

### Task 4: admin Design card

**Files:**
- Modify: `admin/src/pages/settings/general/index.vue` (card `data-test="theme-design-card"` with selects `theme-radius`, `theme-font`, `theme-background`), `admin/src/__tests__/generalSettingsPage.spec.ts`; `pnpm gen:api` for the DTO fields.
- [ ] **Step 1: failing spec** — the card renders the three selects with the saved values and the save payload carries them. **Step 2: RED. Step 3: implement. Step 4: GREEN** (`pnpm test`, `pnpm typecheck`, `pnpm lint`, fmt on touched files).
- [ ] **Step 5: commit** `feat(admin): design settings card`.

### Task 5: region preview parity

**Files:** `packages/thallo-render/themes/default/templates/region-preview.twig`; test in the existing region preview test.
- [ ] Failing test: the preview document contains `theme_colors_style()` output for a non-default appearance and the custom CSS link when custom CSS exists. RED → add both after the theme sheets → GREEN → commit `fix(render): the chrome preview loads theme colours and custom CSS`.

### Task 6: docs and gates

- [ ] `packages/thallo-render/docs/THEMING.md` (hero/button options, design tokens), `CHANGELOG.md` Unreleased, the website plan's status line. Full `composer test`, admin gates, `composer test:skeleton` (settings change). Commit `docs(design): design controls`.

## Self-review

Spec coverage: hero aside (T1), gradient off (T1), button shape and colour limits (T2; colour stays primary/neutral, the theme accent is the brand colour by design), radius/fonts/ground (T3+T4), custom CSS parity in previews (T5). Types: `ThemeDesign` enums used by the controller validation, the appearance source and the CSS emitter; `--radius-btn` written by T3 and read by T2; `hero.aside` rendered through `blocks()` like `hero.links`.
