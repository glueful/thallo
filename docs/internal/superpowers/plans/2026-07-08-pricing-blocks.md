# Pricing Blocks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add five Nuxt-UI-Pro-modeled pricing block types (`pricing_plan`, `pricing_plans`, `pricing_table`, `pricing_tier`, `pricing_feature`) to the Thallo default theme, authored inline and styled in the theme's plain-CSS BEM convention.

**Architecture:** Ordinary Thallo blocks — definitions in `StarterBlockTypes::definitions()`, Twig templates under `themes/default/templates/blocks/`, CSS appended to `themes/default/assets/blocks.css`. `pricing_plans` renders its plan children via `blocks()`; `pricing_table` renders its `pricing_tier`/`pricing_feature` children **inline** (table semantics can't come from independently-dropped child templates — same technique `accordion` uses), with minimal standalone fallback templates for the two child types.

**Tech Stack:** PHP 8.3 (block definitions), Twig (templates), plain CSS (theme tokens in `site.css`), PHPUnit integration tests. Reference: `packages/thallo-render/docs/refs.md` (Nuxt UI Pro maps) and `/Users/michaeltawiahsowah/Sites/glueful/bk/tw-class.css` (compiled Tailwind).

**Spec:** `docs/superpowers/specs/2026-07-08-pricing-blocks-design.md`

## Global Constraints

- Repo: `/Users/michaeltawiahsowah/Sites/glueful/thallo`. Work on `dev` directly (no feature branch).
- **Hold all commits until explicit go-ahead** — the per-task "Commit" steps are staged and ready but MUST NOT run until the user says so. Do not commit this plan.
- No AI/Anthropic attribution in any commit or artifact.
- **5 new block types**, snake_case slugs: `pricing_plan`, `pricing_plans`, `pricing_table`, `pricing_tier`, `pricing_feature`.
- **Rounded corners**: cards/tiers `border-radius: var(--radius-lg)`; inner controls `var(--radius)`.
- **Nestable one wrapper deep, no deeper** — no `blocks` field sits below `BlockDepth::MAX` (3). Achieved by: flat CTA fields (no nested `button` block) and a flat `features` list (no `pricing_section` block).
- **No `json` field type** anywhere in these blocks.
- **Seeding**: create rows via `php glueful thallo:blocks:seed` (idempotent — creates missing, skips existing). NOT a migration. `thallo:blocks:sync` is not used.
- **Feature-icon rule**: resolve via `icon(name|default('check'))` and **never** echo the raw name on null (unlike existing templates' `icon(x) ?? x`) — print nothing.
- Run tests with `vendor/bin/phpunit`; style with `vendor/bin/phpcs`; keep lines ≤ 120 chars.

## File Structure

**Modified (all tasks):**
- `app/Content/Blocks/StarterBlockTypes.php` — append the 5 definitions (each task appends its own; keep them contiguous in a `// ---- Pricing ----` group).
- `tests/Integration/Content/SeedBlockTypesTest.php` — bump the expected-count literal each task (37→38→39→42).

**Created:**
- `packages/thallo-render/themes/default/templates/blocks/pricing_plan.twig` (Task 1)
- `packages/thallo-render/themes/default/templates/blocks/pricing_plans.twig` (Task 2)
- `packages/thallo-render/themes/default/templates/blocks/pricing_table.twig` (Task 3)
- `packages/thallo-render/themes/default/templates/blocks/pricing_tier.twig` (Task 3, standalone fallback)
- `packages/thallo-render/themes/default/templates/blocks/pricing_feature.twig` (Task 3, standalone fallback)
- `tests/Integration/Render/PricingBlockRenderTest.php` (Task 1, extended in 2–3)

**Appended:**
- `packages/thallo-render/themes/default/assets/blocks.css` — one `/* ── Pricing ── */` section, grown per task.

## Conventions all templates follow

- Root class + guarded `--modifier` maps built in a `{% set %}` block up top (see `cta.twig`).
- `data.x|default(...)`; optional parts wrapped in `{% if data.x %}`.
- Text through `editable_text('field')`; URLs through `|safe_url`; icons through `icon(...)`.
- CTA snippet (used verbatim in `pricing_plan` and `pricing_tier`, class prefix swapped):

```twig
{% set ctaUrl = data.button_url|default('')|safe_url %}
{% if data.button_label|default('') is not empty %}
  {% set ctaTag = ctaUrl ? 'a' : 'span' %}
  <{{ ctaTag }} class="thallo-block-PREFIX__cta thallo-block-PREFIX__cta--{{ ({solid:'solid',outline:'outline'}[data.button_variant|default('solid')] ?? 'solid') }}"{% if ctaUrl %} href="{{ ctaUrl }}"{% endif %}>{{ data.button_label|editable_text('button_label') }}</{{ ctaTag }}>
{% endif %}
```

---

### Task 1: `pricing_plan` (card)

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (append definition)
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php` (37 → 38)
- Create: `packages/thallo-render/themes/default/templates/blocks/pricing_plan.twig`
- Create: `tests/Integration/Render/PricingBlockRenderTest.php`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (append Pricing section)

**Interfaces:**
- Produces: block slug `pricing_plan`; root class `thallo-block-pricing_plan` with modifiers `--variant-{outline|solid|soft|subtle}`, `--highlight`, `--orientation-{vertical|horizontal}`; BEM parts `__badge __title __description __price-wrapper __price __discount __billing __billing-period __billing-cycle __features __feature __feature-icon __tagline __cta __cta--{solid|outline} __terms`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/PricingBlockRenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

final class PricingBlockRenderTest extends AppTestCase
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

    public function testPricingPlanRendersVariantFeaturesBadgeAndCta(): void
    {
        $out = $this->render([[
            'id' => 'p1', 'type' => 'pricing_plan',
            'data' => [
                'title' => 'Pro', 'price' => '$29', 'badge' => 'Popular',
                'variant' => 'soft', 'highlight' => true,
                'features' => "Unlimited projects\nPriority support",
                'button_label' => 'Choose Pro', 'button_url' => 'https://example.com/buy',
            ],
        ]]);

        self::assertStringContainsString('thallo-block-pricing_plan--variant-soft', $out);
        self::assertStringContainsString('thallo-block-pricing_plan--highlight', $out);
        self::assertStringContainsString('Popular', $out);
        self::assertStringContainsString('Unlimited projects', $out);
        self::assertStringContainsString('Priority support', $out);
        self::assertStringContainsString('href="https://example.com/buy"', $out);
        // Rounded corners come from CSS; assert the block class is present so CSS can bind.
        self::assertStringContainsString('thallo-block-pricing_plan', $out);
    }

    public function testPricingPlanDropsUnsafeCtaUrlAndUnknownIcon(): void
    {
        $out = $this->render([[
            'id' => 'p2', 'type' => 'pricing_plan',
            'data' => [
                'title' => 'Free', 'price' => '$0',
                'features' => 'One project',
                'feature_icon' => 'definitely-not-a-real-icon',
                'button_label' => 'Start', 'button_url' => 'javascript:alert(1)',
            ],
        ]]);

        // Unsafe url dropped → CTA is a <span>, no href.
        self::assertStringNotContainsString('javascript:alert(1)', $out);
        self::assertStringNotContainsString('href=', $out);
        // Unknown icon name must NOT be echoed as raw text.
        self::assertStringNotContainsString('definitely-not-a-real-icon', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter=PricingBlockRenderTest`
Expected: FAIL — Twig `Unable to find template "blocks/pricing_plan.twig"`.

- [ ] **Step 3: Add the definition to `StarterBlockTypes.php`**

Immediately before the closing `];` of `definitions()` (after the `social_link` entry), add a Pricing group. Add ONLY `pricing_plan` in this task:

```php
            // ---- Pricing ----------------------------------------------------
            // Nuxt UI Pro pricing components (refs.md pricingPlan/pricingPlans/
            // pricingTable), translated to the theme's BEM convention. Flat CTA
            // fields + flat feature/section lists keep everything within
            // BlockDepth::MAX so they nest one wrapper deep.
            ['slug' => 'pricing_plan', 'label' => 'Pricing plan', 'icon' => 'i-lucide-badge-dollar-sign',
                'category' => 'Content',
                'description' => 'A single pricing plan card: price, features and a CTA.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'price', 'type' => 'string'],
                    ['name' => 'discount', 'type' => 'string'],
                    ['name' => 'billing_period', 'type' => 'string'],
                    ['name' => 'billing_cycle', 'type' => 'string'],
                    ['name' => 'badge', 'type' => 'string'],
                    ['name' => 'features', 'type' => 'text'],
                    ['name' => 'feature_icon', 'type' => 'string',
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'tagline', 'type' => 'string'],
                    ['name' => 'terms', 'type' => 'text'],
                    ['name' => 'button_label', 'type' => 'string'],
                    ['name' => 'button_url', 'type' => 'string'],
                    ['name' => 'button_variant', 'type' => 'enum', 'enum' => ['solid', 'outline']],
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['outline', 'solid', 'soft', 'subtle']],
                    ['name' => 'highlight', 'type' => 'boolean'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                ]],
```

- [ ] **Step 4: Create `pricing_plan.twig`**

```twig
{# pricing_plan — a single plan card (refs.md pricingPlan). BEM/computed-class
   convention (see cta.twig): guarded --modifier maps up top. Flat CTA fields
   (button_label/url/variant) — no nested button block (depth budget). Features:
   one per line, each with the uniform feature_icon (default 'check'); an unknown
   icon renders nothing (never the raw name). #}
{% set variant = ({outline:'outline',solid:'solid',soft:'soft',subtle:'subtle'}[data.variant|default('outline')] ?? 'outline') %}
{% set orientation = ({vertical:'vertical',horizontal:'horizontal'}[data.orientation|default('vertical')] ?? 'vertical') %}
{% set rootClass = [
  'thallo-block thallo-block-pricing_plan',
  'thallo-block-pricing_plan--variant-' ~ variant,
  'thallo-block-pricing_plan--orientation-' ~ orientation,
  data.highlight|default(false) ? 'thallo-block-pricing_plan--highlight' : '',
]|join(' ')|trim %}
{% set featureIcon = data.feature_icon|default('check') %}
<div class="{{ rootClass }}">
  {% if data.badge %}<span class="thallo-block-pricing_plan__badge">{{ data.badge }}</span>{% endif %}
  <div class="thallo-block-pricing_plan__body">
    {% if data.title %}<h3 class="thallo-block-pricing_plan__title">{{ data.title|editable_text('title') }}</h3>{% endif %}
    {% if data.description %}<p class="thallo-block-pricing_plan__description">{{ data.description|editable_text('description') }}</p>{% endif %}
    <div class="thallo-block-pricing_plan__price-wrapper">
      {% if data.discount %}<span class="thallo-block-pricing_plan__discount">{{ data.discount }}</span>{% endif %}
      {% if data.price %}<span class="thallo-block-pricing_plan__price">{{ data.price }}</span>{% endif %}
      {% if data.billing_period or data.billing_cycle %}
      <span class="thallo-block-pricing_plan__billing">
        {% if data.billing_period %}<span class="thallo-block-pricing_plan__billing-period">{{ data.billing_period }}</span>{% endif %}
        {% if data.billing_cycle %}<span class="thallo-block-pricing_plan__billing-cycle">{{ data.billing_cycle }}</span>{% endif %}
      </span>
      {% endif %}
    </div>
    {% set featureLines = (data.features|default(''))|split('\n') %}
    {% set hasFeatures = false %}
    {% for line in featureLines %}{% if line|trim is not empty %}{% set hasFeatures = true %}{% endif %}{% endfor %}
    {% if hasFeatures %}
    <ul class="thallo-block-pricing_plan__features">
      {% for line in featureLines %}{% if line|trim is not empty %}
      <li class="thallo-block-pricing_plan__feature">
        <span class="thallo-block-pricing_plan__feature-icon" aria-hidden="true">{{ icon(featureIcon) }}</span>
        <span>{{ line|trim }}</span>
      </li>
      {% endif %}{% endfor %}
    </ul>
    {% endif %}
  </div>
  <div class="thallo-block-pricing_plan__footer">
    {% if data.tagline %}<p class="thallo-block-pricing_plan__tagline">{{ data.tagline }}</p>{% endif %}
    {% set ctaUrl = data.button_url|default('')|safe_url %}
    {% if data.button_label|default('') is not empty %}
      {% set ctaTag = ctaUrl ? 'a' : 'span' %}
      <{{ ctaTag }} class="thallo-block-pricing_plan__cta thallo-block-pricing_plan__cta--{{ ({solid:'solid',outline:'outline'}[data.button_variant|default('solid')] ?? 'solid') }}"{% if ctaUrl %} href="{{ ctaUrl }}"{% endif %}>{{ data.button_label|editable_text('button_label') }}</{{ ctaTag }}>
    {% endif %}
    {% if data.terms %}<p class="thallo-block-pricing_plan__terms">{{ data.terms }}</p>{% endif %}
  </div>
</div>
```

- [ ] **Step 5: Append the Pricing CSS section to `blocks.css`** (at end of file)

```css
/* ── Pricing blocks (refs.md pricingPlan/pricingPlans/pricingTable) ──────────
   Self-contained rounded cards (deliberate radius exception to the squared
   band blocks). Semantic Nuxt tokens mapped to theme tokens (site.css). */
.thallo-block-pricing_plan {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-4);
  border-radius: var(--radius-lg);
  background: var(--bg);
  border: 1px solid var(--line);
}
.thallo-block-pricing_plan--variant-solid {
  background: var(--ink);
  color: var(--accent-ink);
  border-color: transparent;
}
.thallo-block-pricing_plan--variant-soft { background: var(--surface); border-color: transparent; }
.thallo-block-pricing_plan--variant-subtle { background: var(--surface); border-color: var(--line); }
.thallo-block-pricing_plan--highlight {
  border-color: transparent;
  box-shadow: inset 0 0 0 2px var(--accent);
}
.thallo-block-pricing_plan__badge {
  align-self: flex-start;
  padding: 0.15rem 0.6rem;
  border-radius: var(--radius);
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 0.8rem;
  font-weight: 600;
}
.thallo-block-pricing_plan__body { display: flex; flex-direction: column; gap: var(--space-2); flex: 1; }
.thallo-block-pricing_plan__title { margin: 0; font-size: 1.5rem; font-weight: 600; color: inherit; }
.thallo-block-pricing_plan__description { margin: 0; color: var(--muted); }
.thallo-block-pricing_plan__price-wrapper { display: flex; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; }
.thallo-block-pricing_plan__price { font-size: 2.25rem; font-weight: 700; color: inherit; }
.thallo-block-pricing_plan__discount { color: var(--muted); text-decoration: line-through; font-size: 1.25rem; }
.thallo-block-pricing_plan__billing { display: flex; flex-direction: column; }
.thallo-block-pricing_plan__billing-period { font-size: 0.8rem; font-weight: 500; color: color-mix(in srgb, var(--ink) 65%, var(--muted)); }
.thallo-block-pricing_plan__billing-cycle { font-size: 0.8rem; color: var(--muted); }
.thallo-block-pricing_plan__features { list-style: none; margin: var(--space-2) 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.75rem; }
.thallo-block-pricing_plan__feature { display: flex; align-items: center; gap: 0.5rem; }
.thallo-block-pricing_plan__feature-icon { display: inline-flex; width: 1.25rem; height: 1.25rem; color: var(--accent); flex-shrink: 0; }
.thallo-block-pricing_plan__feature-icon svg { width: 100%; height: 100%; }
.thallo-block-pricing_plan__footer { display: flex; flex-direction: column; gap: var(--space-2); align-items: stretch; }
.thallo-block-pricing_plan__tagline { margin: 0; font-weight: 600; text-align: center; }
.thallo-block-pricing_plan__cta {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 0.6rem 1.2rem;
  border-radius: var(--radius);
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}
.thallo-block-pricing_plan__cta--solid { background: var(--accent); color: var(--accent-ink); }
.thallo-block-pricing_plan__cta--outline { background: transparent; color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
.thallo-block-pricing_plan__terms { margin: 0; font-size: 0.75rem; color: var(--muted); text-align: center; }
/* solid variant dims secondary text */
.thallo-block-pricing_plan--variant-solid .thallo-block-pricing_plan__description,
.thallo-block-pricing_plan--variant-solid .thallo-block-pricing_plan__discount,
.thallo-block-pricing_plan--variant-solid .thallo-block-pricing_plan__billing-period,
.thallo-block-pricing_plan--variant-solid .thallo-block-pricing_plan__billing-cycle {
  color: color-mix(in srgb, var(--accent-ink) 65%, transparent);
}
/* horizontal card: body and footer side by side */
@media (min-width: 64em) {
  .thallo-block-pricing_plan--orientation-horizontal { flex-direction: row; align-items: center; }
  .thallo-block-pricing_plan--orientation-horizontal .thallo-block-pricing_plan__body { flex: 2; }
  .thallo-block-pricing_plan--orientation-horizontal .thallo-block-pricing_plan__footer { flex: 1; }
}
```

- [ ] **Step 6: Bump the seed count** in `tests/Integration/Content/SeedBlockTypesTest.php`: change `self::assertSame(37, $expected);` to `self::assertSame(38, $expected);`.

- [ ] **Step 7: Run tests**

Run: `vendor/bin/phpunit --filter=PricingBlockRenderTest` → Expected: PASS (2 tests).
Run: `vendor/bin/phpunit --filter=SeedBlockTypesTest` → Expected: PASS.
Run: `vendor/bin/phpcs packages/thallo-render/themes/default/assets/blocks.css app/Content/Blocks/StarterBlockTypes.php tests/Integration/Render/PricingBlockRenderTest.php` → Expected: no errors.

- [ ] **Step 8: Reseed the dev DB**

Run: `php glueful thallo:blocks:seed` → Expected: "Created 1, skipped ..." (creates `pricing_plan`).

- [ ] **Step 9: Commit (HOLD — do not run until user says so)**

```bash
git add app/Content/Blocks/StarterBlockTypes.php \
  packages/thallo-render/themes/default/templates/blocks/pricing_plan.twig \
  packages/thallo-render/themes/default/assets/blocks.css \
  tests/Integration/Render/PricingBlockRenderTest.php \
  tests/Integration/Content/SeedBlockTypesTest.php
git commit -m "Add pricing_plan block"
```

---

### Task 2: `pricing_plans` (grid/stack of plans)

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (append definition after `pricing_plan`)
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php` (38 → 39)
- Create: `packages/thallo-render/themes/default/templates/blocks/pricing_plans.twig`
- Modify: `tests/Integration/Render/PricingBlockRenderTest.php` (add tests)
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (extend Pricing section)

**Interfaces:**
- Consumes: block slug `pricing_plan` (Task 1) rendered via `blocks()`.
- Produces: block slug `pricing_plans`; root class `thallo-block-pricing_plans` with modifiers `--orientation-{horizontal|vertical}`, `--compact`, `--scale`; `__items` grid with inline `style="--count: N"`.

- [ ] **Step 1: Write the failing tests** — append to `PricingBlockRenderTest`:

```php
    public function testPricingPlansSetsCountAndOrientationAndScaleCascade(): void
    {
        $out = $this->render([[
            'id' => 'ps1', 'type' => 'pricing_plans',
            'data' => [
                'orientation' => 'horizontal', 'scale' => true,
                'plans' => [
                    ['id' => 'a', 'type' => 'pricing_plan', 'data' => ['title' => 'Basic', 'price' => '$0']],
                    ['id' => 'b', 'type' => 'pricing_plan', 'data' => ['title' => 'Pro', 'price' => '$29', 'highlight' => true]],
                    ['id' => 'c', 'type' => 'pricing_plan', 'data' => ['title' => 'Team', 'price' => '$99']],
                ],
            ],
        ]]);

        self::assertStringContainsString('--count: 3', $out);
        self::assertStringContainsString('thallo-block-pricing_plans--orientation-horizontal', $out);
        self::assertStringContainsString('thallo-block-pricing_plans--scale', $out);
        // The three child plans rendered.
        self::assertStringContainsString('Basic', $out);
        self::assertStringContainsString('Pro', $out);
        self::assertStringContainsString('Team', $out);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter=testPricingPlansSetsCountAndOrientationAndScaleCascade`
Expected: FAIL — `Unable to find template "blocks/pricing_plans.twig"`.

- [ ] **Step 3: Add the definition** (after the `pricing_plan` entry):

```php
            ['slug' => 'pricing_plans', 'label' => 'Pricing plans', 'icon' => 'i-lucide-wallet-cards',
                'category' => 'Content',
                'description' => 'A row or stack of pricing plans, with an optional featured plan.',
                'schema' => [
                    ['name' => 'plans', 'type' => 'blocks', 'block_types' => ['pricing_plan']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['horizontal', 'vertical']],
                    ['name' => 'compact', 'type' => 'boolean'],
                    ['name' => 'scale', 'type' => 'boolean'],
                ]],
```

- [ ] **Step 4: Create `pricing_plans.twig`**

```twig
{# pricing_plans — a grid (horizontal) or stack (vertical) of pricing_plan cards
   (refs.md pricingPlans). Auto --count = number of plans drives the grid columns.
   scale + orientation cascade to the child cards via descendant CSS (blocks.css);
   no data is threaded to children. #}
{% set orientation = ({horizontal:'horizontal',vertical:'vertical'}[data.orientation|default('horizontal')] ?? 'horizontal') %}
{% set rootClass = [
  'thallo-block thallo-block-pricing_plans',
  'thallo-block-pricing_plans--orientation-' ~ orientation,
  data.compact|default(false) ? 'thallo-block-pricing_plans--compact' : '',
  data.scale|default(false) ? 'thallo-block-pricing_plans--scale' : '',
]|join(' ')|trim %}
{% set count = data.plans|default([])|length %}
<div class="{{ rootClass }}">
  <div class="thallo-block-pricing_plans__items" style="--count: {{ count > 0 ? count : 1 }}">{{ blocks(data.plans) }}</div>
</div>
```

- [ ] **Step 5: Extend the Pricing CSS** (append to the Pricing section in `blocks.css`)

```css
.thallo-block-pricing_plans__items { display: flex; flex-direction: column; gap: var(--space-4); }
.thallo-block-pricing_plans__items > .thallo-block { margin-block: 0; }
@media (min-width: 48em) {
  .thallo-block-pricing_plans--orientation-horizontal .thallo-block-pricing_plans__items {
    display: grid;
    grid-template-columns: repeat(var(--count, 1), minmax(0, 1fr));
    align-items: start;
  }
  /* compact off widens the gap; scale + not-compact widens it more (room for the
     enlarged featured card). */
  .thallo-block-pricing_plans--orientation-horizontal:not(.thallo-block-pricing_plans--compact) .thallo-block-pricing_plans__items { gap: var(--space-4); }
  .thallo-block-pricing_plans--scale.thallo-block-pricing_plans--orientation-horizontal:not(.thallo-block-pricing_plans--compact) .thallo-block-pricing_plans__items { gap: var(--space-5); }
  /* featured plan enlarges only when the group opts into scale. */
  .thallo-block-pricing_plans--scale .thallo-block-pricing_plan--highlight { transform: scale(1.05); z-index: 1; }
}
```

- [ ] **Step 6: Bump the seed count**: `SeedBlockTypesTest.php` `38` → `39`.

- [ ] **Step 7: Run tests**

Run: `vendor/bin/phpunit --filter=PricingBlockRenderTest` → Expected: PASS (3 tests).
Run: `vendor/bin/phpunit --filter=SeedBlockTypesTest` → Expected: PASS.
Run: `vendor/bin/phpcs packages/thallo-render/themes/default/assets/blocks.css app/Content/Blocks/StarterBlockTypes.php` → Expected: no errors.

- [ ] **Step 8: Reseed** — `php glueful thallo:blocks:seed` → Expected: "Created 1, skipped ...".

- [ ] **Step 9: Commit (HOLD)**

```bash
git add app/Content/Blocks/StarterBlockTypes.php \
  packages/thallo-render/themes/default/templates/blocks/pricing_plans.twig \
  packages/thallo-render/themes/default/assets/blocks.css \
  tests/Integration/Render/PricingBlockRenderTest.php \
  tests/Integration/Content/SeedBlockTypesTest.php
git commit -m "Add pricing_plans block"
```

---

### Task 3: `pricing_table` + `pricing_tier` + `pricing_feature` (comparison table)

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (append 3 definitions)
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php` (39 → 42)
- Create: `packages/thallo-render/themes/default/templates/blocks/pricing_table.twig`
- Create: `packages/thallo-render/themes/default/templates/blocks/pricing_tier.twig`
- Create: `packages/thallo-render/themes/default/templates/blocks/pricing_feature.twig`
- Modify: `tests/Integration/Render/PricingBlockRenderTest.php` (add tests)
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (extend Pricing section)

**Interfaces:**
- Consumes: nothing from earlier tasks (renders its own children inline).
- Produces: slugs `pricing_table`, `pricing_tier`, `pricing_feature`. `pricing_table` renders tiers/features **inline** (not via child templates). Root `thallo-block-pricing_table` + `--highlight`; parts `__table __list __tier __row-header __section __cell __check __dash __item __item-feature`, cell tokens: `✓`/`yes`→check icon, `-`/`no`/empty→dash, else literal.

- [ ] **Step 1: Write the failing tests** — append to `PricingBlockRenderTest`:

```php
    /** @return array<string,mixed> a 3-tier table with a section + two feature rows */
    private function tableBlock(int $tierCount = 3): array
    {
        $tiers = [];
        foreach (['Basic', 'Pro', 'Team', 'Extra'] as $i => $name) {
            if ($i >= $tierCount) {
                break;
            }
            $tiers[] = ['id' => 't' . $i, 'type' => 'pricing_tier',
                'data' => ['title' => $name, 'price' => '$' . ($i * 10), 'highlight' => $name === 'Pro']];
        }
        return [
            'id' => 'tbl', 'type' => 'pricing_table',
            'data' => [
                'highlight' => true,
                'tiers' => $tiers,
                'features' => [
                    ['id' => 's1', 'type' => 'pricing_feature',
                        'data' => ['is_section' => true, 'title' => 'Core',
                            'value_1' => 'stale', 'value_2' => 'stale', 'value_3' => 'stale']],
                    ['id' => 'f1', 'type' => 'pricing_feature',
                        'data' => ['title' => 'Projects', 'value_1' => '3', 'value_2' => 'yes', 'value_3' => '-']],
                ],
            ],
        ];
    }

    public function testPricingTableRendersTiersCellsAndTokens(): void
    {
        $out = $this->render([$this->tableBlock()]);

        // One header cell per tier + the corner.
        self::assertSame(3, substr_count($out, 'thallo-block-pricing_table__tier"') + substr_count($out, 'thallo-block-pricing_table__tier '));
        self::assertStringContainsString('Projects', $out);
        self::assertStringContainsString('3', $out);                 // literal text cell
        self::assertStringContainsString('thallo-block-pricing_table__check', $out); // 'yes' → check
        self::assertStringContainsString('thallo-block-pricing_table__dash', $out);  // '-' → dash
        self::assertStringContainsString('thallo-block-pricing_table--highlight', $out);
    }

    public function testPricingTableSectionRowIsLabelOnlyIgnoringStaleValues(): void
    {
        // Regression: a former feature row toggled into a section (is_section: true)
        // still carries stale value_1/value_2 data. The section row must render as a
        // label only and ignore those values completely.
        $out = $this->render([$this->tableBlock()]);

        self::assertStringContainsString('thallo-block-pricing_table__section', $out);
        self::assertStringContainsString('Core', $out);
        // 1) The stale values must not appear anywhere.
        self::assertStringNotContainsString('stale', $out);
        // 2) Structural guard: only the one real feature row emits value cells
        //    (1 row × 3 tiers = 3 desktop __cell tds). If the section row leaked
        //    cells, this count would be higher — so this fails independently of the
        //    'stale' sentinel.
        self::assertSame(3, substr_count($out, 'thallo-block-pricing_table__cell'));
    }

    public function testPricingTableCapsAtFourTiersAndSurvivesOneAndZero(): void
    {
        $five = $this->tableBlock(4);
        // Add a fifth tier beyond the cap.
        $five['data']['tiers'][] = ['id' => 't4', 'type' => 'pricing_tier', 'data' => ['title' => 'Fifth']];
        $out = $this->render([$five]);
        self::assertStringNotContainsString('Fifth', $out); // 5th column dropped

        // One tier renders without error.
        $one = $this->render([$this->tableBlock(1)]);
        self::assertStringContainsString('Basic', $one);

        // Zero tiers renders without error (empty-safe).
        $zero = $this->render([[
            'id' => 'z', 'type' => 'pricing_table',
            'data' => ['tiers' => [], 'features' => []],
        ]]);
        self::assertStringContainsString('thallo-block-pricing_table', $zero);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter=PricingBlockRenderTest`
Expected: FAIL — `Unable to find template "blocks/pricing_table.twig"`.

- [ ] **Step 3: Add the three definitions** (after `pricing_plans`):

```php
            ['slug' => 'pricing_table', 'label' => 'Pricing table', 'icon' => 'i-lucide-table',
                'category' => 'Content',
                'description' => 'A feature-comparison table across pricing tiers.',
                'schema' => [
                    ['name' => 'tiers', 'type' => 'blocks', 'block_types' => ['pricing_tier']],
                    ['name' => 'features', 'type' => 'blocks', 'block_types' => ['pricing_feature']],
                    ['name' => 'highlight', 'type' => 'boolean'],
                ]],
            ['slug' => 'pricing_tier', 'label' => 'Pricing tier', 'icon' => 'i-lucide-columns-3',
                'category' => 'Items',
                'description' => 'One column of a pricing table: title, price and CTA.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'price', 'type' => 'string'],
                    ['name' => 'discount', 'type' => 'string'],
                    ['name' => 'billing_period', 'type' => 'string'],
                    ['name' => 'billing_cycle', 'type' => 'string'],
                    ['name' => 'badge', 'type' => 'string'],
                    ['name' => 'button_label', 'type' => 'string'],
                    ['name' => 'button_url', 'type' => 'string'],
                    ['name' => 'button_variant', 'type' => 'enum', 'enum' => ['solid', 'outline']],
                    ['name' => 'highlight', 'type' => 'boolean'],
                ]],
            ['slug' => 'pricing_feature', 'label' => 'Pricing feature', 'icon' => 'i-lucide-list-checks',
                'category' => 'Items',
                'description' => 'One comparison row (or a section heading) with a value per tier.',
                'schema' => [
                    ['name' => 'is_section', 'type' => 'boolean'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'value_1', 'type' => 'string'],
                    ['name' => 'value_2', 'type' => 'string'],
                    ['name' => 'value_3', 'type' => 'string'],
                    ['name' => 'value_4', 'type' => 'string'],
                ]],
```

- [ ] **Step 4: Create `pricing_table.twig`** (renders tiers/features inline; a `cell` macro handles tokens)

```twig
{# pricing_table — feature comparison across tiers (refs.md pricingTable). Renders
   its pricing_tier/pricing_feature children INLINE (table semantics can't come from
   standalone child templates — cf. accordion). Desktop <table> + mobile stacked
   list, both server-rendered. Tiers capped at 4 (positional value_1..value_4);
   section rows (is_section) are label-only and never read value_N. #}
{% import _self as t %}
{% set tiers = data.tiers|default([])|slice(0, 4) %}
{% set tierCount = tiers|length %}
{% set rootClass = [
  'thallo-block thallo-block-pricing_table',
  data.highlight|default(false) ? 'thallo-block-pricing_table--highlight' : '',
]|join(' ')|trim %}
<div class="{{ rootClass }}">
  <table class="thallo-block-pricing_table__table">
    <thead>
      <tr>
        <th class="thallo-block-pricing_table__corner"></th>
        {% for tier in tiers %}
        <th class="thallo-block-pricing_table__tier{{ tier.data.highlight|default(false) ? ' is-highlighted' : '' }}">
          {% if tier.data.badge %}<span class="thallo-block-pricing_table__tier-badge">{{ tier.data.badge }}</span>{% endif %}
          {% if tier.data.title %}<span class="thallo-block-pricing_table__tier-title">{{ tier.data.title }}</span>{% endif %}
          {% if tier.data.description %}<span class="thallo-block-pricing_table__tier-description">{{ tier.data.description }}</span>{% endif %}
          {% if tier.data.price %}<span class="thallo-block-pricing_table__tier-price">{{ tier.data.price }}</span>{% endif %}
          {{ t.tierCta(tier.data) }}
        </th>
        {% endfor %}
      </tr>
    </thead>
    <tbody>
      {% for feature in data.features|default([]) %}
        {% if feature.data.is_section|default(false) %}
        <tr class="thallo-block-pricing_table__section"><th colspan="{{ tierCount + 1 }}">{{ feature.data.title|default('') }}</th></tr>
        {% else %}
        <tr class="thallo-block-pricing_table__row">
          <th class="thallo-block-pricing_table__row-header">{{ feature.data.title|default('') }}</th>
          {% for tier in tiers %}
          <td class="thallo-block-pricing_table__cell{{ tier.data.highlight|default(false) ? ' is-highlighted' : '' }}">{{ t.cell(feature.data['value_' ~ loop.index]|default('')) }}</td>
          {% endfor %}
        </tr>
        {% endif %}
      {% endfor %}
    </tbody>
  </table>
  <div class="thallo-block-pricing_table__list">
    {% for tier in tiers %}
    <div class="thallo-block-pricing_table__item{{ tier.data.highlight|default(false) ? ' is-highlighted' : '' }}">
      <div class="thallo-block-pricing_table__item-head">
        {% if tier.data.title %}<span class="thallo-block-pricing_table__tier-title">{{ tier.data.title }}</span>{% endif %}
        {% if tier.data.price %}<span class="thallo-block-pricing_table__tier-price">{{ tier.data.price }}</span>{% endif %}
        {{ t.tierCta(tier.data) }}
      </div>
      {% for feature in data.features|default([]) %}
        {% if not feature.data.is_section|default(false) %}
        <div class="thallo-block-pricing_table__item-feature">
          <span>{{ feature.data.title|default('') }}</span>
          <span>{{ t.cell(feature.data['value_' ~ loop.parent.loop.index]|default('')) }}</span>
        </div>
        {% endif %}
      {% endfor %}
    </div>
    {% endfor %}
  </div>
</div>
{% macro cell(v) %}
{%- set low = (v|default(''))|trim|lower -%}
{%- if low == '✓' or low == 'yes' -%}
<span class="thallo-block-pricing_table__check" aria-hidden="true">{{ icon('check') }}</span>
{%- elseif low == '' or low == '-' or low == 'no' -%}
<span class="thallo-block-pricing_table__dash" aria-hidden="true">–</span>
{%- else -%}
{{ v|trim }}
{%- endif -%}
{% endmacro %}
{% macro tierCta(d) %}
{%- set ctaUrl = d.button_url|default('')|safe_url -%}
{%- if d.button_label|default('') is not empty -%}
{%- set ctaTag = ctaUrl ? 'a' : 'span' -%}
<{{ ctaTag }} class="thallo-block-pricing_table__cta thallo-block-pricing_table__cta--{{ ({solid:'solid',outline:'outline'}[d.button_variant|default('solid')] ?? 'solid') }}"{% if ctaUrl %} href="{{ ctaUrl }}"{% endif %}>{{ d.button_label }}</{{ ctaTag }}>
{%- endif -%}
{% endmacro %}
```

- [ ] **Step 5: Create `pricing_tier.twig`** (standalone fallback — a mini header card)

```twig
{# pricing_tier — standalone fallback. The pricing_table parent renders tiers inline;
   this only matters if a tier is dropped on its own. Degrades to a small header card. #}
<div class="thallo-block thallo-block-pricing_tier{{ data.highlight|default(false) ? ' is-highlighted' : '' }}">
  {% if data.badge %}<span class="thallo-block-pricing_table__tier-badge">{{ data.badge }}</span>{% endif %}
  {% if data.title %}<span class="thallo-block-pricing_table__tier-title">{{ data.title|editable_text('title') }}</span>{% endif %}
  {% if data.price %}<span class="thallo-block-pricing_table__tier-price">{{ data.price }}</span>{% endif %}
</div>
```

- [ ] **Step 6: Create `pricing_feature.twig`** (standalone fallback — a single row)

```twig
{# pricing_feature — standalone fallback. The pricing_table parent renders feature
   rows inline; this only matters if a feature is dropped on its own. #}
<div class="thallo-block thallo-block-pricing_feature">
  <span class="thallo-block-pricing_feature__title">{{ data.title|default('')|editable_text('title') }}</span>
</div>
```

- [ ] **Step 7: Extend the Pricing CSS** (append to the Pricing section)

```css
.thallo-block-pricing_table { border-radius: var(--radius-lg); }
.thallo-block-pricing_table__table { width: 100%; border-collapse: collapse; display: none; }
.thallo-block-pricing_table__list { display: flex; flex-direction: column; gap: var(--space-4); }
@media (min-width: 48em) {
  .thallo-block-pricing_table__table { display: table; }
  .thallo-block-pricing_table__list { display: none; }
}
.thallo-block-pricing_table__tier { padding: var(--space-3); text-align: left; vertical-align: top; border-bottom: 1px solid var(--line); }
.thallo-block-pricing_table__tier-badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: var(--radius); background: var(--accent); color: var(--accent-ink); font-size: 0.75rem; font-weight: 600; }
.thallo-block-pricing_table__tier-title { display: block; font-size: 1.125rem; font-weight: 600; color: var(--ink); }
.thallo-block-pricing_table__tier-description { display: block; font-size: 0.85rem; color: var(--muted); }
.thallo-block-pricing_table__tier-price { display: block; font-size: 1.75rem; font-weight: 700; color: var(--ink); }
.thallo-block-pricing_table__section > th { text-align: left; padding: var(--space-3) var(--space-3) 0.5rem; font-weight: 600; color: var(--ink); }
.thallo-block-pricing_table__row-header { text-align: left; padding: 0.75rem var(--space-3); font-weight: 400; color: var(--ink); border-bottom: 1px solid var(--line); }
.thallo-block-pricing_table__cell { padding: 0.75rem var(--space-3); text-align: center; color: var(--muted); border-bottom: 1px solid var(--line); }
.thallo-block-pricing_table__check { display: inline-flex; width: 1.25rem; height: 1.25rem; color: var(--accent); }
.thallo-block-pricing_table__check svg { width: 100%; height: 100%; }
.thallo-block-pricing_table__dash { color: var(--muted); }
.thallo-block-pricing_table__cta { display: inline-flex; align-items: center; justify-content: center; margin-top: 0.5rem; padding: 0.4rem 0.9rem; border-radius: var(--radius); font-size: 0.85rem; font-weight: 600; text-decoration: none; }
.thallo-block-pricing_table__cta--solid { background: var(--accent); color: var(--accent-ink); }
.thallo-block-pricing_table__cta--outline { background: transparent; color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
/* featured column shading (only when the table opts into highlight) */
.thallo-block-pricing_table--highlight .thallo-block-pricing_table__tier.is-highlighted,
.thallo-block-pricing_table--highlight .thallo-block-pricing_table__cell.is-highlighted { background: var(--surface); }
.thallo-block-pricing_table--highlight .thallo-block-pricing_table__tier.is-highlighted { border-top-left-radius: var(--radius-lg); border-top-right-radius: var(--radius-lg); }
/* mobile item cards */
.thallo-block-pricing_table__item { border: 1px solid var(--line); border-radius: var(--radius-lg); padding: var(--space-4); display: flex; flex-direction: column; gap: 0.5rem; }
.thallo-block-pricing_table__item.is-highlighted { box-shadow: inset 0 0 0 2px var(--accent); }
.thallo-block-pricing_table__item-head { display: flex; flex-direction: column; gap: 0.25rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--line); }
.thallo-block-pricing_table__item-feature { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
/* standalone fallbacks */
.thallo-block-pricing_tier { display: flex; flex-direction: column; gap: 0.25rem; padding: var(--space-4); border: 1px solid var(--line); border-radius: var(--radius-lg); }
.thallo-block-pricing_tier.is-highlighted { box-shadow: inset 0 0 0 2px var(--accent); }
.thallo-block-pricing_feature__title { color: var(--ink); }
```

- [ ] **Step 8: Bump the seed count**: `SeedBlockTypesTest.php` `39` → `42`.

- [ ] **Step 9: Run tests**

Run: `vendor/bin/phpunit --filter=PricingBlockRenderTest` → Expected: PASS (6 tests).
Run: `vendor/bin/phpunit --filter=SeedBlockTypesTest` → Expected: PASS.
Run: `vendor/bin/phpcs packages/thallo-render/themes/default/assets/blocks.css app/Content/Blocks/StarterBlockTypes.php tests/Integration/Render/PricingBlockRenderTest.php` → Expected: no errors.

- [ ] **Step 10: Reseed** — `php glueful thallo:blocks:seed` → Expected: "Created 3, skipped ...".

- [ ] **Step 11: Commit (HOLD)**

```bash
git add app/Content/Blocks/StarterBlockTypes.php \
  packages/thallo-render/themes/default/templates/blocks/pricing_table.twig \
  packages/thallo-render/themes/default/templates/blocks/pricing_tier.twig \
  packages/thallo-render/themes/default/templates/blocks/pricing_feature.twig \
  packages/thallo-render/themes/default/assets/blocks.css \
  tests/Integration/Render/PricingBlockRenderTest.php \
  tests/Integration/Content/SeedBlockTypesTest.php
git commit -m "Add pricing_table with pricing_tier and pricing_feature blocks"
```

---

### Task 4: Depth-nesting verification + full CI

**Files:**
- Modify: `tests/Integration/Render/PricingBlockRenderTest.php` (add a depth test using the seeded validator)

**Interfaces:**
- Consumes: all five seeded block types; `App\Content\Validation\FieldValidator`, `App\Content\Schema\ContentTypeSchema`.

**Note on the validator registry:** `FieldValidator` resolves its block-type registry from a DB-backed `BlockTypeRepository`. `AppTestCase` boots the app with block types seeded, so `new FieldValidator()` resolves them. If the pricing types are not present in the test DB, run `php glueful thallo:blocks:seed` first (Step 10 of Task 3 covers the dev DB; CI seeds via the app boot/migrations path).

- [ ] **Step 1: Write the depth test** — append to `PricingBlockRenderTest`:

```php
    public function testPricingTableFitsDepthBudgetTopLevelAndOneWrapperDeep(): void
    {
        // Entry schema: a single `body` blocks field (depth 0 field; items depth 1).
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'blocks'],
        ]);

        $table = $this->tableBlock();               // pricing_table with tiers + features
        $validator = new FieldValidator();

        // Top-level: body(1) → table(1) → tiers/features(2) → tier/feature(3). Valid.
        $topLevel = $validator->validate($schema, ['body' => [$table]], true);
        self::assertArrayHasKey('body', $topLevel);

        // One wrapper deep: body → container → content → table → ... deepest child at
        // depth 3. Still valid (no blocks field below depth 3).
        $wrapped = ['id' => 'wrap', 'type' => 'container', 'data' => ['content' => [$table]]];
        $oneDeep = $validator->validate($schema, ['body' => [$wrapped]], true);
        self::assertArrayHasKey('body', $oneDeep);
    }
```

- [ ] **Step 2: Run to verify it passes** (no new production code — this asserts the structure already fits)

Run: `vendor/bin/phpunit --filter=testPricingTableFitsDepthBudgetTopLevelAndOneWrapperDeep`
Expected: PASS. If it fails with "exceeds maximum block nesting depth", a `blocks` field is nested too deep — re-check that no pricing block introduced a nested `blocks` field beyond the flat design (button/section must be flat fields, not blocks).

- [ ] **Step 3: Run the full pricing suite + seed test**

Run: `vendor/bin/phpunit --filter=PricingBlockRenderTest` → Expected: PASS (7 tests).
Run: `vendor/bin/phpunit --filter=SeedBlockTypesTest` → Expected: PASS.

- [ ] **Step 4: Full CI**

Run: `composer ci`
Expected: full test suite + `analyse` + `phpcs` all pass. Fix any line-length (≤120) or type findings before proceeding.

- [ ] **Step 5: Commit (HOLD)**

```bash
git add tests/Integration/Render/PricingBlockRenderTest.php
git commit -m "Verify pricing blocks fit the block-nesting depth budget"
```

---

## Self-Review

**Spec coverage:**
- 5 block types (`pricing_plan`/`plans`/`table`/`tier`/`feature`) → Tasks 1–3. ✓
- Rounded corners (`--radius-lg` cards, `--radius` controls) → CSS in each task. ✓
- Flat CTA fields (no button block); flat features list (no section block) → definitions in Tasks 1/3. ✓
- Feature-icon default `check`, no raw-string echo → `pricing_plan.twig` uses `icon(featureIcon)` (no `?? raw`); test in Task 1. ✓
- Cell tokens ✓/yes/-/no/text → `cell` macro + test in Task 3. ✓
- `is_section` label-only, ignores stale `value_N` → template + dedicated test in Task 3. ✓
- Tier cap 4 + graceful 1/0 (render-time, unvalidated) → `slice(0,4)` + edge test in Task 3. ✓
- Auto `--count`, scale cascade, orientation → Task 2 template/CSS/test. ✓
- Depth: nestable one wrapper deep, nothing below depth 3 → Task 4 test. ✓
- Seed via `thallo:blocks:seed`, count 37→42 → each task bumps + reseeds. ✓
- Hold commits, no attribution → Global Constraints + every Commit step marked HOLD. ✓

**Placeholder scan:** No TBD/TODO; every code step contains full code. ✓

**Type/name consistency:** Class prefixes (`thallo-block-pricing_plan/plans/table/tier/feature`), modifiers, and `value_N` keys are consistent across templates, CSS, and tests. The CTA `is-highlighted` class and `--highlight` modifier are used identically in Task 3 template and CSS. ✓
