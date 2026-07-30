# Default Theme Font (Figtree) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Figtree as the default theme's typeface — self-hosted variable roman+italic
latin subsets with reproducible provenance, an existence-aware escaping-complete
`font_faces_style()` helper over a render-scoped asset context, metric-matched fallback,
shop-page inheritance, and a separate 128KB font budget.

**Architecture:** The extension's base-only asset override becomes `setAssetContext(base,
assetsDir)` (cleared inside `resetPerRenderState()`, reset-FIRST ordering at every
boundary; effective dir = context dir ?? constructor dir). One new policy-allowlisted Twig
function composes preload + `@font-face` from a single URL derivation with sink-specific
escaping. The theme owns the font files and names them in `layout.twig`; custom themes
without the files emit nothing.

**Tech Stack:** PHP 8.3 (Glueful), Twig, fonttools/pyftsubset + Brotli (subsetting),
@capsizecss/core + @capsizecss/metrics (fallback literals), PHPUnit.

## Global Constraints

- Work on thallo `dev` directly; commit per task; **never push**; no AI attribution.
- Nothing under `docs/` or `.superpowers/` staged (spec + this plan stay held).
- Stage exact files only — never a directory-wide `git add`; check `git status` first.
- Test runs: `set -o pipefail && vendor/bin/phpunit <paths> 2>&1 | tail -5` — NEVER grep.
- phpcs PSR12 on every touched PHP file.
- Spec: `docs/superpowers/specs/2026-07-28-default-theme-font-design.md`.
- Pinned values (verbatim): upstream **github.com/erikdkennedy/figtree** tag **v2.0.3**,
  sources `'Figtree[wght].ttf'` / `'Figtree-Italic[wght].ttf'` (shell-quoted — `[…]`
  globs); Google-Fonts latin unicode range (Task 1 carries it); font budget
  **≤ 131,072 bytes summed raw woff2**; `font-display: swap`; roman-only preload with
  `crossorigin`; `font-weight: 300 900`; TemplatePolicy `CACHE_VERSION` 13 → **14**;
  `setAssetContext(null, null)` restores constructor-backed live-theme behavior;
  boundary ordering = reset FIRST, then set; capsize literals verified against the
  SHIPPED subset's real metrics before committing.

---

### Task 1: Font binaries, provenance, and the payload budget test

**Files:**
- Create: `packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2`
- Create: `packages/thallo-render/themes/default/assets/fonts/figtree-italic-latin.woff2`
- Create: `packages/thallo-render/themes/default/assets/fonts/OFL.txt`
- Create: `packages/thallo-render/themes/default/assets/fonts/PROVENANCE.md`
- Test: `tests/Integration/Render/FontPayloadBudgetTest.php` (new)

**Interfaces:**
- Produces: the two woff2 files at those exact paths — Tasks 3–4 reference the rel paths
  `fonts/figtree-roman-latin.woff2` and `fonts/figtree-italic-latin.woff2` verbatim.

- [ ] **Step 1: Write the failing budget test** (red because the files don't exist yet):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;

/**
 * Default-theme-font spec §6: the font payload's visibility gate — separate from the
 * runtime's 12KB gzip budget. Raw file size (woff2 is already Brotli-compressed
 * internally, so gzip would be noise). Growth is a conscious decision: headroom exists
 * for the latin subsets, not for a stealth second family.
 */
final class FontPayloadBudgetTest extends TestCase
{
    private const FONTS_DIR = __DIR__ . '/../../../packages/thallo-render/themes/default/assets/fonts';

    public function testShippedFontsExistAndStayWithinTheRawByteBudget(): void
    {
        $roman = self::FONTS_DIR . '/figtree-roman-latin.woff2';
        $italic = self::FONTS_DIR . '/figtree-italic-latin.woff2';
        self::assertFileExists($roman);
        self::assertFileExists($italic);
        self::assertFileExists(self::FONTS_DIR . '/OFL.txt');
        self::assertFileExists(self::FONTS_DIR . '/PROVENANCE.md');

        $total = (int) filesize($roman) + (int) filesize($italic);
        self::assertLessThanOrEqual(
            131_072,
            $total,
            "Shipped fonts total {$total} raw bytes against a 128KB budget. Growth is "
            . 'fine when it is subset/coverage weight (raise the budget here, with '
            . 'reasoning in the default-theme-font spec §6); a second family belongs to '
            . 'the theme-presets track, not this budget.',
        );
    }
}
```

- [ ] **Step 2: Run to verify failure** —
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/FontPayloadBudgetTest.php 2>&1 | tail -5`
  Expected: FAIL, roman file does not exist.

- [ ] **Step 3: Fetch upstream and record checksums.** Work in a scratch dir (NOT the
  repo):

```bash
mkdir -p /tmp/figtree-build && cd /tmp/figtree-build
curl -fL -o 'Figtree[wght].ttf' \
  'https://raw.githubusercontent.com/erikdkennedy/figtree/v2.0.3/fonts/variable/Figtree%5Bwght%5D.ttf'
curl -fL -o 'Figtree-Italic[wght].ttf' \
  'https://raw.githubusercontent.com/erikdkennedy/figtree/v2.0.3/fonts/variable/Figtree-Italic%5Bwght%5D.ttf'
curl -fL -o OFL.txt 'https://raw.githubusercontent.com/erikdkennedy/figtree/v2.0.3/OFL.txt'
shasum -a 256 'Figtree[wght].ttf' 'Figtree-Italic[wght].ttf'
```

  The explicit `-o` names are load-bearing: `curl -O` preserves `%5B`/`%5D` in the
  local filename on the supported workstation, while every checksum and `pyftsubset`
  command below intentionally addresses the decoded, shell-quoted filenames.
  If the in-repo paths differ on the v2.0.3 tag, list the tag's tree
  (`curl -fsL https://api.github.com/repos/erikdkennedy/figtree/git/trees/v2.0.3?recursive=1`)
  and use the actual variable-TTF paths — the FILES are pinned by name, not by directory.
  Record both sha256 values.

- [ ] **Step 4: Subset reproducibly.** Install the pinned toolchain and record versions:

```bash
python3 -m pip install --user fonttools brotli
python3 -c "import fontTools, brotli; print(fontTools.version, brotli.version if hasattr(brotli,'version') else brotli.__version__)"
```

  Then the spec's exact command (quoting mandatory — `[…]` is a glob):

```bash
pyftsubset 'Figtree[wght].ttf' \
  --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD" \
  --layout-features='*' --flavor=woff2 \
  --output-file=figtree-roman-latin.woff2
pyftsubset 'Figtree-Italic[wght].ttf' \
  --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD" \
  --layout-features='*' --flavor=woff2 \
  --output-file=figtree-italic-latin.woff2
shasum -a 256 figtree-roman-latin.woff2 figtree-italic-latin.woff2
ls -la *.woff2
```

  (`pyftsubset` may live at `~/.local/bin` or `$(python3 -m site --user-base)/bin`; use
  `python3 -m fontTools.subset` with the same arguments if the entrypoint is not on
  PATH.) Sanity: each subset ≈ 30–60KB; combined must be ≤ 131,072 bytes.

- [ ] **Step 5: Install into the theme + write PROVENANCE.md:**

```bash
mkdir -p /Users/michaeltawiahsowah/Sites/glueful/thallo/packages/thallo-render/themes/default/assets/fonts
cp figtree-roman-latin.woff2 figtree-italic-latin.woff2 OFL.txt \
  /Users/michaeltawiahsowah/Sites/glueful/thallo/packages/thallo-render/themes/default/assets/fonts/
```

  `PROVENANCE.md` (fill every value from Steps 3–4 — no placeholders may survive):

```markdown
# Figtree — provenance

Upstream: https://github.com/erikdkennedy/figtree, tag v2.0.3 (SIL OFL 1.1 — OFL.txt).

| shipped file | upstream source | upstream sha256 | shipped sha256 |
|---|---|---|---|
| figtree-roman-latin.woff2 | fonts/variable/Figtree[wght].ttf | <sha> | <sha> |
| figtree-italic-latin.woff2 | fonts/variable/Figtree-Italic[wght].ttf | <sha> | <sha> |

Subsetting (reproducible; output bytes depend on BOTH tool versions):
- fonttools <version>, brotli <version> (pip)
- command, run per source file (quotes mandatory — `[…]` is a shell glob):

    pyftsubset '<source>' \
      --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD" \
      --layout-features='*' --flavor=woff2 --output-file=<shipped>

Latin subset only (the standard Google Fonts latin range). Additional subsets follow the
same discipline (default-theme-font spec §9).
```

- [ ] **Step 6: Run the budget test to green**, then phpcs on the test file.

- [ ] **Step 7: Commit** —
  `git add packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2 packages/thallo-render/themes/default/assets/fonts/figtree-italic-latin.woff2 packages/thallo-render/themes/default/assets/fonts/OFL.txt packages/thallo-render/themes/default/assets/fonts/PROVENANCE.md tests/Integration/Render/FontPayloadBudgetTest.php`
  `git commit -m "feat(theme): ship Figtree variable latin subsets with reproducible provenance"`

---

### Task 2: Render-scoped asset context (base + dir), reset-first ordering

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php`
  (`setAssetBase()` → `setAssetContext()`; `resetPerRenderState()` clears it; `asset()`
  reads it; new `effectiveAssetsDir()` internal)
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php`
  (`themedEnv()` returns a triple; `render()` boundary reordered: reset FIRST)
- Modify (boundary conversions — each call site becomes `resetPerRenderState()` FIRST,
  then `setAssetContext(...)`, preserving every other site-specific call):
  - `packages/thallo-render/src/EntryBlocksRenderer.php:69` → `setAssetContext(null, null)`
  - `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php:366` → `(null, null)`
  - `packages/thallo-commerce/src/Http/Shop/ShopCartController.php:284` → `(null, null)`
  - `packages/thallo-commerce/src/Http/Shop/ShopCheckoutController.php:376` → `(null, null)`
  - `app/Http/Controllers/RegionAdminController.php:153` → convert the ONE
    `setAssetBase(null)` call to `setAssetContext(null, null)`. Preserve the later
    `$context['base_href']` assignment unchanged: it is HTML document-base state for
    the SPA blob preview, not render-extension asset context.
- Test: `tests/Integration/Render/AssetContextTest.php` (new)
- Test: `tests/Integration/Render/PreviewSessionTest.php` (extend the existing real
  themed-preview request test in both request orders)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces (Task 3 relies on these):
  - `setAssetContext(?string $base, ?string $assetsDir): void` — replaces
    `setAssetBase()` everywhere (no alias kept; it is an internal API with six callers).
  - Effective-dir rule: `context assetsDir ?? constructor themeAssetsDir` — so
    `setAssetContext(null, null)` IS constructor-backed live-theme behavior (spec pin).
    Buster rule unchanged: `?t=…&v=…` only when the context BASE is null.
  - `resetPerRenderState()` now also calls `setAssetContext(null, null)` internally
    (via a `resetAssetContext()` private step).
  - `themedEnv()` returns `[?Environment, ?string $assetBase, ?string $assetsDir]` —
    the third element is `$locator->activePaths()['assets']`.

- [ ] **Step 1: Write the failing tests:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/**
 * Default-theme-font spec §3: the render-scoped asset context. setAssetContext(null,
 * null) restores constructor-backed live-theme behavior; an alternate context wins for
 * BOTH URL composition and (Task 3) existence checks; the combined reset clears it.
 * Real request-to-request isolation is proved separately in PreviewSessionTest.
 */
final class AssetContextTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testNullNullContextIsConstructorBackedLiveBehavior(): void
    {
        $ext = $this->ext();
        $ext->resetPerRenderState();
        // Live behavior: /theme-assets base + ?t=…&v=… busters for a real theme file.
        $url = $ext->asset('site.css');
        self::assertStringStartsWith('/theme-assets/site.css?t=', $url);
        self::assertStringContainsString('&v=', $url);
    }

    public function testAlternateContextOverridesBaseAndSkipsBusters(): void
    {
        $ext = $this->ext();
        $ext->resetPerRenderState();
        $ext->setAssetContext('/_preview-assets/tok123', sys_get_temp_dir());
        self::assertSame('/_preview-assets/tok123/site.css', $ext->asset('site.css'));
    }

    public function testResetClearsAPreviewContextBackToLive(): void
    {
        $ext = $this->ext();
        $ext->setAssetContext('/_preview-assets/tok123', sys_get_temp_dir());
        $ext->resetPerRenderState(); // preview → live: live must not see the preview base
        self::assertStringStartsWith('/theme-assets/site.css?t=', $ext->asset('site.css'));

        // live → preview: the preview must not inherit live buster behavior either.
        $ext->setAssetContext('/_preview-assets/tok456', sys_get_temp_dir());
        self::assertSame('/_preview-assets/tok456/site.css', $ext->asset('site.css'));
        $ext->resetPerRenderState();
    }
}
```

  Extend `PreviewSessionTest::testThemedSessionRendersAltThemeWithoutPoisoningTheBootTheme()`
  (or split it into two named cases) so the HTTP pipeline is the authority, not the
  extension unit test:

  - preview → live: the preview response's `site.css` URL is under
    `/_preview-assets/{token}/`; the immediately following live response uses
    `/theme-assets/site.css?t=...&v=...` and contains no preview URL;
  - live → preview: issue a live request first, then the themed preview; the preview
    response uses its token base with no live busters and resolves an asset that exists
    only in the preview theme's `activePaths()['assets']`;
  - keep the existing failed-preview → live request case, since it proves reset occurs
    even when rendering throws.

  These request-level assertions exercise `themedEnv()`'s
  `[environment, assetBase, assetsDir]` return, tuple threading into `render()`, and the
  reset-first/set-second boundary order. `AssetContextTest` remains a fast state-machine
  diagnostic only.

- [ ] **Step 2: Run to verify failure** — expected: undefined method `setAssetContext`.

- [ ] **Step 3: Implement in the extension.** Replace the `assetBase` property pair:

```php
/** Render-scoped asset-context override (font spec §3): [base, assetsDir]. */
private ?string $assetBase = null;
private ?string $assetContextDir = null;

/**
 * Render-scoped asset context (default-theme-font spec §3). (null, null) restores
 * constructor-backed live-theme behavior: '/theme-assets' base with ?t/&v busters and
 * the boot theme's assets dir. A themed preview passes ITS base AND ITS dir so URL
 * emission and existence checks can never disagree on which theme is being served.
 * Cleared inside resetPerRenderState() — boundaries RESET FIRST, THEN set.
 */
public function setAssetContext(?string $base, ?string $assetsDir): void
{
    $this->assetBase = $base;
    $this->assetContextDir = $assetsDir;
}

/** The directory asset()/font_faces_style() existence+mtime checks consult. */
private function effectiveAssetsDir(): ?string
{
    return $this->assetContextDir ?? $this->themeAssetsDir;
}
```

  `resetPerRenderState()` gains `$this->setAssetContext(null, null);` as its final step.
  `asset()`'s mtime lookup switches from `$this->themeAssetsDir` to
  `$this->effectiveAssetsDir()` (the buster-only-when-base-null condition is unchanged).
  Delete `setAssetBase()` after all callers are converted.

- [ ] **Step 4: Convert the boundaries.** `RenderController::render()` (~:799) becomes —
  order is the spec pin:

```php
$this->extension->resetTags();
$this->extension->resetPerRenderState();
$this->extension->setAssetContext($assetBase, $assetsDir);
```

  where `render()` now takes/receives the dir alongside the base from `themedEnv()`
  (thread the third tuple element through the same path the second already travels; the
  live path passes `[null, null]`). `themedEnv()`'s success return becomes:

```php
return [$factory->environment(), '/_preview-assets/' . $session->token, $locator->activePaths()['assets']];
```

  (failure/live returns become `[null, null, null]`). Every OTHER boundary follows the
  same reset-first shape with its existing surrounding calls preserved, e.g.
  `EntryBlocksRenderer`:

```php
$this->extension->resetPerRenderState();
$this->extension->setAssetContext(null, null);
$this->extension->setBlockAnnotations(false);
```

  (the explicit `(null, null)` after reset is a deliberate no-op kept for readability at
  sites that previously called `setAssetBase(null)` — drop it where the old code had no
  base assignment). Re-grep when done:
  `grep -rn "setAssetBase" packages app tests | grep -v vendor` must return NOTHING.

- [ ] **Step 5: Run** `AssetContextTest` and the extended `PreviewSessionTest`, then
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render tests/Integration/Commerce tests/Integration/Http 2>&1 | tail -5` — the preview suites
  (`PreviewWorkingCopyTest`, region preview) are the sensitive ones.

- [ ] **Step 6: phpcs; commit** —
  `git add packages/thallo-render/src/RenderContextExtension.php packages/thallo-render/src/Http/Controllers/RenderController.php packages/thallo-render/src/EntryBlocksRenderer.php packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php packages/thallo-commerce/src/Http/Shop/ShopCartController.php packages/thallo-commerce/src/Http/Shop/ShopCheckoutController.php app/Http/Controllers/RegionAdminController.php tests/Integration/Render/AssetContextTest.php tests/Integration/Render/PreviewSessionTest.php`
  `git commit -m "feat(render): render-scoped asset context with reset-first boundaries"`

---

### Task 3: font_faces_style() — one helper owns identity, existence, escaping

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (the function + Twig
  registration)
- Modify: `packages/thallo-render/src/Templates/TemplatePolicy.php` (FUNCTIONS +
  CACHE_VERSION 14 + bump comment)
- Test: `tests/Integration/Render/FontFacesStyleTest.php` (new)
- Test: `tests/Integration/Render/BlocksRenderingTest.php` (extend
  `testBlocksJoinsTheSandboxAllowlistWithACacheVersionBump()`, the established
  representative-template policy test)

**Interfaces:**
- Consumes: Task 2's `effectiveAssetsDir()` + context-aware `asset()` URL composition;
  Task 1's font files (present in the default theme for integration assertions).
- Produces: Twig `font_faces_style(family, romanRel, italicRel = null): Markup` — Task 4
  calls it from `layout.twig` exactly as
  `{{ font_faces_style('Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2') }}`.

- [ ] **Step 1: Write the failing tests:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Twig\Error\RuntimeError;

/**
 * Default-theme-font spec §3/§8: font_faces_style() — existence-aware emission, BYTE
 * identity between the (attribute-decoded) preload URL and the raw CSS url(), and the
 * complete sink-escaping contract under hostile input.
 */
final class FontFacesStyleTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->resetPerRenderState();
        return $ext;
    }

    public function testDefaultThemeEmitsPreloadAndBothFacesWithByteIdenticalUrls(): void
    {
        $html = (string) $this->ext()->fontFacesStyle(
            'Figtree',
            'fonts/figtree-roman-latin.woff2',
            'fonts/figtree-italic-latin.woff2',
        );

        self::assertMatchesRegularExpression(
            '/<link rel="preload" as="font" type="font\/woff2" href="[^"]+" crossorigin>/',
            $html,
        );
        self::assertSame(2, substr_count($html, '@font-face'), 'roman + italic faces');
        self::assertSame(1, substr_count($html, 'rel="preload"'), 'roman only is preloaded');
        self::assertSame(2, substr_count($html, 'font-display: swap'));
        self::assertStringContainsString('font-weight: 300 900', $html);

        // Identity: the HTML-attribute href DECODES to the same bytes the raw CSS url()
        // carries — and the style block itself contains no HTML entities at all.
        preg_match('/href="([^"]+)"/', $html, $m);
        $decodedHref = html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE);
        preg_match('/<style>(.*)<\/style>/s', $html, $s);
        self::assertStringContainsString('url("' . $decodedHref . '")', $s[1]);
        self::assertStringNotContainsString('&amp;', $s[1]);
    }

    public function testMissingRomanEmitsNothingAndMissingItalicOmitsOnlyTheItalic(): void
    {
        $ext = $this->ext();
        self::assertSame('', (string) $ext->fontFacesStyle('Figtree', 'fonts/nope.woff2'));

        $romanOnly = (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/nope-italic.woff2');
        self::assertSame(1, substr_count($romanOnly, '@font-face'));
        self::assertStringNotContainsString('font-style: italic', $romanOnly);
    }

    public function testPreviewContextChecksTheAlternateDirNotTheBootTheme(): void
    {
        $ext = $this->ext();
        // Alternate dir WITHOUT the fonts: boot theme has them, preview must not emit.
        $ext->setAssetContext('/_preview-assets/tok1', sys_get_temp_dir());
        self::assertSame('', (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2'));

        // Alternate dir WITH a font file: emits preview-base URLs.
        $dir = sys_get_temp_dir() . '/font-ctx-' . uniqid('', true);
        mkdir($dir . '/fonts', 0755, true);
        copy(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2',
            $dir . '/fonts/figtree-roman-latin.woff2',
        );
        $ext->resetPerRenderState();
        $ext->setAssetContext('/_preview-assets/tok2', $dir);
        $html = (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2');
        self::assertStringContainsString('href="/_preview-assets/tok2/fonts/figtree-roman-latin.woff2"', $html);
        unlink($dir . '/fonts/figtree-roman-latin.woff2');
        rmdir($dir . '/fonts');
        rmdir($dir);
        $ext->resetPerRenderState();
    }

    /**
     * @dataProvider hostileCssStringProvider
     */
    public function testHostileFamilyInputsCannotEscapeTheStyleElement(string $family): void
    {
        $html = (string) $this->ext()->fontFacesStyle(
            $family,
            'fonts/figtree-roman-latin.woff2',
        );
        self::assertStringNotContainsString('</style><script>', $html);
        self::assertSame(1, substr_count($html, '</style>'), 'only the helper\'s own closer');
        self::assertStringNotContainsString("\x01", $html);
        self::assertStringNotContainsString("\x7F", $html);
    }

    /** @return iterable<string,array{string}> */
    public static function hostileCssStringProvider(): iterable
    {
        yield 'quote and style close' => ['</style><script>x</script>"; font-family: "Evil'];
        yield 'backslash' => ['Figtree\\"); color: red; /*'];
        yield 'C0 control' => ["Figtree\x01Injected"];
        yield 'DEL control' => ["Figtree\x7FInjected"];
    }

    public function testHostileAssetBaseIsEscapedForHtmlAndCssIndependently(): void
    {
        $dir = sys_get_temp_dir() . '/font-hostile-' . uniqid('', true);
        mkdir($dir . '/fonts', 0755, true);
        copy(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2',
            $dir . '/fonts/figtree-roman-latin.woff2',
        );

        $ext = $this->ext();
        $ext->setAssetContext('/preview?x="&y=</style>\\' . "\x01", $dir);
        $html = (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2');

        self::assertStringContainsString('&quot;', $html, 'href is HTML-attribute escaped');
        preg_match('/<style>(.*)<\/style>/s', $html, $style);
        self::assertStringNotContainsString('&quot;', $style[1], 'CSS never receives HTML entities');
        self::assertStringNotContainsString('</style><script>', $html);
        self::assertStringNotContainsString("\x01", $style[1]);
        self::assertStringNotContainsString("\x7F", $style[1]);
        self::assertSame(1, substr_count($html, '</style>'));

        unlink($dir . '/fonts/figtree-roman-latin.woff2');
        rmdir($dir . '/fonts');
        rmdir($dir);
        $ext->resetPerRenderState();
    }

    public function testUnsafeRelativePathKeepsAssetExceptionBehavior(): void
    {
        // Unsafe rel paths keep asset()'s exception behavior.
        $this->expectException(RuntimeError::class);
        $this->ext()->fontFacesStyle('Figtree', '../../../etc/passwd');
    }
}
```

  The hostile-base test proves both sinks are independently escaped. The existing
  simple-URL test remains the byte-identity authority because a CSS-escaped hostile URL
  requires CSS decoding before byte comparison; do not compare escaped source strings
  directly.

- [ ] **Step 2: Run to verify failure** — undefined method `fontFacesStyle`.

- [ ] **Step 3: Implement.** In `RenderContextExtension` (near `asset()`):

```php
/**
 * Preload + @font-face emission for a theme-owned webfont (default-theme-font spec §3).
 * ONE URL derivation feeds both sinks so they are byte-identical on the wire; every
 * dynamic value is escaped for its EXACT sink (the function is DB-template-callable):
 * the href is HTML-attribute-escaped, CSS strings are CSS-escaped (backslash-hex for
 * quotes, backslashes, control chars, and `<` so nothing can form `</style>`). A
 * missing roman emits nothing — a theme without the files (custom theme inheriting the
 * default layout) falls through to the system stack. Roman only is preloaded.
 */
public function fontFacesStyle(string $family, string $romanRel, ?string $italicRel = null): Markup
{
    $romanUrl = $this->assetUrlIfExists($romanRel);
    if ($romanUrl === null) {
        return new Markup('', 'UTF-8');
    }
    $italicUrl = $italicRel !== null ? $this->assetUrlIfExists($italicRel) : null;

    $css = '@font-face { font-family: "' . self::cssEscape($family) . '"; '
        . 'src: url("' . self::cssEscape($romanUrl) . '") format("woff2"); '
        . 'font-weight: 300 900; font-style: normal; font-display: swap; }';
    if ($italicUrl !== null) {
        $css .= "\n@font-face { font-family: \"" . self::cssEscape($family) . '"; '
            . 'src: url("' . self::cssEscape($italicUrl) . '") format("woff2"); '
            . 'font-weight: 300 900; font-style: italic; font-display: swap; }';
    }

    $html = '<link rel="preload" as="font" type="font/woff2" href="'
        . htmlspecialchars($romanUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '" crossorigin>' . "\n<style>\n" . $css . "\n</style>";

    return new Markup($html, 'UTF-8');
}

/** asset() with existence gating against the effective (context ?? boot) dir. */
private function assetUrlIfExists(string $rel): ?string
{
    $url = $this->asset($rel); // path-safety exception behavior shared verbatim
    $dir = $this->effectiveAssetsDir();
    if ($dir === null || !is_file($dir . '/' . $rel)) {
        return null;
    }
    return $url;
}

/** CSS string escape: backslash-hex for quotes/backslash/control/`<` (spec §3). */
private static function cssEscape(string $value): string
{
    return preg_replace_callback(
        '/[\x00-\x1F\x7F"\'\\\\<>]/',
        static fn (array $m): string => sprintf('\\%x ', ord($m[0][0])),
        $value,
    ) ?? '';
}
```

  Add `use Twig\Markup;`. Register:
  `new TwigFunction('font_faces_style', $this->fontFacesStyle(...), ['is_safe' => ['html']]),`.

- [ ] **Step 4: TemplatePolicy.** Add `'font_faces_style'` to `FUNCTIONS` (in the first
  themed group beside `seo_head`), bump `CACHE_VERSION` to 14, add the bump comment line
  `// bumped: font_faces_style joined the function allowlist (default-theme-font spec §3)`.
  Extend the representative-template policy test with a template calling
  `{{ font_faces_style('X', 'fonts/x.woff2') }}` (must compile under the policy).

- [ ] **Step 5: Run to green** (new file + `BlocksRenderingTest` + the Render suite),
  phpcs.

- [ ] **Step 6: Commit** —
  `git add packages/thallo-render/src/RenderContextExtension.php packages/thallo-render/src/Templates/TemplatePolicy.php tests/Integration/Render/FontFacesStyleTest.php tests/Integration/Render/BlocksRenderingTest.php`
  `git commit -m "feat(render): font_faces_style() with sink-complete escaping and policy membership"`

---

### Task 4: Theme adoption — layout, fallback literals, shop inheritance

**Files:**
- Modify: `packages/thallo-render/themes/default/templates/layout.twig` (head, before the
  `site.css` link)
- Modify: `packages/thallo-render/themes/default/assets/site.css` (body font-family +
  fallback face)
- Modify: `packages/thallo-commerce/assets/shop.css` (remove the six page-container
  `font-family` declarations)
- Test: extend `tests/Integration/Render/FontFacesStyleTest.php` with a layout-render
  test; a small shop.css structural assertion in `tests/Integration/Commerce/ShopBlocksTest.php`
  or a dedicated check in the new test file

**Interfaces:**
- Consumes: Task 3's `font_faces_style()` exactly; Task 1's file names.
- Produces: nothing later tasks need.

- [ ] **Step 1: Generate the fallback literals (capsize — the ONE pinned tool):**

```bash
cd /tmp/figtree-build && npm init -y >/dev/null 2>&1
npm install @capsizecss/core @capsizecss/metrics >/dev/null 2>&1
node -e "
const { createFontStack } = require('@capsizecss/core');
const figtree = require('@capsizecss/metrics/figtree');
const arial = require('@capsizecss/metrics/arial');
const r = createFontStack([figtree, arial]);
console.log(r.fontFaces);
console.log(JSON.stringify({figtree: {
  unitsPerEm: figtree.unitsPerEm,
  ascent: figtree.ascent,
  descent: figtree.descent,
  lineGap: figtree.lineGap,
  xWidthAvg: figtree.subsets.latin.xWidthAvg
}}));
"
node -e "console.log(require('@capsizecss/core/package.json').version, require('@capsizecss/metrics/package.json').version)"
```

- [ ] **Step 2: Verify what the shipped binary can prove, and record the width authority
  honestly.** CapSize ships precomputed upstream metadata and never reads our binary.
  Verify its vertical tuple against the shipped subset:

```bash
python3 - << 'EOF'
from fontTools.ttLib import TTFont
f = TTFont('/Users/michaeltawiahsowah/Sites/glueful/thallo/packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2')
print('unitsPerEm', f['head'].unitsPerEm)
print('hhea ascent/descent/lineGap', f['hhea'].ascent, f['hhea'].descent, f['hhea'].lineGap)
EOF
```

  The four vertical values MUST match the CapSize tuple from Step 1. If they differ,
  compute the vertical overrides from the SHIPPED values instead (the formulas are
  `ascent/unitsPerEm` etc. against Arial's metrics from the same package) and record the
  divergence in the CSS comment.

  `size-adjust` additionally depends on CapSize's Latin `xWidthAvg`; `head`/`hhea` do
  not contain that aggregate, so the command above does NOT claim to verify it from the
  binary. Record the exact `xWidthAvg`, `@capsizecss/metrics` version, upstream source
  sha256, and deterministic subset command beside the committed literals. Those form
  the reproducibility authority; Task 5's held-font CLS measurement is the empirical
  acceptance gate for the resulting `size-adjust`. If that gate fails, do not hand-edit
  a percentage: investigate/recompute the width metric and record the method first.
  Only then commit literals.

- [ ] **Step 3: `site.css`.** Body rule (line ~73) becomes:

```css
font: 17px/1.65 "Figtree", "Figtree Fallback", system-ui, -apple-system, "Segoe UI", sans-serif;
```

  Append the fallback face (VALUES from Steps 1–2, never these examples):

```css
/* Metric-matched fallback (default-theme-font spec §4): generated with
   @capsizecss/core <ver> + @capsizecss/metrics <ver>, verified against the shipped
   subset's head/hhea tables (fonttools). Regenerate when the binary changes. */
@font-face {
  font-family: "Figtree Fallback";
  src: local("Arial"), local("ArialMT");
  size-adjust: <generated>%;
  ascent-override: <generated>%;
  descent-override: <generated>%;
  line-gap-override: <generated>%;
}
```

- [ ] **Step 4: `layout.twig`.** In the head, immediately BEFORE the `site.css` link:

```twig
{# Theme font (default-theme-font spec §3): existence-aware — a custom theme inheriting
   this layout without the files emits nothing and falls through to the system stack.
   Inline style relies on the existing style-src 'unsafe-inline' posture (spec §3). #}
{{ font_faces_style('Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2') }}
```

- [ ] **Step 5: `shop.css`.** Delete the `font-family: -apple-system, …` declaration line
  from ALL SIX page containers (`.shop-product`, `.shop-index`+`.shop-category` shared
  rule, `.shop-cart`, `.shop-checkout`+`.shop-confirmation` shared rule) — the layout is
  the single font authority; shop pages always extend it. Nothing else in those rules
  changes.

- [ ] **Step 6: Layout-render test** (add to `FontFacesStyleTest`):

```php
public function testDefaultLayoutEmitsTheFontHeadBeforeSiteCss(): void
{
    $res = $this->handle(\Symfony\Component\HttpFoundation\Request::create('/', 'GET'));
    $html = (string) $res->getContent();
    self::assertStringContainsString('rel="preload" as="font"', $html);
    self::assertStringContainsString('font-family: "Figtree"', $html);
    self::assertTrue(
        strpos($html, 'rel="preload" as="font"') < strpos($html, 'site.css'),
        'font head precedes the stylesheet link',
    );
}
```

  Plus the shop.css structural check:

```php
public function testShopCssNoLongerDeclaresPageFontFamilies(): void
{
    $css = (string) file_get_contents(
        dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css',
    );
    self::assertStringNotContainsString('font-family: -apple-system', $css);
}
```

- [ ] **Step 7: Run to green** — the two new tests, then the FULL suite
  (`set -o pipefail && vendor/bin/phpunit 2>&1 | tail -5`, expect ~2350+ green); phpcs.

- [ ] **Step 8: CHANGELOG** — add to `[Unreleased]` → `### Added`, top:

```markdown
- **Figtree is the default theme's typeface** (self-hosted, SIL OFL): variable roman +
  italic latin subsets with reproducible provenance (upstream tag, checksums, exact
  subsetting command committed), loaded via a new existence-aware `font_faces_style()`
  Twig helper — preload and `@font-face` share one byte-identical URL, custom themes
  without the files fall through to the system stack untouched, and a metric-matched
  Arial fallback eliminates swap reflow. Shop pages inherit the theme face (their own
  font-family overrides removed). Fonts carry their own 128KB payload budget test,
  separate from the runtime's.
```

- [ ] **Step 9: Commit** —
  `git add packages/thallo-render/themes/default/templates/layout.twig packages/thallo-render/themes/default/assets/site.css packages/thallo-commerce/assets/shop.css tests/Integration/Render/FontFacesStyleTest.php CHANGELOG.md`
  `git commit -m "feat(theme): adopt Figtree across the default theme; shop pages inherit"`

---

### Task 5: Validation gate (coordinator + operator; spec §7)

No implementer subagent — this is the coordinator running the browser, then the operator
approving. Steps for the coordinator:

- [ ] **Cold-load behavioral run** (deterministic, per spec §7): in Chrome (dev site,
  cache disabled), install a `PerformanceObserver` for `layout-shift` BEFORE navigation,
  intercept and HOLD the roman font response until first paint, release it, then assert:
  fallback text painted first, Figtree swapped in after release, swap CLS contribution
  < 0.02, and the network log shows EXACTLY ONE request for
  `figtree-roman-latin.woff2` (two = the preload/CSS URLs diverged) and NONE for the
  italic unless italic text is on the page.
- [ ] **Purge dev caches** with the real command
  `php glueful render:cache:clear` (the underlying cache tag is
  `thallo:render:page`) so pages re-render with the font head.
- [ ] **Screenshot pass for operator approval**: article page, listing page, product
  page, form page at ~1440px and ~390px. Present to the operator; the Figtree decision
  is confirmed (or swapped) HERE, before any release.
- [ ] Ledger entry in `.superpowers/sdd/progress.md`; held docs stay uncommitted.

## Self-Review Notes

- Spec coverage: §2→Task 1; §3→Tasks 2–3 (context, ordering, helper, policy, CSP note
  lives in layout comment + spec); §4→Task 4 Steps 1–3 (one tool, shipped-subset
  verification); §5→Task 4 Steps 3–5; §6→Task 1; §7→Task 5; §8 tests distributed.
- The `<generated>`/`<sha>`/`<ver>` markers are explicit fill-at-execution values whose
  generation commands are in the same task — the plan forbids them surviving into
  commits (Task 1 Step 5, Task 4 Step 3).
- Type consistency: `fontFacesStyle`/`font_faces_style`, `setAssetContext`,
  `effectiveAssetsDir`, tuple `[env, assetBase, assetsDir]` consistent across tasks.
