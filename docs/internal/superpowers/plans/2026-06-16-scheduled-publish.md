# Scheduled Publish / Unpublish — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Shipped (2026-06-17) — implemented, reviewed, and merged. Steps left as `[ ]` for historical reference.

**Goal:** Let editors schedule an entry+locale to publish/unpublish at a future time, as deferred execution of the existing `PublishService` actions.

**Architecture:** A new `entry_schedules` table holds pending actions. A shared `ScheduleRunner` claims due rows by flipping `pending → processing` (`FOR UPDATE SKIP LOCKED`, repository-owned raw PDO, short transaction), fires each **outside** any enclosing transaction through `PublishService::publish()`/`unpublish()`, then records `done`/`failed`/`canceled` in a separate write — so the terminal-status write survives an action failure without depending on framework savepoint behavior; stale `processing` rows (crash mid-fire) are reclaimed on the next run. Two thin wrappers drive the runner: `lemma:schedules:run` (operator/manual) and `RunDueSchedulesJob` (the every-minute cron `JobInterface`). A `schedules` admin API creates/lists/cancels schedules (create and cancel are entry-scoped); `run_at` is normalized to UTC. No changes to the draft/version/publication lifecycle, the delivery read path, or the §5 event taxonomy.

**Tech Stack:** PHP 8.3, PostgreSQL (required — the claim depends on `SKIP LOCKED`), PHPUnit 10, Glueful framework (migrations, `BaseCommand`, `RequestData` DTOs, `db()->transaction()`/`afterCommit`).

**Spec:** `docs/superpowers/specs/2026-06-16-scheduled-publish-design.md`

---

## File map

- Create: `app/Content/Enums/ScheduleAction.php`, `app/Content/Enums/ScheduleStatus.php`
- Create: `database/migrations/010_CreateEntrySchedulesTable.php`
- Create: `app/Content/Repositories/ScheduleRepository.php`
- Create: `app/Content/Scheduling/ScheduleRunner.php` (shared run logic)
- Create: `app/Content/Http/DTOs/ScheduleData.php`
- Create: `app/Content/Http/Controllers/ScheduleController.php`
- Create: `app/Content/Console/RunDueSchedulesCommand.php` (`lemma:schedules:run`, operator entry point)
- Create: `app/Content/Jobs/RunDueSchedulesJob.php` (cron `JobInterface` entry point)
- Modify: `routes/lemma_admin.php` (3 routes)
- Modify: `app/Providers/LemmaServiceProvider.php` (register repo, runner, controller, command)
- Modify: `app/Content/Repositories/EntryRepository.php` + `app/Content/Http/Controllers/EntryController.php` (locale-summary `scheduled` block)
- Modify: `config/schedule.php` (every-minute cron entry) + `config/lemma.php` (`scheduler.enabled` switch)
- Create tests: `tests/Integration/Content/ScheduleRepositoryTest.php`, `tests/Integration/Content/ScheduleRunnerTest.php`, `tests/Integration/Http/ScheduleApiTest.php`; extend `tests/Integration/Http/EntryApiTest.php`

All tests run on Postgres via `LemmaTestCase`. Conventions: `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col (no blank lines between constructor params).

---

### Task 1: Enums + `entry_schedules` migration

**Files:**
- Create: `app/Content/Enums/ScheduleAction.php`, `app/Content/Enums/ScheduleStatus.php`
- Create: `database/migrations/010_CreateEntrySchedulesTable.php`
- Test: `tests/Integration/Content/ScheduleRepositoryTest.php` (schema assertions here; repo methods in Task 2)

- [ ] **Step 1: Create the enums.**

`app/Content/Enums/ScheduleAction.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum ScheduleAction: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
}
```

`app/Content/Enums/ScheduleStatus.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum ScheduleStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing'; // transient claim marker (durable claim without holding a row lock across firing)
    case Done = 'done';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
```

> **Why `processing`:** the runner (Task 4) claims a row by flipping it `pending → processing`
> in a short transaction, then fires the action **outside** any enclosing transaction and
> writes the terminal status in its own transaction. This makes the outcome write
> *independent of* the action's success/rollback (no dependence on nested-transaction
> savepoint behavior), while `processing` keeps the claim durable so a concurrent/overlapping
> run won't re-fire it. A crash mid-flight is recovered by reclaiming stale `processing` rows.

- [ ] **Step 2: Write the failing schema test.** Append to a new `tests/Integration/Content/ScheduleRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Tests\Support\LemmaTestCase;

final class ScheduleRepositoryTest extends LemmaTestCase
{
    public function testEntrySchedulesTableShape(): void
    {
        $pdo = $this->connection()->getPDO();
        // partial unique: a 2nd PENDING publish for the same (entry, locale) is rejected,
        // but a pending publish + pending unpublish coexist.
        $ins = static fn (string $action): bool => (bool) $pdo->prepare(
            "INSERT INTO entry_schedules (uuid, entry_uuid, locale, action, run_at, status, created_at)
             VALUES (?, 'e1abcdefghij', 'en', ?, now() + interval '1 hour', 'pending', now())"
        )->execute([substr(md5($action . microtime()), 0, 12), $action]);

        self::assertTrue($ins('publish'));
        self::assertTrue($ins('unpublish'), 'pending publish + pending unpublish must coexist');

        $this->expectException(\PDOException::class);
        $ins('publish'); // 2nd pending publish for same (entry, locale) → unique violation
    }
}
```
(`LemmaTestCase::TABLES` will need `'entry_schedules'` added so it truncates between tests — do that in Step 4.)

- [ ] **Step 3: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter testEntrySchedulesTableShape`
Expected: FAIL — `relation "entry_schedules" does not exist`.

- [ ] **Step 4: Create the migration + register the table for truncation.**

`database/migrations/010_CreateEntrySchedulesTable.php`:
```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntrySchedulesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_schedules')) {
            return;
        }
        $schema->createTable('entry_schedules', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->string('action', 16);
            $table->timestamp('run_at');
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('canceled_by', 12)->nullable();
            $table->unique('uuid');
            $table->index(['status', 'run_at'], 'idx_schedules_status_run_at');
        });

        // CHECK guards + the partial-unique index are Postgres DDL the schema builder
        // does not express, so run them as raw SQL (same approach as the filter-index job).
        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec("ALTER TABLE entry_schedules ADD CONSTRAINT chk_schedule_action "
            . "CHECK (action IN ('publish','unpublish'))");
        $pdo->exec("ALTER TABLE entry_schedules ADD CONSTRAINT chk_schedule_status "
            . "CHECK (status IN ('pending','processing','done','failed','canceled'))");
        $pdo->exec("CREATE UNIQUE INDEX uniq_pending_schedule ON entry_schedules "
            . "(entry_uuid, locale, action) WHERE status = 'pending'");
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_schedules');
    }

    public function getDescription(): string
    {
        return 'Create entry_schedules (deferred publish/unpublish actions).';
    }
}
```

In `tests/Support/LemmaTestCase.php`, add `'entry_schedules'` to the `TABLES` list (first, before `entry_references`, so truncation order stays child→parent):
```php
private const TABLES = [
    'entry_schedules',
    'import_export_reports', 'import_export_errors', 'import_export_files',
    'import_export_batches', 'import_export_jobs',
    'entry_references', 'entry_routes', 'entry_publications',
    'entry_versions', 'entry_drafts', 'entries', 'content_types',
];
```
Also add `'entry_schedules'` to the `$requiredTables` list in `scripts/run-test-migrations.php` so the migration-verification covers it.

- [ ] **Step 5: Run it; verify it passes.**

Run: `composer test:reset-db && composer test:migrate && composer test:phpunit -- --filter testEntrySchedulesTableShape`
Expected: PASS (`entry_schedules` created; the 2nd pending-publish insert throws).

- [ ] **Step 6: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Enums database/migrations/010_CreateEntrySchedulesTable.php tests/Support/LemmaTestCase.php scripts/run-test-migrations.php tests/Integration/Content/ScheduleRepositoryTest.php
git commit -m "Add entry_schedules table + schedule enums"
```

---

### Task 2: `ScheduleRepository` — create/replace-pending, list, cancel

**Files:**
- Create: `app/Content/Repositories/ScheduleRepository.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (register, autowire)
- Test: `tests/Integration/Content/ScheduleRepositoryTest.php`

- [ ] **Step 1: Write failing tests.** Add to `ScheduleRepositoryTest`:
```php
public function testScheduleCreatesPendingThenReplacePreservesTerminalHistory(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());

    $a = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, '2026-07-01T07:00:00Z', 'user00000001');
    self::assertFalse($a['replaced']);
    self::assertSame('pending', $a['status']);

    // a terminal (done) row from a prior cycle must survive a later reschedule
    $this->connection()->table('entry_schedules')->where('uuid', '=', $a['uuid'])->update(['status' => 'done']);

    $b = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, '2026-07-02T07:00:00Z', 'user00000001');
    self::assertFalse($b['replaced'], 'no PENDING row existed (the prior one is done), so this is a fresh create');

    $c = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, '2026-07-03T07:00:00Z', 'user00000001');
    self::assertTrue($c['replaced'], 'an existing pending row is replaced/rescheduled');

    $rows = $this->connection()->table('entry_schedules')->where('entry_uuid', '=', 'e1abcdefghij')->get();
    self::assertCount(2, $rows, 'the done row is preserved; only one pending row exists');
}

public function testCancelPendingMarksCanceledAndTerminalCannotCancel(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());
    $a = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Unpublish, '2026-07-01T07:00:00Z', 'user00000001');

    self::assertTrue($repo->cancel('e1abcdefghij', $a['uuid'], 'user00000002'));
    $row = $this->connection()->table('entry_schedules')->where('uuid', '=', $a['uuid'])->first();
    self::assertSame('canceled', $row['status']);
    self::assertSame('user00000002', $row['canceled_by']);
    self::assertNotNull($row['canceled_at']);

    self::assertFalse($repo->cancel('e1abcdefghij', $a['uuid'], 'user00000002'), 'a terminal row cannot be canceled');
}

public function testCancelIsEntryScoped(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());
    $a = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, '2026-07-01T07:00:00Z', null);
    // a known schedule UUID under the WRONG entry must not cancel it
    self::assertFalse($repo->cancel('e9wrongentry0', $a['uuid'], null));
    self::assertSame('pending', $this->connection()->table('entry_schedules')->where('uuid', '=', $a['uuid'])->first()['status']);
}
```

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter ScheduleRepositoryTest`
Expected: FAIL — `Class "App\Content\Repositories\ScheduleRepository" not found`.

- [ ] **Step 3: Implement `ScheduleRepository`** (create/replace, list, cancel; claim + mark land in Task 3):
```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Enums\ScheduleAction;
use App\Content\Enums\ScheduleStatus;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ScheduleRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Create or replace the single PENDING row for (entry, locale, action). Terminal
     * rows (done/failed/canceled) are never touched. $runAtUtc is an ISO-8601 UTC string.
     *
     * @return array<string,mixed> the row, plus a bool 'replaced'
     */
    public function schedule(
        string $entryUuid,
        string $locale,
        ScheduleAction $action,
        string $runAtUtc,
        ?string $actor,
    ): array {
        $existing = $this->db->table('entry_schedules')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->where('action', '=', $action->value)
            ->where('status', '=', ScheduleStatus::Pending->value)
            ->first();

        if ($existing !== null) {
            $this->db->table('entry_schedules')
                ->where('id', '=', $existing['id'])
                ->update(['run_at' => $runAtUtc, 'created_by' => $actor, 'updated_at' => $this->now()]);
            $row = $this->find((string) $existing['uuid']);
            $row['replaced'] = true;
            return $row;
        }

        $uuid = Utils::generateNanoID(12);
        $this->db->table('entry_schedules')->insert([
            'uuid' => $uuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'action' => $action->value,
            'run_at' => $runAtUtc,
            'status' => ScheduleStatus::Pending->value,
            'attempts' => 0,
            'created_by' => $actor,
            'created_at' => $this->now(),
        ]);
        $row = $this->find($uuid);
        $row['replaced'] = false;
        return $row;
    }

    /** @return list<array<string,mixed>> all schedules for an entry (pending + history), newest first. */
    public function forEntry(string $entryUuid): array
    {
        return $this->db->table('entry_schedules')
            ->where('entry_uuid', '=', $entryUuid)
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Cancel a PENDING row, scoped to its entry so a known schedule UUID cannot be
     * canceled under the wrong entry URL. Returns false if missing, not under this entry,
     * or already terminal.
     */
    public function cancel(string $entryUuid, string $scheduleUuid, ?string $actor): bool
    {
        $affected = $this->db->table('entry_schedules')
            ->where('uuid', '=', $scheduleUuid)
            ->where('entry_uuid', '=', $entryUuid)
            ->where('status', '=', ScheduleStatus::Pending->value)
            ->update([
                'status' => ScheduleStatus::Canceled->value,
                'canceled_at' => $this->now(),
                'canceled_by' => $actor,
                'updated_at' => $this->now(),
            ]);
        return $affected >= 1;
    }

    /** @return array<string,mixed>|null */
    public function find(string $scheduleUuid): ?array
    {
        return $this->db->table('entry_schedules')->where('uuid', '=', $scheduleUuid)->first() ?: null;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
```

- [ ] **Step 4: Register in `LemmaServiceProvider::services()`** (mirror the existing repos):
```php
ScheduleRepository::class => [
    'class' => ScheduleRepository::class,
    'shared' => true,
    'autowire' => true,
],
```
(add `use App\Content\Repositories\ScheduleRepository;`).

- [ ] **Step 5: Run; verify pass.**

Run: `composer test:phpunit -- --filter ScheduleRepositoryTest`
Expected: PASS.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Repositories/ScheduleRepository.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/ScheduleRepositoryTest.php
git commit -m "Add ScheduleRepository create/replace/list/cancel"
```

---

### Task 3: `ScheduleRepository` claim (`FOR UPDATE SKIP LOCKED`) + UTC normalization

**Files:**
- Modify: `app/Content/Repositories/ScheduleRepository.php`
- Test: `tests/Integration/Content/ScheduleRepositoryTest.php`

- [ ] **Step 1: Write failing tests** (due-claim, concurrency, UTC normalization):
```php
public function testNormalizeRunAtConvertsOffsetToUtcAndRejectsNaive(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());
    self::assertSame('2026-07-01T07:00:00Z', $repo->normalizeRunAt('2026-07-01T09:00:00+02:00'));
    self::assertSame('2026-07-01T07:00:00Z', $repo->normalizeRunAt('2026-07-01T07:00:00Z'));

    $this->expectException(\InvalidArgumentException::class);
    $repo->normalizeRunAt('2026-07-01T09:00:00'); // naive (no offset) → rejected
}

public function testClaimDueFlipsPastPendingToProcessingNotFuture(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());
    $due = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, $repo->normalizeRunAt('2020-01-01T00:00:00Z'), null);
    $repo->schedule('e2abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, $repo->normalizeRunAt('2999-01-01T00:00:00Z'), null);

    $claimed = $repo->claimDuePending(10);
    self::assertSame([$due['uuid']], array_map(static fn (array $r): string => $r['uuid'], $claimed));
    self::assertSame('processing', $this->connection()->table('entry_schedules')->where('uuid', '=', $due['uuid'])->first()['status']);
    // a second claim sees no due PENDING rows (the first is now 'processing')
    self::assertSame([], $repo->claimDuePending(10));
}

public function testConcurrentClaimSkipsLockedRow(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());
    $a = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, $repo->normalizeRunAt('2020-01-01T00:00:00Z'), null);

    // A second connection holds a row lock on the due row.
    $pdoB = $this->newPdo();
    $pdoB->beginTransaction();
    $pdoB->prepare('SELECT id FROM entry_schedules WHERE uuid = ? FOR UPDATE')->execute([$a['uuid']]);

    // The repo's claim (framework connection) must SKIP the locked row → claims nothing.
    self::assertSame([], $repo->claimDuePending(10));

    $pdoB->commit();
}

public function testReclaimStaleResetsProcessingToPending(): void
{
    $repo = new \App\Content\Repositories\ScheduleRepository($this->connection());
    $a = $repo->schedule('e1abcdefghij', 'en', \App\Content\Enums\ScheduleAction::Publish, $repo->normalizeRunAt('2020-01-01T00:00:00Z'), null);
    // simulate a crashed claim: processing with a stale updated_at
    $this->connection()->getPDO()->exec(
        "UPDATE entry_schedules SET status='processing', updated_at = now() - interval '10 minutes' WHERE uuid = '{$a['uuid']}'"
    );
    self::assertSame(1, $repo->reclaimStale(300));
    self::assertSame('pending', $this->connection()->table('entry_schedules')->where('uuid', '=', $a['uuid'])->first()['status']);
}
```
Add a `newPdo()` test helper (in `ScheduleRepositoryTest`) that opens a second `\PDO` from the same `DB_PGSQL_*` env the verification PDO in `scripts/run-test-migrations.php` uses (`pgsql:host=…;port=…;dbname=…`, `ERRMODE_EXCEPTION`).

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter "testNormalizeRunAt|testClaimDue|testClaimIsConcurrency"`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Implement the claim + normalization** (append to `ScheduleRepository`):
```php
/**
 * Atomically claim a bounded batch of due rows by flipping them `pending → processing`
 * in ONE short transaction, using `FOR UPDATE SKIP LOCKED` so overlapping runs / multiple
 * workers never select the same rows. The returned rows are now `processing` — the claim is
 * durable WITHOUT holding a lock across firing, so the caller fires each action outside any
 * enclosing transaction and records the terminal status with {@see markOutcome} (its own
 * write). Postgres-only.
 *
 * @return list<array<string,mixed>> the claimed rows (status already 'processing')
 */
public function claimDuePending(int $limit): array
{
    $pdo = $this->db->getPDO();
    // Self-contained short transaction (raw PDO begin/commit): SELECT … FOR UPDATE SKIP
    // LOCKED then flip to 'processing'. It commits BEFORE any firing, so it never overlaps
    // a framework db()->transaction() on the same connection.
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare(
            "SELECT id FROM entry_schedules
             WHERE status = 'pending' AND run_at <= now()
             ORDER BY run_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED"
        );
        $sel->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $sel->execute();
        $ids = array_map('intval', $sel->fetchAll(\PDO::FETCH_COLUMN));
        if ($ids === []) {
            $pdo->commit();
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare(
            "UPDATE entry_schedules SET status = 'processing', updated_at = ? WHERE id IN ($in) RETURNING *"
        );
        $upd->execute([$this->now(), ...$ids]);
        $rows = $upd->fetchAll(\PDO::FETCH_ASSOC);
        $pdo->commit();
        return $rows;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Reset `processing` rows older than $olderThanSeconds back to `pending` (crash recovery). */
public function reclaimStale(int $olderThanSeconds = 300): int
{
    $stmt = $this->db->getPDO()->prepare(
        "UPDATE entry_schedules SET status = 'pending', updated_at = ?
         WHERE status = 'processing' AND updated_at < (now() - (? || ' seconds')::interval)"
    );
    $stmt->execute([$this->now(), (string) $olderThanSeconds]);
    return $stmt->rowCount();
}

/** Mark a claimed (`processing`) row terminal in its own write. $status ∈ done|failed|canceled. */
public function markOutcome(int $id, ScheduleStatus $status, ?string $failureReason): void
{
    $stmt = $this->db->getPDO()->prepare(
        'UPDATE entry_schedules SET status = ?, attempts = attempts + 1, failure_reason = ?, updated_at = ? WHERE id = ?'
    );
    $stmt->execute([$status->value, $failureReason, $this->now(), $id]);
}

/** Normalize an ISO-8601 timestamp WITH timezone to UTC `Y-m-d\TH:i:s\Z`; reject naive input. */
public function normalizeRunAt(string $input): string
{
    if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', trim($input)) !== 1) {
        throw new \InvalidArgumentException('run_at must be an ISO-8601 timestamp with a timezone offset.');
    }
    $ts = strtotime($input);
    if ($ts === false) {
        throw new \InvalidArgumentException('run_at is not a valid ISO-8601 timestamp.');
    }
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}
```
(No `ApplicationContext` needed — the claim, reclaim, and outcome writes use `$this->db->getPDO()` directly, so `ScheduleRepository`'s constructor stays `(Connection $db)`. The claim's short transaction only flips status and commits immediately, independent of any later firing.)

- [ ] **Step 4: Run; verify pass.**

Run: `composer test:phpunit -- --filter "testNormalizeRunAt|testClaimDue|testClaimIsConcurrency"`
Expected: PASS — including the held-lock test proving worker B skips the locked row.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Repositories/ScheduleRepository.php tests/Integration/Content/ScheduleRepositoryTest.php
git commit -m "Add SKIP LOCKED claim + UTC run_at normalization to ScheduleRepository"
```

---

### Task 4: `ScheduleRunner` + `lemma:schedules:run` command — fire due schedules

**Files:**
- Create: `app/Content/Scheduling/ScheduleRunner.php` (shared run logic — the unit of behavior)
- Create: `app/Content/Console/RunDueSchedulesCommand.php` (thin operator wrapper over the runner)
- Modify: `app/Providers/LemmaServiceProvider.php` (register runner + command, extend `commands([...])`)
- Test: `tests/Integration/Content/ScheduleRunnerTest.php`

- [ ] **Step 1: Write failing tests** covering the firing matrix. Model setup on `ResyncCommandTest`/`PublishServiceTest` (create a content type + entry + draft, then schedule a due row). Key cases — one test method each:
```php
// testPublishScheduleFiresDeferredPublish: due publish row, draft valid →
//   after run(): status='done', a published version exists, EntryPublished emitted (recording listener),
//   delivery shows the entry.
// testUnpublishScheduleFires: entry published, due unpublish row → status='done', publication removed.
// testInvalidDraftAtFireTimeRecordsFailedAndLeavesEntryUnchanged: draft missing required field →
//   status='failed', failure_reason non-empty, no version pinned (entry stays as before).
// testSoftDeletedEntryIsCanceled: entry soft-deleted, due publish row → status='canceled', not failed.
// testUnpublishAlreadyUnpublishedIsDone: not-published entry, due unpublish → status='done'.
// testTerminalRowsAreNotRefired: a 'done' row with past run_at is ignored by run().
// testAttemptsIncremented: after a run, attempts == 1.
```
Each: arrange rows via `ScheduleRepository` + the existing entry/draft helpers, run the command, assert `entry_schedules` status + side effects. Use the event-recording approach from `AssetEventsTest`/`ResyncCommandTest` for `EntryPublished`/`EntryUnpublished`.

Also the **outcome-survives-failure test** (the property that makes the design savepoint-independent):
```php
// testPublishFailureRecordsFailedAndSuccessEmitsOnce: invalid draft → the PublishService
//   transaction rolls back (no version) BUT the schedule row is independently written
//   status='failed' with a reason (markOutcome is its own write, not nested in the action).
//   On the success case, EntryPublished fires exactly once.
```

Tests target **`ScheduleRunner::run()`** directly (resolve it from the container) — it is the unit of logic; the command and the job (Task 7) are thin wrappers needing only a smoke test each. Arrange rows via `ScheduleRepository` + the existing entry/draft helpers, then call `$runner->run()` and assert `entry_schedules` status + side effects (use the event-recording approach from `AssetEventsTest`/`ResyncCommandTest` for `EntryPublished`/`EntryUnpublished`). Name the test file `tests/Integration/Content/ScheduleRunnerTest.php`.

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter ScheduleRunnerTest`
Expected: FAIL — `ScheduleRunner` class not found.

- [ ] **Step 3: Implement the shared `ScheduleRunner`** (`app/Content/Scheduling/ScheduleRunner.php`). It claims → fires → records with **no enclosing transaction**, so a `PublishService` failure can never lose the status write (savepoint-independent). The runner is the single unit of logic; the command (this task) and the cron job (Task 7) are thin callers:
```php
<?php

declare(strict_types=1);

namespace App\Content\Scheduling;

use App\Content\Enums\ScheduleAction;
use App\Content\Enums\ScheduleStatus;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Services\PublishService;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Fires due scheduled publish/unpublish actions (V1_DESIGN §2). Deferred execution of the
 * normal PublishService actions — no second publish model. Shared by the lemma:schedules:run
 * command and RunDueSchedulesJob (cron).
 *
 * Savepoint-independent: ScheduleRepository::claimDuePending() flips due rows to 'processing'
 * in its own short transaction (durable claim, no held lock); each action then fires OUTSIDE
 * any enclosing transaction (PublishService manages its own), and the terminal status is
 * written by a separate ScheduleRepository::markOutcome() call — so an action failure cannot
 * roll back the status write. reclaimStale() recovers rows from a crash mid-flight.
 */
final class ScheduleRunner
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ScheduleRepository $schedules,
        private readonly PublishService $publisher,
        private readonly EntryRepository $entries,
    ) {
    }

    /** @return int number of schedules fired this run. */
    public function run(int $limit = 100): int
    {
        // Guard HERE (not via config/schedule.php's enabled flag, which the framework
        // scheduler does not reliably honor).
        if (!(bool) config($this->context, 'lemma.scheduler.enabled', true)) {
            return 0;
        }
        $this->schedules->reclaimStale(300);

        $fired = 0;
        foreach ($this->schedules->claimDuePending($limit) as $row) {
            [$status, $reason] = $this->fire($row);
            $this->schedules->markOutcome((int) $row['id'], $status, $reason);
            $fired++;
        }
        return $fired;
    }

    /** @param array<string,mixed> $row @return array{0:ScheduleStatus,1:?string} */
    private function fire(array $row): array
    {
        $entry = $this->entries->findEntry((string) $row['entry_uuid']);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return [ScheduleStatus::Canceled, 'target entry no longer exists'];
        }
        try {
            if ($row['action'] === ScheduleAction::Publish->value) {
                $actor = ((string) ($row['created_by'] ?? '')) ?: null;
                $this->publisher->publish((string) $row['entry_uuid'], (string) $row['locale'], $actor);
            } else {
                $this->publisher->unpublish((string) $row['entry_uuid'], (string) $row['locale']);
            }
            return [ScheduleStatus::Done, null];
        } catch (\Throwable $e) {
            return [ScheduleStatus::Failed, $e->getMessage()];
        }
    }
}
```

Then the thin command `app/Content/Console/RunDueSchedulesCommand.php` (the manual/operator entry point):
```php
<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Scheduling\ScheduleRunner;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'lemma:schedules:run',
    description: 'Fire due scheduled publish/unpublish actions through the normal publish path',
)]
final class RunDueSchedulesCommand extends BaseCommand
{
    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null,
        private readonly ?ScheduleRunner $runner = null,
    ) {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max schedules to fire this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = $this->runner ?? $this->container->get(ScheduleRunner::class);
        $fired = $runner->run(max(1, (int) $input->getOption('limit')));
        $output->writeln(sprintf('Fired %d scheduled action(s).', $fired));
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the runner + command** in `LemmaServiceProvider`:
```php
// in services() (autowire fills ScheduleRunner's deps):
ScheduleRunner::class => ['class' => ScheduleRunner::class, 'shared' => true, 'autowire' => true],
RunDueSchedulesCommand::class => ['class' => RunDueSchedulesCommand::class, 'shared' => true, 'autowire' => true],
// in boot(), extend the existing commands([...]) call:
$this->commands([ResyncCommand::class, RunDueSchedulesCommand::class]);
```
(add `use App\Content\Scheduling\ScheduleRunner;` and `use App\Content\Console\RunDueSchedulesCommand;`).

- [ ] **Step 5: Run; verify pass.** The `outcome-survives-failure` test is the load-bearing one: it passes *because* `markOutcome` is a standalone write, fired after `claimDuePending` has already committed the `processing` flip and after the action runs *outside* any enclosing transaction — so an action throwing cannot roll back the terminal-status write. This is the executable design; do **not** rely on framework savepoint behavior to make it pass. If it fails, the cause is a structural regression (e.g. someone wrapped `fire()` + `markOutcome()` in a shared transaction) — fix the structure, not the test.

Run: `composer test:phpunit -- --filter ScheduleRunnerTest`
Expected: PASS (all firing-matrix cases + outcome-survives-failure).

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Scheduling/ScheduleRunner.php app/Content/Console/RunDueSchedulesCommand.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/ScheduleRunnerTest.php
git commit -m "Add ScheduleRunner + lemma:schedules:run command to fire due schedules"
```

---

### Task 5: Admin API — `ScheduleData` DTO + `ScheduleController` + routes

**Files:**
- Create: `app/Content/Http/DTOs/ScheduleData.php`, `app/Content/Http/Controllers/ScheduleController.php`
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Http/ScheduleApiTest.php`

- [ ] **Step 1: Write failing tests** (`ScheduleApiTest`, resolve the controller from the container like `ContentTypeApiTest`):
```php
// testPostCreatesPendingReturnsRowWithReplacedFalse
// testSecondPostSameActionReplacesAndReturnsReplacedTrue (terminal rows untouched)
// testPostRejectsPastRunAt (422)
// testPostRejectsNaiveRunAt (422)
// testPostRejectsUnknownAction (422)
// testPostRejectsMissingEntry (404 — unknown {uuid}, no row written)
// testPostRejectsDeletedEntry (404 — entry status='deleted', no row written)
// testGetListsSchedulesIncludingHistory
// testDeleteCancelsPendingRow (status canceled + canceled_at/by)
// testDeleteOnTerminalReturns409
// testDeleteIsEntryScoped: a pending schedule belonging to entry A, DELETEd under entry B's
//   URL → 409 and the row stays pending (entry-scoped cancel; mirrors the repository test)
// testRunAtIsNormalizedToUtcInResponse: POST '2026-07-01T09:00:00+02:00' → response run_at '2026-07-01T07:00:00Z'
```

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter ScheduleApiTest` → FAIL (classes/routes missing).

- [ ] **Step 3: Create `ScheduleData`:**
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/entries/{uuid}/schedules/{locale}`. `action` must be
 * publish|unpublish; `run_at` must be an absolute ISO-8601 timestamp WITH timezone and in
 * the future — that semantic validation is the controller's (beyond a built-in rule).
 */
final class ScheduleData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $action = '',
        #[Rule('required|string')]
        public readonly string $run_at = '',
    ) {
    }
}
```

- [ ] **Step 4: Create `ScheduleController`** (validate locale via `ContentLocaleService`, normalize+future-check `run_at`, map action to the enum):
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Enums\ScheduleAction;
use App\Content\Http\DTOs\ScheduleData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ScheduleController
{
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly EntryRepository $entries,
        private readonly ContentLocaleService $locales,
    ) {
    }

    #[ApiOperation(summary: 'Schedule a publish/unpublish', tags: ['Lemma Admin'])]
    #[ApiResponse(201, description: 'Schedule created or rescheduled.')]
    #[ApiResponse(404, schema: \App\Http\DTOs\ErrorResponse::class, envelope: false, description: 'Entry not found or deleted.')]
    #[ApiResponse(422, schema: \App\Http\DTOs\ErrorResponse::class, envelope: false, description: 'Invalid action, run_at, or locale.')]
    public function store(ScheduleData $input, Request $request, string $uuid, string $locale): Response
    {
        // Reject a missing or already-deleted entry before writing a pending row — a stale
        // editor or a wrong UUID must not leave an orphan schedule that fires (and is then
        // canceled) against a non-existent entry. Soft-delete is status='deleted'.
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return Response::error('Entry not found.', Response::HTTP_NOT_FOUND);
        }
        $localeErrors = $this->locales->validate($locale);
        if ($localeErrors !== []) {
            return Response::validation($localeErrors);
        }
        $action = ScheduleAction::tryFrom($input->action);
        if ($action === null) {
            return Response::validation(['action' => 'must be one of: publish, unpublish']);
        }
        try {
            $runAt = $this->schedules->normalizeRunAt($input->run_at);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['run_at' => $e->getMessage()]);
        }
        if (strtotime($runAt) <= time()) {
            return Response::validation(['run_at' => 'must be in the future']);
        }
        $row = $this->schedules->schedule($uuid, $locale, $action, $runAt, $this->actor($request));
        return Response::created(['schedule' => $row], 'Schedule saved.');
    }

    #[ApiOperation(summary: 'List an entry\'s schedules', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Schedules (pending + history).')]
    public function index(Request $request, string $uuid): Response
    {
        return Response::success(['schedules' => $this->schedules->forEntry($uuid)], 'Schedules retrieved.');
    }

    #[ApiOperation(summary: 'Cancel a pending schedule', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Schedule canceled.')]
    #[ApiResponse(409, schema: \App\Http\DTOs\ErrorResponse::class, envelope: false, description: 'Schedule is not pending.')]
    public function destroy(Request $request, string $uuid, string $scheduleUuid): Response
    {
        // Entry-scoped: cancel only when the schedule UUID belongs to THIS entry URL, so a
        // known schedule UUID cannot be canceled under the wrong entry. A no-op (wrong entry,
        // unknown UUID, or already-terminal row) returns 409 — the row is not pending-here.
        if (!$this->schedules->cancel($uuid, $scheduleUuid, $this->actor($request))) {
            return Response::error('Only a pending schedule can be canceled.', Response::HTTP_CONFLICT);
        }
        return Response::success([], 'Schedule canceled.');
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
```

- [ ] **Step 5: Register controller + routes.** In `LemmaServiceProvider::services()`:
```php
ScheduleController::class => ['class' => ScheduleController::class, 'shared' => true, 'autowire' => true],
```
In `routes/lemma_admin.php` (inside the `/v1/admin` `auth` group):
```php
$router->post('/entries/{uuid}/schedules/{locale}', [ScheduleController::class, 'store'])
    ->middleware('lemma_permission:lemma.entries.publish');
$router->get('/entries/{uuid}/schedules', [ScheduleController::class, 'index'])
    ->middleware('lemma_permission:lemma.entries.read');
$router->delete('/entries/{uuid}/schedules/{scheduleUuid}', [ScheduleController::class, 'destroy'])
    ->middleware('lemma_permission:lemma.entries.publish');
```
(add the `ScheduleController` import to both files).

- [ ] **Step 6: Run; verify pass.** `composer test:phpunit -- --filter ScheduleApiTest` → PASS.

- [ ] **Step 7: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Http/DTOs/ScheduleData.php app/Content/Http/Controllers/ScheduleController.php routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Http/ScheduleApiTest.php
git commit -m "Add scheduled-publish admin API (create/list/cancel)"
```

---

### Task 6: Locale-summary `scheduled` block

**Files:**
- Modify: `app/Content/Repositories/EntryRepository.php` (`localeSummary`)
- Modify: `app/Content/Http/Controllers/EntryController.php` (only if it reshapes the summary; otherwise none)
- Test: `tests/Integration/Http/EntryApiTest.php`

- [ ] **Step 1: Write the failing test** — extend the existing `locales` test (or add `testLocalesSummaryIncludesScheduledBlock`): create an entry, schedule a future publish, call `EntryController::locales()`, assert the `en` summary row has a `scheduled` key with the pending publish's `run_at`.

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter EntryApiTest` → FAIL (no `scheduled` key).

- [ ] **Step 3: Implement** — in `EntryRepository::localeSummary($uuid)`, after building the per-locale draft/publication/route state, attach pending schedules: query `entry_schedules WHERE entry_uuid=? AND status='pending'`, and for each locale add `'scheduled' => ['publish' => <run_at|null>, 'unpublish' => <run_at|null>]` (plus the last failure for that locale if present). Keep the existing keys unchanged (additive).

- [ ] **Step 4: Run; verify pass.** `composer test:phpunit -- --filter EntryApiTest` → PASS.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Repositories/EntryRepository.php tests/Integration/Http/EntryApiTest.php
git commit -m "Surface pending schedules in the entry locale summary"
```

---

### Task 7: Scheduler registration + final verification

The framework scheduler does **not** run console commands. `JobHandlerResolver::resolve()` (the single gate for every `handler_class`) rejects any class that does not implement `Glueful\Queue\Contracts\JobInterface` — so the cron entry must point at a `JobInterface`, not at `RunDueSchedulesCommand`. We add a thin `RunDueSchedulesJob extends Glueful\Queue\Job` that delegates to the shared `ScheduleRunner` (the command keeps being the operator/manual entry point; the job is the cron entry point; both call the one runner).

> **Two framework facts this task depends on (both verified against `src/Scheduler/JobScheduler.php` and `src/Queue/`):**
> 1. **The `'enabled'` key is inert.** `JobScheduler::loadCoreJobsFromConfig()` has the disabled-job skip commented out (`// if (isset($job['enabled']) && !$job['enabled']) continue;`), so an `'enabled' => false` entry **still runs**. Do **not** rely on it to disable the job. The real off-switch is the internal guard in `ScheduleRunner::run()` (`config('lemma.scheduler.enabled', true)`), which both the job and the command honor. We keep `LEMMA_SCHEDULER_ENABLED` as that config's source and default it `false` in the test env.
> 2. **`extends Job` means no constructor DI.** `JobHandlerResolver` constructs a `Job` subclass as `new $class($data, $context)` (it only container-resolves *non-`Job`* `JobInterface` implementors). So the job cannot constructor-inject `ScheduleRunner` — it resolves it from the supplied context with `app($this->context, ScheduleRunner::class)` inside `handle()`.

**Files:**
- Create: `app/Content/Jobs/RunDueSchedulesJob.php`
- Modify: `config/schedule.php`

- [ ] **Step 1: Create the cron job wrapper.**
```php
<?php

declare(strict_types=1);

namespace App\Content\Jobs;

use App\Content\Scheduling\ScheduleRunner;
use Glueful\Queue\Job;

/**
 * Cron entry point for scheduled publish/unpublish. The framework scheduler resolves
 * `handler_class` through JobHandlerResolver, which requires a JobInterface — so this
 * thin Job wraps the shared ScheduleRunner (the same code path lemma:schedules:run uses).
 * Job subclasses are constructed by the resolver as `new $class($data, $context)` with no
 * container DI, so we resolve the runner from the injected context here.
 */
final class RunDueSchedulesJob extends Job
{
    public function handle(): void
    {
        if ($this->context === null) {
            return; // No context (cannot resolve services) — nothing safe to do.
        }
        app($this->context, ScheduleRunner::class)->run();
    }
}
```
(`ScheduleRunner::run()` is internally guarded and no-ops when `lemma.scheduler.enabled` is false, so the job needs no guard of its own.)

- [ ] **Step 2: Register the job every minute** in `config/schedule.php` `'jobs'`:
```php
[
    'name' => 'lemma_schedules_run',
    'schedule' => '* * * * *',
    'handler_class' => \App\Content\Jobs\RunDueSchedulesJob::class,
    'parameters' => [],
    'description' => 'Fire due scheduled publish/unpublish actions',
],
```
Do **not** add an `'enabled'` key expecting it to gate execution (it is inert — see the facts box). Disabling is done by `lemma.scheduler.enabled` (env `LEMMA_SCHEDULER_ENABLED`), read inside `ScheduleRunner::run()`. Confirm the entry sits beside the existing `Glueful\Queue\Jobs\*` `handler_class` entries.

- [ ] **Step 3: Add the config switch** if `config/lemma.php` does not already expose it: under the `scheduler` key, `'enabled' => env('LEMMA_SCHEDULER_ENABLED', true)`. Ensure the test bootstrap/`.env` sets `LEMMA_SCHEDULER_ENABLED=false` so the every-minute job never fires mid-suite.

- [ ] **Step 4: Full suite + phpcs + validate.**

Run: `composer ci && composer validate --strict`
Expected: green (prior total + the new schedule tests), phpcs clean.

- [ ] **Step 5: Manual smoke** (optional): `php glueful lemma:schedules:run` runs cleanly against a migrated DB and reports `Fired 0 scheduled action(s).` when nothing is due.

- [ ] **Step 6: Commit.**
```bash
composer phpcs
git add app/Content/Jobs/RunDueSchedulesJob.php config/schedule.php config/lemma.php
git commit -m "Register scheduled-publish cron job on the every-minute scheduler"
```

---

## Self-review notes

- **Postgres-only** is intended (`FOR UPDATE SKIP LOCKED`, `now()`, partial index, `CHECK`) — consistent with V1_DESIGN §10. The `LemmaTestCase` harness already runs on Postgres.
- **Firing is savepoint-independent by design** (Tasks 3–4). `claimDuePending` flips `pending → processing` in its own short raw-PDO transaction (`SELECT … FOR UPDATE SKIP LOCKED` → `UPDATE … RETURNING`), then the runner fires each action **outside** any enclosing transaction and writes the terminal status via a separate `markOutcome` write. An action throwing therefore cannot roll back the status write — we do **not** depend on the framework's `db()->transaction()` being savepoint-isolated. A crash between claim and outcome leaves a `processing` row, which `reclaimStale(300)` returns to `pending` on the next run. The `outcome-survives-failure` test (Task 4, Step 1) gates this property.
- **Single-track unit of logic.** `ScheduleRunner::run()` is the one code path; `RunDueSchedulesCommand` (manual/operator) and `RunDueSchedulesJob` (cron, via `JobInterface`) are thin wrappers over it. The enabled-guard (`lemma.scheduler.enabled`) lives inside `run()`, so it holds for both entry points — and is the *only* off-switch, because the scheduler's `'enabled'` config key is inert (skip is commented out in `JobScheduler::loadCoreJobsFromConfig()`, verified).
- **Cancel and create are entry-scoped** (Task 2/Task 5). `ScheduleRepository::cancel($entryUuid, $scheduleUuid, $actor)` matches on `entry_uuid AND uuid`, so a known schedule UUID cannot be canceled under a different entry URL; `ScheduleController::store()` rejects a missing or `status='deleted'` entry (404) before writing a pending row.
- **`run_at` normalization** lives in `ScheduleRepository::normalizeRunAt` (used by both the controller and tests) so the with-timezone/UTC rule has one home.
- Migration number `010` assumes `009` is the current max (confirmed); adjust if another migration lands first.
