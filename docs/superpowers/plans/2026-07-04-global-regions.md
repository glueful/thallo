# Global Regions (editable header & footer) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Header and footer become server-validated block regions (`lemma_regions`) rendered through the real blocks pipeline with fallback chrome, edited on a new admin page, purging the render page cache on save.

**Architecture:** A `lemma_regions` table (slug PK, blocks JSON, settings JSON) behind `RegionRepository` → `EngineRegionReader` (app) soft-bound into `RenderContextExtension` via a new `RegionReader` contract (MenuReader pattern). `region_blocks()`/`region_settings()` join the sandbox (CACHE_VERSION 7). The layout renders `{{ region_blocks('header') }}` — the ONE region render path, which suppresses canvas annotation internally (render-context state; blocks()'s public signature unchanged) — falling back to today's hardcoded markup on every null path. Two new structured-source starter blocks (`navigation`, `social_links`+`social_link` child; 31→33). `RegionUpdated` → `PurgeRenderCacheOnRegionUpdate` broad-purges `lemma:render:page`. `_presentation` gains `header`/`footer` ∈ default|hidden.

**Tech Stack:** PHP 8.3, Twig 3 sandbox, PHPUnit, Vue 3 + Nuxt UI admin, openapi-fetch generated types.

**Spec:** `docs/superpowers/specs/2026-07-04-global-regions-design.md`

## Global Constraints

- Regions v1: `header`, `footer` only; global (no locale column), no draft state; saves apply immediately.
- Palettes are code constants, SERVER-enforced (422 with dot-path); header: `logo, navigation, button, social_links, container, columns, rich_text`; footer: header palette + `divider, spacer, icon, image, shortcode, html`.
- Settings vocabulary (loud failure on unknown keys): header `sticky` (bool), `width` ('contained'|'full'); footer `width` only.
- **Null/fallback rule:** `region_blocks()` returns null for unbound reader, absent row, AND saved-empty list — all render fallback chrome; hiding is `_presentation` only. Empty list is a legal save.
- `region_blocks` + `region_settings` join `TemplatePolicy::FUNCTIONS`; `CACHE_VERSION = 7`.
- Region blocks are NEVER canvas-annotated — suppression lives INSIDE region_blocks(); no annotation toggle in any template API.
- Every provider registration carries its `use` import (MediaUrlResolver incident).
- Admin routes gated `lemma_permission:content.manage` (the settings/general gate — chrome is content policy, not Twig editing).
- Session conventions: NO per-task commits — stage at the end, commit only on "commit all"; no attribution trailers; CHANGELOG [Unreleased] updated with the work.

---

### Task 1: Storage + definitions + validator

**Files:**
- Create: `database/migrations/019_CreateLemmaRegionsTable.php`
- Create: `app/Content/Regions/RegionDefinitions.php`
- Create: `app/Content/Regions/RegionRepository.php`
- Create: `app/Content/Regions/RegionValidator.php`
- Test: `tests/Integration/Content/RegionValidatorTest.php`

**Interfaces:**
- Produces: `RegionDefinitions::PALETTES` / `::SETTINGS_KEYS` / `::slugs()`; `RegionRepository::find(string $slug): ?array` / `save(string $slug, array $blocks, array $settings, ?string $updatedBy): void`; `RegionValidator::validate(string $slug, array $blocks, array $settings): array{blocks: list<array<string,mixed>>, settings: array<string,mixed>}` (throws `ValidationException`).

- [ ] **Step 1: Migration**

`database/migrations/019_CreateLemmaRegionsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateLemmaRegionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('lemma_regions')) {
            return;
        }
        $schema->createTable('lemma_regions', function ($table) {
            // Slug-keyed chrome regions (global-regions spec): 'header', 'footer' in v1.
            // Deliberately no locale column (global in v1) and no draft state (saves
            // apply immediately) — both are additive later.
            $table->string('slug', 64)->primary();
            // Ordered {id,type,data} list — the entry blocks-field shape.
            $table->json('blocks');
            // Fixed per-region vocabulary (RegionDefinitions::SETTINGS_KEYS).
            $table->json('settings');
            $table->timestamp('updated_at')->nullable();
            $table->string('updated_by', 12)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('lemma_regions');
    }

    public function getDescription(): string
    {
        return 'Create lemma_regions (global header/footer block regions)';
    }
}
```

(Match `getDescription()`/method set to `017_CreateLemmaBlockTypesTable.php` — copy any additional interface methods it implements.)

- [ ] **Step 2: RegionDefinitions**

`app/Content/Regions/RegionDefinitions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Regions;

/**
 * Chrome policy as code (global-regions spec §4/§6): which blocks a region may
 * contain and which settings keys it accepts. Deliberately NOT DB state — a
 * product decision versioned with the code. Palettes are SERVER-enforced
 * (RegionValidator), a pinned divergence from the picker-only block_types
 * convention: the "structured region" promise is a hard guarantee.
 */
final class RegionDefinitions
{
    /** @var array<string, list<string>> region slug → allowed TOP-LEVEL block types */
    public const PALETTES = [
        'header' => ['logo', 'navigation', 'button', 'social_links', 'container', 'columns', 'rich_text'],
        'footer' => [
            'logo', 'navigation', 'button', 'social_links', 'container', 'columns', 'rich_text',
            'divider', 'spacer', 'icon', 'image', 'shortcode', 'html',
        ],
    ];

    /** @var array<string, list<string>> region slug → allowed settings keys */
    public const SETTINGS_KEYS = [
        'header' => ['sticky', 'width'],
        'footer' => ['width'],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::PALETTES);
    }
}
```

- [ ] **Step 3: Failing validator test**

`tests/Integration/Content/RegionValidatorTest.php` (LemmaTestCase — needs the container for `BlockTypeRepository`-backed blocks validation; seed the starters like other block tests do — mirror the setup used in `SeedBlockTypesTest`/`BlocksRenderingTest` for block-type availability):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Regions\RegionValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;

final class RegionValidatorTest extends LemmaTestCase
{
    private function validator(): RegionValidator
    {
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        return new RegionValidator($this->container()->get(\App\Content\Validation\FieldValidator::class));
    }

    public function testValidHeaderSaves(): void
    {
        $clean = $this->validator()->validate('header', [
            ['id' => 'r1', 'type' => 'logo', 'data' => ['size' => 'medium', 'link_home' => true]],
            ['id' => 'r2', 'type' => 'navigation', 'data' => ['menu' => 'main']],
        ], ['sticky' => true, 'width' => 'full']);
        self::assertCount(2, $clean['blocks']);
        self::assertTrue($clean['settings']['sticky']);
    }

    public function testOutOfPaletteBlockIsADotPath422(): void
    {
        try {
            $this->validator()->validate('header', [
                ['id' => 'r1', 'type' => 'gallery', 'data' => ['images' => []]],
            ], []);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.0.type', $e->getErrors());
        }
    }

    public function testUnknownSettingsKeyAndWrongTypeFailLoudly(): void
    {
        try {
            $this->validator()->validate('footer', [], ['sticky' => true]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.sticky', $e->getErrors());
        }
        try {
            $this->validator()->validate('header', [], ['sticky' => 'yes']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.sticky', $e->getErrors());
        }
        try {
            $this->validator()->validate('header', [], ['width' => 'huge']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.width', $e->getErrors());
        }
    }

    public function testUnknownRegionSlugRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator()->validate('sidebar', [], []);
    }

    public function testEmptyListIsALegalSave(): void
    {
        $clean = $this->validator()->validate('footer', [], []);
        self::assertSame([], $clean['blocks']);
    }
}
```

(Adjust `ValidationException` import/`getErrors()` accessor to the real class — check `app/Content/Validation/ValidationException.php` for the errors accessor name before writing.)

Run: `vendor/bin/phpunit tests/Integration/Content/RegionValidatorTest.php` — Expected: FAIL (class not found).

- [ ] **Step 4: RegionRepository + RegionValidator**

`app/Content/Regions/RegionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Regions;

use Glueful\Database\Connection;

/** lemma_regions rows: {slug, blocks JSON, settings JSON}. */
final class RegionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{slug: string, blocks: list<array<string,mixed>>, settings: array<string,mixed>}|null */
    public function find(string $slug): ?array
    {
        $row = $this->db->table('lemma_regions')->where('slug', '=', $slug)->first();
        if ($row === null) {
            return null;
        }
        return [
            'slug' => (string) $row['slug'],
            'blocks' => json_decode((string) $row['blocks'], true) ?: [],
            'settings' => json_decode((string) $row['settings'], true) ?: [],
        ];
    }

    /** @param list<array<string,mixed>> $blocks @param array<string,mixed> $settings */
    public function save(string $slug, array $blocks, array $settings, ?string $updatedBy): void
    {
        $payload = [
            'blocks' => json_encode($blocks),
            'settings' => json_encode($settings === [] ? (object) [] : $settings),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $updatedBy,
        ];
        $existing = $this->db->table('lemma_regions')->where('slug', '=', $slug)->first();
        if ($existing === null) {
            $this->db->table('lemma_regions')->insert($payload + ['slug' => $slug]);
        } else {
            $this->db->table('lemma_regions')->where('slug', '=', $slug)->update($payload);
        }
    }
}
```

(Verify the query-builder upsert idiom against `BlockTypeRepository` — reuse its insert/update style exactly; `json_encode([])` for settings must produce `{}` not `[]` — hence the object cast.)

`app/Content/Regions/RegionValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Regions;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;

/**
 * Region save validation (global-regions spec §4/§6): the blocks list runs the
 * REAL FieldValidator (block schemas, depth cap, id uniqueness) through a
 * synthetic one-field schema, then the palette is enforced on TOP-LEVEL types
 * only — nested blocks-fields inside an allowed block are governed by that
 * block's own schema, same as entries. Settings mirror validatePresentation:
 * a fixed vocabulary that fails loudly.
 */
final class RegionValidator
{
    public function __construct(private readonly FieldValidator $fields)
    {
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     * @return array{blocks: list<array<string,mixed>>, settings: array<string,mixed>}
     * @throws ValidationException
     */
    public function validate(string $slug, array $blocks, array $settings): array
    {
        $palette = RegionDefinitions::PALETTES[$slug] ?? null;
        if ($palette === null) {
            throw new ValidationException(['slug' => "unknown region '{$slug}'"]);
        }

        // Palette first: a clear product error beats a schema error for the same block.
        $errors = [];
        foreach ($blocks as $i => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (!is_string($type) || !in_array($type, $palette, true)) {
                $errors["blocks.{$i}.type"] =
                    "'" . (is_string($type) ? $type : '?') . "' is not allowed in the {$slug} region";
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $schema = ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
        $clean = $this->fields->validate($schema, ['blocks' => $blocks], true);

        return [
            'blocks' => $clean['blocks'] ?? [],
            'settings' => $this->validateSettings($slug, $settings),
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function validateSettings(string $slug, array $settings): array
    {
        $allowed = RegionDefinitions::SETTINGS_KEYS[$slug] ?? [];
        $clean = [];
        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new ValidationException(["settings.{$key}" => 'unknown setting for this region']);
            }
            if ($key === 'sticky') {
                if (!is_bool($value)) {
                    throw new ValidationException(['settings.sticky' => 'must be a boolean']);
                }
                $clean['sticky'] = $value;
            }
            if ($key === 'width') {
                if (!in_array($value, ['contained', 'full'], true)) {
                    throw new ValidationException(['settings.width' => "must be 'contained' or 'full'"]);
                }
                $clean['width'] = $value;
            }
        }
        return $clean;
    }
}
```

(Check `ValidationException`'s constructor/errors accessor and `FieldValidator::validate` strictness semantics — `strict = true` matches entry saves; confirm blocks-type validation needs the container-bound FieldValidator, i.e. resolve it from the container, not `new`.)

- [ ] **Step 5: Run migration + tests**

Run: `php glueful migrate:run` (creates `lemma_regions` in dev), then
`vendor/bin/phpunit tests/Integration/Content/RegionValidatorTest.php`
Expected: PASS (5 tests). The test DB runs migrations via the harness — verify `run-test-migrations` picks up 019 (it auto-discovers `database/migrations`).

---

### Task 2: Contracts + reader + events

**Files:**
- Create: `packages/lemma-contracts/src/Content/RegionReader.php`
- Create: `packages/lemma-contracts/src/Content/RegionUpdated.php`
- Create: `app/Content/Regions/EngineRegionReader.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (register reader + repository + validator, WITH `use` imports)

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\Content\RegionReader::blocks(string $slug): ?array` (**null for absent OR empty** — the pinned fallback rule lives HERE, not in templates) and `settings(string $slug): array`; `RegionUpdated` event (`public readonly string $slug`).

- [ ] **Step 1: Contracts**

`packages/lemma-contracts/src/Content/RegionReader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

/**
 * Global chrome regions (global-regions spec). blocks() returns the ordered
 * {id,type,data} list — or null when the region is absent OR saved empty:
 * the pinned fallback rule means templates never distinguish "no region"
 * from "empty region"; both render the theme's hardcoded chrome. Hiding
 * chrome is a page _presentation decision, never an empty region.
 */
interface RegionReader
{
    /** @return list<array<string,mixed>>|null */
    public function blocks(string $slug): ?array;

    /** @return array<string,mixed> */
    public function settings(string $slug): array;
}
```

`packages/lemma-contracts/src/Content/RegionUpdated.php` — copy the exact
shape of `Glueful\Lemma\Contracts\Navigation\MenuUpdated` (base class, BaseEvent
extension or plain readonly event — mirror it verbatim) with `slug`.

- [ ] **Step 2: EngineRegionReader**

`app/Content/Regions/EngineRegionReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Regions;

use Glueful\Lemma\Contracts\Content\RegionReader;

/** RegionRepository-backed reader; per-request construction, no cross-request memo. */
final class EngineRegionReader implements RegionReader
{
    public function __construct(private readonly RegionRepository $regions)
    {
    }

    public function blocks(string $slug): ?array
    {
        $row = $this->regions->find($slug);
        // Pinned null/fallback rule: absent row AND saved-empty list are the
        // same null — fallback chrome; hiding is _presentation's job.
        if ($row === null || $row['blocks'] === []) {
            return null;
        }
        return $row['blocks'];
    }

    public function settings(string $slug): array
    {
        return $this->regions->find($slug)['settings'] ?? [];
    }
}
```

- [ ] **Step 3: Provider registrations**

`app/Providers/LemmaServiceProvider.php` — imports (top, alphabetical-ish with neighbors):

```php
use App\Content\Regions\EngineRegionReader;
use App\Content\Regions\RegionRepository;
use App\Content\Regions\RegionValidator;
use Glueful\Lemma\Contracts\Content\RegionReader;
```

In an appropriate services group (repositoryServices or a new regionServices merged in `services()`):

```php
            RegionRepository::class => [
                'class' => RegionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            RegionValidator::class => [
                'class' => RegionValidator::class,
                'shared' => true,
                'autowire' => true,
            ],
            RegionReader::class => [
                'class' => EngineRegionReader::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 4: Sanity probe**

Run: `php -r "require 'vendor/autoload.php'; \$c = container(Glueful\Framework::create(getcwd())->boot()->getContext()); var_dump(\$c->has(Glueful\Lemma\Contracts\Content\RegionReader::class));"` from the lemma root.
Expected: `bool(true)`.

---

### Task 3: navigation + social_links starter blocks (31 → 33)

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php`
- Create: `packages/lemma-render/themes/default/templates/blocks/navigation.twig`
- Create: `packages/lemma-render/themes/default/templates/blocks/social_links.twig`
- Modify: `packages/lemma-render/themes/default/assets/blocks.css`
- Modify: `tests/Integration/Render/StarterTemplatesTest.php` (fixtures + unsafe-URL matrix)
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php` (31 → 33)
- Test additions: `tests/Integration/Render/BlockLibraryRenderTest.php`

- [ ] **Step 1: Definitions**

In `StarterBlockTypes::definitions()` — `navigation` after `columns` (Layout cluster):

```php
            ['slug' => 'navigation', 'label' => 'Navigation', 'icon' => 'i-lucide-menu',
                'category' => 'Layout',
                'description' => 'Links from a navigation menu (structured source — pick a menu, not links).',
                'schema' => [
                    ['name' => 'menu', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['horizontal', 'vertical']],
                ]],
```

`social_links` after `icon` (Media cluster) + `social_link` child in the Items cluster:

```php
            ['slug' => 'social_links', 'label' => 'Social links', 'icon' => 'i-lucide-share-2',
                'category' => 'Content',
                'description' => 'A row of brand icons linking to social profiles.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['social_link']],
                ]],
```

```php
            ['slug' => 'social_link', 'label' => 'Social link', 'icon' => 'i-lucide-link',
                'category' => 'Items', 'description' => 'One social profile: brand icon + URL.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'required' => true,
                        'pattern' => 'brand:[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'label', 'type' => 'string'],
                ]],
```

- [ ] **Step 2: Templates**

`blocks/navigation.twig` (root div ALWAYS renders — the starter sweep asserts the root class even when the menu is empty/unbound):

```twig
<div class="lemma-block lemma-block-navigation lemma-block-navigation--{{ data.orientation|default('horizontal') }}">
  {% set items = menu(data.menu|default('')) %}
  {% if items %}
    <nav class="lemma-block-navigation__nav">
      {% for item in items %}
        <a href="{{ item.url }}">{{ item.label }}</a>
      {% endfor %}
    </nav>
  {% endif %}
</div>
```

`blocks/social_links.twig` (label falls back to the brand name — strip the `brand:` prefix):

```twig
<div class="lemma-block lemma-block-social-links">
  {% for item in data.items|default([]) %}
    {% set url = item.data.url|default('')|safe_url %}
    {% set name = item.data.label|default(item.data.icon|default('')|replace({'brand:': ''})) %}
    {% if url %}
      <a class="lemma-block-social-links__item" href="{{ url }}" aria-label="{{ name }}">
        {{ icon(item.data.icon|default('')) ?? name }}
      </a>
    {% endif %}
  {% endfor %}
</div>
```

(NOTE: social_links renders its items INLINE, not via `blocks()` — the children are data carriers like logo_cloud images, and an unsafe URL drops the whole item, link and icon.)

- [ ] **Step 3: CSS** (`blocks.css`, near the icon/logo rules)

```css
/* navigation — links from a menu (structured source) */
.lemma-block-navigation__nav { display: flex; gap: var(--space-4); flex-wrap: wrap; }
.lemma-block-navigation--vertical .lemma-block-navigation__nav { flex-direction: column; gap: var(--space-2); }
.lemma-block-navigation__nav a { color: var(--muted); text-decoration: none; }
.lemma-block-navigation__nav a:hover { color: var(--ink); }

/* social_links — a row of brand glyphs */
.lemma-block-social-links { display: flex; gap: var(--space-3); align-items: center; }
.lemma-block-social-links__item { color: var(--muted); font-size: 1.25rem; display: inline-flex; }
.lemma-block-social-links__item:hover { color: var(--ink); }
```

(Region-context margin: `.lemma-block` carries `margin-block` — add
`.lemma-region .lemma-block { margin-block: 0; }` in Task 5's CSS so chrome
blocks don't inherit page-flow spacing.)

- [ ] **Step 4: Tests**

`StarterTemplatesTest::fixture()` additions:

```php
            'navigation' => ['menu' => 'main', 'orientation' => 'horizontal'],
            'social_links' => ['items' => [['id' => 'sl1', 'type' => 'social_link',
                'data' => ['icon' => 'brand:github', 'url' => 'https://github.com/x', 'label' => 'GitHub']]]],
            'social_link' => ['icon' => 'brand:github', 'url' => 'https://github.com/x'],
```

Wait — `social_link` is rendered inline by its parent, NOT as a standalone template; the sweep iterates ALL definitions and expects `blocks/{slug}.twig`. Check how existing data-carrier children (`testimonial`, `faq_item`, `tab`, `step`, `feature`) handle the sweep — they HAVE templates. So create a minimal `blocks/social_link.twig` matching that convention (one item rendered standalone):

```twig
{% set url = data.url|default('')|safe_url %}
{% set name = data.label|default(data.icon|default('')|replace({'brand:': ''})) %}
<div class="lemma-block lemma-block-social-link">
  {% if url %}<a class="lemma-block-social-links__item" href="{{ url }}" aria-label="{{ name }}">{{ icon(data.icon|default('')) ?? name }}</a>{% endif %}
</div>
```

…and social_links.twig SHOULD compose children via `{{ blocks(data.items) }}` instead of inline rendering (consistent with features/testimonials composition — verify how features.twig renders its items and mirror it exactly).

Unsafe-URL matrix addition: `'social_link' => 'url'`.

`SeedBlockTypesTest`: 31 → 33 (update the comment too).

`BlockLibraryRenderTest` addition:

```php
    public function testSocialLinksRenderBrandIconsWithAccessibleLabels(): void
    {
        $out = $this->render([[
            'id' => 'soc1', 'type' => 'social_links',
            'data' => ['items' => [
                ['id' => 'soc1a', 'type' => 'social_link',
                    'data' => ['icon' => 'brand:github', 'url' => 'https://github.com/acme']],
            ]],
        ]]);
        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('fill="currentColor"', $out);
        self::assertStringContainsString('aria-label="github"', $out);
        self::assertStringContainsString('href="https://github.com/acme"', $out);
    }

    public function testNavigationBlockRendersNothingForAnUnknownMenu(): void
    {
        $out = $this->render([[
            'id' => 'nav1', 'type' => 'navigation', 'data' => ['menu' => 'no-such-menu'],
        ]]);
        self::assertStringContainsString('lemma-block-navigation', $out); // root always
        self::assertStringNotContainsString('<nav', $out);                 // no empty nav
    }
```

- [ ] **Step 5: Run + seed**

Run: `vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Content/SeedBlockTypesTest.php`
Expected: PASS.
Run: `php glueful lemma:blocks:seed` — Expected: `Created 2, skipped 31`.

---

### Task 4: region_blocks()/region_settings() render helper + policy v7

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (soft-bind RegionReader into the extension)
- Modify: `packages/lemma-render/src/Templates/TemplatePolicy.php` (FUNCTIONS + CACHE_VERSION 7)
- Modify: `tests/Integration/Render/BlocksRenderingTest.php` (version pin 6 → 7 + lint checks)

**Interfaces:**
- Produces: Twig `region_blocks(slug): ?Markup` — THE only region render path
  (review pin): resolves the region, suppresses canvas annotation and
  edit-in-place marks for its render subtree INTERNALLY (render-context
  state, never a template decision), composes through the real `blocks()`
  machinery, returns Markup or null on every unavailable state. Also
  `region_settings(slug): array`. **`blocks()`'s public signature is
  unchanged** — no annotation toggle leaks into the template surface.

- [ ] **Step 1: Failing policy test** — in `BlocksRenderingTest`:

```php
        self::assertContains('region_blocks', TemplatePolicy::FUNCTIONS);
        self::assertContains('region_settings', TemplatePolicy::FUNCTIONS);
        self::assertSame(7, TemplatePolicy::CACHE_VERSION); // 7 = region_blocks/region_settings joined
        // …existing lints…
        self::assertSame([], $linter->lint('{{ region_blocks(\'header\') }}'));
        self::assertSame([], $linter->lint('{{ region_settings(\'header\').width|default(\'contained\') }}'));
```

Run the filter — Expected: FAIL.

- [ ] **Step 2: Policy**

```php
    public const CACHE_VERSION = 7; // bumped: 'region_blocks' + 'region_settings' joined FUNCTIONS (global-regions spec)
```

FUNCTIONS: append `'region_blocks', 'region_settings'` after `'icon'`.

- [ ] **Step 3: Extension**

Imports: `use Glueful\Lemma\Contracts\Content\RegionReader;`

Constructor (after `$icons`):

```php
        /** Soft-bound (global-regions spec): null → region_blocks() returns null → fallback chrome. */
        private readonly ?RegionReader $regions = null,
```

Functions list (region_blocks needs env+context exactly like blocks — it
composes through it):

```php
            new TwigFunction('region_blocks', $this->regionBlocks(...), [
                'needs_environment' => true,
                'needs_context' => true,
                'is_safe' => ['html'],
            ]),
            new TwigFunction('region_settings', $this->regionSettings(...)),
```

Methods:

```php
    /**
     * The ONE region render path (global-regions spec §10): resolves the
     * region and composes it through the real blocks() machinery with canvas
     * annotation and edit-in-place marking suppressed for the subtree —
     * chrome block ids are not entry blocks; annotated wrappers would corrupt
     * the canvas DOM↔id bridge. Suppression is render-context state inside
     * this helper; blocks() keeps its public signature. Null for EVERY
     * unavailable state — reader unbound, region absent, saved empty (the
     * reader folds absent/empty; unbound folds here) — so templates render
     * fallback chrome on null; hiding is _presentation's decision.
     *
     * @param array<string,mixed> $context
     */
    public function regionBlocks(Environment $env, array $context, string $slug): ?\Twig\Markup
    {
        $list = $this->regions?->blocks($slug);
        if ($list === null || $list === []) {
            return null;
        }
        $saved = $this->annotateBlocks;
        $this->annotateBlocks = false;
        try {
            $html = $this->blocks($env, $context, $list);
        } finally {
            $this->annotateBlocks = $saved;
        }
        return new \Twig\Markup($html, 'UTF-8');
    }

    /** @return array<string,mixed> */
    public function regionSettings(string $slug): array
    {
        return $this->regions?->settings($slug) ?? [];
    }
```

(Match `blocks()`'s real PHP signature when delegating — check its parameter
list (env, context, list, …) and pass exactly what it expects; if it returns
Markup already, unwrap/rewrap consistently. `blocks()` itself is untouched.)

- [ ] **Step 4: Provider soft-bind** — in `makeRenderContextExtension`, after the `IconSet` arg:

```php
            // region()/region_settings() (global-regions spec): soft-bound;
            // null = fallback chrome everywhere.
            $container->has(RegionReader::class)
                ? $container->get(RegionReader::class)
                : null,
```

(+ `use Glueful\Lemma\Contracts\Content\RegionReader;` in the provider.)

- [ ] **Step 5: Run**

Run: `vendor/bin/phpunit tests/Integration/Render/` — Expected: PASS.

---

### Task 5: Layout integration + render tests

**Files:**
- Modify: `packages/lemma-render/themes/default/templates/layout.twig`
- Modify: `packages/lemma-render/themes/default/assets/site.css` (region modifier classes) + `blocks.css` (region margin reset)
- Test: `tests/Integration/Render/RegionRenderingTest.php` (new)

- [ ] **Step 1: Failing render tests**

`tests/Integration/Render/RegionRenderingTest.php` — LemmaTestCase; render THROUGH the RenderController path or a layout-level Twig render with the container extension (mirror how `PreviewSessionTest`/render tests drive a full page — reuse their helper). Cases:

```php
    public function testSavedHeaderRegionRendersThroughBlocks(): void
    {
        // seed block types + save a header region (logo + navigation) via RegionRepository
        // render the homepage → assert 'lemma-region-header', 'lemma-block-navigation'
        // and that the hardcoded  <a href="/" class="site-name"> fallback is ABSENT
    }

    public function testAllNullPathsRenderFallbackChrome(): void
    {
        // (a) no region row  (b) region saved with [] blocks
        // both → assert class="site-name" fallback present, no lemma-region-header
    }

    public function testPresentationHiddenSuppressesRegionAndFallback(): void
    {
        // entry _presentation: {header: 'hidden'} → neither lemma-region-header
        // nor site-name in the output; footer unaffected
    }

    public function testHeaderSettingsClassesLand(): void
    {
        // settings {sticky: true, width: 'full'} →
        // 'lemma-region-header--sticky' and 'lemma-region-header--full' on <header>
    }

    public function testRegionBlocksAreNeverCanvasAnnotated(): void
    {
        // render via the PREVIEW/canvas path (annotation on, per PreviewSessionTest)
        // with a saved header region → entry blocks carry .lemma-preview-block,
        // the region's block ids do NOT appear in any lemma-preview-block marker
    }
```

(Write these as real tests against the actual harness helpers — the sketch
above pins the assertions; the file must contain full runnable code. Look at
`PreviewSessionTest` for the render-and-assert plumbing and reuse it.)

Run — Expected: FAIL (no region markup yet).

- [ ] **Step 2: Layout**

Replace the `<header>` block in `layout.twig`:

```twig
  {% set headerHidden = (presentation.header|default('default')) == 'hidden' %}
  {% set headerHtml = headerHidden ? null : region_blocks('header') %}
  {% if headerHtml %}
    {% set hs = region_settings('header') %}
    <header class="site-header lemma-region lemma-region-header lemma-region-header--{{ hs.width|default('contained') }}{% if hs.sticky|default(false) %} lemma-region-header--sticky{% endif %}">
      <div class="site-header__inner">{{ headerHtml }}</div>
    </header>
  {% elseif not headerHidden %}
  <header class="site-header">
    <div class="site-header__inner">
      {% set logo = site_logo() %}
      <a href="/" class="site-name">
        {%- if logo -%}
          <img class="site-logo" src="{{ logo }}" alt="{{ site.name }}">
        {%- else -%}
          {{ site.name }}
        {%- endif -%}
      </a>
      <nav class="site-nav">
        {% for item in menu('main') %}
          <a href="{{ item.url }}">{{ item.label }}</a>
        {% endfor %}
      </nav>
    </div>
  </header>
  {% endif %}
```

Footer, same shape:

```twig
  {% set footerHidden = (presentation.footer|default('default')) == 'hidden' %}
  {% set footerHtml = footerHidden ? null : region_blocks('footer') %}
  {% if footerHtml %}
    {% set fs = region_settings('footer') %}
    <footer class="site-footer lemma-region lemma-region-footer lemma-region-footer--{{ fs.width|default('contained') }}">
      <div class="site-footer__inner">{{ footerHtml }}</div>
    </footer>
  {% elseif not footerHidden %}
  <footer class="site-footer">
    <div class="site-footer__inner"><small>{{ site.name }}</small></div>
  </footer>
  {% endif %}
```

- [ ] **Step 3: CSS**

`site.css` (near the existing `.site-header` rules):

```css
/* Region-backed chrome (global-regions spec) */
.lemma-region-header--sticky { position: sticky; top: 0; z-index: 40; background: var(--bg); }
.lemma-region-header--full .site-header__inner,
.lemma-region-footer--full .site-footer__inner { max-width: none; }
```

`blocks.css`:

```css
/* Chrome context: region blocks flow inline, not as page bands. */
.lemma-region .lemma-block { margin-block: 0; }
.lemma-region .site-header__inner > .lemma-block,
.lemma-region .site-footer__inner > .lemma-block { max-width: none; padding-inline: 0; }
```

(Blocks like `logo`/`icon` carry their own `max-width: var(--container); margin-inline: auto;` — the reset above keeps chrome children from double-containing inside the already-contained `__inner`. Verify visually against the seeded default in Task 8.)

- [ ] **Step 4: Run**

Run: `vendor/bin/phpunit tests/Integration/Render/RegionRenderingTest.php` — Expected: PASS (the `_presentation` case may need Task 6 first — if so, run it after Task 6 and note the ordering).

---

### Task 6: `_presentation` header/footer keys

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php` (`validatePresentation`)
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (`presentationContext` — confirm pass-through/defaults)
- Test: extend the existing `_presentation` validation test file (find it: `grep -rl validatePresentation tests/`) + the render case in Task 5.

- [ ] **Step 1: Failing validation test** — new cases in the existing `_presentation` test:

```php
        // header/footer: 'default' | 'hidden' only (variants are future vocabulary)
        $clean = $validator->validate($schema, ['_presentation' => ['header' => 'hidden']], true);
        self::assertSame('hidden', $clean['_presentation']['header']);
        try {
            $validator->validate($schema, ['_presentation' => ['footer' => 'variant:mini']], true);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('_presentation.footer', $e->getErrors());
        }
```

- [ ] **Step 2: Implement** — in `validatePresentation`, alongside `show_title`/`layout`:

```php
            if ($key === 'header' || $key === 'footer') {
                if (!in_array($subValue, ['default', 'hidden'], true)) {
                    throw new ValidationException([
                        "_presentation.{$key}" => "must be 'default' or 'hidden'",
                    ]);
                }
                $clean[$key] = $subValue;
                continue;
            }
```

Update the docblock vocabulary comment. Then confirm `presentationContext` in RenderController passes unknown-to-it keys through (it composes `_presentation` over theme.json/built-ins — check whether it whitelists keys; if it does, add `header`/`footer` with built-in default `'default'`).

- [ ] **Step 3: Run** — the validation test + `RegionRenderingTest` hidden case.
Expected: PASS.

---

### Task 7: Admin API + cache purge listener

**Files:**
- Create: `app/Http/Controllers/RegionAdminController.php`
- Create: `packages/lemma-render/src/Listeners/PurgeRenderCacheOnRegionUpdate.php`
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php` (controller registration), `packages/lemma-render/src/LemmaRenderServiceProvider.php` (listener registration + factory)
- Test: `tests/Integration/Http/RegionAdminTest.php` (new), cache-purge case in `RegionRenderingTest`

- [ ] **Step 1: Controller** (mirror `GeneralSettingsController` conventions — BaseController, typed responses, events):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\Regions\RegionDefinitions;
use App\Content\Regions\RegionRepository;
use App\Content\Regions\RegionValidator;
use Glueful\Lemma\Contracts\Content\RegionUpdated;
// … BaseController/EventService/Request imports per the house controller shape

final class RegionAdminController extends BaseController
{
    // GET /admin/regions → every region: {slug, blocks, settings, palette, settings_keys}
    // (palette + settings_keys ship so the SPA picker/controls need no hardcoding);
    // absent rows surface as {blocks: [], settings: {}} so the editor always round-trips.
    public function index(): mixed { /* … */ }

    // PUT /admin/regions/{slug} → 404 unknown slug; RegionValidator (422 on
    // palette/settings/schema); save; dispatch RegionUpdated($slug); return the row.
    public function update(string $slug, /* request DTO or Request */): mixed { /* … */ }
}
```

Write the real bodies following `GeneralSettingsController` exactly (context handling, `$this->events->dispatch(new RegionUpdated($slug))` after save, response envelope). Routes in `routes/lemma_admin.php`:

```php
    // Global chrome regions (header/footer block lists) — chrome is content policy.
    $router->get('/regions', [RegionAdminController::class, 'index'])
        ->middleware('lemma_permission:content.view');
    $router->put('/regions/{slug}', [RegionAdminController::class, 'update'])
        ->middleware('lemma_permission:content.manage');
```

(+ controller registration in `LemmaServiceProvider::services()` with its `use` import — the Lemma register-controllers rule.)

- [ ] **Step 2: Purge listener** — `PurgeRenderCacheOnRegionUpdate` copies `PurgeRenderCacheOnMenuUpdate` verbatim (constructor, `invalidateTags(['lemma:render:page'])`, method `onRegionUpdated`). Register in `LemmaRenderServiceProvider`: service definition + factory (mirror `makePurgeRenderCacheOnMenuUpdate`) + boot wiring NEXT TO the MenuUpdated listener:

```php
            $events->addListener(
                RegionUpdated::class,
                [app($context, PurgeRenderCacheOnRegionUpdate::class), 'onRegionUpdated'],
            );
```

(+ `use Glueful\Lemma\Contracts\Content\RegionUpdated;` and the listener import.)

- [ ] **Step 3: Tests**

`RegionAdminTest`: GET exposes both slugs with palettes; PUT round-trips blocks+settings; PUT with `gallery` in header → 422 `blocks.0.type`; PUT unknown slug → 404; PUT invalid settings → 422. Follow the house HTTP-test harness (see how `GeneralSettings`/media admin endpoints are integration-tested and mirror the auth/permission setup).

`RegionRenderingTest` cache case (the spec-pinned wiring proof): render a page (cached), save a region through the REAL event path (dispatch `RegionUpdated` via `EventService` or call the controller), re-render, assert the new chrome appears. If the test cache driver doesn't support tags, assert via the listener being registered on `EventService` (`hasListeners`) AND a direct `invalidateTags` spy — prefer the end-to-end form if the harness allows it.

- [ ] **Step 4: OpenAPI**

Run: `composer run docs:openapi && cd admin && pnpm gen:api` — regenerates the spec + typed client for `/admin/regions`.

---

### Task 8: Setup seeding

**Files:**
- Modify: `app/Setup/SetupService.php`
- Test: extend the setup test (find: `grep -rl "SetupService" tests/Integration | head -1`)

- [ ] **Step 1: Failing test** — fresh install seeds both regions:

```php
        $header = $this->container()->get(\App\Content\Regions\RegionRepository::class)->find('header');
        self::assertNotNull($header);
        self::assertSame(['logo', 'navigation'], array_column($header['blocks'], 'type'));
        $footer = /* … */->find('footer');
        self::assertSame(['rich_text'], array_column($footer['blocks'], 'type'));
```

- [ ] **Step 2: Implement** — in `SetupService::install`, after the existing content-type/menu seeding (ids via the same uuid helper the service already uses for seeded content — check and reuse; NO `Math.random`-style ad-hoc ids):

```php
        $regions = app($this->context, RegionRepository::class);
        $regions->save('header', [
            ['id' => $this->uuid(), 'type' => 'logo', 'data' => ['size' => 'medium', 'link_home' => true]],
            ['id' => $this->uuid(), 'type' => 'navigation', 'data' => ['menu' => 'main']],
        ], ['sticky' => false, 'width' => 'contained'], null);
        $regions->save('footer', [
            ['id' => $this->uuid(), 'type' => 'rich_text', 'data' => ['content' => '<p>' . $siteName . '</p>']],
        ], ['width' => 'contained'], null);
```

(Match the block-id generator to what the admin/canvas produce — find how block ids are minted (`grep -rn "blockId\|newBlockId" admin/src app/` ) and use the same format; `rich_text`'s field name must match its actual schema (`content` vs `html` — check the definition). Seed AFTER `lemma:blocks:seed`-equivalent block-type creation if setup seeds block types — verify setup's ordering.)

- [ ] **Step 3: Run** — setup tests pass; manual sanity: fresh-install path only, existing installs untouched (no seeding outside `install()`).

---

### Task 9: Admin SPA — Design → Header & footer

**Files:**
- Create: `admin/src/queries/regions.ts`
- Create: `admin/src/pages/design/regions.vue` (route + nav entry — find how existing settings pages register in the sidebar and mirror)
- Test: `admin/src/__tests__/regionsPage.spec.ts`

- [ ] **Step 1: Queries** — `useRegions()` (GET list → map by slug), `useSaveRegion()` (PUT, invalidates the list). Typed via the regenerated client paths (`/admin/regions`, `/admin/regions/{slug}`).

- [ ] **Step 2: Page** — one page, two `UCard` sections (Header, Footer). Each section:
  - the existing blocks editor: `BlocksField` (via the field registry or direct import — mirror how the entry form hosts it) with `field: { name: 'blocks', type: 'blocks', blockTypes: <palette from GET> }` and a local `v-model` list;
  - header section adds: `USwitch` sticky (`data-test="region-header-sticky"`), width `USelect` contained/full (`data-test="region-header-width"`);
  - per-section Save button (`data-test="save-region-header"` / `-footer`) with dirty chip (general-settings pattern: dirty flag + no clobber-on-refetch guard — copy the `syncing`/`dirty` watch pair from `settings/general/index.vue`);
  - 422 palette errors surface via the standard notify + field-error path.

- [ ] **Step 3: Spec** — `regionsPage.spec.ts` (house rules: mock queries module, data-test hooks, memory-router if the page renders RouterLink, `attachTo` for teleports):
  - renders both sections from the GET payload;
  - editing then Save PUTs `{blocks, settings}` for that region only;
  - picker allowlist honored: the add-block affordance receives the palette (assert via the component's filtered list at the logic level or a data-test on filtered options — do NOT assert Reka portal DOM);
  - dirty chip shows after edit, clears after save.

- [ ] **Step 4: Gates**

Run: `cd admin && pnpm vitest run && pnpm type-check && pnpm lint`
Expected: all green.

---

### Task 10: Full gates + CHANGELOG + stage

- [ ] **Step 1:** `vendor/bin/phpunit && composer run phpcs` (lemma root) — all green (~1340+ tests).
- [ ] **Step 2:** `cd admin && pnpm vitest run && pnpm type-check && pnpm lint` — all green.
- [ ] **Step 3: Executable-checks sweep** — map the spec's testing section to green tests: validator palette/settings/empty-save; render null-paths/hidden/settings-classes/annotation-suppression; policy v7 lints; navigation/social blocks; API round-trip + 404/422; purge wiring; setup seeding; SPA page.
- [ ] **Step 4: CHANGELOG** — `[Unreleased]` Added entry: global regions (structured header/footer block regions, server-validated palettes, region()/region_settings() + CACHE_VERSION 7, navigation + social_links blocks, RegionUpdated broad purge, _presentation header/footer, Design → Header & footer admin page, setup seeding, fallback chrome rule).
- [ ] **Step 5: Stage everything** (`git add` the touched paths + spec + plan + CHANGELOG; `git status --short`). NO commit — wait for "commit all".
