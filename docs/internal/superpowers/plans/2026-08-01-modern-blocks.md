# Modern Blocks (Hero Slider, Animated Text, Gallery) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the hero-slider carousel preset, the `animated_text` block, and the `gallery` block, with block behaviors as lazily-loaded per-block fingerprinted assets outside the universal runtime.

**Architecture:** `block_script(name)` (closed catalog) emits deferred script tags for per-block assets that `RuntimeAssetMap` already fingerprints and serves; each asset self-guards, registers a ThalloRuntime module, and self-enhances after registration so late execution is safe. Validation caps animated-text alternatives at the save boundary (tabs-cap precedent); templates carry the no-JS floors; the hero preset is CSS + two schema fields.

**Tech Stack:** PHP 8.3 (Glueful, NOT Laravel), Twig 3, PHPUnit 10 (`App\Tests\...` extends `AppTestCase`), vanilla ES5-style JS assets (parse+execute under Node ≥ 18 — tests execute served bytes with hand-stubbed DOMs), Playwright (chromium, `tools/runtime-browser/`), default-theme CSS.

**Spec:** `docs/internal/superpowers/specs/2026-08-01-modern-blocks-design.md` — read it first; pinned rules and the review amendments govern.

## Global Constraints

- Universal `runtime.js` is UNTOUCHED (gzip budget 14,336 stays); block behaviors live ONLY in `packages/thallo-render/runtime/block-animated-text.js` and `block-gallery.js`, each with its own 3,072-byte gzip budget.
- `block_script()` catalog is a hardcoded const of exactly `animated-text` and `gallery`; unknown names return empty. It is DB-template vocabulary.
- ONE policy change in this feature: `TemplatePolicy::FUNCTIONS` += `block_script`; `CACHE_VERSION` 17 → 18 (Task 1 only; update the hardcoded pin in `tests/Integration/Render/BlocksRenderingTest.php`; `TwigCompletionsParityTest` forces the `twigCompletions.ts` sync in the same commit).
- Asset correctness guard: each asset's exactly-once guard burns ONLY after `ThalloRuntime.register()` succeeds; success immediately calls `ThalloRuntime.enhance(document.documentElement)`; absent runtime / failed registration leaves the guard unset (retry-able) and the static floor intact.
- Degradation matrix (all paths byte-correct static output): no-JS, reduced motion, canvas, failed asset load, unsupported/throwing `<dialog>`.
- Both new templates must lint clean (they enter `ShippedTemplatesLintGateTest` automatically).
- `enhance()` teardown contract: return a complete cleanup; `false` for structural no-ops.
- Rotation: at most 5 validated alternatives, 1000ms interval, ONE finite cycle settling on the final alternative (≤4s < 5s bound).
- Gallery lightbox opt-out reads `data.lightbox ?? true` — NEVER `|default(true)`.
- Run PHP tests from repo root (`vendor/bin/phpunit --filter <Class>`; Node required — confirm runtime tests RAN, not skipped). Playwright from `tools/runtime-browser`.
- Commits: exact paths only (`git add <paths>` + `git commit --only <paths>`), NEVER `git add -A` , NO attribution trailers.

---

### Task 1: `block_script()` emitter + policy v18 + completions sync

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (function registration near line 204; method near `runtimeScript()` ~line 330; reset in `resetPerRenderState()` ~line 973)
- Modify: `packages/thallo-render/src/Templates/TemplatePolicy.php` (FUNCTIONS + CACHE_VERSION 17→18 + comment)
- Modify: `tests/Integration/Render/BlocksRenderingTest.php` (hardcoded CACHE_VERSION pin 17→18)
- Modify: `admin/src/pages/templates/components/twigCompletions.ts` (FUNCTIONS += `block_script`)
- Test: `tests/Integration/Render/BlockScriptTest.php` (create), `tests/Integration/Render/TemplateLinterTest.php` (extend)

**Interfaces:**
- Produces: Twig function `block_script(name)` returning `\Twig\Markup` — `<script defer src="/_thallo/runtime/block-{name}.js"></script>` once per render per name, `''` for unknown names or repeats. `RenderContextExtension::BLOCK_SCRIPT_ASSETS = ['animated-text', 'gallery']` (public const — Task 5/6 budget test iterates it). Tasks 4 templates call it.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Render/BlockScriptTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/** block_script() — closed catalog + per-render dedupe (modern-blocks spec §1). */
final class BlockScriptTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testClosedCatalogEmitsOncePerRenderAndRearmsOnReset(): void
    {
        $ext = $this->ext();
        $ext->resetPerRenderState();

        $tag = (string) $ext->blockScript('gallery');
        self::assertSame(
            '<script defer src="/_thallo/runtime/block-gallery.js"></script>',
            $tag,
        );
        // Dedupe within one render.
        self::assertSame('', (string) $ext->blockScript('gallery'));
        // Independent name still emits.
        self::assertSame(
            '<script defer src="/_thallo/runtime/block-animated-text.js"></script>',
            (string) $ext->blockScript('animated-text'),
        );
        // Closed catalog: unknown names (incl. traversal shapes) emit nothing.
        self::assertSame('', (string) $ext->blockScript('shop'));
        self::assertSame('', (string) $ext->blockScript('../runtime'));
        self::assertSame('', (string) $ext->blockScript(''));

        // Fragment boundary: reset re-arms emission (spec §1 — dedupe is a bandwidth
        // optimization; the asset's own IIFE guard is the correctness authority).
        $ext->resetPerRenderState();
        self::assertNotSame('', (string) $ext->blockScript('gallery'));
    }

    public function testEmittedAssetsExistAndAreServedFingerprinted(): void
    {
        // Every catalog entry must be a real pack asset RuntimeAssetMap can serve.
        $map = $this->container()->get(\Thallo\Render\Templates\RuntimeAssetMap::class);
        foreach (RenderContextExtension::BLOCK_SCRIPT_ASSETS as $name) {
            self::assertNotNull(
                $map->fingerprintedName('block-' . $name . '.js'),
                "block-{$name}.js missing from the runtime asset map",
            );
        }
    }
}
```

(Note: `testEmittedAssetsExistAndAreServedFingerprinted` stays RED until Tasks 5/6 add the asset files — mark it `#[Depends]`-free but EXPECT it red until then; alternatively commit it in Task 5. Decision: move that one test method to Task 5's step so Task 1 lands green. Keep only the catalog/dedupe test here.)

`TemplateLinterTest` additions:

```php
public function testBlockScriptIsAllowlisted(): void
{
    self::assertSame([], $this->linter()->lint("{{ block_script('gallery') }}"));
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter "BlockScriptTest|TemplateLinterTest"`
Expected: FAIL — `blockScript` undefined; linter denies `block_script`.
Also run: `vendor/bin/phpunit --filter TwigCompletionsParityTest` AFTER the policy edit in Step 3 but BEFORE the TS sync — expected RED (that's the parity gate working); then GREEN after the sync.

- [ ] **Step 3: Implement**

1. `RenderContextExtension.php` — const + property + method + registration + reset:

```php
    /** Closed block-asset catalog (modern-blocks spec §1) — block_script() is
     *  DB-template vocabulary; only these names ever resolve to a script tag. */
    public const BLOCK_SCRIPT_ASSETS = ['animated-text', 'gallery'];

    /** @var array<string,bool> per-render emitted set (bandwidth dedupe only —
     *  the asset's own exactly-once IIFE guard is the correctness authority). */
    private array $emittedBlockScripts = [];
```

```php
    /**
     * Deferred script tag for a per-block runtime asset (modern-blocks spec §1):
     * closed catalog, once per render per name. Markup return — the tag is the
     * value; autoescape never mangles it. Fragment renders reset independently
     * (EntryBlocksRenderer), so a page may carry a duplicate tag — safe, because
     * each asset self-guards.
     */
    public function blockScript(string $name): \Twig\Markup
    {
        if (!in_array($name, self::BLOCK_SCRIPT_ASSETS, true) || isset($this->emittedBlockScripts[$name])) {
            return new \Twig\Markup('', 'UTF-8');
        }
        $this->emittedBlockScripts[$name] = true;
        return new \Twig\Markup(
            '<script defer src="/_thallo/runtime/block-' . $name . '.js"></script>',
            'UTF-8',
        );
    }
```

Register after `runtime_script` (line ~204): `new TwigFunction('block_script', $this->blockScript(...)),`
In `resetPerRenderState()` add: `$this->emittedBlockScripts = [];`

2. `TemplatePolicy.php`: FUNCTIONS gains `'block_script'` (place after `'json_script'`); CACHE_VERSION comment gains a line (`// bumped: block_script joined the allowlist (modern-blocks spec §1 — closed-catalog per-block asset emission)`) and `public const CACHE_VERSION = 18;`.

3. `BlocksRenderingTest.php`: update the hardcoded `17` pin to `18` (grep the file for `17`).

4. `twigCompletions.ts`: FUNCTIONS array gains `'block_script'` in the same position as the policy const (after `'json_script'`).

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit --filter "BlockScriptTest|TemplateLinterTest|TwigCompletionsParityTest|BlocksRenderingTest|DbTemplateLoaderTest|DbTemplatesPipelineTest"` and `cd admin && npm test -- --run templatesPage && npm run type-check && npm run lint && cd ..`
Expected: ALL PASS (the Db* suites prove the v18 recompile).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/src/RenderContextExtension.php packages/thallo-render/src/Templates/TemplatePolicy.php tests/Integration/Render/BlocksRenderingTest.php admin/src/pages/templates/components/twigCompletions.ts tests/Integration/Render/BlockScriptTest.php tests/Integration/Render/TemplateLinterTest.php
git commit --only packages/thallo-render/src/RenderContextExtension.php packages/thallo-render/src/Templates/TemplatePolicy.php tests/Integration/Render/BlocksRenderingTest.php admin/src/pages/templates/components/twigCompletions.ts tests/Integration/Render/BlockScriptTest.php tests/Integration/Render/TemplateLinterTest.php -m "feat(render): block_script() — closed-catalog lazy block-asset emission (policy v18)"
```

---

### Task 2: animated_text save-time validation (5-alternative cap + normalization)

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php` (rule after the tabs cap, lines 418-428; new public static normalizer)
- Test: extend the suite that covers the tabs cap (locate it: `grep -rn "at most 12 items" tests/` — extend that file in its own idiom)

**Interfaces:**
- Produces: `FieldValidator::normalizeRotateWords(string $raw): array` (public static — Task 4's template-parity test and docs reference it as THE contract) and the save-time rule: >5 normalized alternatives → error at `{path}.rotate_words` with message `animated text supports at most 5 alternatives`.

- [ ] **Step 1: Write the failing tests** (in the located tabs-cap suite's idiom; assertions are the contract)

```php
public function testAnimatedTextRotateWordsCapAndNormalization(): void
{
    // 6 alternatives → rejected with a field-level error (never truncated).
    // Build a valid entry payload whose blocks field contains one block:
    // ['type' => 'animated_text', 'data' => ['rotate_words' => "a\nb\nc\nd\ne\nf"]]
    // → expect error key ending ".rotate_words", message 'animated text supports at most 5 alternatives'.

    // CRLF + blanks normalize identically: "a\r\nb\r\rc\n\n  \nd\ne" → 5 clean values → VALID.

    // Exactly 5 → valid. Empty rotate_words → valid (rotation absent).
}

public function testNormalizeRotateWordsContract(): void
{
    self::assertSame(['a', 'b'], \App\Content\Validation\FieldValidator::normalizeRotateWords("a\r\nb"));
    self::assertSame(['a', 'b'], \App\Content\Validation\FieldValidator::normalizeRotateWords("a\rb"));
    self::assertSame(['a phrase', 'b'], \App\Content\Validation\FieldValidator::normalizeRotateWords("  a phrase  \n\n b \n   "));
    self::assertSame([], \App\Content\Validation\FieldValidator::normalizeRotateWords("  \n \r\n "));
}
```

- [ ] **Step 2: Run to verify failure** — the normalizer is undefined; the cap case passes validation (wrongly).

- [ ] **Step 3: Implement**

Normalizer (in `FieldValidator`):

```php
    /**
     * THE rotate_words interpretation contract (modern-blocks spec §3): CRLF/CR → LF,
     * split on LF, trim, drop blanks. The validator (cap) and the template (render)
     * MUST both consume this exact semantics — a parity test pins it.
     *
     * @return list<string>
     */
    public static function normalizeRotateWords(string $raw): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
```

Rule, immediately after the tabs cap block (`FieldValidator.php:428`), same unconditional posture (pre-launch, zero animated_text blocks exist):

```php
            // Animated-text authoring cap (modern-blocks spec §3): one finite rotation
            // cycle must complete within 5s at 1000ms per step, so at most 5 normalized
            // alternatives may exist. Rejected, never truncated — the template renders
            // the complete accepted list.
            if ($type === 'animated_text') {
                $raw = $block['data']['rotate_words'] ?? null;
                if (is_string($raw) && count(self::normalizeRotateWords($raw)) > 5) {
                    $errors["{$path}.rotate_words"] = 'animated text supports at most 5 alternatives';
                    continue;
                }
            }
```

- [ ] **Step 4: Run** — the extended suite + `vendor/bin/phpunit --filter FieldValidator` (whatever classes match) green.

- [ ] **Step 5: Commit** (exact paths: FieldValidator.php + the test file)
Message: `feat(content): animated_text rotate_words — 5-alternative cap with one normalization contract`

---

### Task 3: Seeder — two new block types + carousel `style` + hero `heading_level`

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php`
- Test: locate and extend the seeder's existing test (grep `blocks:seed`/`StarterBlockTypes` under `tests/`); assert the new types/fields appear with exact enums/defaults and that seeding stays idempotent.

**Interfaces:**
- Produces: block types `animated_text` (Content) and `gallery` (Media); carousel `style` enum; hero `heading_level` enum. Task 4 templates consume these field names verbatim.

- [ ] **Step 1: Failing test** — assert `schemasBySlug()` (or the seeder test's existing accessor) contains `animated_text` and `gallery` with the exact schemas below; carousel has `style`; hero has `heading_level`. ALSO extend the `SyncBlockTypesCommand` test (locate it; same idiom): an install seeded from the PRE-change definitions receives `carousel.style` and `hero.heading_level` after sync (spec §2 — existing installs get the field additions), and sync remains idempotent on a second run.

- [ ] **Step 2: Run** — red.

- [ ] **Step 3: Implement** — add to `StarterBlockTypes.php`:

Carousel schema (line ~369) append: `['name' => 'style', 'type' => 'enum', 'enum' => ['default', 'hero']],`
Hero schema (line ~222) append: `['name' => 'heading_level', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3']],`

New entries (Content section for animated_text; Media section for gallery):

```php
            ['slug' => 'animated_text', 'label' => 'Animated text', 'icon' => 'i-lucide-type',
                'category' => 'Content',
                'description' => 'A heading with a reveal effect and an optional rotating word.',
                'schema' => [
                    ['name' => 'prefix', 'type' => 'string'],
                    // One alternative per line (phrases allowed) — at most 5 (FieldValidator cap).
                    ['name' => 'rotate_words', 'type' => 'text'],
                    ['name' => 'suffix', 'type' => 'string'],
                    ['name' => 'effect', 'type' => 'enum', 'enum' => ['fade', 'slide-up', 'blur']],
                    ['name' => 'tag', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3', 'p']],
                ]],
```

```php
            ['slug' => 'gallery', 'label' => 'Gallery', 'icon' => 'i-lucide-images',
                'category' => 'Media',
                'description' => 'A responsive image grid with an optional lightbox.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks',
                     'block_types' => ['image'], 'enforce_block_types' => true],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
                    ['name' => 'aspect', 'type' => 'enum', 'enum' => ['natural', 'square', 'landscape']],
                    ['name' => 'lightbox', 'type' => 'boolean'],
                ]],
```

- [ ] **Step 4: Run** — seeder suite green + `vendor/bin/phpunit --filter "FieldValidator|Seed|BlockType"` green (the Task 2 cap now has a real registered type in strict runs).

- [ ] **Step 5: Commit** (exact paths). Message: `feat(content): seed animated_text + gallery block types; carousel style; hero heading_level`

---

### Task 4: Templates + CSS (floors, aria, hero contract)

**Files:**
- Create: `packages/thallo-render/themes/default/templates/blocks/animated_text.twig`, `.../blocks/gallery.twig`
- Modify: `.../blocks/hero.twig` (heading_level, line 22), `.../blocks/carousel.twig` (style modifier, line 1)
- Modify: `packages/thallo-render/themes/default/assets/blocks.css`
- Test: `tests/Integration/Render/StarterTemplatesTest.php` (extend — follow its existing render-assert idiom), plus a validator/template normalization parity test (same file or the Task 2 suite)

**Interfaces:**
- Consumes: Task 1's `block_script`, Task 2's `normalizeRotateWords` semantics, Task 3's field names.
- Produces: the class contract Tasks 5/6 JS selects on: `.thallo-block-animated_text` root with `[data-effect]`, `.thallo-block-animated_text__rotate` span stack with `.thallo-block-animated_text__word` children (first one `--active`); `.thallo-block-gallery` root with `[data-lightbox]`, `.thallo-block-gallery__item` anchors.

- [ ] **Step 1: Failing tests** (StarterTemplatesTest idiom — render each block via the real pipeline):
  - animated_text with `prefix "Build" / rotate_words "fast\nwell" / suffix "with Thallo"`: root tag defaults sensibly (`h2` when tag empty), `aria-label="Build fast with Thallo"`, rotate stack `aria-hidden="true"`, BOTH words present stacked, first word active, no `--prepared` class in server output (JS-only), `block_script` tag present exactly once for two animated_text blocks in one render.
  - animated_text with empty rotate_words: no rotate stack, no script tag requirement (template still calls block_script — acceptable; assert prefix+suffix render).
  - CRLF parity: rotate_words `"a\r\nb\r\rc"` renders exactly the 3 normalized words (call `FieldValidator::normalizeRotateWords` in the test to compute the expectation — THE parity assertion).
  - gallery: two image children + one unresolvable → exactly 2 anchors, each `<a href>` to the full blob with aria-label; `data-lightbox="1"` by default; `lightbox: false` → `data-lightbox="0"` (the `?? true` pin — an authored false must survive); columns/aspect modifiers on the root.
  - hero: default renders `<h1>` (unchanged); `heading_level: 'h2'` renders `<h2>`; carousel `style: 'hero'` root carries `thallo-block-carousel--hero`.

- [ ] **Step 2: Run** — red (templates missing).

- [ ] **Step 3: Implement**

`animated_text.twig` (lint-safe vocabulary only; dynamic heading via explicit branches):

```twig
{# animated_text — reveal + finite word rotation (modern-blocks spec §3). Static floor:
   everything visible; JS (block-animated-text.js) adds --prepared/--in-view and rotates.
   Width reservation: ALL alternatives stacked in one grid cell; inactive ones
   visibility:hidden (CSS); the browser reserves the true max rendered width. #}
{% set words = data.rotate_words|default('')|replace({"\r\n": "\n", "\r": "\n"})|split("\n") %}
{% set clean = [] %}
{% for w in words %}{% if w|trim != '' %}{% set clean = clean|merge([w|trim]) %}{% endif %}{% endfor %}
{% set tag = data.tag|default('h2') %}
{% set label = ((data.prefix|default('')) ~ ' ' ~ (clean|first|default('')) ~ ' ' ~ (data.suffix|default('')))|trim %}
{% set inner %}
  {{- data.prefix|default('')|editable_text('prefix') }}
  {%- if clean is not empty %}
  <span class="thallo-block-animated_text__rotate" aria-hidden="true">
    {%- for w in clean %}
    <span class="thallo-block-animated_text__word{% if loop.first %} thallo-block-animated_text__word--active{% endif %}">{{ w }}</span>
    {%- endfor %}
  </span>
  {%- endif %}
  {{ data.suffix|default('')|editable_text('suffix') -}}
{% endset %}
{% if tag == 'h1' %}<h1 class="thallo-block thallo-block-animated_text thallo-block-animated_text--{{ data.effect|default('fade') }}" data-effect="{{ data.effect|default('fade') }}" aria-label="{{ label }}">{{ inner }}</h1>
{% elseif tag == 'h3' %}<h3 class="thallo-block thallo-block-animated_text thallo-block-animated_text--{{ data.effect|default('fade') }}" data-effect="{{ data.effect|default('fade') }}" aria-label="{{ label }}">{{ inner }}</h3>
{% elseif tag == 'p' %}<p class="thallo-block thallo-block-animated_text thallo-block-animated_text--{{ data.effect|default('fade') }}" data-effect="{{ data.effect|default('fade') }}" aria-label="{{ label }}">{{ inner }}</p>
{% else %}<h2 class="thallo-block thallo-block-animated_text thallo-block-animated_text--{{ data.effect|default('fade') }}" data-effect="{{ data.effect|default('fade') }}" aria-label="{{ label }}">{{ inner }}</h2>
{% endif %}
{{ block_script('animated-text') }}
```

(CHECK during implementation: `{% set x %}...{% endset %}` compiles to a `SetNode` with a capture body — confirm the linter's NODE_CLASSES accept it (the `set` tag is allowlisted; capture bodies produce `PrintNode`s etc. already allowed). If the capture form trips the linter, restructure to render `inner` inline in each branch — verbose but lint-safe. `editable_text` is allowlisted; if the string-context nesting inside `{% set %}` misbehaves with annotation markers, drop `editable_text` for prefix/suffix and render plain — note the decision.)

`gallery.twig`:

```twig
{# gallery — responsive image grid; anchors to full-size blobs ARE the no-JS floor
   (modern-blocks spec §4). Lightbox is progressive (block-gallery.js). Resolve-first:
   unresolved/non-image items are omitted entirely — no dead anchors. #}
{% set items = data.items|default([]) %}
{% set lightbox = data.lightbox ?? true %}
<div class="thallo-block thallo-block-gallery thallo-block-gallery--cols-{{ data.columns|default('3') }} thallo-block-gallery--{{ data.aspect|default('natural') }}" data-lightbox="{{ lightbox ? '1' : '0' }}">
  {%- set shown = 0 %}
  {%- for item in items %}
    {%- set full = media(item.data.image|default(null)) %}
    {%- if full %}
      {%- set shown = shown + 1 %}
  <a class="thallo-block-gallery__item" href="{{ full }}" aria-label="{{ item.data.alt|default('Image ' ~ shown) }}">{{ blocks([item]) }}</a>
    {%- endif %}
  {%- endfor %}
</div>
{{ block_script('gallery') }}
```

(CHECK: `media()` with a null/invalid uuid returns null (verify signature — it takes a string; guard with `item.data.image is defined and item.data.image` before calling if needed). `blocks([item])` re-renders the image block through the hierarchy — the established pattern.)

`hero.twig` line 22 area — replace the fixed `<h1>` with branches on `data.heading_level|default('h1')` (`h1`/`h2`/`h3`), keeping the exact class and `editable_text` call on each branch.

`carousel.twig` line 1 — extend the class list: `{% if data.style|default('default') == 'hero' %} thallo-block-carousel--hero{% endif %}` appended inside the existing class attribute.

`blocks.css` additions (end of file, plus the hero rules placed AFTER the existing `--per-2`/`--per-3` rules):

```css
/* animated_text (modern-blocks spec §3): static floor is fully visible. Reveal CSS
   engages ONLY under --prepared (JS adds it after IO is confirmed usable); the word
   stack reserves true max width via same-cell grid. */
:where(.thallo-block-animated_text__rotate) { display: inline-grid; vertical-align: baseline; }
.thallo-block-animated_text__word { grid-area: 1 / 1; visibility: hidden; }
.thallo-block-animated_text__word--active { visibility: visible; }
.thallo-block-animated_text--prepared:not(.thallo-block-animated_text--in-view) { opacity: 0; }
.thallo-block-animated_text--prepared.thallo-block-animated_text--in-view { animation: thallo-at-fade 500ms ease-out both; }
.thallo-block-animated_text--prepared.thallo-block-animated_text--slide-up.thallo-block-animated_text--in-view { animation-name: thallo-at-slide-up; }
.thallo-block-animated_text--prepared.thallo-block-animated_text--blur.thallo-block-animated_text--in-view { animation-name: thallo-at-blur; }
@keyframes thallo-at-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes thallo-at-slide-up { from { opacity: 0; transform: translateY(0.6em); } to { opacity: 1; transform: none; } }
@keyframes thallo-at-blur { from { opacity: 0; filter: blur(8px); } to { opacity: 1; filter: none; } }
@media (prefers-reduced-motion: reduce) {
  .thallo-block-animated_text--prepared { opacity: 1 !important; animation: none !important; }
}

/* gallery (modern-blocks spec §4): the grid + real anchors are the no-JS floor. */
.thallo-block-gallery { display: grid; gap: var(--space-2); }
.thallo-block-gallery--cols-2 { grid-template-columns: repeat(2, 1fr); }
.thallo-block-gallery--cols-3 { grid-template-columns: repeat(3, 1fr); }
.thallo-block-gallery--cols-4 { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 40rem) { .thallo-block-gallery { grid-template-columns: repeat(2, 1fr); } }
.thallo-block-gallery__item { display: block; }
.thallo-block-gallery__item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.thallo-block-gallery--square .thallo-block-gallery__item { aspect-ratio: 1; }
.thallo-block-gallery--landscape .thallo-block-gallery__item { aspect-ratio: 3 / 2; }
.thallo-block-gallery__dialog { border: 0; padding: 0; background: transparent; max-width: min(92vw, 70rem); }
.thallo-block-gallery__dialog::backdrop { background: rgb(0 0 0 / 0.8); }
.thallo-block-gallery__dialog img { max-width: 100%; max-height: 85vh; display: block; margin-inline: auto; }
@media (prefers-reduced-motion: no-preference) {
  .thallo-block-gallery__dialog[open] { animation: thallo-at-fade 150ms ease-out; }
}
```

Hero contract (the six spec points — place the one-slide rule AFTER the `--per-2`/`--per-3` rules in the carousel section; read that section first and mirror its selector idiom):

```css
/* Hero slider preset (modern-blocks spec §2): presentation only — mechanics inherit. */
.thallo-block-carousel--hero { max-width: none; padding-inline: 0; }                     /* 1: full-bleed */
.thallo-block-carousel--hero .thallo-block-carousel__track > * { flex: 0 0 100%; }       /* 2: one per view (after --per-N) */
.thallo-block-carousel--hero .thallo-block-hero { display: grid; min-height: 60vh; }     /* 3: stacked grid */
.thallo-block-carousel--hero .thallo-block-hero > * { grid-area: 1 / 1; }
.thallo-block-carousel--hero .thallo-block-hero__media { z-index: 0; }
.thallo-block-carousel--hero .thallo-block-hero__media img { width: 100%; height: 100%; object-fit: cover; }
.thallo-block-carousel--hero .thallo-block-hero__content { z-index: 2; align-self: end; padding: var(--space-6); }
.thallo-block-carousel--hero .thallo-block-hero::after {                                 /* 4: scrim between media and text */
  content: ''; grid-area: 1 / 1; z-index: 1; align-self: stretch;
  background: linear-gradient(to top, rgb(0 0 0 / 0.65), transparent 55%);
}
.thallo-block-carousel--hero .thallo-block-hero__content,
.thallo-block-carousel--hero .thallo-block-hero__content :is(h1, h2, h3, p) {
  color: var(--hero-overlay-ink, #fff);                                                  /* 5: contrast tokens */
}
/* 6: no-image fallback — a hero slide without media keeps the standard hero background. */
```

(READ `hero.twig` + the existing hero CSS first and adapt the child-selector names (`__media`/`__content`) to the REAL class names — the structure above is the contract, the selectors must match the actual template. If the hero block has no distinct media/content wrappers, adapt with the template's real structure and note it.)

- [ ] **Step 4: Run** — `vendor/bin/phpunit --filter "StarterTemplatesTest|ShippedTemplatesLintGateTest|BlocksRenderingTest|BlockScriptTest"` green (the lint gate now sweeps the two new templates — they MUST pass; if a construct is denied, restructure the template, never the policy).

- [ ] **Step 5: Commit** (exact paths: 2 new templates, hero.twig, carousel.twig, blocks.css, StarterTemplatesTest.php + parity test file). Message: `feat(render): animated_text + gallery templates, hero slider CSS contract`

---

### Task 5: `block-animated-text.js` + Node harness + per-asset budget test

**Files:**
- Create: `packages/thallo-render/runtime/block-animated-text.js`
- Test: `tests/Integration/Render/AnimatedTextAssetTest.php` (create; mirror `RuntimeElementsBridgeTest`'s Node harness skeleton — eval `runtime.js` THEN the asset bytes), `tests/Integration/Render/BlockAssetBudgetTest.php` (create), plus move `testEmittedAssetsExistAndAreServedFingerprinted` here (it goes green when Task 6 adds the gallery asset — write it to iterate only over assets that exist on disk? NO: keep it strict and land it in Task 6's commit instead).

**Interfaces:**
- Consumes: Task 4's class contract (`.thallo-block-animated_text`, `__rotate`, `__word`, `--active`, `--prepared`, `--in-view`, `[data-effect]`).
- Produces: the module `animated-text`; `BlockAssetBudgetTest` iterating `RenderContextExtension::BLOCK_SCRIPT_ASSETS` with a 3,072-byte gzip ceiling per asset.

- [ ] **Step 1: Write the asset** (`block-animated-text.js`, ES5):

```js
/* Thallo block asset: animated_text (modern-blocks spec §3). Loaded lazily via
   block_script('animated-text'); may execute MORE than once (fragment renders emit
   duplicate tags) and possibly BEFORE ThalloRuntime exists — the guard burns only
   after registration succeeds, and success immediately self-enhances so late
   registration (after the runtime's boot pass) still enhances existing blocks. */
(function () {
  'use strict';
  if (window.__thalloBlockAnimatedText) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; } // retry on a later execution

  function enhance(root) {
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) { return false; } // static floor is already correct — nothing to do
    if (typeof IntersectionObserver !== 'function') { return false; }

    var words = [];
    var stack = root.querySelector('.thallo-block-animated_text__rotate');
    if (stack) {
      var all = stack.querySelectorAll('.thallo-block-animated_text__word');
      for (var i = 0; i < all.length; i++) { words.push(all[i]); }
    }

    var undo = [];
    var timer = null;
    var inView = false;
    var index = 0;
    var done = words.length < 2; // nothing to rotate

    function setActive(n) {
      for (var k = 0; k < words.length; k++) {
        if (k === n) { words[k].classList.add('thallo-block-animated_text__word--active'); }
        else { words[k].classList.remove('thallo-block-animated_text__word--active'); }
      }
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function maybeRun() {
      if (done || !inView || document.hidden || timer) { return; }
      timer = setInterval(function () {
        index++;
        setActive(index);
        if (index >= words.length - 1) { done = true; stop(); } // ONE cycle, settle on last
      }, 1000);
    }

    try {
      var io = new IntersectionObserver(function (entries) {
        for (var e = 0; e < entries.length; e++) { inView = entries[e].isIntersecting; }
        if (inView && !root.classList.contains('thallo-block-animated_text--in-view')) {
          root.classList.add('thallo-block-animated_text--in-view'); // reveal, once
        }
        if (inView) { maybeRun(); } else { stop(); }
      });
      io.observe(root);
      undo.push(function () { io.disconnect(); });

      var onVis = function () { if (document.hidden) { stop(); } else { maybeRun(); } };
      document.addEventListener('visibilitychange', onVis);
      undo.push(function () { document.removeEventListener('visibilitychange', onVis); });

      // Prepared LAST (fail-safe handoff, spec §3): reveal CSS engages only now.
      root.classList.add('thallo-block-animated_text--prepared');
      undo.push(function () {
        root.classList.remove('thallo-block-animated_text--prepared');
        root.classList.remove('thallo-block-animated_text--in-view');
      });
    } catch (err) {
      stop();
      for (var u = undo.length - 1; u >= 0; u--) { try { undo[u](); } catch (e2) {} }
      throw err; // containment leaves the block unmarked; static floor intact
    }

    return function () {
      stop();
      setActive(0);
      for (var u2 = undo.length - 1; u2 >= 0; u2--) { undo[u2](); }
    };
  }

  try {
    RT.register('animated-text', { selector: '.thallo-block-animated_text', enhance: enhance });
  } catch (err) {
    return; // duplicate registration (another execution won) — its guard is set
  }
  window.__thalloBlockAnimatedText = true;
  RT.enhance(document.documentElement); // late-registration correctness authority
})();
```

- [ ] **Step 2: Node harness test** (`AnimatedTextAssetTest.php` — RuntimeElementsBridgeTest skeleton; harness evals `runtime.js` bytes, then the asset bytes, with the stub DOM + `matchMedia`/`IntersectionObserver` stubs). Cases: double-eval of the asset registers once (no throw, guard set once); eval with `window.ThalloRuntime` deleted → guard NOT set, static untouched, re-eval after restoring runtime works (retry path); registration after a completed boot enhances an existing block (self-enhance); reveal class added once on intersection; rotation completes exactly `words.length - 1` steps then stops (timers stub) and settles on last word; offscreen/hidden pause + resume; reduced-motion → enhance returns false, no classes; cleanup restores first word active + removes classes/IO/listener.

- [ ] **Step 3: Budget test** (`BlockAssetBudgetTest.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/** Per-block-asset gzip budgets (modern-blocks spec §5): 3,072 bytes each; raising
 *  one is its own reviewed decision — never a silent bump. */
final class BlockAssetBudgetTest extends AppTestCase
{
    public function testEveryBlockAssetIsWithinItsBudget(): void
    {
        $dir = $this->appContext()->getBasePath() . '/packages/thallo-render/runtime';
        foreach (RenderContextExtension::BLOCK_SCRIPT_ASSETS as $name) {
            $path = $dir . '/block-' . $name . '.js';
            self::assertFileExists($path, "catalog entry '{$name}' has no asset file");
            $gz = strlen((string) gzencode((string) file_get_contents($path), 9));
            self::assertLessThanOrEqual(
                3072,
                $gz,
                "block-{$name}.js is {$gz} bytes gzip against a 3,072-byte budget",
            );
        }
    }
}
```

(STAGING DECISION — unambiguous: in THIS task, the class iterates the local literal `['animated-text']` with the comment `// Task 6 widens this to RenderContextExtension::BLOCK_SCRIPT_ASSETS`. Task 6 replaces the literal with the const. The code block above shows the FINAL Task-6 form so both implementers see the target; transcribe it with the literal here.)

- [ ] **Step 4: Run** — `vendor/bin/phpunit --filter "AnimatedTextAssetTest|BlockAssetBudgetTest|BlockScriptTest|RuntimeCoreTest"` green, Node-executed.

- [ ] **Step 5: Commit.** Message: `feat(render): block-animated-text.js — lazy reveal + finite rotation asset`

---

### Task 6: `block-gallery.js` + Node harness

**Files:**
- Create: `packages/thallo-render/runtime/block-gallery.js`
- Modify: `tests/Integration/Render/BlockAssetBudgetTest.php` (widen to the full `BLOCK_SCRIPT_ASSETS` const), `tests/Integration/Render/BlockScriptTest.php` (add `testEmittedAssetsExistAndAreServedFingerprinted` from Task 1's deferred note)
- Test: `tests/Integration/Render/GalleryAssetTest.php` (create; same skeleton)

**Interfaces:**
- Consumes: Task 4's gallery class contract.
- Produces: the `gallery` module.

- [ ] **Step 1: Write the asset** (same guard/self-enhance frame as Task 5 — repeat it, adjusted name `__thalloBlockGallery` / module `gallery`; the enhance body):

```js
  function enhance(root) {
    if (root.getAttribute('data-lightbox') !== '1') { return false; } // opt-out: floor is final
    var anchors = [];
    var found = root.querySelectorAll('.thallo-block-gallery__item');
    for (var i = 0; i < found.length; i++) { anchors.push(found[i]); }
    if (anchors.length === 0) { return false; }

    var dialog = null;
    var current = 0;
    var lastTrigger = null;
    var undo = [];

    function supported() {
      return typeof HTMLDialogElement === 'function' &&
        typeof HTMLDialogElement.prototype.showModal === 'function';
    }
    function build() {
      var d = document.createElement('dialog');
      d.className = 'thallo-block-gallery__dialog';
      d.innerHTML =
        '<img alt="">' +
        '<div class="thallo-block-gallery__bar">' +
        '<button type="button" class="thallo-block-gallery__prev" aria-label="Previous image">‹</button>' +
        '<span class="thallo-block-gallery__status" aria-live="polite"></span>' +
        '<button type="button" class="thallo-block-gallery__next" aria-label="Next image">›</button>' +
        '<button type="button" class="thallo-block-gallery__close" aria-label="Close">×</button>' +
        '</div>';
      d.querySelector('.thallo-block-gallery__prev').addEventListener('click', function () { show(current - 1); });
      d.querySelector('.thallo-block-gallery__next').addEventListener('click', function () { show(current + 1); });
      d.querySelector('.thallo-block-gallery__close').addEventListener('click', function () { d.close(); });
      d.addEventListener('close', function () {
        if (lastTrigger && lastTrigger.focus) { lastTrigger.focus(); } // explicit focus restore
      });
      document.body.appendChild(d);
      return d;
    }
    function show(n) {
      var count = anchors.length;
      current = ((n % count) + count) % count;
      var a = anchors[current];
      var img = dialog.querySelector('img');
      img.src = a.getAttribute('href');
      img.alt = a.getAttribute('aria-label') || '';
      dialog.querySelector('.thallo-block-gallery__status').textContent =
        (current + 1) + ' of ' + count;
    }
    function onClick(e) {
      var a = e.target && e.target.closest ? e.target.closest('.thallo-block-gallery__item') : null;
      if (!a || anchors.indexOf(a) === -1) { return; }
      if (!supported()) { return; } // anchor navigates normally
      try {
        if (!dialog) { dialog = build(); }
        lastTrigger = a;
        show(anchors.indexOf(a));
        dialog.showModal(); // must SUCCEED before we cancel navigation
      } catch (err) {
        return; // construction/showModal failure: leave the click untouched
      }
      e.preventDefault();
    }

    root.addEventListener('click', onClick);
    undo.push(function () { root.removeEventListener('click', onClick); });
    undo.push(function () {
      if (dialog) {
        if (dialog.open && dialog.close) { dialog.close(); }
        if (dialog.parentNode) { dialog.parentNode.removeChild(dialog); }
        dialog = null;
      }
    });

    return function () { for (var u = undo.length - 1; u >= 0; u--) { undo[u](); } };
  }
```

(NOTE the ordering trap the spec pins: `showModal()` is called INSIDE the try and `preventDefault()` only after it returns — a throwing/unsupported dialog leaves the real anchor click to navigate.)

- [ ] **Step 2: Node harness test** (`GalleryAssetTest.php`): guard/self-enhance frame cases (as Task 5); `data-lightbox="0"` → enhance returns false, click untouched; supported-dialog stubs: first click builds one dialog, `showModal` called, `preventDefault` called, status "1 of N"; prev/next wrap; close restores focus to originating anchor; UNSUPPORTED dialog (no HTMLDialogElement stub) → no preventDefault, no dialog; `showModal` throwing → no preventDefault, dialog not left open; two galleries → independent state, one registration; cleanup removes listener + dialog node.

- [ ] **Step 3: Widen `BlockAssetBudgetTest`** to iterate `RenderContextExtension::BLOCK_SCRIPT_ASSETS`; add `testEmittedAssetsExistAndAreServedFingerprinted` (from Task 1's note) to `BlockScriptTest`.

- [ ] **Step 4: Run** — `vendor/bin/phpunit --filter "GalleryAssetTest|AnimatedTextAssetTest|BlockAssetBudgetTest|BlockScriptTest"` green, Node-executed.

- [ ] **Step 5: Commit.** Message: `feat(render): block-gallery.js — native-dialog lightbox asset`

---

### Task 7: Playwright gate extension

**Files:**
- Create: `tools/runtime-browser/fixtures/blocks.html` (animated_text markup ×2 — one in-viewport, one below the fold — plus TWO gallery instances; real CSS + real `runtime.js` with `defer` + the two block assets emitted AFTER runtime.js exactly as `block_script` would)
- Create: `tools/runtime-browser/tests/block-assets.spec.js`
- Modify: `tools/runtime-browser/README.md` (one line)

**Interfaces:** consumes shipped assets + templates' class contract.

- [ ] **Step 1: Specs** (chromium; follow the existing spec style):
  - animated text: below-fold block has no `--in-view` before scroll; scrolling it in adds the reveal; rotation settles on the LAST word after ≤5s (`page.waitForFunction` on the active word); `prefers-reduced-motion` emulation (`page.emulateMedia`) → no `--prepared` class, static text visible.
  - gallery: click thumb → real `<dialog[open]>` with backdrop; Esc closes AND focus returns to the thumbnail (`document.activeElement`); next/prev update status "n of m"; second gallery state independent; with JS disabled (context option) thumbnails navigate to the full image URL.
  - late-registration order: a fixture variant loading `runtime.js` and the block assets in the REAL deferred order proves blocks enhance on first load (marker present exactly once).

- [ ] **Step 2: Run** — `cd tools/runtime-browser && npm test` — all green (including the pre-existing 11).

- [ ] **Step 3: Commit.** Message: `test(render): browser smoke for animated-text + gallery block assets`

---

### Task 8: README recipes + full verification

**Files:**
- Modify: `packages/thallo-render/README.md`

- [ ] **Step 1: Docs** — in the blocks section: the hero-slider recipe (carousel `style=hero` + hero children; heading guidance: slides use `h2` unless the slider is the page's sole hero), the animated_text authoring notes (one alternative per line, max 5, finite single cycle, static everywhere JS can't run), the gallery notes (image children enforced, lightbox opt-out, no-JS = anchors to full images), and `block_script`'s closed catalog + the fragment/duplicate-tag posture in the "Theme runtime" section.

- [ ] **Step 2: Gates** —

```bash
vendor/bin/phpunit tests/Integration/Render
vendor/bin/phpunit   # full suite
composer boundaries
cd admin && npm test -- --run && npm run type-check && npm run lint && cd ..
cd tools/runtime-browser && npm test && cd ..
```

(Known local blocker: `composer ci`'s reset-db step fails on this machine — pre-existing Postgres ownership issue; the full `vendor/bin/phpunit` run is the sanctioned substitute. Confirm Node-backed suites RAN.)

- [ ] **Step 3: Commit.** Message: `docs(render): hero slider recipe + animated_text/gallery authoring notes`

---

## Verification (end-to-end)

1. All Task 8 gates green; `ShippedTemplatesLintGateTest` sweeps the two new templates clean; `BlockAssetBudgetTest` under 3,072 each; universal `RuntimeSizeBudgetTest` byte-identical (runtime.js untouched).
2. Manual smoke (optional): seed (`php glueful thallo:blocks:seed`), build a page with a hero slider, an animated_text, and a gallery; verify no-JS floors (disable JS), reduced-motion, the lightbox, and the canvas editor (behaviors skipped, static markup intact).
