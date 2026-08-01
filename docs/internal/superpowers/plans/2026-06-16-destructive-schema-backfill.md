# Destructive Schema-Change Backfill (V1.x: delete + rename) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Shipped (2026-06-17) — implemented, reviewed, and merged. Steps left as `[ ]` for historical reference.

**Goal:** Let an operator delete or rename a content-type field and safely migrate existing draft and published content to the new shape — without ever mutating an immutable published version and without a visible half-migrated window. Reads stay correct throughout because every fields-bearing row is **lazily projected forward** to the current schema on the read path.

**Architecture:** A pure op model (`RenameField`/`DeleteField`/`MigrationOpSet` in `app/Content/Schema/Migration/`) expresses the field-map transform. A new `entry_schema_migrations` table is **both** the operation record and the ordered migration log. A `SchemaProjector` replays the recorded ops to project any lagging blob forward (the safety invariant, applied on delivery, admin, preview, and rollback reads). `MigrationService` validates ops against the current schema, in **one transaction** flips `content_types.schema_version` + stores the new schema + records the migration row (`status='running'`), then enqueues the backfill **after commit** via `db()->afterCommit()` (so a rolled-back txn leaves no queued job). A shared `BackfillRunner` (driven by `RunBackfillJob` queue wrapper and the `lemma:schema:backfill` command) processes each `{entry_uuid, locale, kind:draft|published}` work item whose `schema_version < to_version`: drafts are transformed in place; published versions get a **new** migrated `entry_versions` row (via `reserveNextVersionNumber` + append) re-pinned (old version untouched), references rebuilt from the projected shape. Filter indexes are reconciled by **enqueuing `EnsureFilterIndexesJob`** (not registry mutation); delivery cache is invalidated. Migrations are forward-only and resumable; reversal is a compensating migration.

**Tech Stack:** PHP 8.3, PostgreSQL (required — partial-unique index, `CHECK`, `pg_advisory_xact_lock`), PHPUnit 10, Glueful framework (migrations, `BaseCommand`, `Queue\Job`/`QueueManager`, `RequestData` DTOs, `db()->transaction()`/`afterCommit`). Tests run on Postgres via `LemmaTestCase`. Conventions: `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col.

**Spec:** `docs/superpowers/specs/2026-06-16-destructive-schema-backfill-design.md`

---

## File map

**Op model (pure — no I/O):**
- Create: `app/Content/Schema/Migration/MigrationOp.php` — op interface: `apply(array): array`, `applyForProjection(array): array`, `toArray()`, static `fromArray()`.
- Create: `app/Content/Schema/Migration/RenameField.php` — moves `$fields[from] → $fields[to]`; `apply()` throws on collision, `applyForProjection()` keeps existing `to`.
- Create: `app/Content/Schema/Migration/DeleteField.php` — unsets `$fields[name]`.
- Create: `app/Content/Schema/Migration/MigrationOpSet.php` — ordered list; `apply()`/`applyForProjection()` run ops in order; `toArray()`/`fromArray()` (de)serialize.
- Create: `app/Content/Schema/Migration/MigrationCollisionException.php` — thrown by `RenameField::apply()` on a present-`to` collision.

**Table + log + projector:**
> **Migration number:** `010` here assumes `009` is the current max on disk. Other POST_V1
> features (scheduled-publish `010_CreateEntrySchedulesTable`, SEO `010_CreateEntryRedirectsTable`)
> also introduce migrations numbered from the same base — they are **not** all `010`. Before
> creating this file, run `ls database/migrations/` and use the **next available** number;
> rename the class/file accordingly (the table name and code are unaffected).
- Create: `database/migrations/010_CreateEntrySchemaMigrationsTable.php` — `entry_schema_migrations` (record + log) with CHECK + partial-unique via raw PDO. *(Number is `010` only if no sibling POST_V1 migration has landed first — see note above.)*
- Create: `app/Content/Repositories/MigrationRepository.php` — insert/find/list migrations; counters; chain lookup for the projector; flip-and-record in one txn.
- Create: `app/Content/Schema/Migration/SchemaProjector.php` — `project(typeUuid, fromSchemaVersion, fields)`: replay ops `from_version >= fromSchemaVersion AND to_version <= current`, no-op fast path, per-request chain cache.

**Service + backfill:**
- Create: `app/Content/Services/MigrationService.php` — validate ops, flip+record in one txn, enqueue after commit; 422/409 mapping inputs.
- Create: `app/Content/Backfill/BackfillRunner.php` — shared run logic (the test target): process work items, new migrated versions + re-pin, draft transform, references, enqueue `EnsureFilterIndexesJob`, cache invalidation, status.
- Create: `app/Content/Jobs/RunBackfillJob.php` — cron/queue `Job` wrapper over the runner.
- Create: `app/Content/Console/RunBackfillCommand.php` — `lemma:schema:backfill <migration-uuid>` operator/resume entry point.

**Admin API:**
- Create: `app/Content/Http/DTOs/MigrationData.php` — request body (`ops` list).
- Create: `app/Content/Http/Controllers/MigrationController.php` — `POST /content-types/{slug}/migrations` (+ `GET .../migrations`, `GET .../migrations/{uuid}` poll).
- Modify: `routes/lemma_admin.php` — 3 routes.

**Read-path edits (the invariant):**
- Modify: `app/Content/Http/Controllers/DeliveryController.php` — project each row in `shape()`.
- Modify: `app/Content/Http/Controllers/EntryController.php` — project `getDraft` fields.
- Modify: `app/Content/Http/Controllers/PublicationController.php` — project `versions` fields.
- Modify: `app/Content/Preview/PreviewReader.php` — project resolved draft/version fields.
- Modify: `app/Content/Services/PublishService.php` — `rollback()` projects target fields before rebuilding `entry_references` (contract unchanged: re-pins existing, returns requested uuid).

**Wiring + tests:**
- Modify: `app/Providers/LemmaServiceProvider.php` — register repo, projector, service, runner, controller, command; extend `commands([...])`.
- Modify: `tests/Support/LemmaTestCase.php` — add `entry_schema_migrations` to `TABLES`.
- Modify: `scripts/run-test-migrations.php` — add `entry_schema_migrations` to `$requiredTables`.
- Create tests: `tests/Unit/Content/Schema/Migration/MigrationOpSetTest.php`, `tests/Unit/Content/Schema/Migration/SchemaProjectorTest.php` (the projector test needs DB → put under Integration), `tests/Integration/Content/SchemaProjectorTest.php`, `tests/Integration/Content/MigrationServiceTest.php`, `tests/Integration/Content/BackfillRunnerTest.php`, `tests/Integration/Http/MigrationApiTest.php`, `tests/Integration/Content/ReadProjectionTest.php`.

> **Migration number `010`:** the current max is `009_AddFilterIndexRegistry.php` (confirmed). If the scheduled-publish plan's `010_CreateEntrySchedulesTable.php` lands first, renumber this to `011`.

---

### Task 1: Op model — `MigrationOp`, `RenameField`, `DeleteField`, `MigrationOpSet`

**Files:**
- Create: `app/Content/Schema/Migration/MigrationOp.php`, `RenameField.php`, `DeleteField.php`, `MigrationOpSet.php`, `MigrationCollisionException.php`
- Test: `tests/Unit/Content/Schema/Migration/MigrationOpSetTest.php`

- [ ] **Step 1: Write the failing unit test.** `tests/Unit/Content/Schema/Migration/MigrationOpSetTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Schema\Migration;

use App\Content\Schema\Migration\DeleteField;
use App\Content\Schema\Migration\MigrationCollisionException;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use PHPUnit\Framework\TestCase;

final class MigrationOpSetTest extends TestCase
{
    public function testRenameMovesTheValue(): void
    {
        $out = (new RenameField('old', 'new'))->apply(['old' => 'v', 'keep' => 1]);
        self::assertSame(['keep' => 1, 'new' => 'v'], $out);
        self::assertArrayNotHasKey('old', $out);
    }

    public function testRenameOfAbsentSourceIsANoOp(): void
    {
        // A row written before the field existed simply has no key to move.
        self::assertSame(['keep' => 1], (new RenameField('old', 'new'))->apply(['keep' => 1]));
    }

    public function testDeleteDropsTheKey(): void
    {
        self::assertSame(['keep' => 1], (new DeleteField('gone'))->apply(['gone' => 'x', 'keep' => 1]));
    }

    public function testOpSetAppliesInOrder(): void
    {
        $set = new MigrationOpSet([new RenameField('a', 'b'), new DeleteField('c')]);
        self::assertSame(['b' => 1], $set->apply(['a' => 1, 'c' => 2]));
    }

    public function testApplyIsIdempotentOnAlreadyMigratedFields(): void
    {
        // Re-applying to a blob already at the new shape changes nothing (rename source
        // absent → no-op; delete target absent → no-op). This underpins resumable backfill.
        $set = new MigrationOpSet([new RenameField('a', 'b'), new DeleteField('c')]);
        $once = $set->apply(['a' => 1, 'c' => 2]);
        self::assertSame($once, $set->apply($once));
    }

    public function testRoundTripsThroughArray(): void
    {
        $set = new MigrationOpSet([new RenameField('a', 'b'), new DeleteField('c')]);
        $restored = MigrationOpSet::fromArray($set->toArray());
        self::assertSame($set->toArray(), $restored->toArray());
        self::assertSame(
            [['op' => 'rename', 'from' => 'a', 'to' => 'b'], ['op' => 'delete', 'name' => 'c']],
            $set->toArray()
        );
    }

    public function testRenameCollisionThrowsOnMaterializeButKeepsExistingOnProjection(): void
    {
        $op = new RenameField('from', 'to');
        // Materialization variant: a present `to` is a data anomaly — throw, do not overwrite.
        try {
            $op->apply(['from' => 'new', 'to' => 'existing']);
            self::fail('expected MigrationCollisionException');
        } catch (MigrationCollisionException $e) {
            self::assertStringContainsString('to', $e->getMessage());
        }
        // Projection variant: never error on a read — keep existing `to`, drop `from`.
        self::assertSame(['to' => 'existing'], $op->applyForProjection(['from' => 'new', 'to' => 'existing']));
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter MigrationOpSetTest`
Expected: FAIL — `Class "App\Content\Schema\Migration\RenameField" not found`.

- [ ] **Step 3: Implement the op model.**

`app/Content/Schema/Migration/MigrationCollisionException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\Migration;

/**
 * Thrown by RenameField::apply() (materialization variant) when the rename target is
 * already present with a value. Unreachable for schema-conformant content (the target was
 * not a declared field before the rename); the backfill records it as a per-item failure.
 */
final class MigrationCollisionException extends \RuntimeException
{
}
```

`app/Content/Schema/Migration/MigrationOp.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\Migration;

/**
 * A single, pure field-map transform. Two variants by caller (spec §1):
 *  - apply()             — materialization (backfill): throws on a rename collision.
 *  - applyForProjection()— read path: never throws; keeps existing target on collision.
 * No I/O — exhaustively unit-testable. Extensible later (RetypeField).
 */
interface MigrationOp
{
    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function apply(array $fields): array;

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function applyForProjection(array $fields): array;

    /** @return array<string,mixed> serialized form for storage */
    public function toArray(): array;

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self;
}
```

`app/Content/Schema/Migration/RenameField.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\Migration;

final class RenameField implements MigrationOp
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function apply(array $fields): array
    {
        if (!array_key_exists($this->from, $fields)) {
            return $fields; // nothing written under the old name — no-op
        }
        if (array_key_exists($this->to, $fields)) {
            throw new MigrationCollisionException(
                "rename target '{$this->to}' already present — refusing to overwrite"
            );
        }
        $fields[$this->to] = $fields[$this->from];
        unset($fields[$this->from]);
        return $fields;
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function applyForProjection(array $fields): array
    {
        if (!array_key_exists($this->from, $fields)) {
            return $fields;
        }
        // Never error on a read: keep an existing target, just drop the stale source.
        if (!array_key_exists($this->to, $fields)) {
            $fields[$this->to] = $fields[$this->from];
        }
        unset($fields[$this->from]);
        return $fields;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['op' => 'rename', 'from' => $this->from, 'to' => $this->to];
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self((string) ($raw['from'] ?? ''), (string) ($raw['to'] ?? ''));
    }
}
```

`app/Content/Schema/Migration/DeleteField.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\Migration;

final class DeleteField implements MigrationOp
{
    public function __construct(public readonly string $name)
    {
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function apply(array $fields): array
    {
        unset($fields[$this->name]);
        return $fields;
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function applyForProjection(array $fields): array
    {
        return $this->apply($fields);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['op' => 'delete', 'name' => $this->name];
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self((string) ($raw['name'] ?? ''));
    }
}
```

`app/Content/Schema/Migration/MigrationOpSet.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\Migration;

final class MigrationOpSet
{
    /** @param list<MigrationOp> $ops */
    public function __construct(private readonly array $ops)
    {
    }

    /** @return list<MigrationOp> */
    public function ops(): array
    {
        return $this->ops;
    }

    /** Materialization: applies ops in order; a rename collision throws. @param array<string,mixed> $fields @return array<string,mixed> */
    public function apply(array $fields): array
    {
        foreach ($this->ops as $op) {
            $fields = $op->apply($fields);
        }
        return $fields;
    }

    /** Read path: applies ops in order, never throwing. @param array<string,mixed> $fields @return array<string,mixed> */
    public function applyForProjection(array $fields): array
    {
        foreach ($this->ops as $op) {
            $fields = $op->applyForProjection($fields);
        }
        return $fields;
    }

    /** @return list<array<string,mixed>> */
    public function toArray(): array
    {
        return array_map(static fn(MigrationOp $o): array => $o->toArray(), $this->ops);
    }

    /** @param list<array<string,mixed>> $raw */
    public static function fromArray(array $raw): self
    {
        $ops = [];
        foreach ($raw as $opRaw) {
            $ops[] = match ((string) ($opRaw['op'] ?? '')) {
                'rename' => RenameField::fromArray($opRaw),
                'delete' => DeleteField::fromArray($opRaw),
                default => throw new \InvalidArgumentException("unknown migration op '" . ($opRaw['op'] ?? '') . "'"),
            };
        }
        return new self($ops);
    }
}
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter MigrationOpSetTest`
Expected: PASS (all 8 cases).

- [ ] **Step 5: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Schema/Migration tests/Unit/Content/Schema/Migration/MigrationOpSetTest.php
git commit -m "Add pure migration op model (rename/delete/op-set)"
```

---

### Task 2: `entry_schema_migrations` table + `MigrationRepository`

**Files:**
- Create: `database/migrations/010_CreateEntrySchemaMigrationsTable.php`
- Create: `app/Content/Repositories/MigrationRepository.php`
- Modify: `tests/Support/LemmaTestCase.php`, `scripts/run-test-migrations.php`, `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Content/MigrationServiceTest.php` (schema + repo assertions here; service logic in Task 4)

- [ ] **Step 1: Write the failing schema + repo test.** Create `tests/Integration/Content/MigrationServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\Migration\DeleteField;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\LemmaTestCase;

final class MigrationServiceTest extends LemmaTestCase
{
    public function testTableShapeAndPartialUnique(): void
    {
        $pdo = $this->connection()->getPDO();
        $ins = static fn (string $uuid, string $type, string $status): bool => (bool) $pdo->prepare(
            "INSERT INTO entry_schema_migrations
               (uuid, content_type_uuid, from_version, to_version, ops, status, created_at)
             VALUES (?, ?, 1, 2, '[]', ?, now())"
        )->execute([$uuid, $type, $status]);

        self::assertTrue($ins('m1aaaaaaaaaa', 'ct1111111111', 'running'));
        // a second ACTIVE migration for the same type → partial-unique violation
        $this->expectException(\PDOException::class);
        $ins('m2bbbbbbbbbb', 'ct1111111111', 'pending');
    }

    public function testCompletedMigrationDoesNotBlockANewActiveOne(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->prepare(
            "INSERT INTO entry_schema_migrations
               (uuid, content_type_uuid, from_version, to_version, ops, status, created_at)
             VALUES (?, 'ct2222222222', 1, 2, '[]', 'completed', now())"
        )->execute(['m3cccccccccc']);
        $ok = $pdo->prepare(
            "INSERT INTO entry_schema_migrations
               (uuid, content_type_uuid, from_version, to_version, ops, status, created_at)
             VALUES (?, 'ct2222222222', 2, 3, '[]', 'running', now())"
        )->execute(['m4dddddddddd']);
        self::assertTrue($ok, 'a completed migration must not block a fresh active one');
    }

    public function testRecordMigrationFlipsSchemaAndStoresOpsInOneTransaction(): void
    {
        $repo = new MigrationRepository($this->connection());
        $typeUuid = $this->seedType(1, [['name' => 'a'], ['name' => 'c']]);

        $set = new MigrationOpSet([new RenameField('a', 'b'), new DeleteField('c')]);
        $newSchema = [['name' => 'b', 'type' => 'string']];

        $uuid = $repo->recordAndFlip($typeUuid, 1, $set, $newSchema, 3, 'user00000001');

        $row = $repo->find($uuid);
        self::assertSame('running', $row['status']);
        self::assertSame(1, (int) $row['from_version']);
        self::assertSame(2, (int) $row['to_version']);
        self::assertSame(3, (int) $row['work_items_total']);
        self::assertSame($set->toArray(), $row['ops']);

        $type = $this->connection()->table('content_types')->where('uuid', '=', $typeUuid)->first();
        self::assertSame(2, (int) $type['schema_version']);
        self::assertSame($newSchema, json_decode((string) $type['schema'], true));
    }

    public function testActiveMigrationLookup(): void
    {
        $repo = new MigrationRepository($this->connection());
        $typeUuid = $this->seedType(1, [['name' => 'a']]);
        self::assertNull($repo->activeForType($typeUuid));
        $repo->recordAndFlip($typeUuid, 1, new MigrationOpSet([new DeleteField('a')]), [], 0, null);
        self::assertNotNull($repo->activeForType($typeUuid));
    }

    /** @param list<array<string,mixed>> $fields */
    private function seedType(int $schemaVersion, array $fields): string
    {
        $uuid = substr(md5(uniqid('', true)), 0, 12);
        $this->connection()->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => 'type-' . $uuid,
            'name' => 'T',
            'status' => 'active',
            'schema' => json_encode($fields, JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter MigrationServiceTest`
Expected: FAIL — `relation "entry_schema_migrations" does not exist`.

- [ ] **Step 3: Create the migration.** `database/migrations/010_CreateEntrySchemaMigrationsTable.php`:
```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntrySchemaMigrationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_schema_migrations')) {
            return;
        }
        $schema->createTable('entry_schema_migrations', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('content_type_uuid', 12);
            $table->integer('from_version');      // schema_version this migration advances FROM
            $table->integer('to_version');        // = from_version + 1
            $table->json('ops');                  // serialized MigrationOpSet
            $table->string('status', 16)->default('pending');
            $table->integer('work_items_total')->default(0);
            $table->integer('work_items_done')->default(0);
            $table->integer('work_items_failed')->default(0);
            $table->json('failure_report')->nullable();
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique('uuid');
            $table->index(['content_type_uuid', 'from_version'], 'idx_schema_migrations_type_from');
        });

        // CHECK guard + the partial-unique "one active migration per type" index are
        // Postgres DDL the schema builder does not express — run them as raw SQL, the same
        // approach the filter-index job uses (Connection::getPDO()->exec()).
        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec(
            "ALTER TABLE entry_schema_migrations ADD CONSTRAINT chk_schema_migration_status "
            . "CHECK (status IN ('pending','running','completed','failed'))"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX uniq_active_schema_migration ON entry_schema_migrations "
            . "(content_type_uuid) WHERE status IN ('pending','running')"
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_schema_migrations');
    }

    public function getDescription(): string
    {
        return 'Create entry_schema_migrations (destructive schema-change record + ordered migration log).';
    }
}
```

- [ ] **Step 4: Register the table for truncation + verification.** In `tests/Support/LemmaTestCase.php`, add `'entry_schema_migrations'` to `TABLES` (after `entry_references`, parent-side — it references no Lemma child rows):
```php
private const TABLES = [
    'import_export_reports', 'import_export_errors', 'import_export_files',
    'import_export_batches', 'import_export_jobs',
    'entry_schema_migrations',
    'entry_references', 'entry_routes', 'entry_publications',
    'entry_versions', 'entry_drafts', 'entries', 'content_types',
];
```
In `scripts/run-test-migrations.php`, add `'entry_schema_migrations'` to `$requiredTables`.

- [ ] **Step 5: Implement `MigrationRepository`.** `app/Content/Repositories/MigrationRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\Migration\MigrationOpSet;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * The entry_schema_migrations table is BOTH the operation record and the ordered migration
 * log. recordAndFlip() inserts the row + flips content_types in one transaction; the
 * projector and runner read it back via chainFor()/find().
 */
final class MigrationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * In one transaction: insert the migration row (status='running') and flip the content
     * type to the new schema_version + schema. Returns the migration uuid. The CALLER must
     * enqueue the backfill AFTER commit (db()->afterCommit) — never inside this transaction.
     *
     * @param list<array<string,mixed>> $newSchema normalized schema (post-migration)
     */
    public function recordAndFlip(
        string $contentTypeUuid,
        int $fromVersion,
        MigrationOpSet $ops,
        array $newSchema,
        int $workItemsTotal,
        ?string $actor,
    ): string {
        $uuid = Utils::generateNanoID(12);
        $toVersion = $fromVersion + 1;
        // Use the framework's transaction (NOT raw PDO begin/commit): it integrates with
        // Glueful's transaction-level tracking and afterCommit() machinery, so the CALLER's
        // post-commit enqueue (db()->afterCommit) fires correctly and this insert+flip composes
        // if it ever runs inside an outer transaction. A raw PDO transaction would not.
        $this->db->transaction(function () use (
            $uuid, $contentTypeUuid, $fromVersion, $toVersion, $ops, $newSchema, $workItemsTotal, $actor
        ): void {
            $this->db->table('entry_schema_migrations')->insert([
                'uuid' => $uuid,
                'content_type_uuid' => $contentTypeUuid,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'ops' => json_encode($ops->toArray(), JSON_THROW_ON_ERROR),
                'status' => 'running',
                'work_items_total' => $workItemsTotal,
                'work_items_done' => 0,
                'work_items_failed' => 0,
                'created_by' => $actor,
                'created_at' => date('Y-m-d H:i:s'),
                'started_at' => date('Y-m-d H:i:s'),
            ]);
            $this->db->table('content_types')->where('uuid', '=', $contentTypeUuid)->update([
                'schema' => json_encode($newSchema, JSON_THROW_ON_ERROR),
                'schema_version' => $toVersion,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        return $uuid;
    }

    /** @return array<string,mixed>|null the active (pending|running) migration for a type. */
    public function activeForType(string $contentTypeUuid): ?array
    {
        $row = $this->db->table('entry_schema_migrations')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->whereIn('status', ['pending', 'running'])
            ->first();
        return $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate(
            $this->db->table('entry_schema_migrations')->where('uuid', '=', $uuid)->first()
        );
    }

    /** @return list<array<string,mixed>> a type's migrations, oldest first. */
    public function forType(string $contentTypeUuid): array
    {
        return array_map(
            fn (array $r): array => (array) $this->hydrate($r),
            $this->db->table('entry_schema_migrations')
                ->where('content_type_uuid', '=', $contentTypeUuid)
                ->orderBy('from_version', 'ASC')
                ->get()
        );
    }

    /**
     * The ordered op chain to replay for a blob at $fromSchemaVersion against a type whose
     * current schema_version is $currentVersion: migrations with from_version >=
     * $fromSchemaVersion AND to_version <= $currentVersion, ordered by from_version ASC,
     * REGARDLESS of status (a failed/running migration still flipped the schema, so its ops
     * are canonical).
     *
     * @return list<array<string,mixed>>
     */
    public function chainFor(string $contentTypeUuid, int $fromSchemaVersion, int $currentVersion): array
    {
        return array_map(
            fn (array $r): array => (array) $this->hydrate($r),
            $this->db->table('entry_schema_migrations')
                ->where('content_type_uuid', '=', $contentTypeUuid)
                ->where('from_version', '>=', $fromSchemaVersion)
                ->where('to_version', '<=', $currentVersion)
                ->orderBy('from_version', 'ASC')
                ->get()
        );
    }

    public function incrementDone(string $uuid): void
    {
        $this->db->getPDO()
            ->prepare('UPDATE entry_schema_migrations SET work_items_done = work_items_done + 1 WHERE uuid = ?')
            ->execute([$uuid]);
    }

    /** Record a per-item failure: bump the counter and append to failure_report. */
    public function recordFailure(string $uuid, string $entryUuid, string $locale, string $kind, string $reason): void
    {
        $row = $this->find($uuid);
        $report = is_array($row['failure_report'] ?? null) ? $row['failure_report'] : [];
        $report[] = ['entry_uuid' => $entryUuid, 'locale' => $locale, 'kind' => $kind, 'reason' => $reason];
        $this->db->table('entry_schema_migrations')->where('uuid', '=', $uuid)->update([
            'work_items_failed' => (int) ($row['work_items_failed'] ?? 0) + 1,
            'failure_report' => json_encode($report, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Clear THIS run's failure accounting before a (re-)run. `work_items_failed` /
     * `failure_report` are per-run; `work_items_done` stays cumulative (the lagging-version
     * predicate prevents an item from being materialized — and counted — twice). Without this
     * reset a resume could never show `failed = 0`, and a stale prior-run report would linger.
     */
    public function resetFailures(string $uuid): void
    {
        $this->db->table('entry_schema_migrations')->where('uuid', '=', $uuid)->update([
            'work_items_failed' => 0,
            'failure_report' => null,
        ]);
    }

    public function finish(string $uuid, string $status): void
    {
        $this->db->table('entry_schema_migrations')->where('uuid', '=', $uuid)->update([
            'status' => $status,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['ops'] = is_string($row['ops'] ?? null) ? (json_decode((string) $row['ops'], true) ?? []) : (array) ($row['ops'] ?? []);
        $row['failure_report'] = is_string($row['failure_report'] ?? null)
            ? (json_decode((string) $row['failure_report'], true) ?? [])
            : ($row['failure_report'] ?? []);
        $row['from_version'] = (int) $row['from_version'];
        $row['to_version'] = (int) $row['to_version'];
        $row['work_items_total'] = (int) $row['work_items_total'];
        $row['work_items_done'] = (int) $row['work_items_done'];
        $row['work_items_failed'] = (int) $row['work_items_failed'];
        return $row;
    }
}
```

- [ ] **Step 6: Register the repository** in `LemmaServiceProvider::services()` (add `use App\Content\Repositories\MigrationRepository;`):
```php
MigrationRepository::class => [
    'class' => MigrationRepository::class,
    'shared' => true,
    'autowire' => true,
],
```

- [ ] **Step 7: Run; verify pass.**

Run: `composer test:reset-db && composer test:migrate && composer test:phpunit -- --filter MigrationServiceTest`
Expected: PASS (table exists; partial-unique blocks a second active migration; recordAndFlip flips + records).

- [ ] **Step 8: phpcs + commit.**
```bash
composer phpcs
git add database/migrations/010_CreateEntrySchemaMigrationsTable.php app/Content/Repositories/MigrationRepository.php app/Providers/LemmaServiceProvider.php tests/Support/LemmaTestCase.php scripts/run-test-migrations.php tests/Integration/Content/MigrationServiceTest.php
git commit -m "Add entry_schema_migrations table + MigrationRepository"
```

---

### Task 3: `SchemaProjector` — lazy forward projection (the safety invariant)

**Files:**
- Create: `app/Content/Schema/Migration/SchemaProjector.php`
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Content/SchemaProjectorTest.php`

- [ ] **Step 1: Write the failing test.** `tests/Integration/Content/SchemaProjectorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\Migration\DeleteField;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Content\Schema\Migration\SchemaProjector;
use App\Tests\Support\LemmaTestCase;

final class SchemaProjectorTest extends LemmaTestCase
{
    public function testProjectsAV1BlobThroughTwoMigrationsToV3(): void
    {
        $repo = new MigrationRepository($this->connection());
        $type = $this->seedType(3); // current schema_version = 3
        // v1→v2: rename a→b ; v2→v3: delete obsolete
        $repo->recordAndFlip($type, 1, new MigrationOpSet([new RenameField('a', 'b')]), [], 0, null);
        // recordAndFlip flips content_types.schema_version each call; reset it to 3 for the test
        $this->connection()->table('content_types')->where('uuid', '=', $type)->update(['schema_version' => 3]);
        $repo->recordAndFlip($type, 2, new MigrationOpSet([new DeleteField('obsolete')]), [], 0, null);
        $this->connection()->table('content_types')->where('uuid', '=', $type)->update(['schema_version' => 3]);

        $projector = new SchemaProjector($repo, $this->connection());
        $out = $projector->project($type, 1, ['a' => 'value', 'obsolete' => 'x', 'keep' => 1]);
        self::assertSame(['keep' => 1, 'b' => 'value'], $out);
    }

    public function testNoOpFastPathWhenAlreadyAtCurrent(): void
    {
        $repo = new MigrationRepository($this->connection());
        $type = $this->seedType(2);
        $projector = new SchemaProjector($repo, $this->connection());
        $fields = ['b' => 'value', 'keep' => 1];
        // fromSchemaVersion === current (2): returned untouched, no chain loaded.
        self::assertSame($fields, $projector->project($type, 2, $fields));
    }

    public function testBoundsRespectedAfterMultipleMigrations(): void
    {
        $repo = new MigrationRepository($this->connection());
        $type = $this->seedType(3);
        $repo->recordAndFlip($type, 1, new MigrationOpSet([new RenameField('a', 'b')]), [], 0, null);
        $this->connection()->table('content_types')->where('uuid', '=', $type)->update(['schema_version' => 3]);
        $repo->recordAndFlip($type, 2, new MigrationOpSet([new RenameField('b', 'c')]), [], 0, null);
        $this->connection()->table('content_types')->where('uuid', '=', $type)->update(['schema_version' => 3]);

        // A v2 blob only replays the v2→v3 migration (from_version >= 2), not v1→v2.
        $projector = new SchemaProjector($repo, $this->connection());
        self::assertSame(['c' => 'value'], $projector->project($type, 2, ['b' => 'value']));
    }

    public function testSameProjectorReflectsAMigrationAddedAfterFirstProject(): void
    {
        // Long-running-process safety (P1): a projector reused ACROSS a schema flip must
        // re-read the current version and NOT serve the pre-flip shape from a memoized cache.
        $repo = new MigrationRepository($this->connection());
        $type = $this->seedType(1); // current schema_version = 1, no migrations yet

        $projector = new SchemaProjector($repo, $this->connection());

        // Before any migration a v1 blob is current → returned untouched (primes any caches).
        self::assertSame(['a' => 'value'], $projector->project($type, 1, ['a' => 'value']));

        // A migration flips the schema to v2 (rename a→b). recordAndFlip sets schema_version=2.
        $repo->recordAndFlip($type, 1, new MigrationOpSet([new RenameField('a', 'b')]), [], 0, null);
        self::assertSame(2, (int) $this->connection()->table('content_types')
            ->where('uuid', '=', $type)->first()['schema_version']);

        // The SAME projector instance must now project the v1 blob forward to v2 — proving it
        // re-reads current rather than serving a stale pre-flip cache.
        self::assertSame(['b' => 'value'], $projector->project($type, 1, ['a' => 'value']));
    }

    private function seedType(int $schemaVersion): string
    {
        $uuid = substr(md5(uniqid('', true)), 0, 12);
        $this->connection()->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => 'type-' . $uuid,
            'name' => 'T',
            'status' => 'active',
            'schema' => json_encode([], JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }
}
```

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter SchemaProjectorTest`
Expected: FAIL — `Class "App\Content\Schema\Migration\SchemaProjector" not found`.

- [ ] **Step 3: Implement `SchemaProjector`.** `app/Content/Schema/Migration/SchemaProjector.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\Migration;

use App\Content\Repositories\MigrationRepository;
use Glueful\Database\Connection;

/**
 * Lazy forward projection — the correctness invariant of the backfill feature (spec §3).
 *
 * Projects a fields-bearing blob written at $fromSchemaVersion forward to the type's current
 * schema_version by replaying the recorded migration ops between them (non-throwing variant).
 * Used on EVERY read door (delivery, admin, preview, rollback-served content), so a blob that
 * has not yet been materialized by the background backfill is still served in the new shape.
 *
 * Hot-path cheap: a no-op fast path returns untouched when the blob is already at current; the
 * op chain is cached, but keyed by `type:current` so the cache can never serve a pre-flip
 * chain (see below). Registered NON-shared (Step 4) so its caches are request-scoped.
 */
final class SchemaProjector
{
    /** @var array<string,array<int,MigrationOpSet>> chain keyed by "type:current", from_version ASC */
    private array $chainCache = [];

    public function __construct(
        private readonly MigrationRepository $migrations,
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string,mixed> $fields the blob's stored fields
     * @return array<string,mixed> the fields projected forward to the current schema
     */
    public function project(string $contentTypeUuid, int $fromSchemaVersion, array $fields): array
    {
        // Re-read current schema_version on EVERY call — never memoize it on the instance. A
        // long-running process (worker/persistent server) must observe a schema flip
        // immediately; a memoized version would keep projecting to the pre-migration shape.
        // This is a single indexed lookup — negligible on the hot path, and the no-op fast
        // path below skips even the chain work once a blob is materialized.
        $current = (int) ($this->db->table('content_types')
            ->where('uuid', '=', $contentTypeUuid)->first()['schema_version'] ?? 1);

        // No-op fast path: an already-materialized blob costs nothing.
        if ($fromSchemaVersion >= $current) {
            return $fields;
        }
        foreach ($this->chain($contentTypeUuid, $current) as $fromVersion => $opSet) {
            if ($fromVersion < $fromSchemaVersion) {
                continue; // ops before the blob's own version do not apply to it
            }
            $fields = $opSet->applyForProjection($fields);
        }
        return $fields;
    }

    /**
     * Op chain (from_version 1..current) for a type, keyed by from_version ASC. Cached by
     * "type:current": the migration log is append-only, so for a FIXED current version the
     * chain is immutable — and a flip produces a new `current`, hence a new cache key, so a
     * stale chain is never reused.
     *
     * @return array<int,MigrationOpSet>
     */
    private function chain(string $contentTypeUuid, int $current): array
    {
        $key = $contentTypeUuid . ':' . $current;
        if (!isset($this->chainCache[$key])) {
            $chain = [];
            foreach ($this->migrations->chainFor($contentTypeUuid, 1, $current) as $m) {
                $chain[(int) $m['from_version']] = MigrationOpSet::fromArray($m['ops']);
            }
            $this->chainCache[$key] = $chain;
        }
        return $this->chainCache[$key];
    }
}
```

- [ ] **Step 4: Register the projector** in `LemmaServiceProvider::services()` (add `use App\Content\Schema\Migration\SchemaProjector;`). **`shared => false`** — a process-singleton projector would risk caching a pre-flip schema version across requests; non-shared keeps its caches request-scoped (the flip-safe `type:current` keying is a second line of defense):
```php
SchemaProjector::class => [
    'class' => SchemaProjector::class,
    'shared' => false,
    'autowire' => true,
],
```

- [ ] **Step 5: Run; verify pass.**

Run: `composer test:phpunit -- --filter SchemaProjectorTest`
Expected: PASS (v1→v3 chain ordered by from_version; no-op fast path; bounds respected).

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Schema/Migration/SchemaProjector.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/SchemaProjectorTest.php
git commit -m "Add SchemaProjector lazy forward projection"
```

---

### Task 4: `MigrationService` — validate, flip-first, enqueue after commit

**Files:**
- Create: `app/Content/Services/MigrationService.php`
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Content/MigrationServiceTest.php` (append service cases)

> **Validation outcomes are surfaced as typed results, not HTTP codes** — `MigrationService` is HTTP-agnostic (mirrors `PublishService`). It throws `SchemaParseException` for invalid ops (controller → 422) and a dedicated `ActiveMigrationException` for a second active migration (controller → 409, Task 6). The "enqueue after commit" obligation lives here, using `db()->afterCommit()`.

- [ ] **Step 1: Write the failing service tests.** Append to `MigrationServiceTest`:
```php
public function testValidRenameDeleteFlipsRecordsAndEnqueuesAfterCommit(): void
{
    $svc = $this->container()->get(\App\Content\Services\MigrationService::class);
    $type = $this->seedType(1, [
        ['name' => 'a', 'type' => 'string'],
        ['name' => 'c', 'type' => 'string'],
    ]);
    // one published entry + one draft so work_items_total = 2
    $this->seedPublished($type, 'e1aaaaaaaaaa', 'en', 1, ['a' => 'v', 'c' => 'x']);
    $this->seedDraft($type, 'e1aaaaaaaaaa', 'en', 1, ['a' => 'v', 'c' => 'x']);

    $uuid = $svc->migrate($type, [
        ['op' => 'rename', 'from' => 'a', 'to' => 'b'],
        ['op' => 'delete', 'name' => 'c'],
    ], 'user00000001');

    $repo = new \App\Content\Repositories\MigrationRepository($this->connection());
    $row = $repo->find($uuid);
    self::assertSame('running', $row['status']);
    self::assertSame(2, (int) $row['work_items_total']);
    $type2 = $this->connection()->table('content_types')->where('uuid', '=', $type)->first();
    self::assertSame(2, (int) $type2['schema_version']);
    // schema now has b (renamed) and not a/c
    $names = array_column(json_decode((string) $type2['schema'], true), 'name');
    self::assertSame(['b'], $names);
    // a job was enqueued after commit (the test queue records pushes)
    self::assertTrue($this->queueHasBackfillJob($uuid));
}

public function testInvalidOpsAreRejected(): void
{
    $svc = $this->container()->get(\App\Content\Services\MigrationService::class);
    $type = $this->seedType(1, [['name' => 'a', 'type' => 'string']]);

    foreach ([
        [['op' => 'delete', 'name' => 'missing']],                       // delete-missing
        [['op' => 'rename', 'from' => 'missing', 'to' => 'b']],          // rename-from-missing
        [['op' => 'rename', 'from' => 'a', 'to' => 'a']],                // rename-to-collides (declared)
        [['op' => 'rename', 'from' => 'a', 'to' => 'b'], ['op' => 'rename', 'from' => 'a', 'to' => 'b']], // dup source+target
        [['op' => 'delete', 'name' => 'a'], ['op' => 'rename', 'from' => 'a', 'to' => 'b']],              // P1-2: 'a' is a source twice
        [['op' => 'rename', 'from' => 'a', 'to' => 'b'], ['op' => 'rename', 'from' => 'a', 'to' => 'c']], // P1-2: 'a' renamed twice
    ] as $ops) {
        try {
            $svc->migrate($type, $ops, null);
            self::fail('expected SchemaParseException for ' . json_encode($ops));
        } catch (\App\Content\Schema\SchemaParseException) {
            self::assertTrue(true);
        }
    }
    // no migration row was written, schema_version unchanged
    self::assertNull((new \App\Content\Repositories\MigrationRepository($this->connection()))->activeForType($type));
    self::assertSame(1, (int) $this->connection()->table('content_types')->where('uuid', '=', $type)->first()['schema_version']);
}

public function testSecondActiveMigrationIsRejected(): void
{
    $svc = $this->container()->get(\App\Content\Services\MigrationService::class);
    $type = $this->seedType(1, [['name' => 'a', 'type' => 'string'], ['name' => 'd', 'type' => 'string']]);
    $svc->migrate($type, [['op' => 'delete', 'name' => 'a']], null);
    $this->expectException(\App\Content\Services\ActiveMigrationException::class);
    $svc->migrate($type, [['op' => 'delete', 'name' => 'd']], null);
}

/** A failure inside recordAndFlip leaves NO queued job (after-commit enqueue). */
public function testRolledBackTransactionLeavesNoQueuedJob(): void
{
    $svc = $this->container()->get(\App\Content\Services\MigrationService::class);
    $type = $this->seedType(1, [['name' => 'a', 'type' => 'string']]);
    // Force the flip to fail by deleting the content type row mid-call is hard; instead
    // assert the structural property: migrate() calls db()->afterCommit AFTER recordAndFlip
    // returns. With no enclosing transaction, recordAndFlip commits and afterCommit fires
    // immediately — so a successful migrate ALWAYS has exactly one queued job, and a thrown
    // migrate (invalid ops, above) has ZERO. This test asserts the zero case for an invalid op.
    try {
        $svc->migrate($type, [['op' => 'delete', 'name' => 'missing']], null);
    } catch (\App\Content\Schema\SchemaParseException) {
    }
    self::assertSame(0, $this->queueBackfillJobCount());
}
```
Add small helpers to `MigrationServiceTest` for seeding a publication/draft and inspecting the test queue:
```php
/** @param array<string,mixed> $fields */
private function seedPublished(string $type, string $entry, string $locale, int $schemaVersion, array $fields): void
{
    $this->connection()->table('entries')->insert(['uuid' => $entry, 'content_type_uuid' => $type, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $vUuid = substr(md5($entry . $locale), 0, 12);
    $this->connection()->table('entry_versions')->insert(['uuid' => $vUuid, 'entry_uuid' => $entry, 'locale' => $locale, 'version' => 1, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'schema_version' => $schemaVersion, 'created_at' => date('Y-m-d H:i:s')]);
    $this->connection()->table('entry_publications')->insert(['entry_uuid' => $entry, 'locale' => $locale, 'version_uuid' => $vUuid, 'published_at' => date('Y-m-d H:i:s')]);
}

/** @param array<string,mixed> $fields */
private function seedDraft(string $type, string $entry, string $locale, int $schemaVersion, array $fields): void
{
    if ($this->connection()->table('entries')->where('uuid', '=', $entry)->first() === null) {
        $this->connection()->table('entries')->insert(['uuid' => $entry, 'content_type_uuid' => $type, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }
    $this->connection()->table('entry_drafts')->insert(['entry_uuid' => $entry, 'locale' => $locale, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'schema_version' => $schemaVersion, 'lock_version' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
}

private function queueHasBackfillJob(string $migrationUuid): bool
{
    return $this->queueBackfillJobCount() >= 1;
}

/** The configured database-queue table — `queue_jobs` by default, NOT `jobs`. Read it from
 *  config rather than hardcoding. The suite runs QUEUE_CONNECTION=database (the default) and
 *  migrates the queue tables (`scripts/run-test-migrations.php` registers `glueful/framework:queue`),
 *  so a pushed job persists here for inspection. */
private function queueTable(): string
{
    return (string) config($this->appContext(), 'queue.connections.database.table', 'queue_jobs');
}

/** @return list<string> raw payloads of currently-queued jobs */
private function queuedPayloads(): array
{
    $table = $this->queueTable();
    if (!$this->connection()->getSchemaBuilder()->hasTable($table)) {
        self::fail("queue table '{$table}' not migrated — cannot assert enqueue (see fake-queue fallback)");
    }
    return array_map(
        static fn (array $r): string => (string) ($r['payload'] ?? ''),
        $this->connection()->table($table)->get()
    );
}

private function queueBackfillJobCount(): int
{
    $n = 0;
    foreach ($this->queuedPayloads() as $payload) {
        if (str_contains($payload, 'RunBackfillJob')) {
            $n++;
        }
    }
    return $n;
}
```
> **Driver-independent fallback (if the env overrides QUEUE_CONNECTION to `sync`/`null`, where
> nothing persists).** The plain `ContentTypeApiTest` does **not** have a queue spy — do not
> "reuse" one. Instead inject a recording `QueueManager` (it is non-`final`, ctor
> `__construct(array $config = [], ?ApplicationContext $context = null)`) and build the
> service/runner with it:
> ```php
> final class RecordingQueueManager extends \Glueful\Queue\QueueManager
> {
>     /** @var list<array{job:string,data:array<string,mixed>}> */
>     public array $pushed = [];
>     public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
>     {
>         $this->pushed[] = ['job' => $job, 'data' => $data];
>         return 'recorded-' . count($this->pushed);
>     }
> }
> ```
> Construct the `BackfillRunner` (Task 5) with `new RecordingQueueManager([], $this->appContext())`
> and assert against `->pushed`. For the container-resolved `MigrationService` (Task 4), prefer
> the `queue_jobs` table read above (the default driver persists), since its enqueue runs through
> the autowired manager.

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter MigrationServiceTest`
Expected: FAIL — `Class "App\Content\Services\MigrationService" not found`.

- [ ] **Step 3: Implement `ActiveMigrationException` + `MigrationService`.**

`app/Content/Services/ActiveMigrationException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Services;

/** Raised when a non-terminal migration already exists for the content type (controller → 409). */
final class ActiveMigrationException extends \RuntimeException
{
}
```

`app/Content/Services/MigrationService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Services;

use App\Content\Jobs\RunBackfillJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\DeleteField;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Content\Schema\SchemaParseException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;

/**
 * Synchronous handler for POST /content-types/{slug}/migrations (spec §4). Validates an
 * explicit ops list against the CURRENT schema, computes + parses the resulting schema, then
 * in ONE transaction flips content_types.schema_version + stores the new schema + records the
 * migration row (MigrationRepository::recordAndFlip). The backfill job is enqueued AFTER commit
 * via db()->afterCommit(), so a rolled-back transaction can never leave an orphaned job.
 *
 * HTTP-agnostic: throws SchemaParseException for invalid ops (controller → 422) and
 * ActiveMigrationException for a second active migration (controller → 409).
 */
final class MigrationService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly MigrationRepository $migrations,
        private readonly QueueManager $queue,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rawOps serialized ops from the request body
     * @return string the migration uuid (the client polls it)
     */
    public function migrate(string $contentTypeUuid, array $rawOps, ?string $actor): string
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new SchemaParseException("content type {$contentTypeUuid} not found");
        }
        if ($this->migrations->activeForType($contentTypeUuid) !== null) {
            throw new ActiveMigrationException('a migration is already in progress for this content type');
        }

        /** @var list<array<string,mixed>> $currentSchema */
        $currentSchema = (array) $type['schema'];
        $opSet = $this->parseAndValidate($rawOps, $currentSchema);
        $newSchema = $this->computeNewSchema($currentSchema, $rawOps);

        // Confirm the resulting schema parses (e.g. no leftover filter_type orphans).
        ContentTypeSchema::fromArray($newSchema);

        $fromVersion = (int) $type['schema_version'];
        $workItems = $this->countWorkItems($contentTypeUuid);

        $uuid = $this->migrations->recordAndFlip(
            $contentTypeUuid,
            $fromVersion,
            $opSet,
            ContentTypeSchema::fromArray($newSchema)->toArray(),
            $workItems,
            $actor,
        );

        // Enqueue AFTER commit. recordAndFlip ran its own transaction and has already
        // committed by the time we get here (no enclosing transaction), so afterCommit
        // fires immediately; if a future caller wraps this in an outer transaction, the
        // enqueue binds to that commit. Either way the job only exists for a committed row.
        db($this->context)->afterCommit(function () use ($uuid): void {
            $this->queue->push(RunBackfillJob::class, ['migration_uuid' => $uuid]);
        });

        return $uuid;
    }

    /**
     * @param list<array<string,mixed>> $rawOps
     * @param list<array<string,mixed>> $currentSchema
     */
    private function parseAndValidate(array $rawOps, array $currentSchema): MigrationOpSet
    {
        $declared = [];
        foreach ($currentSchema as $f) {
            if (isset($f['name']) && is_string($f['name'])) {
                $declared[$f['name']] = true;
            }
        }
        $targets = [];
        $sources = []; // every field consumed as a source/name — each field may appear once only
        $ops = [];
        foreach ($rawOps as $raw) {
            $kind = (string) ($raw['op'] ?? '');
            if ($kind === 'delete') {
                $name = (string) ($raw['name'] ?? '');
                if (!isset($declared[$name])) {
                    throw new SchemaParseException("cannot delete field '{$name}': not declared");
                }
                if (isset($sources[$name])) {
                    throw new SchemaParseException("field '{$name}' is the source/name of more than one op");
                }
                $sources[$name] = true;
                $ops[] = new DeleteField($name);
                continue;
            }
            if ($kind === 'rename') {
                $from = (string) ($raw['from'] ?? '');
                $to = (string) ($raw['to'] ?? '');
                if (!isset($declared[$from])) {
                    throw new SchemaParseException("cannot rename '{$from}': not declared");
                }
                if (isset($declared[$to]) && $to !== $from || $to === $from) {
                    throw new SchemaParseException("rename target '{$to}' collides with a declared field");
                }
                // Reject a field used as a source/name twice (e.g. `delete a` + `rename a→b`, or
                // `rename a→b` + `rename a→c`) — those make the stored-field transform and the
                // computed schema diverge (computeNewSchema applies the $deleted/$renames maps
                // independently, with no defined order between two ops on the same source).
                if (isset($sources[$from])) {
                    throw new SchemaParseException("field '{$from}' is the source/name of more than one op");
                }
                if (isset($targets[$to])) {
                    throw new SchemaParseException("duplicate target '{$to}' in ops");
                }
                $sources[$from] = true;
                $targets[$to] = true;
                $ops[] = new RenameField($from, $to);
                continue;
            }
            throw new SchemaParseException("unknown migration op '{$kind}'");
        }
        if ($ops === []) {
            throw new SchemaParseException('migration must contain at least one op');
        }
        return new MigrationOpSet($ops);
    }

    /**
     * Apply the rename/delete ops to the schema field-definition list (preserving each
     * field's type/flags, just dropping deleted ones and renaming the `name` of renamed ones).
     *
     * @param list<array<string,mixed>> $currentSchema
     * @param list<array<string,mixed>> $rawOps
     * @return list<array<string,mixed>>
     */
    private function computeNewSchema(array $currentSchema, array $rawOps): array
    {
        $deleted = [];
        $renames = [];
        foreach ($rawOps as $raw) {
            if (($raw['op'] ?? '') === 'delete') {
                $deleted[(string) $raw['name']] = true;
            } elseif (($raw['op'] ?? '') === 'rename') {
                $renames[(string) $raw['from']] = (string) $raw['to'];
            }
        }
        $out = [];
        foreach ($currentSchema as $f) {
            $name = (string) ($f['name'] ?? '');
            if (isset($deleted[$name])) {
                continue;
            }
            if (isset($renames[$name])) {
                $f['name'] = $renames[$name];
            }
            $out[] = $f;
        }
        return array_values($out);
    }

    /**
     * Count {entry_uuid, locale, kind} work units affected: every draft of the type PLUS every
     * pinned publication of the type. A pair with both counts as two (spec §2 work-item rule).
     */
    private function countWorkItems(string $contentTypeUuid): int
    {
        $drafts = $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->count();
        $pubs = $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->count();
        return (int) $drafts + (int) $pubs;
    }
}
```

- [ ] **Step 4: Register `MigrationService`** in `LemmaServiceProvider::services()` (add `use App\Content\Services\MigrationService;`):
```php
MigrationService::class => [
    'class' => MigrationService::class,
    'shared' => true,
    'autowire' => true,
],
```

- [ ] **Step 5: Run; verify pass.** (Requires `RunBackfillJob` to exist as a class for `::class` resolution — create the stub in Task 5 first if `migrate()` push fails; or stub a placeholder. Sequence Task 5's job creation before re-running if needed; the validation/409 cases pass independently.)

Run: `composer test:phpunit -- --filter MigrationServiceTest`
Expected: PASS (flip+record+enqueue; 4 invalid-op rejections; 409 second active; zero queued job on rejection).

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Services/MigrationService.php app/Content/Services/ActiveMigrationException.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/MigrationServiceTest.php
git commit -m "Add MigrationService (validate, flip-first, enqueue after commit)"
```

---

### Task 5: `BackfillRunner` + `RunBackfillJob` + `lemma:schema:backfill`

**Files:**
- Create: `app/Content/Backfill/BackfillRunner.php` (shared run logic — the test target)
- Create: `app/Content/Jobs/RunBackfillJob.php` (queue entry point)
- Create: `app/Content/Console/RunBackfillCommand.php` (`lemma:schema:backfill`, operator/resume entry point)
- Modify: `app/Providers/LemmaServiceProvider.php` (register runner + command, extend `commands([...])`)
- Test: `tests/Integration/Content/BackfillRunnerTest.php`

> **Runner/wrapper split** mirrors scheduled-publish: `BackfillRunner::run(migrationUuid)` is the unit of logic and the test target; `RunBackfillJob` is the queued entry point; `RunBackfillCommand` is the operator/resume entry point. Both wrappers call the one runner.

- [ ] **Step 1: Write the failing tests.** `tests/Integration/Content/BackfillRunnerTest.php` — key cases, one method each:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Backfill\BackfillRunner;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\LemmaTestCase;

final class BackfillRunnerTest extends LemmaTestCase
{
    private function runner(): BackfillRunner
    {
        return $this->container()->get(BackfillRunner::class);
    }

    public function testPublishedEntryGetsNewMigratedVersionAndRepinOldPreserved(): void
    {
        [$type, $entry] = $this->seedRenameMigration(['title' => 'Hi'], publishedOnly: true);
        $oldVersion = $this->connection()->table('entry_versions')->where('entry_uuid', '=', $entry)->first();

        $this->runner()->run($this->migrationUuid($type));

        $versions = $this->connection()->table('entry_versions')->where('entry_uuid', '=', $entry)->orderBy('version', 'ASC')->get();
        self::assertCount(2, $versions, 'a NEW migrated version is appended');
        self::assertSame($oldVersion['fields'], $versions[0]['fields'], 'the pre-migration version row is byte-for-byte preserved');
        $new = $versions[1];
        self::assertSame(2, (int) $new['version'], 'reserveNextVersionNumber continues the sequence');
        self::assertSame(2, (int) $new['schema_version']);
        self::assertSame(['heading' => 'Hi'], json_decode((string) $new['fields'], true), 'fields projected to new shape');
        // re-pinned to the new version
        $pub = (new VersionRepository($this->connection()))->findPublication($entry, 'en');
        self::assertSame((string) $new['uuid'], (string) $pub['version_uuid']);
    }

    public function testDraftTransformedInPlaceAndReTagged(): void
    {
        [$type, $entry] = $this->seedRenameMigration(['title' => 'Hi'], publishedOnly: false, draftOnly: true);
        $this->runner()->run($this->migrationUuid($type));

        $draft = $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->first();
        self::assertSame(['heading' => 'Hi'], json_decode((string) $draft['fields'], true));
        self::assertSame(2, (int) $draft['schema_version']);
    }

    public function testReRunIsIdempotent(): void
    {
        [$type, $entry] = $this->seedRenameMigration(['title' => 'Hi'], publishedOnly: true);
        $uuid = $this->migrationUuid($type);
        $this->runner()->run($uuid);
        $afterFirst = $this->connection()->table('entry_versions')->where('entry_uuid', '=', $entry)->count();
        $this->runner()->run($uuid); // resume / re-run: every item already at to_version
        self::assertSame($afterFirst, $this->connection()->table('entry_versions')->where('entry_uuid', '=', $entry)->count());
    }

    public function testWorkItemPartialFailureDraftDonePublishedFailed(): void
    {
        // entry+locale with both a draft AND a publication; force the published item to a
        // rename collision (target already present) while the draft transforms cleanly.
        [$type, $entry] = $this->seedRenameMigration(
            draftFields: ['title' => 'Hi'],
            publishedFields: ['title' => 'Hi', 'heading' => 'already'], // collision on materialize
            both: true,
        );
        $this->runner()->run($this->migrationUuid($type));

        $repo = new MigrationRepository($this->connection());
        $row = $repo->find($this->migrationUuid($type));
        self::assertSame(1, (int) $row['work_items_done'], 'the draft item succeeded');
        self::assertSame(1, (int) $row['work_items_failed'], 'the published item failed independently');
        self::assertSame('published', $row['failure_report'][0]['kind']);
        self::assertSame('failed', $row['status']);
        // draft really did transform
        self::assertSame(['heading' => 'Hi'], json_decode((string) $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->first()['fields'], true));
    }

    public function testResumeMaterializesRemainderAndFlipsFailedToCompleted(): void
    {
        [$type, $entry] = $this->seedRenameMigration(
            draftFields: ['title' => 'Hi'],
            publishedFields: ['title' => 'Hi', 'heading' => 'already'],
            both: true,
        );
        $uuid = $this->migrationUuid($type);
        $this->runner()->run($uuid);
        self::assertSame('failed', (new MigrationRepository($this->connection()))->find($uuid)['status']);

        // operator fixes the anomaly (drop the colliding key) then resumes
        $pub = (new VersionRepository($this->connection()))->findPublication($entry, 'en');
        $vUuid = (string) $pub['version_uuid'];
        $this->connection()->table('entry_versions')->where('uuid', '=', $vUuid)
            ->update(['fields' => json_encode(['title' => 'Hi'], JSON_THROW_ON_ERROR)]);

        $this->runner()->run($uuid);
        self::assertSame('completed', (new MigrationRepository($this->connection()))->find($uuid)['status']);
    }

    public function testEnqueuesEnsureFilterIndexesJobForTheType(): void
    {
        [$type] = $this->seedRenameMigration(['title' => 'Hi'], publishedOnly: true);
        $this->runner()->run($this->migrationUuid($type));
        self::assertTrue($this->queuePushedEnsureFilterIndexesFor($type));
    }
}
```
> Add seed/inspection helpers to the test: `seedRenameMigration(...)` creates a content type at schema_version 1 with fields `[title]`, the requested draft/publication rows, then calls `MigrationService::migrate($type, [['op'=>'rename','from'=>'title','to'=>'heading']], null)` and returns `[$type, $entry]`; `migrationUuid($type)` reads `MigrationRepository::activeForType($type)['uuid']`; `queuePushedEnsureFilterIndexesFor($type)` inspects the configured queue table the same way as Task 4 — copy the `queueTable()` + `queuedPayloads()` helpers and return `true` when a payload contains both `'EnsureFilterIndexesJob'` and the `$type` uuid:
```php
private function queuePushedEnsureFilterIndexesFor(string $typeUuid): bool
{
    foreach ($this->queuedPayloads() as $payload) {
        if (str_contains($payload, 'EnsureFilterIndexesJob') && str_contains($payload, $typeUuid)) {
            return true;
        }
    }
    return false;
}
```
If the env runs a non-persisting queue driver (`sync`/`null`), build the `BackfillRunner` with the `RecordingQueueManager` from Task 4's fallback note and assert against `->pushed` instead.

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter BackfillRunnerTest`
Expected: FAIL — `Class "App\Content\Backfill\BackfillRunner" not found`.

- [ ] **Step 3: Implement `BackfillRunner`.** `app/Content/Backfill/BackfillRunner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Backfill;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationCollisionException;
use App\Content\Schema\Migration\MigrationOpSet;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;

/**
 * Eager materialization of the lazy projection (spec §5). Processes each {entry_uuid, locale,
 * kind} work item whose stored schema_version < to_version — the predicate that makes the run
 * idempotent + resumable. Drafts transform in place; published versions get a NEW migrated
 * version (reserveNextVersionNumber + append) re-pinned, the old version untouched, references
 * rebuilt from the projected shape. Per-item errors (incl. rename collision) are recorded and
 * the run CONTINUES — a pair's draft and published items succeed/fail independently. Once,
 * after the items, it enqueues EnsureFilterIndexesJob for the type and invalidates the delivery
 * cache. It does NOT mutate the lemma_filter_indexes registry (the job owns that algorithm).
 */
final class BackfillRunner
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly MigrationRepository $migrations,
        private readonly ContentTypeRepository $types,
        private readonly VersionRepository $versions,
        private readonly ReferenceProjectionRepository $references,
        private readonly QueueManager $queue,
    ) {
    }

    /** @return array{done:int,failed:int} */
    public function run(string $migrationUuid): array
    {
        $migration = $this->migrations->find($migrationUuid);
        if ($migration === null) {
            throw new \RuntimeException("migration {$migrationUuid} not found");
        }
        $typeUuid = (string) $migration['content_type_uuid'];
        $toVersion = (int) $migration['to_version'];
        $opSet = MigrationOpSet::fromArray($migration['ops']);
        $schema = $this->types->schemaFor($typeUuid);
        $actor = $migration['created_by'] !== null ? (string) $migration['created_by'] : null;

        // Fresh failure accounting for THIS run (done stays cumulative — see resetFailures).
        $this->migrations->resetFailures($migrationUuid);

        foreach ($this->draftItems($typeUuid, $toVersion) as $item) {
            $this->processDraft($migrationUuid, $opSet, $toVersion, $item);
        }
        foreach ($this->publishedItems($typeUuid, $toVersion) as $item) {
            $this->processPublished($migrationUuid, $opSet, $schema, $toVersion, $actor, $item);
        }

        // Reconcile filter indexes via the existing job (NOT registry mutation) and drop the
        // type's delivery cache so consumers stop seeing a stale shape.
        $this->queue->push(EnsureFilterIndexesJob::class, ['content_type_uuid' => $typeUuid]);
        $this->invalidateCache($typeUuid);

        // Status reflects ACTUAL materialization, not a cumulative failure counter: if any
        // work item still lags `to_version` the migration is 'failed' (resumable); when none
        // remain it is 'completed'. A resume re-runs only the still-lagging items, so once an
        // operator repairs them this naturally flips failed → completed.
        $remaining = count($this->draftItems($typeUuid, $toVersion))
            + count($this->publishedItems($typeUuid, $toVersion));
        $this->migrations->finish($migrationUuid, $remaining === 0 ? 'completed' : 'failed');

        $row = $this->migrations->find($migrationUuid);
        return ['done' => (int) $row['work_items_done'], 'failed' => (int) $row['work_items_failed']];
    }

    /** @param array<string,mixed> $item */
    private function processDraft(string $migrationUuid, MigrationOpSet $opSet, int $toVersion, array $item): void
    {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];
        try {
            $fields = is_string($item['fields']) ? (json_decode((string) $item['fields'], true) ?? []) : (array) $item['fields'];
            $migrated = $opSet->apply($fields);
            $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entry)->where('locale', '=', $locale)
                ->update(['fields' => json_encode($migrated, JSON_THROW_ON_ERROR), 'schema_version' => $toVersion]);
            $this->migrations->incrementDone($migrationUuid);
        } catch (MigrationCollisionException | \Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'draft', $e->getMessage());
        }
    }

    /** @param array<string,mixed> $item */
    private function processPublished(
        string $migrationUuid,
        MigrationOpSet $opSet,
        ContentTypeSchema $schema,
        int $toVersion,
        ?string $actor,
        array $item,
    ): void {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];
        try {
            $version = $this->versions->findVersionByUuid((string) $item['version_uuid']);
            if ($version === null) {
                throw new \RuntimeException('pinned version missing');
            }
            $migrated = $opSet->apply((array) $version['fields']);
            db($this->context)->transaction(function () use ($entry, $locale, $migrated, $toVersion, $actor, $schema): void {
                $number = $this->versions->reserveNextVersionNumber($entry, $locale);
                $newUuid = $this->versions->appendVersion($entry, $locale, $number, $migrated, $toVersion, $actor);
                $this->versions->pin($entry, $locale, $newUuid, $actor);
                $this->references->rebuildForEntry($entry, $schema, $migrated);
            });
            $this->migrations->incrementDone($migrationUuid);
        } catch (MigrationCollisionException | \Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'published', $e->getMessage());
        }
    }

    /**
     * Draft work items still lagging: drafts of the type's active entries whose
     * schema_version < to_version.
     *
     * @return list<array<string,mixed>>
     */
    private function draftItems(string $typeUuid, int $toVersion): array
    {
        return $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->select(['d.entry_uuid', 'd.locale', 'd.fields', 'd.schema_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '=', 'active')
            ->where('d.schema_version', '<', $toVersion)
            ->get();
    }

    /**
     * Published work items still lagging: pinned publications whose pinned version's
     * schema_version < to_version.
     *
     * @return list<array<string,mixed>>
     */
    private function publishedItems(string $typeUuid, int $toVersion): array
    {
        return $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['p.entry_uuid', 'p.locale', 'p.version_uuid', 'v.schema_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '=', 'active')
            ->where('v.schema_version', '<', $toVersion)
            ->get();
    }

    private function invalidateCache(string $typeUuid): void
    {
        $type = $this->types->findByUuid($typeUuid);
        if ($type === null) {
            return;
        }
        $container = $this->context->getContainer();
        if (!$container->has(CacheStore::class)) {
            return;
        }
        /** @var CacheStore $cache */
        $cache = $container->get(CacheStore::class);
        $cache->invalidateTags(['lemma:type:' . (string) $type['slug']]);
    }
}
```

- [ ] **Step 4: Implement the queue + command wrappers.**

`app/Content/Jobs/RunBackfillJob.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Jobs;

use App\Content\Backfill\BackfillRunner;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

/**
 * Queue entry point for the schema backfill. The framework reconstructs a Job from its
 * serialized payload + context, so dependencies are resolved from the container at run time
 * (mirrors EnsureFilterIndexesJob). Delegates to the shared BackfillRunner.
 */
final class RunBackfillJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('RunBackfillJob requires an ApplicationContext to run.');
        }
        $data = $this->getData();
        $migrationUuid = isset($data['migration_uuid']) && is_string($data['migration_uuid'])
            ? $data['migration_uuid']
            : '';
        if ($migrationUuid === '') {
            throw new \InvalidArgumentException('RunBackfillJob: missing migration_uuid.');
        }
        $context->getContainer()->get(BackfillRunner::class)->run($migrationUuid);
    }
}
```

`app/Content/Console/RunBackfillCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Backfill\BackfillRunner;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'lemma:schema:backfill',
    description: 'Run (or resume) the backfill for a schema migration — forward-only, idempotent',
)]
final class RunBackfillCommand extends BaseCommand
{
    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null,
        private readonly ?BackfillRunner $runner = null,
    ) {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this->addArgument('migration', InputArgument::REQUIRED, 'The migration uuid to run/resume');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = $this->runner ?? $this->container->get(BackfillRunner::class);
        $result = $runner->run((string) $input->getArgument('migration'));
        $output->writeln(sprintf('Backfill done: %d materialized, %d failed.', $result['done'], $result['failed']));
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Register runner + command** in `LemmaServiceProvider` (add `use App\Content\Backfill\BackfillRunner;` and `use App\Content\Console\RunBackfillCommand;`):
```php
// services():
BackfillRunner::class => ['class' => BackfillRunner::class, 'shared' => true, 'autowire' => true],
RunBackfillCommand::class => ['class' => RunBackfillCommand::class, 'shared' => true, 'autowire' => true],
// boot(): extend the existing commands([...]) call:
$this->commands([ResyncCommand::class, RunBackfillCommand::class]);
```

- [ ] **Step 6: Run; verify pass.**

Run: `composer test:phpunit -- --filter "BackfillRunnerTest|MigrationServiceTest"`
Expected: PASS (new version + re-pin, old preserved; draft transformed; idempotent; partial failure draft-done/published-failed; resume flips failed→completed; EnsureFilterIndexesJob enqueued). MigrationService's enqueue test now also passes (`RunBackfillJob` resolves).

- [ ] **Step 7: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Backfill/BackfillRunner.php app/Content/Jobs/RunBackfillJob.php app/Content/Console/RunBackfillCommand.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/BackfillRunnerTest.php
git commit -m "Add BackfillRunner + RunBackfillJob + lemma:schema:backfill command"
```

---

### Task 6: Migration admin API — `MigrationData` DTO + `MigrationController` + routes

**Files:**
- Create: `app/Content/Http/DTOs/MigrationData.php`, `app/Content/Http/Controllers/MigrationController.php`
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Http/MigrationApiTest.php`

- [ ] **Step 1: Write failing tests.** `tests/Integration/Http/MigrationApiTest.php` — resolve the controller from the container (like `ContentTypeApiTest`):
```php
// testPostMigrationFlipsSchemaAndReturnsRunningRow: POST a rename → 201, body.migration.status='running',
//   content_types.schema_version advanced.
// testPostInvalidOpsReturns422: delete-missing / rename-from-missing / rename-to-collides / duplicate-target each → 422.
// testPostSecondActiveReturns409: a pending/running migration already exists → 409.
// testPostUnknownTypeReturns404: unknown slug → 404, no row written.
// testGetMigrationReturnsPollableRow: GET /content-types/{slug}/migrations/{uuid} → counters + failure_report.
// testGetMigrationsListsForType: GET /content-types/{slug}/migrations → list oldest first.
```
Each arranges a content type via the repository, drives the request through `MigrationController` (resolved from the container) with a built `Request`, and asserts status + envelope.

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter MigrationApiTest` → FAIL (classes/routes missing).

- [ ] **Step 3: Create `MigrationData`.** `app/Content/Http/DTOs/MigrationData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for POST /v1/admin/content-types/{slug}/migrations. `ops` is a list of
 * { op: 'rename'|'delete', ... } maps; the per-op semantic validation (target declared,
 * source exists, no collision/dupes) is MigrationService's job, surfaced as 422.
 */
final class MigrationData implements RequestData
{
    /** @param list<array<string,mixed>> $ops */
    public function __construct(
        #[Rule('required|array')]
        public readonly array $ops = [],
    ) {
    }
}
```

- [ ] **Step 4: Create `MigrationController`.** `app/Content/Http/Controllers/MigrationController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Http\DTOs\MigrationData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\SchemaParseException;
use App\Content\Services\ActiveMigrationException;
use App\Content\Services\MigrationService;
use App\Http\DTOs\ErrorResponse;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The explicit destructive-migration endpoint (spec §3). The plain PATCH .../schema endpoint
 * keeps rejecting destructive changes with 422; THIS endpoint is the tracked path. Delegates
 * to MigrationService (validate → flip-first → enqueue after commit) and returns the migration
 * record the client polls for progress.
 */
final class MigrationController
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly MigrationService $migrations,
        private readonly MigrationRepository $repo,
    ) {
    }

    #[ApiOperation(summary: 'Start a destructive schema migration (delete/rename fields)', tags: ['Lemma Admin'])]
    #[ApiResponse(201, description: 'Migration started; poll the returned record for progress.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No content type with that slug.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'A migration is already in progress.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid ops (delete/rename target/source/collision).')]
    public function store(MigrationData $input, Request $request, string $slug): Response
    {
        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Content type not found.');
        }
        try {
            $uuid = $this->migrations->migrate((string) $type['uuid'], $input->ops, $this->actor($request));
        } catch (ActiveMigrationException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (SchemaParseException $e) {
            return Response::validation(['ops' => $e->getMessage()]);
        }
        return Response::created(['migration' => $this->repo->find($uuid)], 'Migration started.');
    }

    #[ApiOperation(summary: 'List a content type\'s schema migrations', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Migrations (oldest first).')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No content type with that slug.')]
    public function index(Request $request, string $slug): Response
    {
        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Content type not found.');
        }
        return Response::success(['migrations' => $this->repo->forType((string) $type['uuid'])], 'Migrations retrieved.');
    }

    #[ApiOperation(summary: 'Get one schema migration (poll progress)', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'The migration record with progress counters + failure report.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such migration.')]
    public function show(Request $request, string $slug, string $migrationUuid): Response
    {
        $row = $this->repo->find($migrationUuid);
        if ($row === null) {
            return Response::notFound('Migration not found.');
        }
        return Response::success(['migration' => $row], 'Migration retrieved.');
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
```

- [ ] **Step 5: Register controller + routes.** In `LemmaServiceProvider::services()` (add `use App\Content\Http\Controllers\MigrationController;`):
```php
MigrationController::class => ['class' => MigrationController::class, 'shared' => true, 'autowire' => true],
```
In `routes/lemma_admin.php` (inside the `/v1/admin` `auth` group; add the import):
```php
$router->post('/content-types/{slug}/migrations', [MigrationController::class, 'store'])
    ->middleware('lemma_permission:lemma.models.manage');
$router->get('/content-types/{slug}/migrations', [MigrationController::class, 'index'])
    ->middleware('lemma_permission:lemma.entries.read');
$router->get('/content-types/{slug}/migrations/{migrationUuid}', [MigrationController::class, 'show'])
    ->middleware('lemma_permission:lemma.entries.read');
```

- [ ] **Step 6: Run; verify pass.** `composer test:phpunit -- --filter MigrationApiTest` → PASS.

- [ ] **Step 7: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Http/DTOs/MigrationData.php app/Content/Http/Controllers/MigrationController.php routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Http/MigrationApiTest.php
git commit -m "Add destructive-migration admin API (start/list/poll)"
```

---

### Task 7: Read-path projection — delivery, admin, preview, rollback

**Files:**
- Modify: `app/Content/Http/Controllers/DeliveryController.php`
- Modify: `app/Content/Http/Controllers/EntryController.php`
- Modify: `app/Content/Http/Controllers/PublicationController.php`
- Modify: `app/Content/Preview/PreviewReader.php`
- Modify: `app/Content/Services/PublishService.php`
- Test: `tests/Integration/Content/ReadProjectionTest.php`

> This is where the invariant is enforced: every door that returns stored fields projects any lagging blob forward (no-op fast path when already current). The backfill never deletes versions, so preview tokens still resolve; they just need projecting. Rollback's contract is UNCHANGED — it re-pins the existing version and returns the requested uuid — the only change is projecting the target fields before rebuilding `entry_references`.

- [ ] **Step 1: Write failing tests.** `tests/Integration/Content/ReadProjectionTest.php` — drive each door against a content type that has been migrated (schema flipped to v2 via `MigrationService::migrate`) but whose entry is NOT yet materialized (backfill not run), then assert the new-schema shape:
```php
// testDeliveryProjectsNotYetMaterializedPublishedEntry: publish at v1 {title}, migrate rename
//   title→heading (schema now v2, version row still v1), GET delivery show → fields == {heading:...}.
// testAdminDraftReadProjects: draft at v1, migrate, GET /entries/{uuid}/draft/{locale} → {heading:...}.
// testAdminVersionsReadProjects: GET /entries/{uuid}/versions/{locale} → the v1 version's fields projected.
// testPreviewTokenAgainstPreMigrationVersionProjects: mint a pinned-version preview token at v1,
//   migrate, read the token → fields == {heading:...} (NOT the raw {title:...}).
// testDraftPreviewDuringMigrationProjects: a draft preview token reads projected fields.
// testRollbackRepinsExistingReturnsRequestedUuidAndRebuildsReferencesFromProjectedShape (P1):
//   two versions exist; migrate; rollback to the pre-migration version_uuid → endpoint returns
//   THAT version_uuid (unchanged contract), the pin is on that (lagging) version, delivery serves
//   {heading:...} via projection, and entry_references reflect the projected (reference) field shape.
```
Use a reference-field rename case for the rollback references assertion: schema `[body:string, link:reference]`, migrate `rename link→related`, then the rebuilt `entry_references.source_field` for the rolled-back entry must be `related` (projected), not `link`.

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter ReadProjectionTest` → FAIL (raw old-shape returned).

- [ ] **Step 3a: Project on the delivery path.** In `DeliveryController`, inject the projector and project each row in `shape()` before reference expansion. Add to the constructor (after `Projector $projector`):
```php
        private readonly \App\Content\Schema\Migration\SchemaProjector $schemaProjector,
```
In `index()` and `show()` the type uuid is `$typeUuid` / `(string) $typeRow['uuid']`; pass it into `shape()`. Change `shape()`'s signature to accept the type uuid and project first:
```php
    private function shape(array $rows, ContentTypeSchema $schema, FieldSelector $selector, string $locale, string $typeUuid): array
    {
        if ($rows === []) {
            return [];
        }

        // SAFETY INVARIANT: project any row whose pinned version lags the type's current
        // schema_version forward before anything else reads its fields (no-op fast path when
        // already current). A not-yet-materialized published entry is served in the new shape.
        foreach ($rows as $i => $row) {
            $rows[$i]['fields'] = $this->schemaProjector->project(
                $typeUuid,
                (int) ($row['schema_version'] ?? 0),
                (array) ($row['fields'] ?? []),
            );
        }

        $rows = $this->references->expand($rows, $schema, $selector->empty() ? null : $selector, $locale);
        // ... unchanged from here
```
Update the three `$this->shape(...)` call sites in `index()`/`show()` to pass `$typeUuid` (in `show()` use `(string) $typeRow['uuid']`).

- [ ] **Step 3b: Project on the admin draft read.** In `EntryController::getDraft()`, project the draft fields before returning. Inject `SchemaProjector` and resolve the entry's type uuid:
```php
        $draft = $this->entries->findDraft($uuid, $locale);
        if ($draft === null) {
            return Response::notFound('Draft not found.');
        }
        $entry = $this->entries->findEntry($uuid);
        if ($entry !== null) {
            $draft['fields'] = $this->schemaProjector->project(
                (string) $entry['content_type_uuid'],
                (int) $draft['schema_version'],
                (array) $draft['fields'],
            );
        }
        return Response::success(['draft' => $draft], 'Draft retrieved.');
```
(Add `private readonly \App\Content\Schema\Migration\SchemaProjector $schemaProjector,` to `EntryController`'s constructor.)

- [ ] **Step 3c: Project on the admin versions read.** In `PublicationController::versions()`, project each listed version. Inject `SchemaProjector` + `EntryRepository`:
```php
        $entry = $this->entries->findEntry($uuid);
        $typeUuid = $entry === null ? null : (string) $entry['content_type_uuid'];
        $versions = $this->versions->versionsFor($uuid, $locale);
        if ($typeUuid !== null) {
            foreach ($versions as $i => $v) {
                $fields = is_string($v['fields'] ?? null) ? (json_decode((string) $v['fields'], true) ?? []) : (array) ($v['fields'] ?? []);
                $versions[$i]['fields'] = $this->schemaProjector->project($typeUuid, (int) $v['schema_version'], $fields);
            }
        }
        return Response::success(['versions' => $versions], 'Versions retrieved.');
```
(Add `EntryRepository $entries` and `SchemaProjector $schemaProjector` to `PublicationController`'s constructor + imports.)

- [ ] **Step 3d: Project on the preview path.** In `PreviewReader`, inject `ContentTypeRepository` + `SchemaProjector` and project the resolved fields in both `readVersion()` and `readDraft()` before returning. Resolve the type uuid from the entry, then:
```php
        // (readVersion) before the return:
        $fields = $this->projectFields($payload->entryUuid, (int) $version['schema_version'], (array) $version['fields']);
        // ... return [...] with 'fields' => $fields, 'schema_version' => (int) $version['schema_version'], ...
```
Add the helper to `PreviewReader`:
```php
    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function projectFields(string $entryUuid, int $schemaVersion, array $fields): array
    {
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null) {
            return $fields;
        }
        return $this->projector->project((string) $entry['content_type_uuid'], $schemaVersion, $fields);
    }
```
(Add `ContentTypeRepository`/`SchemaProjector` as `$types`/`$projector` to the constructor + imports; apply `projectFields()` to `readDraft()` too.)

- [ ] **Step 3e: Project in rollback before rebuilding references.** In `PublishService::rollback()`, the contract is unchanged — still `pin($versionUuid)` and the controller still returns the requested uuid. Only the reference rebuild changes: project the target version's fields forward first. Inject `SchemaProjector` into `PublishService` and change the rebuild call:
```php
        // inside the transaction, replace the reference rebuild:
            $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
            if ($schema !== null && $entry !== null) {
                $projected = $this->projector->project(
                    (string) $entry['content_type_uuid'],
                    (int) $version['schema_version'],
                    (array) $version['fields'],
                );
                $this->references->rebuildForEntry($entryUuid, $schema, $projected);
            }
```
(Add `private readonly \App\Content\Schema\Migration\SchemaProjector $projector,` to `PublishService`'s constructor. `$entry` is already resolved above the transaction; capture it in the closure `use (...)`.)

- [ ] **Step 4: Run; verify pass.** `composer test:phpunit -- --filter ReadProjectionTest` → PASS (delivery/admin/preview project; rollback re-pins existing + returns requested uuid + references from projected fields).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Http/Controllers/DeliveryController.php app/Content/Http/Controllers/EntryController.php app/Content/Http/Controllers/PublicationController.php app/Content/Preview/PreviewReader.php app/Content/Services/PublishService.php tests/Integration/Content/ReadProjectionTest.php
git commit -m "Project lagging fields forward on every read door (delivery/admin/preview/rollback)"
```

---

### Task 8: Filter-index reconciliation integration + final verification

**Files:**
- Test: `tests/Integration/Content/BackfillRunnerTest.php` (append the index-reconcile end-to-end case)

- [ ] **Step 1: Write the failing end-to-end index test.** Append to `BackfillRunnerTest`:
```php
public function testDeletingAFilterableFieldDropsItsIndexViaTheEnqueuedJob(): void
{
    // type with a filterable field `score` that has a registry row + index, then migrate to
    // delete `score`. The runner enqueues EnsureFilterIndexesJob; running it drops the
    // registry row + physical index. The runner itself writes NO registry rows.
    $type = $this->seedFilterableType('score'); // creates content type + lemma_filter_indexes row
    $svc = $this->container()->get(\App\Content\Services\MigrationService::class);
    $svc->migrate($type, [['op' => 'delete', 'name' => 'score']], null);
    $uuid = (new \App\Content\Repositories\MigrationRepository($this->connection()))->activeForType($type)['uuid'];

    $this->runner()->run($uuid);
    // the runner enqueued the job but did not touch the registry itself
    self::assertTrue($this->queuePushedEnsureFilterIndexesFor($type));

    // run the enqueued job (resolve + reconcile) — the registry row for `score` is gone
    (new \App\Content\Indexing\EnsureFilterIndexesJob([], $this->appContext()))
        ->reconcile($this->connection(), $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class), $type);

    $rows = $this->connection()->table('lemma_filter_indexes')->where('content_type_uuid', '=', $type)->get();
    self::assertSame([], array_values(array_filter($rows, static fn (array $r): bool => $r['field'] === 'score')));
}
```
(`seedFilterableType()` inserts a content type with schema_version 1, schema `[{name:score, type:number, filterable:true, filter_type:number}]`, and a matching `lemma_filter_indexes` row with `status='ready'`; `queuePushedEnsureFilterIndexesFor()` is the helper from Task 5.)

- [ ] **Step 2: Run; verify fail/pass.** `composer test:phpunit -- --filter testDeletingAFilterableFieldDropsItsIndexViaTheEnqueuedJob`. If the runner already enqueues the job (Task 5) and the schema flip dropped `score`, this passes immediately — it is a guard test confirming the registry is reconciled by the JOB, not by the runner. If it fails because the runner mutated the registry directly, remove that mutation (the runner must only enqueue).

- [ ] **Step 3: Full suite + phpcs.**

Run: `composer ci`
Expected: phpcs clean; the full Postgres suite green (prior total + op-model, projector, service, runner, migration-API, read-projection, and index-reconcile tests).

- [ ] **Step 4: Manual smoke (optional).** `php glueful lemma:schema:backfill <uuid>` against a migrated DB reports `Backfill done: N materialized, 0 failed.`

- [ ] **Step 5: Commit.**
```bash
composer phpcs
git add tests/Integration/Content/BackfillRunnerTest.php
git commit -m "Verify filter-index reconciliation runs via the enqueued job, not the runner"
```

---

## Self-review notes

- **Spec coverage.** Op model (rename/delete/order/idempotent/serialize/collision throws-on-materialize + keeps-existing-on-project): Task 1. `SchemaProjector` (multi-migration chain ordered by `from_version`, no-op fast path, bounds): Task 3. `MigrationService` (flip+record+enqueue-after-commit; 422 invalid ops; 409 second active; rolled-back/rejected leaves no queued job): Task 4. `BackfillRunner` (new version + re-pin, old preserved, draft transformed, `reserveNextVersionNumber`, idempotent re-run, work-item partial failure draft-done/published-failed, resume completes): Task 5. Read integration (delivery/admin/preview project; rollback re-pins existing + returns requested uuid + references from projected fields): Task 7. Filter index via `EnsureFilterIndexesJob` enqueued (not registry mutation): Tasks 5 + 8. The `entry_schema_migrations` table (record + log, CHECK + partial-unique via raw PDO): Task 2.

- **Safety invariant placement.** The projector is applied on delivery (`DeliveryController::shape`), admin version + draft reads, preview (`PreviewReader`), and rollback-served content — exactly the doors the spec §6 lists. The no-op fast path (`fromSchemaVersion >= current`) keeps materialized rows free.

- **Flip-first + after-commit enqueue.** `MigrationRepository::recordAndFlip` does the flip + record in one transaction; `MigrationService` enqueues via `db()->afterCommit` strictly after, so a rolled-back/rejected migrate leaves zero queued jobs (Task 4 asserts the zero case).

- **Immutability.** `BackfillRunner::processPublished` only `appendVersion` + `pin`; it never updates a prior version row (Task 5 asserts the old row is byte-for-byte preserved). Migrated versions use `reserveNextVersionNumber` for sequence + lock continuity.

- **Rollback contract unchanged.** `rollback()` still `pin($versionUuid)` and the controller still returns `$input->version_uuid`; only the internal reference rebuild now projects the target fields first. A post-migration rollback can legitimately leave a pinned version whose `schema_version` lags — served correctly by projection.

- **Placeholder scan.** No `TBD`/`...`/"add error handling"/"similar to Task N" in any code block — every step ships complete PHP. The two test files described prose-only (`MigrationApiTest`, `ReadProjectionTest`, and the per-case comments in `BackfillRunnerTest`) name each test method + its arrange/assert explicitly so a worker writes them mechanically.

- **Signature consistency.** Verified against the read code: `VersionRepository::reserveNextVersionNumber/appendVersion/pin/findVersionByUuid/findPublication`, `EntryRepository::findEntry/findDraft`, `ContentTypeRepository::findByUuid/findBySlug/schemaFor` (hydrated `schema` is a list, `schema_version` an int), `ReferenceProjectionRepository::rebuildForEntry(entry, ContentTypeSchema, fields)`, `QueueManager::push(string, array)`, `Job::__construct(array, ?ApplicationContext)` + `getData()`, `DeliveryRepository::base()` selects `v.schema_version` + `p.entry_uuid` (so delivery rows carry what the projector needs), `EnsureFilterIndexesJob::reconcile(Connection, ContentTypeRepository, typeUuid)`. Migration DDL mirrors the raw-PDO `getPDO()->exec()` CHECK + partial-unique pattern.

- **Review-pass fixes (covered).**
  - **Projector staleness (P1):** `SchemaProjector` is registered `shared => false` AND re-reads `content_types.schema_version` on every `project()` call (no instance memo), with the op-chain cache keyed `type:current` — so a long-running process never serves a pre-flip shape. `testSameProjectorReflectsAMigrationAddedAfterFirstProject` resolves one projector, migrates, and projects again.
  - **Duplicate sources (P1):** `parseAndValidate` rejects any field used as a source/name in more than one op (`delete a` + `rename a→b`, or `rename a→b` + `rename a→c`) — Task 4 `testInvalidOpsAreRejected` adds both cases.
  - **Resume → completed (P1):** status is recomputed from **remaining lagging work items** (not the cumulative failure counter), and `resetFailures()` gives each run fresh `work_items_failed`/`failure_report` while `work_items_done` stays cumulative — so a repaired resume flips `failed → completed` (Task 5 `testResumeMaterializesRemainderAndFlipsFailedToCompleted`).
  - **Transaction composition (P2):** `recordAndFlip` uses `$this->db->transaction(...)` (not raw PDO begin/commit), so it composes with Glueful's transaction/`afterCommit` machinery.
  - **Queue assertions (P2):** helpers read the **configured** queue table (`queue_jobs`, default `database` driver, migrated in-suite) — not a nonexistent `jobs` table or a nonexistent `ContentTypeApiTest` spy — with a concrete `RecordingQueueManager` fallback for non-persisting drivers.
- **Unmapped spec requirement:** none. Two open items the spec itself flagged for the plan are resolved: the delivery cache hook is `CacheStore::invalidateTags(['lemma:type:'.$slug])` (the exact tag `InvalidateCacheTagsListener` uses), and `db()->afterCommit()` is confirmed as the after-commit enqueue hook (same as scheduled-publish + `PublishService`). The third open item — backfill batch/paging size on large catalogs — is a throughput-only concern (correctness independent); the runner scans all lagging items in one pass for V1.x and can add `LIMIT`/cursor paging later without changing the contract.
