# Icon Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Icon-name fields become a compact preview+Choose field opening a searchable, page-numbered modal listing the render pack's vendored icon inventory.

**Architecture:** A pack-side `IconInventory` (glob + memo over `resources/icons/{set}`) soft-resolved by a new app `IconInventoryController` (`GET /admin/icons?set=`). `FieldDefinition` gains `STRING_FORMATS = ['icon','brand-icon']` parsed in a string branch mirroring the text branch (format = editor hint; validation stays pattern/enum, with seeded brand-icon schemas PAIRING the `brand:` pattern — P2 pin). Admin: `StringField` branches on format to a compact `IconField` (the TextField→RichText pattern), which opens `IconPickerModal` (client-filtered search, 80/page numbered pagination, one page in DOM, pinned selection, Clear). `MenuTreeEditor` uses `IconField` directly. Brand mode lists bare names, reads/writes `brand:`-prefixed values.

**Tech Stack:** PHP 8.3, PHPUnit; Vue 3 + Nuxt UI, vitest.

**Spec:** `docs/superpowers/specs/2026-07-05-icon-picker-design.md`

## Global Constraints

- Inventory truth = vendored directories; response `{icons: string[]}` bare names, sorted; per-process memo; `content.view` gate; unknown set 422; render pack absent → 409.
- `TEXT_FORMATS` untouched. `format` adds NO validation; seeded `brand-icon` schemas pair `pattern: brand:[a-z0-9]+(-[a-z0-9]+)*` (P2 pin, tested both directions).
- Modal: search focused on open, resets page to 1; 80/page with page numbers + shown-of-total count; ≤ 80 tiles in DOM ever; empty state; pinned current selection; Clear for optional fields; select writes + closes.
- Previews are cosmetic (`i-lucide-*`/`i-simple-icons-*`, name-chip fallback); the saved value is always the server name (brand fields: `brand:`-prefixed).
- Session conventions: stage only; commit on "commit all"; CHANGELOG; no attribution.

---

### Task 1: Inventory — pack helper + endpoint + tests

**Files:**
- Create: `packages/lemma-render/src/Templates/IconInventory.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (register)
- Create: `app/Http/Controllers/IconInventoryController.php`
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php` (controller registration)
- Test: `tests/Integration/Http/IconInventoryApiTest.php` (new)

**Interfaces:**
- Produces: `Glueful\Lemma\Render\Templates\IconInventory::names(string $set): ?array` (null = unknown set; sorted bare names, per-process memo) — registered shared in the render provider; `GET /v1/admin/icons?set=lucide|brands`.

- [ ] **Step 1: Failing endpoint test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\IconInventoryController;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class IconInventoryApiTest extends LemmaTestCase
{
    private function controller(): IconInventoryController
    {
        return $this->container()->get(IconInventoryController::class);
    }

    public function testLucideInventoryMatchesTheVendoredDirectory(): void
    {
        $resp = $this->controller()->index(Request::create('/x', 'GET', ['set' => 'lucide']));
        self::assertSame(200, $resp->getStatusCode());
        $icons = json_decode((string) $resp->getContent(), true)['data']['icons'];
        self::assertContains('activity', $icons);
        self::assertGreaterThan(1500, count($icons));
        // Glob parity, not a pinned literal.
        $dir = $this->appContext()->getBasePath()
            . '/packages/lemma-render/resources/icons/lucide/*.svg';
        self::assertCount(count(glob($dir) ?: []), $icons);
        $sorted = $icons;
        sort($sorted);
        self::assertSame($sorted, $icons); // sorted
    }

    public function testBrandsInventoryIsTheCuratedSetBareNames(): void
    {
        $resp = $this->controller()->index(Request::create('/x', 'GET', ['set' => 'brands']));
        $icons = json_decode((string) $resp->getContent(), true)['data']['icons'];
        self::assertContains('github', $icons);
        self::assertNotContains('brand:github', $icons); // BARE names
        self::assertCount(27, $icons);
    }

    public function testUnknownSetIs422(): void
    {
        $resp = $this->controller()->index(Request::create('/x', 'GET', ['set' => 'emoji']));
        self::assertSame(422, $resp->getStatusCode());
    }
}
```

Run: FAIL (controller missing).

- [ ] **Step 2: Pack helper**

`packages/lemma-render/src/Templates/IconInventory.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

/**
 * The vendored icon inventory (icon-picker spec §1): what the admin picker
 * may offer is EXACTLY what icon() can render — sourced from the shipped
 * directories, never from client-side icon-set assumptions. Per-process memo;
 * the inventory only changes on deploy, so there is no invalidation surface.
 */
final class IconInventory
{
    public const SETS = ['lucide', 'brands'];

    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string>|null null = unknown set */
    public function names(string $set): ?array
    {
        if (!in_array($set, self::SETS, true)) {
            return null;
        }
        if (!isset($this->memo[$set])) {
            $files = glob($this->root . '/' . $set . '/*.svg') ?: [];
            $names = array_map(static fn (string $f): string => basename($f, '.svg'), $files);
            sort($names);
            $this->memo[$set] = array_values($names);
        }
        return $this->memo[$set];
    }
}
```

Register in `LemmaRenderServiceProvider::services()` + factory (same pack root the `IconSet` construction uses):

```php
            IconInventory::class => [
                'shared' => true,
                'factory' => [self::class, 'makeIconInventory'],
            ],
// …
    public static function makeIconInventory(ContainerInterface $container): IconInventory
    {
        return new IconInventory(dirname(__DIR__) . '/resources/icons');
    }
```

(+ the `use` import. NOTE `dirname(__DIR__)` from the provider = pack root — same expression as the IconSet arg; verify.)

- [ ] **Step 3: Controller + route**

`app/Http/Controllers/IconInventoryController.php` — soft-resolve (regions-preview posture):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Lemma\Render\Templates\IconInventory;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The vendored icon inventory for the admin icon picker (icon-picker spec §1):
 * names come from the render pack's shipped SVG directories, so the picker can
 * only offer icons icon() can actually render. Requires `content.view`.
 */
final class IconInventoryController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/icons?set=lucide|brands */
    #[ApiOperation(
        summary: 'List vendored icons',
        description: 'Bare icon names from the render pack\'s vendored set (lucide|brands), sorted. '
            . 'The picker\'s source of truth — parity with icon() by construction. Requires `content.view`.',
        tags: ['Lemma Icons'],
    )]
    #[ApiResponse(200, description: 'Sorted icon names.')]
    #[ApiResponse(409, description: 'Render pack unavailable.')]
    #[ApiResponse(422, description: 'Unknown set.')]
    public function index(Request $request): Response
    {
        $container = container($this->context);
        if (!$container->has(IconInventory::class)) {
            return Response::error('Icons unavailable: the render pack is not active.', 409);
        }
        $set = (string) $request->query->get('set', 'lucide');
        $names = $container->get(IconInventory::class)->names($set);
        if ($names === null) {
            return Response::validation(['set' => "must be one of: lucide, brands"]);
        }
        return Response::success(['icons' => $names], 'Icons retrieved.');
    }
}
```

(Verify `Response::validation` array shape against GeneralSettingsController's usage. Register the controller in `LemmaServiceProvider::services()` WITH its import; route in `routes/lemma_admin.php` near /regions:)

```php
    // Vendored icon inventory for the admin icon picker.
    $router->get('/icons', [IconInventoryController::class, 'index'])
        ->middleware('lemma_permission:content.view');
```

- [ ] **Step 4: Run + OpenAPI**

`vendor/bin/phpunit tests/Integration/Http/IconInventoryApiTest.php` — PASS.
`composer run docs:openapi && cd admin && pnpm gen:api`.

---

### Task 2: `STRING_FORMATS` + seeded schemas + validation tests

**Files:**
- Modify: `app/Content/Schema/FieldDefinition.php`
- Modify: `app/Content/Http/DTOs/FieldDefinitionData.php` (accept the widened union — check where it validates format values, if anywhere; the parse layer is the enforcer)
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (icon block, social_link, feature)
- Tests: schema-parse cases (find the FieldDefinition/schema parse test file), `SeedBlockTypesTest` format pins, brand-prefix API rejection (BlocksValidationTest-style)

- [ ] **Step 1: Failing parse tests** — in the schema parse test file (find via `grep -rl "SchemaParseException" tests/Unit tests/Integration | head -1`):

```php
    public function testStringFormatsParseAndRejectCrossTypeValues(): void
    {
        // string + icon/brand-icon parse; format is presentation metadata.
        $s = ContentTypeSchema::fromArray([
            ['name' => 'icon', 'type' => 'string', 'format' => 'icon'],
        ]);
        self::assertSame('icon', $s->fields()[0]->format);

        // rich is TEXT-only…
        try {
            ContentTypeSchema::fromArray([['name' => 'x', 'type' => 'string', 'format' => 'rich']]);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException) {
        }
        // …and icon is STRING-only.
        try {
            ContentTypeSchema::fromArray([['name' => 'x', 'type' => 'text', 'format' => 'icon']]);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException) {
        }
    }
```

- [ ] **Step 2: Implement the string branch** — in `FieldDefinition` (next to `TEXT_FORMATS`):

```php
    /** Editor-hint formats for STRING fields (icon-picker spec §2): presentation
     *  metadata only — validation stays with pattern/enum. */
    public const STRING_FORMATS = ['icon', 'brand-icon'];
```

…and in the parse, extend the format block:

```php
        $format = null;
        if ($type === 'text') {
            // …existing text branch unchanged…
        } elseif ($type === 'string') {
            $rawFormat = $raw['format'] ?? null;
            if (is_string($rawFormat) && $rawFormat !== '') {
                if (!in_array($rawFormat, self::STRING_FORMATS, true)) {
                    throw new SchemaParseException(
                        "string field '{$name}' has invalid format (expected icon|brand-icon)"
                    );
                }
                $format = $rawFormat;
            }
        }
```

(Null default for strings — a plain string field has NO format, unlike text's
'plain'. Verify the existing docblock comment above the block and update it.)

- [ ] **Step 3: Seeded schemas** — `StarterBlockTypes`:
  - icon block: `['name' => 'icon', …, 'pattern' => …, 'format' => 'icon']`
  - feature: same addition.
  - social_link: `'format' => 'brand-icon'` (pattern ALREADY `brand:[a-z0-9]+(-[a-z0-9]+)*` — the P2 pairing).

`SeedBlockTypesTest`: pin `format` on all three. Brand-prefix rejection test (P2, both directions — server side here, picker side in Task 3): a `social_link` save via `FieldValidator` with `icon: 'github'` 422s, `icon: 'brand:github'` passes (extend `BlockLibraryRenderTest`'s validation style or `BlocksValidationTest`).

- [ ] **Step 4: Dev instance** — additive `updateSchema` script (scratchpad) adding `format` to the three fields (schema only, no content rewrite). Run + verify.

- [ ] **Step 5: Run** — schema tests + SeedBlockTypes + full render group.

---

### Task 3: Admin — query, modal, field, wiring + tests

**Files:**
- Create: `admin/src/queries/icons.ts`
- Create: `admin/src/fields/components/IconPickerModal.vue`
- Create: `admin/src/fields/components/IconField.vue`
- Modify: `admin/src/fields/components/StringField.vue` (format branch), `admin/src/fields/types.ts` (format union), `admin/src/fields/normalize.ts` (verify format passthrough — it already forwards)
- Modify: `admin/src/pages/navigation/components/MenuTreeEditor.vue`
- Tests: `admin/src/__tests__/iconPicker.spec.ts` (new)

- [ ] **Step 1: Query** (`icons.ts`):

```ts
import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

export type IconSetName = 'lucide' | 'brands'

export async function fetchIcons(set: IconSetName): Promise<string[]> {
  const { data, error, response } = await client.GET('/icons', {
    params: { query: { set } } as never,
  })
  if (error) throw toApiError(error, response)
  const payload = data as unknown as { data?: { icons?: string[] } } | undefined
  return payload?.data?.icons ?? []
}

export function useIcons(set: () => IconSetName) {
  return useQuery({ key: () => ['icons', set()], query: () => fetchIcons(set()) })
}
```

- [ ] **Step 2: `IconPickerModal.vue`** — props `{ set: 'lucide' | 'brands', modelValue?: string }` (modelValue = the DISPLAY name, bare), `open` defineModel, emits `select: [name: string]` (bare) and `clear: []`:
  - search `UInput` focused on open (`ref` + watch open → focus), value resets on open; `page` resets to 1 on open AND on search change;
  - `filtered = computed(() => names.filter(n => n.includes(q)))`, `pageCount = ceil(filtered/80)`, `visible = filtered.slice((page-1)*80, page*80)` — ONE page in DOM (the review pin);
  - grid of tiles: `UIcon :name="prefix + n"` (prefix `i-lucide-` / `i-simple-icons-`) + the name, `data-test="icon-tile-{n}"`; a broken admin-side preview still shows the name (UIcon renders empty; the tile stays selectable);
  - header strip: current selection pinned (icon + name, `data-test="icon-picker-current"`) + Clear button (`data-test="icon-picker-clear"`, emits `clear` + closes);
  - footer: `Showing X–Y of N` + `UPagination` (or prev/number/next buttons if UPagination fights jsdom — prefer UPagination, fall back per the vitest house rules) `data-test="icon-picker-pages"`;
  - empty state (`UEmpty`) when no matches;
  - clicking a tile emits `select(name)` and closes.

- [ ] **Step 3: `IconField.vue`** — the compact field (spec §4):

```
props: { field: FieldDef }   // field.format decides the set + prefix handling
model: string | undefined
```

- `brand = field.format === 'brand-icon'`; `display = brand ? model?.replace(/^brand:/, '') : model`;
- renders `UFormField` with: `UIcon` preview (admin prefix + display, when set) + name text + "Choose" `UButton` (`data-test="icon-field-choose"`) + Clear (`data-test="icon-field-clear"`, only when set & !field.required);
- opens `IconPickerModal :set="brand ? 'brands' : 'lucide'" :model-value="display"`;
- `@select="(n) => model = brand ? 'brand:' + n : n"` (the P2 write-side proof), `@clear="model = undefined"`.

`StringField.vue` — the TextField pattern:

```html
<IconField v-if="field.format === 'icon' || field.format === 'brand-icon'" :field="field" v-model="model" />
<UFormField v-else …existing input…>
```

`types.ts`: `format?: 'plain' | 'rich' | 'icon' | 'brand-icon'`.

- [ ] **Step 4: MenuTreeEditor** — replace the icon `UInput` + preview chip with `IconField` bound to `item.icon` via a synthetic def:

```html
<IconField
  :field="{ name: 'icon', label: '', type: 'string', format: 'icon' }"
  :model-value="item.icon ?? undefined"
  data-test="tree-item-icon"
  @update:model-value="(v: unknown) => { item.icon = (v as string | undefined) ?? null; changed() }"
/>
```

(Check IconField renders compactly enough for the tree row — size sm; adjust with a `size` prop if needed.)

- [ ] **Step 5: Vitest** (`iconPicker.spec.ts`) — mock `@/queries/icons` with ~200 fake names + real component mounts (`attachTo`, teleport rules):
  - search filters and RESETS page to 1;
  - pagination: 80 tiles max in DOM (assert `findAll('[data-test^="icon-tile-"]').length <= 80`), page 2 shows the next slice, total count text correct;
  - select emits the bare name and closes;
  - `IconField` with `format: 'brand-icon'`: displays bare name for a `brand:github` model, and selecting `x` emits `brand:x` (the P2 picker-side proof); Clear emits undefined;
  - a plain string field (no format) keeps the text input (StringField branch).

- [ ] **Step 6: Gates** — `pnpm vitest run && pnpm type-check && pnpm lint`.

---

### Task 4: Full gates + CHANGELOG + stage

- [ ] **Step 1:** `vendor/bin/phpunit && composer run phpcs`; admin gates already run in Task 3.
- [ ] **Step 2: CHANGELOG** — Added: icon picker (vendored-inventory endpoint `GET /admin/icons`; `format: icon|brand-icon` string-field editor hints — `STRING_FORMATS`, validation unchanged, seeded brand-icon schemas pair the `brand:` pattern; searchable page-numbered `IconPickerModal` (80/page, one page in DOM) behind a compact `IconField`, adopted by the icon block, `social_link`, `feature` and navigation menu items).
- [ ] **Step 3: Stage** everything (incl. spec, plan, regenerated schema.d.ts/openapi.json, dev updateSchema note). NO commit — wait for "commit all".
