# Default-Theme Rewrite Completion — Seed Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the default theme's block *seed* (`StarterBlockTypes.php`), *styles*, *tests*, and *docs* fully in line with the rewritten template set — removing legacy blocks, adding the 8 new ones, and finishing them in the pattern established by the `navigation` migration.

**Architecture:** Block *types* are seeded rows in `block_types` (schema = a list of field defs). Templates live in `packages/thallo-render/themes/default/templates/blocks/{slug}.twig`. Styling is **hand-authored CSS in our design tokens** (`assets/blocks.css`, plus a dedicated `assets/{block}.css` when a block is large, as `navigation.css` is). The seed reaches already-migrated databases via a reseed migration that reads `StarterBlockTypes` as the single source of truth (precedent: `020_ReseedNavigationBlockType.php`).

**Tech Stack:** PHP 8.3 (Glueful framework), Twig, PostgreSQL (`app_test` for the integration suite), Tailwind-v4-derived `tw-class.css` used **only as a design reference** (never shipped), Vue 3 + Nuxt UI admin (block picker is seed-driven).

## Global Constraints

- **The established pattern (from the `navigation` migration) is authoritative for every block this plan touches:**
  1. Twig authored in the **computed-class shape** (class strings built in `{% set %}` maps at the top, each map guarded with `?? 'default'` so an unknown stored enum degrades to the default modifier — see `blocks/navigation.twig`, `blocks/logos.twig`).
  2. Class names are **our BEM** (`thallo-block-{slug}`, `thallo-block-{slug}__el`, `thallo-block-{slug}--modifier`) — **not** raw Tailwind utilities.
  3. CSS is **hand-authored in our tokens** (`--accent`, `--ink`, `--muted`, `--surface`, `--line`, `--radius`, `--shadow`, `--space-*`), *sourcing design* from `tw-class.css` / `docs/refs.md` — never linking `tw-class.css` itself.
  4. A **dedicated `assets/{slug}.css`** only when the block is large enough to earn it (navigation did); otherwise the block's rules live in `blocks.css`.
  5. **Progressive-enhancement JS in `blocks.js`** only when a block is genuinely interactive beyond CSS/`<details>` (navigation needed it; none of the 8 new blocks do — accordion/collapsible are native `<details>`, stepper is display-only).
- **No AI/Anthropic attribution** in commits, PR bodies/titles, or release notes.
- **Do not stage/commit `CLAUDE.md`.** Work on the `dev` branch directly.
- **Migrations fold; new columns go in the original create-table migration** — but this plan adds no columns; it uses a *data* reseed migration (`021`).
- **Field-schema vocabulary is fixed** by `app/Content/Schema/FieldDefinition.php`: types `string, text, number, boolean, datetime, enum, reference, asset, json, blocks`; keys `name, type, required, localized, filterable, filter_type, enum, format, reference_type, reference_slug_field, multiple, max_items, block_types, pattern, min, max`. `enum` needs a non-empty list; `format` is `plain|rich` (text) or `icon|brand-icon` (string); `block_types` only on `blocks` fields (picker-only, never validation).
- **Pre-launch:** no production data; block *instances* referencing removed slugs keep their stored JSON but render as a missing-template comment and drop from the picker — acceptable.

---

## KEY ARCHITECTURAL DECISION (confirm before Phase 2)

The 8 new templates currently ship in **raw Tailwind-utility style** (Nuxt-UI classes like `bg-default`, `text-muted`, `ring-default`, `size-10`), whose CSS lives in the **unlinked** `assets/tw-class.css` (10,931 lines / 257 KB). Two ways to finish them:

- **Path B — Port to our pattern (CHOSEN; matches navigation).** Rewrite each of the 8 templates to emit **our BEM classes** via computed-class maps, and hand-author each block's CSS in our tokens, sourcing the design from `tw-class.css`/`refs.md`. No build dependency, one consistent styling system, keeps the tested BEM contract. Higher effort (Phase 2 is the bulk of the work).
- **Path A — Link `tw-class.css` (cheaper, NOT chosen).** Keep the 8 templates' utilities and add `<link href="tw-class.css">` to `layout.twig`/`region-preview.twig`. ~1 hour, but ships a 257 KB utility sheet, runs two styling systems side-by-side (BEM for ~25 blocks, utilities for 8), and diverges from the pattern you deliberately chose for navigation.

**Recommendation: Path B.** The rest of this plan assumes Path B. If you prefer Path A, Phase 2 collapses to a single wiring task and the Twig files stay as-is — say so and I'll re-scope.

---

## SCOPE RECONCILIATION (decisions baked into the tasks)

**Remove from the seed (9 legacy blocks — templates already deleted):**
`divider, quote, features, testimonials, faq, steps, gallery, logo_cloud, testimonial`

**Keep as child data-carrier types (used inline by new parents; no standalone template needed):**
`feature` (grid), `faq_item` (accordion), `step` (stepper), `tab` (tabs), `social_link` (social_links)

> Decision D1: **keep `faq_item` + `step` names** (accordion reads `item.data.question/answer` = `faq_item`'s schema; stepper reads `item.data.title/description` = `step`'s schema). Renaming to `accordion_item`/`stepper_item` is cleaner but adds a rename migration + orphans existing child instances — deferred. This makes the **new seed count 33** (34 − 9 + 8).

**Add to the seed (8 new blocks):** `accordion, card, collapsible, footer_columns, links, logos, separator, stepper`

**Supersessions (new block replaces a removed one 1:1):** `logos` ⊃ `logo_cloud` (identical fields), `separator` ⊃ `divider`, `stepper` ⊃ `steps`, `accordion` ⊃ `faq`, `card` ⊃ `testimonial`/`testimonials`.

**Schema drift to fix on 3 surviving blocks:**
- `section`: add `headline` (string), `description` (text), `orientation` (enum `vertical|horizontal`), `reverse` (boolean), `links` (blocks, `block_types=['button']`); change `background` enum `['none','subtle','emphasis']` → `['none','muted','subtle','inverted']`.
- `container`: change `min_height` enum `['auto','half','full']` → `['auto','half','screen']`; trim `bg_repeat` to `['no-repeat','repeat']`.
- `grid`: add `'1'` to `columns` enum → `['1','2','3','4']`.

**One vocabulary gap (accept as `json`):** `footer_columns.columns` and `links.items` are nested plain-array structures with no matching FieldDefinition object type → modeled as `json` (no structured admin editor for now; call out in the block descriptions).

---

## PHASE 1 — Schema reconciliation + reseed migration

### Task 1.1: Rewrite the block seed

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php`

**Interfaces produced:** `StarterBlockTypes::definitions()` returns exactly 33 blocks: the surviving set (unchanged except the 3 drift fixes) + 8 new defs; the 9 removed defs gone.

- [ ] **Step 1: Delete the 9 removed block definitions** — remove the `['slug' => 'divider' …]`, `'quote'`, `'features'`, `'testimonials'`, `'faq'`, `'steps'`, `'gallery'`, `'logo_cloud'`, `'testimonial'` array entries.
- [ ] **Step 2: Apply the 3 drift fixes** (section, container, grid) exactly as listed in SCOPE RECONCILIATION.
- [ ] **Step 3: Add the 8 new definitions** (use `use`-imported short names / existing file style; each schema verified against `FieldDefinition`):

```php
// ---- Content ----
['slug' => 'accordion', 'label' => 'Accordion', 'icon' => 'i-lucide-list-collapse',
    'category' => 'Content', 'description' => 'A stack of expandable question/answer items.',
    'schema' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'multiple', 'type' => 'boolean'],
        ['name' => 'items', 'type' => 'blocks', 'block_types' => ['faq_item']],
    ]],
['slug' => 'collapsible', 'label' => 'Collapsible', 'icon' => 'i-lucide-chevrons-up-down',
    'category' => 'Content', 'description' => 'A single show/hide disclosure wrapping nested blocks.',
    'schema' => [
        ['name' => 'label', 'type' => 'string'],
        ['name' => 'open', 'type' => 'boolean'],
        ['name' => 'content', 'type' => 'blocks'],
    ]],
['slug' => 'card', 'label' => 'Card', 'icon' => 'i-lucide-rectangle-horizontal',
    'category' => 'Content', 'description' => 'A content card: icon, title, description and nested blocks.',
    'schema' => [
        ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'description', 'type' => 'text'],
        ['name' => 'variant', 'type' => 'enum',
            'enum' => ['outline', 'solid', 'soft', 'subtle', 'ghost', 'naked']],
        ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
        ['name' => 'reverse', 'type' => 'boolean'],
        ['name' => 'body', 'type' => 'blocks'],
    ]],
['slug' => 'links', 'label' => 'Links', 'icon' => 'i-lucide-list',
    'category' => 'Content', 'description' => 'A vertical list of navigation links with an optional title.',
    'schema' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'items', 'type' => 'json'],
    ]],
['slug' => 'stepper', 'label' => 'Stepper', 'icon' => 'i-lucide-list-ordered',
    'category' => 'Content', 'description' => 'A numbered sequence of steps, horizontal or vertical.',
    'schema' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
        ['name' => 'color', 'type' => 'enum',
            'enum' => ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'neutral']],
        ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
        ['name' => 'items', 'type' => 'blocks', 'block_types' => ['step']],
    ]],
// ---- Layout ----
['slug' => 'separator', 'label' => 'Separator', 'icon' => 'i-lucide-separator-horizontal',
    'category' => 'Layout', 'description' => 'A horizontal rule, optionally with a centered label and icon.',
    'schema' => [
        ['name' => 'label', 'type' => 'string'],
        ['name' => 'type', 'type' => 'enum', 'enum' => ['solid', 'dashed', 'dotted']],
        ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
        ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
    ]],
['slug' => 'footer_columns', 'label' => 'Footer columns', 'icon' => 'i-lucide-panel-bottom',
    'category' => 'Layout', 'description' => 'Columns of titled link lists for a site footer.',
    'schema' => [
        ['name' => 'columns', 'type' => 'json'],
    ]],
// ---- Media ----
['slug' => 'logos', 'label' => 'Logos', 'icon' => 'i-lucide-building-2',
    'category' => 'Media', 'description' => 'A “trusted by” strip of brand logos.',
    'schema' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'images', 'type' => 'asset', 'multiple' => true],
        ['name' => 'grayscale', 'type' => 'boolean'],
        ['name' => 'scroll', 'type' => 'boolean'],
    ]],
```

- [ ] **Step 4: Verify** — `php -l app/Content/Blocks/StarterBlockTypes.php`; then a one-off `php -r` that loads every definition through `BlockTypeRepository::assertBlockSchema()` (in-memory, no DB) to confirm all 33 schemas parse. Expected: no exception.

### Task 1.2: Reseed migration `021`

**Files:**
- Create: `database/migrations/021_ReseedBlockTypesForThemeRewrite.php`

- [ ] **Step 1: Write the migration** (mirrors `020`, reads `StarterBlockTypes` as source of truth — no duplicated schema JSON):

```php
<?php
declare(strict_types=1);

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Reseed block types for the default-theme rewrite: drop 9 legacy blocks and add
 * accordion/card/collapsible/footer_columns/links/logos/separator/stepper from
 * StarterBlockTypes (single source of truth). Block INSTANCES keep their data.
 */
final class ReseedBlockTypesForThemeRewrite implements MigrationInterface
{
    private const REMOVED = ['divider', 'quote', 'features', 'testimonials', 'faq',
        'steps', 'gallery', 'logo_cloud', 'testimonial'];
    private const ADDED = ['accordion', 'card', 'collapsible', 'footer_columns',
        'links', 'logos', 'separator', 'stepper'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('block-types reseed requires the Glueful SchemaBuilder.');
        }
        $conn = $schema->getConnection();
        $pdo = $conn->getPDO();
        $all = array_merge(self::REMOVED, self::ADDED);
        $in = implode(',', array_fill(0, count($all), '?'));
        $pdo->prepare("DELETE FROM block_types WHERE slug IN ($in)")->execute($all);

        $repo = new BlockTypeRepository($conn);
        foreach (StarterBlockTypes::definitions() as $def) {
            if (in_array($def['slug'], self::ADDED, true)) {
                $repo->create($def);
            }
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema instanceof SchemaBuilder) {
            return;
        }
        $all = self::ADDED;
        $in = implode(',', array_fill(0, count($all), '?'));
        $schema->getConnection()->getPDO()
            ->prepare("DELETE FROM block_types WHERE slug IN ($in)")->execute($all);
        // The 9 removed slugs are not restorable (their defs are gone) — one-way.
    }

    public function getDescription(): string
    {
        return 'Reseed block types for the default-theme rewrite (drop 9 legacy, add 8 new).';
    }
}
```

- [ ] **Step 2: Apply to dev DB** — `printf 'yes\n' | php glueful migrate:run`; expect `021 … Completed`.
- [ ] **Step 3: Verify** — one-off `php -r` asserting `BlockTypeRepository::findBySlug('separator')` etc. are non-null and `findBySlug('quote')` is null.

### Task 1.3: Update `SeedBlockTypesTest`

**Files:**
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php`

- [ ] **Step 1:** Line ~47 `assertSame(34, $expected)` → **`33`**; update the adjacent "34 types" comment to 33.
- [ ] **Step 2:** Line ~49 `assertSame('Items', $repo->findBySlug('testimonial')['category'])` → assert a new block's category instead, e.g. `assertSame('Content', $repo->findBySlug('card')['category'])`.
- [ ] **Step 3:** Lines ~112-113, ~123 — the "deactivation survives reseed" scenario uses `'quote'`; swap all three occurrences to a surviving slug, e.g. `'rich_text'`.
- [ ] **Step 4:** Add assertions for the new set (spot-check): `assertSame(['solid','dashed','dotted'], $sep['type']['enum'])` for `separator`, `assertArrayNotHasKey('quote', …)`, and that `findBySlug('divider')`/`'faq'`/`'logo_cloud'` are null.
- [ ] **Step 5: Verify** — `DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit --no-coverage tests/Integration/Content/SeedBlockTypesTest.php`. (Rebuild the test DB first if the seed changed: `composer test:reset-db && composer test:migrate`.)

---

## PHASE 2 — Port the 8 new blocks to our pattern (Path B)

> One task per block. Each follows the navigation recipe: (1) rewrite the `.twig` to computed-class BEM, (2) author CSS in our tokens sourced from the cited `refs.md` component, (3) decide `blocks.css` vs a dedicated file, (4) no JS. **Source-of-design references** (`packages/thallo-render/docs/refs.md`): accordion `:1250`, stepper `:1270`, separator `:1626`, collapsible `:1816` (+ keyframes `:871`), card ← `pageCard :89`, links ← `pageLinks :430` + `navigationMenu :712`, logos ← `pageLogos :455`, footer_columns ← `footerColumns :1598`.

**Shared verification for every Phase-2 task:** run the twig-lint (`All N templates parse cleanly`) and render the block in isolation asserting `thallo-block-{slug}` + its modifier classes appear and no `Missing block template` comment.

- [ ] **Task 2.1 `separator`** — smallest; do first as the reference. Fields: `label`, `type` (solid|dashed|dotted), `size` (xs|sm|md|lg|xl), `icon`. BEM: `thallo-block-separator--type-{t}`, `--size-{s}`; `__label`, `__icon`. CSS in `blocks.css`. Delete legacy `divider` rules (see Phase 3).
- [ ] **Task 2.2 `logos`** — port the existing computed-class `logos.twig` from Tailwind values to BEM (`thallo-block-logos--scroll`, `--grayscale`; `__title`, `__track`, `__item`). CSS in `blocks.css`, reusing the marquee `@keyframes` currently under the removed `logo_cloud` (rename to `thallo-logos-marquee`). Fields mirror old `logo_cloud`.
- [ ] **Task 2.3 `card`** — fields `icon,title,description,variant(6),orientation,reverse,body`. BEM `--variant-{v}`, `--horizontal`, `--reverse`; `__icon/__title/__description/__body`. CSS in `blocks.css` (variant surfaces from tokens: solid=`--ink`, soft/subtle=`--surface`, outline=`--line` border, ghost/naked=transparent).
- [ ] **Task 2.4 `separator`-sibling `collapsible`** — native `<details>` + CSS. Fields `label,open,content`. BEM `__summary/__panel`; chevron rotate via `[open]`. CSS in `blocks.css`. Reuse the collapsible open/close transition idea from `refs.md :871` but as a CSS `max-height`/`grid-rows` transition in our stylesheet.
- [ ] **Task 2.5 `accordion`** — `<details name="accordion-{{ block.id }}">` for exclusive open when `multiple=false`. Fields `title,multiple,items(faq_item)`; item renders `item.data.question` + `item.data.answer|safe_html`. BEM `__item/__summary/__panel/__question`. CSS in `blocks.css` (shares the collapsible disclosure styling).
- [ ] **Task 2.6 `stepper`** — display-only. Fields `title,orientation,color(7),size(5),items(step)`; item renders `loop.index` + `item.data.title/description`. BEM `--orientation-{o}`, `--color-{c}`, `--size-{s}`; `__item/__marker/__title/__description/__connector`. **Dedicated `assets/stepper.css`** (it's large, like navigation) — link it in Phase 3.
- [ ] **Task 2.7 `links`** — fields `title,items(json: {label,url,icon?,active?})`. BEM `__title/__item/__link`, `__link--active`. CSS in `blocks.css`. Source active/muted recipe from `navigationMenu :712`.
- [ ] **Task 2.8 `footer_columns`** — fields `columns(json: [{label, items:[{label,url}]}])`. BEM `__col/__col-title/__list/__link`. CSS in `blocks.css`. (Note in the block description that `columns` is a raw JSON field with no structured admin editor yet.)

---

## PHASE 3 — CSS cleanup + asset wiring

### Task 3.1: Delete orphan CSS for removed blocks

**Files:** Modify `packages/thallo-render/themes/default/assets/blocks.css`

- [ ] Remove rule blocks (verify by selector, line numbers approximate): `divider :167-180`, `quote :182-212`, `gallery :279-300`, `features` container `:393-413` (**keep `.thallo-block-feature*` `:414-419`**), `testimonials/testimonial :421-452`, `faq/faq_item :454-483`, `steps/step :531-554`, `logo_cloud :655-682` (its marquee keyframes move to `logos` in Task 2.2).
- [ ] In the `.layout--centered` (`:714-731`) and `.layout--full` (`:739-761`) selector lists, remove the dead names `features, testimonials, steps, logo_cloud, faq` (keep surviving selectors).
- [ ] **Verify:** `grep -c "thallo-block-\(divider\|quote\|gallery\|features\|testimonials\|testimonial\|faq\|faq_item\|steps\|step\|logo_cloud\)\b" blocks.css` → account for only the intended survivors (`feature`, `step` as child-carrier if styled inline).

### Task 3.2: Wire the dedicated stepper stylesheet

**Files:** Modify `layout.twig`, `region-preview.twig`

- [ ] Add `<link rel="stylesheet" href="{{ asset('stepper.css') }}">` after the `navigation.css` link in both files (only if Task 2.6 produced a dedicated file; otherwise skip).
- [ ] **Do NOT link `tw-class.css`** (Path B). Leave it in place as a design reference; it ships to nobody. (Optional: move it out of `assets/` into `docs/` so it can't be served — decide with the user.)
- [ ] **Verify:** render a page + region-preview; confirm the new blocks are styled and no console 404s for theme assets.

---

## PHASE 4 — Tests: renders-through-theme + fixtures

### Task 4.1: `StarterTemplatesTest`
**Files:** Modify `tests/Integration/Render/StarterTemplatesTest.php`
- [ ] Delete the dead `fixture()` arms: `divider, quote, gallery, features, testimonials, testimonial, faq, faq_item, steps, step, logo_cloud` (**keep `feature`**).
- [ ] Add 8 new `fixture()` arms (representative data per Task 2.x templates): `accordion, card, collapsible, footer_columns, links, logos, separator, stepper` (data shapes from the discovery report).
- [ ] Swap inner-child `quote` → `rich_text` (`data.body`) at the `section` (`:35`), `columns` (`:37-38`), `container` (`:56`), `grid` (`:58`), `tabs`/`tab` (`:72,74`), `carousel` (`:83`) fixtures, and in `testColumnsRendersPerLayoutEnum` (`:139`).
- [ ] **Verify:** the whole-seed loop renders all 33 with no `Missing block template`.

### Task 4.2: `RegionRenderingTest`
**Files:** Modify `tests/Integration/Render/RegionRenderingTest.php`
- [ ] Lines ~221, ~232: swap the entry block `type => 'quote'` → `'rich_text'` with `['body' => 'Entry']` / `['body' => 'After']` so the `thallo-preview-block` annotation assertions pass.
- [ ] (Already done earlier: `i18n_locales` self-seed in `testNavigationActiveStateAcrossLocaleGrammars`.)

### Task 4.3: `PreviewAnnotationTest` + `PreviewWorkingCopyTest`
**Files:** Modify both.
- [ ] Replace the self-created `quote` type + instances with `rich_text` (create-schema field `text` → `body`; every instance `type => 'rich_text'`, `data ['body' => …]`). Update the "matches blocks/quote.twig" comment.

### Task 4.4: `BlockLibraryRenderTest` faq→accordion
**Files:** Modify `tests/Integration/Render/BlockLibraryRenderTest.php`
- [ ] `testFaqAndTabsGroupsAreScopedPerBlockInstance` (`:251-260`): rewrite the faq half to `accordion` (`type=>'accordion'`, items `type=>'faq_item'` with `data.question/answer`), assert `name="accordion-faqblock0001"` / `…0002`. Leave the tabs half unchanged.
- [ ] Optional: swap the carousel inner `quote` child (`:276`) → `rich_text` (non-breaking either way).

### Task 4.5: Low-risk fixture hygiene (optional, do last)
- [ ] `RegionValidatorTest.php:43`, `RegionAdminApiTest.php:48,79,143`: swap the palette-exclusion example `gallery` → a surviving palette-excluded block (e.g. `logos`).
- [ ] Leave self-seeding validation/JS fixtures that use `quote` as an arbitrary slug (`BlocksRenderingTest`, `BlocksValidationTest`, `EditInPlaceMarkingTest`, `RenderPipelineTest`, admin `blocksField.spec.ts`, `block-notion-ux.spec.ts`) — they don't touch the theme; rename only if you want them representative.

**Phase 4 verification:** `composer test:reset-db && composer test:migrate && DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit --no-coverage tests/Integration/Render tests/Integration/Content` — green.

---

## PHASE 5 — Docs

### Task 5.1: Refresh `THEMING.md`
**Files:** Modify `packages/thallo-render/docs/THEMING.md`
- [ ] §4.4 block list: drop the 9 removed blocks, add the 8 new ones with one-line descriptions + their config fields, note the child-carrier types (`feature/faq_item/step`).
- [ ] Add a short "block styling convention" note codifying the established pattern (computed-class BEM + hand-authored token CSS, `tw-class.css` is reference-only).

---

## Self-review checklist (run before execution)
- Seed count math: 34 − 9 + 8 = **33** (faq_item/step retained). `SeedBlockTypesTest` asserts 33.
- Every new block's schema uses only `FieldDefinition`-supported types/keys (verified in Task 1.1 Step 4).
- Every removed slug appears in the migration `REMOVED` list AND has its `blocks.css` rules deleted (Phase 3) AND its test references swapped (Phase 4).
- `tw-class.css` is never linked (Path B).
- No task renames `faq_item`/`step` (Decision D1) — if that changes, add a child-instance rename migration.

## Execution handoff
Two options:
1. **Subagent-driven (recommended):** one fresh subagent per task, review between tasks. Best for Phase 2's 8 block ports.
2. **Inline:** batch with checkpoints.
