# Term Archives + Facet Counts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The taxonomy read surface — facet counts (`GET /v1/content/{type}/facets`) and term-archive pages (`GET /v1/content/{type}/archive/{field}/{term}`) over a new published-reference projection.

**Architecture:** A new `published_entry_references` table is maintained by a pipeline listener on the existing after-commit entry events (publish rebuilds per entry+locale, unpublish/delete clear) and re-driven by `lemma:resync` — it is the SINGLE source of "published source references published term": facets aggregate it (`COUNT(DISTINCT source)` grouped by target, joined to the target's publication at read time), and the archive endpoint pins membership to an `EXISTS` over it while delegating pagination/sort/shaping/ETag to the existing delivery machinery. A small trait extraction (`HandlesDeliveryReads`) shares `DeliveryController`'s request helpers with the new `TaxonomyController` instead of duplicating them.

**Tech Stack:** PHP 8.3, core Lemma app (`app/Content` — NOT a pack), Glueful QueryBuilder over PostgreSQL JSONB, `RequestData` DTOs, PHPUnit integration tests.

**Spec:** `docs/superpowers/specs/2026-07-02-term-archives-facets-design.md`

## Global Constraints

- Core delivery placement (`app/Content`), no admin/SPA UI — delivery-side only.
- **The projection is the single source of "published source references published term"** (spec §1/§3 pin): facets aggregate it; archive membership is an `EXISTS` over it — NEVER `filter[field][eq]` recompiled through JSONB containment.
- Projection rows: ALL `reference` fields (never `asset`), regardless of `filterable`; endpoints gate on `filterable: true` + `type === 'reference'` at read time. Scalar→array read tolerance via `ReferenceProjectionRepository::targets()`.
- **Delete hygiene is NOT the liveness mechanism**: both read queries join the TARGET's publication (`entry_publications` + `entries.status='active'`) in the request locale at read time — an unpublished term must drop out while its projection rows still exist.
- Maintenance = pipeline listener (idempotent delete-then-insert), NOT a DB trigger; re-driven by `lemma:resync` (direct listener invocation — see `ResyncCommand::reDrive`). Backfill story = run resync once.
- **Visibility fail-closed** (spec §2/§4): SOURCE type gated by the existing `lemma_delivery_access` route middleware (both new routes carry it; kernel tests prove the wiring since controller-direct tests bypass middleware); every referenced TARGET type checked explicitly in the controller via `DeliveryVisibility::isAccessible` — any failure → 404, no partial per-field responses. Facets enumeration is a disclosure surface; "only uuid+slug+count" does not exempt it.
- Envelopes: facets = standard `Response::success()` (`data.{field}` arrays of `{uuid, slug, count}`); archive mirrors `DeliveryListQuery` pagination exactly — default cursor mode `data: {term, items, next_cursor}`, explicit `?page`/`?perPage` → flattened paginated envelope + additive top-level `term`. Param is `perPage`, never `per_page`.
- Facets ordering `count DESC, slug ASC`; `limit` default 100, hard max 500 (per field).
- Term resolution: uuid-first then `referenceSlugField`, via the container `ReferenceTargetResolver` (published-scoped, ambiguity throws) — identical to filter-value precedence. Unknown/unpublished term → 404; known term, zero members → 200 empty.
- Cache: emit existing surrogate tags (`lemma:type:{source}`, `lemma:type:{termType}`, archive also entry tags) via `DeliveryEtag::applyHeaders` — ZERO new purge code.
- Slug-field SQL expressions: interpolated only after re-asserting `\A[a-z][a-z0-9_]*\z` (the `ReferenceFilterResolver` rule); all VALUES are bound placeholders.
- Commits: NO Claude/Anthropic attribution trailers. Batch at the 3 groupings marked below. Never stage `CLAUDE.md`.
- PHPCS: PSR-12, max line 120. Tests namespace `App\Tests\...` may use `App\` classes freely.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `database/migrations/016_CreatePublishedEntryReferencesTable.php` | Create | the projection table |
| `app/Content/Repositories/PublishedReferenceRepository.php` | Create | projection writes (rebuild/clear) + reads (facet counts, membership predicate) |
| `app/Content/Pipeline/Listeners/ProjectPublishedReferencesListener.php` | Create | publish/unpublish/delete → projection maintenance |
| `app/Content/Console/ResyncCommand.php` | Modify | re-drive the projection listener (always) |
| `app/Providers/LemmaServiceProvider.php` | Modify | service defs + listener map entries + controller |
| `app/Content/Http/Controllers/Concerns/HandlesDeliveryReads.php` | Create | request helpers extracted from DeliveryController (pure move) |
| `app/Content/Http/Controllers/DeliveryController.php` | Modify | use the trait; delete the moved privates |
| `app/Content/Http/DTOs/Requests/Delivery/DeliveryFacetsQuery.php` | Create | facets query DTO |
| `app/Content/Http/Controllers/TaxonomyController.php` | Create | `facets()` + `archive()` |
| `routes/lemma_content.php` | Modify | facets route (BEFORE show) + archive route |
| `tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php` | Create | projection lifecycle + listener + resync |
| `tests/Integration/Content/Delivery/TermFacetsArchiveTest.php` | Create | both endpoints, visibility, envelopes, routing |
| `CHANGELOG.md` | Modify | `[Unreleased]` entry |

Codebase facts the implementer needs:
- Tests: `composer test:reset-db && composer test:migrate` once, then `vendor/bin/phpunit <file>`. Tests run on PostgreSQL (`lemma_test`). `LemmaTestCase` gives `connection()`, `container()`, `appContext()`, `handle(Request)`.
- Copy seeding patterns from `tests/Integration/Content/Delivery/ReferenceDeliveryFilterTest.php` — it already seeds a `category` type (direct insert, `public_delivery=false`, schema `[{name: slug, type: string}]`) and a `post` type with a `multiple`+`filterable` reference field `category` (`reference_type: 'category'`, `reference_slug_field: 'slug'`), plus `seedCategory()`/`seedPost()`/`publishPost()` helpers and `RequestDataHydrator` DTO hydration.
- `ContentTypeRepository::findBySlug/findByUuid` return rows whose `schema` is already a decoded array; `ContentTypeSchema::fromArray($typeRow['schema'])`.
- `FieldDefinition` (implements the contracts `FieldDescriptor`) exposes public readonly props `name`, `type`, `filterable`, `referenceType`, `referenceSlugField`.
- `DeliveryRepository::listPublished/paginatePublished` accept a compiled `?array{sql,bindings}` filter applied against a query whose spine aliases are `p` (entry_publications), `v` (entry_versions), `e` (entries) — the membership EXISTS references `p.entry_uuid`/`p.locale` and rides the same filter slot.
- Events: `App\Content\Events\{EntryPublished, EntryUnpublished, EntryDeleted}` extend `BaseEntryEvent` (`__construct(string $entry, string $type, ?string $locale = null, ?int $version = null, ?string $actor = null)`); the app listener map lives in `LemmaServiceProvider` (search `EntryPublished::class => [`); listeners are invoked in array order.
- `new Response($array)` passes the array through as the whole JSON body (that is how `Response::paginated` builds its flattened envelope).

---

### Task 1: Migration + `PublishedReferenceRepository` (write side)

**Files:**
- Create: `database/migrations/016_CreatePublishedEntryReferencesTable.php`
- Create: `app/Content/Repositories/PublishedReferenceRepository.php`
- Test: `tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php`

**Interfaces:**
- Produces: `App\Content\Repositories\PublishedReferenceRepository` —
  `__construct(Connection $db, ContentTypeRepository $types, SchemaProjector $schemaProjector)`;
  `projectFromPublished(string $entryUuid, string $typeUuid, string $locale): void`;
  `clearForEntryLocale(string $entryUuid, string $locale): void`;
  `clearForEntry(string $entryUuid): void`;
  `clearForTarget(string $targetEntryUuid): void`;
  (read methods are added in Task 4). Table `published_entry_references` with columns
  `source_entry_uuid, source_content_type_uuid, field, target_entry_uuid, locale`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/016_CreatePublishedEntryReferencesTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * The PUBLISHED-reference projection (term-archives/facets spec §1) — one row per
 * (source entry, locale, field, target) where the SOURCE side is published. Maintained
 * by ProjectPublishedReferencesListener on publish/unpublish/delete; re-driven by
 * `lemma:resync`. Distinct from entry_references (draft-based admin reverse index).
 *
 * Target liveness is NOT tracked here — facet/archive queries join the target's
 * publication at read time, because a term can be unpublished without being deleted.
 */
final class CreatePublishedEntryReferencesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('published_entry_references', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('source_entry_uuid', 12);
            $table->string('source_content_type_uuid', 12);
            $table->string('field', 160);
            $table->string('target_entry_uuid', 12);
            $table->string('locale', 16);
            $table->unique(
                ['source_entry_uuid', 'locale', 'field', 'target_entry_uuid'],
                'uniq_pubref_source_locale_field_target'
            );
            // Facet aggregation + archive membership probe.
            $table->index(
                ['source_content_type_uuid', 'field', 'locale', 'target_entry_uuid'],
                'idx_pubref_type_field_locale_target'
            );
            $table->index('target_entry_uuid');  // hygiene deletes on term delete
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('published_entry_references');
    }

    public function getDescription(): string
    {
        return 'Create published_entry_references (published-reference projection for term archives/facets).';
    }
}
```

- [ ] **Step 2: Migrate the test DB**

```bash
composer test:reset-db && composer test:migrate
```

Expected: migration list includes `016_CreatePublishedEntryReferencesTable`.

- [ ] **Step 3: Write the failing repository tests**

Create `tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Schema\Migration\SchemaProjector;
use App\Tests\Support\LemmaTestCase;

/**
 * The published-reference projection (term-archives/facets spec §1): write-side rebuild
 * semantics (incl. schema-migration projection for rolled-back versions), listener
 * wiring through real events, and the lemma:resync re-drive.
 */
final class PublishedReferenceProjectionTest extends LemmaTestCase
{
    private const CAT_TYPE_UUID = 'cattypeproj0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID,
            'slug' => 'category',
            'name' => 'Category',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode(
                [['name' => 'slug', 'type' => 'string', 'required' => true]],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                [
                    'name' => 'category',
                    'type' => 'reference',
                    'reference_type' => 'category',
                    'reference_slug_field' => 'slug',
                    'multiple' => true,
                    'filterable' => true,
                ],
                ['name' => 'gallery', 'type' => 'asset', 'multiple' => true],
            ],
        ]);
    }

    private function projection(): PublishedReferenceRepository
    {
        return new PublishedReferenceRepository(
            $this->connection(),
            new ContentTypeRepository($this->connection()),
            $this->container()->get(SchemaProjector::class),
        );
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->connection()->table('published_entry_references')
            ->select(['source_entry_uuid', 'source_content_type_uuid', 'field', 'target_entry_uuid', 'locale'])
            ->orderBy(['id' => 'ASC'])
            ->get();
    }

    /** Seed a published post row directly (spine tables), like ReferenceDeliveryFilterTest. */
    private function seedPublishedPost(string $entryUuid, string $versionUuid, array $rawFields, string $locale = 'en'): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid,
            'content_type_uuid' => $this->postType,
            'status' => 'active',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version' => 1,
            'fields' => json_encode($rawFields, JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    public function testProjectFromPublishedWritesReferenceRowsOnly(): void
    {
        $this->seedPublishedPost('projpost0001', 'projpostv001', [
            'title' => 'P',
            'category' => ['catterm00001', 'catterm00002'],
            'gallery' => ['blob00000001'],  // asset: never projected
        ]);

        $this->projection()->projectFromPublished('projpost0001', $this->postType, 'en');

        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame('category', $rows[0]['field']);
        self::assertSame($this->postType, $rows[0]['source_content_type_uuid']);
        self::assertSame(
            ['catterm00001', 'catterm00002'],
            array_column($rows, 'target_entry_uuid'),
        );
    }

    public function testScalarStoredValueProjectsAsOneElement(): void
    {
        $this->seedPublishedPost('projscalar01', 'projscalarv1', [
            'title' => 'S',
            'category' => 'catterm00001', // pre-flip scalar
        ]);
        $this->projection()->projectFromPublished('projscalar01', $this->postType, 'en');
        self::assertCount(1, $this->rows());
    }

    public function testReprojectIsIdempotentAndDropsRemovedTargets(): void
    {
        $this->seedPublishedPost('projidem0001', 'projidemv001', [
            'title' => 'I', 'category' => ['catterm00001'],
        ]);
        $p = $this->projection();
        $p->projectFromPublished('projidem0001', $this->postType, 'en');
        $p->projectFromPublished('projidem0001', $this->postType, 'en');
        self::assertCount(1, $this->rows()); // no duplicates

        // Simulate a republish with a different target set: version fields changed.
        $this->connection()->table('entry_versions')
            ->where('uuid', '=', 'projidemv001')
            ->update(['fields' => json_encode(['title' => 'I', 'category' => ['catterm00002']], JSON_THROW_ON_ERROR)]);
        $p->projectFromPublished('projidem0001', $this->postType, 'en');
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('catterm00002', $rows[0]['target_entry_uuid']);
    }

    public function testUnpublishedEntryProjectsNothing(): void
    {
        // No publication row at all → projectFromPublished is a no-op (clears any stale rows).
        $this->projection()->projectFromPublished('projnone0001', $this->postType, 'en');
        self::assertSame([], $this->rows());
    }

    public function testOldSchemaVersionFieldsProjectThroughMigrationChain(): void
    {
        // Rollback safety (review P1): a re-pinned older version stores its refs under a
        // field name the CURRENT schema has since renamed. The projection must apply the
        // schema-migration chain before scanning — exactly like delivery shaping does.
        // Old schema (v1) had `topics`; current (v2) renamed it to `category`.
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->postType)
            ->update(['schema_version' => 2]);
        $this->connection()->table('entry_schema_migrations')->insert([
            'uuid' => 'schmig000001',
            'content_type_uuid' => $this->postType,
            'from_version' => 1,
            'to_version' => 2,
            'ops' => json_encode([['op' => 'rename', 'from' => 'topics', 'to' => 'category']], JSON_THROW_ON_ERROR),
            'status' => 'completed',
            'created_at' => '2026-06-01 00:00:00',
        ]);
        // The pinned version predates the rename: schema_version=1, refs under `topics`.
        $this->seedPublishedPost('projdrift001', 'projdriftv01', [
            'title' => 'Old', 'topics' => ['catterm00001'],
        ]);

        $this->projection()->projectFromPublished('projdrift001', $this->postType, 'en');

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('category', $rows[0]['field']); // the CURRENT field name
        self::assertSame('catterm00001', $rows[0]['target_entry_uuid']);
    }

    public function testClearsAreScopedCorrectly(): void
    {
        $this->seedPublishedPost('projclr00001', 'projclrv0001', ['title' => 'A', 'category' => ['catterm00001']], 'en');
        $this->seedPublishedPost('projclr00002', 'projclrv0002', ['title' => 'B', 'category' => ['catterm00001']], 'en');
        $p = $this->projection();
        $p->projectFromPublished('projclr00001', $this->postType, 'en');
        $p->projectFromPublished('projclr00002', $this->postType, 'en');
        self::assertCount(2, $this->rows());

        $p->clearForEntryLocale('projclr00001', 'fr'); // wrong locale: no effect
        self::assertCount(2, $this->rows());
        $p->clearForEntryLocale('projclr00001', 'en');
        self::assertCount(1, $this->rows());
        $p->clearForTarget('catterm00001'); // hygiene: rows pointing AT the term
        self::assertSame([], $this->rows());
    }
}
```

- [ ] **Step 4: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php
```

Expected: ERRORS — class `PublishedReferenceRepository` not found.

- [ ] **Step 5: Implement the repository (write side)**

Create `app/Content/Repositories/PublishedReferenceRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\SchemaProjector;
use Glueful\Database\Connection;

/**
 * The PUBLISHED-reference projection (term-archives/facets spec §1) — the single source
 * of "published source references published term". Rebuilt per (entry, locale) from the
 * PUBLISHED version's reference fields by ProjectPublishedReferencesListener; re-driven
 * by `lemma:resync`. Reference fields only (never asset), regardless of `filterable` —
 * flipping `filterable` later must not require a backfill; endpoints gate at read time.
 *
 * The pinned version's fields are projected FORWARD through SchemaProjector (its
 * schema_version → current) before scanning: a rollback re-pins an older version whose
 * reference fields may since have been renamed/deleted, and the projection must mirror
 * what delivery actually serves (DeliveryItemShaper projects the same way; the rollback
 * path in PublishService does the equivalent for the draft-side projection).
 *
 * Read queries (facetCounts / membershipPredicate) join the TARGET's publication at
 * read time — delete hygiene here is not the liveness mechanism (spec §1: a term can
 * be unpublished without being deleted).
 */
final class PublishedReferenceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly SchemaProjector $schemaProjector,
    ) {
    }

    /**
     * Rebuild the projection rows for one (entry, locale) from its PUBLISHED version:
     * clear that locale's rows, project the pinned fields forward to the current schema,
     * then re-insert. No publication (or no type) → the clear still ran, so stale rows
     * never survive. Idempotent.
     */
    public function projectFromPublished(string $entryUuid, string $typeUuid, string $locale): void
    {
        $this->clearForEntryLocale($entryUuid, $locale);

        $row = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['v.fields', 'v.schema_version'])
            ->where('p.entry_uuid', '=', $entryUuid)
            ->where('p.locale', '=', $locale)
            ->first();
        if ($row === null) {
            return;
        }
        $fields = json_decode((string) $row['fields'], true);
        if (!is_array($fields)) {
            return;
        }
        $typeRow = $this->types->findByUuid($typeUuid);
        if ($typeRow === null) {
            return;
        }
        // Rollback safety: re-pinned older versions carry older schema semantics —
        // apply the migration chain (renames/deletes) so field names match the
        // CURRENT schema the scan below (and the read endpoints) use.
        $fields = $this->schemaProjector->project($typeUuid, (int) ($row['schema_version'] ?? 0), $fields);
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);

        $seen = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'reference') {
                continue; // asset fields are never projected (spec §1)
            }
            foreach (ReferenceProjectionRepository::targets($fields[$f->name] ?? null) as $target) {
                $key = $f->name . '|' . $target;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $this->db->table('published_entry_references')->insert([
                    'source_entry_uuid' => $entryUuid,
                    'source_content_type_uuid' => $typeUuid,
                    'field' => $f->name,
                    'target_entry_uuid' => $target,
                    'locale' => $locale,
                ]);
            }
        }
    }

    public function clearForEntryLocale(string $entryUuid, string $locale): void
    {
        $this->db->table('published_entry_references')
            ->where('source_entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->delete();
    }

    /** Whole-entry delete: drop every locale's rows where the entry is the SOURCE. */
    public function clearForEntry(string $entryUuid): void
    {
        $this->db->table('published_entry_references')
            ->where('source_entry_uuid', '=', $entryUuid)
            ->delete();
    }

    /** Hygiene on term delete: drop rows pointing AT the entry (read-time joins stay the liveness rule). */
    public function clearForTarget(string $targetEntryUuid): void
    {
        $this->db->table('published_entry_references')
            ->where('target_entry_uuid', '=', $targetEntryUuid)
            ->delete();
    }
}
```

- [ ] **Step 6: Run to verify they pass**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php
```

Expected: PASS (5 tests). No commit yet — grouped with Task 2.

---

### Task 2: `ProjectPublishedReferencesListener` + wiring + resync re-drive

**Files:**
- Create: `app/Content/Pipeline/Listeners/ProjectPublishedReferencesListener.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (service def + listener map)
- Modify: `app/Content/Console/ResyncCommand.php` (re-drive, always)
- Test: `tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php`

**Interfaces:**
- Consumes: `PublishedReferenceRepository` (Task 1); `App\Content\Events\{EntryPublished, EntryUnpublished, EntryDeleted}`.
- Produces: `App\Content\Pipeline\Listeners\ProjectPublishedReferencesListener` — `__construct(PublishedReferenceRepository $projection)`, `__invoke(object $event): void`. Wired FIRST in the listener map arrays and FIRST in `ResyncCommand::reDrive`.

- [ ] **Step 1: Write the failing tests**

Add to `PublishedReferenceProjectionTest.php` (imports to add: `use App\Content\Console\ResyncCommand;`, `use App\Content\Events\EntryDeleted;`, `use App\Content\Events\EntryPublished;`, `use App\Content\Events\EntryUnpublished;`, `use Glueful\Events\EventService;`, `use Symfony\Component\Console\Tester\CommandTester;`):

```php
    public function testPublishAndUnpublishEventsMaintainProjectionThroughWiredListeners(): void
    {
        $this->seedPublishedPost('projevt00001', 'projevtv0001', ['title' => 'E', 'category' => ['catterm00001']]);
        $events = $this->container()->get(EventService::class);

        $events->dispatch(new EntryPublished('projevt00001', $this->postType, 'en'));
        self::assertCount(1, $this->rows());

        $events->dispatch(new EntryUnpublished('projevt00001', $this->postType, 'en'));
        self::assertSame([], $this->rows());
    }

    public function testDeleteEventClearsSourceAndTargetRows(): void
    {
        $this->seedPublishedPost('projdelsrc01', 'projdelsrcv1', ['title' => 'D', 'category' => ['projdeltgt01']]);
        $this->projection()->projectFromPublished('projdelsrc01', $this->postType, 'en');
        // A second row where the deleted entry is the TARGET.
        $this->seedPublishedPost('projdeloth01', 'projdelothv1', ['title' => 'O', 'category' => ['projdelsrc01']]);
        $this->projection()->projectFromPublished('projdeloth01', $this->postType, 'en');
        self::assertCount(2, $this->rows());

        $this->container()->get(EventService::class)
            ->dispatch(new EntryDeleted('projdelsrc01', $this->postType, 'en'));

        // Source rows gone AND rows pointing at it gone (hygiene).
        self::assertSame([], $this->rows());
    }

    public function testRollbackRepinRebuildsProjectionFromTheRepinnedVersion(): void
    {
        // Rollback characterization (review P1): PublishService::rollback re-pins an
        // older version and emits EntryPublished — the projection must rebuild from
        // whatever is PINNED, not from the latest version. Simulate the re-pin the way
        // rollback does (publication row swaps its version_uuid), then dispatch the
        // event rollback emits.
        $this->seedPublishedPost('projrbk00001', 'projrbkv0002', ['title' => 'V2', 'category' => ['catterm00002']]);
        // A second version row for the same entry (distinct version number — the helper
        // used 1), referencing a DIFFERENT term. Which row is "older" is irrelevant to
        // the projection; only the publication's pinned version_uuid matters.
        $this->connection()->table('entry_versions')->insert([
            'uuid' => 'projrbkv0001', 'entry_uuid' => 'projrbk00001', 'locale' => 'en', 'version' => 2,
            'fields' => json_encode(['title' => 'V1', 'category' => ['catterm00001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-05-01 00:00:00',
        ]);
        $events = $this->container()->get(EventService::class);
        $events->dispatch(new EntryPublished('projrbk00001', $this->postType, 'en'));
        self::assertSame('catterm00002', $this->rows()[0]['target_entry_uuid']); // v2 pinned

        // The rollback re-pin + the EntryPublished it emits (PublishService.php ~177).
        $this->connection()->table('entry_publications')
            ->where('entry_uuid', '=', 'projrbk00001')->where('locale', '=', 'en')
            ->update(['version_uuid' => 'projrbkv0001']);
        $events->dispatch(new EntryPublished('projrbk00001', $this->postType, 'en'));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('catterm00001', $rows[0]['target_entry_uuid']); // rebuilt from v1
    }

    public function testResyncRedrivesTheProjection(): void
    {
        $this->seedPublishedPost('projsync0001', 'projsyncv001', ['title' => 'R', 'category' => ['catterm00001']]);
        // Simulate the dropped afterCommit: projection is empty despite published content.
        self::assertSame([], $this->rows());

        $tester = new CommandTester(new ResyncCommand($this->container(), $this->appContext()));
        $exit = $tester->execute(['--type' => 'post']);

        self::assertSame(0, $exit);
        self::assertCount(1, $this->rows()); // the projection reconverged
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php
```

Expected: the three new tests FAIL (no listener class / not wired / resync doesn't re-drive it). The Task 1 tests still PASS.

- [ ] **Step 3: Implement the listener**

Create `app/Content/Pipeline/Listeners/ProjectPublishedReferencesListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Pipeline\Listeners;

use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Repositories\PublishedReferenceRepository;

/**
 * Maintains the published-reference projection (term-archives/facets spec §1) on the
 * publishing pipeline's after-commit events. Idempotent delete-then-insert per
 * (entry, locale), so `lemma:resync` re-drives it exactly like the other effects.
 *
 * Wired BEFORE InvalidateCacheTagsListener in the listener map: the cache purge must
 * see a CURRENT projection, or a request racing the purge could re-cache stale facet
 * counts until the next event.
 */
final class ProjectPublishedReferencesListener
{
    public function __construct(private readonly PublishedReferenceRepository $projection)
    {
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof EntryPublished) {
            if ($event->locale !== null) {
                $this->projection->projectFromPublished($event->entry, $event->type, $event->locale);
            }
            return;
        }
        if ($event instanceof EntryUnpublished) {
            if ($event->locale !== null) {
                $this->projection->clearForEntryLocale($event->entry, $event->locale);
            }
            return;
        }
        if ($event instanceof EntryDeleted) {
            $this->projection->clearForEntry($event->entry);
            $this->projection->clearForTarget($event->entry);
        }
    }
}
```

- [ ] **Step 4: Wire it — provider + resync**

In `app/Providers/LemmaServiceProvider.php`:

Add imports (alphabetical among the existing `use` lines):

```php
use App\Content\Pipeline\Listeners\ProjectPublishedReferencesListener;
use App\Content\Repositories\PublishedReferenceRepository;
```

Add service definitions next to the `InvalidateCacheTagsListener` definition (search for `InvalidateCacheTagsListener::class => [`):

```php
            PublishedReferenceRepository::class => [
                'class' => PublishedReferenceRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProjectPublishedReferencesListener::class => [
                'class' => ProjectPublishedReferencesListener::class,
                'shared' => true,
                'autowire' => true,
            ],
```

In the `$listeners` map (search `EntryPublished::class => [`), add `ProjectPublishedReferencesListener::class` as the FIRST element of the `EntryPublished::class`, `EntryUnpublished::class`, and `EntryDeleted::class` arrays (listeners run in array order; the projection must be current before the cache purge — see the listener docblock). Example for the first:

```php
            EntryPublished::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
```

(Repeat the first-position insertion for `EntryUnpublished::class` and `EntryDeleted::class`; the other event arrays are untouched.)

In `app/Content/Console/ResyncCommand.php`: add the import
`use App\Content\Pipeline\Listeners\ProjectPublishedReferencesListener;`, add
`ProjectPublishedReferencesListener  (always)  rebuild published-reference projection rows`
to the RE-DRIVEN EFFECTS docblock list, update the user-facing strings to mention the
projection — the command description/help ("Re-drive the publishing pipeline (projection +
cache + search, optionally webhooks)…") and the success line's `$effects` string
(`'projection + cache + search'` / `'projection + cache + search + webhooks'`; if
`ResyncCommandTest` asserts the old output text, update its expectation) — and make it
the FIRST re-driven effect in `reDrive()`:

```php
    private function reDrive(EntryPublished $event, bool $withWebhooks): void
    {
        ($this->getService(ProjectPublishedReferencesListener::class))($event);
        ($this->getService(InvalidateCacheTagsListener::class))($event);
        ($this->getService(PurgeCdnListener::class))($event);
        ($this->getService(ReindexSearchListener::class))($event);

        if ($withWebhooks) {
            ($this->getService(DispatchWebhookListener::class))($event);
        }
    }
```

- [ ] **Step 5: Run the tests + the neighbouring pipeline/console suites**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php
vendor/bin/phpunit tests/Integration/Pipeline/ tests/Integration/Console/ResyncCommandTest.php
```

Expected: all PASS (`ListenerWiringTest` may assert exact listener arrays — if it does, update its expectation to include the new listener, first).

- [ ] **Step 6: Commit** *(grouping 1 — the projection)*

```bash
composer phpcs
git add database/migrations/016_CreatePublishedEntryReferencesTable.php \
        app/Content/Repositories/PublishedReferenceRepository.php \
        app/Content/Pipeline/Listeners/ProjectPublishedReferencesListener.php \
        app/Content/Console/ResyncCommand.php \
        app/Providers/LemmaServiceProvider.php \
        tests/Integration/Content/Delivery/PublishedReferenceProjectionTest.php
git commit -m "feat(content): published-reference projection for term archives/facets

published_entry_references maintained by ProjectPublishedReferencesListener on
publish/unpublish/delete (first in the map, before cache purge) and re-driven
by lemma:resync; reference fields only, scalar-tolerant, idempotent rebuilds."
```

---

### Task 3: Extract the `HandlesDeliveryReads` trait (pure move)

`TaxonomyController` needs `DeliveryController`'s request helpers (`grantedScopes`, `selectionKey`, `ttl`, pagination clamps…). Duplicating `selectionKey` would fork cache-correctness-critical logic — extract a trait instead. **Bodies move verbatim; behavior must not change.**

**Files:**
- Create: `app/Content/Http/Controllers/Concerns/HandlesDeliveryReads.php`
- Modify: `app/Content/Http/Controllers/DeliveryController.php`

**Interfaces:**
- Produces: trait `App\Content\Http\Controllers\Concerns\HandlesDeliveryReads` with private methods `grantedScopes(Request): ?array`, `isScoped(Request): bool`, `locale(?string): string`, `ttl(array): int`, `clampPerPage(int): int`, `defaultPerPage(): int`, `pageParams(DeliveryListQuery): array`, `limit(DeliveryListQuery): int`, `stringQuery(Request, string): ?string`, `selectionKey(Request): string`, `scopeFingerprint(Request): string`. Requires the using class to have readonly props `$context` (ApplicationContext) and `$locales` (LocaleManagerInterface).

- [ ] **Step 1: Create the trait**

Create `app/Content/Http/Controllers/Concerns/HandlesDeliveryReads.php` with this frame, then MOVE (cut-paste, bodies unchanged) the eleven methods listed above out of `DeliveryController` (they sit between `grantedScopes` at ~line 361 and `scopeFingerprint` at the end of the file; `localeChain`, `compileFilter`, `shape`, `item`, `withCacheHeaders`, and the redirect helpers STAY in DeliveryController):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers\Concerns;

use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Settings\GeneralSettings;
use Symfony\Component\HttpFoundation\Request;

use function app;

/**
 * Read-side request helpers shared by the delivery controllers (DeliveryController,
 * TaxonomyController): caller scopes, locale/TTL resolution, pagination clamps, and the
 * ETag selection key. Pure moves from DeliveryController — behavior unchanged.
 *
 * Requires the using class to provide readonly props:
 *   - \Glueful\Bootstrap\ApplicationContext $context
 *   - \Glueful\Extensions\I18n\Contracts\LocaleManagerInterface $locales
 */
trait HandlesDeliveryReads
{
    // grantedScopes(), isScoped(), locale(), ttl(), clampPerPage(), defaultPerPage(),
    // pageParams(), limit(), stringQuery(), selectionKey(), scopeFingerprint()
    // — moved verbatim from DeliveryController.
}
```

In `DeliveryController`: add `use Concerns\HandlesDeliveryReads;` inside the class body (and keep its existing imports that the moved methods no longer need only if still used elsewhere — `GeneralSettings` moves to the trait; remove its import from DeliveryController if now unused).

- [ ] **Step 2: Run the delivery suites to prove no behavior change**

```bash
composer phpcs
vendor/bin/phpunit tests/Integration/DeliveryFlowTest.php tests/Integration/Content/Delivery/ tests/Integration/Seo/
```

Expected: PASS, zero test edits.

- [ ] **Step 3: Commit** *(grouping 2 — the refactor stands alone)*

```bash
git add app/Content/Http/Controllers/Concerns/HandlesDeliveryReads.php \
        app/Content/Http/Controllers/DeliveryController.php
git commit -m "refactor(content): extract HandlesDeliveryReads trait from DeliveryController

Pure move of the read-side request helpers (scopes, locale/ttl, pagination
clamps, ETag selection key) so TaxonomyController shares them instead of
forking cache-correctness-critical logic."
```

---

### Task 4: Facets — repository read side + endpoint

**Files:**
- Modify: `app/Content/Repositories/PublishedReferenceRepository.php` (add `facetCounts` + `membershipPredicate`)
- Create: `app/Content/Http/DTOs/Requests/Delivery/DeliveryFacetsQuery.php`
- Create: `app/Content/Http/Controllers/TaxonomyController.php` (`facets()` only; `archive()` comes in Task 5)
- Modify: `app/Providers/LemmaServiceProvider.php` (controller registration)
- Modify: `routes/lemma_content.php`
- Test: `tests/Integration/Content/Delivery/TermFacetsArchiveTest.php` (created here)

**Interfaces:**
- Consumes: trait from Task 3; projection table from Task 1; `FieldDefinition` props (`name`, `type`, `filterable`, `referenceType`, `referenceSlugField`); `DeliveryVisibility::isAccessible(bool, string, ?array)`; `DeliveryEtag::forItem/cacheTag/applyHeaders`.
- Produces: `PublishedReferenceRepository::facetCounts(string $sourceTypeUuid, FieldDefinition $field, string $targetTypeUuid, string $locale, int $limit): list<array{uuid: string, slug: ?string, count: int}>` and `membershipPredicate(string $sourceTypeUuid, string $field, string $targetEntryUuid): array{sql: string, bindings: list<mixed>}` (Task 5 consumes the predicate). `TaxonomyController::facets(Request, DeliveryFacetsQuery, string $type): Response`. Route `GET /v1/content/{type}/facets` registered BEFORE the show route.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Content/Delivery/TermFacetsArchiveTest.php`. It reuses `ReferenceDeliveryFilterTest`'s seeding shapes; the `category` type is seeded PUBLIC here (visibility tests flip it):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Delivery\DeliveryItemShaper;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceFilterResolver;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\TaxonomyController;
use App\Content\Http\DeliveryEtag;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryFacetsQuery;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\LemmaTestCase;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Term facets + archive endpoints (term-archives/facets spec §2–§6): projection-backed
 * counts and membership, fail-closed target-type visibility, DeliveryListQuery-mirrored
 * pagination envelopes, and the facets-before-show route precedence.
 */
final class TermFacetsArchiveTest extends LemmaTestCase
{
    private const CAT_TYPE_UUID = 'cattypefct00';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID,
            'slug' => 'category',
            'name' => 'Category',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode(
                [['name' => 'slug', 'type' => 'string', 'required' => true]],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                [
                    'name' => 'category',
                    'type' => 'reference',
                    'reference_type' => 'category',
                    'reference_slug_field' => 'slug',
                    'multiple' => true,
                    'filterable' => true,
                ],
            ],
        ]);
    }

    // ---- helpers -------------------------------------------------------------------

    private function controller(): TaxonomyController
    {
        $conn = $this->connection();
        $repo = new DeliveryRepository($conn);
        $types = new ContentTypeRepository($conn);
        $routes = new RouteRepository($conn, new RedirectRepository($conn));
        $paths = new PathRenderer('/{locale}/{type}/{slug}', null, 'en');
        $references = new ReferenceResolver($repo);
        $projector = new Projector();
        $canonical = new CanonicalProjector($repo, $routes, $types, $paths, 'en');

        return new TaxonomyController(
            $this->appContext(),
            $repo,
            $types,
            $this->container()->get(PublishedReferenceRepository::class),
            $this->container()->get(FilterCompiler::class),
            new SortCompiler(),
            $references,
            new ReferenceFilterResolver($conn, $types),
            $projector,
            new DeliveryEtag(),
            new FakeLocaleManager(),
            $canonical,
            null,
            new DeliveryItemShaper($types, $references, $projector, $canonical, null),
        );
    }

    private function seedCategory(string $entryUuid, string $versionUuid, string $slug): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    /** Seed a published post + its projection rows (the projection is the read source). */
    private function seedMemberPost(string $entryUuid, string $versionUuid, array $categoryUuids, string $title = 'P'): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => $title, 'category' => $categoryUuids], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($entryUuid, $this->postType, 'en');
    }

    /** @return array{status: int, body: array<string,mixed>, headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag} */
    private function facets(string $type, string $fields, ?int $limit = null): array
    {
        $res = $this->controller()->facets(
            Request::create('/v1/content/' . $type . '/facets', 'GET', ['fields' => $fields]),
            new DeliveryFacetsQuery(fields: $fields, limit: $limit),
            $type,
        );
        return [
            'status' => $res->getStatusCode(),
            'body' => (array) json_decode((string) $res->getContent(), true),
            'headers' => $res->headers,
        ];
    }

    // ---- facets ---------------------------------------------------------------------

    public function testFacetCountsGroupDistinctSourcesPerTerm(): void
    {
        $this->seedCategory('cathw0000001', 'vcathw000001', 'php');
        $this->seedCategory('cathw0000002', 'vcathw000002', 'laravel');
        $this->seedMemberPost('fpost0000001', 'vfpost000001', ['cathw0000001']);
        $this->seedMemberPost('fpost0000002', 'vfpost000002', ['cathw0000001', 'cathw0000002']);

        $r = $this->facets('post', 'category');
        self::assertSame(200, $r['status']);
        $cats = $r['body']['data']['category'];
        self::assertSame(
            [['uuid' => 'cathw0000001', 'slug' => 'php', 'count' => 2],
             ['uuid' => 'cathw0000002', 'slug' => 'laravel', 'count' => 1]],
            $cats, // count DESC, slug ASC
        );
        // Surrogate tags: source AND target type (zero new purge code rides these).
        self::assertStringContainsString('lemma:type:post', (string) $r['headers']->get('Cache-Tag'));
        self::assertStringContainsString('lemma:type:category', (string) $r['headers']->get('Cache-Tag'));
    }

    public function testUnpublishedTermDropsOutOfFacetsWhileProjectionRowsRemain(): void
    {
        // THE read-time-join guard (spec §1): a term can be unpublished without deletion.
        $this->seedCategory('catlive00001', 'vcatlive0001', 'live');
        $this->seedCategory('catdead00001', 'vcatdead0001', 'dead');
        $this->seedMemberPost('fpost0000003', 'vfpost000003', ['catlive00001', 'catdead00001']);

        $this->connection()->table('entry_publications')
            ->where('entry_uuid', '=', 'catdead00001')->delete(); // unpublish the term only

        $rows = $this->connection()->table('published_entry_references')
            ->where('target_entry_uuid', '=', 'catdead00001')->get();
        self::assertNotSame([], $rows, 'precondition: projection rows for the dead term still exist');

        $r = $this->facets('post', 'category');
        $uuids = array_column($r['body']['data']['category'], 'uuid');
        self::assertContains('catlive00001', $uuids);
        self::assertNotContains('catdead00001', $uuids);
    }

    public function testNonFilterableOrUnknownFieldIsRejected(): void
    {
        self::assertSame(422, $this->facets('post', 'title')['status']);   // not a reference field
        self::assertSame(422, $this->facets('post', 'nope')['status']);    // unknown field
    }

    public function testFacetLimitCapsPerFieldResults(): void
    {
        $this->seedCategory('catlim000001', 'vcatlim00001', 'aaa');
        $this->seedCategory('catlim000002', 'vcatlim00002', 'bbb');
        $this->seedMemberPost('fpost0000004', 'vfpost000004', ['catlim000001', 'catlim000002']);

        $r = $this->facets('post', 'category', 1);
        self::assertCount(1, $r['body']['data']['category']);
    }

    public function testNonPublicTargetTypeFailsClosedWithNoEnumeration(): void
    {
        // The P1 enumeration guard (spec §2/§4): whole-set term enumeration must not
        // leak when the TARGET type is not visible to the caller.
        $this->seedCategory('catpriv00001', 'vcatpriv0001', 'secret');
        $this->seedMemberPost('fpost0000005', 'vfpost000005', ['catpriv00001']);
        $this->connection()->table('content_types')
            ->where('uuid', '=', self::CAT_TYPE_UUID)->update(['public_delivery' => false]);

        $r = $this->facets('post', 'category'); // anonymous (no api_key_scopes attribute)
        self::assertSame(404, $r['status']);
        self::assertStringNotContainsString('secret', json_encode($r['body']));
        self::assertStringNotContainsString('catpriv00001', json_encode($r['body']));
    }

    public function testFacetsRouteWinsOverShowRoute(): void
    {
        // Kernel-level characterization: /{type}/facets is registered before
        // /{type}/{slugOrUuid}, so `facets` is a reserved word on this surface.
        $res = $this->handle(Request::create('/v1/content/post/facets?fields=category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertArrayHasKey('category', $body['data']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/TermFacetsArchiveTest.php
```

Expected: ERRORS — `TaxonomyController` / `DeliveryFacetsQuery` not found.

- [ ] **Step 3: Add the repository read methods**

Append to `app/Content/Repositories/PublishedReferenceRepository.php` (add imports `use App\Content\Delivery\InvalidFilterException;` and `use App\Content\Schema\FieldDefinition;`):

```php
    /**
     * Global facet counts for one (source type, reference field, locale): entries-per-term
     * over the projection, JOINED to the target's publication in the SAME locale at read
     * time (spec §1 — an unpublished term drops out while its rows remain). The slug is
     * read from the target's published version via the field's referenceSlugField, exactly
     * like ReferenceFilterResolver. Order: count DESC, slug ASC.
     *
     * @return list<array{uuid: string, slug: ?string, count: int}>
     */
    public function facetCounts(
        string $sourceTypeUuid,
        FieldDefinition $field,
        string $targetTypeUuid,
        string $locale,
        int $limit,
    ): array {
        $slugField = $field->referenceSlugField ?? 'slug';
        // Schema identifier — interpolated (never bound) so it can hit expression indexes;
        // re-assert the safe shape first (the ReferenceFilterResolver rule).
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $slugField) !== 1) {
            throw new InvalidFilterException("unsafe reference_slug_field: '{$slugField}'");
        }
        $slugExpr = "tv.fields ->> '{$slugField}'";

        $rows = $this->db->table('published_entry_references as pr')
            ->join('entry_publications as tp', 'tp.entry_uuid', '=', 'pr.target_entry_uuid')
            ->join('entry_versions as tv', 'tv.uuid', '=', 'tp.version_uuid')
            ->join('entries as te', 'te.uuid', '=', 'pr.target_entry_uuid')
            ->selectRaw("pr.target_entry_uuid as uuid, {$slugExpr} as slug, COUNT(DISTINCT pr.source_entry_uuid) as cnt")
            ->where('pr.source_content_type_uuid', '=', $sourceTypeUuid)
            ->where('pr.field', '=', $field->name)
            ->where('pr.locale', '=', $locale)
            ->where('tp.locale', '=', $locale)
            ->where('te.status', '=', 'active')
            ->where('te.content_type_uuid', '=', $targetTypeUuid)
            ->groupBy(['uuid', 'slug'])
            ->orderByRaw('cnt DESC, slug ASC')
            ->limit($limit)
            ->get();

        return array_map(static fn(array $r): array => [
            'uuid' => (string) $r['uuid'],
            'slug' => isset($r['slug']) ? (string) $r['slug'] : null,
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Archive membership predicate (spec §3 pin): an EXISTS over the projection, shaped
     * to ride DeliveryRepository's compiled-filter slot. Coupled to the delivery spine
     * aliases (`p` = entry_publications) exactly like FilterCompiler's `v.fields`
     * expressions are.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function membershipPredicate(string $sourceTypeUuid, string $field, string $targetEntryUuid): array
    {
        return [
            'sql' => 'EXISTS (SELECT 1 FROM published_entry_references pr'
                . ' WHERE pr.source_entry_uuid = p.entry_uuid AND pr.locale = p.locale'
                . ' AND pr.source_content_type_uuid = ? AND pr.field = ? AND pr.target_entry_uuid = ?)',
            'bindings' => [$sourceTypeUuid, $field, $targetEntryUuid],
        ];
    }
```

- [ ] **Step 4: Create the DTO**

Create `app/Content/Http/DTOs/Requests/Delivery/DeliveryFacetsQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Requests\Delivery;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for `GET /v1/content/{type}/facets`
 * ({@see \App\Content\Http\Controllers\TaxonomyController::facets()}).
 */
final class DeliveryFacetsQuery implements RequestData
{
    public function __construct(
        #[FromQuery(
            description: 'Comma-separated filterable reference field names to count, '
                . 'e.g. `fields=categories,tags`.',
        )]
        #[Rule('required|string')]
        public readonly string $fields = '',
        #[FromQuery(description: 'Content locale; defaults to the i18n default locale.')]
        #[Rule('string')]
        public readonly ?string $locale = null,
        #[FromQuery(description: 'Max terms per field (default 100, capped at 500).')]
        #[Rule('numeric')]
        public readonly ?int $limit = null,
    ) {
    }
}
```

- [ ] **Step 5: Create the controller with `facets()`**

Create `app/Content/Http/Controllers/TaxonomyController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Delivery\Cursor;
use App\Content\Delivery\DeliveryItemShaper;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\DeliveryVisibility;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\InvalidFilterException;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Delivery\UnfilterableFieldException;
use App\Content\Http\Controllers\Concerns\HandlesDeliveryReads;
use App\Content\Http\DeliveryEtag;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryFacetsQuery;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Seo\CanonicalProjector;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * The taxonomy read surface (term-archives/facets spec): facet counts and term-archive
 * pages over the published-reference projection. Published-only, like everything on the
 * delivery spine; the projection — not JSONB containment — is the membership source.
 *
 * Visibility is FAIL-CLOSED for referenced target types (spec §4): whole-set term
 * enumeration is a disclosure surface, so a non-visible target type 404s the request.
 */
final class TaxonomyController
{
    use HandlesDeliveryReads;

    private const FACET_LIMIT_DEFAULT = 100;
    private const FACET_LIMIT_MAX = 500;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DeliveryRepository $delivery,
        private readonly ContentTypeRepository $types,
        private readonly PublishedReferenceRepository $projection,
        private readonly FilterCompiler $filters,
        private readonly SortCompiler $sorts,
        private readonly ReferenceResolver $references,
        private readonly ReferenceTargetResolver $terms,
        private readonly Projector $projector,
        private readonly DeliveryEtag $etags,
        private readonly LocaleManagerInterface $locales,
        private readonly CanonicalProjector $canonical,
        private readonly ?SchemaProjector $schemaProjector = null,
        private readonly ?DeliveryItemShaper $shaper = null,
    ) {
    }

    private function itemShaper(): DeliveryItemShaper
    {
        return $this->shaper ?? new DeliveryItemShaper(
            $this->types,
            $this->references,
            $this->projector,
            $this->canonical,
            $this->schemaProjector,
        );
    }

    /**
     * Global facet counts for filterable reference fields of {type} (spec §2):
     * `?fields=categories,tags` → data.{field} = [{uuid, slug, count}...], counts from the
     * projection joined to the target's publication in the request locale.
     */
    public function facets(Request $request, DeliveryFacetsQuery $query, string $type): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($query->locale);
        $scopes = $this->grantedScopes($request);

        $names = array_values(array_filter(array_map('trim', explode(',', $query->fields))));
        if ($names === []) {
            return Response::validation(['fields' => 'at least one field name is required']);
        }

        // Validate every field and gate every TARGET type BEFORE any counting — fail
        // closed, no partial per-field responses (spec §2/§4).
        /** @var array<string, array{field: FieldDefinition, target: array<string,mixed>|null}> $resolved */
        $resolved = [];
        foreach ($names as $name) {
            $field = $schema->field($name);
            if ($field === null || $field->type !== 'reference' || !$field->filterable) {
                return Response::validation([
                    'fields' => "field '{$name}' is not a filterable reference field of '{$type}'",
                ]);
            }
            $targetRow = $this->types->findBySlug((string) ($field->referenceType ?? ''));
            if ($targetRow !== null) {
                $visible = DeliveryVisibility::isAccessible(
                    (bool) $targetRow['public_delivery'],
                    (string) $targetRow['slug'],
                    $scopes,
                );
                if (!$visible) {
                    return Response::notFound('Not found.');
                }
            }
            $resolved[$name] = ['field' => $field, 'target' => $targetRow];
        }

        $limit = $this->facetLimit($query->limit);
        $data = [];
        $tagSlugs = [(string) $typeRow['slug']];
        foreach ($resolved as $name => $pair) {
            if ($pair['target'] === null) {
                $data[$name] = []; // unknown target type matches nothing (filter parity)
                continue;
            }
            $data[$name] = $this->projection->facetCounts(
                $typeUuid,
                $pair['field'],
                (string) $pair['target']['uuid'],
                $locale,
                $limit,
            );
            $tagSlugs[] = (string) $pair['target']['slug'];
        }

        $response = Response::success($data, 'Facet counts retrieved.');
        // The payload hash stands in for a version identity: counts have no single
        // version uuid, and any publish that changes them purges via the type tags.
        $etag = $this->etags->forItem(sha1((string) json_encode($data)), $this->selectionKey($request));
        $cacheTag = implode(', ', array_map(
            static fn(string $s): string => 'lemma:type:' . $s,
            array_values(array_unique($tagSlugs)),
        ));
        return $this->etags->applyHeaders($response, $etag, $this->ttl($typeRow), $cacheTag, $this->isScoped($request));
    }

    private function facetLimit(?int $limit): int
    {
        if ($limit === null || $limit < 1) {
            return self::FACET_LIMIT_DEFAULT;
        }
        return min($limit, self::FACET_LIMIT_MAX);
    }
}
```

(`Cursor`, `UnfilterableFieldException`, `InvalidFilterException`, `FieldSelector`, and `DeliveryListQuery` imports are used by Task 5's `archive()` — keep them now so Task 5 only adds methods; if phpcs flags unused imports at this intermediate step, run Task 5 before the phpcs gate or drop and re-add them.)

- [ ] **Step 6: Register + route**

In `app/Providers/LemmaServiceProvider.php`: add import `use App\Content\Http\Controllers\TaxonomyController;` and a service definition next to `DeliveryController::class` (every Lemma HTTP controller must be registered):

```php
            TaxonomyController::class => [
                'class' => TaxonomyController::class,
                'shared' => true,
                'autowire' => true,
            ],
```

In `routes/lemma_content.php`: add the import `use App\Content\Http\Controllers\TaxonomyController;` and register facets BEFORE the show route (inside the existing group):

```php
    // Facet counts — registered BEFORE the show route: /{type}/facets and
    // /{type}/{slugOrUuid} share a segment shape and `facets` must win. `facets` is a
    // reserved word on this surface (an entry literally slugged `facets` is shadowed).
    $router->get('/{type}/facets', [TaxonomyController::class, 'facets'])
        ->middleware('lemma_delivery_access')
        ->middleware('rate_limit')
        ->rateLimit(120, 1, by: 'user');
```

- [ ] **Step 7: Run the facets tests**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/TermFacetsArchiveTest.php
vendor/bin/phpunit tests/Integration/DeliveryFlowTest.php
```

Expected: PASS. (If `groupBy(['uuid', 'slug'])` produces SQL PostgreSQL rejects — it groups by the output aliases — replace the two `groupBy`/`orderByRaw` lines with `->groupBy(['pr.target_entry_uuid', 'slug'])` and keep `orderByRaw('cnt DESC, slug ASC')`; the test defines correctness.) No commit yet — grouped with Tasks 5–6.

---

### Task 5: The archive endpoint

**Files:**
- Modify: `app/Content/Http/Controllers/TaxonomyController.php` (add `archive()` + helpers)
- Modify: `routes/lemma_content.php`
- Test: `tests/Integration/Content/Delivery/TermFacetsArchiveTest.php`

**Interfaces:**
- Consumes: `membershipPredicate` (Task 4); `ReferenceTargetResolver::resolve(FieldDescriptor, string, list<string>): list<string>`; `DeliveryRepository::{listPublished, paginatePublished, findPublishedByUuid, cursorFor}`; `DeliveryItemShaper::{shape, item, shapePublic}`; trait helpers.
- Produces: `TaxonomyController::archive(Request, DeliveryListQuery, string $type, string $field, string $term): Response`; route `GET /v1/content/{type}/archive/{field}/{term}`.

- [ ] **Step 1: Write the failing tests**

Add to `TermFacetsArchiveTest.php` (import to add: `use Glueful\Validation\RequestDataHydrator;`):

```php
    /** @return array{status: int, body: array<string,mixed>, headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag} */
    private function archive(string $type, string $field, string $term, array $queryParams = []): array
    {
        $dto = (new RequestDataHydrator())->hydrate(DeliveryListQuery::class, [], [], $queryParams);
        $req = new Request($queryParams);
        $res = $this->controller()->archive($req, $dto, $type, $field, $term);
        return [
            'status' => $res->getStatusCode(),
            'body' => (array) json_decode((string) $res->getContent(), true),
            'headers' => $res->headers,
        ];
    }

    public function testArchiveResolvesTermBySlugAndUuidWithEnvelope(): void
    {
        $this->seedCategory('catarc000001', 'vcatarc00001', 'news');
        $this->seedMemberPost('apost0000001', 'vapost000001', ['catarc000001'], 'A1');
        $this->seedMemberPost('apost0000002', 'vapost000002', [], 'A2'); // not a member

        foreach (['news', 'catarc000001'] as $termInput) {
            $r = $this->archive('post', 'category', $termInput);
            self::assertSame(200, $r['status']);
            self::assertSame('catarc000001', $r['body']['data']['term']['uuid']);
            $memberUuids = array_column($r['body']['data']['items'], 'uuid');
            self::assertSame(['apost0000001'], $memberUuids);
            self::assertArrayHasKey('next_cursor', $r['body']['data']);
        }
        // Surrogate tags: member entries + term entry + both types.
        $r = $this->archive('post', 'category', 'news');
        $cacheTag = (string) $r['headers']->get('Cache-Tag');
        self::assertStringContainsString('lemma:entry:apost0000001', $cacheTag);
        self::assertStringContainsString('lemma:entry:catarc000001', $cacheTag);
        self::assertStringContainsString('lemma:type:post', $cacheTag);
        self::assertStringContainsString('lemma:type:category', $cacheTag);
    }

    public function testArchiveMembershipComesFromTheProjectionNotJsonb(): void
    {
        // Seed a deliberate divergence (spec §3 pin): the stored JSONB claims membership,
        // the projection does not — the projection must win.
        $this->seedCategory('catdiv000001', 'vcatdiv00001', 'div');
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'divpost00001', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vdivpost0001', 'entry_uuid' => 'divpost00001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'Div', 'category' => ['catdiv000001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'divpost00001', 'locale' => 'en', 'version_uuid' => 'vdivpost0001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        // NO projection row inserted.

        $r = $this->archive('post', 'category', 'div');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['data']['items']); // JSONB says member; projection says no
    }

    public function testUnknownTermIs404AndEmptyArchiveIs200(): void
    {
        $this->seedCategory('catempty0001', 'vcatempty001', 'empty');
        self::assertSame(404, $this->archive('post', 'category', 'no-such-term')['status']);

        $r = $this->archive('post', 'category', 'empty');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['data']['items']);
    }

    public function testArchiveOffsetModeUsesFlattenedEnvelopeWithTopLevelTerm(): void
    {
        $this->seedCategory('catpage00001', 'vcatpage0001', 'paged');
        $this->seedMemberPost('ppost0000001', 'vppost000001', ['catpage00001'], 'P1');
        $this->seedMemberPost('ppost0000002', 'vppost000002', ['catpage00001'], 'P2');

        $r = $this->archive('post', 'category', 'paged', ['page' => '1', 'perPage' => '1']);
        self::assertSame(200, $r['status']);
        self::assertSame('catpage00001', $r['body']['term']['uuid']); // top-level in offset mode
        self::assertCount(1, $r['body']['data']);
        self::assertSame(2, $r['body']['total']);
        self::assertSame(2, $r['body']['total_pages']);
        self::assertTrue($r['body']['has_next_page']);
    }

    public function testArchiveComposesExtraFiltersAndRejectsBadField(): void
    {
        $this->seedCategory('catflt000001', 'vcatflt00001', 'flt');
        $this->seedMemberPost('xpost0000001', 'vxpost000001', ['catflt000001'], 'Keep');

        // Extra filter[...] on a non-filterable field → 422 through the same compiler.
        $r = $this->archive('post', 'category', 'flt', ['filter' => ['title' => ['eq' => 'Keep']]]);
        self::assertSame(422, $r['status']);

        // Bad {field} segment → 422 (same gate as facets).
        self::assertSame(422, $this->archive('post', 'title', 'flt')['status']);
    }

    public function testArchiveNonPublicTermTypeFailsClosed(): void
    {
        $this->seedCategory('catpriv00002', 'vcatpriv0002', 'hidden');
        $this->seedMemberPost('hpost0000001', 'vhpost000001', ['catpriv00002']);
        $this->connection()->table('content_types')
            ->where('uuid', '=', self::CAT_TYPE_UUID)->update(['public_delivery' => false]);

        $r = $this->archive('post', 'category', 'hidden'); // anonymous
        self::assertSame(404, $r['status']);
        self::assertStringNotContainsString('hidden', json_encode($r['body']));
    }

    public function testArchiveRouteResolvesThroughTheKernel(): void
    {
        $this->seedCategory('catkern00001', 'vcatkern0001', 'kern');
        $this->seedMemberPost('kpost0000001', 'vkpost000001', ['catkern00001'], 'K1');

        $res = $this->handle(Request::create('/v1/content/post/archive/category/kern', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertSame('catkern00001', $body['data']['term']['uuid']);
    }

    public function testNonPublicSourceTypeIs404OnBothEndpointsThroughTheKernel(): void
    {
        // Review P2: SOURCE-type visibility is enforced by the lemma_delivery_access
        // route middleware (same as the existing delivery routes) — controller-direct
        // tests bypass it, so prove the route wiring at kernel level for both endpoints.
        $this->seedCategory('catsrc000001', 'vcatsrc00001', 'src');
        $this->seedMemberPost('spost0000001', 'vspost000001', ['catsrc000001'], 'S1');
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->postType)->update(['public_delivery' => false]);

        $facets = $this->handle(Request::create('/v1/content/post/facets?fields=category', 'GET'));
        self::assertSame(404, $facets->getStatusCode());
        self::assertStringNotContainsString('catsrc000001', (string) $facets->getContent());

        $archive = $this->handle(Request::create('/v1/content/post/archive/category/src', 'GET'));
        self::assertSame(404, $archive->getStatusCode());
        self::assertStringNotContainsString('catsrc000001', (string) $archive->getContent());
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/TermFacetsArchiveTest.php
```

Expected: new tests ERROR — `archive()` undefined; the Task 4 facets tests still PASS.

- [ ] **Step 3: Implement `archive()`**

Add to `TaxonomyController` (below `facets()`):

```php
    /**
     * Term archive (spec §3): the shaped term + its published members. Membership is
     * PINNED to the projection (an EXISTS combined into the compiled-filter slot) —
     * never filter[field][eq] recompiled through JSONB, or facets and archives could
     * diverge. Pagination/sort/field-selection/shaping/ETag delegate to the existing
     * delivery machinery; envelopes mirror DeliveryListQuery's two modes exactly.
     */
    public function archive(
        Request $request,
        DeliveryListQuery $query,
        string $type,
        string $field,
        string $term,
    ): Response {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($query->locale);
        $scopes = $this->grantedScopes($request);

        $fieldDef = $schema->field($field);
        if ($fieldDef === null || $fieldDef->type !== 'reference' || !$fieldDef->filterable) {
            return Response::validation([
                'field' => "field '{$field}' is not a filterable reference field of '{$type}'",
            ]);
        }
        $targetRow = $this->types->findBySlug((string) ($fieldDef->referenceType ?? ''));
        if ($targetRow === null) {
            return Response::notFound('Term not found.');
        }
        $targetSlug = (string) $targetRow['slug'];
        if (!DeliveryVisibility::isAccessible((bool) $targetRow['public_delivery'], $targetSlug, $scopes)) {
            // Fail closed (spec §4): the term body would be in the envelope.
            return Response::notFound('Not found.');
        }

        // uuid-first then referenceSlugField, published-scoped — filter-value precedence.
        try {
            $targets = $this->terms->resolve($fieldDef, $locale, [$term]);
        } catch (InvalidFilterException $e) {
            return Response::validation(['term' => $e->getMessage()]);
        }
        $termUuid = $targets[0] ?? null;
        if ($termUuid === null) {
            return Response::notFound('Term not found.'); // ≠ empty archive (200)
        }
        $termRow = $this->delivery->findPublishedByUuid((string) $targetRow['uuid'], $locale, $termUuid);
        if ($termRow === null) {
            return Response::notFound('Term not found.');
        }
        $shapedTerm = $this->itemShaper()->shapePublic($termRow, (string) $targetRow['uuid'], $targetSlug);

        try {
            $filter = $query->filter === [] ? null : $this->filters->compile($schema, $query->filter, $locale);
            $order = $this->sorts->compile($schema, $query->sort);
        } catch (UnfilterableFieldException | InvalidFilterException $e) {
            return Response::validation(['filter' => $e->getMessage()]);
        }
        $filter = $this->combineWithMembership(
            $filter,
            $this->projection->membershipPredicate($typeUuid, $field, $termUuid),
        );

        $selector = FieldSelector::fromRequest($request);

        if ($query->wantsPagination()) {
            [$page, $perPage] = $this->pageParams($query);
            $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, $order);
            $rows = $this->itemShaper()->shape($result['data'], $schema, $selector, $locale, $typeUuid, $scopes);
            $totalPages = (int) ceil($result['total'] / max(1, $result['per_page']));
            // The list endpoint's flattened paginated envelope + ONE additive top-level
            // `term` (that envelope has no data object to nest into — spec §3).
            $response = new Response([
                'success' => true,
                'message' => 'Data retrieved successfully',
                'term' => $shapedTerm,
                'data' => array_map(fn(array $r): array => $this->itemShaper()->item($r), $rows),
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'total_pages' => $totalPages,
                'has_next_page' => $result['current_page'] < $totalPages,
                'has_previous_page' => $result['current_page'] > 1,
            ]);
            return $this->archiveCacheHeaders($request, $response, $rows, $typeRow, $termRow, $targetSlug);
        }

        $limit = $this->limit($query);
        $cursor = Cursor::decode($query->cursor ?? '');
        $rows = $this->delivery->listPublished($typeUuid, $locale, $limit, $filter, $order, $cursor);
        $shaped = $this->itemShaper()->shape($rows, $schema, $selector, $locale, $typeUuid, $scopes);
        $nextCursor = null;
        if (count($rows) === $limit && $rows !== []) {
            $nextCursor = Cursor::encode($this->delivery->cursorFor($rows[count($rows) - 1], $order));
        }
        $response = Response::success([
            'term' => $shapedTerm,
            'items' => array_map(fn(array $r): array => $this->itemShaper()->item($r), $shaped),
            'next_cursor' => $nextCursor,
        ], 'Content retrieved.');
        return $this->archiveCacheHeaders($request, $response, $shaped, $typeRow, $termRow, $targetSlug);
    }

    /**
     * @param array{sql: string, bindings: list<mixed>}|null $filter
     * @param array{sql: string, bindings: list<mixed>} $membership
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function combineWithMembership(?array $filter, array $membership): array
    {
        if ($filter === null) {
            return $membership;
        }
        return [
            'sql' => '(' . $filter['sql'] . ') AND ' . $membership['sql'],
            'bindings' => array_merge($filter['bindings'], $membership['bindings']),
        ];
    }

    /**
     * List ETag/Cache-Tag mechanics + the term's identity: the term's version is part of
     * the payload, and its entry tag + type tag make term edits/unpublishes purge the
     * archive with zero new invalidation code (spec §5).
     *
     * @param list<array<string,mixed>> $rows SHAPED member rows (still carrying envelope keys)
     * @param array<string,mixed> $typeRow
     * @param array<string,mixed> $termRow
     */
    private function archiveCacheHeaders(
        Request $request,
        Response $response,
        array $rows,
        array $typeRow,
        array $termRow,
        string $targetSlug,
    ): Response {
        $versionUuids = array_map(static fn(array $r): string => (string) ($r['version_uuid'] ?? ''), $rows);
        $versionUuids[] = (string) ($termRow['version_uuid'] ?? '');
        $entryUuids = array_map(static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''), $rows);
        $entryUuids[] = (string) ($termRow['entry_uuid'] ?? '');
        $etag = $this->etags->forList($versionUuids, $this->selectionKey($request));
        $cacheTag = $this->etags->cacheTag($entryUuids, (string) $typeRow['slug']);
        if ($targetSlug !== (string) $typeRow['slug']) {
            $cacheTag .= ', lemma:type:' . $targetSlug; // self-referencing taxonomies dedupe
        }
        return $this->etags->applyHeaders($response, $etag, $this->ttl($typeRow), $cacheTag, $this->isScoped($request));
    }
```

- [ ] **Step 4: Add the route**

In `routes/lemma_content.php`, after the show route (distinct segment count — no precedence concern):

```php
    // Term archive: the shaped term + its published members (projection-backed
    // membership — term-archives/facets spec §3).
    $router->get('/{type}/archive/{field}/{term}', [TaxonomyController::class, 'archive'])
        ->middleware('lemma_delivery_access')
        ->middleware('rate_limit')
        ->rateLimit(120, 1, by: 'user');
```

- [ ] **Step 5: Run the full test file + delivery suites**

```bash
vendor/bin/phpunit tests/Integration/Content/Delivery/TermFacetsArchiveTest.php
vendor/bin/phpunit tests/Integration/DeliveryFlowTest.php tests/Integration/Content/Delivery/
```

Expected: PASS. No commit yet — grouped with Task 6.

---

### Task 6: Changelog + full verification

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]` → `### Added`, prepend to the existing block)

- [ ] **Step 1: CHANGELOG entry**

Prepend under `## [Unreleased]` / `### Added`:

```markdown
- **Term archives + facet counts** (the taxonomy delivery surface the references spec
  deferred): a new `published_entry_references` projection (listener-maintained on
  publish/unpublish/delete, re-driven by `lemma:resync`) is the single source of
  "published source references published term". `GET /v1/content/{type}/facets?fields=…`
  returns global per-term counts (`{uuid, slug, count}`, `count DESC, slug ASC`, limit
  100/max 500); `GET /v1/content/{type}/archive/{field}/{term}` returns the shaped term +
  its members with the list endpoint's exact pagination modes. Target-type visibility is
  fail-closed (a non-public term type 404s — no term enumeration); term liveness is a
  read-time publication join, so unpublished terms drop out immediately. Cache purging
  rides the existing surrogate tags with zero new invalidation code. `facets` becomes a
  reserved word under `/v1/content/{type}/`.
```

- [ ] **Step 2: Full verification**

```bash
composer phpcs
composer boundaries
vendor/bin/phpunit --testsuite Integration
```

Expected: phpcs clean, boundaries OK, Integration green (same pre-existing single skip as the render-caching run).

- [ ] **Step 3: Commit** *(grouping 3 — endpoints + docs)*

```bash
git add app/Content/Repositories/PublishedReferenceRepository.php \
        app/Content/Http/DTOs/Requests/Delivery/DeliveryFacetsQuery.php \
        app/Content/Http/Controllers/TaxonomyController.php \
        app/Providers/LemmaServiceProvider.php \
        routes/lemma_content.php \
        tests/Integration/Content/Delivery/TermFacetsArchiveTest.php \
        CHANGELOG.md
git commit -m "feat(content): term-archive + facet-count delivery endpoints

GET /v1/content/{type}/facets (global counts over the published-reference
projection, fail-closed target-type visibility) and
/{type}/archive/{field}/{term} (shaped term envelope; membership pinned to the
projection via EXISTS, composing with filters/sort/pagination/ETag unchanged)."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 table/maintenance/hygiene/resync → Tasks 1–2 (read-time-join warning proven by `testUnpublishedTermDropsOutOfFacetsWhileProjectionRowsRemain`; rollback/schema-drift covered by `testOldSchemaVersionFieldsProjectThroughMigrationChain` + `testRollbackRepinRebuildsProjectionFromTheRepinnedVersion` — `projectFromPublished` projects pinned fields forward through `SchemaProjector`, mirroring delivery shaping); §2 facets endpoint incl. fail-closed P1 guard → Task 4; §3 archive incl. the projection-membership pin (divergence test) and both pagination envelopes → Task 5; §4 visibility → target-type gates in Tasks 4–5 controller tests + SOURCE-type middleware wiring proven by `testNonPublicSourceTypeIs404OnBothEndpointsThroughTheKernel`; §5 caching/tags → `archiveCacheHeaders` + facets tag assertions; §6 routing → `testFacetsRouteWinsOverShowRoute` (kernel) + registration-order comment; §7 test list → all mapped except "locale isolation (fr counts ≠ en counts)", which is covered structurally (every projection row, join, and predicate carries `locale`; the clears test exercises locale scoping) — add an explicit fr/en counts test during execution if reviewers want it belt-and-braces.
- **Type consistency:** `facetCounts(FieldDefinition …)` matches the controller passing `$pair['field']`; `membershipPredicate(string, string, string)` matches `archive()`'s call; the trait method set matches both call sites; `DeliveryFacetsQuery(fields:, limit:)` named args match the test helper; event constructors match `BaseEntryEvent`.
- **Known judgement calls, stated:** listener ordered FIRST (before cache purge) with rationale; facets ETag = payload-hash via `forItem` (no version identity exists for an aggregate); `groupBy` output-alias fallback documented inline in Task 4 Step 7; unknown target type → empty facet list (filter parity: "matches nothing") vs non-visible target → 404 (fail closed).
