# Color Mode (A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship light/dark/system color mode for the Thallo default theme — flash-free, HTML mode-agnostic, disable-able — per the approved spec `docs/superpowers/specs/2026-07-07-color-mode-design.md`.

**Architecture:** A byte-stable inline resolver (single PHP constant, rendered verbatim in `<head>`) stamps `html[data-theme="light|dark"]` before paint. A global `blocks.js` runtime — hard-gated on a server `data-color-mode-enabled` marker — owns runtime `data-theme` writes and the OS `matchMedia` listener; toggle blocks are optional consumers. Dark styling is a `html[data-theme="dark"]` token re-map. Everything is gated by `config/theme.php → color_mode.enabled`.

**Tech Stack:** PHP 8.3 (Glueful), Twig (thallo-render), vanilla JS (`blocks.js`), PostgreSQL (`app_test`), PHPUnit.

## Global Constraints

- **Naming:** "Thallo" in docs/copy/storage/event names. Storage key `thallo.colorMode`; event `thallo:color-mode-change`.
- **Storage values:** `light | dark | system`; default `system`.
- **DOM:** resolver stamps `html[data-theme="light|dark"]` — never `system`.
- **Cache:** server HTML is mode-agnostic; no server branch on preference. The `data-color-mode-enabled` marker is config-driven (identical for all visitors), so it does not vary the cache.
- **CSP:** the resolver is defined once as `Thallo\Render\ColorMode::RESOLVER_JS`, rendered verbatim; its `sha256` is `ColorMode::RESOLVER_SHA256`, published for `script-src`. No nonce.
- **Disable:** `color_mode.enabled === false` ⇒ no resolver, no marker, no toggle UI, inert even if `localStorage['thallo.colorMode']=dark`.
- **Env:** `THALLO_COLOR_MODE_ENABLED`.
- **Runtime:** global, in external deferred `blocks.js`; hard-gated on the marker.
- **Workflow:** work on `dev` directly (no feature branch). Commits are **batched at the phase boundaries marked below and held until the user gives the go-ahead**; never commit CLAUDE.md; no AI/Anthropic attribution in commit messages.
- **Test DB:** `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit`; rebuild via `composer test:reset-db && composer test:migrate`.

---

## File Structure

- `config/theme.php` **(create)** — `color_mode.enabled` from `THALLO_COLOR_MODE_ENABLED`.
- `.env.example` **(modify)** — document `THALLO_COLOR_MODE_ENABLED`.
- `packages/thallo-render/src/ColorMode.php` **(create)** — `RESOLVER_JS`, `RESOLVER_SHA256`, `scriptTag()`.
- `packages/thallo-render/src/RenderContextExtension.php` **(modify)** — `+bool $colorModeEnabled`, `color_mode_enabled()`, `color_mode_script()`.
- `packages/thallo-render/src/RenderServiceProvider.php:~337` **(modify)** — wire `config($context,'theme.color_mode.enabled',true)`.
- `packages/thallo-render/themes/default/templates/layout.twig` **(modify)** — `<html>` marker + resolver in `<head>`.
- `packages/thallo-render/themes/default/assets/blocks.css` **(modify)** — `html[data-theme="dark"]` token re-map + block audits + `.thallo-block-color_mode` styles.
- `packages/thallo-render/themes/default/assets/blocks.js` **(modify)** — global color-mode runtime.
- `packages/thallo-render/themes/default/templates/blocks/color_mode.twig` **(create)** — toggle block.
- `app/Content/Blocks/StarterBlockTypes.php` **(modify)** — seed `color_mode` (34 → 35).
- `app/Content/Regions/RegionDefinitions.php` **(modify)** — add `color_mode` to `header`.
- `database/migrations/021_ReseedBlockTypesForThemeRewrite.php` **(modify)** — add `color_mode` to `ADDED`.
- `tests/Integration/Render/ColorModeTest.php` **(create)** — hash drift, emit/omit, byte-match, mode-agnostic, disabled-inert.
- `tests/Integration/Render/DarkTokensTest.php` **(create)** — dark re-map presence.
- `tests/Integration/Render/ColorModeRuntimeTest.php` **(create)** — node-evaluated runtime hard-gate + system/OS behavior.
- `tests/Integration/Content/SeedBlockTypesTest.php` **(modify)** — count 34 → 35 + color_mode assert.
- `tests/Integration/Render/StarterTemplatesTest.php` **(modify)** — `color_mode` fixture.
- `packages/thallo-render/docs/THEMING.md` **(modify)** — Color mode section + published hash.

---

## Task 1: ColorMode constant, config, and the hash source-of-truth

**Files:**
- Create: `packages/thallo-render/src/ColorMode.php`
- Create: `config/theme.php`
- Modify: `.env.example`
- Test: `tests/Integration/Render/ColorModeTest.php`

**Interfaces:**
- Produces: `Thallo\Render\ColorMode::RESOLVER_JS` (string), `::RESOLVER_SHA256` (string, base64), `ColorMode::scriptTag(): string`.
- Config: `config('theme.color_mode.enabled')` → bool (default true).

- [ ] **Step 1: Write the ColorMode class**

Create `packages/thallo-render/src/ColorMode.php`:
```php
<?php

declare(strict_types=1);

namespace Thallo\Render;

/**
 * Color-mode support (color-mode spec §3.1/§5). RESOLVER_JS is the ONE definition
 * of the no-flash resolver; the layout renders it verbatim so its sha256 (published
 * for CSP `script-src`) has a real source of truth instead of trusting byte stability
 * of the template. Never interpolate into RESOLVER_JS — one string, one hash.
 */
final class ColorMode
{
    /** The inline no-flash resolver. Byte-stable literal — do NOT edit without updating RESOLVER_SHA256. */
    public const RESOLVER_JS = "(function(){try{var k=localStorage.getItem('thallo.colorMode')||'system';var d=k==='dark'||(k!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=d?'dark':'light';}catch(e){document.documentElement.dataset.theme='light';}})();";

    /** base64(sha256(RESOLVER_JS)) — the value operators add to a strict CSP as 'sha256-...'. Set in Step 4. */
    public const RESOLVER_SHA256 = '__FILL_IN_STEP_4__';

    /** The exact <script> the layout emits (verbatim resolver, no attributes). */
    public static function scriptTag(): string
    {
        return '<script>' . self::RESOLVER_JS . '</script>';
    }
}
```

- [ ] **Step 2: Write `config/theme.php`**

Create `config/theme.php`:
```php
<?php

declare(strict_types=1);

return [
    // Color mode (color-mode spec §3.4). false ⇒ no resolver, no marker, no toggle UI;
    // the site renders light-only regardless of any stored visitor preference.
    'color_mode' => [
        'enabled' => (bool) env('THALLO_COLOR_MODE_ENABLED', true),
    ],
];
```

- [ ] **Step 3: Write the hash-drift test (fails first)**

Create `tests/Integration/Render/ColorModeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\ColorMode;

final class ColorModeTest extends AppTestCase
{
    public function testPublishedHashMatchesTheResolverConstant(): void
    {
        // The documented CSP hash is the source of truth; if the resolver bytes
        // change without re-publishing, this fails (color-mode spec §6).
        self::assertSame(
            base64_encode(hash('sha256', ColorMode::RESOLVER_JS, true)),
            ColorMode::RESOLVER_SHA256,
        );
    }
}
```

- [ ] **Step 4: Run it, capture the real hash, paste it in**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && php -r "require 'vendor/autoload.php'; echo base64_encode(hash('sha256', Thallo\Render\ColorMode::RESOLVER_JS, true)), PHP_EOL;"`
Copy the printed value into `ColorMode::RESOLVER_SHA256` (replacing `__FILL_IN_STEP_4__`).

- [ ] **Step 5: Run the test to verify it passes**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit --filter testPublishedHashMatchesTheResolverConstant`
Expected: PASS.

- [ ] **Step 6: Document the env var**

Add to `.env.example` (near other feature flags):
```
# Color mode (light/dark/system). false = light-only, no toggle, no dark CSS applied.
THALLO_COLOR_MODE_ENABLED=true
```

---

## Task 2: Twig plumbing + layout emit (resolver + marker, gated)

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php`
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (~line 337)
- Modify: `packages/thallo-render/themes/default/templates/layout.twig`
- Test: `tests/Integration/Render/ColorModeTest.php`

**Interfaces:**
- Consumes: `ColorMode::scriptTag()`, `config('theme.color_mode.enabled')`.
- Produces: Twig `color_mode_enabled(): bool`, `color_mode_script(): \Twig\Markup`.

- [ ] **Step 1: Write failing render tests**

Add to `tests/Integration/Render/ColorModeTest.php` (reuse the `env()`/render helpers from `StarterTemplatesTest` — build a Twig env and render `layout.twig`, or render via the RenderController; match the existing suite's approach). Add:
```php
    public function testLayoutEmitsResolverAndMarkerVerbatimWhenEnabled(): void
    {
        $html = $this->renderLayout(colorMode: true); // helper: renders layout.twig with color mode on
        self::assertStringContainsString(ColorMode::scriptTag(), $html);        // byte-for-byte resolver
        self::assertStringContainsString('data-color-mode-enabled="true"', $html);
        self::assertStringNotContainsString('data-theme=', $html);              // mode-agnostic HTML
    }

    public function testLayoutEmitsNeitherScriptNorMarkerWhenDisabled(): void
    {
        $html = $this->renderLayout(colorMode: false);
        self::assertStringNotContainsString('thallo.colorMode', $html);         // no resolver
        self::assertStringNotContainsString('data-color-mode-enabled', $html);  // no marker → runtime inert
    }
```
(Implement `renderLayout(bool $colorMode)` in the test by constructing `RenderContextExtension` with the flag and rendering the real `layout.twig` through a `TwigFactory`, mirroring `StarterTemplatesTest::env()`.)

- [ ] **Step 2: Run — verify they fail**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ColorModeTest.php`
Expected: FAIL (functions/markup not present yet).

- [ ] **Step 3: Add the constructor flag + Twig functions**

In `RenderContextExtension.php` constructor, add a parameter after `$themeSource`:
```php
        private readonly ?ActiveThemeSource $themeSource = null,
        /** color-mode spec §3.4: false → no resolver, no marker, toggle renders nothing. */
        private readonly bool $colorModeEnabled = true,
```
In `getFunctions()` add (near `custom_css`):
```php
            new TwigFunction('color_mode_enabled', $this->colorModeEnabled(...)),
            // is_safe html: trusted, static, theme-owned resolver (mirrors icon()).
            new TwigFunction('color_mode_script', $this->colorModeScript(...), ['is_safe' => ['html']]),
```
Add methods (near `customCss()`):
```php
    public function colorModeEnabled(): bool
    {
        return $this->colorModeEnabled;
    }

    /** The verbatim no-flash resolver, or empty when disabled. */
    public function colorModeScript(): \Twig\Markup
    {
        $html = $this->colorModeEnabled ? \Thallo\Render\ColorMode::scriptTag() : '';
        return new \Twig\Markup($html, 'UTF-8');
    }
```

- [ ] **Step 4: Wire the config flag at construction**

In `RenderServiceProvider.php` at the `new RenderContextExtension(...)` factory (~line 337), pass the flag as the final argument:
```php
            colorModeEnabled: (bool) config($context, 'theme.color_mode.enabled', true),
```
(Use the named argument to avoid depending on positional order; if the surrounding call is positional, append it in constructor order after `$themeSource`.)

- [ ] **Step 5: Emit in the layout**

In `layout.twig`, add the marker to `<html>`:
```twig
<html lang="{{ site.locale }}"{% if color_mode_enabled() %} data-color-mode-enabled="true"{% endif %}>
```
And emit the resolver as the first thing in `<head>`, BEFORE any `<link rel="stylesheet">` (immediately after the `<meta name="viewport">` line):
```twig
  <meta name="viewport" content="width=device-width, initial-scale=1">
  {# color-mode spec §3.1: no-flash resolver, verbatim from ColorMode::RESOLVER_JS,
     BEFORE any CSS so data-theme is set pre-paint. Emitted only when enabled. #}
  {{ color_mode_script() }}
```

- [ ] **Step 6: Run — verify pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ColorModeTest.php`
Expected: PASS (all Task 1 + Task 2 tests).

- [ ] **Step 7 — PHASE 1 CHECKPOINT (hold for user):** foundation (config + constant + Twig + layout) is complete and green. Do not commit yet; surface for review. When cleared, batch-commit Tasks 1–2 together.

---

## Task 3: Dark token set + block visual pass

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/blocks.css`
- Test: `tests/Integration/Render/DarkTokensTest.php`

**Interfaces:**
- Consumes: the `:root` token names already defined at the top of `blocks.css`.
- Produces: a `html[data-theme="dark"]` block re-mapping every token.

- [ ] **Step 1: Write the presence test (fails first)**

Create `tests/Integration/Render/DarkTokensTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

final class DarkTokensTest extends AppTestCase
{
    public function testDarkThemeReMapsCoreTokens(): void
    {
        $css = file_get_contents(
            $this->appContext()->getBasePath()
            . '/../packages/thallo-render/themes/default/assets/blocks.css'
        ); // adjust the relative path to the theme assets for the suite's base
        self::assertStringContainsString('html[data-theme="dark"]', $css);
        foreach (['--bg', '--ink', '--muted', '--surface', '--line'] as $token) {
            self::assertMatchesRegularExpression(
                '/html\[data-theme="dark"\][^}]*' . preg_quote($token, '/') . '\s*:/s',
                $css,
                "dark theme must re-map {$token}",
            );
        }
    }
}
```
(Confirm the exact theme-assets path for the test harness before running; adjust the `file_get_contents` path accordingly.)

- [ ] **Step 2: Run — verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/DarkTokensTest.php`
Expected: FAIL.

- [ ] **Step 3: Add the dark token re-map**

Append to `blocks.css` (right after the `:root { … }` token block, so it reads as the paired light/dark definition):
```css
/* Dark mode (color-mode spec §3.3): re-map the SAME tokens; every block is authored
   against these, so the switch carries the theme. Keyed on html[data-theme="dark"],
   which the resolver stamps pre-paint. */
html[data-theme="dark"] {
  --bg: #0b0f19;
  --ink: #e8ecf4;
  --muted: #94a3b8;
  --surface: #141a27;
  --surface-2: #1b2333;
  --line: #2a3547;
  --accent-ink: #ffffff;
  --shadow: 0 1px 3px rgba(0, 0, 0, 0.6), 0 8px 24px rgba(0, 0, 0, 0.45);
}
```
(`--accent` stays the brand color across modes — Spec B will make it operator-configurable.)

- [ ] **Step 4: Run — verify pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/DarkTokensTest.php`
Expected: PASS.

- [ ] **Step 5: Block visual audit (manual, token-gap cases)**

Serve the theme, set `document.documentElement.dataset.theme='dark'` in devtools, and eyeball each token-gap case the re-map doesn't fully cover; adjust rules in `blocks.css` as needed (each fix is a `color-mix`/token tweak, not a new utility):
- `button`/`card` `--soft`/`--subtle`/`--ghost` `color-mix(... var(--bg))` tints — legibility on dark.
- Inverted bands: `section--inverted`, `cta--solid`, `button__link--solid`, `card--variant-solid` — still read against dark.
- `--shadow` heaviness on `columns__col`, `card`, `logo`.
- `logos--grayscale`, image/video posters, `separator`/`accordion` hairlines.
Record anything needing a dark *asset* (logos) as a follow-up note in THEMING.md (out of scope for A).

---

## Task 4: Global color-mode runtime in `blocks.js`

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/blocks.js`
- Test: `tests/Integration/Render/ColorModeRuntimeTest.php`

**Interfaces:**
- Consumes: the `data-color-mode-enabled` marker, `localStorage['thallo.colorMode']`, `.thallo-block-color_mode [data-color-mode-option]` controls.
- Produces: runtime `data-theme` writes; `thallo:color-mode-change` events.

- [ ] **Step 1: Append the runtime module**

Add to `blocks.js` (as its own IIFE alongside the other enhancers; `blocks.js` is `defer`, so the DOM is ready):
```js
/* Color mode (color-mode spec §3.6): a GLOBAL runtime — not tied to any toggle block.
   Hard-gated on the server marker so a disabled site is inert even if a visitor has an
   old 'dark' preference in localStorage. Owns data-theme writes + the OS listener;
   toggle controls are optional consumers.
   The color-mode:start/end markers below are an extraction contract — ColorModeRuntimeTest
   slices the IIFE out between them to evaluate it under a stubbed DOM. Keep them. */
/* color-mode:start */
(function () {
  var root = document.documentElement;
  if (root.getAttribute('data-color-mode-enabled') !== 'true') return; // hard gate
  var KEY = 'thallo.colorMode';
  var EVT = 'thallo:color-mode-change';
  var mq = window.matchMedia('(prefers-color-scheme: dark)');

  function pref() {
    try {
      var v = localStorage.getItem(KEY);
      return v === 'light' || v === 'dark' || v === 'system' ? v : 'system';
    } catch (e) { return 'system'; }
  }
  function resolve(p) { return p === 'dark' || (p === 'system' && mq.matches) ? 'dark' : 'light'; }
  function apply(p) { root.dataset.theme = resolve(p); }
  function reflect(p) {
    document.querySelectorAll('.thallo-block-color_mode [data-color-mode-option]').forEach(function (b) {
      b.setAttribute('aria-checked', b.getAttribute('data-color-mode-option') === p ? 'true' : 'false');
    });
  }
  function set(p) {
    try { localStorage.setItem(KEY, p); } catch (e) {}
    apply(p);
    document.dispatchEvent(new CustomEvent(EVT, { detail: p }));
  }

  // Optional consumers: wire every toggle control's options.
  document.querySelectorAll('.thallo-block-color_mode [data-color-mode-option]').forEach(function (b) {
    b.addEventListener('click', function () { set(b.getAttribute('data-color-mode-option')); });
  });
  // Keep controls in sync across the page.
  document.addEventListener(EVT, function (e) { reflect(e.detail); });
  // OS change only matters while preference is 'system'.
  mq.addEventListener('change', function () { if (pref() === 'system') apply('system'); });

  reflect(pref()); // resolver already set data-theme pre-paint; just sync controls.
})();
/* color-mode:end */
```

- [ ] **Step 2: Write the executable runtime test (fails first)**

There is no vitest/jsdom harness in `thallo-render` (only the separate admin app has one), but node is available. This test extracts the `color-mode:start/end` IIFE from `blocks.js` and runs it under a hand-stubbed DOM in node — proving the hard-gate (the exact bug we pinned) and the system/OS behavior, no npm deps. It **skips** cleanly where node is absent.

Create `tests/Integration/Render/ColorModeRuntimeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the hard-gated color-mode runtime (color-mode spec §3.6).
 * Extracts the color-mode:start/end IIFE from blocks.js and evaluates it under a
 * hand-stubbed DOM in Node (no jsdom/npm). Asserts: (A) no marker → no localStorage
 * read + no data-theme write (inert even with a stale 'dark' preference); (B) marker +
 * system → matchMedia drives data-theme and an OS change updates it.
 */
final class ColorModeRuntimeTest extends AppTestCase
{
    private function nodeBin(): string
    {
        $bin = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($bin === '') {
            self::markTestSkipped('node is not available');
        }
        return $bin;
    }

    private function runtimeSource(): string
    {
        $js = (string) file_get_contents(
            __DIR__ . '/../../../packages/thallo-render/themes/default/assets/blocks.js'
        );
        self::assertSame(
            1,
            preg_match('#/\* color-mode:start \*/(.*?)/\* color-mode:end \*/#s', $js, $m),
            'blocks.js must delimit the runtime with color-mode:start/end markers',
        );
        return trim($m[1]);
    }

    /** JSON-encode a PHP scalar as a JS literal ('' → null). */
    private function js(mixed $v): string
    {
        return $v === '' ? 'null' : (string) json_encode($v);
    }

    /** @return array<string,mixed> */
    private function evaluate(string $marker, string $stored, bool $matches, bool $flip): array
    {
        $bin = $this->nodeBin();
        $harness = <<<JS
        'use strict';
        let getItemCalls = 0;
        const mqListeners = {};
        const mq = { matches: {$this->js($matches)}, addEventListener: (t, cb) => { mqListeners[t] = cb; } };
        globalThis.window = { matchMedia: () => mq };
        const docListeners = {};
        globalThis.document = {
          documentElement: {
            _attrs: { 'data-color-mode-enabled': {$this->js($marker)} },
            dataset: {},
            getAttribute(n) { return this._attrs[n] ?? null; },
          },
          querySelectorAll: () => [],
          addEventListener: (t, cb) => { docListeners[t] = cb; },
          dispatchEvent: (e) => { if (docListeners[e.type]) docListeners[e.type](e); return true; },
        };
        globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } };
        globalThis.localStorage = {
          _v: {$this->js($stored)},
          getItem() { getItemCalls++; return this._v; },
          setItem(k, v) { this._v = v; },
        };

        {$this->runtimeSource()}

        const out = { getItemCalls, themeAfterLoad: document.documentElement.dataset.theme ?? null };
        if (mqListeners.change) { mq.matches = {$this->js($flip)}; mqListeners.change(); }
        out.themeAfterOsChange = document.documentElement.dataset.theme ?? null;
        console.log(JSON.stringify(out));
        JS;

        $file = sys_get_temp_dir() . '/thallo-colormode-' . bin2hex(random_bytes(6)) . '.cjs';
        file_put_contents($file, $harness);
        $json = shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);
        $decoded = json_decode(trim((string) $json), true);
        self::assertIsArray($decoded, "node harness output was not JSON: {$json}");
        return $decoded;
    }

    public function testDisabledIsInertEvenWithStaleDarkPreference(): void
    {
        $r = $this->evaluate(marker: '', stored: 'dark', matches: false, flip: false);
        self::assertSame(0, $r['getItemCalls'], 'no marker → runtime must not read localStorage');
        self::assertNull($r['themeAfterLoad'], 'no marker → runtime must not write data-theme');
    }

    public function testEnabledSystemFollowsMatchMediaAndOsChanges(): void
    {
        $r = $this->evaluate(marker: 'true', stored: 'system', matches: true, flip: false);
        self::assertSame('dark', $r['themeAfterLoad'], 'marker + system + prefers-dark → dark');
        self::assertSame('light', $r['themeAfterOsChange'], 'OS change to light while system → light');
    }
}
```

- [ ] **Step 3: Run — verify it fails, then passes**

Run before Step 1 of this task exists in `blocks.js`: the marker `preg_match` assertion FAILS (no markers yet). After appending the runtime (Step 1): 
Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ColorModeRuntimeTest.php`
Expected: PASS (or SKIPPED only where node is unavailable).

- [ ] **Step 4: Manual QA smoke (in addition to the executable test)**

Browser sanity: first-paint no-flash in dark; three states + multi-toggle sync; private-mode/localStorage-throw → light, no error. (The hard-gate and system/OS behavior are now covered by Step 2, not left to eyeballing.)

---

## Task 5: The `color_mode` toggle block

**Files:**
- Create: `packages/thallo-render/themes/default/templates/blocks/color_mode.twig`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (control styles)
- Modify: `app/Content/Blocks/StarterBlockTypes.php`
- Modify: `app/Content/Regions/RegionDefinitions.php`
- Modify: `database/migrations/021_ReseedBlockTypesForThemeRewrite.php`
- Test: `tests/Integration/Content/SeedBlockTypesTest.php`, `tests/Integration/Render/StarterTemplatesTest.php`, `tests/Integration/Render/ColorModeTest.php`

**Interfaces:**
- Consumes: `color_mode_enabled()`, `icon()`.
- Produces: block slug `color_mode`; `.thallo-block-color_mode` + `[data-color-mode-option]` markup.

- [ ] **Step 1: Seed + count tests first**

In `tests/Integration/Content/SeedBlockTypesTest.php` change `self::assertSame(34, $expected);` → `self::assertSame(35, $expected);` and add:
```php
        self::assertSame('Content', $repo->findBySlug('color_mode')['category']);
```
In `tests/Integration/Render/StarterTemplatesTest.php` add a fixture arm (near `button`):
```php
            'color_mode' => [],
```
In `tests/Integration/Render/ColorModeTest.php` add:
```php
    public function testToggleBlockRendersControlWhenEnabledAndNothingWhenDisabled(): void
    {
        $on = $this->renderBlock('color_mode', [], colorMode: true);   // helper: render one block via the theme
        self::assertStringContainsString('thallo-block-color_mode', $on);
        self::assertStringContainsString('data-color-mode-option="system"', $on);

        $off = $this->renderBlock('color_mode', [], colorMode: false);
        self::assertSame('', trim($off));
    }
```
(Implement `renderBlock()` like `renderLayout()` — a Twig env with the flag, rendering `{{ blocks([{id,type,data}]) }}`.)

- [ ] **Step 2: Run — verify failures**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ColorModeTest.php tests/Integration/Content/SeedBlockTypesTest.php`
Expected: FAIL (block/seed missing).

- [ ] **Step 3: Seed the block**

In `StarterBlockTypes.php`, add after `button` (Content category):
```php
            // Color-mode switch (color-mode spec §3.5): a 3-way light/system/dark
            // segmented control. Presentation only — no data fields.
            ['slug' => 'color_mode', 'label' => 'Color mode', 'icon' => 'i-lucide-sun-moon',
                'category' => 'Content',
                'description' => 'A light / system / dark color-mode switch for visitors.',
                'schema' => []],
```

- [ ] **Step 4: Add to the reseed migration + region palette**

In `021_ReseedBlockTypesForThemeRewrite.php` add `'color_mode'` to the `ADDED` array (and bump the docstring/`getDescription` counts by one). In `RegionDefinitions.php` add `'color_mode'` to the `header` palette list.

- [ ] **Step 5: Write the template**

Create `packages/thallo-render/themes/default/templates/blocks/color_mode.twig`:
```twig
{# color_mode — a 3-way light/system/dark switch (color-mode spec §3.5). Server renders
   all three options with stable hooks; the global blocks.js runtime reflects the stored
   preference and wires clicks. Renders NOTHING when color mode is disabled. #}
{% if color_mode_enabled() %}
<div class="thallo-block thallo-block-color_mode" role="radiogroup" aria-label="Color mode">
  <button type="button" class="thallo-block-color_mode__option" data-color-mode-option="light" role="radio" aria-checked="false" aria-label="Light">{{ icon('sun') ?? '☀' }}</button>
  <button type="button" class="thallo-block-color_mode__option" data-color-mode-option="system" role="radio" aria-checked="false" aria-label="System">{{ icon('monitor') ?? '◻' }}</button>
  <button type="button" class="thallo-block-color_mode__option" data-color-mode-option="dark" role="radio" aria-checked="false" aria-label="Dark">{{ icon('moon') ?? '☾' }}</button>
</div>
{% endif %}
```

- [ ] **Step 6: Style the control**

Append to `blocks.css` (Primitive-blocks section):
```css
/* color_mode — a segmented light/system/dark switch. The active option (aria-checked)
   is set by the blocks.js runtime. */
.thallo-block-color_mode {
  display: inline-flex; gap: 2px; padding: 3px;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: 999px;
}
.thallo-block-color_mode__option {
  display: inline-flex; align-items: center; justify-content: center;
  width: 2rem; height: 2rem; padding: 0;
  border: 0; background: transparent; color: var(--muted);
  border-radius: 999px; cursor: pointer;
  transition: background-color .15s ease, color .15s ease;
}
.thallo-block-color_mode__option svg { width: 1.1rem; height: 1.1rem; }
.thallo-block-color_mode__option:hover { color: var(--ink); }
.thallo-block-color_mode__option[aria-checked="true"] {
  background: var(--bg); color: var(--ink); box-shadow: var(--shadow);
}
```

- [ ] **Step 7: Reseed dev DB, rebuild test DB, run**

Run:
```
cd /Users/michaeltawiahsowah/Sites/glueful/thallo
printf 'yes\n' | php glueful migrate:rollback --steps=1 && printf 'yes\n' | php glueful migrate:run
composer test:reset-db && composer test:migrate
DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Content/SeedBlockTypesTest.php
```
Expected: PASS.

- [ ] **Step 8 — PHASE 2 CHECKPOINT (hold for user):** dark CSS + runtime + toggle block complete and green. Hold; when cleared, batch-commit Tasks 3–5.

---

## Task 6: Docs

**Files:**
- Modify: `packages/thallo-render/docs/THEMING.md`

- [ ] **Step 1: Add a Color mode section**

Add a section covering: the `html[data-theme]` contract; the dark token re-map (and that per-site re-skin still flows through tokens); the disable config (`THALLO_COLOR_MODE_ENABLED` / `config/theme.php`); the toggle block; and **CSP** — paste the published `ColorMode::RESOLVER_SHA256` value and instruct strict-CSP operators to add `script-src 'sha256-<value>'`. Note the resolver is the only inline script and that HTML stays mode-agnostic (cache-safe).

- [ ] **Step 2: Full suite**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit`
Expected: PASS (1393+ tests; new color-mode + dark-token tests green).

- [ ] **Step 3 — FINAL CHECKPOINT (hold for user):** everything green. Hold; when cleared, batch-commit Task 6 (docs) with the phase-2 group or as its own commit, per the user's call.

---

## Self-review notes

- **Spec coverage:** resolver+hash (T1), Twig/layout emit + marker + mode-agnostic (T2), dark tokens (T3), global hard-gated runtime (T4), toggle block + config gating (T5), CSP docs/hash (T6). Disable path asserted in T2 (no marker/script), T5 (block renders nothing), and — **executably** — T4's `ColorModeRuntimeTest` (no marker → no localStorage read, no data-theme write; marker+system → matchMedia + OS change drive data-theme). No longer manual-QA-only.
- **Hash drift:** T1 asserts `hash(RESOLVER_JS) === RESOLVER_SHA256`; T2 asserts the rendered layout contains `ColorMode::scriptTag()` byte-for-byte — the two-assertion guard from the spec.
- **Types:** `color_mode_enabled(): bool`, `color_mode_script(): \Twig\Markup`, `ColorMode::scriptTag(): string` — consistent across tasks.
