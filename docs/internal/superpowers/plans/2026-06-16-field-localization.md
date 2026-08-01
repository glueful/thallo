# Field-Level Localization Automation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Shipped (2026-06-17) — implemented, reviewed, and merged. Steps left as `[ ]` for historical reference.

**Goal:** Make the existing `localized: true` field-schema flag *do* something: when a new locale variant is created from a source locale, seed the new draft's **non-localized** fields from the source and leave the **localized** fields empty for the editor to translate.

**Architecture:** One new pure unit — `LocaleFieldSeeder::seed(array $sourceFields, ContentTypeSchema $schema): array` — partitions source fields by the schema's `localized` flag, copying a non-localized field **iff `array_key_exists($field->name, $sourceFields)`** (key presence, NOT truthiness, so `false`/`0`/`0.0`/`''` survive) and omitting localized fields. The schema is the source of truth for the partition: fields in the source but not in the schema are dropped; fields in the schema but absent from the source are not invented. `EntryRepository::createLocaleDraft` gains a `ContentTypeSchema $schema` parameter and routes the source copy through the seeder; the no-source (empty draft) path is unchanged. `EntryController::createLocaleDraft` passes the already-resolved schema via `types->schemaFor(...)`. No new persisted unit, route, permission, event, DTO, or migration — the only behavioral change is *what gets copied* inside `createLocaleDraft`. Flag changes are prospective only (existing drafts/versions are never re-shaped). `overwrite: true` re-seed intentionally drops the target's localized values (re-seed = fresh translation).

**Tech Stack:** PHP 8.3, PostgreSQL (tests run on Postgres via `LemmaTestCase`), PHPUnit 10, Glueful framework. Conventions: `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col.

**Spec:** `docs/superpowers/specs/2026-06-16-field-localization-design.md`

---

## File map

- Create: `app/Content/Localization/LocaleFieldSeeder.php` — pure seeder; partitions source fields by the schema's `localized` flag (key-presence copy of non-localized fields, localized omitted, schema-driven).
- Modify: `app/Content/Repositories/EntryRepository.php` — `createLocaleDraft` gains a `ContentTypeSchema $schema` param and builds the seed via `LocaleFieldSeeder` when a source locale is present (no-source path unchanged).
- Modify: `app/Content/Http/Controllers/EntryController.php` — pass `$this->types->schemaFor($entry['content_type_uuid'])` into `createLocaleDraft`.
- Create test: `tests/Unit/Content/Localization/LocaleFieldSeederTest.php` — pure unit tests for the seeder (mixed schema, falsy preservation, stale/absent fields, empty source, all-localized, all-shared).
- Modify test: `tests/Integration/Content/EntryRepositoryTest.php` — repository createLocaleDraft seeding (fr from en: price copied, title omitted, source unchanged; overwrite re-seed drops localized; prospective flag change).
- Modify test: `tests/Integration/Http/EntryApiTest.php` — controller wiring (mixed schema fr-from-en) + required-localized-blocks-publish via the existing publish path; no-source unchanged.

All tests run on Postgres via `LemmaTestCase`. Test command: `composer test:phpunit -- --filter <Name>`. The seeder is pure (no DB), but its test still extends `LemmaTestCase` for the shared bootstrap; nothing it asserts touches a table.

---

### Task 1: Pure `LocaleFieldSeeder`

**Files:**
- Create: `app/Content/Localization/LocaleFieldSeeder.php`
- Test: `tests/Unit/Content/Localization/LocaleFieldSeederTest.php`

- [ ] **Step 1: Write the failing unit tests.** Create `tests/Unit/Content/Localization/LocaleFieldSeederTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Localization;

use App\Content\Localization\LocaleFieldSeeder;
use App\Content\Schema\ContentTypeSchema;
use PHPUnit\Framework\TestCase;

// The seeder is pure (no DB/IO), so this is a plain PHPUnit unit test — it must NOT extend
// LemmaTestCase (which boots the framework + needs `composer test:migrate`). Runs standalone.
final class LocaleFieldSeederTest extends TestCase
{
    private function schema(array $fields): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray($fields);
    }

    public function testCopiesNonLocalizedAndOmitsLocalized(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string', 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ]);

        $seed = (new LocaleFieldSeeder())->seed(['title' => 'Hello', 'price' => 42], $schema);

        self::assertSame(['price' => 42], $seed, 'non-localized copied, localized omitted');
        self::assertArrayNotHasKey('title', $seed);
    }

    public function testPreservesFalsyNonLocalizedValuesViaKeyPresence(): void
    {
        $schema = $this->schema([
            ['name' => 'flag', 'type' => 'boolean'],
            ['name' => 'count', 'type' => 'number'],
            ['name' => 'ratio', 'type' => 'number'],
            ['name' => 'note', 'type' => 'string'],
        ]);

        $source = ['flag' => false, 'count' => 0, 'ratio' => 0.0, 'note' => ''];
        $seed = (new LocaleFieldSeeder())->seed($source, $schema);

        // Key-presence copy, NOT truthiness: every falsy value must survive verbatim.
        self::assertArrayHasKey('flag', $seed);
        self::assertArrayHasKey('count', $seed);
        self::assertArrayHasKey('ratio', $seed);
        self::assertArrayHasKey('note', $seed);
        self::assertFalse($seed['flag']);
        self::assertSame(0, $seed['count']);
        self::assertSame(0.0, $seed['ratio']);
        self::assertSame('', $seed['note']);
    }

    public function testDropsSourceFieldThatIsNotInSchema(): void
    {
        $schema = $this->schema([
            ['name' => 'price', 'type' => 'number'],
        ]);

        $seed = (new LocaleFieldSeeder())->seed(['price' => 9, 'stale' => 'gone'], $schema);

        self::assertSame(['price' => 9], $seed, 'a stale key not in the schema is not carried over');
    }

    public function testDoesNotInventSchemaFieldAbsentFromSource(): void
    {
        $schema = $this->schema([
            ['name' => 'price', 'type' => 'number'],
            ['name' => 'weight', 'type' => 'number'],
        ]);

        $seed = (new LocaleFieldSeeder())->seed(['price' => 9], $schema);

        self::assertSame(['price' => 9], $seed, 'a schema field absent from source is not invented');
        self::assertArrayNotHasKey('weight', $seed);
    }

    public function testEmptySourceProducesEmptySeed(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string', 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ]);

        self::assertSame([], (new LocaleFieldSeeder())->seed([], $schema));
    }

    public function testAllLocalizedSchemaCopiesNothing(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string', 'localized' => true],
            ['name' => 'body', 'type' => 'text', 'localized' => true],
        ]);

        self::assertSame([], (new LocaleFieldSeeder())->seed(['title' => 'Hi', 'body' => 'B'], $schema));
    }

    public function testAllSharedSchemaCopiesEverything(): void
    {
        $schema = $this->schema([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'number'],
        ]);

        $source = ['title' => 'Hi', 'price' => 5];
        self::assertSame($source, (new LocaleFieldSeeder())->seed($source, $schema));
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter LocaleFieldSeederTest`
Expected: FAIL — `Class "App\Content\Localization\LocaleFieldSeeder" not found`.

- [ ] **Step 3: Implement the seeder.** Create `app/Content/Localization/LocaleFieldSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Localization;

use App\Content\Schema\ContentTypeSchema;

/**
 * Computes the initial field map for a new locale variant, making the schema's
 * `localized: true` flag do work (V1_DESIGN §3 / field-localization-design spec).
 *
 * For each schema field: copy the source value IFF the field is NOT localized AND the key
 * is present in the source (`array_key_exists`, not truthiness — so a non-localized field
 * set to `false`, `0`, `0.0`, or `''` is copied verbatim, never silently dropped). Localized
 * fields are omitted so the editor authors the translation fresh (and required-localized
 * fields then gate publish). The schema is the source of truth for the partition: a source
 * key absent from the schema is dropped, and a schema field absent from the source is not
 * invented. Pure — no I/O.
 */
final class LocaleFieldSeeder
{
    /**
     * @param array<string,mixed> $sourceFields the source-locale draft's field map
     * @return array<string,mixed> the seed for the new variant (non-localized values only)
     */
    public function seed(array $sourceFields, ContentTypeSchema $schema): array
    {
        $seed = [];
        foreach ($schema->fields() as $field) {
            if ($field->localized) {
                continue;
            }
            if (array_key_exists($field->name, $sourceFields)) {
                $seed[$field->name] = $sourceFields[$field->name];
            }
        }
        return $seed;
    }
}
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter LocaleFieldSeederTest`
Expected: PASS (all 7 cases — mixed, falsy-preserved, stale-dropped, not-invented, empty, all-localized, all-shared).

- [ ] **Step 5: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Localization/LocaleFieldSeeder.php tests/Unit/Content/Localization/LocaleFieldSeederTest.php
git commit -m "Add LocaleFieldSeeder for flag-aware locale field copy"
```

---

### Task 2: `EntryRepository::createLocaleDraft` builds the seed via the schema

**Files:**
- Modify: `app/Content/Repositories/EntryRepository.php`
- Test: `tests/Integration/Content/EntryRepositoryTest.php`

The signature gains a `ContentTypeSchema $schema` parameter **after** `$overwrite` so the controller can pass the resolved schema. Inside, when `$sourceLocale !== null`, the verbatim `$fields = (array) $source['fields'];` copy is replaced by `$this->seeder->seed((array) $source['fields'], $schema)`. The no-source path stays `$fields = []`. `LocaleFieldSeeder` is added as a constructor collaborator (autowired), defaulting to `new LocaleFieldSeeder()` so existing hand-built `EntryRepository` instances in tests keep working without passing it.

- [ ] **Step 1: Write the failing tests.** Add to `tests/Integration/Content/EntryRepositoryTest.php` (it already has `repo()` building an `EntryRepository`). Add these methods, plus a `localizedType()` helper that creates a content type with a localized `title` and a non-localized `price`:

```php
private function localizedType(): array
{
    $types = new ContentTypeRepository($this->connection());
    $uuid = $types->create([
        'slug' => 'product', 'name' => 'Product',
        'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true, 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ],
    ]);
    return [$uuid, $types->schemaFor($uuid)];
}

public function testCreateLocaleDraftSeedsNonLocalizedAndOmitsLocalized(): void
{
    [$typeUuid, $schema] = $this->localizedType();
    $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');
    $this->repo()->saveDraft($entry, 'en', ['title' => 'English', 'price' => 42], 1, 0, 'user00000001');

    $fr = $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', 'en', false, $schema);

    self::assertSame('fr', $fr['locale']);
    self::assertSame(['price' => 42], $fr['fields'], 'price (shared) copied, title (localized) omitted');
    self::assertArrayNotHasKey('title', $fr['fields']);

    // The source draft is unchanged.
    self::assertSame(['title' => 'English', 'price' => 42], $this->repo()->findDraft($entry, 'en')['fields']);
}

public function testCreateLocaleDraftWithoutSourceIsUnchangedEmptyDraft(): void
{
    [$typeUuid, $schema] = $this->localizedType();
    $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');

    $fr = $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', null, false, $schema);

    self::assertSame([], $fr['fields'], 'no source locale → empty draft, no seeding');
    self::assertSame(0, $fr['lock_version']);
}

public function testOverwriteReseedDropsTargetLocalizedAndResetsShared(): void
{
    [$typeUuid, $schema] = $this->localizedType();
    $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');
    $this->repo()->saveDraft($entry, 'en', ['title' => 'English', 'price' => 42], 1, 0, 'user00000001');

    // fr exists with a translated localized title and a diverged shared price.
    $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', 'en', false, $schema);
    $frLock = (int) $this->repo()->findDraft($entry, 'fr')['lock_version'];
    $this->repo()->saveDraft($entry, 'fr', ['title' => 'Bonjour', 'price' => 99], 1, $frLock, 'user00000001');

    // Re-seed from en with overwrite → localized title gone, shared price reset to en's.
    $reseeded = $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', 'en', true, $schema);

    self::assertArrayNotHasKey('title', $reseeded['fields'], 'overwrite re-seed drops the target localized value');
    self::assertSame(42, $reseeded['fields']['price'], 'shared price reset to source value');
}

public function testFlagChangeIsProspectiveOnlyForExistingDrafts(): void
{
    $types = new ContentTypeRepository($this->connection());
    $typeUuid = $types->create([
        'slug' => 'product', 'name' => 'Product',
        'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'price', 'type' => 'number'],
        ],
    ]);
    $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');
    $this->repo()->saveDraft($entry, 'en', ['title' => 'English', 'price' => 42], 1, 0, 'user00000001');

    // Seed fr while title is SHARED → title copied. Pass the CURRENT schema version exactly
    // as the controller does (`$type['schema_version']`), not a hardcoded constant.
    $v1 = (int) $types->findByUuid($typeUuid)['schema_version'];
    $sharedSchema = $types->schemaFor($typeUuid);
    $fr = $this->repo()->createLocaleDraft($entry, 'fr', $v1, 'user00000001', 'en', false, $sharedSchema);
    self::assertSame(['title' => 'English', 'price' => 42], $fr['fields']);
    self::assertSame($v1, (int) $fr['schema_version'], 'fr draft is tagged with the pre-flip schema version');

    // Flip title shared → localized (prospective). Existing en/fr drafts must be byte-unchanged.
    $types->updateSchema($typeUuid, [
        ['name' => 'title', 'type' => 'string', 'required' => true, 'localized' => true],
        ['name' => 'price', 'type' => 'number'],
    ]);
    self::assertSame(['title' => 'English', 'price' => 42], $this->repo()->findDraft($entry, 'en')['fields']);
    self::assertSame(['title' => 'English', 'price' => 42], $this->repo()->findDraft($entry, 'fr')['fields']);

    // Only the NEXT create reflects the new partition: de from en omits the now-localized
    // title AND carries the UPDATED schema version (the controller always passes the current
    // one). Asserting the version guards against green-lighting a stale tag on new drafts.
    $v2 = (int) $types->findByUuid($typeUuid)['schema_version'];
    self::assertGreaterThan($v1, $v2, 'updateSchema bumped the content type schema version');
    $newSchema = $types->schemaFor($typeUuid);
    $de = $this->repo()->createLocaleDraft($entry, 'de', $v2, 'user00000001', 'en', false, $newSchema);
    self::assertSame(['price' => 42], $de['fields'], 'next create reflects the prospective flag change');
    self::assertSame($v2, (int) $de['schema_version'], 'new de draft carries the UPDATED schema version, not a stale 1');
}
```
Add `use App\Content\Repositories\ContentTypeRepository;` if not already imported (the file imports it). The `repo()` helper already exists in this file.

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter EntryRepositoryTest`
Expected: FAIL — `createLocaleDraft()` does not accept a 7th `ContentTypeSchema` argument (`ArgumentCountError`/too many arguments) or the seeding assertions fail (`title` still copied).

- [ ] **Step 3: Implement.** In `app/Content/Repositories/EntryRepository.php`:

Add the import (alongside the existing `use App\Content\Schema\ContentTypeSchema;`):
```php
use App\Content\Localization\LocaleFieldSeeder;
```

Add the collaborator to the constructor (defaulted so existing 3/4-arg instantiations keep working):
```php
    public function __construct(
        private readonly Connection $db,
        private readonly ApplicationContext $context,
        private readonly ContentTypeRepository $types,
        private readonly ?PublishEventEmitter $events = null,
        private readonly LocaleFieldSeeder $seeder = new LocaleFieldSeeder(),
    ) {
    }
```

Change `createLocaleDraft`'s signature to take the schema and route the source copy through the seeder:
```php
    public function createLocaleDraft(
        string $entryUuid,
        string $locale,
        int $schemaVersion,
        ?string $actor,
        ?string $sourceLocale = null,
        bool $overwrite = false,
        ?ContentTypeSchema $schema = null,
    ): array {
        $existing = $this->findDraft($entryUuid, $locale);
        if ($existing !== null && !$overwrite) {
            throw new \RuntimeException('Draft already exists for locale.');
        }

        $fields = [];
        if ($sourceLocale !== null) {
            $source = $this->findDraft($entryUuid, $sourceLocale);
            if ($source === null) {
                throw new \InvalidArgumentException('Source draft not found.');
            }
            // Flag-aware seed: copy non-localized field values, omit localized ones. The
            // schema is the partition's source of truth; when absent (legacy callers),
            // fall back to the prior verbatim copy so behavior is unchanged.
            $fields = $schema === null
                ? (array) $source['fields']
                : $this->seeder->seed((array) $source['fields'], $schema);
        }

        $data = [
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'lock_version' => 0,
            'updated_by' => $actor,
            'updated_at' => $this->now(),
        ];

        if ($existing === null) {
            $this->db->table('entry_drafts')->insert($data);
        } else {
            $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entryUuid)
                ->where('locale', '=', $locale)
                ->update($data);
        }

        return $this->findDraft($entryUuid, $locale) ?? [];
    }
```
The `$schema === null` fallback keeps the verbatim copy for any caller that has not yet been updated, so this change is non-breaking; the controller (Task 3) always passes the schema, making the feature live in production.

- [ ] **Step 4: Run; verify pass.**

Run: `composer test:phpunit -- --filter EntryRepositoryTest`
Expected: PASS (seeding, no-source-unchanged, overwrite re-seed drops localized, prospective flag change) and the pre-existing `EntryRepositoryTest` methods still pass.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Repositories/EntryRepository.php tests/Integration/Content/EntryRepositoryTest.php
git commit -m "Seed locale drafts through the flag-aware LocaleFieldSeeder"
```

---

### Task 3: Wire the schema through `EntryController::createLocaleDraft` + publish gating

**Files:**
- Modify: `app/Content/Http/Controllers/EntryController.php`
- Test: `tests/Integration/Http/EntryApiTest.php`

The controller already resolves `$type` for the entry; it now also resolves the schema via `$this->types->schemaFor((string) $entry['content_type_uuid'])` and passes it as the `$schema` argument. No DTO or route change (`CopyLocaleData` stays `source_locale` + `overwrite`).

- [ ] **Step 1: Write the failing tests.** Add to `tests/Integration/Http/EntryApiTest.php`. The existing `setUp()` creates an all-shared `post` type (`title` string, no localized) — its `testLocaleDraftCanBeCopiedFromSourceLocale` already asserts the all-shared full-copy behavior, which must keep passing (proves the feature is a strict superset). Add a localized-type helper + new cases:

```php
private function createLocalizedEntryUuid(): string
{
    $typeUuid = (new ContentTypeRepository($this->connection()))->create([
        'slug' => 'product', 'name' => 'Product',
        'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true, 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ],
    ]);
    $resp = $this->controller()->store(
        $this->hydrate(CreateEntryData::class, ['content_type' => 'product', 'locale' => 'en']),
        new Request(),
    );
    return json_decode((string) $resp->getContent(), true)['data']['entry']['uuid'];
}

public function testLocaleDraftCopySeedsNonLocalizedAndOmitsLocalized(): void
{
    $uuid = $this->createLocalizedEntryUuid();
    $this->controller(new FakeLocaleManager())->saveDraft(
        $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'English', 'price' => 42], 'lock_version' => 0]),
        new Request(),
        $uuid,
        'en',
    );

    $resp = $this->controller(new FakeLocaleManager())->createLocaleDraft(
        $this->hydrate(CopyLocaleData::class, ['source_locale' => 'en']),
        new Request(),
        $uuid,
        'fr',
    );

    self::assertSame(201, $resp->getStatusCode());
    $draft = json_decode((string) $resp->getContent(), true)['data']['draft'];
    self::assertSame('fr', $draft['locale']);
    self::assertSame(['price' => 42], $draft['fields'], 'shared price copied, localized title omitted');
    self::assertArrayNotHasKey('title', $draft['fields']);
}

public function testRequiredLocalizedFieldLeftEmptyBlocksPublish(): void
{
    $uuid = $this->createLocalizedEntryUuid();
    // Save the en source draft (title is required+localized; price is non-localized).
    $this->controller(new FakeLocaleManager())->saveDraft(
        $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'English', 'price' => 42], 'lock_version' => 0]),
        new Request(),
        $uuid,
        'en',
    );

    // Seed fr from en: fr has price but no (required, localized) title.
    $this->controller(new FakeLocaleManager())->createLocaleDraft(
        $this->hydrate(CopyLocaleData::class, ['source_locale' => 'en']),
        new Request(),
        $uuid,
        'fr',
    );

    // Publishing fr must fail through the REAL publish path: PublishService::publish()
    // validates the draft and THROWS before any write (PublishService.php) — the same path
    // PublicationController::publish() drives and maps to 422. Asserting against the service
    // (not FieldValidator directly) proves the publish path itself rejects the missing
    // required localized field AND that no publication row is created.
    $publisher = $this->container()->get(\App\Content\Services\PublishService::class);

    $errors = [];
    try {
        $publisher->publish($uuid, 'fr', 'user00000001');
        self::fail('publishing a draft missing a required localized field must throw');
    } catch (\App\Content\Validation\ValidationException $e) {
        $errors = $e->errors();
    }
    self::assertArrayHasKey('title', $errors, 'required localized field left empty blocks publish for the locale');

    // The failed publish wrote nothing — no pinned version for fr.
    self::assertNull(
        (new \App\Content\Repositories\VersionRepository($this->connection()))->findPublication($uuid, 'fr'),
        'a validation-failed publish creates no publication row'
    );
}
```
The `controllerEntryRepo()` helper already exists at the bottom of `EntryApiTest`. `FieldValidator`, `EntryRepository`, `ContentTypeRepository`, `CopyLocaleData`, `SaveDraftData`, `CreateEntryData`, and `FakeLocaleManager` are already imported in `EntryApiTest`.

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter EntryApiTest`
Expected: FAIL — `testLocaleDraftCopySeedsNonLocalizedAndOmitsLocalized` still sees `title` in the copied `fr` draft (controller does not yet pass the schema), so the `assertArrayNotHasKey('title', ...)` fails.

- [ ] **Step 3: Implement the controller wiring.** In `app/Content/Http/Controllers/EntryController.php`, the `createLocaleDraft` method already resolves `$type`; resolve the schema and pass it as the new `$schema` argument:

```php
        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($type === null) {
            return Response::validation(['content_type' => 'unknown content type']);
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);

        try {
            $draft = $this->entries->createLocaleDraft(
                $uuid,
                $locale,
                (int) $type['schema_version'],
                $this->actor($request),
                $input->source_locale,
                $input->overwrite,
                $schema,
            );
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['source_locale' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, ['code' => 'DRAFT_EXISTS']);
        }
```
Only the added `$schema` resolution line and the extra `$schema` argument change; the rest of the method (locale validation, entry/type resolution, error mapping, `Response::created`) is unchanged.

- [ ] **Step 4: Run; verify pass.**

Run: `composer test:phpunit -- --filter EntryApiTest`
Expected: PASS — the new mixed-schema copy + required-localized-blocks-publish cases pass, and the pre-existing all-shared `testLocaleDraftCanBeCopiedFromSourceLocale` (full copy) and `testEntryLocalesSummarizeDraftsPublicationsAndRoutes` still pass (no-source and all-shared paths unchanged).

- [ ] **Step 5: Full suite + phpcs.**

Run: `composer test:phpunit` then `composer phpcs`
Expected: green (prior total + the new seeder/repository/controller tests), phpcs clean.

- [ ] **Step 6: Commit.**
```bash
composer phpcs
git add app/Content/Http/Controllers/EntryController.php tests/Integration/Http/EntryApiTest.php
git commit -m "Wire content type schema into createLocaleDraft for flag-aware copy"
```

---

## Self-review notes

- **Spec coverage (every scope + resolved decision + P2/P3 fix):**
  - Scope 1 (flag-aware copy-on-create is the whole feature) → Task 1 seeder + Task 2 repo wiring.
  - Scope 2 / Resolved 1 (localized fields start empty/omitted; required gates publish) → seeder omits localized; `testRequiredLocalizedFieldLeftEmptyBlocksPublish` (Task 3) asserts the publish-validation gate.
  - Scope 3 (schema is the partition's source of truth) → seeder iterates `ContentTypeSchema::fields()`; `testDropsSourceFieldThatIsNotInSchema` + `testDoesNotInventSchemaFieldAbsentFromSource` (Task 1).
  - Scope 4 (no change to the persisted unit) → still one `entry_drafts.fields` blob; no migration/table in the file map.
  - Scope 5 ("shared" is a seed, not enforced) → only copy-on-create changes; no copy-on-change anywhere (out of scope).
  - Scope 6 (no new permissions/routes/events/DTO) → none added; reuses `POST …/locales/{locale}` and `CopyLocaleData`.
  - Scope 7 / P2 (flag changes prospective only) → `testFlagChangeIsProspectiveOnlyForExistingDrafts` (Task 2): existing en/fr byte-unchanged after `updateSchema`; only the next create reflects the flip.
  - **P2 falsy preservation via `array_key_exists`** → `testPreservesFalsyNonLocalizedValuesViaKeyPresence` (Task 1) asserts `false`/`0`/`0.0`/`''` survive verbatim; seeder uses `array_key_exists`, never truthiness.
  - **P3 overwrite re-seed drops target localized** → `testOverwriteReseedDropsTargetLocalizedAndResetsShared` (Task 2).
  - all-localized copies nothing → `testAllLocalizedSchemaCopiesNothing` (Task 1).
  - all-shared copies everything → `testAllSharedSchemaCopiesEverything` (Task 1) + existing `testLocaleDraftCanBeCopiedFromSourceLocale` (Task 3 keeps it green).
  - no-source unchanged → `testCreateLocaleDraftWithoutSourceIsUnchangedEmptyDraft` (Task 2).
  - createLocaleDraft integration (fr from en: price copied, title omitted, source unchanged) → `testCreateLocaleDraftSeedsNonLocalizedAndOmitsLocalized` (Task 2).
- **Signature consistency:** `LocaleFieldSeeder::seed(array $sourceFields, ContentTypeSchema $schema): array` is identical in the implementation, every Task-1 call, and the repo call. `EntryRepository::createLocaleDraft(string, string, int, ?string, ?string $sourceLocale = null, bool $overwrite = false, ?ContentTypeSchema $schema = null)` — the new `$schema` is last and nullable; every test/controller call passes positional args in that order. `ContentTypeSchema::fromArray()` / `schemaFor()` / `FieldDefinition::$localized` match the real code read in `app/Content/Schema/*` and `ContentTypeRepository.php`. `FieldValidator::validate(ContentTypeSchema, array): array` matches `app/Content/Validation/FieldValidator.php`.
- **Placeholder scan:** clean — `testRequiredLocalizedFieldLeftEmptyBlocksPublish` saves the `en` source draft (locale `'en'`) then copies to `fr`; no stray/placeholder lines, no TBD/"similar to Task N"/elided code anywhere. All code blocks are complete and runnable.
- **Backward compatibility:** the `?ContentTypeSchema $schema = null` default with the `$schema === null` verbatim fallback means any not-yet-updated caller (and the hand-built `EntryRepository` instances in other tests) keep prior behavior; only the controller (always passing the schema) makes the feature live.
- **Test layering:** the seeder unit test extends plain `PHPUnit\Framework\TestCase` (pure, no DB — runs without `composer test:migrate`, matching `ContentTypeSchemaTest` et al.); the integration tests (`EntryRepositoryTest`, `EntryApiTest`) extend `LemmaTestCase` on Postgres and exercise the real `entry_drafts` blob + the real `PublishService::publish` path.
