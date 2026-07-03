# Block-Schema Migrations + Hard-Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Explicit eager-only block-schema migrations (declared ops + write gate + queued backfill converging all current content) and usage-gated hard-delete of block types.

**Architecture:** A `lemma_block_type_migrations` table whose microsecond `created_at` is the chain identity (no version numbers, no per-instance stamps). A shared `BlockInstanceWalker` powers the four consumers: backfill rewrite, write gate, restore projection, usage scan. `PublishService::rollback` materializes a new version when the timestamp-suffix projection changes fields. Registry edits become additive-only; rename/delete go through migrations; delete is zero-usage-gated.

**Tech Stack:** PHP 8.4, PHPUnit 10 (PostgreSQL test DB), Glueful framework, Vue 3/Nuxt UI SPA (vitest). Spec: `docs/superpowers/specs/2026-07-03-block-schema-migrations-design.md`.

## Global Constraints

- **Commit gate:** STAGE at the end (Task 10); commit ONLY on explicit authorization. No Claude/Anthropic attribution anywhere.
- phpcs via `vendor/bin/phpcs -q <files>; echo "PHPCS_EXIT=$?"`; `composer boundaries` after any pack change (none expected — everything here is app-side + SPA; run it anyway in Task 10).
- **No per-instance schema stamps; no read-time/view projection** (spec model pin).
- **Microsecond timestamps** (spec §5): `lemma_block_type_migrations.created_at/completed_at` and `entry_versions.created_at` writes use `(new \DateTimeImmutable())->format('Y-m-d H:i:s.u')`. Postgres `timestamp` stores µs natively — the WRITE format is the fix. Comparison is strictly `>`.
- **Active = running OR failed** (spec §2/§3): a failed migration keeps the write gate closed and blocks new declarations; only `completed` unlocks.
- **Backfill predicate** (spec §4): `entries.status != 'deleted'` — mirror `BackfillRunner`'s SHAPE, not its active-only predicate.
- Ops vocabulary: `rename {from,to}`, `delete {name}` — reuse `App\Content\Schema\Migration\{MigrationOpSet,RenameField,DeleteField}` verbatim (pure field-map ops).
- Nested descent always caps at `BlockDepth::MAX`; registry lookups use `schemasBySlug()` (includes deactivated types).
- New controllers must be registered in `LemmaServiceProvider::services()` with `use` imports (recurring project rule).
- Error copy pins: additive-only 422 → `"cannot remove field '{name}' from a block type schema — declare a block-type migration instead"`; write gate 409 code `BLOCK_MIGRATION_IN_PROGRESS`, message `"block type '{slug}' has a migration in progress"`; restore unknown-type error → `"unknown block type '{slug}' (hard-deleted?) — cannot restore this version"`.

## File Structure

- Modify: `app/Content/Blocks/BlockTypeRepository.php` — additive-only guard, `applyMigratedSchema()`, `deleteBySlug()`.
- Create: `database/migrations/018_CreateLemmaBlockTypeMigrationsTable.php`.
- Create: `app/Content/Blocks/Migration/BlockMigrationRepository.php`, `BlockMigrationService.php`, `BlockInstanceWalker.php`, `BlockBackfillRunner.php`, `BlockMigrationInProgressException.php`, `UnknownBlockTypeException.php`.
- Create: `app/Content/Blocks/BlockMigrationGate.php`, `app/Content/Blocks/BlockRestoreProjector.php`, `app/Content/Blocks/BlockUsageScanner.php`.
- Create: `app/Content/Jobs/RunBlockBackfillJob.php`, `app/Content/Console/RunBlockBackfillCommand.php`, `app/Content/Http/Controllers/BlockMigrationController.php`.
- Modify: `app/Content/Http/Controllers/BlockTypeController.php` (usage + destroy), `app/Content/Http/Controllers/EntryController.php` (gate), `app/Content/Services/PublishService.php` (gate + restore projection), `app/Content/Repositories/VersionRepository.php` (µs), `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php`, `tests/Support/LemmaTestCase.php` (TABLES).
- SPA: `admin/src/queries/blockTypes.ts`, `admin/src/pages/settings/block-types/[slug].vue` (+ new components), tests in `admin/src/__tests__/`.

---

### Task 1: Registry — additive-only guard, internal schema apply, hard delete

**Files:**
- Modify: `app/Content/Blocks/BlockTypeRepository.php`
- Test: extend `tests/Integration/Content/BlockTypeRepositoryTest.php` (create if absent — check `grep -rl BlockTypeRepository tests/Integration/Content/` and extend the existing registry test file found there)

**Interfaces:**
- Produces: `updateSchema()` throws `SchemaParseException` when the new schema's field-name set is not a superset of the old; `applyMigratedSchema(string $uuid, array $schema): void` (internal, guard-exempt); `deleteBySlug(string $slug): void` (hard DELETE + memo reset).

- [ ] **Step 1: Failing tests** (in the registry test file; use its existing harness/`create()` helpers):

```php
    public function testUpdateSchemaIsAdditiveOnly(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $uuid = $repo->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
        ]]);

        // Additive: new field appended — allowed.
        $repo->updateSchema($uuid, [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
            ['name' => 'icon', 'type' => 'string'],
        ], 'Card', null, null, null);
        self::assertCount(3, $repo->findBySlug('card')['schema']);

        // Retype of an existing field — allowed (visible validation on next save,
        // never silent loss; spec §1).
        $repo->updateSchema($uuid, [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'string'],
            ['name' => 'icon', 'type' => 'string'],
        ], 'Card', null, null, null);

        // Removal (also the remove+add shape of a rename) — rejected.
        try {
            $repo->updateSchema($uuid, [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'heading', 'type' => 'text'], // body "renamed"
                ['name' => 'icon', 'type' => 'string'],
            ], 'Card', null, null, null);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException $e) {
            self::assertStringContainsString("cannot remove field 'body'", $e->getMessage());
        }
    }

    public function testApplyMigratedSchemaBypassesTheGuardAndDeleteRemovesTheRow(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $uuid = $repo->create(['slug' => 'gone', 'label' => 'Gone', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);

        // Internal path: removal allowed (the migration flow computed this schema).
        $repo->applyMigratedSchema($uuid, [['name' => 'b', 'type' => 'string']]);
        self::assertSame('b', $repo->findBySlug('gone')['schema'][0]['name']);

        $repo->deleteBySlug('gone');
        self::assertNull($repo->findBySlug('gone'));
    }
```

Import `App\Content\Schema\SchemaParseException` in the test.

- [ ] **Step 2: Verify fail** — guard doesn't exist (removal passes), methods undefined.

- [ ] **Step 3: Implement** in `BlockTypeRepository`:

In `updateSchema()`, after `$this->assertBlockSchema($schema);` insert:

```php
        // Additive-only (block-migrations spec §1): removing a field (including the
        // remove+add shape of a rename) orphans stored instance keys, which the
        // cleaned payload then silently strips. Destructive edits go through the
        // migration flow (applyMigratedSchema is its guard-exempt internal path).
        $current = $this->findByUuid($uuid);
        if ($current !== null) {
            $newNames = [];
            foreach ($schema as $field) {
                if (isset($field['name']) && is_string($field['name'])) {
                    $newNames[$field['name']] = true;
                }
            }
            foreach ((array) $current['schema'] as $field) {
                $name = (string) ($field['name'] ?? '');
                if ($name !== '' && !isset($newNames[$name])) {
                    throw new SchemaParseException(
                        "cannot remove field '{$name}' from a block type schema — "
                        . 'declare a block-type migration instead'
                    );
                }
            }
        }
```

New methods (full):

```php
    /**
     * Guard-exempt schema replacement for the MIGRATION flow only (spec §2): the
     * computed post-op schema legitimately removes/renames fields. Never expose
     * this through the public update endpoint.
     *
     * @param list<array<string,mixed>> $schema
     */
    public function applyMigratedSchema(string $uuid, array $schema): void
    {
        $this->assertBlockSchema($schema);
        $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->update([
            'schema' => (string) json_encode(array_values($schema)),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->schemas = null;
    }

    /**
     * HARD delete (spec §6) — callers gate on zero usage + no active migration.
     * Deactivate remains the editorial soft path; there is no deleted_at here.
     */
    public function deleteBySlug(string $slug): void
    {
        $this->db->table('lemma_block_types')->where('slug', '=', $slug)->delete();
        $this->schemas = null;
    }
```

(`lemma_block_types` has no `deleted_at`, so the soft-delete handler is inert here — plain `delete()` is a real DELETE; verify in Step 4's test.)

- [ ] **Step 4: Verify pass + gates** — the registry test file + `vendor/bin/phpunit tests/Integration/Content/`; phpcs on touched files.

---

### Task 2: Migrations table + `BlockMigrationRepository`

**Files:**
- Create: `database/migrations/018_CreateLemmaBlockTypeMigrationsTable.php`
- Create: `app/Content/Blocks/Migration/BlockMigrationRepository.php`
- Modify: `tests/Support/LemmaTestCase.php` (add `'lemma_block_type_migrations'` to `TABLES`)
- Test: `tests/Integration/Content/BlockMigrationRepositoryTest.php`

**Interfaces:**
- Produces: `recordAndFlip(string $blockTypeUuid, MigrationOpSet $ops, array $newSchema, int $workItemsTotal, ?string $actor): string`; `activeForType(string $blockTypeUuid): ?array` (status IN running, failed); `activeAny(): list<array{block_type_uuid: string, slug: string}>`; `completedAfter(string $blockTypeUuid, string $versionCreatedAt): list<array>` (completed, `created_at > $ts`, ASC); `find/forType`; `incrementDone/recordFailure/resetFailures/finish` (accounting, mirroring `MigrationRepository`); microsecond `now()`.

- [ ] **Step 1: DB migration** — mirror `017_CreateLemmaBlockTypesTable.php`'s structure/style exactly (same class shape, schema-builder calls):

Columns: `id` auto-increment PK; `uuid` string(12) unique; `block_type_uuid` string(12) indexed; `ops` json; `status` string(16) default `'running'` indexed; `work_items_total` int default 0; `work_items_done` int default 0; `work_items_failed` int default 0; `failure_report` json; `created_by` string(12) nullable; `created_at` timestamp; `started_at` timestamp nullable; `completed_at` timestamp nullable. Down: drop table. Then `composer run test:reset-db && composer run test:migrate` (edited-applied-migration rule does not apply — it's new — but the fresh table must exist before tests).

- [ ] **Step 2: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\LemmaTestCase;

final class BlockMigrationRepositoryTest extends LemmaTestCase
{
    public function testRecordAndFlipLifecycleAndSuffixSelection(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $type = $blocks->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);
        $repo = new BlockMigrationRepository($this->connection(), $blocks);

        $before = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s.u');
        $uuid = $repo->recordAndFlip(
            $type,
            new MigrationOpSet([new RenameField('title', 'heading')]),
            [['name' => 'heading', 'type' => 'string']],
            5,
            'user00000001',
        );

        // Flip happened atomically with the record.
        self::assertSame('heading', $blocks->findBySlug('card')['schema'][0]['name']);

        // Active while running AND while failed; only completed unlocks (spec §2/§3).
        self::assertNotNull($repo->activeForType($type));
        $repo->finish($uuid, 'failed');
        self::assertNotNull($repo->activeForType($type));
        self::assertNotSame([], $repo->activeAny());
        $repo->finish($uuid, 'completed');
        self::assertNull($repo->activeForType($type));
        self::assertSame([], $repo->activeAny());
        self::assertNotNull($repo->find($uuid)['completed_at']);

        // Timestamp-suffix selection: completed + strictly-after only, ASC.
        self::assertCount(1, $repo->completedAfter($type, $before));
        $after = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s.u');
        self::assertSame([], $repo->completedAfter($type, $after));
        // Strict >: equal timestamp applies nothing (spec §5).
        $row = $repo->find($uuid);
        self::assertSame([], $repo->completedAfter($type, (string) $row['created_at']));
    }

    public function testMicrosecondPrecisionIsPersisted(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $type = $blocks->create(['slug' => 'ms', 'label' => 'Ms', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);
        $repo = new BlockMigrationRepository($this->connection(), $blocks);
        $uuid = $repo->recordAndFlip(
            $type,
            new MigrationOpSet([new RenameField('a', 'b')]),
            [['name' => 'b', 'type' => 'string']],
            0,
            null,
        );
        $created = (string) $repo->find($uuid)['created_at'];
        // Not second-truncated: fractional part present and nonzero-capable.
        self::assertMatchesRegularExpression('/\.\d{1,6}$/', $created);
    }

    public function testAccountingMirrorsContentTypeMigrations(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $type = $blocks->create(['slug' => 'acct', 'label' => 'A', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);
        $repo = new BlockMigrationRepository($this->connection(), $blocks);
        $uuid = $repo->recordAndFlip(
            $type,
            new MigrationOpSet([new RenameField('a', 'b')]),
            [['name' => 'b', 'type' => 'string']],
            2,
            null,
        );
        $repo->incrementDone($uuid);
        $repo->recordFailure($uuid, 'entry0000001', 'en', 'draft', 'boom');
        $row = $repo->find($uuid);
        self::assertSame(1, (int) $row['work_items_done']);
        self::assertSame(1, (int) $row['work_items_failed']);
        self::assertNotSame([], $row['failure_report']);
        $repo->resetFailures($uuid);
        self::assertSame(0, (int) $repo->find($uuid)['work_items_failed']);
    }
}
```

- [ ] **Step 3: Verify fail**, then implement `BlockMigrationRepository` — mirror `MigrationRepository`'s method bodies (`recordAndFlip` transaction, `incrementDone`, `recordFailure` appending `{entry,locale,kind,message}` to `failure_report`, `resetFailures`, `finish` setting `completed_at` on `completed`), with these deltas:

```php
    public function __construct(
        private readonly Connection $db,
        private readonly BlockTypeRepository $blockTypes,
    ) {
    }
```

- `recordAndFlip(string $blockTypeUuid, MigrationOpSet $ops, array $newSchema, int $workItemsTotal, ?string $actor): string` — inserts into `lemma_block_type_migrations` (no from/to versions) and calls `$this->blockTypes->applyMigratedSchema($blockTypeUuid, $newSchema)` inside the same transaction.
- `activeForType()`: `whereIn('status', ['running', 'failed'])` — **failed blocks** (spec delta from content types).
- `activeAny(): array` — all running|failed rows joined to `lemma_block_types` for `slug`:

```php
    /** @return list<array{block_type_uuid: string, slug: string, uuid: string, status: string}> */
    public function activeAny(): array
    {
        $rows = $this->db->table('lemma_block_type_migrations as m')
            ->join('lemma_block_types as t', 't.uuid', '=', 'm.block_type_uuid')
            ->select(['m.uuid', 'm.block_type_uuid', 'm.status', 't.slug'])
            ->whereIn('m.status', ['running', 'failed'])
            ->get();
        return array_map(static fn(array $r): array => [
            'uuid' => (string) $r['uuid'],
            'block_type_uuid' => (string) $r['block_type_uuid'],
            'status' => (string) $r['status'],
            'slug' => (string) $r['slug'],
        ], $rows);
    }

    /**
     * The restore suffix (spec §5): COMPLETED migrations for this type STRICTLY
     * after the version's creation, oldest first. Microsecond precision on both
     * sides; ties apply nothing (the only same-instant writer is the backfill).
     *
     * @return list<array<string,mixed>>
     */
    public function completedAfter(string $blockTypeUuid, string $versionCreatedAt): array
    {
        return array_map(
            fn(array $r): array => (array) $this->hydrate($r),
            $this->db->table('lemma_block_type_migrations')
                ->where('block_type_uuid', '=', $blockTypeUuid)
                ->where('status', '=', 'completed')
                ->where('created_at', '>', $versionCreatedAt)
                ->orderBy('created_at', 'ASC')
                ->get()
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }
```

(`hydrate` decodes `ops`/`failure_report` json — mirror `MigrationRepository::hydrate`.)

- [ ] **Step 4: Verify pass + gates.** If the µs regex test fails because the installed column truncates (precision 0), add a column alteration to migration 018 (`timestamp(6)`) and rebuild the test DB — the spec anticipates this.

---

### Task 3: `BlockInstanceWalker` — the shared walk

**Files:**
- Create: `app/Content/Blocks/Migration/BlockInstanceWalker.php`
- Test: `tests/Integration/Content/BlockInstanceWalkerTest.php`

**Interfaces:**
- Produces:
  - `slugsIn(array $fields, ContentTypeSchema $entrySchema): list<string>` — distinct block-type slugs present (nested, capped).
  - `rewrite(array $fields, ContentTypeSchema $entrySchema, string $slug, MigrationOpSet $ops): array{0: array<string,mixed>, 1: bool}` — ops applied to `data` of instances of `$slug` (nested); bool = changed.
  - `hasOpSources(array $fields, ContentTypeSchema $entrySchema, string $slug, MigrationOpSet $ops): bool` — "remaining work" predicate: any matching instance still carries a rename-`from`/delete-`name` key.
- Consumes: `BlockTypeRepository::schemasBySlug()` (nested descent through block schemas' `blocks` fields), `BlockDepth::MAX`, `ContentTypeSchema::fields()` (`->type === 'blocks'`).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockInstanceWalker;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\LemmaTestCase;

final class BlockInstanceWalkerTest extends LemmaTestCase
{
    private BlockInstanceWalker $walker;
    private ContentTypeSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        $blocks->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);
        $blocks->create(['slug' => 'nest', 'label' => 'Nest', 'schema' => [
            ['name' => 'inner', 'type' => 'blocks'],
        ]]);
        $this->walker = new BlockInstanceWalker($blocks);
        $this->schema = ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
    }

    /** @return array<string,mixed> */
    private function fieldsWithNestedCard(): array
    {
        return ['title' => 'X', 'body' => [
            ['id' => 'a', 'type' => 'card', 'data' => ['title' => 'top']],
            ['id' => 'n', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'b', 'type' => 'card', 'data' => ['title' => 'deep']],
            ]]],
        ]];
    }

    public function testSlugsInFindsNestedSlugs(): void
    {
        self::assertEqualsCanonicalizing(
            ['card', 'nest'],
            $this->walker->slugsIn($this->fieldsWithNestedCard(), $this->schema),
        );
        self::assertSame([], $this->walker->slugsIn(['title' => 'X'], $this->schema));
    }

    public function testRewriteAppliesOpsToMatchingInstancesOnlyNestedIncluded(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        [$out, $changed] = $this->walker->rewrite(
            $this->fieldsWithNestedCard(),
            $this->schema,
            'card',
            $ops,
        );
        self::assertTrue($changed);
        self::assertSame('top', $out['body'][0]['data']['heading']);
        self::assertArrayNotHasKey('title', $out['body'][0]['data']);
        self::assertSame('deep', $out['body'][1]['data']['inner'][0]['data']['heading']);
        // The entry's own top-level `title` is NOT a block field — untouched.
        self::assertSame('X', $out['title']);

        // Idempotent: re-running changes nothing (tolerant ops).
        [$again, $changedAgain] = $this->walker->rewrite($out, $this->schema, 'card', $ops);
        self::assertFalse($changedAgain);
        self::assertSame($out, $again);
    }

    public function testHasOpSourcesIsTheRemainingWorkPredicate(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        self::assertTrue($this->walker->hasOpSources($this->fieldsWithNestedCard(), $this->schema, 'card', $ops));
        [$out] = $this->walker->rewrite($this->fieldsWithNestedCard(), $this->schema, 'card', $ops);
        self::assertFalse($this->walker->hasOpSources($out, $this->schema, 'card', $ops));
        // An instance that never had the field is not remaining work.
        $sparse = ['body' => [['id' => 'c', 'type' => 'card', 'data' => []]]];
        self::assertFalse($this->walker->hasOpSources($sparse, $this->schema, 'card', $ops));
    }

    public function testMalformedItemsAndDepthCapAreLeftUntouched(): void
    {
        $deep = ['id' => 'x', 'type' => 'card', 'data' => ['title' => 'below-cap']];
        for ($i = 0; $i < 3; $i++) {
            $deep = ['id' => "n{$i}", 'type' => 'nest', 'data' => ['inner' => [$deep]]];
        }
        $fields = ['body' => [
            'not-a-block',
            ['id' => 'y', 'type' => 'ghost', 'data' => ['title' => 'z']],
            $deep,
        ]];
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        [$out, $changed] = $this->walker->rewrite($fields, $this->schema, 'card', $ops);
        self::assertFalse($changed);
        self::assertSame($fields, $out);
        self::assertFalse($this->walker->hasOpSources($fields, $this->schema, 'card', $ops));
    }
}
```

- [ ] **Step 2: Verify fail**, then implement:

```php
<?php

declare(strict_types=1);

namespace App\Content\Blocks\Migration;

use App\Content\Blocks\BlockDepth;
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationOpSet;

/**
 * The ONE structural walk over block instances inside entry fields — shared by the
 * backfill rewrite, the write gate, the restore projection, and the usage scan, so
 * "which blocks does this content contain" can never diverge between them.
 * Descent: entry-schema `blocks` fields, then nested `blocks` fields per the
 * registry schema (schemasBySlug includes deactivated types), capped at
 * BlockDepth::MAX. Malformed items and unknown slugs are skipped, never modified.
 */
final class BlockInstanceWalker
{
    public function __construct(private readonly BlockTypeRepository $registry)
    {
    }

    /** @return list<string> distinct block-type slugs present (nested, capped) */
    public function slugsIn(array $fields, ContentTypeSchema $entrySchema): array
    {
        $found = [];
        foreach ($this->blocksFieldNames($entrySchema) as $name) {
            $this->collectSlugs($fields[$name] ?? null, 1, $found);
        }
        return array_keys($found);
    }

    /**
     * Apply $ops to the data of every instance of $slug (nested, capped).
     *
     * @param array<string,mixed> $fields
     * @return array{0: array<string,mixed>, 1: bool} [rewritten fields, changed]
     */
    public function rewrite(array $fields, ContentTypeSchema $entrySchema, string $slug, MigrationOpSet $ops): array
    {
        $changed = false;
        foreach ($this->blocksFieldNames($entrySchema) as $name) {
            if (!array_key_exists($name, $fields)) {
                continue;
            }
            $fields[$name] = $this->rewriteList($fields[$name], 1, $slug, $ops, $changed);
        }
        return [$fields, $changed];
    }

    /** True when a matching instance still carries a rename-from/delete-name key. */
    public function hasOpSources(
        array $fields,
        ContentTypeSchema $entrySchema,
        string $slug,
        MigrationOpSet $ops,
    ): bool {
        $sources = $this->sourceKeys($ops);
        foreach ($this->blocksFieldNames($entrySchema) as $name) {
            if ($this->listHasSources($fields[$name] ?? null, 1, $slug, $sources)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function blocksFieldNames(ContentTypeSchema $entrySchema): array
    {
        $names = [];
        foreach ($entrySchema->fields() as $field) {
            if ($field->type === 'blocks') {
                $names[] = $field->name;
            }
        }
        return $names;
    }

    /** @return list<string> the op source keys (rename.from / delete.name) */
    private function sourceKeys(MigrationOpSet $ops): array
    {
        $keys = [];
        foreach ($ops->toArray() as $op) {
            $keys[] = (string) ($op['from'] ?? $op['name'] ?? '');
        }
        return array_values(array_filter($keys, static fn(string $k): bool => $k !== ''));
    }

    /** @param array<string,bool> $found */
    private function collectSlugs(mixed $list, int $depth, array &$found): void
    {
        foreach ($this->items($list, $depth) as [$type, $data, $schema]) {
            $found[$type] = true;
            foreach ($this->nestedBlockFields($schema) as $inner) {
                $this->collectSlugs($data[$inner] ?? null, $depth + 1, $found);
            }
        }
    }

    private function rewriteList(mixed $list, int $depth, string $slug, MigrationOpSet $ops, bool &$changed): mixed
    {
        if (!is_array($list) || !array_is_list($list) || $depth > BlockDepth::MAX) {
            return $list;
        }
        foreach ($list as $i => $item) {
            [$type, $data, $schema] = $this->item($item);
            if ($type === null) {
                continue;
            }
            if ($type === $slug) {
                $applied = $ops->apply($data);
                if ($applied !== $data) {
                    $changed = true;
                    $data = $applied;
                }
            }
            foreach ($this->nestedBlockFields($schema) as $inner) {
                if (array_key_exists($inner, $data)) {
                    $data[$inner] = $this->rewriteList($data[$inner], $depth + 1, $slug, $ops, $changed);
                }
            }
            $list[$i]['data'] = $data;
        }
        return $list;
    }

    /** @param list<string> $sources */
    private function listHasSources(mixed $list, int $depth, string $slug, array $sources): bool
    {
        foreach ($this->items($list, $depth) as [$type, $data, $schema]) {
            if ($type === $slug) {
                foreach ($sources as $key) {
                    if (array_key_exists($key, $data)) {
                        return true;
                    }
                }
            }
            foreach ($this->nestedBlockFields($schema) as $inner) {
                if ($this->listHasSources($data[$inner] ?? null, $depth + 1, $slug, $sources)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Iterate well-formed items of a blocks list: yields [type, data, blockSchema].
     *
     * @return iterable<array{0: string, 1: array<string,mixed>, 2: ContentTypeSchema}>
     */
    private function items(mixed $list, int $depth): iterable
    {
        if (!is_array($list) || !array_is_list($list) || $depth > BlockDepth::MAX) {
            return;
        }
        foreach ($list as $item) {
            [$type, $data, $schema] = $this->item($item);
            if ($type !== null && $schema !== null) {
                yield [$type, $data, $schema];
            }
        }
    }

    /** @return array{0: ?string, 1: array<string,mixed>, 2: ?ContentTypeSchema} */
    private function item(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['type'] ?? null) || !is_array($item['data'] ?? null)) {
            return [null, [], null];
        }
        $schema = $this->registry->schemasBySlug()[$item['type']] ?? null;
        if ($schema === null) {
            return [null, [], null];
        }
        return [$item['type'], $item['data'], $schema];
    }

    /** @return list<string> */
    private function nestedBlockFields(ContentTypeSchema $schema): array
    {
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type === 'blocks') {
                $names[] = $field->name;
            }
        }
        return $names;
    }
}
```

NOTE the `rewrite`/`item` interplay: `rewriteList` iterates raw items (not `items()`) so unknown/malformed entries survive byte-identical. `MigrationOpSet::apply()` may throw `MigrationCollisionException` — let it bubble (backfill records it as a failure; the gate/restore paths surface it).

- [ ] **Step 3: Verify pass + gates.**

---

### Task 4: `BlockMigrationService` + declaration endpoints

**Files:**
- Create: `app/Content/Blocks/Migration/BlockMigrationService.php`, `app/Content/Http/Controllers/BlockMigrationController.php`, `app/Content/Jobs/RunBlockBackfillJob.php` (thin job shell — the runner arrives in Task 5)
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Content/BlockMigrationServiceTest.php`

**Interfaces:**
- Produces: `BlockMigrationService::migrate(string $blockTypeUuid, array $rawOps, ?string $actor): string` (validates ops against the CURRENT block schema with `MigrationService`'s collision rules; computes new schema; counts work items via the walker over drafts+publications with `entries.status != 'deleted'`; `recordAndFlip`; queues `RunBlockBackfillJob` after commit; throws `ActiveMigrationException` when `activeForType` is non-null). Routes: `POST /block-types/{slug}/migrations` (201), `GET /block-types/{slug}/migrations`, `GET /block-types/{slug}/migrations/{migrationUuid}` — mirror `MigrationController`'s three endpoints, reusing the `MigrationData` DTO and its 404/409/422 mapping.
- Consumes: Task 2 repository, Task 3 walker.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Schema\SchemaParseException;
use App\Content\Services\ActiveMigrationException;
use App\Tests\Support\LemmaTestCase;

final class BlockMigrationServiceTest extends LemmaTestCase
{
    private function service(): BlockMigrationService
    {
        return $this->container()->get(BlockMigrationService::class);
    }

    private function blockType(): string
    {
        return (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'card',
            'label' => 'Card',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'note', 'type' => 'text'],
            ],
        ]);
    }

    public function testDeclareValidatesFlipsAndRecords(): void
    {
        $type = $this->blockType();
        $uuid = $this->service()->migrate($type, [
            ['op' => 'rename', 'from' => 'title', 'to' => 'heading'],
            ['op' => 'delete', 'name' => 'note'],
        ], 'user00000001');

        $blocks = new BlockTypeRepository($this->connection());
        $schema = $blocks->findBySlug('card')['schema'];
        self::assertSame(['heading'], array_column($schema, 'name'));

        $repo = new BlockMigrationRepository($this->connection(), $blocks);
        $row = $repo->find($uuid);
        self::assertSame('running', $row['status']);
        self::assertCount(2, $row['ops']);
    }

    public function testInvalidOpsRejectAndSecondDeclarationBlocks(): void
    {
        $type = $this->blockType();

        try {
            $this->service()->migrate($type, [['op' => 'rename', 'from' => 'nope', 'to' => 'x']], null);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->migrate($type, [['op' => 'delete', 'name' => 'note']], null);
        try {
            $this->service()->migrate($type, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);
            self::fail('expected ActiveMigrationException');
        } catch (ActiveMigrationException) {
            $this->addToAssertionCount(1);
        }
    }
}
```

(`ActiveMigrationException` already exists for content types — reuse it. If its namespace differs from `App\Content\Services`, adjust the import to the real one found via `grep -rn "class ActiveMigrationException" app/`.)

- [ ] **Step 2: Verify fail**, then implement `BlockMigrationService`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Blocks\Migration;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Jobs\RunBlockBackfillJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\SchemaParseException;
use App\Content\Services\ActiveMigrationException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;

/**
 * Declares a block-type migration (spec §2): validates ops against the CURRENT
 * block schema (content-type collision rules), computes the post-op schema,
 * counts work items (current drafts + pinned publications of NON-DELETED entries
 * containing op-source keys), records + flips atomically, queues the backfill.
 * One active (running|failed) migration per block type.
 */
final class BlockMigrationService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly BlockTypeRepository $blockTypes,
        private readonly BlockMigrationRepository $migrations,
        private readonly ContentTypeRepository $contentTypes,
        private readonly BlockInstanceWalker $walker,
        private readonly QueueManager $queue,
    ) {
    }

    /** @param list<array<string,mixed>> $rawOps */
    public function migrate(string $blockTypeUuid, array $rawOps, ?string $actor): string
    {
        $type = $this->blockTypes->findByUuid($blockTypeUuid);
        if ($type === null) {
            throw new SchemaParseException("block type {$blockTypeUuid} not found");
        }
        if ($this->migrations->activeForType($blockTypeUuid) !== null) {
            throw new ActiveMigrationException(
                'a migration is already active for this block type (running or failed — re-drive it first)'
            );
        }

        $currentSchema = (array) $type['schema'];
        $opSet = $this->parseAndValidate($rawOps, $currentSchema);
        $newSchema = $this->computeNewSchema($currentSchema, $rawOps);
        $workItems = $this->countWorkItems((string) $type['slug'], $opSet, $currentSchema);

        $uuid = $this->migrations->recordAndFlip($blockTypeUuid, $opSet, $newSchema, $workItems, $actor);

        $this->db->afterCommit(function () use ($uuid): void {
            $this->queue->push(RunBlockBackfillJob::class, ['migration_uuid' => $uuid]);
        });

        return $uuid;
    }

    /**
     * Count entries whose current draft OR pinned publication still carries an
     * op-source key inside an instance of the migrating type. NON-DELETED entries
     * (archived included — spec §4); the walk needs the PRE-FLIP schema, which is
     * why this runs before recordAndFlip... except the walker reads the registry
     * live. The ops' source keys are the OLD names, matched against stored data —
     * the registry schema is only used for DESCENT (blocks-field names), which the
     * flip does not change. Safe either side of the flip.
     */
    private function countWorkItems(string $slug, MigrationOpSet $opSet, array $currentSchema): int
    {
        $count = 0;
        foreach ($this->contentTypes->all() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            $hasBlocks = false;
            foreach ($schema->fields() as $field) {
                if ($field->type === 'blocks') {
                    $hasBlocks = true;
                    break;
                }
            }
            if (!$hasBlocks) {
                continue;
            }
            $typeUuid = (string) $ct['uuid'];
            foreach (
                $this->db->table('entry_drafts as d')
                    ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
                    ->select(['d.fields'])
                    ->where('e.content_type_uuid', '=', $typeUuid)
                    ->whereRaw("e.status != 'deleted'")
                    ->get() as $row
            ) {
                if ($this->walker->hasOpSources($this->decode($row['fields']), $schema, $slug, $opSet)) {
                    $count++;
                }
            }
            foreach (
                $this->db->table('entry_publications as p')
                    ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
                    ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
                    ->select(['v.fields'])
                    ->where('e.content_type_uuid', '=', $typeUuid)
                    ->whereRaw("e.status != 'deleted'")
                    ->get() as $row
            ) {
                if ($this->walker->hasOpSources($this->decode($row['fields']), $schema, $slug, $opSet)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** @return array<string,mixed> */
    private function decode(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($fields) ? $fields : [];
    }
```

…plus `parseAndValidate()` and `computeNewSchema()` copied VERBATIM from `MigrationService` (same collision rules, same error strings — they operate on plain field lists, so they transplant unchanged; keep them private). If `whereRaw` differs in this query-builder version, use `->where('e.status', '!=', 'deleted')` (verify against another call site).

`RunBlockBackfillJob` — mirror `RunBackfillJob`'s class shape (queue job invoking the runner; Task 5 supplies `BlockBackfillRunner`; for THIS task the job class can exist referencing it since Task 5 lands before the suite must pass — implement Tasks 4+5 back-to-back, run this task's service test after the runner class exists as a stub if needed).

`BlockMigrationController` — mirror `MigrationController` exactly (store/index/show; `MigrationData` DTO reused; slug → `BlockTypeRepository::findBySlug` 404; `ActiveMigrationException` → 409; `SchemaParseException` → 422). Routes after the block-types deactivate route, same middleware/permission chain as the existing block-type admin routes (copy their `->middleware(...)` calls verbatim):

```php
    $router->post('/block-types/{slug}/migrations', [BlockMigrationController::class, 'store'])
        /* same middleware chain as the PATCH /block-types/{slug} route */;
    $router->get('/block-types/{slug}/migrations', [BlockMigrationController::class, 'index'])
        /* same middleware chain as GET /block-types/{slug} */;
    $router->get('/block-types/{slug}/migrations/{migrationUuid}', [BlockMigrationController::class, 'show'])
        /* same middleware chain as GET /block-types/{slug} */;
```

Provider: register `BlockMigrationRepository`, `BlockMigrationService`, `BlockInstanceWalker`, `BlockMigrationController` (shared+autowire, mirroring the existing block-type entries), with `use` imports.

- [ ] **Step 3: Verify pass + gates** — service test + `tests/Integration/Content/`; phpcs.

---

### Task 5: `BlockBackfillRunner` + job + CLI

**Files:**
- Create: `app/Content/Blocks/Migration/BlockBackfillRunner.php`, `app/Content/Console/RunBlockBackfillCommand.php`; finish `app/Content/Jobs/RunBlockBackfillJob.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (runner + command registrations: `consoleCommandServices()` AND the `commands([...])` list)
- Test: `tests/Integration/Content/BlockBackfillRunnerTest.php`

**Interfaces:**
- Produces: `BlockBackfillRunner::run(string $migrationUuid): array{done:int,failed:int}`; CLI `lemma:blocks:migration:backfill {migration}` (re-drive).
- Consumes: Tasks 2–4.

- [ ] **Step 1: Failing test** (harness: block types `card`+`nest`, content type `page` with `body` blocks field, publish helper with the FULL validator — copy `BlockReferenceExpansionTest`'s `createPublished()` including the FieldValidator construction; declare via `BlockMigrationService`):

```php
    public function testBackfillRewritesDraftsAndPublicationsIncludingArchivedAndNested(): void
    {
        // Published source with nested card; then ARCHIVE it (spec §4: archived
        // entries are rewritten — not-deleted predicate, not active-only).
        $archived = $this->createPublished(['title' => 'A', 'body' => [
            ['id' => 'n', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'deep']],
            ]]],
        ]], 'archived-one');
        $this->connection()->table('entries')->where('uuid', '=', $archived)
            ->update(['status' => 'archived']);

        // A draft-only entry with a top-level card.
        $draft = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c2', 'type' => 'card', 'data' => ['title' => 'drafty']],
        ]]);

        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        $result = $this->runner()->run($migration);
        self::assertSame(0, $result['failed']);

        // Draft rewritten in place.
        $draftRow = $this->entries()->findDraft($draft, 'en');
        self::assertSame('drafty', $draftRow['fields']['body'][0]['data']['heading']);

        // Archived entry's publication: NEW version + repin, nested rewritten.
        $pinned = $this->pinnedFields($archived);
        self::assertSame('deep', $pinned['body'][0]['data']['inner'][0]['data']['heading']);

        // Status completed; accounting adds up.
        $row = $this->migrationRow($migration);
        self::assertSame('completed', $row['status']);
        self::assertSame((int) $row['work_items_total'], (int) $row['work_items_done']);
        self::assertNotNull($row['completed_at']);
    }

    public function testStaleLockCasMissCountsAsRedrivableFailureAndNeverClobbers(): void
    {
        // Exact precedent: BackfillRunnerTest.php:153 drives processDraft with a
        // STALE work item via reflection — the CAS must miss, the editor's newer
        // content must survive byte-identical, and the failure must be recorded.
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);

        $staleItem = [
            'entry_uuid' => $entry,
            'locale' => 'en',
            'fields' => json_encode(['title' => 'D', 'body' => [
                ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
            ]], JSON_THROW_ON_ERROR),
            'lock_version' => 0, // stale: the editor saved since (below)
        ];
        // "Editor save" after the work list was read: newer content + bumped lock.
        $editorFields = ['title' => 'D-edited', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'edited']],
        ]];
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->update([
            'fields' => json_encode($editorFields, JSON_THROW_ON_ERROR),
            'lock_version' => 7,
        ]);

        $runner = $this->runner();
        $m = new \ReflectionMethod($runner, 'processDraft');
        $m->invoke($runner, $migration, 'card', $this->opSetFor($migration), $this->pageSchema(), $staleItem);

        // Editor content survives byte-identical; failure recorded, re-drivable.
        $draft = $this->entries()->findDraft($entry, 'en');
        self::assertSame($editorFields, $draft['fields']);
        self::assertSame(7, (int) $draft['lock_version']);
        self::assertSame(1, (int) $this->migrationRow($migration)['work_items_failed']);
    }

    public function testOpCollisionCountsAsFailureAndMarksMigrationFailed(): void
    {
        // Separate coverage: an instance that ALREADY has the rename target makes
        // RenameField::apply throw MigrationCollisionException -> recorded failure,
        // end-of-run recount marks the migration failed (write gate stays closed).
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x', 'heading' => 'y']],
        ]]);
        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);

        $result = $this->runner()->run($migration);
        self::assertSame(1, $result['failed']);
        self::assertSame('failed', (string) $this->migrationRow($migration)['status']);
    }
```

(Write the helpers — `createPublished`, `draftOnly`, `declare`, `runner`, `entries`, `pinnedFields`, `migrationRow`, plus `opSetFor(string $migrationUuid): MigrationOpSet` (rehydrate from the migration row's `ops`) and `pageSchema(): ContentTypeSchema` — as thin wrappers over the repositories used in earlier tasks' tests; `declare` calls `BlockMigrationService::migrate` and returns the uuid. The reflection invocation requires `processDraft`'s signature to be `(string $migrationUuid, string $slug, MigrationOpSet $ops, ContentTypeSchema $schema, array $item)` — `run()` iterates per content type and passes each type's entry schema down; pin that signature in the implementation.)

- [ ] **Step 2: Verify fail**, then implement `BlockBackfillRunner` — mirror `BackfillRunner`'s structure with these deltas:

- Work lists: iterate ALL content types with blocks fields (reuse the Task 4 service's discovery shape); predicate `e.status != 'deleted'`; an item is remaining when `walker->hasOpSources(fields, schema, slug, opSet)` (there is no schema_version filter).
- `processDraft`: decode fields → `[$migrated, $changed] = walker->rewrite(...)` → if `!$changed` skip (not a work item; stay quiet) → CAS update on `lock_version` only (no schema_version guard exists):

```php
            $affected = $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entry)
                ->where('locale', '=', $locale)
                ->where('lock_version', '=', $expectedLock)
                ->update([
                    'fields' => json_encode($migrated, JSON_THROW_ON_ERROR),
                    'lock_version' => $expectedLock + 1,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
```

  On `$affected < 1`: re-read; if `hasOpSources` still true → `recordFailure(..., 'draft changed concurrently during backfill; re-run to migrate the latest content')`, else stay quiet. Wrap in try/catch recording failures (collisions land here).
- `processPublished`: mirror the advisory-lock + re-read-pin + append + pin + `references->rebuildForEntry` transaction from `BackfillRunner::processPublished` verbatim, with `walker->rewrite` instead of `opSet->apply` and no schema-version argument concerns — `appendVersion(..., $currentContentTypeSchemaVersion, $actor)` using the entry's content type's CURRENT `schema_version` (the fields were stored under it; block ops don't change content-type shape).
- End-of-run recount: re-scan both work lists; `finish($uuid, remaining === 0 ? 'completed' : 'failed')`.
- Cache invalidation: `lemma:type:{slug}` for every content type that had work items.

`RunBlockBackfillCommand`: `#[AsCommand(name: 'lemma:blocks:migration:backfill', description: 'Run or resume the backfill for a block-type schema migration')]`, one required `migration` argument, mirror `RunBackfillCommand`'s body (getService the runner, print done/failed, `self::SUCCESS`/`FAILURE` accordingly). Register in BOTH `consoleCommandServices()` and `commands([...])`.

`RunBlockBackfillJob`: mirror `RunBackfillJob` (resolve runner, `run($payload['migration_uuid'])`).

- [ ] **Step 3: Verify pass** — this task's test + re-run Task 4's service test + `tests/Integration/Content/`; phpcs; provider boundaries untouched but run `composer boundaries` once here.

---

### Task 6: Write gate

**Files:**
- Create: `app/Content/Blocks/BlockMigrationGate.php`, `app/Content/Blocks/Migration/BlockMigrationInProgressException.php`
- Modify: `app/Content/Http/Controllers/EntryController.php` (saveDraft), `app/Content/Services/PublishService.php` (publish), `app/Providers/LemmaServiceProvider.php` (gate registration + PublishService factory arg if PublishService is factory-built — check its current registration and mirror)
- Test: `tests/Integration/Content/BlockMigrationGateTest.php`

**Interfaces:**
- Produces: `BlockMigrationGate::assertWritable(array $fields, ContentTypeSchema $schema): void` — throws `BlockMigrationInProgressException($slug)` when any present slug has an active (running|failed) migration; cheap-first (one `activeAny()` query; walks only when non-empty). `EntryController::saveDraft` maps it to 409 `BLOCK_MIGRATION_IN_PROGRESS`; `PublishService::publish` calls it on the STORED draft fields before validation (nullable ctor dep — existing constructions keep working; the gate never fires for the backfill's own republish path, which bypasses `PublishService`).

- [ ] **Step 1: Failing test**

```php
    public function testSaveAndPublishAreGatedWhileMigrationActiveIncludingFailed(): void
    {
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        // Migration row is 'running' (backfill not run yet in this test).

        // SAVE 409s.
        $resp = $this->saveDraftViaController($entry, ['title' => 'D2', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x2']],
        ]]);
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('BLOCK_MIGRATION_IN_PROGRESS', (string) $resp->getContent());

        // PUBLISH 409s (stored draft contains the slug).
        try {
            $this->publishService()->publish($entry, 'en', 'user00000001');
            self::fail('expected gate');
        } catch (BlockMigrationInProgressException) {
            $this->addToAssertionCount(1);
        }

        // FAILED keeps the gate closed.
        $this->migrations()->finish($migration, 'failed');
        self::assertSame(409, $this->saveDraftViaController($entry, ['title' => 'D2', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x2']],
        ]])->getStatusCode());

        // COMPLETED opens it (payload uses the NEW schema key).
        $this->migrations()->finish($migration, 'completed');
        self::assertSame(200, $this->saveDraftViaController($entry, ['title' => 'D2', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['heading' => 'x2']],
        ]])->getStatusCode());
    }

    public function testEntriesWithoutTheMigratingTypeSaveNormally(): void
    {
        $entry = $this->draftOnly(['title' => 'Plain', 'body' => []]);
        $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        self::assertSame(200, $this->saveDraftViaController($entry, [
            'title' => 'Plain2', 'body' => [],
        ])->getStatusCode());
    }
```

(`saveDraftViaController` hydrates `SaveDraftData` via `RequestDataHydrator` and calls the container's `EntryController::saveDraft` — mirror the DTO-hydration pattern from `ExpansionCacheValidatorsTest::deliverShow`; include `lock_version` from `findDraft`.)

- [ ] **Step 2: Verify fail**, then implement:

`BlockMigrationInProgressException` (in `app/Content/Blocks/Migration/`):

```php
final class BlockMigrationInProgressException extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("block type '{$slug}' has a migration in progress");
    }
}
```

`BlockMigrationGate`:

```php
final class BlockMigrationGate
{
    public function __construct(
        private readonly BlockMigrationRepository $migrations,
        private readonly BlockInstanceWalker $walker,
    ) {
    }

    /**
     * Spec §3: block instances carry no schema stamp, so a write against a
     * flipped-but-unconverged schema is the silent-strip data-loss path. Cheap
     * first: one query (usually empty), the walk only when a migration is live.
     *
     * @param array<string,mixed> $fields
     */
    public function assertWritable(array $fields, ContentTypeSchema $schema): void
    {
        $active = $this->migrations->activeAny();
        if ($active === []) {
            return;
        }
        $present = $this->walker->slugsIn($fields, $schema);
        foreach ($active as $migration) {
            if (in_array($migration['slug'], $present, true)) {
                throw new BlockMigrationInProgressException($migration['slug']);
            }
        }
    }
}
```

`EntryController::saveDraft` — after schema lookup, before `$this->validator->validate(...)`:

```php
        try {
            $this->gate?->assertWritable($input->fields, $schema);
        } catch (BlockMigrationInProgressException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, [
                'code' => 'BLOCK_MIGRATION_IN_PROGRESS',
                'block_type' => $e->slug,
            ]);
        }
```

(ctor gains `private readonly ?BlockMigrationGate $gate = null` — trailing optional; verify the controller's provider registration autowires or extend its factory.)

`PublishService::publish` — after the draft lookup and publish-gates loop, before projection/validation:

```php
        // Block-migration write gate (spec §3): publish snapshots the stored draft.
        $this->blockGate?->assertWritable((array) $draft['fields'], $schema);
```

(ctor gains trailing `private readonly ?BlockMigrationGate $blockGate = null`; `PublicationController::publish` maps the exception to a 409 — add a catch mirroring its existing error mapping with code `BLOCK_MIGRATION_IN_PROGRESS`.) NOTE the backfill republishes via `VersionRepository` directly, never through `PublishService::publish` — the gate cannot deadlock the migration's own convergence.

- [ ] **Step 3: Verify pass + gates** — this test + `tests/Integration/Content/` + the Workflow suite (`tests/Integration/Workflow/` — publish-path change).

---

### Task 7: Restore projection + µs `appendVersion`

**Files:**
- Create: `app/Content/Blocks/BlockRestoreProjector.php`, `app/Content/Blocks/Migration/UnknownBlockTypeException.php`
- Modify: `app/Content/Repositories/VersionRepository.php` (µs `created_at`), `app/Content/Services/PublishService.php` (rollback), `app/Content/Http/Controllers/PublicationController.php` (rollback error mapping)
- Test: `tests/Integration/Content/BlockRestoreProjectionTest.php`

**Interfaces:**
- Produces: `BlockRestoreProjector::project(array $fields, ContentTypeSchema $schema, string $versionCreatedAt): array{0: array<string,mixed>, 1: bool}` — throws `UnknownBlockTypeException($slug)` when a present slug is missing from the registry; otherwise applies each present type's `completedAfter()` suffix in `created_at ASC` order via the walker. `PublishService::rollback` uses it: changed → strict-validate + append new version (µs) + pin; unchanged → existing re-pin path byte-identical.
- **P1 pin — rollback reports the ACTUAL pinned version:** `PublishService::rollback()` now returns `array{version_uuid: string, version: int}` — the REQUESTED version on the re-pin path, the APPENDED version on the materialized path. `PublicationController::rollback` returns that actual `version_uuid` (today it echoes the input — wrong once materialization exists), and the emitted `EntryPublished` carries the ACTUAL pinned version number, never the old target's.
- Consumes: Tasks 2–3.

- [ ] **Step 1: µs fix first (tiny, enables the same-second test)** — in `VersionRepository::appendVersion()` and `pin()`, replace `date('Y-m-d H:i:s')` with `(new \DateTimeImmutable())->format('Y-m-d H:i:s.u')` for `created_at`/`published_at`. Run `tests/Integration/Content/` — expect green (consumers treat these as opaque strings; any test pinning an exact second-precision format fails loudly here and gets updated to a regex).

- [ ] **Step 2: Failing test**

```php
    public function testRollbackProjectsTheTimestampSuffixOnly(): void
    {
        // Era 1: field `title`. Publish v1.
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'era1']],
        ]], 'restore-me');
        $v1 = $this->pinnedVersionUuid($entry);

        // Migration A: title -> heading. Backfill converges (v2 republished).
        $mA = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        $this->runner()->run($mA);

        // Migration B: heading -> caption. Converges (v3).
        $mB = $this->declare([['op' => 'rename', 'from' => 'heading', 'to' => 'caption']]);
        $this->runner()->run($mB);

        // Rollback to v1: BOTH migrations postdate it -> full suffix applies,
        // and a NEW version materializes (append-and-repin), not a re-pin of v1.
        $this->publishService()->rollback($entry, 'en', $v1, 'user00000001');
        $pinned = $this->pinnedFields($entry);
        self::assertSame('era1', $pinned['body'][0]['data']['caption']);
        self::assertNotSame($v1, $this->pinnedVersionUuid($entry));

        // Rollback to the migration-B-era version (backfill-created): its
        // created_at postdates B -> NO reprojection, plain re-pin.
        $v3 = $this->versionUuidWithFieldValue($entry, 'caption', 'era1');
        $this->publishService()->rollback($entry, 'en', $v3, 'user00000001');
        self::assertSame($v3, $this->pinnedVersionUuid($entry));
    }

    public function testRenameReuseChainRestoresCorrectlyPerEra(): void
    {
        // a -> b (migration 1), then b -> a (migration 2). An era-1 version
        // (field `a`) projected through BOTH lands back on `a` — and an era-2
        // version (field `b`) gets ONLY migration 2. Blind full-chain application
        // would corrupt era-2 data; the suffix cannot.
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'flip', 'data' => ['a' => 'one']],
        ]], 'flip-entry');
        $v1 = $this->pinnedVersionUuid($entry);
        $this->runner()->run($this->declareFor('flip', [['op' => 'rename', 'from' => 'a', 'to' => 'b']]));
        $v2 = $this->pinnedVersionUuid($entry); // backfill version: {b: one}
        $this->runner()->run($this->declareFor('flip', [['op' => 'rename', 'from' => 'b', 'to' => 'a']]));

        $this->publishService()->rollback($entry, 'en', $v1, null); // suffix = both
        self::assertSame('one', $this->pinnedFields($entry)['body'][0]['data']['a']);

        $this->publishService()->rollback($entry, 'en', $v2, null); // suffix = only #2
        self::assertSame('one', $this->pinnedFields($entry)['body'][0]['data']['a']);
    }

    public function testSameSecondPrecisionAndDeletedTypeBlock(): void
    {
        // Same-second (spec §5 precision pin), made DETERMINISTIC: after creating
        // the rows, pin exact microsecond timestamps directly in the DB so the
        // test proves ordering rather than racing the wall clock.
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'fast']],
        ]], 'fast-entry');
        $v1 = $this->pinnedVersionUuid($entry);
        $m = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        $this->runner()->run($m);
        $vBackfill = $this->pinnedVersionUuid($entry); // backfill-created version

        // One wall-clock second, three distinct microsecond instants:
        $this->connection()->table('entry_versions')->where('uuid', '=', $v1)
            ->update(['created_at' => '2026-07-03 12:00:00.100000']);
        $this->connection()->table('lemma_block_type_migrations')->where('uuid', '=', $m)
            ->update(['created_at' => '2026-07-03 12:00:00.200000']);
        $this->connection()->table('entry_versions')->where('uuid', '=', $vBackfill)
            ->update(['created_at' => '2026-07-03 12:00:00.300000']);

        // v1 (.1) predates the migration (.2) -> suffix applies.
        $result = $this->publishService()->rollback($entry, 'en', $v1, null);
        self::assertSame('fast', $this->pinnedFields($entry)['body'][0]['data']['heading']);
        // P1: the reported version is the MATERIALIZED one, not the requested v1.
        self::assertNotSame($v1, $result['version_uuid']);
        self::assertSame($result['version_uuid'], $this->pinnedVersionUuid($entry));

        // The backfill version (.3) postdates the migration (.2) -> plain re-pin,
        // and the reported version IS the requested one.
        $result = $this->publishService()->rollback($entry, 'en', $vBackfill, null);
        self::assertSame($vBackfill, $result['version_uuid']);
        self::assertSame($vBackfill, $this->pinnedVersionUuid($entry));

        // Deleted type: restoring a version containing it is BLOCKED before write.
        $ghostEntry = $this->createPublished(['title' => 'G', 'body' => [
            ['id' => 'g', 'type' => 'solo', 'data' => ['x' => '1']],
        ]], 'ghost-entry');
        $vG = $this->pinnedVersionUuid($ghostEntry);
        $this->publishService()->unpublish($ghostEntry, 'en');
        $this->drainUsageAndDelete('solo'); // zero current usage now -> hard delete
        try {
            $this->publishService()->rollback($ghostEntry, 'en', $vG, null);
            self::fail('expected UnknownBlockTypeException');
        } catch (UnknownBlockTypeException $e) {
            self::assertSame('solo', $e->slug);
        }
        // Nothing was pinned.
        self::assertNull($this->versions()->findPublication($ghostEntry, 'en'));
    }
```

(Helpers reuse Task 5's; `declareFor` targets a second block type `flip` seeded in setUp; `drainUsageAndDelete` clears the draft (`discard`/direct delete of the draft row) then calls `BlockTypeRepository::deleteBySlug` — Task 8's endpoint isn't needed for this unit-level delete. `versionUuidWithFieldValue` scans `entry_versions` for the row whose decoded fields match.)

- [ ] **Step 3: Verify fail**, then implement:

`UnknownBlockTypeException`:

```php
final class UnknownBlockTypeException extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("unknown block type '{$slug}' (hard-deleted?) — cannot restore this version");
    }
}
```

`BlockRestoreProjector` (in `app/Content/Blocks/`):

```php
final class BlockRestoreProjector
{
    public function __construct(
        private readonly BlockTypeRepository $registry,
        private readonly BlockMigrationRepository $migrations,
        private readonly BlockInstanceWalker $walker,
    ) {
    }

    /**
     * Spec §5: one-shot projection at restore. Unknown slug -> blocked before any
     * write; otherwise each present type's COMPLETED timestamp suffix
     * (created_at > versionCreatedAt, ASC) applies via the shared walker. Ops are
     * tolerant, but only within the suffix — never the full chain.
     *
     * @param array<string,mixed> $fields
     * @return array{0: array<string,mixed>, 1: bool} [projected fields, changed]
     */
    public function project(array $fields, ContentTypeSchema $schema, string $versionCreatedAt): array
    {
        $present = $this->walker->slugsIn($fields, $schema);
        if ($present === []) {
            return [$fields, false];
        }
        $known = $this->registry->schemasBySlug();
        $byUuid = [];
        foreach ($present as $slug) {
            if (!isset($known[$slug])) {
                throw new UnknownBlockTypeException($slug);
            }
            $row = $this->registry->findBySlug($slug);
            $byUuid[$slug] = (string) $row['uuid'];
        }

        $changed = false;
        foreach ($byUuid as $slug => $typeUuid) {
            foreach ($this->migrations->completedAfter($typeUuid, $versionCreatedAt) as $migration) {
                [$fields, $stepChanged] = $this->walker->rewrite(
                    $fields,
                    $schema,
                    $slug,
                    MigrationOpSet::fromArray((array) $migration['ops']),
                );
                $changed = $changed || $stepChanged;
            }
        }
        return [$fields, $changed];
    }
}
```

`PublishService::rollback` — after the version/entry checks, before the transaction:

```php
        $projectedFields = null;
        if ($this->blockRestore !== null && $schema !== null) {
            [$candidate, $blockChanged] = $this->blockRestore->project(
                (array) $version['fields'],
                $schema,
                (string) $version['created_at'],
            );
            if ($blockChanged) {
                // Spec §5: materialize — the projected content is validated
                // strictly and appended as a NEW version; plain re-pin would
                // reintroduce stale keys into current content.
                $projectedFields = $this->validator->validate($schema, $candidate, true);
            }
        }
```

…then inside the transaction: when `$projectedFields !== null`, `reserveNextVersionNumber` + `appendVersion($entryUuid, $locale, $number, $projectedFields, <current content-type schema_version>, $actor)` + `pin(new)` + `rebuildForEntry(..., $projectedFields, ...)`; else the existing re-pin body unchanged. Ctor gains trailing `private readonly ?BlockRestoreProjector $blockRestore = null`.

**P1 — return the ACTUAL pinned version.** `rollback()`'s signature becomes:

```php
    /**
     * Re-pin an existing version — or, when block-migration projection changes its
     * fields (spec §5), MATERIALIZE a new projected version and pin that. Returns
     * the version actually pinned: the requested one on the re-pin path, the
     * appended one on the materialized path. Callers must report THIS, not their
     * input — and the EntryPublished event carries this version number.
     *
     * @return array{version_uuid: string, version: int}
     */
    public function rollback(string $entryUuid, string $locale, string $versionUuid, ?string $actor): array
```

The materialized branch captures `$number`/`$newUuid` from the transaction and returns those; the re-pin branch returns the requested `$versionUuid` + `(int) $version['version']`. The `EntryPublished` emission uses the RETURNED version number in both branches. `PublicationController::rollback` becomes:

```php
        try {
            $pinned = $this->publisher->rollback($uuid, $locale, $input->version_uuid, $this->actor($request));
        } catch (UnknownBlockTypeException $e) {
            return Response::validation(['version_uuid' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return Response::validation(['version_uuid' => $e->getMessage()]);
        }
        return Response::success($pinned, 'Rolled back to version.');
```

(The explicit `UnknownBlockTypeException` catch documents the contract; the body key changes from echoing the INPUT `version_uuid` to reporting the ACTUAL pinned `{version_uuid, version}` — grep the SPA's rollback consumer (`admin/src/queries/versions.ts`) for a shape dependency and adjust it if it reads the old key.)

Provider: register `BlockRestoreProjector`; extend PublishService's registration (factory or autowire — mirror how `publishGates`/`projector` got wired; find its current binding with `grep -n "PublishService" app/Providers/LemmaServiceProvider.php`).

Add one CONTROLLER-level test (same file) asserting the P1 contract end-to-end:

```php
    public function testRollbackEndpointReportsTheMaterializedVersion(): void
    {
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'era1']],
        ]], 'http-restore');
        $v1 = $this->pinnedVersionUuid($entry);
        $this->runner()->run($this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]));

        $resp = $this->rollbackViaController($entry, $v1); // hydrates RollbackData, calls the controller
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        // The response names the MATERIALIZED version, not the requested v1.
        self::assertNotSame($v1, $body['data']['version_uuid']);
        self::assertSame($body['data']['version_uuid'], $this->pinnedVersionUuid($entry));
        self::assertIsInt($body['data']['version']);
    }
```

(`rollbackViaController` mirrors the DTO-hydration pattern from Task 6's `saveDraftViaController`, targeting `PublicationController::rollback`.)

- [ ] **Step 4: Verify pass + gates** — this test + `tests/Integration/Content/` + `tests/Integration/Workflow/` (rollback signature change: fix any existing caller/tests that treated it as void — find them with `grep -rn "->rollback(" app tests admin/src`).

---

### Task 8: Usage endpoint + hard-delete endpoint

**Files:**
- Create: `app/Content/Blocks/BlockUsageScanner.php`
- Modify: `app/Content/Http/Controllers/BlockTypeController.php` (add `usage()` + `destroy()`), `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php` (scanner)
- Test: `tests/Integration/Content/BlockUsageAndDeleteTest.php`

**Interfaces:**
- Produces: `BlockUsageScanner::usage(string $slug): array{total: int, per_type: list<array{type: string, drafts: int, publications: int, sample: list<array{entry_uuid: string, title: ?string}>}>, allowlists: list<string>}`; `GET /block-types/{slug}/usage`; `DELETE /block-types/{slug}` (409 on usage>0 via server-side re-scan, 409 on active migration, 404 unknown slug, 200 on delete).

- [ ] **Step 1: Failing test**

```php
    public function testUsageCountsCurrentAndArchivedNestedButNotHistoricalAndReportsAllowlists(): void
    {
        // Content type with a block_types allowlist naming `card` (report-only).
        $this->createContentTypeWithAllowlist('landing', ['card']);

        // Draft-only usage + archived published usage (nested).
        $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        $archived = $this->createPublished(['title' => 'A', 'body' => [
            ['id' => 'n', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'c2', 'type' => 'card', 'data' => ['title' => 'deep']],
            ]]],
        ]], 'arch');
        $this->connection()->table('entries')->where('uuid', '=', $archived)
            ->update(['status' => 'archived']);

        // HISTORICAL-only usage: publish with card, then republish WITHOUT it —
        // the old version still has it; must NOT count.
        $hist = $this->createPublished(['title' => 'H', 'body' => [
            ['id' => 'c3', 'type' => 'card', 'data' => ['title' => 'old']],
        ]], 'hist');
        $this->republish($hist, ['title' => 'H', 'body' => []]);
        // Its draft also carries the emptied body now (republish helper saves the
        // draft first), so neither surface counts for `hist`.

        $usage = $this->scanner()->usage('card');
        self::assertSame(2, $usage['total']);
        self::assertContains('landing', $usage['allowlists']);

        // Allowlist-only usage does not gate: `nest`'s usage for a type never
        // instantiated is zero even while allowlisted.
        $this->createContentTypeWithAllowlist('promo', ['solo']);
        self::assertSame(0, $this->scanner()->usage('solo')['total']);
        self::assertContains('promo', $this->scanner()->usage('solo')['allowlists']);
    }

    public function testDeleteGatesServerSideAndDeletesAtZero(): void
    {
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        self::assertSame(409, $this->deleteViaController('card')->getStatusCode());

        // Remove the usage; delete succeeds; registry row is GONE.
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->delete();
        self::assertSame(200, $this->deleteViaController('card')->getStatusCode());
        self::assertNull((new BlockTypeRepository($this->connection()))->findBySlug('card'));

        // Unknown slug -> 404.
        self::assertSame(404, $this->deleteViaController('card')->getStatusCode());
    }

    public function testDeleteBlockedDuringActiveMigration(): void
    {
        $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]); // running
        self::assertSame(409, $this->deleteViaController('card')->getStatusCode());
    }
```

- [ ] **Step 2: Verify fail**, then implement `BlockUsageScanner` (constructor: `Connection`, `ContentTypeRepository`, `BlockInstanceWalker`): iterate `contentTypes->all()`; skip schemas without blocks fields for INSTANCE counting but still check every schema's blocks-field `blockTypes` allowlists (`FieldDefinition::$blockTypes`) for the reporting list; count a draft/publication row when `walker->slugsIn(decoded fields, schema)` contains the slug (predicate `e.status != 'deleted'`, drafts + pinned publications, all locales); collect ≤5 samples (`entry_uuid`, best-effort `fields['title']` when it is a string). Endpoints in `BlockTypeController`:

```php
    public function usage(Request $request, string $slug): Response
    {
        if ($this->blockTypes->findBySlug($slug) === null) {
            return Response::notFound('Block type not found.');
        }
        return Response::success($this->usageScanner->usage($slug), 'Block type usage.');
    }

    public function destroy(Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Block type not found.');
        }
        if ($this->blockMigrations->activeForType((string) $row['uuid']) !== null) {
            return Response::error(
                'A migration is active for this block type.',
                Response::HTTP_CONFLICT,
                ['code' => 'BLOCK_MIGRATION_IN_PROGRESS'],
            );
        }
        $usage = $this->usageScanner->usage($slug); // server-side re-scan (spec §6)
        if ($usage['total'] > 0) {
            return Response::error(
                'Block type is in use by current content.',
                Response::HTTP_CONFLICT,
                ['code' => 'BLOCK_TYPE_IN_USE', 'usage' => $usage],
            );
        }
        $this->blockTypes->deleteBySlug($slug);
        return Response::success(['slug' => $slug], 'Block type deleted.');
    }
```

(Ctor gains `BlockUsageScanner` + `BlockMigrationRepository` — trailing params; update the provider registration. Add the `#[ApiOperation]`/`#[ApiResponse]` attribute blocks mirroring the controller's existing endpoints, including 409 docs.) Routes:

```php
    $router->get('/block-types/{slug}/usage', [BlockTypeController::class, 'usage'])
        /* middleware chain of GET /block-types/{slug} */;
    $router->delete('/block-types/{slug}', [BlockTypeController::class, 'destroy'])
        /* middleware chain of PATCH /block-types/{slug} */;
```

- [ ] **Step 3: Verify pass + gates** — this test + full `tests/Integration/Content/`.

---

### Task 9: Admin SPA — usage panel, delete, migrate dialog, gate error

**Files:**
- Modify: `admin/src/queries/blockTypes.ts` (add `fetchBlockTypeUsage(slug)`, `deleteBlockType(slug)`, `declareBlockTypeMigration(slug, ops)`, `fetchBlockTypeMigrations(slug)` via the existing authFetch/query patterns in that file — hand-built URLs, matching its current style)
- Modify: `admin/src/pages/settings/block-types/[slug].vue` — add a "Danger zone" section: usage summary (fetched on mount of the section, `data-testid="block-usage"`), Delete button (disabled while `usage.total > 0`, `data-testid="block-delete"`, confirmation via the page's existing confirm pattern), and a "Migrate fields" dialog (`data-testid="block-migrate"`): rows of `rename from→to` / `delete name` ops built against the current schema fields, POSTs to the migrations endpoint, then polls/refreshes the migration list showing status + `work_items_done/total` (+ failed count); while a migration is `running`/`failed`, the schema editor's save is disabled with an explanatory note and the migrate button shows the active state.
- Modify: the entry editor's save-error handling — surface a 409 with `code === 'BLOCK_MIGRATION_IN_PROGRESS'` as a distinct toast/banner ("Block type '{slug}' is being migrated — try again when the migration completes"). Locate the saveDraft error branch via `grep -rn "STALE_DRAFT" admin/src` and add the sibling case beside it.
- Test: `admin/src/__tests__/block-type-lifecycle.spec.ts`

**Interfaces:** UButton handlers are void methods (no inline assignment expressions — Nuxt UI onClick typing); assert `data-testid` hooks, never Nuxt UI internals or portal DOM (recorded harness rules).

- [ ] **Step 1: Failing component test** — mount the `[slug].vue` page (or extracted components if the page splits them out — follow how existing settings-page specs in `admin/src/__tests__/` mount and stub queries): assert (a) usage renders total from a mocked usage response and Delete is disabled at `total > 0`, enabled at 0; (b) the migrate dialog submits `{ops:[{op:'rename',from,to}]}` to the mocked mutation; (c) with a mocked active migration (`status: 'running'`), schema save is disabled and the migrate control shows progress; (d) the entry-editor error mapper turns the 409 payload `{code:'BLOCK_MIGRATION_IN_PROGRESS', block_type:'card'}` into the named banner (unit-test the mapping function if the editor page is too heavy to mount — extract it if needed).

- [ ] **Step 2: Verify fail, implement, verify pass** — `cd admin && pnpm test` and `pnpm type-check` (both must exit 0; never pipe tsc through tail).

---

### Task 10: Docs + full verification + STAGE

- [ ] **Step 1: CHANGELOG `[Unreleased]`** — append to the block-builder bullet family:

```markdown
  Follow-up: **block-schema migrations + hard-delete** — block-type schema edits
  are now additive-only; renames/deletes are declared migrations (rename/delete
  ops, one active per type) with an eager queued backfill that rewrites every
  current draft and republishes every pinned publication (non-deleted entries,
  archived included, nested to the depth cap). While a migration is active
  (running OR failed), saving/publishing entries containing that block type 409s
  — closing the unstamped-instance data-loss window. Version rollback projects
  block data once through the completed-migration timestamp suffix (microsecond
  precision) and materializes a new version when anything changed; restoring a
  version that references a hard-deleted block type is blocked with a clear
  error. New usage endpoint (current drafts + publications, archived included,
  nested; historical versions excluded; picker allowlists reported, not gating)
  and zero-usage-gated hard delete (server-side re-scan, no force flag).
  `entry_versions.created_at` now persists microseconds.
```

- [ ] **Step 2: README** — lemma root README/docs where the block builder is documented (`grep -rn "block" README.md docs/*.md | head` to find the right section): add a short "Changing block schemas" paragraph (additive edits free; rename/delete via `POST /block-types/{slug}/migrations`; write gate; `lemma:blocks:migration:backfill` re-drive; delete via `DELETE /block-types/{slug}` at zero usage).

- [ ] **Step 3: Full verification**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
cd admin && pnpm type-check && pnpm test && cd ..
```

Expected: all green (single pre-existing Integration skip).

- [ ] **Step 4: STAGE** *(commit only when authorized)*

```bash
git add database/migrations/018_CreateLemmaBlockTypeMigrationsTable.php \
        app/Content/Blocks app/Content/Jobs/RunBlockBackfillJob.php \
        app/Content/Console/RunBlockBackfillCommand.php \
        app/Content/Http/Controllers app/Content/Services/PublishService.php \
        app/Content/Repositories/VersionRepository.php routes/lemma_admin.php \
        app/Providers/LemmaServiceProvider.php tests/Support/LemmaTestCase.php \
        tests/Integration/Content admin/src CHANGELOG.md README.md docs/superpowers
```

STOP — when authorized:

```bash
git commit -m "feat(content): block-schema migrations + usage-gated hard delete

Block-type schema edits are now additive-only (field removal/rename rejects
with a pointer at the migration flow). Declared migrations (rename/delete ops,
content-type collision rules, one active per type) record into
lemma_block_type_migrations — no version numbers: microsecond created_at IS
the chain identity — flip the registry schema atomically, and queue an eager
backfill that rewrites current drafts in place (lock CAS) and republishes
pinned publications as new versions, across all non-deleted entries (archived
included) and nested blocks to the depth cap. A write gate closes the
unstamped-instance data-loss window: while a migration is running OR failed,
saves/publishes of entries containing the type 409 (BLOCK_MIGRATION_IN_PROGRESS).

Rollback now projects block data once through the completed-migration
timestamp suffix (strictly-after, microsecond precision — appendVersion writes
.u timestamps) and materializes a validated new version when projection
changed anything; plain re-pin otherwise. Restoring a version that references
a hard-deleted block type is blocked before write.

Hard delete: GET /block-types/{slug}/usage scans current drafts + pinned
publications (archived included, nested; historical versions excluded; picker
allowlists reported, never gating); DELETE re-runs the scan server-side and
refuses at nonzero usage or during an active migration. No force flag.
Shared BlockInstanceWalker keeps backfill/gate/restore/usage on one walk."
```

---

## Self-Review Notes (applied)

- **Spec coverage:** §1 → Task 1; §2 → Tasks 2+4 (incl. failed-blocks-declaration); §3 → Task 6 (save payload + publish stored-draft, backfill bypass note); §4 → Task 5 (not-deleted predicate pinned in test with an ARCHIVED entry, nested, CAS failure, recount, cache invalidation); §5 → Task 7 (materialize-vs-repin, suffix-only incl. rename-reuse test, same-second µs test, strict `>`, deleted-type block, µs write fix); §6 → Task 8 (report-only allowlists, historical-only usage excluded, server-side re-scan, active-migration 409); §7 → Task 9; §8 respected (no stamps/read-path projection anywhere); §9 test matrix mapped across Tasks 1–9.
- **Type consistency:** walker API (`slugsIn/rewrite/hasOpSources`) identical across Tasks 3–8; `activeForType` running|failed in Tasks 2/4/6/8; `completedAfter(blockTypeUuid, timestamp)` in Tasks 2/7; exception names/copy match the Global Constraints pins.
- **Verify-don't-guess flags:** `ActiveMigrationException` namespace (Task 4), `whereRaw` vs `where('!=')` (Task 4), PublishService/EntryController provider wiring for new nullable deps (Tasks 6–7), registry test file existence (Task 1), µs column precision fallback (Task 2 Step 4), SPA mount patterns + saveDraft error branch location (Task 9), SPA rollback-response shape dependency in `admin/src/queries/versions.ts` (Task 7 P1).
- **Review fixes (applied):** P1 — `rollback()` returns `{version_uuid, version}` (actual pinned), controller reports it, `EntryPublished` emits the actual version number, controller-level test added; P2 — same-second µs test made deterministic via direct DB timestamp pins (.1/.2/.3 within one second); P2 — stale-lock CAS miss test restored via reflection (BackfillRunnerTest.php:153 precedent, `processDraft(string $migrationUuid, string $slug, MigrationOpSet $ops, ContentTypeSchema $schema, array $item)` signature pinned), with the op-collision case kept as separate coverage.
- **Sequencing note:** Tasks 4 and 5 are one deliverable pair (the service queues the Task 5 job); implement consecutively, running Task 4's tests once the runner exists.
