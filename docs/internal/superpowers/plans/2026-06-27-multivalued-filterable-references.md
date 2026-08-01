# Multi-valued + Filterable References — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `reference`/`asset` content-type fields hold an ordered array of uuids (`multiple`), and let delivery filter entries by a reference target (by uuid or slug) — the relationship + query layer under which taxonomies fall out of plain content types.

**Architecture:** Storage/projection are already multi-ready (`ReferenceProjectionRepository.targets()` parses uuid arrays). The work is: (1) a schema `multiple`/`max_items`/`reference_slug_field` flag + strict array validation, (2) a delivery membership filter that normalizes the published `entry_versions.fields->'F'` to a jsonb array via one `CASE` compatibility expression and uses `@>` containment (correct across single/multi/flipped-across-versions), with slug→uuid resolution against the target type's published spine, (3) a GIN expression index for those fields, and (4) admin builder controls + ordered multi-pickers.

**Tech Stack:** PHP 8.3 (Glueful framework), PostgreSQL JSONB, Vue 3 + Nuxt UI v4, Pinia Colada, vitest + @vue/test-utils.

**Spec:** `docs/superpowers/specs/2026-06-27-multivalued-filterable-references-design.md` — read it; this plan implements it section-for-section.

**Conventions (read before starting):**
- Glueful is **not Laravel**. Query builder: `$this->db->table('x as a')->join(...)->where('c','=',$v)->whereIn('c',$arr)->whereRaw($sql,$bind)->selectRaw($sql)->get()`. Controllers return `Glueful\Http\Response::success/validation/notFound`.
- **JSON keys are never bound** — they're regex-validated (`^[a-z][a-z0-9_]*$`) then interpolated via the `FieldSqlExpression` helper, so predicates match expression indexes. **Values are always bound** as expanded `?` placeholders (never `ANY(?)`).
- Verification gates: backend `vendor/bin/phpunit --filter <Name>` + `vendor/bin/phpcs <files>`; frontend (in `admin/`) `pnpm run type-check; echo EXIT=$?` (must be 0, never piped through `tail`) + `pnpm run lint && pnpm run format` + `pnpm exec vitest run <file>`.
- Spec/type regen: `composer run docs:openapi` (root) then `cd admin && pnpm run gen:api`.
- **Do not commit until instructed.** When authorized, commit on `dev` (the per-task commit steps below are the boundaries to use *then*). No Claude/Anthropic attribution. Never stage `CLAUDE.md`.

---

## File-structure map

**Backend (schema/validation):**
- `app/Content/Schema/FieldDefinition.php` — add `multiple`/`maxItems`/`referenceSlugField` props + `fromArray` parsing; allow `filterable` on ref/asset without `filter_type`.
- `app/Content/Schema/ContentTypeSchema.php` — round-trip the new attrs in `toArray()`.
- `app/Content/Validation/FieldValidator.php` — strict multi-valued array normalization for ref/asset.

**Backend (delivery filter & index):**
- `app/Content/Indexing/FieldSqlExpression.php` — add `membershipArray()` (the normalized `CASE` expression; single source for predicate + index).
- `app/Content/Delivery/ReferenceFilterResolver.php` *(new)* — resolve uuid/slug values → target uuids.
- `app/Content/Delivery/FilterCompiler.php` — membership predicate path for filterable ref/asset; gains `$locale` + injected resolver/repo.
- `app/Content/Http/Controllers/DeliveryController.php` — pass `$locale` into `compileFilter`.
- `app/Providers/LemmaServiceProvider.php` — `FilterCompiler` constructor deps + register `ReferenceFilterResolver`.
- `app/Content/Indexing/FilterIndexPlanner.php` — emit a membership (GIN) index spec for filterable ref/asset.
- `app/Content/Indexing/EnsureFilterIndexesJob.php` — GIN DDL branch.

**Admin / OpenAPI:**
- `app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php` — document the new attrs (+ regen spec/types).
- `admin/src/fields/types.ts`, `admin/src/queries/contentTypes.ts`, `admin/src/pages/content/[type]/[uuid]/index.vue` — thread the attrs through.
- `admin/src/components/ContentTypeFields.vue` — builder controls.
- `admin/src/fields/components/MultiReferencePicker.vue` *(new)*, `ReferenceField.vue`, `AssetField.vue` — ordered multi-pickers.

**Tests:** alongside each, under `tests/` (backend) and `admin/src/.../*.spec.ts` (frontend).

---

## Phase A — Schema & validation

### Task 1: Schema attributes (`multiple`, `max_items`, `reference_slug_field`)

**Files:**
- Modify: `app/Content/Schema/FieldDefinition.php`
- Modify: `app/Content/Schema/ContentTypeSchema.php`
- Test: `tests/Unit/Content/Schema/FieldDefinitionMultiValueTest.php`

- [ ] **Step 1: Write the failing test.** `tests/Unit/Content/Schema/FieldDefinitionMultiValueTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Schema;

use App\Content\Schema\FieldDefinition;
use App\Content\Schema\SchemaParseException;
use PHPUnit\Framework\TestCase;

final class FieldDefinitionMultiValueTest extends TestCase
{
    public function testReferenceParsesMultipleMaxItemsAndSlugField(): void
    {
        $f = FieldDefinition::fromArray([
            'name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
            'multiple' => true, 'max_items' => 5, 'reference_slug_field' => 'slug',
        ]);
        self::assertTrue($f->multiple);
        self::assertSame(5, $f->maxItems);
        self::assertSame('slug', $f->referenceSlugField);
    }

    public function testReferenceSlugFieldDefaultsToSlug(): void
    {
        $f = FieldDefinition::fromArray(['name' => 'tag', 'type' => 'reference', 'reference_type' => 'tag']);
        self::assertSame('slug', $f->referenceSlugField);
        self::assertFalse($f->multiple);
        self::assertNull($f->maxItems);
    }

    public function testAssetMayBeMultiple(): void
    {
        $f = FieldDefinition::fromArray(['name' => 'gallery', 'type' => 'asset', 'multiple' => true, 'max_items' => 3]);
        self::assertTrue($f->multiple);
        self::assertSame(3, $f->maxItems);
        self::assertNull($f->referenceSlugField); // slug field is reference-only
    }

    public function testReferenceAssetMayBeFilterableWithoutFilterType(): void
    {
        $f = FieldDefinition::fromArray(['name' => 'category', 'type' => 'reference', 'reference_type' => 'category', 'filterable' => true]);
        self::assertTrue($f->filterable);
        self::assertNull($f->filterType);
    }

    public function testMaxItemsMustBePositive(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'category', 'type' => 'reference', 'multiple' => true, 'max_items' => 0]);
    }

    public function testInvalidSlugFieldNameRejected(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'category', 'type' => 'reference', 'reference_slug_field' => 'Bad-Name']);
    }

    public function testScalarFilterableStillRequiresFilterType(): void
    {
        $this->expectException(SchemaParseException::class);
        FieldDefinition::fromArray(['name' => 'price', 'type' => 'number', 'filterable' => true]);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`->multiple` etc. undefined).

Run: `vendor/bin/phpunit --filter FieldDefinitionMultiValueTest`

- [ ] **Step 3: Add the constructor props.** In `app/Content/Schema/FieldDefinition.php`, extend the constructor (after `$referenceType`):

```php
        public readonly ?string $referenceType = null,
        public readonly bool $multiple = false,
        public readonly ?int $maxItems = null,
        public readonly ?string $referenceSlugField = null,
    ) {
    }
```

- [ ] **Step 4: Parse them in `fromArray`.** Two edits.

(a) Change the `filterable`/`filterType` block so ref/asset may be filterable **without** a `filter_type` (membership semantics — see spec §4):

```php
        $filterable = (bool) ($raw['filterable'] ?? false);
        $filterType = $raw['filter_type'] ?? null;
        $membershipType = in_array($type, ['reference', 'asset'], true);
        if ($filterable && !$membershipType) {
            if (!is_string($filterType) || !in_array($filterType, self::FILTER_TYPES, true)) {
                throw new SchemaParseException("filterable field '{$name}' must declare a valid filter_type");
            }
        } else {
            // membership fields (reference/asset) carry no scalar filter_type
            $filterType = null;
        }
```

(b) Replace the `$referenceType = null; if ($type === 'reference') {...}` block with reference/asset multi-value parsing:

```php
        $referenceType = null;
        $referenceSlugField = null;
        if ($type === 'reference') {
            $rawRef = $raw['reference_type'] ?? null;
            if (is_string($rawRef) && $rawRef !== '') {
                $referenceType = $rawRef;
            }
            $rawSlug = $raw['reference_slug_field'] ?? null;
            $referenceSlugField = is_string($rawSlug) && $rawSlug !== '' ? $rawSlug : 'slug';
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $referenceSlugField) !== 1) {
                throw new SchemaParseException("field '{$name}' has invalid reference_slug_field");
            }
        }

        $multiple = false;
        $maxItems = null;
        if ($type === 'reference' || $type === 'asset') {
            $multiple = (bool) ($raw['multiple'] ?? false);
            if (array_key_exists('max_items', $raw) && $raw['max_items'] !== null) {
                $mi = $raw['max_items'];
                if (!is_int($mi) || $mi < 1) {
                    throw new SchemaParseException("field '{$name}' max_items must be a positive integer");
                }
                $maxItems = $mi;
            }
        }
```

Then pass them to the constructor call:

```php
        return new self(
            name: $name,
            type: $type,
            required: (bool) ($raw['required'] ?? false),
            localized: (bool) ($raw['localized'] ?? false),
            filterable: $filterable,
            filterType: $filterType,
            enumValues: $enum,
            format: $format,
            referenceType: $referenceType,
            multiple: $multiple,
            maxItems: $maxItems,
            referenceSlugField: $referenceSlugField,
        );
```

- [ ] **Step 5: Round-trip in `ContentTypeSchema::toArray()`.** Add the three keys to the `array_filter` map (the filter drops `false`/`null`/`[]`, so defaults stay out of the persisted JSON):

```php
            'reference_type' => $f->referenceType,
            'multiple' => $f->multiple,
            'max_items' => $f->maxItems,
            'reference_slug_field' => $f->type === 'reference' ? $f->referenceSlugField : null,
```

> Note: `reference_slug_field` defaults to `'slug'` for every reference field, so it will persist explicitly — that's fine and self-documenting. `multiple:false` and `max_items:null` are dropped by the existing `array_filter`.

- [ ] **Step 6: Run the test — expect PASS.** Then phpcs.

Run: `vendor/bin/phpunit --filter FieldDefinitionMultiValueTest && vendor/bin/phpcs app/Content/Schema/ tests/Unit/Content/Schema/FieldDefinitionMultiValueTest.php`

- [ ] **Step 7: Commit.**

```bash
git add app/Content/Schema/ tests/Unit/Content/Schema/FieldDefinitionMultiValueTest.php
git commit -m "Add multiple/max_items/reference_slug_field to the field schema"
```

---

### Task 2: Strict multi-valued validation

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php`
- Test: `tests/Unit/Content/Validation/MultiValueFieldValidatorTest.php`

- [ ] **Step 1: Write the failing test.** `tests/Unit/Content/Validation/MultiValueFieldValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Validation;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class MultiValueFieldValidatorTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category', 'multiple' => true, 'max_items' => 3],
            ['name' => 'author', 'type' => 'reference', 'reference_type' => 'author'], // single
        ]);
    }

    public function testAcceptsAndDedupesOrderedArray(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), ['category' => ['a1', 'a1', 'b2']]);
        self::assertSame(['a1', 'b2'], $clean['category']); // first-occurrence dedupe, order preserved
    }

    public function testRejectsScalarForMultipleField(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['category' => 'a1']);
    }

    public function testRejectsEmptyOrNullElements(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['category' => ['a1', '']]);
    }

    public function testEnforcesMaxItemsAfterDedupe(): void
    {
        // 4 distinct > max 3 → fail; but duplicates collapsing under the cap is fine.
        $clean = (new FieldValidator())->validate($this->schema(), ['category' => ['a', 'a', 'b', 'c']]);
        self::assertSame(['a', 'b', 'c'], $clean['category']);
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['category' => ['a', 'b', 'c', 'd']]);
    }

    public function testSingleReferenceUnchanged(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), ['author' => 'x9']);
        self::assertSame('x9', $clean['author']);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

Run: `vendor/bin/phpunit --filter MultiValueFieldValidatorTest`

- [ ] **Step 3: Implement multi-valued normalization.** In `app/Content/Validation/FieldValidator.php`, inside `validate()`, after the `checkType` block returns null and **before** the asset single-existence check, branch for multiple ref/asset. Replace the section from the `checkType` call through `$clean[$field->name] = $value;` with:

```php
            // Multi-valued reference/asset: strict ordered uuid array, deduped, capped.
            if (($field->type === 'reference' || $field->type === 'asset') && $field->multiple) {
                $normalized = $this->normalizeMultiValue($field, $value);
                if (is_string($normalized)) { // error message
                    $errors[$field->name] = $normalized;
                    continue;
                }
                if ($field->type === 'asset') {
                    foreach ($normalized as $uuid) {
                        if (!$this->assetExistsOnMediaDisk($uuid)) {
                            $errors[$field->name] = 'must reference active blobs on the configured media disk';
                            continue 2;
                        }
                    }
                }
                $clean[$field->name] = $normalized;
                continue;
            }

            $error = $this->checkType($field, $value);
            if ($error !== null) {
                $errors[$field->name] = $error;
                continue;
            }
            if ($field->type === 'asset' && is_string($value) && !$this->assetExistsOnMediaDisk($value)) {
                $errors[$field->name] = 'must reference an active blob on the configured media disk';
                continue;
            }
            if ($field->type === 'datetime' && is_string($value)) {
                $value = self::normalizeDatetime($value);
            }
            $clean[$field->name] = $value;
```

Add the normalizer (private method):

```php
    /**
     * Normalize a multiple reference/asset value to an ordered, deduped uuid array.
     * Returns the array on success, or a string error message on failure.
     *
     * @return list<string>|string
     */
    private function normalizeMultiValue(FieldDefinition $field, mixed $value): array|string
    {
        if (!is_array($value) || !array_is_list($value)) { // reject objects/maps; [] is a valid empty list
            return 'must be an array of uuids';
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                return 'each item must be a non-empty uuid';
            }
            if (!in_array($item, $out, true)) { // dedupe, first occurrence kept
                $out[] = $item;
            }
        }
        if ($field->maxItems !== null && count($out) > $field->maxItems) {
            return "must have at most {$field->maxItems} items";
        }
        return $out;
    }
```

Add `use App\Content\Schema\FieldDefinition;` if not already imported (it is used in `checkType`'s signature — confirm the import exists at the top).

- [ ] **Step 4: Run the test — expect PASS.** Then phpcs.

Run: `vendor/bin/phpunit --filter MultiValueFieldValidatorTest && vendor/bin/phpcs app/Content/Validation/FieldValidator.php tests/Unit/Content/Validation/MultiValueFieldValidatorTest.php`

- [ ] **Step 5: Commit.**

```bash
git add app/Content/Validation/FieldValidator.php tests/Unit/Content/Validation/MultiValueFieldValidatorTest.php
git commit -m "Validate multi-valued reference/asset as strict deduped uuid arrays"
```

---

### Task 3: Characterize projection read-tolerance

**Files:**
- Test: `tests/Unit/Content/ReferenceProjectionTargetsTest.php`

The spec keeps scalar→array read-tolerance **out of the validator** and in `ReferenceProjectionRepository::targets()` (already tolerant). Lock it with a characterization test (no production change).

- [ ] **Step 1: Write the test.** `tests/Unit/Content/ReferenceProjectionTargetsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Repositories\ReferenceProjectionRepository;
use PHPUnit\Framework\TestCase;

final class ReferenceProjectionTargetsTest extends TestCase
{
    public function testScalarStringBecomesSingleton(): void
    {
        self::assertSame(['a1'], ReferenceProjectionRepository::targets('a1'));
    }

    public function testArrayPassesThroughFilteringEmpties(): void
    {
        self::assertSame(['a1', 'b2'], ReferenceProjectionRepository::targets(['a1', '', 'b2']));
    }

    public function testNullAndEmptyYieldNoTargets(): void
    {
        self::assertSame([], ReferenceProjectionRepository::targets(null));
        self::assertSame([], ReferenceProjectionRepository::targets(''));
    }
}
```

- [ ] **Step 2: Run it — expect PASS** (already implemented).

Run: `vendor/bin/phpunit --filter ReferenceProjectionTargetsTest`

- [ ] **Step 3: Commit.**

```bash
git add tests/Unit/Content/ReferenceProjectionTargetsTest.php
git commit -m "Characterize reference-projection scalar/array read tolerance"
```

---

## Phase B — Delivery filter & index

### Task 4: Membership SQL expression

**Files:**
- Modify: `app/Content/Indexing/FieldSqlExpression.php`
- Test: `tests/Unit/Content/Indexing/MembershipArrayExpressionTest.php`

- [ ] **Step 1: Write the failing test.** `tests/Unit/Content/Indexing/MembershipArrayExpressionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Indexing;

use App\Content\Indexing\FieldSqlExpression;
use PHPUnit\Framework\TestCase;

final class MembershipArrayExpressionTest extends TestCase
{
    public function testMembershipArrayNormalizesToJsonbArray(): void
    {
        $sql = FieldSqlExpression::membershipArray('category');
        self::assertStringContainsString("fields -> 'category' IS NULL", $sql);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category') = 'array'", $sql);
        self::assertStringContainsString("jsonb_build_array(fields -> 'category')", $sql);
        self::assertStringContainsString("'[]'::jsonb", $sql);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

Run: `vendor/bin/phpunit --filter MembershipArrayExpressionTest`

- [ ] **Step 3: Implement.** Add to `app/Content/Indexing/FieldSqlExpression.php`:

```php
    /**
     * Normalize `fields->'F'` to a jsonb array regardless of stored shape, so single, multi, and
     * flipped-across-versions reference/asset values all filter identically via `@>`. IMMUTABLE
     * (jsonb_typeof/jsonb_build_array are immutable), so it is usable in a GIN expression index.
     *
     * The caller MUST pass a validated `[a-z][a-z0-9_]*` field name — it is interpolated, never bound.
     */
    public static function membershipArray(string $field): string
    {
        return 'CASE'
            . " WHEN fields -> '{$field}' IS NULL THEN '[]'::jsonb"
            . " WHEN jsonb_typeof(fields -> '{$field}') = 'array' THEN fields -> '{$field}'"
            . " ELSE jsonb_build_array(fields -> '{$field}') END";
    }
```

- [ ] **Step 4: Run the test — expect PASS.** Then phpcs.

Run: `vendor/bin/phpunit --filter MembershipArrayExpressionTest && vendor/bin/phpcs app/Content/Indexing/FieldSqlExpression.php tests/Unit/Content/Indexing/MembershipArrayExpressionTest.php`

- [ ] **Step 5: Commit.**

```bash
git add app/Content/Indexing/FieldSqlExpression.php tests/Unit/Content/Indexing/MembershipArrayExpressionTest.php
git commit -m "Add normalized-array membership SQL expression"
```

---

### Task 5: Reference filter resolver (slug/uuid → uuids)

**Files:**
- Create: `app/Content/Delivery/ReferenceFilterResolver.php`
- Test: `tests/Integration/Content/Delivery/ReferenceFilterResolverTest.php`

- [ ] **Step 1: Write the failing integration test.** `tests/Integration/Content/Delivery/ReferenceFilterResolverTest.php` — seed a `category` type + two published terms (news/sports) and assert resolution. Use `App\Tests\Support\LemmaTestCase` (`$this->connection()`, `$this->container()`); follow the raw-seed style of `tests/Integration/Content/EntryLocaleSummaryTest.php`. Remember: `entries` (uuid, content_type_uuid, status, created_at, updated_at), `entry_versions` (uuid, …, `fields` json), `entry_publications` (entry_uuid, locale, version_uuid, published_at — no uuid col). Confirm `entry_versions` columns against `004_CreateEntryVersionsTable.php` before seeding.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Delivery\InvalidFilterException;
use App\Content\Delivery\ReferenceFilterResolver;
use App\Content\Schema\FieldDefinition;
use App\Tests\Support\LemmaTestCase;

final class ReferenceFilterResolverTest extends LemmaTestCase
{
    private function seedType(): void
    {
        // The target type the reference points at — its slug ('category') is what the field carries;
        // the resolver looks up its uuid via ContentTypeRepository::findBySlug. (Match content_types
        // columns to the seeding style of EntryLocaleSummaryTest / WordpressContentImporterTest.)
        $this->connection()->table('content_types')->insert([
            'uuid' => 'typecatref01', 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => false, 'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-27 00:00:00', 'updated_at' => '2026-06-27 00:00:00',
        ]);
    }

    private function seedTerm(string $entryUuid, string $versionUuid, string $slug): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => 'typecatref01', 'status' => 'active',
            'created_at' => '2026-06-27 00:00:00', 'updated_at' => '2026-06-27 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en',
            'fields' => json_encode(['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'version' => 1, 'created_at' => '2026-06-27 00:00:00',
        ]); // reconcile columns with 004_CreateEntryVersionsTable.php
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en',
            'version_uuid' => $versionUuid, 'published_at' => '2026-06-27 01:00:00',
        ]);
    }

    private function field(): FieldDefinition
    {
        return FieldDefinition::fromArray([
            'name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
            'multiple' => true, 'filterable' => true, 'reference_slug_field' => 'slug',
        ]);
    }

    private function resolver(): ReferenceFilterResolver
    {
        return $this->container()->get(ReferenceFilterResolver::class);
    }

    public function testResolvesByUuidAndSlugWithUuidPrecedenceAndDedupe(): void
    {
        $this->seedType();
        $this->seedTerm('catnews00001', 'vcatnews0001', 'news');
        $this->seedTerm('catsport0001', 'vcatsport001', 'sports');

        // uuid passes through; slug resolves; duplicate collapses.
        $out = $this->resolver()->resolve($this->field(), 'en', ['catnews00001', 'sports', 'news']);
        sort($out);
        self::assertSame(['catnews00001', 'catsport0001'], $out);
    }

    public function testUnknownSlugContributesNoMatch(): void
    {
        $this->seedType();
        $this->seedTerm('catnews00001', 'vcatnews0001', 'news');
        self::assertSame([], $this->resolver()->resolve($this->field(), 'en', ['nope']));
    }

    public function testAmbiguousSlugThrows(): void
    {
        $this->seedType();
        $this->seedTerm('catnews00001', 'vcatnews0001', 'news');
        $this->seedTerm('catnews00002', 'vcatnews0002', 'news'); // duplicate slug, both published
        $this->expectException(InvalidFilterException::class);
        $this->resolver()->resolve($this->field(), 'en', ['news']);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (class missing).

Run: `vendor/bin/phpunit --filter ReferenceFilterResolverTest`

- [ ] **Step 3: Implement the resolver behind an interface.** Two files — `FilterCompiler` (Task 6) depends on the **interface**, so it stays unit-testable without subclassing a `final` class. The resolver owns the `reference_type` slug → target-uuid lookup, so `FilterCompiler` needs no `ContentTypeRepository`.

`app/Content/Delivery/ReferenceTargetResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Schema\FieldDefinition;

/**
 * Resolves a reference field's filter input values (uuids and/or slugs) to a deduped list of
 * published target entry uuids in the given delivery locale.
 */
interface ReferenceTargetResolver
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function resolve(FieldDefinition $field, string $locale, array $values): array;
}
```

`app/Content/Delivery/ReferenceFilterResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\FieldDefinition;
use Glueful\Database\Connection;

/**
 * Resolves reference-filter input values (uuids and/or slugs) to a deduped list of target entry
 * uuids, scoped to the field's target content type's published spine in the delivery locale.
 *
 * Per-input precedence: a value that equals a published target entry_uuid resolves to that uuid;
 * otherwise it resolves by the configured slug field (0 rows → dropped; >1 → ambiguous, throws).
 */
final class ReferenceFilterResolver implements ReferenceTargetResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function resolve(FieldDefinition $field, string $locale, array $values): array
    {
        if ($values === []) {
            return [];
        }
        $targetSlug = $field->referenceType ?? '';
        $targetRow = $targetSlug !== '' ? $this->types->findBySlug($targetSlug) : null;
        if ($targetRow === null || !isset($targetRow['uuid'])) {
            return []; // unknown target type → matches nothing
        }
        $slugField = $field->referenceSlugField ?? 'slug';
        // Slug field is a schema identifier — interpolate (never bind) so the lookup can hit the
        // slug field's expression index. Re-assert the safe shape here.
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $slugField) !== 1) {
            throw new InvalidFilterException("unsafe reference_slug_field: '{$slugField}'");
        }
        $slugExpr = "v.fields ->> '{$slugField}'";
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $rows = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->selectRaw("p.entry_uuid as uuid, {$slugExpr} as slug")
            ->where('e.content_type_uuid', '=', (string) $targetRow['uuid'])
            ->where('e.status', '=', 'active')
            ->where('p.locale', '=', $locale)
            ->whereRaw("(p.entry_uuid IN ({$placeholders}) OR {$slugExpr} IN ({$placeholders}))", [...$values, ...$values])
            ->get();

        $isUuid = [];
        $bySlug = [];
        foreach ($rows as $r) {
            $uuid = (string) $r['uuid'];
            $isUuid[$uuid] = true;
            $slug = $r['slug'] ?? null;
            if (is_string($slug) && $slug !== '') {
                $bySlug[$slug][$uuid] = true;
            }
        }

        $resolved = [];
        foreach ($values as $val) {
            if (isset($isUuid[$val])) {
                $resolved[$val] = true; // uuid precedence
                continue;
            }
            $matches = array_keys($bySlug[$val] ?? []);
            if (count($matches) === 0) {
                continue; // no match → dropped
            }
            if (count($matches) > 1) {
                throw new InvalidFilterException("ambiguous slug '{$val}' for reference filter");
            }
            $resolved[$matches[0]] = true;
        }

        return array_keys($resolved);
    }
}
```

- [ ] **Step 4: Register it + bind the interface.** In `app/Providers/LemmaServiceProvider.php` `services()`, register the concrete resolver and bind the interface to it. Use the project's existing interface→implementation binding pattern — grep `LemmaServiceProvider.php` for an interface key bound to a concrete class and mirror it:

```php
            ReferenceFilterResolver::class => [
                'class' => ReferenceFilterResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceTargetResolver::class => [
                // interface → impl. If the container has a dedicated alias mechanism, use it so this
                // shares the concrete's instance; otherwise a second autowired instance is harmless
                // (the resolver is stateless).
                'class' => ReferenceFilterResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
```

Add `use App\Content\Delivery\ReferenceFilterResolver;` and `use App\Content\Delivery\ReferenceTargetResolver;` with the other `use` imports.

- [ ] **Step 5: Run the test — expect PASS.** Then phpcs.

Run: `vendor/bin/phpunit --filter ReferenceFilterResolverTest && vendor/bin/phpcs app/Content/Delivery/ReferenceFilterResolver.php app/Content/Delivery/ReferenceTargetResolver.php`

- [ ] **Step 6: Commit.**

```bash
git add app/Content/Delivery/ReferenceTargetResolver.php app/Content/Delivery/ReferenceFilterResolver.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/Delivery/ReferenceFilterResolverTest.php
git commit -m "Add reference filter resolver (uuid/slug → target uuids)"
```

---

### Task 6: Membership predicate in FilterCompiler

**Files:**
- Modify: `app/Content/Delivery/FilterCompiler.php`
- Test: `tests/Unit/Content/Delivery/MembershipFilterCompileTest.php`

The resolver needs a real DB; to unit-test the **SQL shaping** in isolation, inject a tiny fake resolver. The end-to-end behavior is covered by Task 9.

- [ ] **Step 1: Write the failing unit test.** `tests/Unit/Content/Delivery/MembershipFilterCompileTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Delivery;

use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\InvalidFilterException;
use App\Content\Delivery\ReferenceTargetResolver;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use PHPUnit\Framework\TestCase;

final class MembershipFilterCompileTest extends TestCase
{
    private function compiler(array $resolveMap): FilterCompiler
    {
        // Fake resolver implementing the interface — no final-class subclassing needed.
        $resolver = new class ($resolveMap) implements ReferenceTargetResolver {
            /** @param array<string,list<string>> $map keyed by the imploded input values */
            public function __construct(private array $map)
            {
            }
            public function resolve(FieldDefinition $field, string $locale, array $values): array
            {
                return $this->map[implode(',', $values)] ?? [];
            }
        };
        return new FilterCompiler($resolver);
    }

    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category', 'multiple' => true, 'filterable' => true],
            ['name' => 'gallery', 'type' => 'asset', 'multiple' => true, 'filterable' => true],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
    }

    public function testReferenceEqResolvesAndContains(): void
    {
        $c = $this->compiler(['news' => ['catnews00001']]);
        $out = $c->compile($this->schema(), ['category' => ['eq' => 'news']], 'en');
        self::assertStringContainsString('@> jsonb_build_array(?::text)', $out['sql']);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category')", $out['sql']);
        self::assertSame(['catnews00001'], $out['bindings']);
    }

    public function testReferenceInResolvesEachToOredContainment(): void
    {
        $c = $this->compiler(['a,b' => ['ua', 'ub']]);
        $out = $c->compile($this->schema(), ['category' => ['in' => 'a,b']], 'en');
        self::assertSame(['ua', 'ub'], $out['bindings']);
        self::assertStringContainsString(' OR ', $out['sql']);
    }

    public function testAssetIsUuidOnlyNoResolution(): void
    {
        $c = $this->compiler([]); // resolver never consulted for assets
        $out = $c->compile($this->schema(), ['gallery' => ['eq' => 'blob00000001']], 'en');
        self::assertSame(['blob00000001'], $out['bindings']);
    }

    public function testNoResolvedTargetsMatchesNothing(): void
    {
        $c = $this->compiler(['ghost' => []]);
        $out = $c->compile($this->schema(), ['category' => ['eq' => 'ghost']], 'en');
        self::assertStringContainsString('1 = 0', $out['sql']);
        self::assertSame([], $out['bindings']);
    }

    public function testOrderedOpRejectedForReference(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compiler([])->compile($this->schema(), ['category' => ['gt' => 'x']], 'en');
    }

    public function testScalarPathStillWorks(): void
    {
        $out = $this->compiler([])->compile($this->schema(), ['price' => ['gt' => '10']], 'en');
        self::assertStringContainsString("(fields ->> 'price')::numeric > ?", $out['sql']);
        self::assertSame([10], $out['bindings']);
    }
}
```

> The fake implements `ReferenceTargetResolver` (defined in Task 5), so no `final` class is subclassed. `FilterCompiler` depends only on that interface — it never touches `ContentTypeRepository` (the resolver owns the slug→type-uuid lookup).

- [ ] **Step 2: Run it — expect FAIL** (constructor signature/`compile` arity).

Run: `vendor/bin/phpunit --filter MembershipFilterCompileTest`

- [ ] **Step 3: Implement the membership path.** In `app/Content/Delivery/FilterCompiler.php`:

(a) Add constructor + imports:

```php
use App\Content\Indexing\FieldSqlExpression;
// `ReferenceTargetResolver` is in the same namespace (App\Content\Delivery) — no import needed.
// ... existing imports ...

final class FilterCompiler
{
    /** Max values accepted in a reference/asset `in` (and max resolved targets). */
    private const MEMBERSHIP_MAX_VALUES = 50;

    public function __construct(private readonly ReferenceTargetResolver $references)
    {
    }
```

(b) Change `compile()` to take `$locale` and branch on membership fields:

```php
    public function compile(ContentTypeSchema $schema, array $filterParam, string $locale): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($filterParam as $fieldName => $ops) {
            $field = $schema->field((string) $fieldName);
            if ($field !== null && $field->filterable && in_array($field->type, ['reference', 'asset'], true)) {
                if (!is_array($ops)) {
                    throw new InvalidFilterException("filter '{$field->name}' must be an object of operators");
                }
                [$clause, $opBindings] = $this->compileMembership($field, $ops, $locale);
            } else {
                $field = $this->resolveFilterableField($schema, (string) $fieldName);
                if (!is_array($ops)) {
                    throw new InvalidFilterException("filter '{$field->name}' must be an object of operators");
                }
                [$clause, $opBindings] = $this->compileScalar($field, $ops);
            }
            $clauses[] = $clause;
            foreach ($opBindings as $b) {
                $bindings[] = $b;
            }
        }

        return ['sql' => implode(' AND ', $clauses), 'bindings' => $bindings];
    }
```

> Refactor note: extract the existing per-field scalar operator loop (the current body of `compile`'s inner `foreach ($ops...)`) into a `compileScalar(FieldDefinition $field, array $ops): array{0:string,1:list<mixed>}` returning `[implode(' AND ', $clauses), $bindings]`, reusing the existing `compilePredicate`. This keeps the scalar path byte-for-byte identical.

(c) Add the membership compiler:

```php
    /**
     * @param array<string,mixed> $ops
     * @return array{0:string,1:list<mixed>}
     */
    private function compileMembership(FieldDefinition $field, array $ops, string $locale): array
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $field->name) !== 1) {
            throw new InvalidFilterException("unsafe field name: '{$field->name}'");
        }
        $expr = FieldSqlExpression::membershipArray($field->name);
        $clauses = [];
        $bindings = [];

        foreach ($ops as $op => $rawValue) {
            if ($op !== 'eq' && $op !== 'in') {
                throw new InvalidFilterException("operator '{$op}' is not allowed for {$field->type} field '{$field->name}'");
            }
            $values = $this->membershipValues($field, $rawValue);
            if ($op === 'eq' && count($values) !== 1) {
                throw new InvalidFilterException("operator 'eq' for '{$field->name}' takes a single value");
            }

            $targets = $field->type === 'reference'
                ? $this->references->resolve($field, $locale, $values)
                : array_values(array_unique($values)); // asset: uuid-only, no resolution

            if ($targets === []) {
                $clauses[] = '1 = 0'; // resolves to nothing
                continue;
            }
            $ors = [];
            foreach ($targets as $t) {
                $ors[] = "({$expr}) @> jsonb_build_array(?::text)";
                $bindings[] = $t;
            }
            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * @return list<string>
     */
    private function membershipValues(FieldDefinition $field, mixed $raw): array
    {
        $parts = is_array($raw) ? array_values($raw) : explode(',', (string) $raw);
        $parts = array_values(array_filter(
            array_map(static fn($v): string => is_string($v) ? trim($v) : (string) $v, $parts),
            static fn(string $v): bool => $v !== ''
        ));
        if ($parts === []) {
            throw new InvalidFilterException("filter for '{$field->name}' requires at least one value");
        }
        if (count($parts) > self::MEMBERSHIP_MAX_VALUES) {
            throw new InvalidFilterException("filter for '{$field->name}' accepts at most " . self::MEMBERSHIP_MAX_VALUES . ' values');
        }
        return $parts;
    }
```

Add `use App\Content\Schema\FieldDefinition;` if not present. (Target-uuid + slug resolution now lives in `ReferenceFilterResolver`; `FilterCompiler` just calls `$this->references->resolve($field, $locale, $values)`.)

- [ ] **Step 4: Run the test — expect PASS.** Then phpcs.

Run: `vendor/bin/phpunit --filter MembershipFilterCompileTest && vendor/bin/phpcs app/Content/Delivery/FilterCompiler.php`

- [ ] **Step 5: Commit.**

```bash
git add app/Content/Delivery/FilterCompiler.php tests/Unit/Content/Delivery/MembershipFilterCompileTest.php
git commit -m "Compile reference/asset membership filter predicates"
```

---

### Task 7: Thread locale through the controller + DI wiring

**Files:**
- Modify: `app/Content/Http/Controllers/DeliveryController.php`
- Modify: `app/Providers/LemmaServiceProvider.php`

`FilterCompiler::compile()` now needs `$locale` and constructor deps. The call site already has `$locale`.

- [ ] **Step 1: Update the controller call.** In `app/Content/Http/Controllers/DeliveryController.php`, change `compileFilter` to accept and forward the locale:

```php
    private function compileFilter(array $filter, ContentTypeSchema $schema, string $locale): ?array
    {
        if ($filter === []) {
            return null;
        }
        return $this->filters->compile($schema, $filter, $locale);
    }
```

And the call (the `index` method, ~line 114): pass `$locale` — it is already computed on the line above:

```php
        $filter = $this->compileFilter($query->filter, $schema, $locale);
```

> Check every caller of `compileFilter` in this controller (there may be a single-entry/by-route delivery path too) and pass the corresponding `$locale` each carries.

- [ ] **Step 2: Update DI registration.** In `app/Providers/LemmaServiceProvider.php`, ensure `FilterCompiler` is registered with autowire so its constructor dep (`ReferenceTargetResolver`, bound to `ReferenceFilterResolver` in Task 5) resolves. If it had an explicit definition without `'autowire' => true`, add it:

```php
            FilterCompiler::class => [
                'class' => FilterCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 3: Smoke-check the container boots and existing scalar-filter tests still pass.**

Run: `vendor/bin/phpunit --filter "FilterCompiler|Delivery"` (the existing delivery/filter suite). Expected: green (scalar path unchanged; signature change compiles).

> If existing tests call `FilterCompiler::compile($schema, $filter)` with two args, update those call sites to pass a locale (e.g. `'en'`). Grep: `grep -rn "->compile(" tests app | grep -i filter`.

- [ ] **Step 4: Commit.**

```bash
git add app/Content/Http/Controllers/DeliveryController.php app/Providers/LemmaServiceProvider.php tests
git commit -m "Thread delivery locale into reference-aware filter compilation"
```

---

### Task 8: GIN expression index for membership fields

**Files:**
- Modify: `app/Content/Indexing/FilterIndexPlanner.php`
- Modify: `app/Content/Indexing/EnsureFilterIndexesJob.php`
- Test: `tests/Unit/Content/Indexing/MembershipIndexPlanTest.php`

- [ ] **Step 1: Write the failing test.** `tests/Unit/Content/Indexing/MembershipIndexPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Indexing;

use App\Content\Indexing\FilterIndexPlanner;
use App\Content\Schema\ContentTypeSchema;
use PHPUnit\Framework\TestCase;

final class MembershipIndexPlanTest extends TestCase
{
    public function testFilterableReferencePlansGinMembershipIndex(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category', 'multiple' => true, 'filterable' => true],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
        $plan = (new FilterIndexPlanner())->desiredIndexes($schema, 'type00000001');

        $byField = [];
        foreach ($plan as $p) {
            $byField[$p['field']] = $p;
        }
        self::assertSame('gin', $byField['category']['method']);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category')", $byField['category']['expression']);
        self::assertSame('btree', $byField['price']['method'] ?? 'btree');
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (membership field skipped + no `method` key).

Run: `vendor/bin/phpunit --filter MembershipIndexPlanTest`

- [ ] **Step 3: Extend the planner.** In `app/Content/Indexing/FilterIndexPlanner.php`, rewrite `desiredIndexes()` so membership fields are included and each spec carries a `method`:

```php
    public function desiredIndexes(ContentTypeSchema $schema, string $typeUuid): array
    {
        $out = [];
        foreach ($schema->fields() as $field) {
            $membership = $field->filterable && in_array($field->type, ['reference', 'asset'], true);
            $scalar = $field->filterable && $field->filterType !== null;
            if (!$membership && !$scalar) {
                continue;
            }
            $name = $field->name;
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new \InvalidArgumentException("unsafe field name for index expression: '{$name}'");
            }
            $out[] = $membership
                ? [
                    'field' => $name,
                    'filter_type' => $field->type, // 'reference' | 'asset'
                    'method' => 'gin',
                    'index_name' => 'lemma_fidx_' . substr(sha1($typeUuid . $name), 0, 16),
                    'expression' => '(' . FieldSqlExpression::membershipArray($name) . ') jsonb_path_ops',
                ]
                : [
                    'field' => $name,
                    'filter_type' => (string) $field->filterType,
                    'method' => 'btree',
                    'index_name' => 'lemma_fidx_' . substr(sha1($typeUuid . $name), 0, 16),
                    'expression' => $this->expression($name, (string) $field->filterType),
                ];
        }
        return $out;
    }
```

> The GIN `expression` embeds the opclass (`jsonb_path_ops`) directly, because the DDL renders `USING gin (<expression>)`. The btree branch keeps the existing `expression()` helper output unchanged.

- [ ] **Step 4: Add the GIN DDL branch.** In `app/Content/Indexing/EnsureFilterIndexesJob.php` `createIndex()`, build the `USING` clause from the spec's `method` (default `btree` for back-compat with rows that predate the key):

```php
        $method = isset($d['method']) && $d['method'] === 'gin' ? 'gin' : 'btree';
        $using = $method === 'gin' ? ' USING gin' : '';
        $sql = sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s%s (%s)',
            $name,
            self::TABLE,
            $using,
            $d['expression']
        );
```

- [ ] **Step 5: Drop-and-recreate stale indexes on a family change.** A scalar→membership flip (or any filter-type change) keeps the same `index_name` (`sha1($typeUuid . $name)`) but a different `method`/`expression`; the current `reconcile()` skips any same-name `ready` row (`EnsureFilterIndexesJob.php:88`), leaving the old btree in place. In `reconcile()`, change the create loop to drop the old physical index first when the registry's `filter_type` differs from desired (so the `IF NOT EXISTS` create actually rebuilds it):

```php
        // Create / ensure desired indexes.
        foreach ($desired as $d) {
            $this->assertSafeName($d['index_name']);
            $current = $existingByName[$d['index_name']] ?? null;
            $stale = $current !== null
                && ($current['status'] ?? '') === 'ready'
                && (string) ($current['filter_type'] ?? '') !== $d['filter_type'];
            if ($stale) {
                // Family changed (e.g. scalar 'number' → membership 'reference'); the index name is
                // stable, so drop the old physical index before recreating with the new definition.
                $this->dropIndex($db, $d['index_name'], $logger);
            }
            if ($current === null || ($current['status'] ?? '') !== 'ready' || $stale) {
                $this->createIndex($db, $typeUuid, $d, $logger);
            }
        }
```

> `createIndex()` calls `upsertRegistry(..., 'pending')` then `markStatus(..., 'ready')`, updating the same `(content_type_uuid, field)` row's `filter_type` to the new value — so the registry no longer reports the stale family, and the `index_name` is unchanged (no unique-constraint churn).

- [ ] **Step 6: Write the index-change integration test.** `tests/Integration/Content/Indexing/MembershipIndexReconcileTest.php` (real Postgres; mirror any existing `EnsureFilterIndexesJob` integration test — `grep -rln "EnsureFilterIndexesJob\|lemma_filter_indexes" tests`). Seed a content type whose `category` field is a **scalar** filterable field (`filter_type: string`); run `reconcile()`; flip `category` to a **multiple filterable reference**; run `reconcile()` again; assert (a) the `lemma_filter_indexes` row for `category` now has `filter_type = 'reference'` and `status = 'ready'`, and (b) the live index is GIN — `SELECT indexdef FROM pg_indexes WHERE indexname = :name` contains `USING gin`.

- [ ] **Step 7: Run the tests — expect PASS.** Then phpcs.

Run: `vendor/bin/phpunit --filter "MembershipIndexPlanTest|MembershipIndexReconcileTest" && vendor/bin/phpcs app/Content/Indexing/`

- [ ] **Step 8: Commit.**

```bash
git add app/Content/Indexing/FilterIndexPlanner.php app/Content/Indexing/EnsureFilterIndexesJob.php tests/Unit/Content/Indexing/MembershipIndexPlanTest.php tests/Integration/Content/Indexing/MembershipIndexReconcileTest.php
git commit -m "Plan/build GIN membership indexes; rebuild stale indexes on family change"
```

---

### Task 9: End-to-end delivery filter integration

**Files:**
- Test: `tests/Integration/Content/Delivery/ReferenceDeliveryFilterTest.php`

Exercises the real product behavior through `DeliveryController` (or `DeliveryRepository` + `FilterCompiler` if a controller-level harness is heavier). Prefer the same harness existing delivery integration tests use — find one with `grep -rln "listPublished\|DeliveryController" tests` and mirror its setup.

- [ ] **Step 1: Write the integration test.** `tests/Integration/Content/Delivery/ReferenceDeliveryFilterTest.php`. Seed: a `post` type with a `multiple` filterable `category` reference field + a `category` type with a `slug` field; two category terms (news/sports), three posts published with category arrays; then assert:

```php
public function testFilterByUuidSlugAndIn(): void
{
    // ... seed posts: p1->[news], p2->[news,sports], p3->[sports] (all published) ...
    self::assertEqualsCanonicalizing(['p1','p2'], $this->deliverFilter('post', ['category' => ['eq' => 'newsUuid']]));
    self::assertEqualsCanonicalizing(['p1','p2'], $this->deliverFilter('post', ['category' => ['eq' => 'news']]));      // slug
    self::assertEqualsCanonicalizing(['p1','p2','p3'], $this->deliverFilter('post', ['category' => ['in' => 'news,sports']]));
}

public function testFlippedSingleToMultiFiltersAcrossVersions(): void
{
    // A post published when `category` was single-valued (fields.category = "newsUuid" scalar),
    // and another published after the flip (fields.category = ["newsUuid"]). Both must match eq=news.
    self::assertEqualsCanonicalizing(['old','new'], $this->deliverFilter('post', ['category' => ['eq' => 'news']]));
}

public function testAbsentFieldNoMatchAndAmbiguousSlug422(): void
{
    // a post with no category → not returned; two published terms sharing slug 'dup' → eq=dup raises InvalidFilterException
}

public function testAssetUuidFilter(): void
{
    // a `gallery` multiple filterable asset field; filter eq=<blobUuid> returns the posts containing it
}
```

Implement a `deliverFilter(string $type, array $filter): array` helper that drives the controller/repo path and returns the matched entry uuids. Reconcile all seed column lists with the migrations (`entries`, `entry_versions`, `entry_publications`, `content_types`).

- [ ] **Step 2: Run it — expect FAIL first, then implement seeding until PASS.** (No production code should be needed if Phases A–B are correct; failures indicate a real bug to fix in the prior tasks.)

Run: `vendor/bin/phpunit --filter ReferenceDeliveryFilterTest`

- [ ] **Step 3: Run the broader delivery suite to confirm no regressions.**

Run: `vendor/bin/phpunit --testsuite Integration --filter Delivery`

- [ ] **Step 4: Commit.**

```bash
git add tests/Integration/Content/Delivery/ReferenceDeliveryFilterTest.php
git commit -m "Integration-test reference delivery filtering (uuid/slug/in/flip/asset)"
```

---

## Phase C — Admin & OpenAPI

### Task 10: Schema attributes through the API contract & FE types

**Files:**
- Modify: `app/Content/Http/DTOs/FieldDefinitionData.php` (request DTO)
- Modify: `app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php` (response DTO)
- Test: `tests/Integration/Content/MultiValueSchemaPersistenceTest.php`
- Modify: `admin/src/queries/contentTypes.ts`
- Modify: `admin/src/fields/types.ts`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue`
- Regenerate: `docs/openapi.json`, `admin/src/api/schema.d.ts`

- [ ] **Step 1: Add attributes to the response DTO.** In `app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php`, add after `$reference_type`:

```php
        public readonly ?string $reference_type = null,
        /** Whether a reference/asset field holds an ordered array of uuids. */
        public readonly ?bool $multiple = null,
        /** Max array length for a multiple field; null = unbounded. */
        public readonly ?int $max_items = null,
        /** Target field used to resolve slug filter values for a reference field (default `slug`). */
        public readonly ?string $reference_slug_field = null,
    ) {
    }
```

> Find where `FieldSchemaData` is constructed from a `FieldDefinition` (grep `new FieldSchemaData`) and pass the new values (`multiple: $f->multiple ?: null, max_items: $f->maxItems, reference_slug_field: $f->type === 'reference' ? $f->referenceSlugField : null`).

- [ ] **Step 1b: Add attributes to the REQUEST DTO (`FieldDefinitionData`).** Create/update content-type schema flows through `FieldDefinitionData::toArray()` (`ContentTypeController::store` line ~100 / `updateSchema` line ~160). It currently has no `multiple`/`max_items`/`reference_slug_field`, so `toArray()` would **discard** them and the builder could never persist them. In `app/Content/Http/DTOs/FieldDefinitionData.php`, add constructor params after `$reference_type`:

```php
        #[Rule('string')]
        public readonly ?string $reference_type = null,
        #[Rule('boolean')]
        public readonly ?bool $multiple = null,
        #[Rule('integer')]
        public readonly ?int $max_items = null,
        #[Rule('string')]
        public readonly ?string $reference_slug_field = null,
    ) {
    }
```

and add them to `toArray()` (so `FieldDefinition::fromArray()` sees them — semantic validation stays there):

```php
            'reference_type' => $this->reference_type,
            'multiple' => $this->multiple ?? false,
            'max_items' => $this->max_items,
            'reference_slug_field' => $this->reference_slug_field,
        ];
```

> Confirm the `#[Rule(...)]` token for integers used elsewhere in the codebase (`grep -rn "#\[Rule('int" app`); if it is `'int'` rather than `'integer'`, use that.

- [ ] **Step 1c: Write the create/update persistence test.** `tests/Integration/Content/MultiValueSchemaPersistenceTest.php` (mirror an existing content-type create/update integration test — `grep -rln "ContentTypeController\|ContentTypeRepository" tests`). Create a content type whose schema has a `multiple` filterable `reference` field with `max_items` + `reference_slug_field`; reload it via the repository; assert `schema` round-trips all three attributes (and that a single-valued reference is unaffected). Run it — expect FAIL before Step 1b, PASS after.

- [ ] **Step 2: Regenerate spec + FE types.**

Run: `composer run docs:openapi && (cd admin && pnpm run gen:api) && grep -c '"reference_slug_field"' docs/openapi.json`
Expected: ≥1.

- [ ] **Step 3: Extend the FE backend type + normalizer.** In `admin/src/queries/contentTypes.ts`, add to `ContentTypeField`:

```ts
  reference_type?: string | null
  /** ordered-array reference/asset. */
  multiple?: boolean
  max_items?: number | null
  reference_slug_field?: string | null
```

and in `normalizeField`, after the `format` line, carry them through:

```ts
    multiple: f.multiple ?? false,
    max_items: f.max_items ?? null,
    reference_slug_field: f.reference_slug_field ?? undefined,
```

- [ ] **Step 4: Extend `FieldDef`.** In `admin/src/fields/types.ts`, add to the interface:

```ts
  referenceType?: string
  /** Ordered-array reference/asset field. */
  multiple?: boolean
  /** Max items for a multiple field. */
  maxItems?: number
  /** Target field used to resolve reference slug filters (default `slug`). */
  referenceSlugField?: string
```

- [ ] **Step 5: Thread through the entry-editor schema map.** In `admin/src/pages/content/[type]/[uuid]/index.vue`, add to the `schema` computed's per-field object:

```ts
    referenceType: f.reference_type ?? undefined,
    multiple: f.multiple ?? undefined,
    maxItems: f.max_items ?? undefined,
    referenceSlugField: f.reference_slug_field ?? undefined,
```

- [ ] **Step 6: Verify + commit.** Run the persistence test (Step 1c) and the FE gate.

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit --filter MultiValueSchemaPersistenceTest && vendor/bin/phpcs app/Content/Http/DTOs/FieldDefinitionData.php && (cd admin && pnpm run type-check; echo "EXIT=$?")` → test green, EXIT=0; then `cd admin && pnpm run lint && pnpm run format`.

```bash
git add app/Content/Http/DTOs/FieldDefinitionData.php app/Content/Http/DTOs/Responses/ContentTypes/FieldSchemaData.php tests/Integration/Content/MultiValueSchemaPersistenceTest.php docs/openapi.json "admin/src/queries/contentTypes.ts" "admin/src/fields/types.ts" "admin/src/pages/content/[type]/[uuid]/index.vue" admin/src/api/schema.d.ts
git commit -m "Carry multiple/max_items/reference_slug_field through request+response DTOs and FE types"
```

---

### Task 11: Content-type builder controls

**Files:**
- Modify: `admin/src/components/ContentTypeFields.vue`
- Modify: `admin/src/components/ContentTypePreview.vue`

- [ ] **Step 1: Add builder controls.** In `admin/src/components/ContentTypeFields.vue`, after the existing `reference` `UFormField` (the "References" target select), add controls for `reference`/`asset` fields. Insert:

```vue
        <UFormField
          v-if="field.type === 'reference' || field.type === 'asset'"
          label="Multiple"
          hint="Store an ordered list of targets"
        >
          <USwitch
            :model-value="field.multiple ?? false"
            @update:model-value="patch(index, { multiple: $event })"
          />
        </UFormField>

        <UFormField
          v-if="(field.type === 'reference' || field.type === 'asset') && field.multiple"
          label="Max items"
          hint="Leave blank for no limit"
        >
          <UInput
            type="number"
            min="1"
            :model-value="field.max_items ?? undefined"
            @update:model-value="patch(index, { max_items: $event === '' ? null : Number($event) })"
          />
        </UFormField>

        <UFormField
          v-if="field.type === 'reference'"
          label="Slug filter field"
          hint="Target field used to resolve slug filters (default: slug)"
        >
          <UInput
            :model-value="field.reference_slug_field ?? 'slug'"
            @update:model-value="patch(index, { reference_slug_field: String($event) || 'slug' })"
          />
        </UFormField>
```

> The existing `filterable` `USwitch` already covers reference/asset (no `filter_type` control is required for them — the backend now accepts filterable ref/asset without one). Confirm the builder does not force a `filter_type` for these types; if it does, gate that control to non-ref/asset types.

- [ ] **Step 2: Clear the attrs on type change.** In `onTypeChange`, extend the patch so switching away from reference/asset clears the new attrs:

```ts
    ...(type === 'reference' ? {} : { reference_type: undefined, reference_slug_field: undefined }),
    ...(type === 'reference' || type === 'asset' ? {} : { multiple: false, max_items: null }),
```

- [ ] **Step 3: Preview badges.** In `admin/src/components/ContentTypePreview.vue`, in the field badge row, add after the `filterable` badge:

```vue
      <UBadge v-if="field.multiple" color="neutral" variant="outline" size="sm">
        multiple{{ field.max_items ? ` · max ${field.max_items}` : '' }}
      </UBadge>
```

- [ ] **Step 4: Verify + commit.**

Run: `cd admin && pnpm run type-check; echo "EXIT=$?"` → 0; then `pnpm run lint && pnpm run format`.

```bash
git add "admin/src/components/ContentTypeFields.vue" "admin/src/components/ContentTypePreview.vue"
git commit -m "Add multiple/max_items/slug-field controls to the content-type builder"
```

---

### Task 12: Ordered multi-pickers in the entry editor

**Files:**
- Create: `admin/src/fields/components/MultiReferencePicker.vue`
- Create: `admin/src/fields/components/MultiReferencePicker.spec.ts`
- Modify: `admin/src/fields/components/ReferenceField.vue`
- Modify: `admin/src/fields/components/AssetField.vue`

- [ ] **Step 1: Write the failing component test (ordering is the contract).** `admin/src/fields/components/MultiReferencePicker.spec.ts`:

```ts
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

// Mock the entries query so the picker has deterministic options.
vi.mock('@/queries/entries', () => ({
  useEntries: () => ({
    data: ref({
      entries: [
        { uuid: 'a1', display_title: 'Alpha' },
        { uuid: 'b2', display_title: 'Bravo' },
        { uuid: 'c3', display_title: 'Charlie' },
      ],
    }),
  }),
}))

import MultiReferencePicker from './MultiReferencePicker.vue'

describe('MultiReferencePicker', () => {
  it('preserves selection order on add and supports remove', async () => {
    const wrapper = mount(MultiReferencePicker, {
      props: { target: 'tag', modelValue: [] },
    })
    // add in non-alphabetical order: Charlie then Alpha
    ;(wrapper.vm as unknown as { add: (u: string) => void }).add('c3')
    ;(wrapper.vm as unknown as { add: (u: string) => void }).add('a1')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual(['c3', 'a1'])
    ;(wrapper.vm as unknown as { remove: (u: string) => void }).remove('c3')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual(['a1'])
  })

  it('blocks adding past maxItems', () => {
    const wrapper = mount(MultiReferencePicker, {
      props: { target: 'tag', modelValue: ['a1', 'b2'], maxItems: 2 },
    })
    ;(wrapper.vm as unknown as { add: (u: string) => void }).add('c3')
    // at the cap → no new emit beyond mount
    expect(wrapper.emitted('update:modelValue') ?? []).toEqual([])
  })
})
```

- [ ] **Step 2: Run it — expect FAIL** (component missing).

Run: `cd admin && pnpm exec vitest run src/fields/components/MultiReferencePicker.spec.ts`

- [ ] **Step 3: Implement the picker.** `admin/src/fields/components/MultiReferencePicker.vue` — an explicit ordered list (chips) + a search-select to append; selection order is authoritative (never derived from component value order):

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useEntries } from '@/queries/entries'

const props = defineProps<{ target: string; maxItems?: number }>()
const model = defineModel<string[]>({ default: () => [] })

const searchTerm = ref('')
const debounced = refDebounced(searchTerm, 250)
const { data } = useEntries(
  () => props.target,
  () => 1,
  () => 20,
  () => debounced.value || undefined,
)

const titleByUuid = computed<Record<string, string>>(() => {
  const m: Record<string, string> = {}
  for (const e of data.value?.entries ?? []) m[e.uuid] = e.display_title || e.uuid
  return m
})

const atCap = computed(() => props.maxItems != null && model.value.length >= props.maxItems)

// Options exclude already-selected entries.
const items = computed(() =>
  (data.value?.entries ?? [])
    .filter((e) => !model.value.includes(e.uuid))
    .map((e) => ({ label: e.display_title || e.uuid, value: e.uuid })),
)

function add(uuid: string) {
  if (!uuid || model.value.includes(uuid) || atCap.value) return
  model.value = [...model.value, uuid] // append → selection order preserved
}
function remove(uuid: string) {
  model.value = model.value.filter((u) => u !== uuid)
}

defineExpose({ add, remove })
</script>

<template>
  <div class="space-y-2">
    <div v-if="model.length" class="flex flex-wrap gap-1">
      <UBadge v-for="uuid in model" :key="uuid" color="neutral" variant="subtle" class="gap-1">
        {{ titleByUuid[uuid] ?? uuid }}
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          :aria-label="`Remove ${uuid}`"
          @click="remove(uuid)"
        />
      </UBadge>
    </div>
    <USelectMenu
      :items="items"
      value-key="value"
      :disabled="atCap"
      :placeholder="atCap ? `Max ${maxItems} reached` : 'Add an entry…'"
      class="w-full"
      @update:model-value="(v: string) => add(v)"
      @update:search-term="searchTerm = $event"
    />
  </div>
</template>
```

> Verify the Nuxt UI v4 `USelectMenu` emits a single value on `@update:model-value` when not bound with `multiple`; if the chosen value comes wrapped, unwrap it in `add`. The component test pins the `add`/`remove` ordering contract regardless of the select's internals.

- [ ] **Step 4: Branch `ReferenceField.vue` on `multiple`.** Replace its body so a multiple field renders the multi-picker with an array model:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '../types'
import ReferencePicker from './ReferencePicker.vue'
import MultiReferencePicker from './MultiReferencePicker.vue'

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<string | string[]>()
const target = computed(() => props.field.referenceType ?? '')
const multiModel = computed<string[]>({
  get: () => (Array.isArray(model.value) ? model.value : []),
  set: (v) => (model.value = v),
})
const singleModel = computed<string | undefined>({
  get: () => (typeof model.value === 'string' ? model.value : undefined),
  set: (v) => (model.value = v),
})
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <MultiReferencePicker
      v-if="field.multiple && target"
      v-model="multiModel"
      :target="target"
      :max-items="field.maxItems"
    />
    <ReferencePicker v-else-if="target" v-model="singleModel" :target="target" />
    <UInput v-else v-model="singleModel" placeholder="Entry UUID" class="w-full" />
  </UFormField>
</template>
```

- [ ] **Step 5: Asset fields persist blob uuids (single + multi).** The backend validator requires an `asset` value to be an active **blob uuid** on the media disk (`FieldValidator::assetExistsOnMediaDisk`), but the current `AssetField.vue` stores `asset.url` (line 18) — a latent mismatch. Fix both single and multiple to store `blob_uuid` and render via `blobDisplayUrl(uuid)`. Both `blobDisplayUrl` and `UploadedAsset.blob_uuid` already exist in `admin/src/queries/media.ts`.
  - **Single (`!field.multiple`):** on upload set `model.value = asset.blob_uuid` (guard: skip when absent) instead of `asset.url`; render the stored uuid as a thumbnail via `blobDisplayUrl(model)`. This corrects the pre-existing bug.
  - **Multiple (`field.multiple`):** bind `defineModel<string | string[]>()`; on each upload push the new `blob_uuid` (`model.value = [...asArray, blobUuid]`), capped at `field.maxItems`; render the list as removable chips, each showing `blobDisplayUrl(uuid)` as a thumbnail (mirroring the reference chips). Import `blobDisplayUrl` from `@/queries/media`.

> Do not change the upload mutation — only what the field **persists** (the uuid) vs. **renders** (the url via `blobDisplayUrl`).

- [ ] **Step 6: Run the component test — expect PASS; then type-check/lint/format.**

Run: `cd admin && pnpm exec vitest run src/fields/components/MultiReferencePicker.spec.ts && pnpm run type-check; echo "EXIT=$?"` → tests pass, EXIT=0; then `pnpm run lint && pnpm run format`.

- [ ] **Step 7: Commit.**

```bash
git add "admin/src/fields/components/MultiReferencePicker.vue" "admin/src/fields/components/MultiReferencePicker.spec.ts" "admin/src/fields/components/ReferenceField.vue" "admin/src/fields/components/AssetField.vue"
git commit -m "Add ordered multi-reference and multi-asset pickers to the entry editor"
```

---

### Task 13: Import/export round-trip + CHANGELOG

**Files:**
- Test: `tests/Integration/ImportExport/MultiValueReferenceRoundTripTest.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the round-trip test.** `tests/Integration/ImportExport/MultiValueReferenceRoundTripTest.php` — export an entry whose `category` field is a uuid array via `LemmaContentExporter`, re-import via `LemmaContentImporter` (dry-run + commit), and assert the array survives intact. Mirror the harness of the existing import/export integration tests (`grep -rln "LemmaContentExporter\|LemmaContentImporter" tests`).

```php
public function testMultiValueReferenceArraySurvivesExportImport(): void
{
    // seed a post entry with fields.category = ['catA','catB']; export to NDJSON; import into a clean
    // target; assert the imported draft/version fields.category === ['catA','catB'] (order preserved).
}
```

- [ ] **Step 2: Run it — expect PASS** (no production change expected; `fields` JSON round-trips verbatim and `targets()` is tolerant). A failure indicates a real serialization gap to fix.

Run: `vendor/bin/phpunit --filter MultiValueReferenceRoundTripTest`

- [ ] **Step 3: Update the CHANGELOG.** In `CHANGELOG.md` under `## [Unreleased]` → `### Added`, in the relevant content section, add:

```markdown
- Multi-valued + filterable references: `reference`/`asset` fields can be declared `multiple`
  (ordered uuid array, optional `max_items`), and `reference`/`asset` fields can be `filterable`.
  Delivery filters published entries by a reference target via JSONB array containment —
  `?filter[category][eq|in]=<uuid|slug>` — with slug→uuid resolution against the target type
  (`reference_slug_field`, default `slug`), GIN-indexed, and correct across single/multi/flipped
  fields. Admin gains builder controls and ordered multi-pickers. (Unblocks taxonomies + a future
  WordPress categories/tags importer.)
```

- [ ] **Step 4: Full suites green.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && composer test` (backend) and `cd admin && pnpm run type-check && pnpm exec vitest run && pnpm run build` (frontend) — all pass.

- [ ] **Step 5: Commit.**

```bash
git add tests/Integration/ImportExport/MultiValueReferenceRoundTripTest.php CHANGELOG.md
git commit -m "Round-trip multi-valued references through import/export; changelog"
```

---

## Notes / risks (resolve while implementing)

- **Interface boundary (Tasks 5–6):** the plan already resolves the `final`-class test problem — `FilterCompiler` depends on the `ReferenceTargetResolver` interface (which `ReferenceFilterResolver` implements), and the resolver owns the slug→type-uuid lookup, so the unit test fakes only the interface (no `final` subclassing, no `ContentTypeRepository` fake). Verify the container's interface→impl binding pattern when registering (Task 5 Step 4).
- **Existing `FilterCompiler::compile()` callers (Task 7):** the added `$locale` param is a breaking signature change — grep and update every caller (controller + tests).
- **Seed column reconciliation:** every integration test seeds raw rows; reconcile `entry_versions`/`entry_publications`/`content_types` column lists with the migrations before first run (`entry_publications` has no `uuid`; needs `version_uuid`).
- **Request DTO parity (Task 10):** the create/update path goes through `FieldDefinitionData::toArray()` — the new keys must be on the **request** DTO too (Step 1b), not just the response DTO, or the builder can't persist them.
- **Index family change (Task 8):** reconciliation now drops+recreates an index whose `filter_type` family changed (same `index_name`); the integration test (Step 6) guards it.
- **Nuxt UI v4 `USelectMenu` emit shape (Task 12):** confirm single-value emit vs wrapped; the `add`/`remove` ordering contract is pinned by the component test regardless.
- **Asset value semantics (Task 12):** asset fields persist a **blob uuid** (the validator requires it), not the URL — this fixes a pre-existing single-`AssetField` mismatch; render via `blobDisplayUrl(uuid)`.
- **Phasing:** Phase A (1–3) and Phase B (4–9) are backend and independently testable; Phase C (10–13) is admin/contract. If executing in parallel sessions, Phase C Task 10 depends only on Task 1's DTO mapping, not on Phase B.
