# Icon Library (`icon()` Twig function) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One sandbox-safe `icon(name)` Twig function inlining vendored SVGs — Lucide by default, curated Simple Icons under `brand:` — returning `\Twig\Markup`, with the features block as first schema-integrated consumer.

**Architecture:** A pack-internal `IconSet` class (validate → resolve → read → inject → memoize) feeds a new `icon()` function on `RenderContextExtension` that wraps output in `\Twig\Markup` (no `is_safe`), so fallback strings stay auto-escaped. Assets vendored once from pinned upstream releases into `packages/lemma-render/resources/icons/`; brand SVGs normalized to `fill="currentColor"` at import. `TemplatePolicy::FUNCTIONS` += `icon` with `CACHE_VERSION` 5 → 6.

**Tech Stack:** PHP 8.3, Twig 3 sandbox, PHPUnit; vendored SVG via curl from GitHub release tarballs (one-time, no runtime/build dependency).

**Spec:** `docs/superpowers/specs/2026-07-04-icon-library-design.md`

## Global Constraints

- Name grammar: `^(brand:)?[a-z0-9-]+$` — anything else returns `null`.
- Fixed roots only: `packages/lemma-render/resources/icons/lucide/`, `.../brands/`.
- `icon()` returns `?\Twig\Markup`; NO `is_safe` flag on the TwigFunction.
- Injection only at render: `class="lemma-icon"` (append to existing class) + `aria-hidden="true"`; no other markup changes at render time.
- Brand SVGs normalized to `fill="currentColor"` at vendoring; Lucide byte-identical to upstream.
- No runtime sanitizer; vendored review enforced by a regression test.
- `TemplatePolicy::CACHE_VERSION = 6`.
- Feature block `icon` field pattern is Lucide-only: `[a-z0-9]+(-[a-z0-9]+)*`.
- Session conventions: NO per-task commits — stage everything at the end; commit only on "commit all". No attribution trailers.

---

### Task 1: Vendor the icon assets

**Files:**
- Create: `packages/lemma-render/resources/icons/lucide/*.svg` (full set)
- Create: `packages/lemma-render/resources/icons/brands/*.svg` (curated, normalized)
- Create: `packages/lemma-render/resources/icons/VENDORED.md`

- [ ] **Step 1: Fetch pinned releases and vendor Lucide (full) + brands (curated)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
SCRATCH=/tmp/icon-vendor && mkdir -p "$SCRATCH"

LUCIDE_TAG=$(curl -s https://api.github.com/repos/lucide-icons/lucide/releases/latest | perl -ne 'print $1 if /"tag_name":\s*"([^"]+)"/')
SIMPLE_TAG=$(curl -s https://api.github.com/repos/simple-icons/simple-icons/releases/latest | perl -ne 'print $1 if /"tag_name":\s*"([^"]+)"/')
echo "lucide=$LUCIDE_TAG simple-icons=$SIMPLE_TAG"

curl -sL "https://github.com/lucide-icons/lucide/archive/refs/tags/${LUCIDE_TAG}.tar.gz" | tar xz -C "$SCRATCH"
curl -sL "https://github.com/simple-icons/simple-icons/archive/refs/tags/${SIMPLE_TAG}.tar.gz" | tar xz -C "$SCRATCH"

mkdir -p packages/lemma-render/resources/icons/{lucide,brands}
# Lucide ships icons/*.svg alongside *.json metadata — copy ONLY the SVGs, byte-identical.
cp "$SCRATCH"/lucide-*/icons/*.svg packages/lemma-render/resources/icons/lucide/

# Curated brands — STRICT: any requested slug missing upstream FAILS the
# vendoring. A typo like `linked-in` must break the import loudly, not
# silently ship a smaller social set that the tests then "prove" complete.
BRANDS="github gitlab bitbucket google apple x facebook instagram youtube tiktok discord whatsapp telegram reddit pinterest twitch spotify snapchat threads bluesky mastodon vimeo medium dribbble behance figma stackoverflow"  # linkedin/slack/microsoft: removed upstream (VENDORED.md)
MISSING=""
for b in $BRANDS; do
  src=$(ls "$SCRATCH"/simple-icons-*/icons/"$b".svg 2>/dev/null | head -1)
  if [ -n "$src" ]; then cp "$src" packages/lemma-render/resources/icons/brands/; else MISSING="$MISSING $b"; fi
done
if [ -n "$MISSING" ]; then echo "VENDORING FAILED — missing upstream:$MISSING"; false; fi
ls packages/lemma-render/resources/icons/lucide | wc -l
ls packages/lemma-render/resources/icons/brands | wc -l
```

Expected: lucide count ≈ 1600; brands count == number of curated slugs. On
`VENDORING FAILED`: either the slug is wrong (fix it — check
`ls "$SCRATCH"/simple-icons-*/icons/ | grep <term>` for the current upstream
name) or the brand was removed upstream — in which case removing it from the
curated list is a DELIBERATE edit made in the same patch (update `BRANDS`
here, the spec's curated list, and VENDORED.md together), never a silent
drop. Then re-run the whole step. The final list in VENDORED.md must equal
`BRANDS` exactly; the count assertion in `IconAssetsTest` (Task 5, Step 1a)
enforces the shipped set matches the manifest
(`testShippedBrandSetMatchesTheManifestExactly`).

- [ ] **Step 2: Normalize brand SVGs to currentColor**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/packages/lemma-render/resources/icons/brands
# Simple Icons root tag is `<svg role="img" ...>` with NO fill; add one. Idempotent guard: only if absent.
perl -pi -e 's/<svg (?![^>]*fill=)/<svg fill="currentColor" /' *.svg
# Verify: every file carries it, and no OTHER fixed paint attributes exist anywhere.
grep -L 'fill="currentColor"' *.svg || true            # expect NO output
grep -o 'fill="[^"]*"' *.svg | grep -v currentColor || true   # expect NO output
grep -o 'stroke="[^"]*"' *.svg | grep -v currentColor || true # expect NO output
```

Expected: all three verification greps print nothing.

- [ ] **Step 3: Vendoring-time security review (manual, one-shot)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/packages/lemma-render/resources/icons
grep -rl '<script' . || echo clean-script
grep -rlE ' on[a-z]+=' . || echo clean-handlers
grep -rl 'href="http' . || echo clean-hrefs
grep -rl '<foreignObject' . || echo clean-foreign
```

Expected: `clean-script clean-handlers clean-hrefs clean-foreign` (one per line). Any hit → inspect and drop the file.

- [ ] **Step 4: Write VENDORED.md**

`packages/lemma-render/resources/icons/VENDORED.md` (fill in the two tags from Step 1 and the final brand list):

```markdown
# Vendored icon sets

| Set | Upstream | Version | Files | License |
| --- | --- | --- | --- | --- |
| `lucide/` | https://github.com/lucide-icons/lucide (`icons/*.svg`) | <LUCIDE_TAG> | full set, byte-identical | ISC |
| `brands/` | https://github.com/simple-icons/simple-icons (`icons/*.svg`) | <SIMPLE_TAG> | curated subset, normalized (below) | CC0 |

## Brand normalization rule (reapply on every refresh)

Simple Icons ships single-path SVGs with NO fill attribute (fixed black by SVG
default) — brand colors exist only as package metadata. Each curated file gets
`fill="currentColor"` on the root `<svg>` and must carry no other fixed
`fill`/`stroke` values:

    perl -pi -e 's/<svg (?![^>]*fill=)/<svg fill="currentColor" /' *.svg

**Exact brand color is theme CSS, not the SVG asset** — a theme wanting
GitHub-black or LinkedIn-blue sets `color` on the element. Brand marks remain
trademarks of their owners; usage responsibility sits with the site operator.

## Curated brands

<final list, one per line>

## Refresh procedure

1. Download the new pinned release tarballs; replace `lucide/` wholesale,
   re-copy the curated brand slugs, re-run the normalization rule.
2. Security review (regression-tested in `IconAssetsTest`): no `<script`,
   no ` on*=` attributes, no `href="http`, no `<foreignObject`.
3. Update the version table above.
```

- [ ] **Step 5: Sanity-check one icon of each set**

```bash
head -c 300 /Users/michaeltawiahsowah/Sites/glueful/lemma/packages/lemma-render/resources/icons/lucide/activity.svg; echo
head -c 300 /Users/michaeltawiahsowah/Sites/glueful/lemma/packages/lemma-render/resources/icons/brands/github.svg; echo
```

Expected: lucide `activity.svg` opens `<svg` with `stroke="currentColor"`; brand `github.svg` opens `<svg fill="currentColor" role="img"`.

---

### Task 2: `IconSet` class + unit tests

**Files:**
- Create: `packages/lemma-render/src/Templates/IconSet.php`
- Test: `tests/Unit/Render/IconSetTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Render\Templates\IconSet::__construct(string $root)`, `svg(string $name): ?string` — used by Task 3.

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/Render/IconSetTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Render;

use Glueful\Lemma\Render\Templates\IconSet;
use PHPUnit\Framework\TestCase;

final class IconSetTest extends TestCase
{
    private function set(): IconSet
    {
        return new IconSet(dirname(__DIR__, 3) . '/packages/lemma-render/resources/icons');
    }

    public function testLucideNameResolvesToDecoratedSvg(): void
    {
        $svg = $this->set()->svg('activity');
        self::assertNotNull($svg);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
        // Lucide ships class="lucide lucide-activity" — ours is APPENDED, not duplicated.
        self::assertStringContainsString('lemma-icon', $svg);
        self::assertSame(1, substr_count(substr($svg, 0, strpos($svg, '>') ?: 0), 'class='));
    }

    public function testBrandNamespaceResolvesFromBrandsDir(): void
    {
        $svg = $this->set()->svg('brand:github');
        self::assertNotNull($svg);
        self::assertStringContainsString('fill="currentColor"', $svg);
        self::assertStringContainsString('lemma-icon', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
    }

    /** Executable checks pinned at plan review. */
    public function testInvalidAndUnknownNamesReturnNull(): void
    {
        $set = $this->set();
        self::assertNull($set->svg('../x'));
        self::assertNull($set->svg('brand:../x'));
        self::assertNull($set->svg('Brand:github'));
        self::assertNull($set->svg('brand:github.svg'));
        self::assertNull($set->svg('no-such-icon-name'));
        self::assertNull($set->svg('brand:no-such-brand'));
        self::assertNull($set->svg(''));
        self::assertNull($set->svg('a/b'));
        self::assertNull($set->svg('Star'));
    }

    public function testMissesAreMemoized(): void
    {
        $set = $this->set();
        self::assertNull($set->svg('no-such-icon-name'));
        self::assertNull($set->svg('no-such-icon-name')); // second hit served from memo
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Unit/Render/IconSetTest.php`
Expected: FAIL — class `IconSet` not found.

- [ ] **Step 3: Implement `IconSet`**

`packages/lemma-render/src/Templates/IconSet.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

/**
 * Vendored inline-SVG icon resolver (icon-library spec). Two fixed sets under
 * one root: lucide/ (default namespace) and brands/ (`brand:` prefix, curated
 * Simple Icons normalized to currentColor at vendoring). The strict name
 * grammar admits no dots or slashes, so a name can only ever select a file
 * inside the fixed roots — traversal is impossible by construction. Output is
 * the vendored markup plus exactly two injected attributes; anything invalid,
 * unknown, or unreadable is null so templates can fall back to text.
 */
final class IconSet
{
    private const GRAMMAR = '/\A(brand:)?[a-z0-9-]+\z/';

    /** @var array<string, string|null> per-process memo (null = known miss) */
    private array $memo = [];

    public function __construct(private readonly string $root)
    {
    }

    public function svg(string $name): ?string
    {
        if (array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }
        if (preg_match(self::GRAMMAR, $name) !== 1) {
            return $this->memo[$name] = null;
        }
        $brand = str_starts_with($name, 'brand:');
        $file = $this->root . '/' . ($brand ? 'brands' : 'lucide') . '/'
            . ($brand ? substr($name, 6) : $name) . '.svg';
        if (!is_file($file)) {
            return $this->memo[$name] = null;
        }
        $raw = file_get_contents($file);
        if ($raw === false || !str_starts_with(ltrim($raw), '<svg')) {
            return $this->memo[$name] = null;
        }
        return $this->memo[$name] = $this->decorate(trim($raw));
    }

    /** Inject class="lemma-icon" (appended to an existing class) + aria-hidden into the opening tag. */
    private function decorate(string $svg): string
    {
        $end = strpos($svg, '>');
        if ($end === false) {
            return $svg;
        }
        $tag = substr($svg, 0, $end);
        if (preg_match('/class="([^"]*)"/', $tag, $m) === 1) {
            $tag = str_replace($m[0], 'class="' . $m[1] . ' lemma-icon"', $tag);
        } else {
            $tag .= ' class="lemma-icon"';
        }
        if (!str_contains($tag, 'aria-hidden=')) {
            $tag .= ' aria-hidden="true"';
        }
        return $tag . substr($svg, $end);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Unit/Render/IconSetTest.php`
Expected: PASS (4 tests).

---

### Task 3: `icon()` Twig function, provider wiring, policy + cache bump

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (constructor + functions + method)
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php:249-275` (`makeRenderContextExtension`)
- Modify: `packages/lemma-render/src/Templates/TemplatePolicy.php:31,43-46` (`CACHE_VERSION`, `FUNCTIONS`)
- Modify: `tests/Integration/Render/BlocksRenderingTest.php:152-165` (version pin + lint checks)

**Interfaces:**
- Consumes: `IconSet::svg(string): ?string` (Task 2).
- Produces: Twig function `icon(name): ?\Twig\Markup` for templates (Task 4) and DB templates.

- [ ] **Step 1: Extend the existing policy test (failing first)**

In `tests/Integration/Render/BlocksRenderingTest.php`, update `testBlocksJoinsTheSandboxAllowlistWithACacheVersionBump`:

```php
        self::assertContains('blocks', TemplatePolicy::FUNCTIONS);
        self::assertContains('media', TemplatePolicy::FUNCTIONS);
        self::assertContains('site_logo', TemplatePolicy::FUNCTIONS);
        self::assertContains('video_embed', TemplatePolicy::FUNCTIONS);
        self::assertContains('icon', TemplatePolicy::FUNCTIONS);
        self::assertSame(6, TemplatePolicy::CACHE_VERSION); // 6 = 'icon' joined (icon-library spec)

        // DB templates calling the allowlisted functions lint clean.
        $linter = $this->container()->get(TemplateLinter::class);
        self::assertSame([], $linter->lint('{{ blocks(entry.fields.body) }}'));
        self::assertSame([], $linter->lint('{{ media(data.image) }}'));
        self::assertSame([], $linter->lint('{{ site_logo() }}'));
        self::assertSame([], $linter->lint('{{ icon(data.icon) ?? data.icon }}'));
```

Run: `vendor/bin/phpunit --filter=testBlocksJoinsTheSandboxAllowlist tests/Integration/Render/BlocksRenderingTest.php`
Expected: FAIL — `'icon'` not in FUNCTIONS / CACHE_VERSION is 5.

- [ ] **Step 2: Policy allowlist + version bump**

`packages/lemma-render/src/Templates/TemplatePolicy.php`:

```php
    public const CACHE_VERSION = 6; // bumped: 'icon' joined FUNCTIONS (icon-library spec)
```

```php
    public const FUNCTIONS = [
        'menu', 'path', 'asset', 'facets', 'blocks', 'media', 'site_logo', 'video_embed', 'icon',
        'include', 'parent', 'block', 'cycle', 'date', 'min', 'max', 'range',
    ];
```

- [ ] **Step 3: Extension — dependency, registration, method**

`packages/lemma-render/src/RenderContextExtension.php`. Add the import:

```php
use Glueful\Lemma\Render\Templates\IconSet;
```

Constructor — append after the `$siteLogo` param:

```php
        /** Pack-internal (icon-library spec): null → icon() returns null. */
        private readonly ?IconSet $icons = null,
```

In `getFunctions()`, after the `site_logo` line:

```php
            new TwigFunction('icon', $this->icon(...)),   // NO is_safe — safety travels in the Markup value
```

New method (near `siteLogo()`):

```php
    /**
     * Vendored inline icon (icon-library spec): Lucide by default,
     * `brand:{name}` for the curated Simple Icons set. Returns Markup — NOT an
     * is_safe string — so `{{ icon(x) ?? x }}` renders the trusted SVG raw
     * while the untrusted string fallback stays auto-escaped. Null for any
     * invalid or unknown name so templates can fall back to text.
     */
    public function icon(?string $name): ?\Twig\Markup
    {
        $svg = $name === null || $name === '' ? null : $this->icons?->svg($name);
        return $svg === null ? null : new \Twig\Markup($svg, 'UTF-8');
    }
```

- [ ] **Step 4: Provider wiring**

`packages/lemma-render/src/LemmaRenderServiceProvider.php` — add the import (`use Glueful\Lemma\Render\Templates\IconSet;` in the existing block, short name per convention), then append the constructor arg at the end of `makeRenderContextExtension`'s `new RenderContextExtension(...)`:

```php
            // icon() (icon-library spec): pack-internal furniture — fixed
            // resources root, no app-side contract to soft-bind.
            new IconSet(dirname(__DIR__) . '/resources/icons'),
```

(`__DIR__` of the provider is `packages/lemma-render/src`, so `dirname(__DIR__)` is the pack root.)

- [ ] **Step 5: Run to verify pass**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit --filter=testBlocksJoinsTheSandboxAllowlist tests/Integration/Render/BlocksRenderingTest.php`
Expected: PASS.

Then the whole render group (catches any other CACHE_VERSION/extension-construction pins):

Run: `vendor/bin/phpunit tests/Integration/Render/`
Expected: PASS (~184+ tests). If any test constructs `RenderContextExtension` directly and asserts icon behavior is absent, fix it here.

---

### Task 4: Feature block integration (template, schema, CSS) + render tests

**Files:**
- Modify: `packages/lemma-render/themes/default/templates/blocks/feature.twig`
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (`feature` definition, ~line 247)
- Modify: `packages/lemma-render/themes/default/assets/blocks.css` (~line 377)
- Test: `tests/Integration/Render/BlockLibraryRenderTest.php` (new cases)

**Interfaces:**
- Consumes: Twig `icon()` from Task 3.

- [ ] **Step 1: Write the failing render tests**

Append to `tests/Integration/Render/BlockLibraryRenderTest.php` (uses the file's existing `render()` helper):

```php
    public function testFeatureIconRendersInlineSvgForLucideNames(): void
    {
        $out = $this->render([[
            'id' => 'f1', 'type' => 'feature',
            'data' => ['icon' => 'activity', 'title' => 'Fast'],
        ]]);
        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('lemma-icon', $out);
        self::assertStringNotContainsString('&lt;svg', $out); // not escaped text
    }

    public function testFeatureIconFallsBackToEscapedTextForNonNames(): void
    {
        // Legacy free-text icons (emoji) keep rendering as text…
        $emoji = $this->render([[
            'id' => 'f2', 'type' => 'feature',
            'data' => ['icon' => '✓', 'title' => 'Legacy'],
        ]]);
        self::assertStringContainsString('✓', $emoji);
        self::assertStringNotContainsString('<svg', $emoji);

        // …and a hostile legacy value is ESCAPED, never markup (Markup discipline).
        $hostile = $this->render([[
            'id' => 'f3', 'type' => 'feature',
            'data' => ['icon' => '<img src=x onerror=alert(1)>', 'title' => 'Hostile'],
        ]]);
        self::assertStringNotContainsString('<img', $hostile);
        self::assertStringContainsString('&lt;img', $hostile);
    }
```

Run: `vendor/bin/phpunit --filter=testFeatureIcon tests/Integration/Render/BlockLibraryRenderTest.php`
Expected: FAIL — first test sees the raw text `activity`, no `<svg`.

- [ ] **Step 2: Template change**

`packages/lemma-render/themes/default/templates/blocks/feature.twig` — replace the icon line:

```twig
  {% if data.icon %}<span class="lemma-block-feature__icon" aria-hidden="true">{{ icon(data.icon) ?? data.icon }}</span>{% endif %}
```

(Markup discipline makes this single expression correct: SVG raw, string fallback escaped.)

- [ ] **Step 3: Schema pattern (new installs only)**

`app/Content/Blocks/StarterBlockTypes.php`, `feature` definition — the `icon` field becomes:

```php
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
```

(Lucide-only by design: no `brand:` in the feature grammar. Seeder is skip-if-exists, so existing installs keep the loose field — the template fallback keeps them rendering.)

- [ ] **Step 4: Sizing CSS**

`packages/lemma-render/themes/default/assets/blocks.css` — add a global rule in the base/utility area (near the top-level block primitives), and scale the feature icon:

```css
.lemma-icon { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; }
```

and extend the existing rule at ~line 377:

```css
.lemma-block-feature__icon { font-size: 1.4rem; line-height: 1.3; display: inline-block; }
```

(CSS beats the SVGs' `width="24"` presentation attributes; icons track font size and `currentColor`.)

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Render/BlockLibraryRenderTest.php`
Expected: PASS (existing matrix + 2 new tests).

---

### Task 5: Vendored-tree regression test

**Files:**
- Test: `tests/Integration/Render/IconAssetsTest.php` (new)

- [ ] **Step 1: Write the test (passes immediately against the vendored tree — it's the refresh gate)**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;

/**
 * The vendoring-time security review as a regression gate (icon-library spec):
 * every shipped SVG must stay free of active content, and the brand set must
 * keep its currentColor normalization. Guards future upstream refreshes.
 */
final class IconAssetsTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3) . '/packages/lemma-render/resources/icons';
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $out = glob($this->root() . '/' . $dir . '/*.svg');
        self::assertNotFalse($out);
        self::assertNotEmpty($out, "no vendored SVGs under $dir/");
        return $out;
    }

    public function testEveryVendoredSvgIsFreeOfActiveContent(): void
    {
        foreach (array_merge($this->files('lucide'), $this->files('brands')) as $file) {
            $svg = (string) file_get_contents($file);
            $name = basename($file);
            self::assertStringNotContainsString('<script', $svg, $name);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $svg, $name);
            self::assertStringNotContainsString('href="http', $svg, $name);
            self::assertStringNotContainsString('<foreignObject', $svg, $name);
        }
    }

    public function testBrandSvgsAreNormalizedToCurrentColor(): void
    {
        foreach ($this->files('brands') as $file) {
            $svg = (string) file_get_contents($file);
            $name = basename($file);
            self::assertStringContainsString('fill="currentColor"', $svg, $name);
            // No OTHER fixed paint values anywhere in the file.
            preg_match_all('/(?:fill|stroke)="([^"]*)"/', $svg, $m);
            foreach ($m[1] as $paint) {
                self::assertContains($paint, ['currentColor', 'none'], "$name carries fixed paint '$paint'");
            }
        }
    }

    public function testLucideSvgsRemainCurrentColorCompatible(): void
    {
        foreach ($this->files('lucide') as $file) {
            $svg = (string) file_get_contents($file);
            $name = basename($file);
            preg_match_all('/(?:fill|stroke)="([^"]*)"/', $svg, $m);
            foreach ($m[1] as $paint) {
                self::assertContains($paint, ['currentColor', 'none'], "$name carries fixed paint '$paint'");
            }
        }
    }

    public function testVendoredManifestRecordsVersionsAndNormalizationRule(): void
    {
        $md = (string) file_get_contents($this->root() . '/VENDORED.md');
        self::assertStringContainsString('lucide', $md);
        self::assertStringContainsString('simple-icons', $md);
        self::assertMatchesRegularExpression('/\bv?\d+\.\d+/', $md, 'no pinned upstream version recorded');
        self::assertStringContainsString('fill="currentColor"', $md, 'normalization rule not documented');
    }

    /**
     * Strict curation (plan review pin): the shipped brand set must equal the
     * manifest's curated list EXACTLY — a missing file means a slug typo or a
     * silent drop at vendoring; an extra file means an undocumented addition.
     */
    public function testShippedBrandSetMatchesTheManifestExactly(): void
    {
        $md = (string) file_get_contents($this->root() . '/VENDORED.md');
        // The "## Curated brands" section lists one slug per line until the next heading.
        self::assertSame(1, preg_match('/## Curated brands\n(.*?)\n## /s', $md, $m), 'curated list section missing');
        preg_match_all('/^[a-z0-9-]+$/m', trim($m[1]), $slugs);
        $manifest = $slugs[0];
        sort($manifest);
        self::assertNotEmpty($manifest, 'curated list is empty');

        $shipped = array_map(
            static fn(string $f): string => basename($f, '.svg'),
            $this->files('brands'),
        );
        sort($shipped);
        self::assertSame($manifest, $shipped);
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Integration/Render/IconAssetsTest.php`
Expected: PASS. A failure here means Task 1's vendoring/normalization is incomplete — fix the assets, not the test. (If a Lucide file legitimately carries another paint value — e.g. a fixed `fill` on an inner shape — inspect it; if benign, extend the allowed list deliberately and note it in VENDORED.md.)

---

### Task 6: Full gates + stage

- [ ] **Step 1: Lemma suite + phpcs**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit && composer run phpcs`
Expected: ~1320+ tests OK; phpcs clean.

- [ ] **Step 2: Executable checks from plan review (explicit sweep)**

All covered by tests — verify each maps green:

| Check | Test |
| --- | --- |
| `icon('activity')` renders inline SVG, not escaped text | `testFeatureIconRendersInlineSvgForLucideNames` (asserts no `&lt;svg`) |
| `icon('missing') ?? '<img onerror=x>'` escapes the fallback | `testFeatureIconFallsBackToEscapedTextForNonNames` (hostile case) |
| DB template using `icon()` lints clean under `CACHE_VERSION = 6` | `testBlocksJoinsTheSandboxAllowlistWithACacheVersionBump` |
| `../x`, `brand:../x`, `Brand:github`, `brand:github.svg` → null | `IconSetTest::testInvalidAndUnknownNamesReturnNull` |
| Brand SVGs have `fill="currentColor"`, no fixed paint | `IconAssetsTest::testBrandSvgsAreNormalizedToCurrentColor` |
| Lucide SVGs remain currentColor-compatible | `IconAssetsTest::testLucideSvgsRemainCurrentColorCompatible` |
| VENDORED.md records versions + normalization rule | `IconAssetsTest::testVendoredManifestRecordsVersionsAndNormalizationRule` |

- [ ] **Step 3: CHANGELOG**

Add under `## [Unreleased]` in `CHANGELOG.md` (Added section):

```markdown
- `icon(name)` Twig function: vendored inline SVG icons — full Lucide set by
  default, curated Simple Icons under `brand:` (normalized to `currentColor`);
  returns `Twig\Markup` so text fallbacks stay escaped; unknown/invalid names
  return null. Sandbox `FUNCTIONS` allowlist + `CACHE_VERSION` 6.
- Feature block: `icon` field renders Lucide SVGs (`{{ icon(data.icon) ?? data.icon }}`)
  with the legacy free-text/emoji fallback preserved; Lucide-only `pattern` on
  newly seeded installs.
```

- [ ] **Step 4: Stage everything (NO commit — wait for "commit all")**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add packages/lemma-render/resources/icons \
        packages/lemma-render/src/Templates/IconSet.php \
        packages/lemma-render/src/Templates/TemplatePolicy.php \
        packages/lemma-render/src/RenderContextExtension.php \
        packages/lemma-render/src/LemmaRenderServiceProvider.php \
        packages/lemma-render/themes/default/templates/blocks/feature.twig \
        packages/lemma-render/themes/default/assets/blocks.css \
        app/Content/Blocks/StarterBlockTypes.php \
        tests/Unit/Render/IconSetTest.php \
        tests/Integration/Render/IconAssetsTest.php \
        tests/Integration/Render/BlockLibraryRenderTest.php \
        tests/Integration/Render/BlocksRenderingTest.php \
        docs/superpowers/specs/2026-07-04-icon-library-design.md \
        docs/superpowers/plans/2026-07-04-icon-library.md \
        CHANGELOG.md
git status --short
```
