# Page/Block Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `blocks` content field type (ordered typed blocks inside entry JSON) backed by a global admin-defined block-type registry, edited with a structured block-list widget, rendered through `blocks/{type}.twig`.

**Architecture:** Blocks are a normal content field — validation/versions/publish/localization/delivery are untouched by construction. A `lemma_block_types` registry (in-app, `App\Content`) holds reusable block schemas that reuse `FieldDefinition`; `FieldValidator` validates each block's `data` against its block type per-block with dot-path errors; the render pack gains a `blocks()` Twig function (hierarchy `blocks/{type}.twig`, DB-template overridable) added to the sandbox allowlist with a `CACHE_VERSION` bump.

**Tech Stack:** PHP 8.4 / Glueful, Twig 3.27, PostgreSQL test suite, Vue 3 + Nuxt UI admin SPA.

**Spec:** `docs/superpowers/specs/2026-07-03-page-block-builder-design.md` — read it first.

## Global Constraints

- **Commit gate:** STAGE at two groupings (after Task 5, after Task 7); commit ONLY on explicit user authorization. No Claude/Anthropic attribution anywhere.
- **phpcs:** `vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"` — never through a pipe. `composer boundaries` after backend tasks.
- **Registry is in-app** (`App\Content`) — content-schema machinery. The render pack NEVER reads the registry (template-convention only); pack boundaries stay clean.
- **Pins (spec, verbatim):** no `blocks`/`localized: true`/`filterable: true` fields inside block schemas (422 at block-type save); `blockTypes` is a **picker constraint only — never enforced in `FieldValidator`**; localization = outer field only; references inside blocks validated but **never auto-expanded** (no `ReferenceResolver` change); schema evolution non-migrating (cleaned payload strips unknown block-data keys on save; history untouched); **deactivate, no hard delete**; block-instance `id` = nanoid, unique in list, server-generated when missing; slugs immutable after create; render context `{block, data, entry, index}` with `blocks()` `needs_context` + `needs_environment`; missing template → prod HTML comment / debug placeholder / log once per type; **`blocks` joins `TemplatePolicy::FUNCTIONS` + `CACHE_VERSION` bumps to 2**.
- **Route auth (spec §1):** reads `lemma_permission:content.view`, mutations `lemma_permission:content.manage`, in `routes/lemma_admin.php` (auto-discovered — the provider must NOT `loadRoutesFrom()` it).
- Working directories: `/Users/michaeltawiahsowah/Sites/glueful/lemma` (backend), `…/lemma/admin` (SPA).

> **Addendum (shipped 2026-07-03, folded in pre-commit):** block types carry a
> nullable free-form **`category`** (spec §1 — presentation-only picker grouping;
> nothing branches on it). It threads through EVERY surface below: the migration
> column (after `icon`), `BlockTypeRepository::create/updateSchema/hydrate`, the
> `BlockTypeData`/`UpdateBlockTypeData`/`BlockTypeItemData` DTOs, the controller,
> the SPA `BlockType`/`BlockTypePayload` interfaces, the Category input on the
> block-type forms + a badge in the settings list, and `BlocksField`'s picker,
> which groups by category (named categories alphabetical, uncategorized under
> "Other" last, headings only when >1 group). The snippets below carry it where
> they define those surfaces.

> **Addendum 2 — nesting amendment (spec "Amendment: Container blocks"):** the
> Global-Constraints pin "no `blocks` fields inside block schemas" was LIFTED by
> the follow-up plan `docs/superpowers/plans/2026-07-03-block-nesting.md`
> (`localized`/`filterable` rejections stay). This plan documents the v1 build;
> the nesting plan patches it.

## File Map

| File | Responsibility |
|---|---|
| `database/migrations/017_CreateLemmaBlockTypesTable.php` | registry table |
| `app/Content/Blocks/BlockTypeRepository.php` | registry CRUD + §2 schema rules + memoized slug map |
| `app/Content/Schema/FieldDefinition.php` (modify) | `'blocks'` type + `blockTypes` + `fromArray` branch |
| `app/Content/Schema/ContentTypeSchema.php` (modify) | `toArray()` preserves `block_types` |
| `app/Content/Enums/FieldType.php` (modify) | `Blocks` case |
| `app/Content/Schema/FieldTypes/EditorialFieldTypes.php` (modify) | `content.blocks` registration |
| `app/Content/Http/DTOs/FieldDefinitionData.php` (modify) | `block_types` input + `toArray()` |
| `app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php` (modify) | response/OpenAPI field |
| `app/Content/Validation/FieldValidator.php` (modify) | blocks branch (per-block validation, dot-paths) |
| `app/Content/Http/DTOs/BlockTypeData.php`, `UpdateBlockTypeData.php` | request DTOs |
| `app/Content/Http/DTOs/Responses/BlockTypes/*.php` | response DTOs |
| `app/Content/Http/Controllers/BlockTypeController.php` | admin CRUD |
| `routes/lemma_admin.php` (modify) | `/v1/admin/block-types` routes |
| `app/Providers/LemmaServiceProvider.php` (modify) | register repo + controller |
| `packages/lemma-render/src/RenderContextExtension.php` (modify) | `blocks()` |
| `packages/lemma-render/src/Templates/TemplatePolicy.php` (modify) | FUNCTIONS + CACHE_VERSION=2 |
| `scripts/run-test-migrations.php` — NO change (app `database/migrations` is already the base path) |
| `tests/Support/LemmaTestCase.php` (modify) | TABLES += `lemma_block_types` |
| `admin/src/queries/contentTypes.ts`, `blockTypes.ts`, `components/ContentTypeFields.vue`, `fields/*`, `pages/settings/block-types/*`, `registry/coreModule.ts` or settings nav | SPA |

---

### Task 1: Registry — migration + BlockTypeRepository

**Files:**
- Create: `database/migrations/017_CreateLemmaBlockTypesTable.php`, `app/Content/Blocks/BlockTypeRepository.php`
- Modify: `tests/Support/LemmaTestCase.php` (TABLES), `app/Providers/LemmaServiceProvider.php` (service)
- Test: `tests/Integration/Content/BlockTypeRepositoryTest.php`

**Interfaces:**
- Consumes: `Connection`, `Utils::generateNanoID()`, `ContentTypeSchema::fromArray(array): ContentTypeSchema` (verified — that is the real static-constructor name).
- Produces (later tasks rely on these exact signatures):
  - `create(array{slug: string, label: string, icon?: ?string, category?: ?string, description?: ?string, schema: list<array<string,mixed>>}): string` (uuid; throws `SchemaParseException` on §2 violations; duplicate slug → caller checks `findBySlug` first)
  - `findBySlug(string $slug): ?array` — hydrated row (`schema` decoded to array)
  - `findByUuid(string $uuid): ?array`
  - `all(): list<array>` — every row, active first then label order
  - `updateSchema(string $uuid, array $schema, string $label, ?string $icon, ?string $description): void` (re-runs §2 rules; slug NOT updatable)
  - `setActive(string $uuid, bool $active): void`
  - `schemasBySlug(): array<string, ContentTypeSchema>` — memoized per instance; ALL types (active + inactive) — the validator's lookup
  - `assertBlockSchema(list<array<string,mixed>> $schema): void` — §2 rules (`blocks` type, `localized`, `filterable` rejected), throws `SchemaParseException`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Content/BlockTypeRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Schema\SchemaParseException;
use App\Tests\Support\LemmaTestCase;

final class BlockTypeRepositoryTest extends LemmaTestCase
{
    private function repo(): BlockTypeRepository
    {
        return new BlockTypeRepository($this->connection());
    }

    /** @return list<array<string,mixed>> */
    private function heroSchema(): array
    {
        return [
            ['name' => 'heading', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ['name' => 'link', 'type' => 'reference', 'reference_type' => 'blog'],
        ];
    }

    public function testCreateFindUpdateDeactivateRoundTrip(): void
    {
        $r = $this->repo();
        $uuid = $r->create(['slug' => 'hero', 'label' => 'Hero', 'icon' => 'i-lucide-star',
            'schema' => $this->heroSchema()]);

        $row = $r->findBySlug('hero');
        self::assertSame('Hero', $row['label']);
        self::assertTrue((bool) $row['active']);
        self::assertSame('heading', $row['schema'][0]['name']);

        $r->updateSchema($uuid, [['name' => 'heading', 'type' => 'string']], 'Hero v2', null, null);
        self::assertSame('Hero v2', $r->findByUuid($uuid)['label']);
        self::assertCount(1, $r->findByUuid($uuid)['schema']);

        // Deactivate over delete (spec §2): row survives, flagged inactive.
        $r->setActive($uuid, false);
        self::assertFalse((bool) $r->findBySlug('hero')['active']);
        self::assertCount(1, $r->all());

        // schemasBySlug covers INACTIVE types too (existing content must keep validating).
        self::assertArrayHasKey('hero', $r->schemasBySlug());
    }

    public function testBlockSchemaRulesRejectNestingLocalizationAndFilterable(): void
    {
        $r = $this->repo();
        $cases = [
            [['name' => 'sections', 'type' => 'blocks']],           // no nesting (spec §2)
            [['name' => 'title', 'type' => 'string', 'localized' => true]],  // outer-field only
            [['name' => 'flag', 'type' => 'boolean', 'filterable' => true, 'filter_type' => 'boolean']],
        ];
        foreach ($cases as $i => $schema) {
            try {
                $r->create(['slug' => "bad{$i}", 'label' => 'Bad', 'schema' => $schema]);
                self::fail("expected SchemaParseException for case {$i}");
            } catch (SchemaParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidUnderlyingFieldSchemaAlsoRejects(): void
    {
        $this->expectException(SchemaParseException::class);
        $this->repo()->create(['slug' => 'bad', 'label' => 'Bad',
            'schema' => [['name' => 'x', 'type' => 'nope']]]);
    }

    public function testPathUnsafeSlugRejectsAtTheRepository(): void
    {
        // The slug is the blocks/{slug}.twig contract — the DOMAIN enforces it, not
        // just the API DTO (rows written around the API included).
        foreach (['../evil', 'Has Space', 'UPPER', ''] as $slug) {
            try {
                $this->repo()->create(['slug' => $slug, 'label' => 'Bad', 'schema' => []]);
                self::fail("expected SchemaParseException for slug '{$slug}'");
            } catch (SchemaParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Content/BlockTypeRepositoryTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Migration**

`database/migrations/017_CreateLemmaBlockTypesTable.php` (mirror the shape of `016_*`; global namespace, `MigrationInterface`):

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateLemmaBlockTypesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('lemma_block_types')) {
            return;
        }
        $schema->createTable('lemma_block_types', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            // Immutable after create (spec §1): the blocks/{slug}.twig template contract.
            $table->string('slug', 64);
            $table->string('label', 120);
            $table->string('icon', 64)->nullable();
            // Free-form picker grouping ("Layout", "Content", …) — presentation-level
            // metadata only; NOTHING branches on the value. Null groups under "Other".
            $table->string('category', 64)->nullable();
            $table->string('description', 500)->nullable();
            // Field-definition list, same JSON shape as content_types.schema.
            $table->text('schema');
            // Deactivate over delete (spec §2): inactive = hidden from the picker only.
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('slug', 'uniq_lemma_block_type_slug');
            $table->unique('uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('lemma_block_types');
    }

    public function getDescription(): string
    {
        return 'Create lemma_block_types (global block-type registry for blocks fields).';
    }
}
```

`tests/Support/LemmaTestCase.php` — TABLES gains `'lemma_block_types',` (append after the two `lemma_render_template*` entries; it has no FK ordering constraints).

Apply: `composer run test:migrate` — expect `Applied: 017_CreateLemmaBlockTypesTable.php`.

- [ ] **Step 4: BlockTypeRepository**

`app/Content/Blocks/BlockTypeRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\SchemaParseException;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * The global block-type registry (block-builder spec §1–§2). Block types are reusable
 * mini-schemas: their `schema` is the same field-definition JSON content types use,
 * parsed through ContentTypeSchema with three EXTRA rules (assertBlockSchema): no
 * `blocks` fields (no nesting in v1), no `localized` fields (localization belongs to
 * the outer blocks field), no `filterable` fields (block data is never a filter
 * surface). Slugs are immutable after create — they are the blocks/{slug}.twig
 * template contract. Removal is DEACTIVATION only.
 */
final class BlockTypeRepository
{
    /** @var array<string, ContentTypeSchema>|null slug => parsed schema (active + inactive) */
    private ?array $schemas = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{slug: string, label: string, icon?: ?string, category?: ?string,
     *   description?: ?string, schema: list<array<string,mixed>>} $data
     * @return string the new uuid
     */
    public function create(array $data): string
    {
        // The slug is the durable blocks/{slug}.twig contract — a DOMAIN invariant,
        // enforced here and not only in the API DTO (rows written around the API
        // must not mint path-unsafe template names).
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $data['slug']) !== 1) {
            throw new SchemaParseException(
                "block type slug '{$data['slug']}' must match [a-z][a-z0-9_-]{0,63}"
            );
        }
        $this->assertBlockSchema($data['schema']);
        $now = gmdate('Y-m-d H:i:s');
        $uuid = Utils::generateNanoID();
        $this->db->table('lemma_block_types')->insert([
            'uuid' => $uuid,
            'slug' => $data['slug'],
            'label' => $data['label'],
            'icon' => $data['icon'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'schema' => (string) json_encode(array_values($data['schema'])),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->schemas = null;
        return $uuid;
    }

    /** @return array<string,mixed>|null hydrated row (schema decoded) */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->table('lemma_block_types')->where('slug', '=', $slug)->first();
        return $row === null ? null : $this->hydrate((array) $row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->first();
        return $row === null ? null : $this->hydrate((array) $row);
    }

    /** @return list<array<string,mixed>> active first, then label */
    public function all(): array
    {
        $out = [];
        foreach (
            $this->db->table('lemma_block_types')
                ->orderBy('active', 'DESC')
                ->orderBy('label', 'ASC')
                ->get() as $row
        ) {
            $out[] = $this->hydrate((array) $row);
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $schema slug stays immutable (spec §1) */
    public function updateSchema(
        string $uuid,
        array $schema,
        string $label,
        ?string $icon,
        ?string $description,
        ?string $category = null,
    ): void {
        $this->assertBlockSchema($schema);
        $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->update([
            'label' => $label,
            'icon' => $icon,
            'category' => $category,
            'description' => $description,
            'schema' => (string) json_encode(array_values($schema)),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->schemas = null;
    }

    public function setActive(string $uuid, bool $active): void
    {
        $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->update([
            'active' => $active ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->schemas = null;
    }

    /**
     * The validator's lookup: slug => parsed schema, ACTIVE AND INACTIVE (existing
     * content referencing a deactivated type must keep validating — spec §4).
     *
     * @return array<string, ContentTypeSchema>
     */
    public function schemasBySlug(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }
        $this->schemas = [];
        foreach ($this->all() as $row) {
            $this->schemas[(string) $row['slug']] = ContentTypeSchema::fromArray($row['schema']);
        }
        return $this->schemas;
    }

    /**
     * §2 rules on TOP of normal field-schema parsing: parsing itself (via
     * ContentTypeSchema) rejects invalid types/enums/etc.; these three are the
     * blocks-specific prohibitions.
     *
     * @param list<array<string,mixed>> $schema
     */
    public function assertBlockSchema(array $schema): void
    {
        foreach ($schema as $field) {
            $name = is_string($field['name'] ?? null) ? $field['name'] : '?';
            if (($field['type'] ?? null) === 'blocks') {
                throw new SchemaParseException(
                    "block field '{$name}': blocks inside block schemas are not allowed (no nesting in v1)"
                );
            }
            if ((bool) ($field['localized'] ?? false)) {
                throw new SchemaParseException(
                    "block field '{$name}': localization belongs to the outer blocks field"
                );
            }
            if ((bool) ($field['filterable'] ?? false)) {
                throw new SchemaParseException("block field '{$name}': block data is never filterable");
            }
        }
        ContentTypeSchema::fromArray($schema); // full semantic validation (throws SchemaParseException)
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $row['schema'] = (array) json_decode((string) ($row['schema'] ?? '[]'), true);
        $row['active'] = (int) $row['active'];
        return $row;
    }
}
```

VERIFY: `ContentTypeSchema`'s static constructor name — check `app/Content/Schema/ContentTypeSchema.php` (`parse` vs `fromArray`); use the real one everywhere this plan says `ContentTypeSchema::fromArray(...)`, and use the same one `ContentTypeRepository` uses when hydrating content types.

Provider (`app/Providers/LemmaServiceProvider.php`): register `BlockTypeRepository` exactly like `ContentTypeRepository` is registered (same style — `use` import, `services()` entry or factory, `shared: true`). Mirror, don't invent.

- [ ] **Step 5: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Integration/Content/BlockTypeRepositoryTest.php
```
Expected: PASS (3 tests).

- [ ] **Step 6: Gates**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
```
Expected: clean. **No staging yet.**

---

### Task 2: Schema surfaces — the `blocks` type round-trips everywhere

**Files:**
- Modify: `app/Content/Schema/FieldDefinition.php`, `app/Content/Schema/ContentTypeSchema.php`, `app/Content/Enums/FieldType.php`, `app/Content/Schema/FieldTypes/EditorialFieldTypes.php`, `app/Content/Http/DTOs/FieldDefinitionData.php`, `app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php`
- Test: `tests/Unit/Content/BlocksFieldSchemaTest.php`

**Interfaces:**
- Produces: `FieldDefinition` with `public readonly array $blockTypes = []` (list<string>); `'blocks'` in `TYPES`; `FieldType::Blocks`; DTO key `block_types`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Content/BlocksFieldSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Http\DTOs\FieldDefinitionData;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use App\Content\Schema\SchemaParseException;
use PHPUnit\Framework\TestCase;

final class BlocksFieldSchemaTest extends TestCase
{
    public function testBlocksFieldParsesAndRoundTripsThroughToArray(): void
    {
        $schema = ContentTypeSchema::fromArray([[
            'name' => 'body',
            'type' => 'blocks',
            'localized' => true,
            'block_types' => ['hero', 'quote'],
        ]]);
        $field = $schema->field('body');
        self::assertSame('blocks', $field->type);
        self::assertSame(['hero', 'quote'], $field->blockTypes);
        self::assertTrue($field->localized);

        // ContentTypeSchema::toArray PRESERVES block_types (spec §1 round-trip pin).
        $out = $schema->toArray()[0];
        self::assertSame(['hero', 'quote'], $out['block_types']);
    }

    public function testBlocksFieldRejectsFilterable(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([[
            'name' => 'body', 'type' => 'blocks', 'filterable' => true, 'filter_type' => 'string',
        ]]);
    }

    public function testEmptyBlockTypesMeansAllAndIsOmittedFromToArray(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        self::assertSame([], $schema->field('body')->blockTypes);
        self::assertArrayNotHasKey('block_types', $schema->toArray()[0]);
    }

    public function testRequestDtoCarriesBlockTypesThrough(): void
    {
        $dto = new FieldDefinitionData(name: 'body', type: 'blocks', block_types: ['hero']);
        self::assertSame(['hero'], $dto->toArray()['block_types']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Content/BlocksFieldSchemaTest.php
```
Expected: FAIL — `'blocks'` not a valid type / unknown property.

- [ ] **Step 3: Implement the surfaces**

`FieldDefinition.php`:
- `TYPES` gains `'blocks'` (append to the list).
- Constructor gains (after `referenceSlugField`):

```php
        /** Picker-only allowlist of block-type slugs for a `blocks` field ([] = all active). */
        public readonly array $blockTypes = [],
```

- `fromArray()` gains, next to the reference/asset branches:

```php
        // `blocks` (block-builder spec §1): block_types is a PICKER-ONLY allowlist —
        // FieldValidator deliberately does not enforce it (tightening it must never
        // strand existing content). Blocks fields are never filterable.
        $blockTypes = [];
        if ($type === 'blocks') {
            if ($filterable) {
                throw new SchemaParseException("blocks field '{$name}' cannot be filterable");
            }
            $blockTypes = array_values(array_filter(
                array_map('strval', (array) ($raw['block_types'] ?? [])),
                static fn(string $v): bool => $v !== ''
            ));
        }
```

…and the constructor call at the end of `fromArray()` passes `blockTypes: $blockTypes`. (Read the tail of `fromArray()` first; add the argument in its style.)

`ContentTypeSchema::toArray()` — inside the mapped array add `'block_types' => $f->blockTypes,` (the existing `array_filter` with `!== []` already omits it when empty).

`FieldType.php` — add `case Blocks = 'blocks';`.

`EditorialFieldTypes.php` — after `content.json`, register (spec §1 pin, values verbatim):

```php
            self::make('content.blocks', 'Blocks', 'scalar', 'blocks-editor', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => true,
                'localized'  => true,
            ]),
```

(Match the second/third `make()` args to the neighbors — read one to confirm the 'scalar' group value used for json/reference and follow it.)

`FieldDefinitionData.php` — constructor gains (after `reference_slug_field`):

```php
        /** @var list<string> Picker-only block-type allowlist for a `blocks` field. */
        #[ArrayOf('string')]
        #[Rule('array')]
        public readonly array $block_types = [],
```

…and `toArray()` gains `'block_types' => $this->block_types,`.

`FieldSchemaData.php` — constructor gains:

```php
        /** Picker-only allowlist of block-type slugs for a `blocks` field; absent = all active. */
        #[ArrayOf('string')]
        public readonly array $block_types = [],
```

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Content/BlocksFieldSchemaTest.php
vendor/bin/phpunit tests/Unit/Content/
```
Expected: PASS, existing unit suite unaffected.

- [ ] **Step 5: Gates** — `vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"`. **No staging yet.**

---

### Task 3: FieldValidator — per-block validation

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php` (+ wherever it is constructed for entry saves — find `new FieldValidator(` call sites and thread the repository)
- Test: `tests/Integration/Content/BlocksValidationTest.php`

**Interfaces:**
- Consumes: `BlockTypeRepository::schemasBySlug()` (Task 1).
- Produces: `FieldValidator::__construct(?Connection $db = null, ?ApplicationContext $context = null, ?BlockTypeRepository $blockTypes = null)`; blocks values validate per spec §4; cleaned output blocks are `{id, type, data}` with `data` = the per-block cleaned payload.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Content/BlocksValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;

final class BlocksValidationTest extends LemmaTestCase
{
    private BlockTypeRepository $blocks;
    private FieldValidator $validator;
    private ContentTypeSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blocks = new BlockTypeRepository($this->connection());
        $this->blocks->create(['slug' => 'hero', 'label' => 'Hero', 'schema' => [
            ['name' => 'heading', 'type' => 'string', 'required' => true],
            ['name' => 'author', 'type' => 'reference', 'reference_type' => 'blog'],
        ]]);
        $this->blocks->create(['slug' => 'quote', 'label' => 'Quote', 'schema' => [
            ['name' => 'text', 'type' => 'text'],
        ]]);
        $this->validator = new FieldValidator($this->connection(), $this->appContext(), $this->blocks);
        // The field allowlists ONLY hero — proving the picker-only rule below.
        $this->schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'blocks', 'block_types' => ['hero']],
        ]);
    }

    /** @return array<string,mixed> */
    private function clean(array $payload, bool $strict = false): array
    {
        return $this->validator->validate($this->schema, $payload, $strict);
    }

    public function testValidBlocksCleanPerBlockAndPreserveOrderAndIds(): void
    {
        $clean = $this->clean(['body' => [
            ['id' => 'aaaaaaaaaaaa', 'type' => 'hero', 'data' => [
                'heading' => 'Hi',
                'stale_key' => 'removed from schema long ago', // cleaned-payload strip (spec §3)
            ]],
            ['type' => 'quote', 'data' => ['text' => 'Words']], // missing id → generated
        ]]);
        $blocks = $clean['body'];
        self::assertSame('aaaaaaaaaaaa', $blocks[0]['id']);
        self::assertSame(['heading' => 'Hi'], $blocks[0]['data']); // stale key stripped
        self::assertSame('quote', $blocks[1]['type']);
        self::assertSame(12, strlen($blocks[1]['id'])); // server-generated nanoid
    }

    public function testKnownButOutsideAllowlistTypeIsAccepted(): void
    {
        // 'quote' is NOT in the field's block_types — picker-only rule (spec §1/§4).
        $clean = $this->clean(['body' => [['type' => 'quote', 'data' => ['text' => 'ok']]]]);
        self::assertSame('quote', $clean['body'][0]['type']);
    }

    public function testStructuralAndTypeErrorsUseDotPaths(): void
    {
        try {
            $this->clean(['body' => [
                ['id' => 'aaaaaaaaaaaa', 'type' => 'hero', 'data' => ['heading' => 123]],
                ['id' => 'bbbbbbbbbbbb', 'type' => 'ghost', 'data' => []],
                ['id' => 'aaaaaaaaaaaa', 'type' => 'quote', 'data' => []],
            ]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertArrayHasKey('body.0.heading', $errors);        // per-block dot path
            self::assertArrayHasKey('body.1', $errors);                // unknown type
            self::assertStringContainsString('unknown block type', $errors['body.1']);
            self::assertArrayHasKey('body.2', $errors);                // duplicate id
            self::assertStringContainsString('duplicate', $errors['body.2']);
        }
    }

    public function testNonListValueAndMalformedItemsError(): void
    {
        try {
            $this->clean(['body' => ['not-a-block']]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0', $e->errors());
        }
        try {
            $this->clean(['body' => 'nope']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body', $e->errors());
        }
    }

    public function testDataMustBeAnObjectNotMissingStringOrList(): void
    {
        // {type:"quote", data:"oops"} must NOT pass just because quote has no
        // required fields — `data` is structurally an object (spec §1).
        foreach (
            [
                ['type' => 'quote', 'data' => 'oops'],   // string
                ['type' => 'quote'],                      // missing entirely
                ['type' => 'quote', 'data' => [1, 2]],    // non-empty list, not an object
            ] as $block
        ) {
            try {
                $this->clean(['body' => [$block]]);
                self::fail('expected ValidationException for data=' . json_encode($block['data'] ?? null));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('body.0.data', $e->errors());
                self::assertStringContainsString('must be an object', $e->errors()['body.0.data']);
            }
        }
        // Empty object is fine (json '{}' decodes to [] in PHP — indistinguishable, allowed).
        try {
            $this->clean(['body' => [['type' => 'hero', 'data' => []]]]);
            self::fail('heading is required — but the DATA shape itself must be accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.heading', $e->errors()); // field error, NOT a data-shape error
            self::assertArrayNotHasKey('body.0.data', $e->errors());
        }
    }

    public function testStrictPublishRejectsDanglingReferenceInsideBlockData(): void
    {
        $payload = ['body' => [
            ['type' => 'hero', 'data' => ['heading' => 'Hi', 'author' => 'nope00000000']],
        ]];
        // Draft: lenient — dangling reference passes (top-level semantics, one level down).
        $this->clean($payload, strict: false);
        $this->addToAssertionCount(1);
        // Publish: strict — rejected with the block's dot path.
        try {
            $this->clean($payload, strict: true);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.author', $e->errors());
        }
    }

    public function testRequiredBlockFieldMissingIsAlwaysAnError(): void
    {
        try {
            $this->clean(['body' => [['type' => 'hero', 'data' => []]]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.heading', $e->errors());
        }
    }
}
```

(`ValidationException::errors(): array` is verified — the assertions' accessor is correct as written.)

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit tests/Integration/Content/BlocksValidationTest.php
```
Expected: FAIL — ctor arity / 'unknown field type' for blocks.

- [ ] **Step 3: Implement**

`FieldValidator.php`:

1. Ctor gains a third optional param + import:

```php
    public function __construct(
        private readonly ?Connection $db = null,
        private readonly ?ApplicationContext $context = null,
        private ?BlockTypeRepository $blockTypes = null,
    ) {
    }

    private function blockTypes(): ?BlockTypeRepository
    {
        if ($this->blockTypes === null && $this->db !== null) {
            $this->blockTypes = new BlockTypeRepository($this->db);
        }
        return $this->blockTypes;
    }
```

2. In `validate()`'s per-field loop, BEFORE the multi-value branch, add:

```php
            // Blocks (block-builder spec §4): per-block validation against the block
            // type's schema, dot-path errors `field.index[.blockField]`. The field's
            // block_types allowlist is PICKER-ONLY and deliberately not enforced here.
            if ($field->type === 'blocks') {
                [$cleanBlocks, $blockErrors] = $this->validateBlocks($field->name, $value, $strict);
                foreach ($blockErrors as $path => $message) {
                    $errors[$path] = $message;
                }
                if ($blockErrors === []) {
                    $clean[$field->name] = $cleanBlocks;
                }
                continue;
            }
```

3. New private method + `checkType()` gains a `'blocks'` arm returning an error (blocks never reach `checkType` via the branch above; the arm is a guard):

```php
    /**
     * @return array{0: list<array{id: string, type: string, data: array<string,mixed>}>,
     *   1: array<string,string>}
     */
    private function validateBlocks(string $fieldName, mixed $value, bool $strict): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [[], [$fieldName => 'must be an ordered list of blocks']];
        }
        $registry = $this->blockTypes();
        if ($registry === null) {
            return [[], [$fieldName => 'block types are unavailable']];
        }
        $schemas = $registry->schemasBySlug();
        $errors = [];
        $clean = [];
        $seenIds = [];
        foreach ($value as $i => $block) {
            $path = "{$fieldName}.{$i}";
            if (!is_array($block)) {
                $errors[$path] = 'must be a block object {id, type, data}';
                continue;
            }
            $type = $block['type'] ?? null;
            if (!is_string($type) || !isset($schemas[$type])) {
                $errors[$path] = 'unknown block type' . (is_string($type) ? " '{$type}'" : '');
                continue;
            }
            $id = isset($block['id']) && is_string($block['id']) && $block['id'] !== ''
                ? $block['id']
                : Utils::generateNanoID();
            if (isset($seenIds[$id])) {
                $errors[$path] = "duplicate block id '{$id}'";
                continue;
            }
            $seenIds[$id] = true;
            // `data` is structurally an OBJECT (spec §1): missing, scalar, or a
            // non-empty list is a shape error — never silently coerced to [] (that
            // would let {data:"oops"} pass whenever the schema has no required
            // fields). PHP can't distinguish decoded '{}' from '[]'; empty is allowed.
            $data = $block['data'] ?? null;
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                $errors["{$path}.data"] = 'must be an object';
                continue;
            }
            try {
                // Recursion: the SAME cleaned-payload semantics as top-level fields
                // (known keys only, in schema order; strict threads the publish gate).
                $cleanData = $this->validate($schemas[$type], $data, $strict);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $blockField => $message) {
                    $errors["{$path}.{$blockField}"] = $message;
                }
                continue;
            }
            $clean[] = ['id' => $id, 'type' => $type, 'data' => $cleanData];
        }
        return [$clean, $errors];
    }
```

(Imports: `use App\Content\Blocks\BlockTypeRepository;` and `use Glueful\Helpers\Utils;`.)

4b. `checkType()` gains a guard arm — blocks never reach it via the branch above:

```php
            'blocks' => 'must be an ordered list of blocks', // handled by validateBlocks(); guard only
```

4. **Call sites:** find where entry save/publish constructs `FieldValidator` (`grep -rn "new FieldValidator(" app/`) and pass the container's `BlockTypeRepository` as the third argument wherever a `Connection` is already passed (the lazy fallback covers the rest — nothing may construct one WITHOUT db that needs blocks).

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Integration/Content/BlocksValidationTest.php
vendor/bin/phpunit tests/Unit/Content/FieldValidatorTest.php
```
Expected: PASS both (existing validator behavior untouched).

- [ ] **Step 5: Gates** — phpcs + boundaries. **No staging yet.**

---

### Task 4: Block-types admin API

**Files:**
- Create: `app/Content/Http/DTOs/BlockTypeData.php`, `app/Content/Http/DTOs/UpdateBlockTypeData.php`, `app/Content/Http/DTOs/Responses/BlockTypes/BlockTypeItemData.php`, `app/Content/Http/Controllers/BlockTypeController.php`
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php` (controller registration — remember the memory rule: every new Lemma HTTP controller joins `LemmaServiceProvider::services()` with a `use` import)
- Test: `tests/Integration/Content/BlockTypeApiTest.php`

**Interfaces:**
- Consumes: `BlockTypeRepository` (Task 1), `FieldDefinitionData` (Task 2).
- Produces routes: `GET /v1/admin/block-types` (content.view), `POST` (content.manage), `GET /{slug}` (view), `PATCH /{slug}` (manage — label/icon/description/schema; NOT slug), `POST /{slug}/activate` + `POST /{slug}/deactivate` (manage).

- [ ] **Step 1: Write the failing test**

`tests/Integration/Content/BlockTypeApiTest.php` (direct controller invocation + route-table assertions — the established idiom):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Http\Controllers\BlockTypeController;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BlockTypeApiTest extends LemmaTestCase
{
    private function api(): BlockTypeController
    {
        return $this->container()->get(BlockTypeController::class);
    }

    /** @param array<string,mixed> $body */
    private function req(array $body): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body));
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    public function testCrudLifecycle(): void
    {
        // NOTE: adapt store()/update() invocation to the hydrated-DTO signature — the
        // router normally hydrates BlockTypeData; direct tests construct the DTO
        // (ContentTypeController tests are the model — mirror them).
        $store = $this->api()->store(new \App\Content\Http\DTOs\BlockTypeData(
            slug: 'hero',
            label: 'Hero',
            icon: 'i-lucide-star',
            description: null,
            schema: [new \App\Content\Http\DTOs\FieldDefinitionData(name: 'heading', type: 'string')],
        ), $this->req([]));
        self::assertSame(201, $store->getStatusCode());

        // Duplicate slug → 422.
        $dup = $this->api()->store(new \App\Content\Http\DTOs\BlockTypeData(
            slug: 'hero', label: 'Again', icon: null, description: null, schema: [],
        ), $this->req([]));
        self::assertSame(422, $dup->getStatusCode());

        // §2 schema rules surface as 422.
        $bad = $this->api()->store(new \App\Content\Http\DTOs\BlockTypeData(
            slug: 'bad', label: 'Bad', icon: null, description: null,
            schema: [new \App\Content\Http\DTOs\FieldDefinitionData(name: 's', type: 'blocks')],
        ), $this->req([]));
        self::assertSame(422, $bad->getStatusCode());

        $list = $this->json($this->api()->index(Request::create('/x', 'GET')));
        self::assertCount(1, $list['data']['block_types']);

        $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'hero'));
        self::assertSame('Hero', $show['data']['block_type']['label']);

        $update = $this->api()->update(new \App\Content\Http\DTOs\UpdateBlockTypeData(
            label: 'Hero v2', icon: null, description: 'Big banner',
            schema: [new \App\Content\Http\DTOs\FieldDefinitionData(name: 'heading', type: 'string')],
        ), Request::create('/x', 'PATCH'), 'hero');
        self::assertSame(200, $update->getStatusCode());

        self::assertSame(200, $this->api()->deactivate('hero')->getStatusCode());
        $repo = new BlockTypeRepository($this->connection());
        self::assertSame(0, $repo->findBySlug('hero')['active']);
        self::assertSame(200, $this->api()->activate('hero')->getStatusCode());
        self::assertSame(1, $repo->findBySlug('hero')['active']);

        self::assertSame(404, $this->api()->show(Request::create('/x', 'GET'), 'ghost')->getStatusCode());
    }

    public function testRoutesCarryTheContentPermissions(): void
    {
        foreach (
            [
                ['GET', '/v1/admin/block-types', 'content.view'],
                ['POST', '/v1/admin/block-types', 'content.manage'],
                ['GET', '/v1/admin/block-types/{slug}', 'content.view'],
                ['PATCH', '/v1/admin/block-types/{slug}', 'content.manage'],
                ['POST', '/v1/admin/block-types/{slug}/activate', 'content.manage'],
                ['POST', '/v1/admin/block-types/{slug}/deactivate', 'content.manage'],
            ] as [$method, $path, $permission]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "missing route {$method} {$path}");
            self::assertContains(
                "lemma_permission:{$permission}",
                (array) ($route['middleware'] ?? []),
                "wrong permission on {$method} {$path}",
            );
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails** — FAIL, classes missing.

- [ ] **Step 3: DTOs**

`app/Content/Http/DTOs/BlockTypeData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/block-types`. Slug shape validates here; the §2
 * block-schema rules (no blocks/localized/filterable inside block schemas) surface
 * from BlockTypeRepository as SchemaParseException → 422. Slugs are immutable after
 * create — the blocks/{slug}.twig template contract.
 */
final class BlockTypeData implements RequestData
{
    /** @param list<FieldDefinitionData> $schema */
    public function __construct(
        /** @var string Unique lowercase block-type slug (also the template name). */
        #[Rule('required|string|regex:/\A[a-z][a-z0-9_-]{0,63}\z/')]
        public readonly string $slug,
        #[Rule('required|string')]
        public readonly string $label,
        /** @var string|null Lucide icon name shown in the block picker. */
        #[Rule('string')]
        public readonly ?string $icon = null,
        /** @var string|null Free-form picker grouping ("Layout", "Content", …); presentation only. */
        #[Rule('string')]
        public readonly ?string $category = null,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[ArrayOf(FieldDefinitionData::class)]
        #[Rule('array')]
        public readonly array $schema = [],
    ) {
    }
}
```

`app/Content/Http/DTOs/UpdateBlockTypeData.php` — same minus `slug` (label required; icon/category/description nullable; schema array).

`app/Content/Http/DTOs/Responses/BlockTypes/BlockTypeItemData.php` (doc-only, `ResponseData`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\BlockTypes;

use App\Content\Http\DTOs\Responses\ContentTypes\FieldSchemaData;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/** Doc-only: one block type as the admin API returns it. */
final class BlockTypeItemData implements ResponseData
{
    /** @param list<FieldSchemaData> $schema */
    public function __construct(
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $label,
        public readonly ?string $icon,
        /** Free-form picker grouping ("Layout", "Content", …); null groups under "Other". */
        public readonly ?string $category,
        public readonly ?string $description,
        public readonly bool $active,
        #[ArrayOf(FieldSchemaData::class)]
        public readonly array $schema = [],
    ) {
    }
}
```

(Mirror the envelope response DTOs the content-types endpoints use — e.g. a `BlockTypeListData`/`BlockTypeResultData` wrapper if `ContentTypeListData` is that shape; read `ContentTypeListData.php` and copy the pattern.)

- [ ] **Step 4: Controller + routes + provider**

`app/Content/Http/Controllers/BlockTypeController.php` (mirror `ContentTypeController`'s idioms: hydrated DTOs, `Response::validation`, `SchemaParseException` → 422, `ApiOperation` tags `['Lemma Admin']`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Http\DTOs\BlockTypeData;
use App\Content\Http\DTOs\FieldDefinitionData;
use App\Content\Http\DTOs\UpdateBlockTypeData;
use App\Content\Schema\SchemaParseException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The global block-type registry API (block-builder spec §1–§2). Slugs are immutable
 * (blocks/{slug}.twig contract); removal is deactivation only — inactive types
 * disappear from the add-picker while existing content keeps validating and rendering.
 */
final class BlockTypeController
{
    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    #[ApiOperation(summary: 'List block types', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'All block types, active first.')]
    public function index(Request $request): Response
    {
        return Response::success(['block_types' => $this->blockTypes->all()], 'Block types retrieved.');
    }

    #[ApiOperation(summary: 'Create a block type', tags: ['Lemma Admin'])]
    #[ApiResponse(201, description: 'Block type created.')]
    #[ApiResponse(422, description: 'Duplicate slug or invalid block schema (§2 rules).')]
    public function store(BlockTypeData $input, Request $request): Response
    {
        if ($this->blockTypes->findBySlug($input->slug) !== null) {
            return Response::validation(['slug' => "block type '{$input->slug}' already exists"]);
        }
        try {
            $uuid = $this->blockTypes->create([
                'slug' => $input->slug,
                'label' => trim($input->label),
                'icon' => $input->icon,
                'description' => $input->description,
                'schema' => array_map(
                    static fn (FieldDefinitionData $f): array => $f->toArray(),
                    $input->schema,
                ),
            ]);
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::created(
            ['block_type' => $this->blockTypes->findByUuid($uuid)],
            'Block type created.',
        );
    }

    #[ApiOperation(summary: 'One block type', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'The block type with its schema.')]
    #[ApiResponse(404, description: 'Unknown slug.')]
    public function show(Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        return $row === null
            ? Response::error('Unknown block type.', 404)
            : Response::success(['block_type' => $row]);
    }

    #[ApiOperation(summary: 'Update a block type (slug is immutable)', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Updated.')]
    #[ApiResponse(404, description: 'Unknown slug.')]
    #[ApiResponse(422, description: 'Invalid block schema (§2 rules).')]
    public function update(UpdateBlockTypeData $input, Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::error('Unknown block type.', 404);
        }
        try {
            $this->blockTypes->updateSchema(
                (string) $row['uuid'],
                array_map(static fn (FieldDefinitionData $f): array => $f->toArray(), $input->schema),
                trim($input->label),
                $input->icon,
                $input->description,
            );
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::success(['block_type' => $this->blockTypes->findBySlug($slug)], 'Block type updated.');
    }

    #[ApiOperation(summary: 'Reactivate a block type', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Active — appears in the block picker again.')]
    #[ApiResponse(404, description: 'Unknown slug.')]
    public function activate(string $slug): Response
    {
        return $this->setActive($slug, true);
    }

    #[ApiOperation(
        summary: 'Deactivate a block type (existing content keeps rendering/editing)',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Inactive — hidden from the block picker.')]
    #[ApiResponse(404, description: 'Unknown slug.')]
    public function deactivate(string $slug): Response
    {
        return $this->setActive($slug, false);
    }

    private function setActive(string $slug, bool $active): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::error('Unknown block type.', 404);
        }
        $this->blockTypes->setActive((string) $row['uuid'], $active);
        return Response::success(
            ['block_type' => $this->blockTypes->findBySlug($slug)],
            $active ? 'Block type activated.' : 'Block type deactivated.',
        );
    }
}
```

`routes/lemma_admin.php` — after the content-types block, inside the same group:

```php
    // Block-type registry (block-builder spec §1): the reusable block schemas that
    // `blocks` fields compose. Same permissions as content-type schema management.
    $router->get('/block-types', [BlockTypeController::class, 'index'])
        ->middleware('lemma_permission:content.view');
    $router->post('/block-types', [BlockTypeController::class, 'store'])
        ->middleware('lemma_permission:content.manage');
    $router->get('/block-types/{slug}', [BlockTypeController::class, 'show'])
        ->middleware('lemma_permission:content.view');
    $router->patch('/block-types/{slug}', [BlockTypeController::class, 'update'])
        ->middleware('lemma_permission:content.manage');
    $router->post('/block-types/{slug}/activate', [BlockTypeController::class, 'activate'])
        ->middleware('lemma_permission:content.manage');
    $router->post('/block-types/{slug}/deactivate', [BlockTypeController::class, 'deactivate'])
        ->middleware('lemma_permission:content.manage');
```

(+ `use App\Content\Http\Controllers\BlockTypeController;` at the top.)

Provider: `BlockTypeController` joins `LemmaServiceProvider::services()` with a `use` import, mirroring how `ContentTypeController` is registered.

- [ ] **Step 5: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Integration/Content/BlockTypeApiTest.php
```
Expected: PASS.

- [ ] **Step 6: Gates** — phpcs, boundaries. **No staging yet.**

---

### Task 5: Render — `blocks()` + sandbox policy + STAGE grouping 1

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/src/Templates/TemplatePolicy.php`, `packages/lemma-render/README.md` (short section)
- Test: `tests/Integration/Render/BlocksRenderingTest.php`

**Interfaces:**
- Consumes: nothing from the registry (the render pack NEVER reads it — template convention only).
- Produces: Twig `blocks(list)` — renders `blocks/{type}.twig` per block with context `{block, data, entry, index}`; safe-slug check; missing-template + malformed-item behavior per spec §6; `TemplatePolicy::FUNCTIONS` gains `'blocks'`; `CACHE_VERSION = 2`.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Render/BlocksRenderingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplatePolicy;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Twig\Environment;

final class BlocksRenderingTest extends LemmaTestCase
{
    /** Environment WITH the DB loader (block templates are DB-overridable — spec §6). */
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
            new DatabaseTemplateLoader(
                new TemplateRepository($this->connection()),
                $this->container()->get(TemplateLinter::class),
                'default',
            ),
        ))->environment();
    }

    private function saveBlockTemplate(string $type, string $source): void
    {
        (new TemplateRepository($this->connection()))->save('default', "blocks/{$type}.twig", $source, null);
    }

    public function testRendersBlocksInOrderWithThePinnedContext(): void
    {
        $this->saveBlockTemplate('hero', 'HERO[{{ index }}:{{ data.heading }}:{{ block.id }}:{{ entry.slug }}]');
        $this->saveBlockTemplate('quote', 'QUOTE[{{ index }}:{{ data.text }}]');

        $out = $this->env()->createTemplate("{{ blocks(entry.fields.body) }}")->render([
            'entry' => ['slug' => 'hello', 'fields' => ['body' => [
                ['id' => 'aaaaaaaaaaaa', 'type' => 'hero', 'data' => ['heading' => 'Hi']],
                ['id' => 'bbbbbbbbbbbb', 'type' => 'quote', 'data' => ['text' => 'Words']],
            ]]],
        ]);
        self::assertStringContainsString('HERO[0:Hi:aaaaaaaaaaaa:hello]', $out);
        self::assertStringContainsString('QUOTE[1:Words]', $out);
        self::assertLessThan(strpos($out, 'QUOTE['), strpos($out, 'HERO[')); // order
    }

    public function testMissingTemplateAndMalformedItemsAreSafe(): void
    {
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'x', 'type' => 'ghost', 'data' => []],   // no template anywhere
            'not-a-block',                                     // malformed → skipped
            ['id' => 'y', 'type' => '../evil', 'data' => []], // unsafe slug → skipped
        ]]);
        // Debug envs may render a placeholder; either way NOTHING throws and the
        // unsafe slug never becomes a template path.
        self::assertStringNotContainsString('evil', $out);
        $this->addToAssertionCount(1);
    }

    public function testNonListValueRendersNothing(): void
    {
        self::assertSame('', trim($this->env()->createTemplate("{{ blocks(x) }}")->render(['x' => 'nope'])));
    }

    public function testBlocksJoinsTheSandboxAllowlistWithACacheVersionBump(): void
    {
        self::assertContains('blocks', TemplatePolicy::FUNCTIONS);
        self::assertSame(2, TemplatePolicy::CACHE_VERSION); // spec §6 pin

        // A DB template calling blocks() lints clean.
        $linter = $this->container()->get(TemplateLinter::class);
        self::assertSame([], $linter->lint('{{ blocks(entry.fields.body) }}'));
    }
}
```

NOTE: `{{ blocks(...) }}` must OUTPUT the concatenated HTML — the function returns Twig markup marked safe (`is_safe: ['html']`).

- [ ] **Step 2: Run to verify it fails** — FAIL, unknown function `blocks`.

- [ ] **Step 3: Implement**

`RenderContextExtension.php`:

- `getFunctions()` gains:

```php
            new TwigFunction('blocks', $this->blocks(...), [
                'needs_environment' => true,
                'needs_context' => true,
                'is_safe' => ['html'],
            ]),
```

- New method + one property:

```php
    /** @var array<string,bool> block types already logged this process (log ONCE per type) */
    private array $loggedBlockMisses = [];

    /**
     * Render an ordered blocks list through blocks/{type}.twig (block-builder spec §6).
     * Context per block: {block, data, entry, index} — `entry` is the CALLER's entry
     * (needs_context), read-only ambient state. Missing templates: prod = HTML comment,
     * debug = visible placeholder; logged once per type per process. Malformed items
     * and path-unsafe type slugs are skipped with the same once-per-type logging — a
     * template never explodes over data. The registry is NEVER consulted here.
     *
     * @param array<string,mixed> $context
     */
    public function blocks(\Twig\Environment $env, array $context, mixed $list): string
    {
        if (!is_array($list) || !array_is_list($list)) {
            return '';
        }
        $entry = $context['entry'] ?? null;
        $html = [];
        foreach ($list as $index => $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : null;
            if ($type === null || preg_match('/\A[a-z][a-z0-9_-]*\z/', $type) !== 1) {
                $this->logBlockMiss($type ?? '(malformed)', 'malformed block instance');
                continue;
            }
            $template = "blocks/{$type}.twig";
            if (!$env->getLoader()->exists($template)) {
                $this->logBlockMiss($type, "no template at {$template}");
                $html[] = $this->debug
                    ? '<div style="border:1px dashed red;padding:.5rem">Missing block template: '
                        . htmlspecialchars($template, ENT_QUOTES) . '</div>'
                    : '<!-- lemma: no template for block "' . htmlspecialchars($type, ENT_QUOTES) . '" -->';
                continue;
            }
            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            $html[] = $env->render($template, [
                'block' => ['id' => $item['id'] ?? null, 'type' => $type, 'data' => $data],
                'data' => $data,
                'entry' => $entry,
                'index' => $index,
            ]);
        }
        return implode('', $html);
    }

    private function logBlockMiss(string $type, string $reason): void
    {
        if (isset($this->loggedBlockMisses[$type])) {
            return;
        }
        $this->loggedBlockMisses[$type] = true;
        $this->logger?->warning("lemma-render: blocks(): {$reason}", ['type' => $type]);
    }
```

**Debug + logger are PROVIDER-INJECTED (pinned — not Twig-context reads):** the
`RenderContextExtension` constructor gains two params after `$facetReader`:

```php
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
        private readonly bool $debug = false,
```

(use a `use Psr\Log\LoggerInterface;` import and the short name), and
`LemmaRenderServiceProvider::makeRenderContextExtension()` passes them:

```php
            $container->get(\Psr\Log\LoggerInterface::class),
            (bool) config($context, 'app.debug', false),
```

(the factory already resolves `ApplicationContext` as `$context`; the pack already
resolves `LoggerInterface` for the controller — same binding, `use` import per house
style). Every existing `new RenderContextExtension(...)` call site in tests keeps
working (both params default).

`TemplatePolicy.php`:

```php
    public const CACHE_VERSION = 2; // bumped: 'blocks' joined FUNCTIONS (block-builder spec §6)
```

…and `'blocks',` appended to `FUNCTIONS`.

`packages/lemma-render/README.md` — short section after "Facet counts in templates":

```markdown
## Blocks in templates

`blocks(entry.fields.body)` renders an ordered blocks-field value through the template
hierarchy `blocks/{type}.twig` (theme file or DB-edited template — both work). Each
block template receives `{ block, data, entry, index }`. Missing templates render an
HTML comment in production and a visible placeholder in debug, logged once per type.
Reference values inside `data` are raw uuids — use `path(uuid)` for links.
```

- [ ] **Step 4: Run + full render/content suites**

```bash
vendor/bin/phpunit tests/Integration/Render/BlocksRenderingTest.php
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Content/ tests/Unit/Content/
```
Expected: PASS. (The CACHE_VERSION bump legitimately changes DB-template cache keys — the Task 3 loader test asserts `:policy:` + the constant, so it stays green.)

- [ ] **Step 5: Full backend verification + STAGE** *(grouping 1 — commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add database/migrations app/Content app/Providers/LemmaServiceProvider.php \
        routes/lemma_admin.php packages/lemma-render tests/Support/LemmaTestCase.php \
        tests/Integration/Content tests/Integration/Render tests/Unit/Content
```

Expected: green (pre-existing single skip only). STOP — when authorized:

```bash
git commit -m "feat(content): page/block builder backend — blocks field, block-type registry, blocks() rendering

'blocks' joins the content field types: ordered {id, type, data} lists inside
entry JSON (versions/publish/localization/delivery untouched). Global
lemma_block_types registry (slug-immutable, deactivate-over-delete, §2 schema
rules: no nesting, no per-field localization, never filterable) with
content.view/manage-gated admin CRUD. FieldValidator validates per block with
dot-path errors and cleaned payloads; strict publish rejects dangling refs
inside blocks; block_types allowlist is picker-only by design. Render pack
gains blocks() (needs_context; blocks/{type}.twig hierarchy incl. DB-edited
templates; prod comment / debug placeholder / log-once on misses; path-safe
type slugs) and 'blocks' joins TemplatePolicy::FUNCTIONS with CACHE_VERSION=2."
```

---

### Task 6: Admin SPA — blocks widget + block-types screen

**Files:**
- Modify: `admin/src/queries/contentTypes.ts` (FIELD_TYPES + `block_types` on `ContentTypeField`), `admin/src/components/ContentTypeFields.vue` (blocks support + `context` prop), `admin/src/fields/types.ts`, `admin/src/fields/registry.ts`, `admin/src/queries/keys.ts`
- Create: `admin/src/queries/blockTypes.ts`, `admin/src/fields/normalize.ts`, `admin/src/fields/components/BlocksField.vue`, `admin/src/pages/settings/block-types/index.vue`, `admin/src/pages/settings/block-types/[slug].vue`, `admin/src/pages/settings/block-types/new.vue`
- Modify: the settings nav (find where `settings/content-types` is linked — `admin/src/registry/coreModule.ts` — and add Block types beside it)
- Test: `admin/src/__tests__/blocksField.spec.ts`

**Interfaces:**
- Consumes: `/v1/admin/block-types` endpoints (Task 4 — typed client after gen:api), existing field widgets via `fieldComponent()`.
- Produces: `BlocksField.vue` registered for `type: 'blocks'`; block-types settings screens.

- [ ] **Step 1: Regenerate the typed client** (needed before typed SPA code):

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && php glueful generate:openapi -f --clean
cd admin && pnpm gen:api
```
Expected: `block-types` paths in `src/api/schema.d.ts` (`grep -c "block-types" src/api/schema.d.ts` ≥ 4).

- [ ] **Step 2: Write the failing widget test**

`admin/src/__tests__/blocksField.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const blockTypes = ref([
  { uuid: 'bt1', slug: 'hero', label: 'Hero', icon: 'i-lucide-star', active: true,
    schema: [{ name: 'heading', type: 'string', required: true }] },
  { uuid: 'bt2', slug: 'quote', label: 'Quote', icon: null, active: true,
    schema: [{ name: 'text', type: 'text' }] },
  { uuid: 'bt3', slug: 'legacy', label: 'Legacy', icon: null, active: false, schema: [] },
])
vi.mock('@/queries/blockTypes', () => ({
  useBlockTypes: () => ({ data: blockTypes }),
}))
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/x', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import BlocksField from '@/fields/components/BlocksField.vue'

const field = { name: 'body', type: 'blocks' as const, required: false }

describe('BlocksField', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('adds a block from the picker (active types only) with a generated id', async () => {
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: [], 'onUpdate:modelValue': (v: unknown) => wrapper.setProps({ modelValue: v }) },
    })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="picker-item-legacy"]').exists()).toBe(false) // inactive hidden
    await wrapper.find('[data-test="picker-item-hero"]').trigger('click')
    await flushPromises()
    const value = wrapper.props('modelValue') as { id: string; type: string }[]
    expect(value).toHaveLength(1)
    expect(value[0]!.type).toBe('hero')
    expect(value[0]!.id.length).toBeGreaterThanOrEqual(8)
  })

  it('respects the field block_types allowlist in the picker', async () => {
    const wrapper = mount(BlocksField, {
      props: { field: { ...field, blockTypes: ['quote'] }, modelValue: [] },
    })
    await flushPromises()
    await wrapper.find('[data-test="add-block"]').trigger('click')
    expect(wrapper.find('[data-test="picker-item-quote"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="picker-item-hero"]').exists()).toBe(false)
  })

  it('reorders with the move buttons and edits nested data via existing widgets', async () => {
    const model = ref([
      { id: 'a', type: 'hero', data: { heading: 'One' } },
      { id: 'b', type: 'quote', data: { text: 'Two' } },
    ])
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: model.value, 'onUpdate:modelValue': (v: never) => (model.value = v) },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-move-down-a"]').trigger('click')
    expect(model.value[0]!.id).toBe('b')

    // Inactive badge on legacy types + delete
    await wrapper.find('[data-test="block-delete-b"]').trigger('click')
    await wrapper.find('[data-test="block-delete-confirm"]').trigger('click')
    expect(model.value.some((b) => b.id === 'b')).toBe(false)
  })

  it('normalizes snake_case block-schema fields for nested widgets (reference target)', async () => {
    // P1 pin: a reference field inside a block must reach ReferenceField as
    // camelCase FieldDef — field.referenceType drives the entry picker.
    blockTypes.value = [
      { uuid: 'bt4', slug: 'author_card', label: 'Author card', icon: null, active: true,
        schema: [{ name: 'author', type: 'reference', reference_type: 'blog' }] },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: [{ id: 'c', type: 'author_card', data: {} }],
      },
      global: { stubs: { ReferenceField: true } }, // the picker itself has its own spec
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-c"]').trigger('click')
    const nested = wrapper.findComponent({ name: 'ReferenceField' })
    expect(nested.exists()).toBe(true)
    expect((nested.props('field') as { referenceType?: string }).referenceType).toBe('blog')
  })
})
```

(Adapt prop-update plumbing to the harness idiom in existing specs if `setProps` fights `defineModel` — the meaning of each assertion is the contract.)

- [ ] **Step 3: Implement the SPA pieces**

`admin/src/queries/contentTypes.ts` — `FIELD_TYPES` gains `'blocks'`; `ContentTypeField` gains `block_types?: string[]`.

`admin/src/fields/types.ts` — `FieldDef['type']` union gains `'blocks'`; add `blockTypes?: string[]`.

`admin/src/queries/keys.ts` — `blockTypes: () => ['block-types'] as const,`.

`admin/src/fields/normalize.ts` — **the snake→camel bridge (P1 pin)**: backend schema
fields are snake_case (`reference_type`, `max_items`, `reference_slug_field`,
`block_types`) while the field widgets consume camelCase `FieldDef`
(`referenceType`, …) — `ReferenceField` reads `field.referenceType`, so passing a raw
block-schema field into a widget breaks reference/asset editing inside blocks:

```ts
import type { ContentTypeField } from '@/queries/contentTypes'
import type { FieldDef } from './types'

/**
 * Backend field-schema entry (snake_case wire shape) → the camelCase FieldDef the
 * field widgets consume. The entry editor's schema computed does this mapping inline
 * for top-level fields; block schemas arrive raw from the block-types API, so nested
 * widgets need the same bridge.
 */
export function toFieldDef(f: ContentTypeField): FieldDef {
  return {
    name: String(f.name ?? ''),
    type: (f.type ?? 'string') as FieldDef['type'],
    required: f.required ?? undefined,
    enum: f.enum ?? undefined,
    format: (f.format ?? undefined) as FieldDef['format'],
    referenceType: f.reference_type ?? undefined,
    multiple: f.multiple ?? undefined,
    maxItems: f.max_items ?? undefined,
    referenceSlugField: f.reference_slug_field ?? undefined,
    blockTypes: f.block_types ?? undefined,
  }
}
```

(Compare with the inline mapping in `pages/content/[type]/[uuid]/index.vue`'s `schema`
computed and keep the two field-for-field consistent; optionally refactor that
computed to call `toFieldDef` — same mapping, one source.)

`admin/src/queries/blockTypes.ts` (typed client; Pinia Colada like `contentTypes.ts` — read that file and mirror its `useQuery` + mutation idioms):

```ts
import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'
import type { ContentTypeField } from './contentTypes'

export interface BlockType {
  uuid: string
  slug: string
  label: string
  icon: string | null
  /** Free-form picker grouping ("Layout", "Content", …); null groups under "Other". */
  category: string | null
  description: string | null
  active: boolean
  schema: ContentTypeField[]
}

export async function fetchBlockTypes(): Promise<BlockType[]> {
  const { data, error, response } = await client.GET('/block-types')
  if (error) throw toApiError(error, response)
  return ((data as unknown as { data?: { block_types?: BlockType[] } })?.data?.block_types ??
    []) as BlockType[]
}

export function useBlockTypes() {
  return useQuery({ key: qk.blockTypes, query: fetchBlockTypes })
}

export function useBlockTypeMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk.blockTypes() })
  const create = useMutation({
    mutation: (body: { slug: string; label: string; icon?: string | null;
      category?: string | null; description?: string | null; schema: ContentTypeField[] }) =>
      client.POST('/block-types', { body }).then(({ error, response }) => {
        if (error) throw toApiError(error, response)
      }),
    onSettled: invalidate,
  })
  const update = useMutation({
    mutation: ({ slug, ...body }: { slug: string; label: string; icon?: string | null;
      category?: string | null; description?: string | null; schema: ContentTypeField[] }) =>
      client.PATCH('/block-types/{slug}', { params: { path: { slug } }, body }).then(({ error, response }) => {
        if (error) throw toApiError(error, response)
      }),
    onSettled: invalidate,
  })
  const setActive = useMutation({
    mutation: ({ slug, active }: { slug: string; active: boolean }) =>
      client.POST(active ? '/block-types/{slug}/activate' : '/block-types/{slug}/deactivate', {
        params: { path: { slug } },
      }).then(({ error, response }) => {
        if (error) throw toApiError(error, response)
      }),
    onSettled: invalidate,
  })
  return { create, update, setActive }
}
```

(Typed-client note: if the generated body/response types make the `.then` shape awkward, follow whatever `contentTypes.ts` actually does — mirror, don't fight.)

`admin/src/fields/components/BlocksField.vue` — the widget (complete component; nested fields reuse `fieldComponent()`):

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import type { FieldDef } from '../types'
import { fieldComponent } from '../registry'
import { toFieldDef } from '../normalize'
import { useBlockTypes, type BlockType } from '@/queries/blockTypes'

interface BlockInstance {
  id: string
  type: string
  data: Record<string, unknown>
}

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<BlockInstance[]>({ default: () => [] })

const { data: allTypes } = useBlockTypes()
const bySlug = computed(() => new Map((allTypes.value ?? []).map((t) => [t.slug, t])))

// Picker: ACTIVE types, filtered by the field's picker-only allowlist (spec §1).
const pickerTypes = computed(() => {
  const allow = props.field.blockTypes ?? []
  return (allTypes.value ?? []).filter(
    (t) => t.active && (allow.length === 0 || allow.includes(t.slug)),
  )
})

const pickerOpen = ref(false)
const expanded = ref<Record<string, boolean>>({})
const pendingDelete = ref<string | null>(null)

// Client-side nanoid-ish id (server regenerates only when absent).
function newId(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(12)))
    .map((b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36])
    .join('')
}

function addBlock(type: BlockType) {
  const block: BlockInstance = { id: newId(), type: type.slug, data: {} }
  model.value = [...(model.value ?? []), block]
  expanded.value[block.id] = true
  pickerOpen.value = false
}

function move(id: string, delta: number) {
  const list = [...(model.value ?? [])]
  const from = list.findIndex((b) => b.id === id)
  const to = from + delta
  if (from < 0 || to < 0 || to >= list.length) return
  const [item] = list.splice(from, 1)
  list.splice(to, 0, item!)
  model.value = list
}

function duplicate(id: string) {
  const list = [...(model.value ?? [])]
  const index = list.findIndex((b) => b.id === id)
  if (index < 0) return
  const copy = { ...list[index]!, id: newId(), data: { ...list[index]!.data } }
  list.splice(index + 1, 0, copy)
  model.value = list
}

function remove(id: string) {
  model.value = (model.value ?? []).filter((b) => b.id !== id)
  pendingDelete.value = null
}

function patchData(id: string, name: string, value: unknown) {
  model.value = (model.value ?? []).map((b) =>
    b.id === id ? { ...b, data: { ...b.data, [name]: value } } : b,
  )
}

function summary(block: BlockInstance, type: BlockType | undefined): string {
  for (const f of type?.schema ?? []) {
    const v = block.data[f.name]
    if (typeof v === 'string' && v.trim() !== '') return v.slice(0, 60)
  }
  return ''
}
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <div class="space-y-2" data-test="blocks-field">
      <div
        v-for="block in model"
        :key="block.id"
        class="rounded-lg border border-default"
        :data-test="`block-card-${block.id}`"
      >
        <div class="flex items-center gap-2 px-3 py-2">
          <UIcon :name="bySlug.get(block.type)?.icon || 'i-lucide-box'" class="shrink-0" />
          <button
            class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
            :data-test="`block-toggle-${block.id}`"
            @click="expanded[block.id] = !expanded[block.id]"
          >
            <span class="font-medium">{{ bySlug.get(block.type)?.label ?? block.type }}</span>
            <span class="truncate text-muted">{{ summary(block, bySlug.get(block.type)) }}</span>
          </button>
          <UBadge
            v-if="bySlug.get(block.type) && !bySlug.get(block.type)!.active"
            size="xs"
            color="warning"
            variant="subtle"
            :data-test="`block-inactive-${block.id}`"
          >
            inactive
          </UBadge>
          <UButton
            variant="ghost" color="neutral" size="xs" icon="i-lucide-chevron-up"
            :data-test="`block-move-up-${block.id}`" aria-label="Move up" @click="move(block.id, -1)"
          />
          <UButton
            variant="ghost" color="neutral" size="xs" icon="i-lucide-chevron-down"
            :data-test="`block-move-down-${block.id}`" aria-label="Move down" @click="move(block.id, 1)"
          />
          <UButton
            variant="ghost" color="neutral" size="xs" icon="i-lucide-copy"
            :data-test="`block-duplicate-${block.id}`" aria-label="Duplicate" @click="duplicate(block.id)"
          />
          <UButton
            variant="ghost" color="error" size="xs" icon="i-lucide-trash-2"
            :data-test="`block-delete-${block.id}`" aria-label="Delete" @click="pendingDelete = block.id"
          />
        </div>
        <div v-if="pendingDelete === block.id" class="flex items-center gap-2 border-t border-default px-3 py-2">
          <span class="flex-1 text-sm text-muted">Delete this block?</span>
          <UButton size="xs" color="error" data-test="block-delete-confirm" @click="remove(block.id)">
            Delete
          </UButton>
          <UButton size="xs" variant="ghost" color="neutral" @click="pendingDelete = null">Cancel</UButton>
        </div>
        <div v-if="expanded[block.id]" class="space-y-3 border-t border-default p-3">
          <!-- toFieldDef: block schemas arrive snake_case; widgets consume camelCase
               FieldDef (ReferenceField reads field.referenceType — P1 pin). -->
          <component
            :is="fieldComponent(toFieldDef(f).type)"
            v-for="f in bySlug.get(block.type)?.schema ?? []"
            :key="f.name"
            :field="toFieldDef(f)"
            :model-value="block.data[f.name]"
            @update:model-value="(v: unknown) => patchData(block.id, f.name, v)"
          />
        </div>
      </div>

      <div class="relative">
        <UButton
          variant="subtle" color="neutral" icon="i-lucide-plus" data-test="add-block"
          @click="pickerOpen = !pickerOpen"
        >
          Add block
        </UButton>
        <div
          v-if="pickerOpen"
          class="mt-2 rounded-lg border border-default p-1"
          data-test="block-picker"
        >
          <button
            v-for="t in pickerTypes"
            :key="t.slug"
            class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-elevated"
            :data-test="`picker-item-${t.slug}`"
            @click="addBlock(t)"
          >
            <UIcon :name="t.icon || 'i-lucide-box'" />
            <span class="font-medium">{{ t.label }}</span>
            <span v-if="t.description" class="truncate text-muted">{{ t.description }}</span>
          </button>
          <p v-if="!pickerTypes.length" class="px-2 py-1.5 text-sm text-muted">No block types available.</p>
        </div>
      </div>
    </div>
  </UFormField>
</template>
```

`admin/src/fields/registry.ts` — import `BlocksField` and add `blocks: BlocksField` (and `fields/types.ts` union updated in the same step so the record type accepts it).

`admin/src/components/ContentTypeFields.vue` — three additions (read the whole file first; mirror its patterns):
1. A `context` prop: `withDefaults(defineProps<{ context?: 'content-type' | 'block-type' }>(), { context: 'content-type' })`; `typeItems` becomes a computed excluding `'blocks'` when `context === 'block-type'`; the `localized` and `filterable` toggles render only when `context === 'content-type'` (block schemas reject them — §2).
2. When a field's type is `blocks`: hide filterable, show a **block-types multi-select** fed by `useBlockTypes()` (active types; value ↔ `block_types`), and clear `block_types` on type change away (extend `onTypeChange`).
3. `addField` unchanged.

`admin/src/pages/settings/block-types/` — three pages mirroring `settings/content-types/` (read `index.vue`/`new.vue`/`[slug].vue` there first and keep their structure): the list shows label/slug/active with an activate/deactivate toggle; `new.vue` = slug+label+icon+description+`<ContentTypeFields context="block-type">`; `[slug].vue` = same minus slug editing. Nav: add a "Block types" item next to wherever "Content types" is registered (`registry/coreModule.ts`) with the same capability/permission gating.

- [ ] **Step 4: Verify**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin
pnpm type-check > /tmp/tc.log 2>&1; echo "TC_EXIT=$?"
pnpm test > /tmp/vt.log 2>&1; echo "VT_EXIT=$?"; grep -E "Test Files|Tests " /tmp/vt.log
```
Expected: both 0, all tests green. **No staging yet.**

---

### Task 7: Docs + OpenAPI assertions + full verification + STAGE

**Files:**
- Modify: `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md`; regenerate `docs/openapi.json` + admin client.

- [ ] **Step 1: CHANGELOG `[Unreleased]` → `### Added`** (prepend):

```markdown
- **Page/block builder**: a `blocks` content field type — ordered `{id, type, data}`
  lists inside entry JSON, so versions/publish/localization/delivery work unchanged —
  backed by a global admin-defined block-type registry (`lemma_block_types`:
  slug-immutable, deactivate-over-delete, schemas reusing the content field
  vocabulary minus nesting/localization/filterable). Per-block validation with
  dot-path errors and publish-time dangling-reference checks; `block_types`
  field allowlists are picker-only by design. Structured block-list editor in the
  entry editor (picker, reorder, duplicate, collapse; nested fields reuse the
  existing widgets) + a Block types settings screen. Rendering via a new `blocks()`
  Twig function through `blocks/{type}.twig` (theme or DB-edited templates), added
  to the DB-template sandbox allowlist with a policy cache-version bump. References
  inside blocks stay raw uuids (no auto-expansion in v1).
```

- [ ] **Step 2: Trackers** — `docs/V2_DESIGN.md` §6: `- page/block builder` becomes:

```markdown
- ✅ page/block builder — **shipped 2026-07-03**
  (`docs/superpowers/specs/2026-07-03-page-block-builder-design.md`)
```

`docs/NEXT.md` — append beside the other ✅ render-track notes:

```markdown
   ✅ **Page/block builder** also shipped (2026-07-03): `blocks` field type + global
   block-type registry + `blocks/{type}.twig` rendering. Spec:
   `docs/superpowers/specs/2026-07-03-page-block-builder-design.md`.
```

- [ ] **Step 3: OpenAPI + client + assertions**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
php glueful generate:openapi -f --clean
python3 -c "
import json; spec = json.load(open('docs/openapi.json'))
paths = spec['paths']
assert '/v1/admin/block-types' in paths, 'block-types index missing'
assert '/v1/admin/block-types/{slug}' in paths and 'patch' in paths['/v1/admin/block-types/{slug}'], 'update missing'
assert '/v1/admin/block-types/{slug}/deactivate' in paths, 'deactivate missing'
assert not any(p.startswith('/_preview') for p in paths), 'preview routes leaked'
print('openapi OK,', len(paths), 'paths')
"
cd admin && pnpm gen:api && pnpm type-check && pnpm test && cd ..
```

- [ ] **Step 4: Full verification + STAGE** *(grouping 2 — commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add admin/src CHANGELOG.md docs/V2_DESIGN.md docs/NEXT.md docs/openapi.json
```

Expected: green (pre-existing single skip only). STOP — when authorized:

```bash
git commit -m "feat(admin): block-list editor + block-types settings screen

BlocksField widget (picker filtered to active types + the field's picker-only
allowlist, reorder/duplicate/delete, collapse with summaries, inactive badges,
nested editing through the existing field widgets); ContentTypeFields gains a
block-type context (no blocks/localized/filterable inside block schemas) and a
block_types multi-select for blocks fields; Block types settings CRUD with
activate/deactivate; typed client + docs refreshed."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 field type + every round-trip surface (Task 2 — `FieldDefinitionData`, `FieldSchemaData`, `ContentTypeSchema::toArray`, `FieldType`, `EditorialFieldTypes` with the exact pinned flags) + registry/table/route permissions (Tasks 1+4); §2 rules as `assertBlockSchema` + tests (nesting/localized/filterable, deactivate semantics, slug immutability via no-update path); §3 non-migrating evolution — the cleaned-payload strip is asserted in `testValidBlocksClean…` (stale key removed on save, history untouched by construction); §4 validation matrix fully mapped incl. picker-only-allowlist acceptance and strict-publish dangling refs; §5 no-expansion — no `ReferenceResolver` change anywhere in the plan (the README line documents raw uuids + `path()`); §6 render context/miss behavior/log-once/`needs_context`/`is_safe`/path-safe slugs + `FUNCTIONS`+`CACHE_VERSION=2` (Task 5, incl. the lint-clean test); §7 SPA (Task 6); §8 error table covered across Task 1/3/4/5 tests; §9 test list mapped; OpenAPI in Task 7.
- **Type consistency:** `BlockTypeRepository` method names match every consumer (validator `schemasBySlug`, controller `findBySlug/create/updateSchema/setActive`); block instance shape `{id, type, data}` identical across validator clean output, render context, and the SPA `BlockInstance`; `block_types` (snake) on wire/DTO vs `blockTypes` (camel) on `FieldDefinition`/`FieldDef` — consistent with the existing `reference_type`/`referenceType` split.
- **Verified during planning:** `ContentTypeSchema::fromArray` and `ValidationException::errors()` are the real names (used as-is in the plan's code). Debug + logger for the render extension are PINNED as provider-injected (`makeRenderContextExtension` passes `LoggerInterface` + `(bool) config($context, 'app.debug', false)`) — not Twig-context reads. **Verify-don't-guess points still flagged inline:** `EditorialFieldTypes::make()`'s positional args; typed-client mutation idioms in `contentTypes.ts`; `FieldValidator` construction call sites; envelope response-DTO wrappers for the block-types endpoints.
- **Review-round fixes applied:** block `data` must be an OBJECT (`body.{i}.data => must be an object` for missing/scalar/non-empty-list values — never silently coerced) with the empty-`[]`-is-`{}` PHP caveat tested both ways; `toFieldDef` normalizer bridges snake_case block schemas to the camelCase `FieldDef` widgets (nested reference-field test asserts `referenceType` reaches `ReferenceField`); the block-type slug regex is enforced in `BlockTypeRepository::create` as a domain invariant (path-unsafe-slug test), not just the API DTO.
- **Judgement calls, stated:** activate/deactivate as POST subroutes (mirrors the render pack's action-route style and keeps PATCH schema-only); direct-controller API tests (the established `ContentTypeController`/`NavigationApiTest` idiom) with the route-table permission assertions carrying the auth contract; the SPA generates ids client-side for stable keys with the server as fallback generator (spec §1 allows both).
