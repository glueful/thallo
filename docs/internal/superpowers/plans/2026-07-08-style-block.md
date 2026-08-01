# Style block (Feature C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `style` wrapper block that re-skins its subtree's accent/neutral design tokens (reusing Spec B's `ThemeColors`) and attaches a namespaced custom-CSS class hook, following the global color mode.

**Architecture:** A new server-seeded block type (`slug: style`) renders via `themes/default/templates/blocks/style.twig`. `ThemeColors` gains pure scoped-emission helpers; `RenderContextExtension` gains a `theme_style_scope()` function and a `style_hook` filter. The re-skin is delivered as a small inline `<style>` adjacent to each block (both light + dark rules, switched by `data-theme`). Preview, caching, and the admin editor need no new work — the values are ordinary published block content.

**Tech Stack:** PHP 8.3+, Twig, PostgreSQL (`app_test`), PHPUnit, existing thallo-render pack.

**Spec:** `docs/superpowers/specs/2026-07-08-style-block-design.md`.

## Global Constraints

Every task inherits these (verbatim from the spec pins):

- **Naming (fixed):** block slug `style`, label `Style`, template `packages/thallo-render/themes/default/templates/blocks/style.twig`; root CSS class `thallo-block-style`, inner `thallo-block-style__inner`; stored hook field `class_hook`; rendered hook class(es) `thallo-style-{hook}`; generated scope class `thallo-skin-{accent}-{neutral}` (unset dimension = literal `none`); public Twig helper `theme_style_scope(accent, neutral)` → `{ class, style }`; Twig filter `style_hook`.
- **Enum single source of truth + explicit inherit:** accent options `array_merge(['inherit'], ThemeColors::ACCENTS)`, neutral options `array_merge(['inherit'], ThemeColors::NEUTRALS)`. `inherit`/absent/empty/unknown all normalize to unset.
- **Scoped, partial emission:** accent and neutral independent; emit only the set dimension's vars; neither set → emit nothing.
- **No blue/slate fallback (scoped):** unknown/invalid accent or neutral → inherit/unset (never coerced to the global default).
- **Follows the global color mode:** scoped CSS emits both `.scope{…light}` and `html[data-theme="dark"] .scope{…dark}`.
- **Inline delivery:** each block emits its own `<style>` adjacent to its wrapper (not hoisted); identical pairs share one deterministic scope class.
- **Class hook never trusted raw:** `style_hook` sanitizes at render time regardless of any admin `pattern`; only tokens matching `^[A-Za-z_-][A-Za-z0-9_-]*$` survive, each namespaced under `thallo-style-`.
- **CSP unchanged:** reuses the `style-src 'unsafe-inline'` allowance already accepted for Spec B.
- **B stays byte-identical:** the `accentVars`/`neutralVars` refactor must not change `tokens()`/`css()` output (assert via B's existing `ThemeColorsTest`).

**Test command (backend):** `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit <path>`
**phpcs:** `vendor/bin/phpcs <path>` — 0 errors; wrap any line >120 chars.
**Commit policy (this session):** hold commits; batch at the phase checkpoints below and commit only when the user says so (matching the color-mode / theme-color plans). The per-task "stage" steps stage but do not commit.

---

## Phase 1 — Render primitives

### Task 1: `ThemeColors` scoped emission

**Files:**
- Modify: `packages/thallo-render/src/Theme/ThemeColors.php`
- Test: `tests/Integration/Render/ThemeColorsScopedTest.php` (create)

**Interfaces:**
- Consumes: existing `ThemeColors::ACCENT`, `NEUTRAL_LIGHT`, `NEUTRAL_DARK`, `normalizeAccent`, `normalizeNeutral`.
- Produces:
  - `ThemeColors::skinClass(?string $accent, ?string $neutral): string` — `''` or `thallo-skin-{accent|none}-{neutral|none}`.
  - `ThemeColors::scopedCss(?string $accent, ?string $neutral, string $scopeClass): string` — `''` or the light+dark rule pair, emitting only set dimensions.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/ThemeColorsScopedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Theme\ThemeColors;

/** Style-block spec §4.1: scoped, partial, mode-aware token emission. */
final class ThemeColorsScopedTest extends AppTestCase
{
    public function testSkinClassEncodesBothDimensionsAndUnsetAsNone(): void
    {
        self::assertSame('thallo-skin-rose-zinc', ThemeColors::skinClass('rose', 'zinc'));
        self::assertSame('thallo-skin-rose-none', ThemeColors::skinClass('rose', null));
        self::assertSame('thallo-skin-rose-none', ThemeColors::skinClass('rose', ''));
        self::assertSame('thallo-skin-none-slate', ThemeColors::skinClass(null, 'slate'));
    }

    public function testSkinClassIsEmptyWhenNeitherResolves(): void
    {
        self::assertSame('', ThemeColors::skinClass(null, null));
        self::assertSame('', ThemeColors::skinClass('banana', 'notacolor'));
        self::assertSame('', ThemeColors::skinClass('inherit', 'inherit'));
    }

    public function testScopedCssEmitsLightAndDarkForBothDimensions(): void
    {
        $css = ThemeColors::scopedCss('rose', 'zinc', 'thallo-skin-rose-zinc');
        self::assertStringContainsString('.thallo-skin-rose-zinc{', $css);
        self::assertStringContainsString('html[data-theme="dark"] .thallo-skin-rose-zinc{', $css);
        self::assertStringContainsString('--accent:#e11d48;', $css);   // rose light
        self::assertStringContainsString('--accent:#f43f5e;', $css);   // rose dark
        self::assertStringContainsString('--bg:#ffffff;', $css);       // zinc light bg
    }

    public function testScopedCssAccentOnlyOmitsNeutralVars(): void
    {
        $css = ThemeColors::scopedCss('rose', null, 'thallo-skin-rose-none');
        self::assertStringContainsString('--accent:#e11d48;', $css);
        self::assertStringNotContainsString('--bg:', $css);
        self::assertStringNotContainsString('--surface:', $css);
    }

    public function testScopedCssNeutralOnlyOmitsAccentVars(): void
    {
        $css = ThemeColors::scopedCss(null, 'zinc', 'thallo-skin-none-zinc');
        self::assertStringContainsString('--bg:#ffffff;', $css);
        self::assertStringNotContainsString('--accent:', $css);
    }

    public function testScopedCssIsEmptyWhenNeitherResolves(): void
    {
        self::assertSame('', ThemeColors::scopedCss(null, null, 'x'));
        self::assertSame('', ThemeColors::scopedCss('banana', 'notacolor', 'x'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsScopedTest.php`
Expected: FAIL — `Error: Call to undefined method Thallo\Render\Theme\ThemeColors::skinClass()`.

- [ ] **Step 3: Refactor `tokens()`/`css()` onto shared helpers (no output change)**

In `packages/thallo-render/src/Theme/ThemeColors.php`, replace the `tokens()` and `css()` methods with helper-backed versions. Add three private static helpers and keep output byte-identical:

```php
    /**
     * The 8 token values for a validated pair in one mode ('light'|'dark').
     *
     * @return array<string,string>
     */
    public static function tokens(string $accent, string $neutral, string $mode): array
    {
        return self::neutralVars($neutral, $mode) + self::accentVars($accent, $mode);
    }

    /** Override CSS for a validated pair, or '' when it is the default. */
    public static function css(string $accent, string $neutral): string
    {
        if ($accent === self::DEFAULT_ACCENT && $neutral === self::DEFAULT_NEUTRAL) {
            return '';
        }
        return ':root{' . self::declarations(self::tokens($accent, $neutral, 'light')) . '}'
            . 'html[data-theme="dark"]{' . self::declarations(self::tokens($accent, $neutral, 'dark')) . '}';
    }

    /** @return array<string,string> --accent + --accent-ink for one mode. */
    private static function accentVars(string $accent, string $mode): array
    {
        [$light, $dark] = self::ACCENT[$accent];
        return ['--accent' => $mode === 'dark' ? $dark : $light, '--accent-ink' => '#ffffff'];
    }

    /** @return array<string,string> the six neutral vars for one mode. */
    private static function neutralVars(string $neutral, string $mode): array
    {
        return $mode === 'dark' ? self::NEUTRAL_DARK[$neutral] : self::NEUTRAL_LIGHT[$neutral];
    }

    /** @param array<string,string> $tokens */
    private static function declarations(array $tokens): string
    {
        $decls = '';
        foreach ($tokens as $name => $value) {
            $decls .= "{$name}:{$value};";
        }
        return $decls;
    }
```

(Order is preserved: `neutralVars + accentVars` yields the same keys in the same order as the old `$neutralTokens + ['--accent'=>…,'--accent-ink'=>…]`, so `css()` emits the identical string.)

- [ ] **Step 4: Add `skinClass()` and `scopedCss()`**

Append to `ThemeColors` (after `css()`):

```php
    /** Deterministic scope class for a scoped re-skin, or '' when neither resolves. */
    public static function skinClass(?string $accent, ?string $neutral): string
    {
        $a = self::normalizeAccent($accent ?? '');
        $n = self::normalizeNeutral($neutral ?? '');
        if ($a === null && $n === null) {
            return '';
        }
        return 'thallo-skin-' . ($a ?? 'none') . '-' . ($n ?? 'none');
    }

    /**
     * Scoped CSS re-skinning ONLY the set dimensions, following the global mode:
     *   .scope{ <light> } html[data-theme="dark"] .scope{ <dark> }
     * Returns '' when neither accent nor neutral resolves.
     */
    public static function scopedCss(?string $accent, ?string $neutral, string $scopeClass): string
    {
        $a = self::normalizeAccent($accent ?? '');
        $n = self::normalizeNeutral($neutral ?? '');
        if ($a === null && $n === null) {
            return '';
        }
        $vars = static function (string $mode) use ($a, $n): array {
            return ($n !== null ? self::neutralVars($n, $mode) : [])
                + ($a !== null ? self::accentVars($a, $mode) : []);
        };
        return '.' . $scopeClass . '{' . self::declarations($vars('light')) . '}'
            . 'html[data-theme="dark"] .' . $scopeClass . '{' . self::declarations($vars('dark')) . '}';
    }
```

- [ ] **Step 5: Run the new test + B's existing test (byte-identical guard)**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsScopedTest.php tests/Integration/Render/ThemeColorsTest.php`
Expected: PASS (all). `ThemeColorsTest` passing proves the refactor left `tokens()`/`css()` output unchanged.

- [ ] **Step 6: phpcs**

Run: `vendor/bin/phpcs packages/thallo-render/src/Theme/ThemeColors.php tests/Integration/Render/ThemeColorsScopedTest.php`
Expected: 0 errors. Wrap any line >120 chars.

- [ ] **Step 7: Stage (hold commit)**

```bash
git add packages/thallo-render/src/Theme/ThemeColors.php tests/Integration/Render/ThemeColorsScopedTest.php
```

---

### Task 2: `theme_style_scope()` function + `style_hook` filter

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php`
- Test: `tests/Unit/Render/StyleHookTest.php` (create), `tests/Integration/Render/ThemeStyleScopeTest.php` (create)

**Interfaces:**
- Consumes: `ThemeColors::skinClass`, `ThemeColors::scopedCss` (Task 1).
- Produces:
  - Twig function `theme_style_scope(accent, neutral)` → `array{class: \Twig\Markup, style: \Twig\Markup}` (no `is_safe`; safety travels in each Markup — the class is enum-derived, P2).
  - Twig filter `style_hook` → `string` (` thallo-style-…` or `''`).
  - `RenderContextExtension::sanitizeStyleHook(string $raw): string` — pure static, testable.

- [ ] **Step 1: Write the failing unit test for the sanitizer**

Create `tests/Unit/Render/StyleHookTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\RenderContextExtension;

/** Style-block spec §4.3 / pin 7: the class-hook is never trusted raw. */
final class StyleHookTest extends TestCase
{
    public function testValidSingleTokenIsNamespaced(): void
    {
        self::assertSame(' thallo-style-promo', RenderContextExtension::sanitizeStyleHook('promo'));
    }

    public function testMultipleTokensEachNamespaced(): void
    {
        self::assertSame(
            ' thallo-style-promo thallo-style-dark-cta',
            RenderContextExtension::sanitizeStyleHook('promo dark-cta'),
        );
    }

    public function testPrefixIsIdempotent(): void
    {
        self::assertSame(' thallo-style-promo', RenderContextExtension::sanitizeStyleHook('thallo-style-promo'));
    }

    public function testMaliciousInputYieldsEmpty(): void
    {
        self::assertSame('', RenderContextExtension::sanitizeStyleHook('"><script>alert(1)</script>'));
        self::assertSame('', RenderContextExtension::sanitizeStyleHook('a"onclick=b'));
        self::assertSame('', RenderContextExtension::sanitizeStyleHook(''));
        self::assertSame('', RenderContextExtension::sanitizeStyleHook('   '));
    }

    public function testMixedGoodAndBadKeepsOnlyGood(): void
    {
        self::assertSame(' thallo-style-foo', RenderContextExtension::sanitizeStyleHook('foo bar">x'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Unit/Render/StyleHookTest.php`
Expected: FAIL — `Error: Call to undefined method …::sanitizeStyleHook()`.

- [ ] **Step 3: Add the sanitizer, the function, and the filter methods**

In `packages/thallo-render/src/RenderContextExtension.php`, add these methods (place near `themeColorsStyle()`):

```php
    /**
     * Style-block spec §4.2: the effective scoped skin for a block instance.
     * Returns a class fragment (leading space, '' when no re-skin) and the inline
     * <style> Markup ('' when no re-skin). Follows the global color mode. BOTH members
     * are Twig\Markup: the class is enum-derived (closed families → safe by
     * construction), so it is emitted as-is rather than relying on autoescape being a
     * no-op (P2). The <style> carries its own safety.
     *
     * @return array{class: \Twig\Markup, style: \Twig\Markup}
     */
    public function themeStyleScope(?string $accent, ?string $neutral): array
    {
        $class = ThemeColors::skinClass($accent, $neutral);
        $css = $class === '' ? '' : ThemeColors::scopedCss($accent, $neutral, $class);
        return [
            'class' => new \Twig\Markup($class === '' ? '' : ' ' . $class, 'UTF-8'),
            'style' => new \Twig\Markup($css === '' ? '' : "<style>{$css}</style>", 'UTF-8'),
        ];
    }

    /** Style-block spec §4.3: namespaced, sanitized custom-CSS class hook. */
    public function styleHook(mixed $value): string
    {
        return self::sanitizeStyleHook(is_string($value) ? $value : '');
    }

    /**
     * Pure sanitizer for the class hook (pin 7). Keeps only tokens matching
     * ^[A-Za-z_-][A-Za-z0-9_-]*$, strips any existing thallo-style- prefix
     * (idempotent), then namespaces each under thallo-style-. Returns a
     * leading-space-joined string, or '' when nothing survives.
     */
    public static function sanitizeStyleHook(string $raw): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (str_starts_with($token, 'thallo-style-')) {
                $token = substr($token, strlen('thallo-style-'));
            }
            if (preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $token) !== 1) {
                continue;
            }
            $out[] = 'thallo-style-' . $token;
        }
        return $out === [] ? '' : ' ' . implode(' ', $out);
    }
```

- [ ] **Step 4: Register the function and filter**

In `getFunctions()` (after the `theme_colors_style` registration ~line 162), add:

```php
            new TwigFunction('theme_style_scope', $this->themeStyleScope(...)),
```

In `getFilters()` (after `safe_url` ~line 363), add:

```php
            new TwigFilter('style_hook', $this->styleHook(...)),
```

(Neither is `is_safe`: `theme_style_scope` returns an array whose `style` member is already `Markup` and whose `class` member is autoescape-safe; `style_hook` output is autoescape-safe post-sanitize.)

- [ ] **Step 5: Run the unit test to verify it passes**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Unit/Render/StyleHookTest.php`
Expected: PASS.

- [ ] **Step 6: Write + run the Twig-integration test**

Create `tests/Integration/Render/ThemeStyleScopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Style-block spec §4.2/§4.3: the function + filter as templates see them. */
final class ThemeStyleScopeTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    private function render(string $tpl, array $ctx = []): string
    {
        return $this->env()->createTemplate($tpl)->render($ctx);
    }

    public function testFunctionReturnsClassAndInlineStyle(): void
    {
        $out = $this->render('{{ theme_style_scope("rose", "zinc").class }}|{{ theme_style_scope("rose", "zinc").style }}');
        self::assertStringContainsString('thallo-skin-rose-zinc', $out);
        self::assertStringContainsString('<style>.thallo-skin-rose-zinc{', $out);
        self::assertStringContainsString('html[data-theme="dark"] .thallo-skin-rose-zinc{', $out);
    }

    public function testFunctionEmitsNothingForInherit(): void
    {
        $out = $this->render('[{{ theme_style_scope("inherit", "inherit").class }}][{{ theme_style_scope("inherit", "inherit").style }}]');
        self::assertSame('[][]', $out);
    }

    public function testFilterNamespacesAndSanitizes(): void
    {
        self::assertSame(' thallo-style-promo', $this->render('{{ "promo"|style_hook }}'));
        // Malicious input is dropped AND autoescaped — no raw < or > reaches output.
        $out = $this->render('{{ "\"><script>"|style_hook }}');
        self::assertStringNotContainsString('<script>', $out);
        self::assertSame('', trim($out));
    }
}
```

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeStyleScopeTest.php`
Expected: PASS.

- [ ] **Step 7: phpcs**

Run: `vendor/bin/phpcs packages/thallo-render/src/RenderContextExtension.php tests/Unit/Render/StyleHookTest.php tests/Integration/Render/ThemeStyleScopeTest.php`
Expected: 0 errors. Wrap any line >120 chars.

- [ ] **Step 8: Stage (hold commit)**

```bash
git add packages/thallo-render/src/RenderContextExtension.php tests/Unit/Render/StyleHookTest.php tests/Integration/Render/ThemeStyleScopeTest.php
```

### ✋ PHASE 1 CHECKPOINT — hold for user

Render primitives complete (`ThemeColors` scoped emission, `theme_style_scope`, `style_hook`). All Phase-1 tests + B's `ThemeColorsTest` green. Hold; do not commit until cleared.

---

## Phase 2 — Block + wiring

### Task 3: `style.twig` template + `blocks.css` base

**Files:**
- Create: `packages/thallo-render/themes/default/templates/blocks/style.twig`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css`
- Test: `tests/Integration/Render/StyleBlockRenderTest.php` (create)

**Interfaces:**
- Consumes: `theme_style_scope()`, `style_hook` (Task 2), `blocks()` (existing recursive child renderer).
- Produces: the `blocks/style.twig` template contract (the `style` slug renders here).

- [ ] **Step 1: Write the failing render test**

Create `tests/Integration/Render/StyleBlockRenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Style-block spec §4.4: the style wrapper renders a scoped skin + class hook. */
final class StyleBlockRenderTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    public function testSkinAndHookAndChildrenRender(): void
    {
        $out = $this->render([[
            'id' => 's1', 'type' => 'style',
            'data' => [
                'accent' => 'rose', 'neutral' => 'zinc', 'class_hook' => 'promo',
                'content' => [['id' => 'r1', 'type' => 'rich_text', 'data' => ['body' => '<p>hi</p>']]],
            ],
        ]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style thallo-skin-rose-zinc thallo-style-promo"', $out);
        self::assertStringContainsString('<style>.thallo-skin-rose-zinc{', $out);
        self::assertSame(1, substr_count($out, '<style>'));         // exactly one skin style
        self::assertStringContainsString('thallo-block-style__inner', $out);
        self::assertStringContainsString('hi', $out);               // child rendered
        // P1: __inner is the first element child (canvas host), the <style> comes AFTER.
        self::assertLessThan(strpos($out, '<style>'), strpos($out, 'thallo-block-style__inner'));
    }

    public function testNoReskinStillWrapsAndAppliesHook(): void
    {
        $out = $this->render([[
            'id' => 's2', 'type' => 'style',
            'data' => ['class_hook' => 'plain', 'content' => []],
        ]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style thallo-style-plain"', $out);
        self::assertStringNotContainsString('<style>', $out);       // nothing to skin
    }

    public function testEmptyDataRendersCleanWrapper(): void
    {
        $out = $this->render([['id' => 's3', 'type' => 'style', 'data' => []]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style"', $out);
        self::assertStringNotContainsString('<style>', $out);
    }

    public function testNestedStyleBlocksEachEmitTheirSkin(): void
    {
        $out = $this->render([[
            'id' => 'o', 'type' => 'style',
            'data' => ['accent' => 'rose', 'content' => [[
                'id' => 'i', 'type' => 'style',
                'data' => ['neutral' => 'zinc', 'content' => []],
            ]]],
        ]]);
        self::assertStringContainsString('thallo-skin-rose-none', $out);
        self::assertStringContainsString('thallo-skin-none-zinc', $out);
        self::assertSame(2, substr_count($out, '<style>'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/StyleBlockRenderTest.php`
Expected: FAIL — Twig `Template "blocks/style.twig" is not defined` (the `blocks()` renderer can't resolve the `style` slug).

- [ ] **Step 3: Create the template**

Create `packages/thallo-render/themes/default/templates/blocks/style.twig`:

```twig
{# style — re-skins its subtree's design tokens (accent/neutral) and wraps a child
   block list, plus an optional custom-CSS class hook (style-block spec §4.4). Follows
   the global color mode: the scoped <style> carries both light and dark var sets,
   switched by data-theme. All fields optional except content; 'inherit'/absent/unknown
   accent|neutral = don't apply that override.
   NOTE (canvas): the <style> is rendered LAST, AFTER __inner, so the canvas bridge's
   host (w.firstElementChild) is always the content wrapper — never the <style> (P1). #}
{% set scope = theme_style_scope(data.accent|default(''), data.neutral|default('')) %}
<div class="thallo-block thallo-block-style{{ scope.class }}{{ data.class_hook|default('')|style_hook }}">
  <div class="thallo-block-style__inner">{{ blocks(data.content|default([])) }}</div>
  {{ scope.style }}
</div>
```

- [ ] **Step 4: Add the base CSS**

Append to `packages/thallo-render/themes/default/assets/blocks.css`:

```css
/* style — a token-scoping wrapper (style-block spec §4.5). It paints nothing itself;
   it only re-defines design-token custom properties for its subtree, which descendant
   blocks already consume. The scoped values arrive via the block's inline <style>. */
.thallo-block-style { display: block; }
.thallo-block-style__inner { display: block; }
```

- [ ] **Step 5: Run the render test to verify it passes**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/StyleBlockRenderTest.php`
Expected: PASS (all 4).

- [ ] **Step 6: phpcs (test file only — twig/css are not phpcs-linted)**

Run: `vendor/bin/phpcs tests/Integration/Render/StyleBlockRenderTest.php`
Expected: 0 errors. Wrap any line >120 chars.

- [ ] **Step 7: Stage (hold commit)**

```bash
git add packages/thallo-render/themes/default/templates/blocks/style.twig packages/thallo-render/themes/default/assets/blocks.css tests/Integration/Render/StyleBlockRenderTest.php
```

---

### Task 4: Seed the `style` block type

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php`
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php`
- Modify: `tests/Integration/Render/StarterTemplatesTest.php` (add a `style` fixture arm)
- Test: `tests/Integration/Content/SeedBlockTypesTest.php`

**Interfaces:**
- Consumes: `ThemeColors::ACCENTS`, `ThemeColors::NEUTRALS`; the `style.twig` template (Task 3); `StarterTemplatesTest`'s per-definition sweep (renders every slug, asserts `thallo-block-{slug}` non-empty).
- Produces: a seeded `style` block type with schema `accent`/`neutral`/`class_hook`/`content`.

- [ ] **Step 1: Update the seed-count expectations (failing test)**

In `tests/Integration/Content/SeedBlockTypesTest.php`, change the block-count assertion (currently `self::assertSame(35, $expected);`) to `36` and add `style` assertions. Find:

```php
        self::assertSame(35, $expected);
```

Replace with:

```php
        self::assertSame(36, $expected);
        // Style block (style-block spec §3): scoped accent/neutral re-skin + class hook.
        $style = $repo->findBySlug('style');
        self::assertSame('Layout', $style['category']);
        $fields = array_column($style['schema'], 'type', 'name');
        self::assertSame('enum', $fields['accent']);
        self::assertSame('enum', $fields['neutral']);
        self::assertSame('blocks', $fields['content']);
        $accentField = array_values(array_filter($style['schema'], fn ($f) => $f['name'] === 'accent'))[0];
        self::assertContains('inherit', $accentField['enum']);
        self::assertContains('rose', $accentField['enum']);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/SeedBlockTypesTest.php`
Expected: FAIL — count is still 35 (`Failed asserting that 35 is identical to 36`) and `style` is not found.

- [ ] **Step 3: Add the `ThemeColors` import to `StarterBlockTypes`**

In `app/Content/Blocks/StarterBlockTypes.php`, after the namespace line add:

```php
use Thallo\Render\Theme\ThemeColors;
```

- [ ] **Step 4: Add the `style` definition**

In the Layout section of `StarterBlockTypes::definitions()` (near `container`), add:

```php
            ['slug' => 'style', 'label' => 'Style', 'icon' => 'i-lucide-palette',
                'category' => 'Layout',
                'description' => 'Re-skin a group of blocks with a chosen accent/neutral, '
                    . 'plus an optional custom-CSS class hook.',
                'schema' => [
                    ['name' => 'accent', 'type' => 'enum',
                        'enum' => array_merge(['inherit'], ThemeColors::ACCENTS)],
                    ['name' => 'neutral', 'type' => 'enum',
                        'enum' => array_merge(['inherit'], ThemeColors::NEUTRALS)],
                    ['name' => 'class_hook', 'type' => 'string',
                        'pattern' => '[A-Za-z_][A-Za-z0-9_-]*( [A-Za-z_][A-Za-z0-9_-]*)*'],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
```

- [ ] **Step 5: Add a `style` fixture arm to the template sweep**

In `tests/Integration/Render/StarterTemplatesTest.php`, add a `style` arm to the `fixture()` match so the per-definition sweep exercises a real skin. Add before the match's default arm:

```php
            'style' => ['accent' => 'rose', 'neutral' => 'zinc', 'class_hook' => 'promo', 'content' => []],
```

- [ ] **Step 6: Run seed + template-sweep tests to verify they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/SeedBlockTypesTest.php tests/Integration/Render/StarterTemplatesTest.php`
Expected: PASS. The sweep now renders `style` and finds `thallo-block-style`.

- [ ] **Step 7: phpcs**

Run: `vendor/bin/phpcs app/Content/Blocks/StarterBlockTypes.php tests/Integration/Content/SeedBlockTypesTest.php tests/Integration/Render/StarterTemplatesTest.php`
Expected: 0 errors. Wrap any line >120 chars.

- [ ] **Step 8: Stage (hold commit)**

```bash
git add app/Content/Blocks/StarterBlockTypes.php tests/Integration/Content/SeedBlockTypesTest.php tests/Integration/Render/StarterTemplatesTest.php
```

### ✋ PHASE 2 CHECKPOINT — hold for user

The `style` block renders and seeds. All Phase-2 tests green. Hold; do not commit until cleared. (When executing against a real DB, reseed block types with `php glueful seed:block-types` so the admin picker shows the new type.)

---

## Phase 3 — Guards + docs

### Task 5: Canvas host skips non-visual first children (spec P1)

**Files:**
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`

**Interfaces:**
- Consumes: the existing bridge host-resolution sites (`selectWrapper`, `flipReorder`, `buildDragGhost`, and the drop-target scan — all currently `…firstElementChild`).
- Produces: `firstVisualChild(el)` — the first element child that renders (skips `STYLE`/`SCRIPT`/`LINK`/`TEMPLATE`). Invariant: the canvas host is never a block-owned `<style>`.

**Why:** the Style block renders a scoped `<style>` inside its wrapper. Task 3 already puts it LAST so `firstElementChild` is the content — but the bridge should not *depend* on template order. Anchoring the toolbar into a `<style>` (as `selectWrapper` would do via `host.insertBefore(toolbar, …)`) is a silent canvas-UX regression. This guard makes the host resolution robust regardless of child order.

- [ ] **Step 1: Write the failing test**

Add this test inside the `describe('preview bridge (direct eval)', …)` block in `admin/src/__tests__/preview-bridge-dom.spec.ts`:

```ts
  it('a leading <style> child is skipped: the toolbar anchors to the visual content, not the <style>', () => {
    // Style-block spec P1: a block-owned <style> must never become the canvas host.
    // Render it FIRST here (worst case) to prove the bridge — not template order —
    // guarantees the invariant.
    const w = wrapper(
      'skin-a-00001',
      '<style>.thallo-skin-rose-none{--accent:#e11d48;}</style>' +
        '<div class="thallo-block-style__inner"><a href="/x">x</a></div>',
    )
    document.body.appendChild(w)
    w.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(lastPost('thallo:block-select')).toMatchObject({ id: 'skin-a-00001' })
    const style = w.querySelector('style')!
    const inner = w.querySelector('.thallo-block-style__inner')!
    expect(style.classList.contains('thallo-canvas-anchor')).toBe(false)
    expect(inner.classList.contains('thallo-canvas-anchor')).toBe(true)
    expect(inner.querySelector(':scope > .thallo-canvas-toolbar')).not.toBeNull()
  })
```

- [ ] **Step 2: Run it to verify it fails**

Run: `pnpm --dir admin exec vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: FAIL — the toolbar anchors to the `<style>` (its `firstElementChild`), so `inner` has no anchor class and the `:scope > .thallo-canvas-toolbar` query is null.

- [ ] **Step 3: Add the `firstVisualChild` helper**

In `packages/thallo-render/assets/preview/preview-bridge.js`, after the `NO_CHILD_HOSTS` definition (~line 98), add:

```js
  // Metadata elements that render nothing: a block may own a <style> (the Style
  // block's scoped skin) — it must never be treated as the visual host. Resolve a
  // wrapper's host by skipping these when scanning its element children.
  var NON_VISUAL_HOSTS = { STYLE: 1, SCRIPT: 1, LINK: 1, TEMPLATE: 1 }
  function firstVisualChild(el) {
    var c = el.firstElementChild
    while (c && NON_VISUAL_HOSTS[c.tagName]) c = c.nextElementSibling
    return c
  }
```

- [ ] **Step 4: Route all four host lookups through the helper**

Replace each host-resolution line:

- In `selectWrapper`: `var host = w.firstElementChild` → `var host = firstVisualChild(w)`
- In `flipReorder`: `var h = el.firstElementChild` → `var h = firstVisualChild(el)`
- In `buildDragGhost`: `var host = w.firstElementChild` → `var host = firstVisualChild(w)`
- In the drop-target scan (~line 740): `var host = el.firstElementChild` → `var host = firstVisualChild(el)`

- [ ] **Step 5: Run the test to verify it passes**

Run: `pnpm --dir admin exec vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (the new test plus all existing bridge tests — the helper is a no-op for wrappers whose first child already renders).

- [ ] **Step 6: Stage (hold commit)**

```bash
git add packages/thallo-render/assets/preview/preview-bridge.js admin/src/__tests__/preview-bridge-dom.spec.ts
```

---

### Task 6: Cache/publish guard (spec P2)

**Files:**
- Modify: `tests/Integration/Render/RenderPageCacheTest.php`

**Interfaces:**
- Consumes: `RenderPageCache` middleware (existing), the shared tag-capable `CacheStore`.
- Produces: a test proving a style-skinned render is tagged with the page's entry surrogate and evicted when that tag is invalidated (what publishing the entry does).

- [ ] **Step 1: Add the import**

In `tests/Integration/Render/RenderPageCacheTest.php`, add to the `use` block:

```php
use Thallo\Render\Http\Middleware\RenderPageCache;
```

- [ ] **Step 2: Write the failing-then-passing guard test**

Append this method to the `RenderPageCacheTest` class:

```php
    public function testStyleSkinnedRenderIsPurgedByItsEntrySurrogateTag(): void
    {
        // Style-block spec P2: a page whose HTML carries a scoped style-block skin is
        // stored in the render page cache tagged with the page's entry surrogate
        // (thallo:entry:{uuid}, from Cache-Tag) alongside thallo:render:page. Publishing
        // that entry invalidates the SAME entry tag, so the stale skin is dropped — C
        // rides the existing content-publish purge path (end-to-end coverage:
        // testPublishPurgesCachedPageThroughTheRealListener) with no new cache code.
        // The middleware's appearance fingerprint is the GLOBAL default (blue-slate) —
        // the style block's rose-zinc skin lives in the HTML body, NOT the cache key.
        // This deliberately proves normal-content purge, decoupled from the fingerprint.
        $cache = $this->cache();
        $mw = new RenderPageCache($cache, 'default', 'blue-slate', true, 3600);

        $skinned = '<div class="thallo-block thallo-block-style thallo-skin-rose-zinc">'
            . '<div class="thallo-block-style__inner"></div>'
            . '<style>.thallo-skin-rose-zinc{--accent:#e11d48;}'
            . 'html[data-theme="dark"] .thallo-skin-rose-zinc{--accent:#f43f5e;}</style></div>';
        $next = static function () use ($skinned): Response {
            $res = new Response($skinned, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            $res->headers->set('Cache-Tag', 'thallo:entry:STYLE1, thallo:type:post');
            return $res;
        };

        $mw->handle(Request::create('/skinned', 'GET'), $next);

        $key = 'render:default:blue-slate:%2Fskinned';
        $stored = $cache->get($key);
        self::assertIsArray($stored);
        self::assertStringContainsString('thallo-skin-rose-zinc', $stored['body']);

        // Publishing the entry invalidates thallo:entry:STYLE1 — the render entry's tag.
        $cache->invalidateTags(['thallo:entry:STYLE1']);
        self::assertNull($cache->get($key)); // stale skinned render dropped
    }
```

- [ ] **Step 3: Run it to verify it passes**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php --filter=testStyleSkinnedRenderIsPurgedByItsEntrySurrogateTag`
Expected: PASS. (It exercises real middleware storage + tag invalidation on the shared `CacheStore`; `tearDown` already purges `render:*`.)

- [ ] **Step 4: Run the whole `RenderPageCacheTest` (no regression to the shared store)**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/RenderPageCacheTest.php`
Expected: PASS (all).

- [ ] **Step 5: phpcs**

Run: `vendor/bin/phpcs tests/Integration/Render/RenderPageCacheTest.php`
Expected: 0 errors. Wrap any line >120 chars.

- [ ] **Step 6: Stage (hold commit)**

```bash
git add tests/Integration/Render/RenderPageCacheTest.php
```

---

### Task 7: Docs + full-suite verification

**Files:**
- Modify: `packages/thallo-render/docs/THEMING.md`

- [ ] **Step 1: Document the `style` block**

Append this section to `packages/thallo-render/docs/THEMING.md`:

```markdown
## 10. Style block (scoped accent/neutral + class hook)

The **Style** block (`slug: style`, category Layout) re-skins a group of blocks
without swapping templates — the local sibling of the global theme color config (§9).

### 10.1 What it configures
- **Accent** and **Neutral** — the same closed Tailwind families as §9. Each is
  optional; the first option, **Inherit**, leaves that dimension unchanged.
- **Class hook** (`class_hook`) — an optional custom-CSS hook (see §10.4).
- **Content** — the child blocks the skin applies to.

### 10.2 How it re-skins (tokens only, follows color mode)
The block redefines design-token custom properties (`--accent`/`--accent-ink` for
accent; `--bg`/`--surface`/`--surface-2`/`--ink`/`--muted`/`--line` for neutral) on
its subtree via a generated scope class `thallo-skin-{accent}-{neutral}` (an unset
dimension is `none`, e.g. `thallo-skin-rose-none`). It **follows the global light/dark
mode** (§ color-mode): the emitted `<style>` carries both a light rule and an
`html[data-theme="dark"] …` rule, so the reader's chosen mode still wins. Only the
set dimension's variables are emitted; picking **Inherit** (or leaving a dimension
blank) emits nothing for it. An unknown/stale value is treated as inherit — a scoped
block has a safe do-nothing state, so it never falls back to the global blue/slate.

### 10.3 Delivery
Each Style block emits its own small `<style>` next to its wrapper (not hoisted to
`<head>`), so the block fragment stays self-contained for the visual canvas. Identical
accent/neutral pairs share one deterministic scope class. As with §9, the inline
`<style>` relies on the CSP `style-src 'unsafe-inline'` allowance (accepted for v1).

### 10.4 Custom class hook
The **Class hook** field lets you target the wrapper from `custom.css`. Enter a bare
hook name (e.g. `promo`); it renders as the namespaced class `thallo-style-promo` on
the wrapper. Multiple space-separated hooks are allowed. Input is sanitized at render
time — only safe class tokens survive — so it can never inject markup.

### 10.5 Preview & caching (inherited, no new machinery)
Style values are ordinary published block content, so they preview through the normal
content preview and their rendered HTML is invalidated by the existing content/publish
cache purge (the render entry is tagged with the page's entry surrogate). There is no
separate preview token, appearance fingerprint, or purge listener for this block.
```

- [ ] **Step 2: Run the full backend suite**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit`
Expected: all green (prior baseline was 1423 tests; this adds the new files — expect the total to rise and 0 failures).

- [ ] **Step 3: Run the admin suite (schema-driven editor sanity)**

Run: `pnpm --dir admin test && pnpm --dir admin type-check`
Expected: green (includes Task 5's `preview-bridge-dom.spec.ts` canvas guard); type-check exit 0. (No admin *source* changes beyond the bridge asset — the `style` block editor is schema-driven. If `openapi.json`/`schema.d.ts` regeneration is desired for completeness, run `composer docs:openapi` then `pnpm --dir admin gen:api`; there is no new admin API surface, so this is optional.)

- [ ] **Step 4: phpcs on all changed PHP**

Run:
```bash
vendor/bin/phpcs packages/thallo-render/src/Theme/ThemeColors.php \
  packages/thallo-render/src/RenderContextExtension.php \
  app/Content/Blocks/StarterBlockTypes.php \
  tests/Integration/Render/ThemeColorsScopedTest.php \
  tests/Unit/Render/StyleHookTest.php \
  tests/Integration/Render/ThemeStyleScopeTest.php \
  tests/Integration/Render/StyleBlockRenderTest.php \
  tests/Integration/Content/SeedBlockTypesTest.php \
  tests/Integration/Render/StarterTemplatesTest.php \
  tests/Integration/Render/RenderPageCacheTest.php
```
Expected: 0 errors. Wrap any line >120 chars.

- [ ] **Step 5: Stage docs (hold commit)**

```bash
git add packages/thallo-render/docs/THEMING.md
```

### ✋ FINAL CHECKPOINT — hold for user

Everything green. Hold; when cleared, batch-commit Feature C (the spec, this plan, and all code) grouped logically, per the user's call.

---

## Self-review notes (for the executor)

- **Spec coverage:** §1 shape → T4; §2 pins → Global Constraints + all tasks; §3 block def → T4; §4.1 ThemeColors → T1; §4.2 function → T2; §4.3 filter → T2; §4.4 template → T3; §4.5 CSS → T3; §5 free preview/cache/admin → T6 (cache guard) + T7 step 3 (admin sanity); §6 edge cases → T1/T2/T3 tests; §7 tests → T1–T6; §8 file map → tasks' Files; §9 deferred → not implemented (correct).
- **Review pins:** P1 (canvas host must not be a block `<style>`) → T3 (template renders `<style>` last) + T5 (bridge `firstVisualChild` guard + test); P2a (`theme_style_scope().class` returned as `Twig\Markup`, enum-derived) → T2; P2b (cache guard uses default `blue-slate` fingerprint, skin lives in the body) → T6.
- **Type consistency:** `skinClass(?string,?string):string`, `scopedCss(?string,?string,string):string`, `themeStyleScope(?string,?string):array{class:\Twig\Markup,style:\Twig\Markup}`, `sanitizeStyleHook(string):string`, `styleHook(mixed):string` — used consistently across T1→T3.
- **Ordering:** T1→T2 (function consumes ThemeColors); T2→T3 (template consumes function+filter); T3→T4 (sweep needs template); T5 (bridge) independent but grouped in Phase 3; T4/T5/T6 independent of one another.
