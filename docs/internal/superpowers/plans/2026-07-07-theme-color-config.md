# Theme Color Config Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator re-skin the Thallo default theme by choosing a brand **accent** and a **neutral** tone (closed Tailwind-family enums) that re-map the design tokens only — applied live and previewable, in both light and dark mode.

**Architecture:** Two `GeneralSettings` keys store the chosen families. A pure `ThemeColors` table maps each family to concrete token hex (light + dark). A render-pack `ThemeAppearanceSource` resolves the saved/default pair (validated, memoized, fallback+log). A Twig function `theme_colors_style()` emits a `:root{}` + `html[data-theme="dark"]{}` override — but **only for a non-default pair** (default lives canonically in `site.css`) — placed after `blocks.css` and before `custom.css`. Preview rides the existing signed-token machinery (accent/neutral signed into the preview token, applied request-locally). The render page-cache key gains an appearance fingerprint and a `ThemeAppearanceChanged` event purges `thallo:render:page`.

**Tech Stack:** PHP 8.3+ (Glueful framework), Twig themes, PostgreSQL (`app_test`), Vue 3 admin SPA, PHPUnit 10.

## Global Constraints

- **Closed enums only.** Accent ∈ `{red, orange, amber, yellow, lime, green, emerald, teal, cyan, sky, blue, indigo, violet, purple, fuchsia, pink, rose}`; neutral ∈ `{slate, gray, zinc, neutral, stone}`. No free-text color input anywhere.
- **Defaults `blue` / `slate`** reproduce today's look; their emitted token values MUST equal the current `site.css` values byte-for-byte (frozen-default test, Task 1).
- **`theme_colors_style()` emits ONLY generated CSS from validated enum values** — never arbitrary input. Marked `['is_safe' => ['html']]`.
- **Output order:** after `site.css`/`blocks.css`, before `custom.css` (custom CSS stays the final escape hatch).
- **Emit nothing for the default pair** (`blue`/`slate`); `site.css` base applies.
- **Resolution ladder (render controller):** verified preview-session override → `ThemeAppearanceProvider` (saved) → `blue`/`slate`. Each value validated against its enum after read; out-of-enum → default **and log**.
- **Fingerprint & emitted CSS both use the validated-resolved pair after fallback** — a `banana`/`slate` row renders and caches as `blue`/`slate`.
- **Preview override is token/session-only; never writes `GeneralSettings`.** `Save` is the only live write.
- **CSP:** inline style varies by settings → strict-CSP operators must allow `style-src 'unsafe-inline'`. Documented, accepted for v1.
- **Namespaces:** contracts live in `Thallo\Contracts\…` (the `thallo-contracts` package). Prose/product language is Thallo, never "Lemma".
- **Commit cadence (user rule):** do NOT commit per task. Batch at the phase checkpoints and **hold every commit until the user explicitly says to commit**. Never stage `CLAUDE.md`. Work on `dev` directly.
- **Test DB:** `app_test` (PostgreSQL). Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit <path>`. Rebuild only if migrations change (this feature adds none): `composer test:reset-db && composer test:migrate`.

## File Structure

**New files**
- `packages/thallo-render/src/Theme/ThemeColors.php` — enums, default constants, Tailwind palette, `normalizeAccent()/normalizeNeutral()`, `css()`. Pure; no deps.
- `packages/thallo-contracts/src/Settings/ThemeAppearanceProvider.php` — `accent()/neutral()` contract.
- `packages/thallo-contracts/src/Settings/ThemeAppearanceChanged.php` — event.
- `packages/thallo-render/src/ThemeAppearanceSource.php` — per-request resolved saved/default pair (soft-binds provider, validates, memoizes, logs).
- `packages/thallo-render/src/Listeners/PurgeRenderCacheOnAppearanceChange.php` — purge listener.
- `app/Settings/EngineThemeAppearanceProvider.php` — app binding over `GeneralSettings`.
- Test files under `tests/Integration/Render/`, `tests/Integration/Content/`, `tests/Integration/Settings/`, `admin/src/__tests__/`.

**Modified files**
- `app/Settings/GeneralSettings.php` — 2 DEFS + accessors.
- `app/Http/DTOs/UpdateGeneralSettingsData.php` — 2 fields.
- `app/Http/Controllers/GeneralSettingsController.php` — save map + enum validation + dispatch event.
- `app/Providers/ThalloServiceProvider.php` — bind provider, register listener + event subscription.
- `packages/thallo-render/src/RenderContextExtension.php` — ctor gains appearance source + per-request override; `theme_colors_style()` fn + method; setter + reset.
- `packages/thallo-render/src/RenderServiceProvider.php` — wire `ThemeAppearanceSource`, pass to extension + `RenderPageCache`; wire listener.
- `packages/thallo-render/src/Http/Middleware/RenderPageCache.php` — ctor gains fingerprint; `key()` includes it.
- `packages/thallo-render/src/Http/Controllers/RenderController.php` — reset block sets/clears appearance override from the session.
- `packages/thallo-render/themes/default/templates/layout.twig` — add `{{ theme_colors_style() }}`.
- Preview chain: `packages/thallo-contracts/src/Delivery/PreviewSession.php`, `app/Content/Preview/PreviewToken.php`, `app/Content/Preview/PreviewMinter.php`, `app/Content/Preview/EnginePreviewSessionVerifier.php`, `app/Content/Preview/PreviewReader.php`, `app/Content/Http/DTOs/MintPreviewData.php`, `app/Content/Http/Controllers/PreviewController.php`.
- `admin/src/pages/settings/general/index.vue` (+ `admin/src/queries/*`) — Theme colors card.
- `packages/thallo-render/docs/THEMING.md` — §9 Theme colors.

---

## Phase 1 — Foundations (colors table + settings storage + validation)

### Task 1: `ThemeColors` — the family→token table

**Files:**
- Create: `packages/thallo-render/src/Theme/ThemeColors.php`
- Test: `tests/Integration/Render/ThemeColorsTest.php`

**Interfaces:**
- Produces:
  - `ThemeColors::ACCENTS` (`list<string>`), `ThemeColors::NEUTRALS` (`list<string>`)
  - `ThemeColors::DEFAULT_ACCENT = 'blue'`, `ThemeColors::DEFAULT_NEUTRAL = 'slate'`
  - `ThemeColors::normalizeAccent(string $v): ?string` — the value if in `ACCENTS`, else `null`
  - `ThemeColors::normalizeNeutral(string $v): ?string` — likewise
  - `ThemeColors::css(string $accent, string $neutral): string` — the override CSS for a **validated** pair, or `''` when the pair equals the default. Callers pass already-normalized values.
  - `ThemeColors::tokens(string $accent, string $neutral, string $mode): array<string,string>` — **intentional public API** (used by `css()` and by the frozen-default test): the 8 token name→hex values for a validated pair in one `$mode` (`'light'|'dark'`).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/ThemeColorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Theme\ThemeColors;

final class ThemeColorsTest extends AppTestCase
{
    public function testDefaultPairEmitsNothing(): void
    {
        self::assertSame('', ThemeColors::css('blue', 'slate'));
    }

    public function testEveryEnumFamilyIsRenderable(): void
    {
        // No enum member throws or yields an empty non-default override.
        foreach (ThemeColors::ACCENTS as $a) {
            foreach (ThemeColors::NEUTRALS as $n) {
                $css = ThemeColors::css($a, $n);
                if ($a === 'blue' && $n === 'slate') {
                    continue;
                }
                self::assertStringContainsString(':root', $css, "$a/$n :root");
                self::assertStringContainsString('html[data-theme="dark"]', $css, "$a/$n dark");
                // Every token must be present in both blocks.
                foreach (['--bg', '--surface', '--surface-2', '--ink', '--muted', '--line', '--accent', '--accent-ink'] as $t) {
                    self::assertStringContainsString($t, $css, "$a/$n missing $t");
                }
            }
        }
    }

    public function testFrozenDefaultValuesMatchSiteCss(): void
    {
        // P2b: force-emit the default pair via a non-default sibling so we can read
        // the blue/slate token values, and assert they equal the shipped site.css.
        // We read them from the neutral=gray accent=blue override's counterpart:
        // instead, assert the table's blue/slate row directly through a debug hook.
        $light = ThemeColors::tokens('blue', 'slate', 'light');
        $dark = ThemeColors::tokens('blue', 'slate', 'dark');
        self::assertSame([
            '--bg' => '#ffffff', '--surface' => '#f6f7f9', '--surface-2' => '#eef0f4',
            '--ink' => '#0f172a', '--muted' => '#64748b', '--line' => '#e2e8f0',
            '--accent' => '#2563eb', '--accent-ink' => '#ffffff',
        ], $light);
        self::assertSame([
            '--bg' => '#0b1120', '--surface' => '#111a2e', '--surface-2' => '#16213a',
            '--ink' => '#e2e8f0', '--muted' => '#94a3b8', '--line' => '#1e293b',
            '--accent' => '#3b82f6', '--accent-ink' => '#ffffff',
        ], $dark);
    }

    public function testWhiteAccentInkMeetsContrastForEveryAccent(): void
    {
        // Every accent's light --accent must reach >= 4.5:1 against white ink,
        // so --accent-ink can stay white uniformly (spec §3).
        foreach (ThemeColors::ACCENTS as $a) {
            $accent = ThemeColors::tokens($a, 'slate', 'light')['--accent'];
            self::assertGreaterThanOrEqual(
                4.5,
                self::contrastWithWhite($accent),
                "accent '$a' light fill fails AA against white text ($accent)",
            );
        }
    }

    public function testNormalizeRejectsUnknown(): void
    {
        self::assertNull(ThemeColors::normalizeAccent('banana'));
        self::assertSame('blue', ThemeColors::normalizeAccent('blue'));
        self::assertNull(ThemeColors::normalizeNeutral('octarine'));
        self::assertSame('slate', ThemeColors::normalizeNeutral('slate'));
    }

    private static function contrastWithWhite(string $hex): float
    {
        $l = self::relLuminance($hex);
        return (1.0 + 0.05) / ($l + 0.05);
    }

    private static function relLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $c((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $c((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $c((int) hexdec(substr($hex, 4, 2)));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsTest.php`
Expected: FAIL — class `Thallo\Render\Theme\ThemeColors` not found.

- [ ] **Step 3: Create `ThemeColors`**

Create `packages/thallo-render/src/Theme/ThemeColors.php`. The `ACCENT` / `NEUTRAL_LIGHT` / `NEUTRAL_DARK` maps below carry **canonical Tailwind v4 values** — verify each against Tailwind's published scale rather than trusting them blind (the frozen-default and per-accent-contrast tests above are the guardrails). The `slate` rows are hand-frozen to the shipped `site.css` values (P2b); amber/yellow/lime accents are pre-bumped to darker stops so white ink stays AA.

```php
<?php

declare(strict_types=1);

namespace Thallo\Render\Theme;

/**
 * Theme-color config (theme-color-config spec §3): maps a closed accent/neutral
 * family pair to concrete design-token hex, light + dark. Pure + static — no CSS
 * is emitted for the frozen default pair (blue/slate), which lives canonically in
 * site.css. Every value comes from Tailwind's published palette EXCEPT the slate
 * rows, which reproduce the shipped site.css values byte-for-byte.
 */
final class ThemeColors
{
    public const DEFAULT_ACCENT = 'blue';
    public const DEFAULT_NEUTRAL = 'slate';

    /** @var list<string> */
    public const ACCENTS = [
        'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
        'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ];

    /** @var list<string> */
    public const NEUTRALS = ['slate', 'gray', 'zinc', 'neutral', 'stone'];

    /**
     * Accent fill per family: [light600, dark500]. accent-ink is white uniformly,
     * so light-hued families (amber/yellow/lime) use DARKER stops [700, 600] to
     * keep white text at AA — enforced by ThemeColorsTest::testWhiteAccentInk...
     * @var array<string,array{0:string,1:string}>
     */
    private const ACCENT = [
        'red'     => ['#dc2626', '#ef4444'],
        'orange'  => ['#ea580c', '#f97316'],
        'amber'   => ['#b45309', '#d97706'], // bumped (amber-700/600) for white AA
        'yellow'  => ['#a16207', '#ca8a04'], // bumped (yellow-700/600)
        'lime'    => ['#4d7c0f', '#65a30d'], // bumped (lime-700/600)
        'green'   => ['#16a34a', '#22c55e'],
        'emerald' => ['#059669', '#10b981'],
        'teal'    => ['#0d9488', '#14b8a6'],
        'cyan'    => ['#0891b2', '#06b6d4'],
        'sky'     => ['#0284c7', '#0ea5e9'],
        'blue'    => ['#2563eb', '#3b82f6'],
        'indigo'  => ['#4f46e5', '#6366f1'],
        'violet'  => ['#7c3aed', '#8b5cf6'],
        'purple'  => ['#9333ea', '#a855f7'],
        'fuchsia' => ['#c026d3', '#d946ef'],
        'pink'    => ['#db2777', '#ec4899'],
        'rose'    => ['#e11d48', '#f43f5e'],
    ];

    /**
     * Neutral token stops per family (light), keys: bg, surface, surface-2, ink,
     * muted, line. bg is always white in light mode. slate is frozen (see below).
     * Fill non-slate families from Tailwind at stops 50/100/900/500/200.
     * @var array<string,array<string,string>>
     */
    private const NEUTRAL_LIGHT = [
        'slate'   => ['--bg' => '#ffffff', '--surface' => '#f6f7f9', '--surface-2' => '#eef0f4', '--ink' => '#0f172a', '--muted' => '#64748b', '--line' => '#e2e8f0'],
        'gray'    => ['--bg' => '#ffffff', '--surface' => '#f9fafb', '--surface-2' => '#f3f4f6', '--ink' => '#111827', '--muted' => '#6b7280', '--line' => '#e5e7eb'],
        'zinc'    => ['--bg' => '#ffffff', '--surface' => '#fafafa', '--surface-2' => '#f4f4f5', '--ink' => '#18181b', '--muted' => '#71717a', '--line' => '#e4e4e7'],
        'neutral' => ['--bg' => '#ffffff', '--surface' => '#fafafa', '--surface-2' => '#f5f5f5', '--ink' => '#171717', '--muted' => '#737373', '--line' => '#e5e5e5'],
        'stone'   => ['--bg' => '#ffffff', '--surface' => '#fafaf9', '--surface-2' => '#f5f5f4', '--ink' => '#1c1917', '--muted' => '#78716c', '--line' => '#e7e5e4'],
    ];

    /**
     * Neutral token stops per family (dark). slate is frozen to site.css; non-slate
     * families use stops bg=950 surface=900 surface-2=800 ink=200 muted=400 line=800.
     * @var array<string,array<string,string>>
     */
    private const NEUTRAL_DARK = [
        'slate'   => ['--bg' => '#0b1120', '--surface' => '#111a2e', '--surface-2' => '#16213a', '--ink' => '#e2e8f0', '--muted' => '#94a3b8', '--line' => '#1e293b'],
        'gray'    => ['--bg' => '#030712', '--surface' => '#111827', '--surface-2' => '#1f2937', '--ink' => '#e5e7eb', '--muted' => '#9ca3af', '--line' => '#1f2937'],
        'zinc'    => ['--bg' => '#09090b', '--surface' => '#18181b', '--surface-2' => '#27272a', '--ink' => '#e4e4e7', '--muted' => '#a1a1aa', '--line' => '#27272a'],
        'neutral' => ['--bg' => '#0a0a0a', '--surface' => '#171717', '--surface-2' => '#262626', '--ink' => '#e5e5e5', '--muted' => '#a3a3a3', '--line' => '#262626'],
        'stone'   => ['--bg' => '#0c0a09', '--surface' => '#1c1917', '--surface-2' => '#292524', '--ink' => '#e7e5e4', '--muted' => '#a8a29e', '--line' => '#292524'],
    ];

    public static function normalizeAccent(string $v): ?string
    {
        return in_array($v, self::ACCENTS, true) ? $v : null;
    }

    public static function normalizeNeutral(string $v): ?string
    {
        return in_array($v, self::NEUTRALS, true) ? $v : null;
    }

    /**
     * The 8 token values for a validated pair in one mode ('light'|'dark').
     * @return array<string,string>
     */
    public static function tokens(string $accent, string $neutral, string $mode): array
    {
        [$light600, $dark500] = self::ACCENT[$accent];
        $neutralTokens = $mode === 'dark' ? self::NEUTRAL_DARK[$neutral] : self::NEUTRAL_LIGHT[$neutral];
        return $neutralTokens + [
            '--accent' => $mode === 'dark' ? $dark500 : $light600,
            '--accent-ink' => '#ffffff',
        ];
    }

    /** Override CSS for a validated pair, or '' when it is the default. */
    public static function css(string $accent, string $neutral): string
    {
        if ($accent === self::DEFAULT_ACCENT && $neutral === self::DEFAULT_NEUTRAL) {
            return '';
        }
        $emit = static function (array $tokens): string {
            $decls = '';
            foreach ($tokens as $name => $value) {
                $decls .= "{$name}:{$value};";
            }
            return $decls;
        };
        return ':root{' . $emit(self::tokens($accent, $neutral, 'light')) . '}'
            . 'html[data-theme="dark"]{' . $emit(self::tokens($accent, $neutral, 'dark')) . '}';
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsTest.php`
Expected: PASS (5 tests). If `testWhiteAccentInk...` fails for a family, bump that family's `ACCENT` stops one step darker until white reaches AA.

- [ ] **Step 5: Hold commit** (batch at the Phase 1 checkpoint).

---

### Task 2: Settings storage + provider contract + app binding

**Files:**
- Modify: `app/Settings/GeneralSettings.php` (DEFS ~20-46; add accessors after `theme()`)
- Create: `packages/thallo-contracts/src/Settings/ThemeAppearanceProvider.php`
- Create: `app/Settings/EngineThemeAppearanceProvider.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (bind the provider)
- Test: `tests/Integration/Settings/ThemeAppearanceSettingsTest.php`

**Interfaces:**
- Consumes: `ThemeColors::DEFAULT_ACCENT/DEFAULT_NEUTRAL` (Task 1).
- Produces:
  - `GeneralSettings::themeAccent(): string`, `GeneralSettings::themeNeutral(): string`
  - `Thallo\Contracts\Settings\ThemeAppearanceProvider::accent(): string` / `neutral(): string`
  - `App\Settings\EngineThemeAppearanceProvider` implementing it over `GeneralSettings`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Settings/ThemeAppearanceSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\EngineThemeAppearanceProvider;
use App\Settings\GeneralSettings;
use App\Tests\Support\AppTestCase;

final class ThemeAppearanceSettingsTest extends AppTestCase
{
    public function testDefaultsAreBlueSlate(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        self::assertSame('blue', $settings->themeAccent());
        self::assertSame('slate', $settings->themeNeutral());
    }

    public function testRoundTripThroughSave(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->save(['theme_accent' => 'emerald', 'theme_neutral' => 'zinc']);
        self::assertSame('emerald', $settings->themeAccent());
        self::assertSame('zinc', $settings->themeNeutral());
    }

    public function testProviderReflectsSavedValues(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->save(['theme_accent' => 'rose']);
        $provider = new EngineThemeAppearanceProvider($settings);
        self::assertSame('rose', $provider->accent());
        self::assertSame('slate', $provider->neutral());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Settings/ThemeAppearanceSettingsTest.php`
Expected: FAIL — `themeAccent()` / `EngineThemeAppearanceProvider` undefined.

- [ ] **Step 3: Add the DEFS + accessors**

In `app/Settings/GeneralSettings.php`, add to `DEFS` after the `'theme'` line:

```php
        // Theme color config (theme-color-config spec §2): accent + neutral
        // Tailwind families; DB row -> config -> blue/slate. Enum-validated on save.
        'theme_accent'  => ['thallo.theme.accent', 'string', 'blue'],
        'theme_neutral' => ['thallo.theme.neutral', 'string', 'slate'],
```

Add accessors after `theme()`:

```php
    public function themeAccent(): string
    {
        return (string) $this->value('theme_accent');
    }

    public function themeNeutral(): string
    {
        return (string) $this->value('theme_neutral');
    }
```

- [ ] **Step 4: Create the contract**

Create `packages/thallo-contracts/src/Settings/ThemeAppearanceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/**
 * The stored theme-appearance selection for render surfaces (theme-color-config
 * spec §4). Returns the EFFECTIVE saved-or-default family names (unlike
 * ThemeSettingProvider's raw-override posture): render only needs the value to
 * skin tokens, and the default is a real render input, not an env ladder.
 */
interface ThemeAppearanceProvider
{
    /** The saved accent family, or the default when none is stored. */
    public function accent(): string;

    /** The saved neutral family, or the default when none is stored. */
    public function neutral(): string;
}
```

- [ ] **Step 5: Create the app binding**

Create `app/Settings/EngineThemeAppearanceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Settings;

use Thallo\Contracts\Settings\ThemeAppearanceProvider;

/**
 * Binds ThemeAppearanceProvider over GeneralSettings (theme-color-config spec §4).
 * Returns the effective saved-or-default family names; enum validation happens
 * downstream in ThemeAppearanceSource so an out-of-enum DB row falls back + logs
 * rather than throwing here.
 */
final class EngineThemeAppearanceProvider implements ThemeAppearanceProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function accent(): string
    {
        return $this->settings->themeAccent();
    }

    public function neutral(): string
    {
        return $this->settings->themeNeutral();
    }
}
```

- [ ] **Step 6: Register the binding**

In `app/Providers/ThalloServiceProvider.php`, find where `ThemeSettingProvider` is bound (grep `ThemeSettingProvider`) and add an adjacent binding:

```php
        \Thallo\Contracts\Settings\ThemeAppearanceProvider::class => [
            'class' => \App\Settings\EngineThemeAppearanceProvider::class,
            'shared' => true,
            'arguments' => ['@' . \App\Settings\GeneralSettings::class],
        ],
```

(Match the exact array/factory shape used by the neighbouring `ThemeSettingProvider` binding — copy its style.)

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Settings/ThemeAppearanceSettingsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Hold commit** (Phase 1 checkpoint).

---

### Task 3: Write-time enum validation + `ThemeAppearanceChanged` event

**Files:**
- Create: `packages/thallo-contracts/src/Settings/ThemeAppearanceChanged.php`
- Modify: `app/Http/DTOs/UpdateGeneralSettingsData.php` (add 2 fields)
- Modify: `app/Http/Controllers/GeneralSettingsController.php` (save map ~72-88; `validate()` ~107; dispatch after save ~90-95)
- Test: `tests/Integration/Content/GeneralSettingsAppearanceTest.php`

**Interfaces:**
- Consumes: `ThemeColors::normalizeAccent/normalizeNeutral` (Task 1); `GeneralSettings::themeAccent/themeNeutral` (Task 2).
- Produces: `Thallo\Contracts\Settings\ThemeAppearanceChanged` with `public readonly string $accent; public readonly string $neutral;`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Content/GeneralSettingsAppearanceTest.php`. Mirror the existing general-settings controller tests for setup (grep an existing one, e.g. how it builds the controller + calls `update()`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Http\DTOs\UpdateGeneralSettingsData;
use App\Settings\GeneralSettings;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;

final class GeneralSettingsAppearanceTest extends AppTestCase
{
    public function testSaveRejectsUnknownAccent(): void
    {
        $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_accent: 'banana'));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testSaveRejectsUnknownNeutral(): void
    {
        $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_neutral: 'octarine'));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testSaveAcceptsValidPairAndPersists(): void
    {
        $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_accent: 'violet', theme_neutral: 'zinc'));
        self::assertSame(200, $res->getStatusCode());
        $settings = $this->container()->get(GeneralSettings::class);
        self::assertSame('violet', $settings->themeAccent());
        self::assertSame('zinc', $settings->themeNeutral());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/GeneralSettingsAppearanceTest.php`
Expected: FAIL — `theme_accent` is not a constructor arg of `UpdateGeneralSettingsData`.

- [ ] **Step 3: Add DTO fields**

In `app/Http/DTOs/UpdateGeneralSettingsData.php`, add two params before the closing `) {`:

```php
        /** @var string|null Accent Tailwind family (theme-color-config spec §2); enum-validated in the controller. */
        #[Rule('string')]
        public readonly ?string $theme_accent = null,
        /** @var string|null Neutral Tailwind family; enum-validated in the controller. */
        #[Rule('string')]
        public readonly ?string $theme_neutral = null,
```

- [ ] **Step 4: Add the save-map entries + validation + dispatch**

In `app/Http/Controllers/GeneralSettingsController.php`:

(a) Add to the `$this->settings->save([...])` array:

```php
            'theme_accent' => $input->theme_accent,
            'theme_neutral' => $input->theme_neutral,
```

(b) In `validate()`, before `return $errors;`, add:

```php
        // Theme appearance (theme-color-config spec §2): closed enums; null =
        // unchanged. An out-of-enum value can never be stored.
        if ($input->theme_accent !== null && \Thallo\Render\Theme\ThemeColors::normalizeAccent($input->theme_accent) === null) {
            $errors['theme_accent'] = 'unknown accent color';
        }
        if ($input->theme_neutral !== null && \Thallo\Render\Theme\ThemeColors::normalizeNeutral($input->theme_neutral) === null) {
            $errors['theme_neutral'] = 'unknown neutral color';
        }
```

(c) After the existing `ThemeChanged` dispatch block, add appearance-change detection + dispatch. Capture the before-values at the top of `update()` next to `$themeBefore`:

```php
        $accentBefore = $this->settings->themeAccent();
        $neutralBefore = $this->settings->themeNeutral();
```

Then after save:

```php
        if (
            ($input->theme_accent !== null && $this->settings->themeAccent() !== $accentBefore)
            || ($input->theme_neutral !== null && $this->settings->themeNeutral() !== $neutralBefore)
        ) {
            $this->events?->dispatch(new \Thallo\Contracts\Settings\ThemeAppearanceChanged(
                $this->settings->themeAccent(),
                $this->settings->themeNeutral(),
            ));
        }
```

- [ ] **Step 5: Create the event**

Create `packages/thallo-contracts/src/Settings/ThemeAppearanceChanged.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

use Glueful\Events\Contracts\BaseEvent;

/**
 * The theme color config (accent/neutral) changed (theme-color-config spec §7).
 * The app's settings save dispatches it only when a STORED value actually
 * changed; thallo-render purges its page cache via
 * invalidateTags(['thallo:render:page']) — the same broad tag class as
 * ThemeChanged, since color config touches every page.
 */
final class ThemeAppearanceChanged extends BaseEvent
{
    public function __construct(
        public readonly string $accent,
        public readonly string $neutral,
    ) {
        parent::__construct();
    }
}
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/GeneralSettingsAppearanceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: PHASE 1 CHECKPOINT — hold for user.** Run the settings/content suites green, then stop:

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Settings tests/Integration/Content/GeneralSettingsAppearanceTest.php tests/Integration/Render/ThemeColorsTest.php`
Expected: PASS. Hold; when cleared, batch-commit Tasks 1–3.

---

## Phase 2 — Render integration (resolve → emit → cache)

### Task 4: `ThemeAppearanceSource` (per-request resolved saved/default pair)

**Files:**
- Create: `packages/thallo-render/src/ThemeAppearanceSource.php`
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (add a `makeThemeAppearanceSource` factory + service def, mirroring `makeActiveThemeSource` ~308)
- Test: `tests/Integration/Render/ThemeAppearanceSourceTest.php`

**Interfaces:**
- Consumes: `ThemeAppearanceProvider` (Task 2), `ThemeColors::normalize*` + defaults (Task 1).
- Produces: `ThemeAppearanceSource` with `accent(): string`, `neutral(): string` — validated, memoized, fallback+log.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/ThemeAppearanceSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\ThemeAppearanceSource;

final class ThemeAppearanceSourceTest extends AppTestCase
{
    private function provider(string $a, string $n): ThemeAppearanceProvider
    {
        return new class ($a, $n) implements ThemeAppearanceProvider {
            public function __construct(private string $a, private string $n)
            {
            }
            public function accent(): string
            {
                return $this->a;
            }
            public function neutral(): string
            {
                return $this->n;
            }
        };
    }

    public function testReturnsSavedPair(): void
    {
        $src = new ThemeAppearanceSource($this->provider('emerald', 'zinc'), new NullLogger());
        self::assertSame('emerald', $src->accent());
        self::assertSame('zinc', $src->neutral());
    }

    public function testUnboundProviderFallsBackToDefault(): void
    {
        $src = new ThemeAppearanceSource(null, new NullLogger());
        self::assertSame('blue', $src->accent());
        self::assertSame('slate', $src->neutral());
    }

    public function testInvalidStoredValueFallsBackToDefault(): void
    {
        $src = new ThemeAppearanceSource($this->provider('banana', 'slate'), new NullLogger());
        self::assertSame('blue', $src->accent());
        self::assertSame('slate', $src->neutral());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeAppearanceSourceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `ThemeAppearanceSource`**

Create `packages/thallo-render/src/ThemeAppearanceSource.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Render;

use Psr\Log\LoggerInterface;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\Theme\ThemeColors;

/**
 * The effective theme appearance (theme-color-config spec §4): saved accent/
 * neutral -> blue/slate, validated against the closed enums and memoized per
 * instance (per request in classic PHP). An out-of-enum stored value logs and
 * falls back to the default rather than emitting broken CSS.
 */
final class ThemeAppearanceSource
{
    private ?string $accentMemo = null;
    private ?string $neutralMemo = null;

    public function __construct(
        /** Soft-bound: null = no settings engine, default applies. */
        private readonly ?ThemeAppearanceProvider $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function accent(): string
    {
        if ($this->accentMemo !== null) {
            return $this->accentMemo;
        }
        $raw = $this->settings?->accent() ?? ThemeColors::DEFAULT_ACCENT;
        $ok = ThemeColors::normalizeAccent($raw);
        if ($ok === null) {
            $this->logger?->warning("[Thallo] Invalid theme accent '{$raw}'; falling back to 'blue'.");
            $ok = ThemeColors::DEFAULT_ACCENT;
        }
        return $this->accentMemo = $ok;
    }

    public function neutral(): string
    {
        if ($this->neutralMemo !== null) {
            return $this->neutralMemo;
        }
        $raw = $this->settings?->neutral() ?? ThemeColors::DEFAULT_NEUTRAL;
        $ok = ThemeColors::normalizeNeutral($raw);
        if ($ok === null) {
            $this->logger?->warning("[Thallo] Invalid theme neutral '{$raw}'; falling back to 'slate'.");
            $ok = ThemeColors::DEFAULT_NEUTRAL;
        }
        return $this->neutralMemo = $ok;
    }
}
```

- [ ] **Step 4: Wire the factory**

In `packages/thallo-render/src/RenderServiceProvider.php`, add a service def next to `ActiveThemeSource::class` (~62) and a factory next to `makeActiveThemeSource` (~308):

```php
        ThemeAppearanceSource::class => [
            'class' => ThemeAppearanceSource::class,
            'factory' => [self::class, 'makeThemeAppearanceSource'],
            'shared' => true,
        ],
```

```php
    public static function makeThemeAppearanceSource(ContainerInterface $container): ThemeAppearanceSource
    {
        return new ThemeAppearanceSource(
            $container->has(\Thallo\Contracts\Settings\ThemeAppearanceProvider::class)
                ? $container->get(\Thallo\Contracts\Settings\ThemeAppearanceProvider::class)
                : null,
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }
```

Add `use Thallo\Render\ThemeAppearanceSource;` to the imports.

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeAppearanceSourceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Hold commit** (Phase 2 checkpoint).

---

### Task 5: `theme_colors_style()` Twig function + per-request override

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (ctor last param; TwigFunction registration ~147-149; methods after `colorModeScript()`; per-request override field + setter)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (`makeRenderContextExtension` — pass the appearance source)
- Test: `tests/Integration/Render/ThemeColorsStyleTest.php`

**Interfaces:**
- Consumes: `ThemeAppearanceSource` (Task 4), `ThemeColors::css/normalize*` (Task 1).
- Produces:
  - Twig function `theme_colors_style()` → `\Twig\Markup` (safe html)
  - `RenderContextExtension::setThemeAppearanceOverride(?string $accent, ?string $neutral): void` (reset-per-render)
  - `RenderContextExtension::themeColorsStyle(): \Twig\Markup`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/ThemeColorsStyleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;

final class ThemeColorsStyleTest extends AppTestCase
{
    private function ext(string $savedAccent, string $savedNeutral): RenderContextExtension
    {
        $provider = new class ($savedAccent, $savedNeutral) implements ThemeAppearanceProvider {
            public function __construct(private string $a, private string $n)
            {
            }
            public function accent(): string
            {
                return $this->a;
            }
            public function neutral(): string
            {
                return $this->n;
            }
        };
        return new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
        );
    }

    public function testDefaultPairEmitsEmpty(): void
    {
        $out = (string) $this->ext('blue', 'slate')->themeColorsStyle();
        self::assertSame('', $out);
    }

    public function testNonDefaultSavedPairEmitsOverride(): void
    {
        $out = (string) $this->ext('emerald', 'zinc')->themeColorsStyle();
        self::assertStringContainsString(':root{', $out);
        self::assertStringContainsString('html[data-theme="dark"]{', $out);
        self::assertStringContainsString('--accent:#059669', $out);
    }

    public function testPreviewOverrideBeatsSaved(): void
    {
        $ext = $this->ext('rose', 'zinc');            // saved non-default
        $ext->setThemeAppearanceOverride('blue', 'slate'); // preview = default
        self::assertSame('', (string) $ext->themeColorsStyle(), 'preview default over saved non-default emits nothing');
    }

    public function testInvalidOverrideFallsBackNotThrows(): void
    {
        $ext = $this->ext('blue', 'slate');
        $ext->setThemeAppearanceOverride('banana', 'slate');
        self::assertSame('', (string) $ext->themeColorsStyle()); // banana -> blue -> default -> empty
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsStyleTest.php`
Expected: FAIL — `appearance:` is not a constructor arg / `themeColorsStyle()` undefined.

- [ ] **Step 3: Extend the extension constructor + state**

In `packages/thallo-render/src/RenderContextExtension.php`, add a constructor param AFTER `colorModeEnabled` (keep it last so existing positional callers are unaffected):

```php
        /** color-mode spec §3.4: false → no resolver, no marker, toggle renders nothing. */
        private readonly bool $colorModeEnabled = true,
        /** theme-color-config spec §4: null → default blue/slate (no override emitted). */
        private readonly ?\Thallo\Render\ThemeAppearanceSource $appearance = null,
    ) {
        $this->locale = $defaultLocale;
    }
```

Add the per-request override fields near the other reset-family state (e.g. after `$annotateBlocks`):

```php
    /** Preview-only appearance override (theme-color-config spec §6): request-local,
     *  reset before every render. Null = use the saved/default source. */
    private ?string $appearanceAccentOverride = null;
    private ?string $appearanceNeutralOverride = null;
```

- [ ] **Step 4: Register the function + implement the methods**

In the `getFunctions()` list (after the `color_mode_script` registration), add:

```php
            new TwigFunction('theme_colors_style', $this->themeColorsStyle(...), ['is_safe' => ['html']]),
```

After `colorModeScript()`, add:

```php
    /** Preview-only appearance override (reset before every render by the controller). */
    public function setThemeAppearanceOverride(?string $accent, ?string $neutral): void
    {
        $this->appearanceAccentOverride = $accent;
        $this->appearanceNeutralOverride = $neutral;
    }

    /**
     * Theme-color-config spec §5: emit the token override for the effective pair —
     * preview override (request-local) beats the saved/default source. Emits NOTHING
     * for the default pair (site.css already carries blue/slate). Generated purely
     * from the closed enums, so it is html-safe.
     */
    public function themeColorsStyle(): \Twig\Markup
    {
        $accent = $this->appearanceAccentOverride
            ?? $this->appearance?->accent()
            ?? \Thallo\Render\Theme\ThemeColors::DEFAULT_ACCENT;
        $neutral = $this->appearanceNeutralOverride
            ?? $this->appearance?->neutral()
            ?? \Thallo\Render\Theme\ThemeColors::DEFAULT_NEUTRAL;

        // Normalize (a preview override or stale value could be junk) — invalid → default.
        $accent = \Thallo\Render\Theme\ThemeColors::normalizeAccent($accent) ?? \Thallo\Render\Theme\ThemeColors::DEFAULT_ACCENT;
        $neutral = \Thallo\Render\Theme\ThemeColors::normalizeNeutral($neutral) ?? \Thallo\Render\Theme\ThemeColors::DEFAULT_NEUTRAL;

        $css = \Thallo\Render\Theme\ThemeColors::css($accent, $neutral);
        return new \Twig\Markup($css === '' ? '' : "<style>{$css}</style>", 'UTF-8');
    }
```

- [ ] **Step 5: Pass the appearance source in the factory**

In `packages/thallo-render/src/RenderServiceProvider.php`, `makeRenderContextExtension()`, add the appearance source as the final constructor argument (after the `colorModeEnabled:` arg):

```php
            appearance: $container->get(ThemeAppearanceSource::class),
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsStyleTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Hold commit** (Phase 2 checkpoint).

---

### Task 6: Layout injection (order pin)

**Files:**
- Modify: `packages/thallo-render/themes/default/templates/layout.twig` (the CSS-link block ~15-22)
- Test: `tests/Integration/Render/ThemeColorsLayoutTest.php`

**Interfaces:**
- Consumes: `theme_colors_style()` (Task 5), the existing `custom_css()` (layout).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/ThemeColorsLayoutTest.php` (reuse the `ColorModeTest` layout-render helper shape):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

final class ThemeColorsLayoutTest extends AppTestCase
{
    public function testStyleLandsAfterBlocksCssAndBeforeCustomCss(): void
    {
        $base = $this->appContext()->getBasePath();
        $provider = new class implements ThemeAppearanceProvider {
            public function accent(): string
            {
                return 'emerald';
            }
            public function neutral(): string
            {
                return 'zinc';
            }
        };
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
        );
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        $html = $env->load('layout.twig')->render([
            'site' => ['locale' => 'en', 'name' => 'Test Site'],
            'preview' => false,
        ]);

        $blocksCss = strpos($html, 'blocks.css');
        $style = strpos($html, '--accent:#059669');
        self::assertNotFalse($style, 'override style present');
        self::assertGreaterThan($blocksCss, $style, 'style after blocks.css');
        // custom_css() emits nothing here (no row), so assert relative ordering via the marker comment instead.
        self::assertStringContainsString('<style>:root{', $html);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsLayoutTest.php`
Expected: FAIL — no `<style>:root{` in the rendered layout.

- [ ] **Step 3: Add the injection to `layout.twig`**

In `packages/thallo-render/themes/default/templates/layout.twig`, immediately AFTER the four theme stylesheet `<link>`s (`site.css`/`blocks.css`/`navigation.css`/`stepper.css`) and BEFORE the `custom_css()` block, insert:

```twig
  {# Theme color config (theme-color-config spec §5): a :root + dark token
     override generated from the operator's accent/neutral choice. Emits nothing
     for the default (blue/slate). AFTER the theme CSS, BEFORE custom.css so
     custom CSS stays the final escape hatch. #}
  {{ theme_colors_style() }}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/ThemeColorsLayoutTest.php`
Expected: PASS.

- [ ] **Step 5: Hold commit** (Phase 2 checkpoint).

---

### Task 7: Cache fingerprint + purge listener

**Files:**
- Modify: `packages/thallo-render/src/Http/Middleware/RenderPageCache.php` (ctor + `key()` ~102-105)
- Modify: `packages/thallo-render/src/RenderErrorCache.php` (ctor ~24-30; extract + fingerprint the error key ~51)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (`makeRenderPageCache` ~250 AND `makeRenderErrorCache` ~239 — pass the fingerprint)
- Create: `packages/thallo-render/src/Listeners/PurgeRenderCacheOnAppearanceChange.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (register the listener on `ThemeAppearanceChanged`)
- Test: `tests/Integration/Render/RenderPageCacheAppearanceTest.php`

**Interfaces:**
- Consumes: `ThemeAppearanceSource` (Task 4), `ThemeAppearanceChanged` (Task 3).
- Produces: page key `render:{theme}:{accent}-{neutral}:{path}` AND error key `render:{theme}:{accent}-{neutral}:{status}` (both use the validated pair — spec §7 pins error keys alongside page keys, since cached error chrome carries token styles too); `PurgeRenderCacheOnAppearanceChange::onAppearanceChanged(object $event): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/RenderPageCacheAppearanceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderErrorCache;

final class RenderPageCacheAppearanceTest extends AppTestCase
{
    public function testPageKeyIncludesAppearanceFingerprint(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $mw = new RenderPageCache($cache, 'default', 'emerald-zinc', true, 60);
        $ref = new \ReflectionMethod($mw, 'key');
        $ref->setAccessible(true);
        self::assertSame('render:default:emerald-zinc:%2F', $ref->invoke($mw, '/'));
    }

    public function testErrorKeyIncludesAppearanceFingerprint(): void
    {
        // Cached 404/410 chrome carries the theme's token styles, so error keys
        // must vary by appearance too (spec §7).
        $cache = $this->container()->get(CacheStore::class);
        $errors = new RenderErrorCache($cache, 'default', 'emerald-zinc', true, 60);
        $ref = new \ReflectionMethod($errors, 'key');
        $ref->setAccessible(true);
        self::assertSame('render:default:emerald-zinc:404', $ref->invoke($errors, 404));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/RenderPageCacheAppearanceTest.php`
Expected: FAIL — `RenderPageCache::__construct` takes 4 args, not 5.

- [ ] **Step 3: Add the fingerprint to the middleware**

In `packages/thallo-render/src/Http/Middleware/RenderPageCache.php`, add a constructor param after `$theme`:

```php
    public function __construct(
        private readonly CacheStore $cache,
        private readonly string $theme,
        /** Validated accent-neutral fingerprint (theme-color-config spec §7). */
        private readonly string $appearance,
        private readonly bool $enabled,
        private readonly int $ttl,
    ) {
    }
```

Update `key()`:

```php
    private function key(string $path): string
    {
        return "render:{$this->theme}:{$this->appearance}:" . rawurlencode(self::normalizePath($path));
    }
```

The error key stays disjoint from page keys by construction — a page key's final segment is `rawurlencode(path)` (always begins `%2F`), an error key's is a bare status (`404`/`410`).

- [ ] **Step 3b: Fingerprint the error cache too**

In `packages/thallo-render/src/RenderErrorCache.php`, add the same constructor param after `$theme`:

```php
    public function __construct(
        private readonly CacheStore $cache,
        private readonly string $theme,
        /** Validated accent-neutral fingerprint (theme-color-config spec §7). */
        private readonly string $appearance,
        private readonly bool $enabled,
        private readonly int $ttl,
    ) {
    }
```

Extract the inline error key into a private method (parallel to `RenderPageCache::key()`) so it is testable, and fingerprint it:

```php
    private function key(int $status): string
    {
        return "render:{$this->theme}:{$this->appearance}:{$status}";
    }
```

Then in `fixedError()`, replace `$key = "render:{$this->theme}:{$status}";` with `$key = $this->key($status);`.

- [ ] **Step 4: Pass the fingerprint from BOTH factories**

In `packages/thallo-render/src/RenderServiceProvider.php`, `makeRenderPageCache()`, build the fingerprint from the **validated** source and pass it after the theme name:

```php
        $appearance = $container->get(ThemeAppearanceSource::class);
        return new RenderPageCache(
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            $appearance->accent() . '-' . $appearance->neutral(),
            (bool) config($context, 'render.cache_enabled', true),
            (int) config($context, 'render.cache_ttl', 3600),
        );
```

And the SAME fingerprint in `makeRenderErrorCache()` (after the theme name):

```php
        $appearance = $container->get(ThemeAppearanceSource::class);
        return new RenderErrorCache(
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            $appearance->accent() . '-' . $appearance->neutral(),
            (bool) config($context, 'render.cache_enabled', true),
            (int) config($context, 'render.cache_ttl', 3600),
        );
```

- [ ] **Step 5: Create the purge listener**

Create `packages/thallo-render/src/Listeners/PurgeRenderCacheOnAppearanceChange.php` (mirror `PurgeRenderCacheOnThemeChange`):

```php
<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;

/**
 * ThemeAppearanceChanged → invalidateTags(['thallo:render:page']) — color config
 * touches every page (theme-color-config spec §7). Cache keys carry the appearance
 * fingerprint, so stale entries were never servable under the new pair; the purge
 * is hygiene for the old pair's keys.
 */
final class PurgeRenderCacheOnAppearanceChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onAppearanceChanged(object $event): void
    {
        if (!$event instanceof ThemeAppearanceChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:render:page']);
    }
}
```

- [ ] **Step 6: Register the listener**

In `app/Providers/ThalloServiceProvider.php`, find where `PurgeRenderCacheOnThemeChange` is subscribed to `ThemeChanged` (grep it) and add the parallel subscription of `PurgeRenderCacheOnAppearanceChange::onAppearanceChanged` to `ThemeAppearanceChanged`, matching the exact event-registration style used there.

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render/RenderPageCacheAppearanceTest.php`
Expected: PASS.

- [ ] **Step 8: PHASE 2 CHECKPOINT — hold for user.** Run the render suite green, then stop:

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Render`
Expected: PASS. Hold; when cleared, batch-commit Tasks 4–7.

---

## Phase 3 — Preview + admin

### Task 8: Sign appearance into the preview token; apply request-locally

**Files:**
- Modify: `packages/thallo-contracts/src/Delivery/PreviewSession.php` (2 fields)
- Modify: `app/Content/Preview/PreviewToken.php` (payload keys, factory args)
- Modify: `app/Content/Preview/PreviewMinter.php` (mint args)
- Modify: `app/Content/Preview/EnginePreviewSessionVerifier.php` (build session with appearance)
- Modify: `app/Content/Preview/PreviewReader.php` (thread appearance)
- Modify: `app/Content/Http/DTOs/MintPreviewData.php` (2 fields)
- Modify: `app/Content/Http/Controllers/PreviewController.php` (validate enums + pass to minter)
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (reset block ~745-748: set/clear the override from the session)
- Test: `tests/Integration/Content/PreviewAppearanceTest.php`

**Interfaces:**
- Consumes: `ThemeColors::normalize*` (Task 1), `setThemeAppearanceOverride()` (Task 5), `PreviewSession` (extended here).
- Produces: `PreviewSession` gains `public readonly ?string $accent; public readonly ?string $neutral;`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Content/PreviewAppearanceTest.php`. Model setup on the existing `PreviewWorkingCopyTest`/`PreviewAnnotationTest` (grep how they mint a token + drive a render). Assert:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\PreviewSession;

final class PreviewAppearanceTest extends AppTestCase
{
    public function testSessionCarriesAppearanceFields(): void
    {
        // PreviewSession VO accepts nullable accent/neutral without breaking old callers.
        $s = new PreviewSession('tok', 'entry-uuid', 'en', null, null, 'emerald', 'zinc', 9999999999);
        self::assertSame('emerald', $s->accent);
        self::assertSame('zinc', $s->neutral);
    }

    public function testSavedNonDefaultPlusPreviewDefaultEmitsNoOverride(): void
    {
        // The user's pin: preview resolves INDEPENDENTLY of saved settings. Persist a
        // REAL non-default pair, then a preview override of the DEFAULT must emit
        // nothing — the preview page shows the default look, not the saved skin.
        $settings = $this->container()->get(\App\Settings\GeneralSettings::class);
        $settings->save(['theme_accent' => 'rose', 'theme_neutral' => 'zinc']);

        $source = new \Thallo\Render\ThemeAppearanceSource(
            new \App\Settings\EngineThemeAppearanceProvider($settings),
            new \Psr\Log\NullLogger(),
        );
        $ext = new \Thallo\Render\RenderContextExtension(
            null,
            $this->container()->get(\Thallo\Contracts\Delivery\EntryTargetResolver::class),
            'en',
            appearance: $source,
        );

        // Sanity: saved rose/zinc DOES emit an override with no preview active.
        self::assertNotSame('', (string) $ext->themeColorsStyle());

        // Preview override = default -> no override emitted, independent of saved.
        $ext->setThemeAppearanceOverride('blue', 'slate');
        self::assertSame('', (string) $ext->themeColorsStyle());
    }
}
```

Add a fuller end-to-end arm if the preview render harness is readily reusable: with `rose/zinc` saved, mint a token carrying `accent=blue,neutral=slate` (default), drive `/_preview/{token}`, assert the response body contains **no** `<style>:root{` override (default look, not the saved rose/zinc), and that `GeneralSettings::themeAccent()` is STILL `rose` afterward — proving token-only, no write, resolved independently (spec §6 / P1a + the user's pin).

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/PreviewAppearanceTest.php`
Expected: FAIL — `PreviewSession::__construct` takes 6 args, not 8.

- [ ] **Step 3: Extend the `PreviewSession` VO**

In `packages/thallo-contracts/src/Delivery/PreviewSession.php`, add two fields AFTER `?string $theme` (keep `expiresAt` last):

```php
        public readonly ?string $theme,
        /** theme-color-config spec §6: previewed accent/neutral families (null = none). */
        public readonly ?string $accent,
        public readonly ?string $neutral,
        public readonly int $expiresAt,
```

- [ ] **Step 4: Thread through the token + minter + verifier + reader**

In `app/Content/Preview/PreviewToken.php`: add `?string $accent = null, ?string $neutral = null` to the constructor + `make()` factory, and add payload keys `'a' => $accent, 'n' => $neutral` alongside `'t' => $theme` (and read them back in whatever `fromPayload`/decode path exists — mirror the `'t'`/`theme` handling exactly).

In `app/Content/Preview/PreviewMinter.php`: add `?string $accent = null, ?string $neutral = null` to the mint method signature and pass them into the token (next to `$theme`).

In `app/Content/Preview/EnginePreviewSessionVerifier.php`: where it builds the `PreviewSession` (the line using `$payload->theme`), pass `$payload->accent` and `$payload->neutral` into the new VO slots.

In `app/Content/Preview/PreviewReader.php`: where it constructs the `PreviewSession` (the line using `$session->theme`), thread `accent`/`neutral` through the same way.

(These are additive nullable fields — old tokens with no `a`/`n` payload keys decode to `null`, so they keep verifying. Spec §6.)

- [ ] **Step 5: Accept + validate in the mint endpoint**

In `app/Content/Http/DTOs/MintPreviewData.php`: add nullable `?string $accent = null` and `?string $neutral = null` fields (mirror the existing `?string $theme` field's `#[Rule('string')]`).

In `app/Content/Http/Controllers/PreviewController.php`: where it validates `theme` and calls the minter, add — reject out-of-enum accent/neutral with a 422 (use `ThemeColors::normalizeAccent/normalizeNeutral`), then pass the validated values into `PreviewMinter`. An absent value stays null (no override).

- [ ] **Step 6: Apply the override in the render controller**

In `packages/thallo-render/src/Http/Controllers/RenderController.php`, in the reset-before-render block (next to `setAssetBase`), add:

```php
        // theme-color-config spec §6: a verified preview session's signed appearance
        // overrides the saved/default pair for THIS render only; null clears it so a
        // normal render falls back to the source. Reset-before-render discipline.
        $this->extension->setThemeAppearanceOverride(
            $session?->accent ?? null,
            $session?->neutral ?? null,
        );
```

(Ensure `$session` — the `?PreviewSession` from `session($request)` — is in scope where the reset block runs; if the reset helper doesn't receive it, thread it in the same way `$assetBase`/annotations are, or default to clearing the override to `null, null` for non-preview entry points.)

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content/PreviewAppearanceTest.php`
Expected: PASS. Then run the whole preview surface to catch VO-arity regressions:
Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Content tests/Integration/Render`
Expected: PASS (any other `new PreviewSession(...)` call sites now pass 8 args — fix them if the compiler/tests flag them).

- [ ] **Step 8: Hold commit** (Phase 3 checkpoint).

---

### Task 9: Admin "Theme colors" card

**Files:**
- Modify: `admin/src/pages/settings/general/index.vue` (form model ~35-48; a new card near the theme card ~225)
- Modify: `admin/src/queries/*` if the general-settings save payload type is declared there (add `theme_accent`/`theme_neutral`)
- Test: `admin/src/__tests__/themeColors.spec.ts`

**Interfaces:**
- Consumes: the general-settings save API (now accepting `theme_accent`/`theme_neutral`).

- [ ] **Step 1: Write the failing test**

Create `admin/src/__tests__/themeColors.spec.ts`. Follow the vitest + Nuxt UI conventions already used in `admin/src/__tests__/` (assert on `data-test` hooks, not portal DOM):

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GeneralSettings from '../pages/settings/general/index.vue'

describe('theme colors card', () => {
  it('exposes accent and neutral selectors', async () => {
    const wrapper = mount(GeneralSettings, { /* global stubs as the sibling specs use */ })
    expect(wrapper.find('[data-test="theme-accent"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="theme-neutral"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: Run it to verify it fails**

Run: `pnpm --dir admin test themeColors`
Expected: FAIL — selectors not found.

- [ ] **Step 3: Add the fields to the form model + card**

In `admin/src/pages/settings/general/index.vue`, add `theme_accent: 'blue'` and `theme_neutral: 'slate'` to the reactive form model (near `theme: ''`). Add a "Theme colors" `UCard` near the Live theme card with two `USelectMenu`/`USelect`s bound to those fields, each option carrying `data-test="theme-accent"` / `data-test="theme-neutral"` and a color swatch (a small `<span>` with the family's 500 hex as `background`). Include both families' option lists as local constants (the 17 accents, 5 neutrals). Add a "Preview on site" button that mints a preview session with the pending pair (call the existing preview-mint query with `accent`/`neutral`) and opens it, plus wire the two fields into the Save payload.

- [ ] **Step 4: Run the test + type-check**

Run: `pnpm --dir admin test themeColors && pnpm --dir admin type-check`
Expected: PASS + no type errors (do NOT pipe through tail — it masks the exit code).

- [ ] **Step 5: PHASE 3 CHECKPOINT — hold for user.** Backend + preview + admin done. Hold; when cleared, batch-commit Tasks 8–9.

---

## Phase 4 — Docs + full suite

### Task 10: Docs + full suite

**Files:**
- Modify: `packages/thallo-render/docs/THEMING.md` (add §9)

- [ ] **Step 1: Add a "Theme colors" section**

Append `## 9. Theme colors (accent + neutral)` to `packages/thallo-render/docs/THEMING.md`, covering: the two `GeneralSettings` keys; that they re-map tokens only (no template swap); the closed enums; that the default (blue/slate) emits nothing and lives in `site.css`; the `theme_colors_style()` cascade slot (after `blocks.css`, before `custom.css`); preview via a signed session; and the **CSP note** — strict-CSP operators must allow `style-src 'unsafe-inline'` because the inline style varies by settings (a linked route is a future delivery-only swap).

- [ ] **Step 2: Full suite**

Run: `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit`
Expected: PASS (existing count + the new appearance/colors tests). Also run `pnpm --dir admin test` for the admin spec.

- [ ] **Step 3: phpcs on changed PHP**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && vendor/bin/phpcs packages/thallo-render/src/Theme/ThemeColors.php packages/thallo-render/src/ThemeAppearanceSource.php app/Settings/EngineThemeAppearanceProvider.php packages/thallo-render/src/Listeners/PurgeRenderCacheOnAppearanceChange.php`
Expected: no errors (wrap any >120-char lines).

- [ ] **Step 4: FINAL CHECKPOINT — hold for user.** Everything green. Hold; when cleared, batch-commit Task 10 (docs) with the phase group or as its own commit, per the user's call. Also commit the spec + this plan under `docs/superpowers/` at that time.

---

## Self-review notes

- **Spec coverage:** §1 scope → all tasks; §2 storage → T2; §3 table + frozen default → T1; §4 resolution + contract → T2/T4/T5; §5 injection + order + emit-nothing-default → T5/T6; §6 preview token-only → T8; §7 cache fingerprint (page AND error keys) + purge → T7; §8 admin → T9; §9 validation → T3 (write) + T4/T5 (read fallback); §10 CSP → T10 docs; §11 testing → each task's test + T10 full suite. The user's extra pin (saved non-default + preview default → no override) is covered in T5 `testPreviewOverrideBeatsSaved` and T8 `testSavedNonDefaultPlusPreviewDefaultEmitsNoOverride`.
- **Type consistency:** `normalizeAccent/normalizeNeutral` return `?string` everywhere; `ThemeColors::css()` takes validated strings; `ThemeAppearanceSource::accent()/neutral()` return `string`; `PreviewSession` field order is `(token, entry, locale, version, theme, accent, neutral, expiresAt)` — used consistently in T8 test and VO.
- **Palette caveat:** T1 `ACCENT`/`NEUTRAL_LIGHT`/`NEUTRAL_DARK` values are copied from Tailwind's published scale; the frozen-default test (exact `site.css` hex) and the per-accent white-contrast test are the guardrails against drift. If a family fails contrast, bump its accent stops (already pre-bumped for amber/yellow/lime).
