# Shadow System + Style-Block Presentation Controls — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the theme's single flat `--shadow` with a full Tailwind-derived elevation scale (light/dark, overridable color + opacity), and give page-builders shadow/padding/margin controls on the Style and Container blocks.

**Architecture:** Shadow tokens live in `site.css` as `--shadow-2xs…2xl`, each composed from an overridable `--shadow-color` + `--shadow-strength` via `color-mix()` so an element can retint or restrength its shadow. Matching `.thallo-shadow-{level}` utility classes let blocks opt into a depth. The Style block gains fields (shadow/shadow_color/shadow_opacity/padding/margin) following Container's existing "enum → modifier class, freeform → inline CSS var" pattern; Container gains a shadow enum. Internal components keep `var(--shadow)`, which is re-aliased to `--shadow-md`; only the nav overlay moves to `--shadow-lg`.

**Tech Stack:** CSS custom properties + `color-mix()`, Twig, PHP 8.3, PostgreSQL (`app_test`), PHPUnit.

## Global Constraints

- **Server default theme only** — `packages/thallo-render/themes/default/`. No admin/Tailwind changes.
- **Scale (verbatim levels):** `--shadow-none, --shadow-2xs, --shadow-xs, --shadow-sm, --shadow-md, --shadow-lg, --shadow-xl, --shadow-2xl`. Utility classes: `.thallo-shadow-{none,2xs,xs,sm,md,lg,xl,2xl}`. Block enum values: `['none','2xs','xs','sm','md','lg','xl','2xl']`.
- **Overridable mechanism:** every scale token composes its color from `var(--shadow-color)` and its opacity from `calc(<base>% * var(--shadow-strength))` via `color-mix(in srgb, var(--shadow-color) …, transparent)`. Defaults: light `--shadow-color: #0f172a` (slate-900) / `--shadow-strength: 1`; dark `--shadow-color: #000000` / `--shadow-strength: 2.5`. Operators override per element for colored / opacity-adjusted shadows.
- **Backward-compatible default:** `--shadow` is redefined as `var(--shadow-md)`, so every existing `box-shadow: var(--shadow)` becomes md with no per-line edits. Overlays (nav submenu) move to `--shadow-lg`.
- **New controls are opt-in (default `none`)** — the Style/Container shadow fields default to `none` so existing content does NOT retroactively gain a shadow (defaulting them to `md` would change every current Style/Container block). The "shadow-bearing blocks default to md" rule is satisfied by the internal surfaces via the `--shadow` alias. *(Decision flagged for review — see §Decisions.)*
- **Follow the Container pattern, guarded at render time:** enumerated fields → guarded `{map}[value] ?? default` modifier classes; freeform values (`shadow_color`, `shadow_opacity`) → inline CSS vars in the `style` attribute, exactly as Container emits `--container-*`. Twig autoescape handles HTML-context safety, but it does **not** stop CSS-declaration injection inside the attribute value — so the template treats validation as a trust boundary, not a nicety: `shadow_color` is emitted only when it matches the same `self::HEX` shape, and `shadow_opacity` only when numeric, clamped to 0–200 before becoming `--shadow-strength`. A stale or hand-crafted DB value can't inject extra declarations.
- **Test DB:** `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit <path>`.
- **phpcs:** `vendor/bin/phpcs <path>` — 0 errors; wrap PHP lines >120 (CSS/Twig are not phpcs-linted).
- **Commit policy (this session):** hold commits; stage per task; batch at the phase checkpoints below and commit only when the user says so.

## Decisions (made here; flag at review)

1. **New shadow controls default to `none`** (opt-in), not `md` — avoids retroactively shadowing existing Style/Container blocks. Internal surfaces are md via the `--shadow` alias.
2. **Dark shadows** boost via `--shadow-strength: 2.5` (moderate/modern) rather than the current theme's heavy `/.4–.5`. Purely a var override; the scale recomputes automatically in dark.
3. **Style-block spacing:** `padding` = all sides (`padding: var(--space-*)`); `margin` = vertical only (`margin-block: var(--space-*)`) — the common wrapper need; avoids horizontal shove inside columns. Scale: small=`--space-3`, medium=`--space-4`, large=`--space-5`.
4. **Container gets shadow depth only** (enum) — no color/opacity fields there; those richer controls live on the Style block (the dedicated presentation wrapper).

---

## Phase 1 — Shadow token foundation

### Task 1: Shadow scale tokens, utilities, alias, overlay migration

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/site.css` (light `:root` ~line 18; dark `html[data-theme="dark"]` ~line 40)
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (append utility classes)
- Modify: `packages/thallo-render/themes/default/assets/navigation.css` (submenu ~line 104)
- Test: `tests/Integration/Render/ShadowTokensTest.php` (create)

**Interfaces:**
- Produces: CSS custom properties `--shadow-color`, `--shadow-strength`, `--shadow-none/2xs/xs/sm/md/lg/xl/2xl`, `--shadow` (= `var(--shadow-md)`); utility classes `.thallo-shadow-{none,2xs,xs,sm,md,lg,xl,2xl}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/ShadowTokensTest.php` (mirrors `DarkTokensTest`'s file-read approach):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/** Shadow-system plan Task 1: the elevation scale + overridable color/strength. */
final class ShadowTokensTest extends AppTestCase
{
    private function css(string $file): string
    {
        $path = $this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/' . $file;
        return (string) file_get_contents($path);
    }

    public function testScaleTokensDefinedWithOverridableColorAndStrength(): void
    {
        $site = $this->css('site.css');
        foreach (['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $level) {
            self::assertStringContainsString('--shadow-' . $level . ':', $site, "missing --shadow-{$level}");
        }
        // Overridable knobs + color-mix composition.
        self::assertStringContainsString('--shadow-color:', $site);
        self::assertStringContainsString('--shadow-strength:', $site);
        self::assertStringContainsString('color-mix(in srgb, var(--shadow-color)', $site);
        self::assertStringContainsString('var(--shadow-strength)', $site);
    }

    public function testDefaultShadowAliasesMd(): void
    {
        self::assertMatchesRegularExpression('/--shadow:\s*var\(--shadow-md\)\s*;/', $this->css('site.css'));
    }

    public function testDarkOverridesColorAndStrengthOnly(): void
    {
        $site = $this->css('site.css');
        $dark = substr($site, (int) strpos($site, 'html[data-theme="dark"]'));
        self::assertStringContainsString('--shadow-color: #000000', $dark);
        self::assertStringContainsString('--shadow-strength: 2.5', $dark);
        // Dark must NOT re-hardcode a raw multi-value --shadow literal (recomputes via vars).
        self::assertDoesNotMatchRegularExpression('/--shadow:\s*0 /', $dark);
    }

    public function testUtilityClassesExist(): void
    {
        $blocks = $this->css('blocks.css');
        foreach (['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $level) {
            self::assertStringContainsString('.thallo-shadow-' . $level . ' {', $blocks, "missing .thallo-shadow-{$level}");
        }
    }

    public function testNavOverlayUsesLg(): void
    {
        self::assertStringContainsString('box-shadow: var(--shadow-lg)', $this->css('navigation.css'));
    }

    public function testNoRawBoxShadowLiteralsRemain(): void
    {
        // Every box-shadow: declaration in component CSS must go through a token
        // (var(--shadow…)) or be `none` — the single-source-of-truth invariant.
        foreach (['blocks.css', 'navigation.css'] as $file) {
            foreach (explode("\n", $this->css($file)) as $line) {
                if (!preg_match('/(?<!-)box-shadow:\s*(.+?);/', $line, $m)) {
                    continue; // skips `transition: … box-shadow …` (no colon-value) and token defs
                }
                $val = trim($m[1]);
                self::assertTrue(
                    str_contains($val, 'var(--shadow') || $val === 'none',
                    "raw box-shadow in {$file}: {$val}",
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ShadowTokensTest.php`
Expected: FAIL — `--shadow-md` etc. and `.thallo-shadow-*` don't exist yet; `--shadow` isn't an alias.

- [ ] **Step 3: Replace the light `--shadow` token with the full scale**

In `site.css`, replace the single light shadow line:

```css
  --shadow: 0 1px 2px rgb(15 23 42 / 0.06), 0 8px 24px rgb(15 23 42 / 0.08);
```

with the scale (keep the surrounding `--radius`/`--space-*` lines intact):

```css
  /* Elevation scale (Tailwind-derived). Each token composes its color from
     --shadow-color and its opacity from calc(<base>% * --shadow-strength), so an
     element can retint (colored shadow) or restrength (opacity modifier) its shadow. */
  --shadow-color: #0f172a;   /* slate-900; dark mode + operators override */
  --shadow-strength: 1;
  --shadow-none: none;
  --shadow-2xs: 0 1px color-mix(in srgb, var(--shadow-color) calc(5% * var(--shadow-strength)), transparent);
  --shadow-xs:  0 1px 2px 0 color-mix(in srgb, var(--shadow-color) calc(5% * var(--shadow-strength)), transparent);
  --shadow-sm:  0 1px 3px 0 color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent), 0 1px 2px -1px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent);
  --shadow-md:  0 4px 6px -1px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent), 0 2px 4px -2px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent);
  --shadow-lg:  0 10px 15px -3px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent), 0 4px 6px -4px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent);
  --shadow-xl:  0 20px 25px -5px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent), 0 8px 10px -6px color-mix(in srgb, var(--shadow-color) calc(10% * var(--shadow-strength)), transparent);
  --shadow-2xl: 0 25px 50px -12px color-mix(in srgb, var(--shadow-color) calc(25% * var(--shadow-strength)), transparent);
  --shadow: var(--shadow-md);
```

- [ ] **Step 4: Replace the dark `--shadow` with var overrides**

In `site.css`, in the `html[data-theme="dark"]` block, replace:

```css
  --shadow: 0 1px 2px rgb(0 0 0 / 0.4), 0 8px 24px rgb(0 0 0 / 0.5);
```

with:

```css
  /* Shadows recompute from the scale; dark just darkens the tint and boosts strength. */
  --shadow-color: #000000;
  --shadow-strength: 2.5;
```

- [ ] **Step 5: Append the utility classes to `blocks.css`**

Add near the top-level utilities (end of file is fine):

```css
/* Shadow elevation utilities (shadow-system plan): a block opts into a depth by
   adding one of these; --shadow-color / --shadow-strength (set inline by the block)
   retint / restrength it. */
.thallo-shadow-none { box-shadow: var(--shadow-none); }
.thallo-shadow-2xs { box-shadow: var(--shadow-2xs); }
.thallo-shadow-xs { box-shadow: var(--shadow-xs); }
.thallo-shadow-sm { box-shadow: var(--shadow-sm); }
.thallo-shadow-md { box-shadow: var(--shadow-md); }
.thallo-shadow-lg { box-shadow: var(--shadow-lg); }
.thallo-shadow-xl { box-shadow: var(--shadow-xl); }
.thallo-shadow-2xl { box-shadow: var(--shadow-2xl); }
```

- [ ] **Step 6: Re-point the nav overlay to `lg`**

In `navigation.css`, in the `.thallo-block-navigation__submenu` rule (~line 104), change `box-shadow: var(--shadow);` to:

```css
  border-radius: var(--radius); box-shadow: var(--shadow-lg);
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ShadowTokensTest.php`
Expected: PASS (all 6). Every other `box-shadow: var(--shadow)` in `blocks.css` now resolves to `--shadow-md` via the alias — no edits needed.

- [ ] **Step 8: Regression — render suite still green**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render`
Expected: PASS (no template references the old flat value directly; the alias keeps everything rendering).

- [ ] **Step 9: phpcs + stage (hold commit)**

Run: `vendor/bin/phpcs tests/Integration/Render/ShadowTokensTest.php` → 0 errors.
```bash
git add packages/thallo-render/themes/default/assets/site.css \
  packages/thallo-render/themes/default/assets/blocks.css \
  packages/thallo-render/themes/default/assets/navigation.css \
  tests/Integration/Render/ShadowTokensTest.php
```

### ✋ PHASE 1 CHECKPOINT — hold for user

Scale + utilities + alias + overlay migration done; internal surfaces are md, nav overlay lg. Hold.

---

## Phase 2 — Block presentation controls

### Task 2: Style block — shadow, color, opacity, padding, margin

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (the `style` schema)
- Modify: `packages/thallo-render/themes/default/templates/blocks/style.twig`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (style spacing modifiers)
- Modify: `tests/Integration/Render/StyleBlockRenderTest.php`
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php`

**Interfaces:**
- Consumes: `.thallo-shadow-{level}` utilities, `--shadow-color`/`--shadow-strength` vars (Task 1).
- Produces: style-block fields `shadow` (enum), `shadow_color` (hex string), `shadow_opacity` (number 0–200), `padding` (enum), `margin` (enum); CSS `.thallo-block-style--pad-{small,medium,large}` / `--mar-{small,medium,large}`.

- [ ] **Step 1: Write the failing render test**

Add to `tests/Integration/Render/StyleBlockRenderTest.php`:

```php
    public function testShadowSpacingAndInlineVarsRender(): void
    {
        $out = $this->render([[
            'id' => 'sp1', 'type' => 'style',
            'data' => [
                'shadow' => 'lg', 'shadow_color' => '#06b6d4', 'shadow_opacity' => 50,
                'padding' => 'medium', 'margin' => 'small', 'content' => [],
            ],
        ]]);
        self::assertStringContainsString('thallo-shadow-lg', $out);
        self::assertStringContainsString('thallo-block-style--pad-medium', $out);
        self::assertStringContainsString('thallo-block-style--mar-small', $out);
        self::assertStringContainsString('--shadow-color: #06b6d4', $out);
        self::assertStringContainsString('--shadow-strength: 0.5', $out);
    }

    public function testShadowDefaultsToNoneAndOmitsInlineVars(): void
    {
        $out = $this->render([['id' => 'sp2', 'type' => 'style', 'data' => ['content' => []]]]);
        self::assertStringNotContainsString('thallo-shadow-', $out);
        self::assertStringNotContainsString('--shadow-color', $out);
        self::assertStringNotContainsString('style="', $out);          // no inline vars when nothing set
    }

    public function testUnknownShadowEnumDegradesToNoModifier(): void
    {
        $out = $this->render([['id' => 'sp3', 'type' => 'style', 'data' => ['shadow' => 'banana', 'content' => []]]]);
        self::assertStringNotContainsString('thallo-shadow-banana', $out);
        self::assertStringNotContainsString('thallo-shadow-', $out);   // allowlist guard, no bogus class
    }

    /**
     * Render-time trust boundary: stale/malicious shadow_color and shadow_opacity
     * (values a DB row can hold regardless of admin validation) must never reach the
     * style attribute. A non-hex color and a non-numeric opacity are both dropped —
     * the shadow depth class still applies, but no inline var is emitted.
     */
    public function testMaliciousShadowColorAndOpacityAreDropped(): void
    {
        $out = $this->render([[
            'id' => 'sp4', 'type' => 'style',
            'data' => [
                'shadow' => 'md',
                'shadow_color' => '#06b6d4; background: url(//evil)',  // CSS-injection attempt
                'shadow_opacity' => '50); }',                          // non-numeric
                'content' => [],
            ],
        ]]);
        self::assertStringContainsString('thallo-shadow-md', $out);       // depth still applies
        self::assertStringNotContainsString('--shadow-color', $out);      // non-hex rejected
        self::assertStringNotContainsString('--shadow-strength', $out);   // non-numeric rejected
        self::assertStringNotContainsString('background: url', $out);     // no injected declaration
        self::assertStringNotContainsString('evil', $out);
    }

    public function testShadowOpacityIsClampedToTheAllowedRange(): void
    {
        // 999 → clamp to 200 → strength 2; a bare negative never matches the numeric
        // guard, so it is dropped rather than emitted as a negative strength.
        $high = $this->render([['id' => 'sp5', 'type' => 'style',
            'data' => ['shadow' => 'md', 'shadow_opacity' => 999, 'content' => []]]]);
        self::assertStringContainsString('--shadow-strength: 2', $high);

        $neg = $this->render([['id' => 'sp6', 'type' => 'style',
            'data' => ['shadow' => 'md', 'shadow_opacity' => -5, 'content' => []]]]);
        self::assertStringNotContainsString('--shadow-strength', $neg);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/StyleBlockRenderTest.php --filter='Shadow|Spacing'`
Expected: FAIL — the template emits no shadow/spacing classes or inline vars yet.

- [ ] **Step 3: Extend `blocks/style.twig`**

Replace the body of `style.twig` (keep the header comment; add the new maps + attribute):

```twig
{% set scope = theme_style_scope(data.accent|default(''), data.neutral|default('')) %}
{# Leading-space-in-value convention (matches scope.class / style_hook) so unset
   dimensions contribute '' and the class list stays single-spaced — the Feature-C
   exact-class-match test depends on this. Maps are allowlist guards (?? ''). #}
{% set shadowCls = {
  none: '', '2xs': ' thallo-shadow-2xs', xs: ' thallo-shadow-xs', sm: ' thallo-shadow-sm',
  md: ' thallo-shadow-md', lg: ' thallo-shadow-lg', xl: ' thallo-shadow-xl', '2xl': ' thallo-shadow-2xl',
}[data.shadow|default('none')] ?? '' %}
{% set padCls = {
  none: '', small: ' thallo-block-style--pad-small',
  medium: ' thallo-block-style--pad-medium', large: ' thallo-block-style--pad-large',
}[data.padding|default('none')] ?? '' %}
{% set marCls = {
  none: '', small: ' thallo-block-style--mar-small',
  medium: ' thallo-block-style--mar-medium', large: ' thallo-block-style--mar-large',
}[data.margin|default('none')] ?? '' %}
{% set hasShadow = shadowCls is not empty %}
{% set vars = [] %}
{# Render-time trust boundary (NOT just the admin pattern): only emit a hex that
   matches the field's shape, and a numeric opacity clamped to 0..200. Stale or
   malicious block content can't inject extra CSS declarations into the style
   attribute (parity with the class_hook sanitizer). #}
{% if hasShadow and data.shadow_color|default('') matches '/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/' %}{% set vars = vars|merge(['--shadow-color: ' ~ data.shadow_color]) %}{% endif %}
{% if hasShadow and data.shadow_opacity is defined and data.shadow_opacity is not null and data.shadow_opacity matches '/^[0-9]+(\.[0-9]+)?$/' %}{% set vars = vars|merge(['--shadow-strength: ' ~ (max(0, min(200, data.shadow_opacity)) / 100)]) %}{% endif %}
<div class="thallo-block thallo-block-style{{ scope.class }}{{ shadowCls }}{{ padCls }}{{ marCls }}{{ data.class_hook|default('')|style_hook }}"{% if vars is not empty %} style="{{ vars|join('; ') }}"{% endif %}>
  <div class="thallo-block-style__inner">{{ blocks(data.content|default([])) }}</div>
  {{ scope.style }}
</div>
```

Note: `scope.class` and `style_hook` already emit a leading space (or ''), and the three new maps do too — so with everything unset the class attribute is exactly `thallo-block-style` and the Feature-C exact-match test (`…thallo-skin-rose-zinc thallo-style-promo`) is preserved. The `<style>` stays last (canvas-host invariant from the style-block spec). `shadow_color`/`shadow_opacity` only emit when a shadow level is set.

- [ ] **Step 4: Add the spacing modifier CSS**

In `blocks.css`, extend the existing style block rules:

```css
.thallo-block-style { display: block; }
.thallo-block-style__inner { display: block; }
.thallo-block-style--pad-small { padding: var(--space-3); }
.thallo-block-style--pad-medium { padding: var(--space-4); }
.thallo-block-style--pad-large { padding: var(--space-5); }
.thallo-block-style--mar-small { margin-block: var(--space-3); }
.thallo-block-style--mar-medium { margin-block: var(--space-4); }
.thallo-block-style--mar-large { margin-block: var(--space-5); }
```

(The two `display:block` lines already exist from the style-block feature — leave them; add the six modifier lines.)

- [ ] **Step 5: Add the fields to the `style` seed schema**

In `StarterBlockTypes.php`, extend the `style` block's `schema` (insert before `content`):

```php
                    ['name' => 'shadow', 'type' => 'enum',
                        'enum' => ['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl']],
                    ['name' => 'shadow_color', 'type' => 'string', 'pattern' => self::HEX],
                    ['name' => 'shadow_opacity', 'type' => 'number', 'min' => 0, 'max' => 200],
                    ['name' => 'padding', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large']],
                    ['name' => 'margin', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large']],
```

- [ ] **Step 6: Extend the seed schema assertion**

In `tests/Integration/Content/SeedBlockTypesTest.php`, after the existing `style` assertions, add:

```php
        // Shadow-system plan: presentation controls on the style block.
        self::assertSame('enum', $fields['shadow']);
        self::assertSame('number', $fields['shadow_opacity']);
        self::assertSame('enum', $fields['padding']);
        self::assertSame('enum', $fields['margin']);
        $shadowField = array_values(array_filter($style['schema'], fn ($f) => $f['name'] === 'shadow'))[0];
        self::assertContains('2xl', $shadowField['enum']);
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/StyleBlockRenderTest.php tests/Integration/Content/SeedBlockTypesTest.php`
Expected: PASS (all — including the earlier Feature-C style tests, which still hold since defaults keep the wrapper class unchanged).

- [ ] **Step 8: phpcs + stage (hold)**

Run: `vendor/bin/phpcs app/Content/Blocks/StarterBlockTypes.php tests/Integration/Render/StyleBlockRenderTest.php tests/Integration/Content/SeedBlockTypesTest.php` → 0 errors.
```bash
git add app/Content/Blocks/StarterBlockTypes.php packages/thallo-render/themes/default/templates/blocks/style.twig packages/thallo-render/themes/default/assets/blocks.css tests/Integration/Render/StyleBlockRenderTest.php tests/Integration/Content/SeedBlockTypesTest.php
```

---

### Task 3: Container — shadow depth

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (the `container` schema)
- Modify: `packages/thallo-render/themes/default/templates/blocks/container.twig`
- Modify: `tests/Integration/Render/BlockLibraryRenderTest.php`
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php`

**Interfaces:**
- Consumes: `.thallo-shadow-{level}` utilities (Task 1).
- Produces: container field `shadow` (enum); container root carries `.thallo-shadow-{level}` when set.

- [ ] **Step 1: Write the failing render test**

Add to `tests/Integration/Render/BlockLibraryRenderTest.php`:

```php
    public function testContainerShadowEnumAddsUtilityClass(): void
    {
        $out = $this->render([[
            'id' => 'cs1', 'type' => 'container',
            'data' => ['shadow' => 'md', 'content' => []],
        ]]);
        self::assertStringContainsString('thallo-shadow-md', $out);
    }

    public function testContainerShadowDefaultsToNone(): void
    {
        $out = $this->render([['id' => 'cs2', 'type' => 'container', 'data' => ['content' => []]]]);
        self::assertStringNotContainsString('thallo-shadow-', $out);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/BlockLibraryRenderTest.php --filter=ContainerShadow`
Expected: FAIL — container emits no shadow utility class.

- [ ] **Step 3: Add the `shadow` modifier to `container.twig`**

In `container.twig`, add a shadow map alongside the other `{% set %}` modifier maps (after `bgPosMod`):

```twig
{% set shadowMod = {
  none: '', '2xs': 'thallo-shadow-2xs', xs: 'thallo-shadow-xs', sm: 'thallo-shadow-sm',
  md: 'thallo-shadow-md', lg: 'thallo-shadow-lg', xl: 'thallo-shadow-xl', '2xl': 'thallo-shadow-2xl',
}[data.shadow|default('none')] ?? '' %}
```

and add `shadowMod` to the `rootClass` join list:

```twig
{% set rootClass = [
  'thallo-block thallo-block-container',
  widthMod, padMod, heightMod, bgSizeMod, bgRepeatMod, bgPosMod, shadowMod,
]|join(' ')|trim %}
```

- [ ] **Step 4: Add the `shadow` field to the `container` seed schema**

In `StarterBlockTypes.php`, in the `container` block's `schema`, add (before `content`):

```php
                    ['name' => 'shadow', 'type' => 'enum',
                        'enum' => ['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl']],
```

- [ ] **Step 5: Extend the seed assertion**

In `SeedBlockTypesTest.php`, add a container-shadow assertion (near the block-count block):

```php
        $container = $repo->findBySlug('container');
        self::assertContains('shadow', array_column($container['schema'], 'name'));
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/BlockLibraryRenderTest.php tests/Integration/Content/SeedBlockTypesTest.php`
Expected: PASS. (The container's existing style-attribute test still holds — `shadow` adds only a class, not an inline var.)

- [ ] **Step 7: phpcs + stage (hold)**

Run: `vendor/bin/phpcs app/Content/Blocks/StarterBlockTypes.php tests/Integration/Render/BlockLibraryRenderTest.php tests/Integration/Content/SeedBlockTypesTest.php` → 0 errors.
```bash
git add app/Content/Blocks/StarterBlockTypes.php packages/thallo-render/themes/default/templates/blocks/container.twig tests/Integration/Render/BlockLibraryRenderTest.php tests/Integration/Content/SeedBlockTypesTest.php
```

### Task 4: `thallo:blocks:sync` — additive starter-schema sync

**Files:**
- Create: `app/Content/Console/SyncBlockTypesCommand.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (register the command — service def + `commands([...])` list + `use` import)
- Test: `tests/Integration/Content/SyncBlockTypesTest.php` (create)

**Interfaces:**
- Consumes: `BlockTypeRepository::updateSchema()` (additive-only), `StarterBlockTypes::definitions()`, the `style`/`container` schema additions (Tasks 2–3).
- Produces: CLI `thallo:blocks:sync` (alias `blocks:sync`) that, for each starter, appends fields present in the code definition but missing from the DB row — never removing.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Content/SyncBlockTypesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Console\SeedBlockTypesCommand;
use App\Content\Console\SyncBlockTypesCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Shadow-system plan Task 4: additive sync of evolved starter schemas. */
final class SyncBlockTypesTest extends AppTestCase
{
    private function seed(): void
    {
        (new CommandTester($this->container()->get(SeedBlockTypesCommand::class)))->execute([]);
    }

    public function testSyncAdditivelyRestoresMissingStarterFields(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        // Simulate a pre-evolution row missing the newest field, via the guard-exempt
        // migrated-schema path (updateSchema itself refuses field removal).
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'shadow'));
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);
        self::assertNotContains('shadow', array_column($repo->findBySlug('style')['schema'], 'name'));

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced style', $tester->getDisplay());
        self::assertContains('shadow', array_column($repo->findBySlug('style')['schema'], 'name'));
    }

    public function testSyncIsIdempotentWhenUpToDate(): void
    {
        $this->seed();
        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Synced 0', $tester->getDisplay());
    }

    public function testSyncPreservesFieldOrderAndOperatorAddedFields(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        // A pre-evolution row: missing the newest starter field, plus an operator's
        // own custom field appended at the end.
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'shadow'));
        $reduced[] = ['name' => 'op_custom', 'type' => 'string'];
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);

        (new CommandTester($this->container()->get(SyncBlockTypesCommand::class)))->execute([]);

        $names = array_column($repo->findBySlug('style')['schema'], 'name');
        self::assertContains('op_custom', $names);                       // operator field preserved
        self::assertContains('shadow', $names);                          // starter field restored
        // Existing order kept; the restored starter field is appended AFTER op_custom.
        self::assertLessThan(
            array_search('shadow', $names, true),
            array_search('op_custom', $names, true),
        );
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'shadow'));
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced style', $tester->getDisplay());   // same line, no write
        self::assertStringContainsString('No changes written', $tester->getDisplay());
        // DB schema is untouched — the field is still absent.
        self::assertNotContains('shadow', array_column($repo->findBySlug('style')['schema'], 'name'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/SyncBlockTypesTest.php`
Expected: FAIL — `SyncBlockTypesCommand` does not exist.

- [ ] **Step 3: Create the command**

Create `app/Content/Console/SyncBlockTypesCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Additively syncs evolved starter block-type schemas onto existing rows.
 *
 * The seeder (thallo:blocks:seed) is create-only by design — it never touches an
 * existing row, so new fields added to a StarterBlockTypes definition never reach
 * already-seeded installs. This closes that gap the SAFE way: for each starter it
 * PRESERVES the existing field order and APPENDS (via array_merge) any starter field
 * whose `name` is absent from the DB row's schema. Operator-added fields and the
 * row's label/icon/description/category are left untouched, and field REMOVAL is
 * never performed here — that is the migration flow's job — so this is non-destructive
 * and mirrors updateSchema's additive-only guard. `--dry-run` reports the same
 * "synced …" lines without writing, so it doubles as a safe pre-upgrade preview.
 */
#[AsCommand(
    name: 'thallo:blocks:sync',
    description: 'Additively add new starter block-type fields to existing rows (never removes).',
    aliases: ['blocks:sync'],
)]
final class SyncBlockTypesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BlockTypeRepository $repo */
        $repo = $this->getService(BlockTypeRepository::class);
        $dryRun = (bool) $input->getOption('dry-run');
        $synced = 0;
        $unchanged = 0;
        $missing = 0;
        foreach (StarterBlockTypes::definitions() as $definition) {
            $row = $repo->findBySlug($definition['slug']);
            if ($row === null) {
                $this->line("missing {$definition['slug']} (run thallo:blocks:seed)");
                $missing++;
                continue;
            }
            $existing = array_column($row['schema'], 'name');
            $toAdd = array_values(array_filter(
                $definition['schema'],
                static fn (array $f): bool => !in_array($f['name'], $existing, true),
            ));
            if ($toAdd === []) {
                $unchanged++;
                continue;
            }
            if (!$dryRun) {
                $repo->updateSchema(
                    (string) $row['uuid'],
                    array_merge($row['schema'], $toAdd),
                    (string) $row['label'],
                    $row['icon'] !== null ? (string) $row['icon'] : null,
                    $row['description'] !== null ? (string) $row['description'] : null,
                    $row['category'] !== null ? (string) $row['category'] : null,
                );
            }
            $names = implode(', ', array_column($toAdd, 'name'));
            $this->line("synced {$definition['slug']} (+" . count($toAdd) . ": {$names})");
            $synced++;
        }
        $summary = "Synced {$synced}, unchanged {$unchanged}, missing {$missing}.";
        $this->success($dryRun ? "[dry-run] {$summary} No changes written." : $summary);
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the command**

In `app/Providers/ThalloServiceProvider.php`: add `use App\Content\Console\SyncBlockTypesCommand;` near the other console `use`s; add a service definition mirroring `SeedBlockTypesCommand`'s:

```php
            SyncBlockTypesCommand::class => [
                'class' => SyncBlockTypesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
```

and add `SyncBlockTypesCommand::class,` to the `$this->commands([...])` list next to `SeedBlockTypesCommand::class,`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/SyncBlockTypesTest.php`
Expected: PASS (both).

- [ ] **Step 6: phpcs + stage (hold)**

Run: `vendor/bin/phpcs app/Content/Console/SyncBlockTypesCommand.php app/Providers/ThalloServiceProvider.php tests/Integration/Content/SyncBlockTypesTest.php` → 0 errors.
```bash
git add app/Content/Console/SyncBlockTypesCommand.php app/Providers/ThalloServiceProvider.php tests/Integration/Content/SyncBlockTypesTest.php
```

### ✋ PHASE 2 CHECKPOINT — hold for user

Style block has shadow/color/opacity/padding/margin; Container has shadow depth; `thallo:blocks:sync` propagates the new fields to existing rows. Hold.

---

## Phase 3 — Docs + verification

### Task 5: Docs + full suite + phpcs

**Files:**
- Modify: `packages/thallo-render/docs/THEMING.md`

- [ ] **Step 1: Document the shadow system**

Append a `## 11. Shadows (elevation scale + block controls)` section to `THEMING.md` covering: the `--shadow-2xs…2xl` scale + `--shadow-none`; that `--shadow` aliases `--shadow-md` and dark just overrides `--shadow-color`/`--shadow-strength`; the `.thallo-shadow-{level}` utilities; that the Style block exposes shadow depth + `shadow_color` (colored shadow) + `shadow_opacity` (opacity modifier, 0–200 where 100 = as-designed) + `padding`/`margin`, and Container exposes shadow depth; and that operator color/opacity are delivered as inline `--shadow-color`/`--shadow-strength` vars.

```markdown
## 11. Shadows (elevation scale + block controls)

The theme ships a Tailwind-derived elevation scale as design tokens in `site.css`,
light + dark aware, plus page-builder controls on the Style and Container blocks.

### 11.1 The scale
`--shadow-none`, `--shadow-2xs`, `--shadow-xs`, `--shadow-sm`, `--shadow-md`,
`--shadow-lg`, `--shadow-xl`, `--shadow-2xl`. `--shadow` aliases `--shadow-md` (the
default), so every component that used the old flat shadow now renders md; floating
overlays (nav dropdown) use `--shadow-lg`. Apply a depth anywhere with the utility
classes `.thallo-shadow-{level}`.

### 11.2 Overridable color + opacity
Each token composes its color from `--shadow-color` and its opacity from
`calc(<base>% * --shadow-strength)` via `color-mix()`. Defaults: light slate-900 /
strength 1; dark black / strength 2.5 (the scale recomputes automatically in dark —
no separate dark shadow values). Override either variable on an element for a colored
or stronger/softer shadow.

### 11.3 Block controls
- **Style block:** `shadow` (depth), `shadow_color` (any hex — the "colored shadow"),
  `shadow_opacity` (0–200, where 100 = as-designed — the "opacity modifier"),
  `padding` (all sides) and `margin` (vertical). Color/opacity are emitted as inline
  `--shadow-color` / `--shadow-strength` on the wrapper. All default to `none`/unset.
- **Container:** `shadow` (depth) only. Defaults to `none`.
```

- [ ] **Step 2: Full backend suite**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit`
Expected: all green (adds ShadowTokensTest + the new render/seed cases; expect the total to rise, 0 failures).

- [ ] **Step 3: Admin suite (schema-driven editor sanity)**

Run: `pnpm --dir admin test && pnpm --dir admin type-check`
Expected: green; type-check exit 0. (The new fields render via the schema-driven block editor — enum selects, a hex string field, a number field — no bespoke admin code.)

- [ ] **Step 4: phpcs on all changed PHP**

Run:
```bash
vendor/bin/phpcs app/Content/Blocks/StarterBlockTypes.php \
  app/Content/Console/SyncBlockTypesCommand.php \
  app/Providers/ThalloServiceProvider.php \
  tests/Integration/Render/ShadowTokensTest.php \
  tests/Integration/Render/StyleBlockRenderTest.php \
  tests/Integration/Render/BlockLibraryRenderTest.php \
  tests/Integration/Content/SeedBlockTypesTest.php \
  tests/Integration/Content/SyncBlockTypesTest.php
```
Expected: 0 errors. Wrap any PHP line >120 chars.

- [ ] **Step 5: Refresh the existing block-type schemas via the sync command**

The seeder is create-only (it won't touch the already-seeded `style`/`container` rows), so run the additive sync built in Task 4 to push the new fields onto the dev DB:

Run: `php glueful thallo:blocks:sync`
Expected: `synced style (+5: shadow, shadow_color, shadow_opacity, padding, margin)` and `synced container (+1: shadow)`, then `Synced 2, unchanged 34, missing 0.`

Verify the admin now sees the fields:

Run: `php glueful thallo:blocks:sync` (again)
Expected: `Synced 0, unchanged 36, missing 0.` (idempotent — nothing left to add). The `style`/`container` rows now carry the new fields, so the schema-driven admin editor exposes the controls.

- [ ] **Step 6: Stage docs (hold)**

```bash
git add packages/thallo-render/docs/THEMING.md
```

### ✋ FINAL CHECKPOINT — hold for user

Everything green. Hold; when cleared, batch-commit the shadow system (this plan + all code) per the user's call.

---

## Self-review notes (for the executor)

- **Coverage:** scale+utilities+alias+overlay → T1; style-block shadow/color/opacity/padding/margin → T2; container shadow → T3; additive schema sync command → T4; docs+verify → T5.
- **Starter-schema evolution (resolved):** the seeder is create-only by design, so it won't push new fields to existing `style`/`container` rows. Render is unaffected (templates read `data.*`); the admin editor is DB-schema-driven. **Task 4 adds `thallo:blocks:sync`** — an additive-only sync (via `updateSchema`, which refuses removals) that propagates new starter fields to existing rows without disturbing operator edits. Run in Task 5 Step 5.
- **Type consistency:** enum values `['none','2xs','xs','sm','md','lg','xl','2xl']`, spacing `['none','small','medium','large']`, and modifier class names (`thallo-shadow-*`, `thallo-block-style--pad-*`/`--mar-*`) are identical across the seed schema, templates, CSS, and tests.
- **Non-regression:** `--shadow` alias keeps every existing `var(--shadow)` rendering (md); new controls default to `none`; Feature-C style tests and the container style-attribute test are unaffected.
