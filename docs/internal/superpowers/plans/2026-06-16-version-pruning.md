# Version Retention / Pruning — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Shipped (2026-06-17) — implemented, reviewed, and merged. Steps left as `[ ]` for historical reference.

**Goal:** Add opt-in, configurable pruning of `entry_versions` history — keep-N and/or age-based — that deletes old version snapshots for each `(entry, locale)` lineage **without ever deleting the currently-pinned version**, with the `glueful/import-export` bundle as the archival safety net. Pruning is off by default; the only deletion path this iteration is a deliberate operator CLI command.

**Architecture:** Three units plus config + registration. `RetentionPolicy` is an immutable value object **and** the validation barrier — `RetentionPolicy::fromValues($keep, $maxAgeDays)` rejects `0`/negative/non-numeric (throwing `InvalidRetentionPolicyException`) and treats null/empty as "that dimension off"; `isEnabled()` is true when at least one dimension is set. `VersionPruner::prune(RetentionPolicy $policy, bool $dryRun = false): PruneReport` is the unit of logic: it enumerates each `(entry_uuid, locale)` lineage, computes the deletable version set (keep-N floor ∪ age-survivors ∪ the pinned row), and issues a per-lineage parameterized `DELETE` that carries an in-statement `NOT EXISTS (SELECT 1 FROM entry_publications p WHERE p.version_uuid = entry_versions.uuid)` guard so a row pinned by a concurrent `rollback()` between selection and delete is skipped atomically. `PruneReport` is a small DTO (`lineagesScanned`, `versionsDeleted`, `versionsRetained`, `pinnedSkipped`). The `lemma:versions:prune` console command is the only thin wrapper over the pruner (`--dry-run`, `--keep=`, `--max-age-days=`). No new tables, no schema change, no new content event, no cron job, no admin HTTP API this iteration. PostgreSQL only.

**Tech Stack:** PHP 8.3, PostgreSQL (required — `now()`, set-based ranking, bound params, the delete-time `NOT EXISTS` guard), PHPUnit 10, Glueful framework (`BaseCommand`, `Connection`/raw PDO, `config()` helper, service-provider `services()` + `commands()`). Tests run on Postgres via `App\Tests\Support\LemmaTestCase`.

**Spec:** `docs/superpowers/specs/2026-06-16-version-pruning-design.md`

---

## File map

- Create: `app/Content/Retention/RetentionPolicy.php` — immutable value object + validation barrier (`fromValues`, `isEnabled`).
- Create: `app/Content/Retention/InvalidRetentionPolicyException.php` — thrown by `RetentionPolicy::fromValues` on a present-but-invalid value.
- Create: `app/Content/Retention/PruneReport.php` — counts DTO (`lineagesScanned`, `versionsDeleted`, `versionsRetained`, `pinnedSkipped`) + `toArray()`.
- Create: `app/Content/Retention/VersionPruner.php` — the prune service; owns the per-lineage selection + guarded DELETE.
- Create: `app/Content/Console/PruneVersionsCommand.php` — `lemma:versions:prune` operator command (`--dry-run`, `--keep`, `--max-age-days`).
- Modify: `config/lemma.php` — add the `versions.retention` block (raw env, no cast).
- Modify: `app/Providers/LemmaServiceProvider.php` — register `VersionPruner` + `PruneVersionsCommand`; add the command to `commands([...])`.
- Create test: `tests/Unit/Content/RetentionPolicyTest.php` — pure validation unit (no DB).
- Create test: `tests/Integration/Content/VersionPrunerTest.php` — keep-N, keep-N-protects-pin, age, combined, disabled, dry-run, per-lineage isolation, no-publication, FK-safety, delete-time pin race, idempotency, historical-preview-token 404.
- Create test: `tests/Integration/Console/PruneVersionsCommandTest.php` — command smoke + `--dry-run`/`--keep`/`--max-age-days` + invalid-policy-fails-loud.

All Integration/Console tests run on Postgres via `LemmaTestCase`. Conventions: `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col. No new migration (pruning is `DELETE FROM entry_versions WHERE …`).

---

### Task 1: `RetentionPolicy` value object + `InvalidRetentionPolicyException` (validation barrier)

**Files:**
- Create: `app/Content/Retention/RetentionPolicy.php`, `app/Content/Retention/InvalidRetentionPolicyException.php`
- Test: `tests/Unit/Content/RetentionPolicyTest.php` (pure unit — no DB, extends `PHPUnit\Framework\TestCase`)

- [ ] **Step 1: Write the failing unit test.** Create `tests/Unit/Content/RetentionPolicyTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Retention\InvalidRetentionPolicyException;
use App\Content\Retention\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function testNullAndEmptyDimensionsMeanDisabled(): void
    {
        $p = RetentionPolicy::fromValues(null, null);
        self::assertNull($p->keep);
        self::assertNull($p->maxAgeDays);
        self::assertFalse($p->isEnabled());

        $q = RetentionPolicy::fromValues('', '');
        self::assertNull($q->keep);
        self::assertNull($q->maxAgeDays);
        self::assertFalse($q->isEnabled(), 'empty strings are "unset", not zero');
    }

    public function testValidPositiveIntegersEnablePolicy(): void
    {
        $p = RetentionPolicy::fromValues('5', null);
        self::assertSame(5, $p->keep);
        self::assertNull($p->maxAgeDays);
        self::assertTrue($p->isEnabled());

        $q = RetentionPolicy::fromValues(null, '30');
        self::assertNull($q->keep);
        self::assertSame(30, $q->maxAgeDays);
        self::assertTrue($q->isEnabled());

        $r = RetentionPolicy::fromValues(2, 90); // ints accepted as well as numeric strings
        self::assertSame(2, $r->keep);
        self::assertSame(90, $r->maxAgeDays);
        self::assertTrue($r->isEnabled());
    }

    /** @return iterable<string, array{0: mixed, 1: mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'keep zero' => ['0', null];
        yield 'keep negative' => ['-1', null];
        yield 'keep non-numeric' => ['abc', null];
        yield 'keep float' => ['1.5', null];
        yield 'age zero' => [null, '0'];
        yield 'age negative' => [null, '-7'];
        yield 'age non-numeric' => [null, 'soon'];
        yield 'keep zero int' => [0, null];
    }

    /**
     * @dataProvider invalidValues
     */
    public function testPresentButInvalidValueFailsLoud(mixed $keep, mixed $maxAge): void
    {
        $this->expectException(InvalidRetentionPolicyException::class);
        RetentionPolicy::fromValues($keep, $maxAge);
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter RetentionPolicyTest`
Expected: FAIL — `Class "App\Content\Retention\RetentionPolicy" not found`.

- [ ] **Step 3: Implement the exception + value object.**

`app/Content/Retention/InvalidRetentionPolicyException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Retention;

/**
 * Thrown by {@see RetentionPolicy::fromValues()} when a retention dimension is
 * present but not a positive integer (0, negative, non-numeric, or non-integer).
 * Construction fails loud so a destructive prune never runs on a nonsensical policy.
 */
final class InvalidRetentionPolicyException extends \InvalidArgumentException
{
}
```

`app/Content/Retention/RetentionPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Retention;

/**
 * Immutable retention policy AND the single validation barrier for "what counts as a
 * valid policy" (spec §Configuration). Each dimension is either absent (null/'' ⇒ that
 * dimension off) or a positive integer (≥ 1). Anything present-but-invalid — 0, a
 * negative number, a non-numeric string, a non-integer — throws
 * InvalidRetentionPolicyException BEFORE any deletion. keep=0 is rejected (it would mean
 * "keep zero versions" → delete all non-pinned history), never silently treated as off.
 *
 *  - keep:        retain the N most recent versions per (entry, locale).
 *  - maxAgeDays:  delete versions whose created_at is older than now() - N days.
 *
 * When both are set a version survives if it satisfies EITHER rule (keep-N is a floor).
 */
final class RetentionPolicy
{
    private function __construct(
        public readonly ?int $keep,
        public readonly ?int $maxAgeDays,
    ) {
    }

    /**
     * Build from raw config/CLI values. null or '' ⇒ that dimension off; otherwise the
     * value must be a positive-integer string/int or construction throws.
     */
    public static function fromValues(mixed $keep, mixed $maxAgeDays): self
    {
        return new self(
            self::parseDimension($keep, 'keep'),
            self::parseDimension($maxAgeDays, 'max_age_days'),
        );
    }

    /** True when at least one dimension is set; a disabled policy is a guaranteed no-op. */
    public function isEnabled(): bool
    {
        return $this->keep !== null || $this->maxAgeDays !== null;
    }

    private static function parseDimension(mixed $value, string $name): ?int
    {
        // Absent ⇒ that dimension is off.
        if ($value === null || $value === '') {
            return null;
        }

        // Accept an int directly or a string that is exactly a base-10 integer. Reject
        // floats ('1.5'), non-numerics ('abc'), and any value < 1 (0 and negatives).
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $int = (int) $value;
        } else {
            throw new InvalidRetentionPolicyException(
                "retention '{$name}' must be a positive integer or unset; got: "
                . var_export($value, true)
            );
        }

        if ($int < 1) {
            throw new InvalidRetentionPolicyException(
                "retention '{$name}' must be >= 1 (0 or negative is not a valid policy); got: {$int}"
            );
        }

        return $int;
    }
}
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter RetentionPolicyTest`
Expected: PASS (all dataset rows + the enabled/disabled cases).

- [ ] **Step 5: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Retention/RetentionPolicy.php app/Content/Retention/InvalidRetentionPolicyException.php tests/Unit/Content/RetentionPolicyTest.php
git commit -m "Add RetentionPolicy value object with fail-loud validation"
```

---

### Task 2: `PruneReport` DTO

**Files:**
- Create: `app/Content/Retention/PruneReport.php`
- Test: `tests/Unit/Content/RetentionPolicyTest.php` is unrelated; add a small DTO test inline in the pruner suite instead. We assert `PruneReport` shape directly in the `VersionPrunerTest` (Task 3), so this task ships the DTO with a tiny standalone unit test.

- [ ] **Step 1: Write the failing unit test.** Create `tests/Unit/Content/PruneReportTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Retention\PruneReport;
use PHPUnit\Framework\TestCase;

final class PruneReportTest extends TestCase
{
    public function testAccumulatesCountsAndExportsArray(): void
    {
        $report = new PruneReport();
        self::assertSame(0, $report->lineagesScanned);

        $report->recordLineage(deleted: 3, retained: 2, pinnedSkipped: 1);
        $report->recordLineage(deleted: 0, retained: 1, pinnedSkipped: 1);

        self::assertSame(2, $report->lineagesScanned);
        self::assertSame(3, $report->versionsDeleted);
        self::assertSame(3, $report->versionsRetained);
        self::assertSame(2, $report->pinnedSkipped);

        self::assertSame([
            'lineages_scanned' => 2,
            'versions_deleted' => 3,
            'versions_retained' => 3,
            'pinned_skipped' => 2,
        ], $report->toArray());
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter PruneReportTest`
Expected: FAIL — `Class "App\Content\Retention\PruneReport" not found`.

- [ ] **Step 3: Implement the DTO.**

`app/Content/Retention/PruneReport.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Retention;

/**
 * Mutable accumulator returned by {@see VersionPruner::prune()} and printed by the
 * command + written to the structured log line. Counts are summed across every
 * (entry, locale) lineage scanned in a pass.
 */
final class PruneReport
{
    public int $lineagesScanned = 0;
    public int $versionsDeleted = 0;
    public int $versionsRetained = 0;
    public int $pinnedSkipped = 0;

    /** Fold one lineage's outcome into the running totals. */
    public function recordLineage(int $deleted, int $retained, int $pinnedSkipped): void
    {
        $this->lineagesScanned++;
        $this->versionsDeleted += $deleted;
        $this->versionsRetained += $retained;
        $this->pinnedSkipped += $pinnedSkipped;
    }

    /** @return array{lineages_scanned:int,versions_deleted:int,versions_retained:int,pinned_skipped:int} */
    public function toArray(): array
    {
        return [
            'lineages_scanned' => $this->lineagesScanned,
            'versions_deleted' => $this->versionsDeleted,
            'versions_retained' => $this->versionsRetained,
            'pinned_skipped' => $this->pinnedSkipped,
        ];
    }
}
```

- [ ] **Step 4: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter PruneReportTest`
Expected: PASS.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Retention/PruneReport.php tests/Unit/Content/PruneReportTest.php
git commit -m "Add PruneReport counts DTO"
```

---

### Task 3: `VersionPruner` service — selection + delete-time pin guard

**Files:**
- Create: `app/Content/Retention/VersionPruner.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (register `VersionPruner`)
- Test: `tests/Integration/Content/VersionPrunerTest.php`

This is the core. The pruner enumerates lineages, and for each runs ONE parameterized statement that ranks `version DESC`, marks keep-N + age survivors + the pinned row, and DELETEs the rest — with the in-statement `NOT EXISTS` pin guard as the correctness barrier. Because `entry_versions.created_at` is stored as a naive `Y-m-d H:i:s` timestamp (migration 004), the age cutoff compares against `now() - (:days || ' days')::interval` server-side (no client clock).

- [ ] **Step 1: Write the failing integration tests.** Create `tests/Integration/Content/VersionPrunerTest.php`. The suite builds lineages directly through `VersionRepository::appendVersion()` + `pin()` (the real write path) so created-at ordering follows `version`, then drives `VersionPruner::prune()` and asserts surviving rows + report counts.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Repositories\VersionRepository;
use App\Content\Retention\RetentionPolicy;
use App\Content\Retention\VersionPruner;
use App\Tests\Support\LemmaTestCase;

final class VersionPrunerTest extends LemmaTestCase
{
    private VersionRepository $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versions = new VersionRepository($this->connection());
    }

    private function pruner(): VersionPruner
    {
        return new VersionPruner($this->connection());
    }

    /**
     * Append $count versions for one (entry, locale), oldest-created first so that
     * higher `version` == newer. created_at is set explicitly increasing to make the
     * age tests deterministic. Returns the created version UUIDs in version order [v1..vN].
     *
     * @return list<string>
     */
    private function buildLineage(string $entry, string $locale, int $count, int $baseDaysAgo = 0): array
    {
        $uuids = [];
        for ($v = 1; $v <= $count; $v++) {
            // Newest (highest version) has the smallest "days ago".
            $daysAgo = $baseDaysAgo + ($count - $v);
            $uuid = $this->versions->appendVersion($entry, $locale, $v, ['title' => "v{$v}"], 1, 'user00000001');
            // Bound params in test setup too (same safety standard as production code).
            $stmt = $this->connection()->getPDO()->prepare(
                "UPDATE entry_versions SET created_at = now() - (:days * interval '1 day') WHERE uuid = :uuid"
            );
            $stmt->execute(['days' => $daysAgo, 'uuid' => $uuid]);
            $uuids[] = $uuid;
        }
        return $uuids;
    }

    /** @return list<string> surviving version UUIDs for the lineage, newest first */
    private function survivors(string $entry, string $locale): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['uuid'],
            $this->versions->versionsFor($entry, $locale)
        );
    }

    public function testKeepNDeletesOldestBeyondN(): void
    {
        $entry = 'e1aaaaaaaaaa';
        $v = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $v[4], 'user00000001'); // pin newest (v5)

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));

        self::assertSame([$v[4], $v[3]], $this->survivors($entry, 'en'), '2 newest survive');
        self::assertSame(1, $report->lineagesScanned);
        self::assertSame(3, $report->versionsDeleted);
        self::assertSame(2, $report->versionsRetained);
    }

    public function testKeepNProtectsPinnedVersionBeyondN(): void
    {
        $entry = 'e2aaaaaaaaaa';
        $v = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $v[2], 'user00000001'); // pin v3 (NOT in the 2 newest)

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));

        // survivors = {v5, v4} (keep-N) ∪ {v3} (pin) = 3 rows; v1, v2 deleted.
        self::assertEqualsCanonicalizing([$v[4], $v[3], $v[2]], $this->survivors($entry, 'en'));
        self::assertSame(2, $report->versionsDeleted);
        self::assertSame(1, $report->pinnedSkipped, 'the pinned out-of-policy row is reported as a pin skip');
    }

    public function testAgeBasedDeletesOldRetainsNewAndAlwaysKeepsPin(): void
    {
        $entry = 'e3aaaaaaaaaa';
        // v1=4d, v2=3d, v3=2d, v4=1d, v5=0d ago.
        $v = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $v[0], 'user00000001'); // pin the OLDEST (v1, 4d)

        $report = $this->pruner()->prune(RetentionPolicy::fromValues(null, '2')); // older than 2 days → delete

        // < 2 days old: v3(2d? boundary)… use strict: v4(1d), v5(0d) survive by age; v1 survives by pin.
        $survivors = $this->survivors($entry, 'en');
        self::assertContains($v[4], $survivors);
        self::assertContains($v[3], $survivors);
        self::assertContains($v[0], $survivors, 'pinned oldest survives despite being beyond max_age_days');
        self::assertNotContains($v[1], $survivors, 'v2 (3d) is deleted');
        self::assertGreaterThanOrEqual(1, $report->pinnedSkipped);
    }

    public function testCombinedKeepNIsAFloorOverAge(): void
    {
        $entry = 'e4aaaaaaaaaa';
        // make all 3 versions 90 days old so age alone would delete everything.
        $v = $this->buildLineage($entry, 'en', 3, baseDaysAgo: 90);
        $this->versions->pin($entry, 'en', $v[2], 'user00000001');

        $this->pruner()->prune(RetentionPolicy::fromValues('2', '30'));

        // keep-N floor: the 2 newest survive even though they are 90 days old.
        self::assertEqualsCanonicalizing([$v[2], $v[1]], $this->survivors($entry, 'en'));
    }

    public function testDisabledPolicyDeletesNothing(): void
    {
        $entry = 'e5aaaaaaaaaa';
        $this->buildLineage($entry, 'en', 4);
        $report = $this->pruner()->prune(RetentionPolicy::fromValues(null, null));

        self::assertCount(4, $this->versions->versionsFor($entry, 'en'));
        self::assertSame(0, $report->lineagesScanned, 'a disabled policy short-circuits before scanning');
        self::assertSame(0, $report->versionsDeleted);
    }

    public function testDryRunComputesReportButDeletesNothing(): void
    {
        $entry = 'e6aaaaaaaaaa';
        $v = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $v[4], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('2', null), dryRun: true);

        self::assertSame(3, $report->versionsDeleted, 'dry-run reports what WOULD be deleted');
        self::assertCount(5, $this->versions->versionsFor($entry, 'en'), 'dry-run deletes nothing');
    }

    public function testPerLineageIsolationAcrossEntriesAndLocales(): void
    {
        $a = 'e7aaaaaaaaaa';
        $b = 'e8aaaaaaaaaa';
        $va = $this->buildLineage($a, 'en', 4);
        $vb = $this->buildLineage($b, 'en', 2);
        $vaFr = $this->buildLineage($a, 'fr', 3);
        $this->versions->pin($a, 'en', $va[3], 'user00000001');
        $this->versions->pin($b, 'en', $vb[1], 'user00000001');
        $this->versions->pin($a, 'fr', $vaFr[2], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        self::assertCount(1, $this->versions->versionsFor($a, 'en'));
        self::assertCount(1, $this->versions->versionsFor($b, 'en'));
        self::assertCount(1, $this->versions->versionsFor($a, 'fr'));
        self::assertSame(3, $report->lineagesScanned);
        self::assertSame(6, $report->versionsDeleted, '3+1+2 deleted across the three lineages');
    }

    public function testNoPublicationLineagePrunesDownToPolicy(): void
    {
        $entry = 'e9aaaaaaaaaa';
        $this->buildLineage($entry, 'en', 4); // never pinned (no publication row)

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        self::assertCount(1, $this->versions->versionsFor($entry, 'en'), 'an unpinned lineage prunes to keep-N');
        self::assertSame(0, $report->pinnedSkipped, 'no pin to skip');
        self::assertSame(3, $report->versionsDeleted);
    }

    public function testFkSafetyEveryPublicationStillResolvesAfterPrune(): void
    {
        $entry = 'eaaaaaaaaaaa';
        $v = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $v[2], 'user00000001');

        $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        $pub = $this->versions->findPublication($entry, 'en');
        self::assertNotNull($pub);
        self::assertNotNull(
            $this->versions->findVersionByUuid((string) $pub['version_uuid']),
            'the pinned version row still exists after pruning (no orphaned publication)'
        );
    }

    public function testDeleteTimePinGuardSkipsRowPinnedAfterSelection(): void
    {
        // The GUARD — not the selection — protects the pin under a concurrent rollback. Prove
        // it by ordering the steps so selection happens while v1 is NOT pinned, then v1 is
        // pinned, then the guarded DELETE runs on the already-selected set. If the NOT EXISTS
        // guard were removed, v1 (in the selected set) would be deleted and this test fails.
        $entry = 'ebbbbbbbbbbb';
        $v = $this->buildLineage($entry, 'en', 4);
        $this->versions->pin($entry, 'en', $v[3], 'user00000001'); // pin newest

        $policy = RetentionPolicy::fromValues('1', null); // keep newest only → v1..v3 deletable
        $pruner = $this->pruner();

        // 1. SELECT deletable while v1 is NOT pinned → v1 is in the set.
        $selection = $pruner->computeDeletable($entry, 'en', $policy);
        self::assertContains($v[0], $selection['deletable'], 'v1 is selected as deletable before any re-pin');

        // 2. A concurrent rollback() re-pins v1 AFTER selection, BEFORE the delete.
        $this->versions->pin($entry, 'en', $v[0], 'user00000001');

        // 3. Run the guarded DELETE on the already-selected set (still contains v1).
        $deleted = $pruner->deleteGuarded($selection['deletable']);

        // The in-statement NOT EXISTS guard spares the now-pinned v1; the rest go.
        self::assertNotNull(
            $this->versions->findVersionByUuid($v[0]),
            'a row pinned after candidate selection must NOT be deleted (delete-time guard)'
        );
        self::assertSame(
            count($selection['deletable']) - 1,
            $deleted,
            'the guard spared exactly the one row that became pinned after selection'
        );
        self::assertSame($v[0], (string) $this->versions->findPublication($entry, 'en')['version_uuid']);
    }

    public function testIdempotencySecondPassDeletesNothing(): void
    {
        $entry = 'eccccccccccc';
        $v = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $v[4], 'user00000001');

        $first = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));
        $second = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));

        self::assertSame(3, $first->versionsDeleted);
        self::assertSame(0, $second->versionsDeleted, 'a second identical pass deletes nothing');
    }

    public function testHistoricalPreviewTokenReturns404AfterPruneWhileDraftTokenSurvives(): void
    {
        $entry = 'edddddddddddd';
        $v = $this->buildLineage($entry, 'en', 3);
        $this->versions->pin($entry, 'en', $v[2], 'user00000001'); // pin newest

        // A REAL en draft so the draft token has something to resolve to (no source → fresh,
        // empty draft row). This is what makes the "draft token survives" assertion meaningful
        // — without it the draft token would 404 for lack of a draft, proving nothing.
        $this->entryRepository()->createLocaleDraft($entry, 'en', 1, 'user00000001');

        $minter = new PreviewMinter($this->appContext());
        $historicalToken = $minter->mint($entry, 'en', $v[0]); // pinned to historical v1
        $draftToken = $minter->mint($entry, 'en');             // draft token (no version_uuid)

        $this->pruner()->prune(RetentionPolicy::fromValues('1', null)); // v1 pruned

        $reader = new PreviewReader($this->appContext(), $this->entryRepository(), $this->versions);

        // Historical-version token now 404s (graceful fail-closed) — its version row is gone.
        try {
            $reader->read($historicalToken);
            self::fail('expected PreviewNotFoundException for a pruned historical version');
        } catch (PreviewNotFoundException) {
            self::assertTrue(true);
        }

        // The draft token is UNAFFECTED by the prune: it still reads the live draft. A draft
        // token never touches entry_versions, so pruning cannot change its outcome — and here
        // we PROVE it resolves successfully (not merely that both tokens 404).
        $result = $reader->read($draftToken);
        self::assertSame($entry, $result['entry_uuid']);
        self::assertSame('en', $result['locale']);
        self::assertNull($result['version_uuid'], 'a draft token resolves a draft, not a version');
    }

    private function entryRepository(): \App\Content\Repositories\EntryRepository
    {
        return new \App\Content\Repositories\EntryRepository(
            $this->connection(),
            $this->appContext(),
            new \App\Content\Repositories\ContentTypeRepository($this->connection()),
        );
    }
}
```

> **Boundary note on the age test:** `created_at >= now() - (:days || ' days')::interval`. A version created exactly `:days` ago is on the boundary; the test pins/asserts only rows that are unambiguously inside or outside (v4 at 1d, v2 at 3d for a 2-day cutoff), so it is not boundary-sensitive.

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter VersionPrunerTest`
Expected: FAIL — `Class "App\Content\Retention\VersionPruner" not found`.

- [ ] **Step 3: Implement `VersionPruner`.**

`app/Content/Retention/VersionPruner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Retention;

use Glueful\Database\Connection;

/**
 * Deletes out-of-policy, NON-PINNED rows from entry_versions per (entry, locale)
 * lineage (spec §Pruning mechanism). Postgres-only.
 *
 * Correctness barrier: the DELETE carries an in-statement
 *   AND NOT EXISTS (SELECT 1 FROM entry_publications p WHERE p.version_uuid = entry_versions.uuid)
 * guard, so a row that became pinned (e.g. via a concurrent rollback()) between candidate
 * selection and the DELETE is skipped atomically — entry_publications.version_uuid can
 * never be orphaned. The selection in computeDeletable() is the report input; the
 * delete-time guard is what makes the pin invariant hold under concurrency.
 *
 * Survival rule per lineage (a row survives if ANY holds):
 *   - keep-N: its rank by version DESC is <= keep (keep-N is a FLOOR),
 *   - age:    created_at >= now() - maxAgeDays days,
 *   - pin:    it is the entry_publications.version_uuid for this (entry, locale).
 */
final class VersionPruner
{
    /** Bound size for the per-lineage DELETE … WHERE uuid IN (...) batch. */
    private const DELETE_BATCH = 500;

    public function __construct(private readonly Connection $db)
    {
    }

    public function prune(RetentionPolicy $policy, bool $dryRun = false): PruneReport
    {
        $report = new PruneReport();

        // Disabled policy → guaranteed silent no-op (spec §1, §Behaviors). Never scan, never
        // delete, never log (the command separately reports "pruning disabled" to the operator).
        if (!$policy->isEnabled()) {
            return $report;
        }

        foreach ($this->lineages() as $lineage) {
            $sel = $this->computeDeletable($lineage['entry_uuid'], $lineage['locale'], $policy);
            $deleted = $dryRun ? count($sel['deletable']) : $this->deleteGuarded($sel['deletable']);
            $report->recordLineage($deleted, $sel['retained'], $sel['pinnedSkipped']);
        }

        return $report;
    }

    /** @return list<array{entry_uuid:string,locale:string}> */
    private function lineages(): array
    {
        $rows = $this->db->table('entry_versions')
            ->select(['entry_uuid', 'locale'])
            ->distinct()
            ->get();

        return array_map(
            static fn (array $r): array => [
                'entry_uuid' => (string) $r['entry_uuid'],
                'locale' => (string) $r['locale'],
            ],
            $rows
        );
    }

    /**
     * Select the deletable version UUIDs for one lineage (the report input). A row survives
     * if keep-N OR age OR pin holds; everything else is deletable.
     *
     * Public so the delete-time-pin-race test can SELECT first, pin a selected row, then call
     * deleteGuarded() — proving the in-statement guard (not this point-in-time selection) is
     * what protects the pin under a concurrent rollback().
     *
     * Survivor flags are returned as 0/1 ints, NOT pg booleans: PDO_pgsql fetches a boolean as
     * the string 'f'/'t', and `(bool) 'f' === true`, so the only safe read is `::int` +
     * `=== 1`. Policy dimensions bind as **nullable ints** (no SQL boolean params): a NULL
     * :keep / :age_days makes its `… IS NOT NULL` guard false → that dimension simply doesn't
     * apply. This avoids relying on PDO to coerce a PHP bool into a PostgreSQL boolean param.
     *
     * @return array{deletable: list<string>, retained: int, pinnedSkipped: int}
     */
    public function computeDeletable(string $entry, string $locale, RetentionPolicy $policy): array
    {
        $sql = "
            WITH ranked AS (
                SELECT
                    ev.uuid,
                    ev.created_at,
                    ROW_NUMBER() OVER (ORDER BY ev.version DESC) AS rnk,
                    (p.version_uuid IS NOT NULL)::int AS is_pinned
                FROM entry_versions ev
                LEFT JOIN entry_publications p
                       ON p.version_uuid = ev.uuid
                WHERE ev.entry_uuid = :entry AND ev.locale = :locale
            )
            SELECT
                uuid,
                is_pinned,
                (:keep::int IS NOT NULL AND rnk <= :keep::int)::int AS keep_survivor,
                (:age_days::int IS NOT NULL
                    AND created_at >= now() - (:age_days::int * interval '1 day'))::int AS age_survivor
            FROM ranked
        ";
        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute([
            'entry' => $entry,
            'locale' => $locale,
            'keep' => $policy->keep,            // int|null
            'age_days' => $policy->maxAgeDays,  // int|null
        ]);

        $deletable = [];
        $retained = 0;
        $pinnedSkipped = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['keep_survivor'] === 1 || (int) $row['age_survivor'] === 1) {
                $retained++;
                continue;
            }
            if ((int) $row['is_pinned'] === 1) {
                // Out-of-policy but pinned: survives by the pin rule; report it.
                $pinnedSkipped++;
                continue;
            }
            $deletable[] = (string) $row['uuid'];
        }

        return ['deletable' => $deletable, 'retained' => $retained, 'pinnedSkipped' => $pinnedSkipped];
    }

    /**
     * DELETE the selected UUIDs with the delete-time pin guard. Returns the number of
     * rows actually removed (a row pinned by a concurrent rollback after selection is
     * spared by the NOT EXISTS guard, so the returned count can be < count($uuids)).
     *
     * Public so the race test can drive it directly on a pre-selected set.
     *
     * @param list<string> $uuids
     */
    public function deleteGuarded(array $uuids): int
    {
        if ($uuids === []) {
            return 0;
        }

        $pdo = $this->db->getPDO();
        $deleted = 0;
        foreach (array_chunk($uuids, self::DELETE_BATCH) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "DELETE FROM entry_versions
                 WHERE uuid IN ({$placeholders})
                   AND NOT EXISTS (
                       SELECT 1 FROM entry_publications p WHERE p.version_uuid = entry_versions.uuid
                   )"
            );
            $stmt->execute($chunk);
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }
}
```

> **Why the guard is the barrier, not the selection:** `computeDeletable` reads the pin via the `LEFT JOIN`, but a `rollback()` can re-pin a selected row after that read. The `NOT EXISTS` clause re-evaluates the pin against `entry_publications` *at delete time*, inside the same statement, so a freshly-pinned row is never deleted — exactly the spec §3 invariant. `rowCount()` is the truthful deleted count, so the test in Step 1 (`testDeleteTimePinGuardSkipsRowPinnedAfterSelection`) passes because the guard, not the selection, decides.

- [ ] **Step 4: Register `VersionPruner` in `LemmaServiceProvider::services()`** (mirror the existing repos; autowire fills `Connection`). Add `use App\Content\Retention\VersionPruner;` to the imports and this entry to the `services()` array (near the other repositories):
```php
VersionPruner::class => [
    'class' => VersionPruner::class,
    'shared' => true,
    'autowire' => true,
],
```

- [ ] **Step 5: Run; verify pass.**

Run: `composer test:phpunit -- --filter VersionPrunerTest`
Expected: PASS — every case incl. keep-N, keep-N-protects-pin, age, combined, disabled, dry-run, per-lineage isolation, no-publication, FK-safety, delete-time pin race, idempotency, and the historical-preview-token 404.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Retention/VersionPruner.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/VersionPrunerTest.php
git commit -m "Add VersionPruner with per-lineage selection and delete-time pin guard"
```

---

### Task 4: `config/lemma.php` `versions.retention` block (raw env, no cast)

**Files:**
- Modify: `config/lemma.php`
- Test: `tests/Integration/Content/VersionPrunerTest.php` (one config-wiring assertion appended)

The config passes raw env values through **unchanged** (empty string ⇒ "unset"); it does NOT cast. Validation is `RetentionPolicy::fromValues()`'s job, so `(int) "" === 0` / `(int) "-1" === -1` can never silently produce a destructive policy.

- [ ] **Step 1: Write the failing test.** Append to `VersionPrunerTest`:
```php
public function testRetentionConfigBlockPassesRawValuesThrough(): void
{
    // Defaults (no env set in the test environment) ⇒ both dimensions absent ⇒ disabled.
    $keep = config($this->appContext(), 'lemma.versions.retention.keep');
    $maxAge = config($this->appContext(), 'lemma.versions.retention.max_age_days');

    // Raw pass-through: null (env unset) — NOT cast to 0.
    self::assertNull($keep);
    self::assertNull($maxAge);

    $policy = RetentionPolicy::fromValues($keep, $maxAge);
    self::assertFalse($policy->isEnabled(), 'unconfigured retention is a disabled (no-op) policy');
}
```

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter testRetentionConfigBlockPassesRawValuesThrough`
Expected: FAIL — `config('lemma.versions.retention.keep')` is `null` only if the key path resolves; before the block exists the assertion on the wired path may pass trivially, so verify by adding a non-default check. To make the fail unambiguous, the block also exposes a marker. Implement Step 3, then this passes; if the test passes BEFORE Step 3 because both are coincidentally null, add `self::assertTrue(is_array(config($this->appContext(), 'lemma.versions')), 'versions block exists');` which fails until the block is added.

> Use this exact assertion in the test so the fail is real:
```php
self::assertIsArray(
    config($this->appContext(), 'lemma.versions'),
    'config/lemma.php must expose a versions block'
);
```

- [ ] **Step 3: Add the block to `config/lemma.php`** (append a `versions` key to the returned array, after `pipeline`):
```php
    // Version retention / pruning (see docs/superpowers/specs/2026-06-16-version-pruning-design.md).
    // RAW env pass-through — do NOT cast. RetentionPolicy::fromValues() validates: null/''
    // ⇒ that dimension off; otherwise it must be a positive integer (>= 1) or it fails loud.
    // Both absent ⇒ pruning is a no-op everywhere (the V1 unlimited-history contract holds).
    'versions' => [
        'retention' => [
            'keep' => env('LEMMA_VERSION_KEEP'),               // null, '', or a numeric string
            'max_age_days' => env('LEMMA_VERSION_MAX_AGE_DAYS'),
        ],
    ],
```

- [ ] **Step 4: Run; verify pass.**

Run: `composer test:phpunit -- --filter testRetentionConfigBlockPassesRawValuesThrough`
Expected: PASS (`lemma.versions` is an array; both dimensions are null ⇒ disabled policy).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add config/lemma.php tests/Integration/Content/VersionPrunerTest.php
git commit -m "Add versions.retention config block (raw env, no cast)"
```

---

### Task 5: `lemma:versions:prune` command (--dry-run / --keep / --max-age-days)

**Files:**
- Create: `app/Content/Console/PruneVersionsCommand.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (register the command + add to `commands([...])`)
- Test: `tests/Integration/Console/PruneVersionsCommandTest.php`

The command reads the configured policy, lets `--keep`/`--max-age-days` override it (passed to `RetentionPolicy::fromValues` so the SAME validation barrier applies to overrides), and runs `VersionPruner::prune()` with `--dry-run`. An invalid policy (config or override) aborts BEFORE any DELETE with a clear error and a non-zero exit.

- [ ] **Step 1: Write the failing tests.** Create `tests/Integration/Console/PruneVersionsCommandTest.php` (uses `CommandTester`, mirroring `ResyncCommandTest::tester()`):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Content\Console\PruneVersionsCommand;
use App\Content\Repositories\VersionRepository;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneVersionsCommandTest extends LemmaTestCase
{
    private VersionRepository $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versions = new VersionRepository($this->connection());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PruneVersionsCommand($this->container(), $this->appContext()));
    }

    private function buildLineage(string $entry, int $count): array
    {
        $uuids = [];
        for ($v = 1; $v <= $count; $v++) {
            $uuids[] = $this->versions->appendVersion($entry, 'en', $v, ['title' => "v{$v}"], 1, 'user00000001');
        }
        return $uuids;
    }

    public function testKeepOverridePrunesAndReportsCounts(): void
    {
        $entry = 'f1aaaaaaaaaa';
        $v = $this->buildLineage($entry, 5);
        $this->versions->pin($entry, 'en', $v[4], 'user00000001');

        $tester = $this->tester();
        $exit = $tester->execute(['--keep' => '2']);

        self::assertSame(0, $exit);
        self::assertCount(2, $this->versions->versionsFor($entry, 'en'));
        self::assertStringContainsString('versions_deleted', $tester->getDisplay());
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testDryRunDeletesNothing(): void
    {
        $entry = 'f2aaaaaaaaaa';
        $v = $this->buildLineage($entry, 5);
        $this->versions->pin($entry, 'en', $v[4], 'user00000001');

        $tester = $this->tester();
        $exit = $tester->execute(['--keep' => '2', '--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertCount(5, $this->versions->versionsFor($entry, 'en'), 'dry-run deletes nothing');
        self::assertStringContainsString('dry-run', strtolower($tester->getDisplay()));
    }

    public function testDisabledPolicyIsANoOp(): void
    {
        $entry = 'f3aaaaaaaaaa';
        $this->buildLineage($entry, 4); // no override, no env config ⇒ disabled

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertCount(4, $this->versions->versionsFor($entry, 'en'), 'a disabled policy deletes nothing');
    }

    public function testInvalidOverrideFailsLoudWithoutDeleting(): void
    {
        $entry = 'f4aaaaaaaaaa';
        $this->buildLineage($entry, 4);

        $tester = $this->tester();
        $exit = $tester->execute(['--keep' => '0']); // keep=0 is rejected by RetentionPolicy

        self::assertSame(1, $exit, 'an invalid policy aborts with a non-zero exit');
        self::assertCount(4, $this->versions->versionsFor($entry, 'en'), 'NO rows deleted on an invalid policy');
        self::assertStringContainsString('keep', strtolower($tester->getDisplay()));
    }

    public function testMaxAgeOverrideRejectsNonNumeric(): void
    {
        $entry = 'f5aaaaaaaaaa';
        $this->buildLineage($entry, 3);

        $tester = $this->tester();
        $exit = $tester->execute(['--max-age-days' => 'soon']);

        self::assertSame(1, $exit);
        self::assertCount(3, $this->versions->versionsFor($entry, 'en'));
    }

    public function testCommandIsNamedForCliRegistration(): void
    {
        // The #[AsCommand] name must be exactly 'lemma:versions:prune' so it registers under
        // that name when LemmaServiceProvider::commands([...]) exposes it to the console.
        // (Full console-registration can't be asserted here: commands() is a console-only
        // no-op in the HTTP-booted test harness — the CLI smoke in Step 6 covers that.)
        self::assertSame(
            'lemma:versions:prune',
            (new PruneVersionsCommand($this->container(), $this->appContext()))->getName(),
        );
    }
}
```

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter PruneVersionsCommandTest`
Expected: FAIL — `Class "App\Content\Console\PruneVersionsCommand" not found`.

- [ ] **Step 3: Implement the command.**

`app/Content/Console/PruneVersionsCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Retention\InvalidRetentionPolicyException;
use App\Content\Retention\RetentionPolicy;
use App\Content\Retention\VersionPruner;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prunes out-of-policy, NON-PINNED rows from entry_versions (spec §CLI). This is the ONLY
 * deletion path this iteration (no cron job). Pruning is off unless a policy is configured
 * (LEMMA_VERSION_KEEP / LEMMA_VERSION_MAX_AGE_DAYS) or overridden with --keep / --max-age-days.
 *
 * Deletion is permanent — operators should EXPORT first (glueful/import-export) for a
 * recoverable archive. An invalid policy (0, negative, non-numeric — from config OR an
 * override) aborts BEFORE any DELETE via InvalidRetentionPolicyException.
 */
#[AsCommand(
    name: 'lemma:versions:prune',
    description: 'Delete out-of-policy, non-pinned entry_versions history (keep-N and/or max-age)',
)]
final class PruneVersionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                "Deletes old, NON-PINNED version snapshots per (entry, locale) lineage. The pinned\n"
                . "version always survives. Deletion is PERMANENT — export first if you want an archive.\n\n"
                . "  lemma:versions:prune --dry-run            report only, delete nothing\n"
                . "  lemma:versions:prune --keep=10           keep the 10 newest per lineage\n"
                . "  lemma:versions:prune --max-age-days=90   delete versions older than 90 days\n"
                . 'With no policy configured or passed, pruning is a no-op (unlimited history).'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted; delete nothing')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Override: keep the N newest versions per lineage')
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Override: delete versions older than D days'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        // Override config when an option is given; otherwise use the configured raw values.
        // Both flow through RetentionPolicy::fromValues so the SAME validation barrier holds.
        $keep = $input->getOption('keep')
            ?? config($this->context, 'lemma.versions.retention.keep');
        $maxAge = $input->getOption('max-age-days')
            ?? config($this->context, 'lemma.versions.retention.max_age_days');

        try {
            $policy = RetentionPolicy::fromValues($keep, $maxAge);
        } catch (InvalidRetentionPolicyException $e) {
            $this->error('Invalid retention policy: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!$policy->isEnabled()) {
            $this->warning(
                'No retention policy configured (LEMMA_VERSION_KEEP / LEMMA_VERSION_MAX_AGE_DAYS) '
                . 'and no --keep/--max-age-days override — nothing to prune (unlimited history).'
            );
            return self::SUCCESS;
        }

        /** @var VersionPruner $pruner */
        $pruner = $this->getService(VersionPruner::class);
        $report = $pruner->prune($policy, $dryRun);

        if ($dryRun) {
            $this->info('DRY-RUN — no rows were deleted.');
        }
        foreach ($report->toArray() as $key => $value) {
            $this->line(sprintf('  %-18s %d', $key, $value));
        }
        $this->success(sprintf(
            '%s %d version(s) across %d lineage(s) (%d pinned-skipped).',
            $dryRun ? 'Would prune' : 'Pruned',
            $report->versionsDeleted,
            $report->lineagesScanned,
            $report->pinnedSkipped,
        ));

        return self::SUCCESS;
    }
}
```

> `BaseCommand` exposes `$this->context` (ApplicationContext), `getService()`, and the
> `info()/warning()/error()/success()/line()` output helpers — all used above (verified
> against `vendor/glueful/framework/src/Console/BaseCommand.php`). `config($this->context, …)`
> is the framework helper. `getOption('keep')` returns `null` when the option is absent (it
> has no default), so the `?? config(...)` fallback is correct.

- [ ] **Step 4: Register the command** in `LemmaServiceProvider`. Add `use App\Content\Console\PruneVersionsCommand;`, add the service entry beside `ResyncCommand`:
```php
PruneVersionsCommand::class => [
    'class' => PruneVersionsCommand::class,
    'shared' => true,
    'autowire' => true,
],
```
and extend the `boot()` registration:
```php
$this->commands([ResyncCommand::class, PruneVersionsCommand::class]);
```
> This `commands([...])` line is what actually exposes the command to `php glueful` (the
> service entry alone only makes it container-resolvable). Missing it means the command passes
> the `CommandTester` unit tests but never appears in the CLI — exactly the gap the CLI smoke
> in Task 6 Step 4 catches (`php glueful list` would not list `lemma:versions:prune`).

- [ ] **Step 5: Run; verify pass.**

Run: `composer test:phpunit -- --filter PruneVersionsCommandTest`
Expected: PASS — keep override prunes, dry-run deletes nothing, disabled is a no-op, and both invalid-override cases abort with exit 1 and zero deletions.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Console/PruneVersionsCommand.php app/Providers/LemmaServiceProvider.php tests/Integration/Console/PruneVersionsCommandTest.php
git commit -m "Add lemma:versions:prune command (dry-run/keep/max-age overrides)"
```

---

### Task 6: Full verification + structured log line

**Files:**
- Modify: `app/Content/Retention/VersionPruner.php` (add the per-pass structured log line)
- Test: existing suites (no new test file)

The spec (§Architecture, §Events) requires the pruner to emit a structured log line for observability — no content event. **Decision (explicit): one log line per ENABLED prune pass.** A disabled policy is a deliberate silent no-op (it returns before the scan/log), since the operator command separately reports "pruning disabled" to the operator — logging on every disabled invocation would be noise with no signal. The log line is therefore emitted at the end of `prune()`, which only enabled passes reach. We add it via the framework logger: the pruner takes an optional `\Psr\Log\LoggerInterface` (autowired, nullable) so unit construction stays simple.

- [ ] **Step 1: Add an optional logger to `VersionPruner`** and emit one line at the end of `prune()`. Change the constructor and the `return`:
```php
use Psr\Log\LoggerInterface;
// ...
public function __construct(
    private readonly Connection $db,
    private readonly ?LoggerInterface $logger = null,
) {
}
```
At the end of `prune()`, before `return $report;`:
```php
        $this->logger?->info('lemma.versions.pruned', array_merge($report->toArray(), [
            'keep' => $policy->keep,
            'max_age_days' => $policy->maxAgeDays,
            'dry_run' => $dryRun,
        ]));

        return $report;
```
(The existing `VersionPrunerTest` constructs `new VersionPruner($this->connection())` with no logger — the nullable default keeps every test green. Autowiring supplies the framework logger in production.)

- [ ] **Step 2: Run the retention + command suites; verify still green.**

Run: `composer test:phpunit -- --filter "VersionPruner|PruneVersionsCommand|RetentionPolicy|PruneReport"`
Expected: PASS (logger is nullable; no behavior change).

- [ ] **Step 3: Full CI.**

Run: `composer ci`
Expected: phpcs clean + full Postgres suite green (prior total + the new retention/command/policy/report tests).

- [ ] **Step 4: Manual smoke — also verifies CLI registration.** Against a migrated DB:
```bash
php glueful list | grep lemma:versions:prune   # MUST list it — proves commands([...]) wiring
php glueful lemma:versions:prune                # no policy configured
php glueful lemma:versions:prune --keep=2 --dry-run
```
Expected: the `list` grep prints the command row (a blank result means the `commands([...])`
registration in Task 5 Step 4 was missed). With no policy: warns "No retention policy
configured … nothing to prune", exit 0. With `--keep=2 --dry-run` on seeded content: prints
the report and deletes nothing. (If `list` shows nothing after registering, run
`php glueful commands:cache` to rebuild the command manifest, then retry.)

- [ ] **Step 5: Commit.**
```bash
composer phpcs
git add app/Content/Retention/VersionPruner.php
git commit -m "Emit a structured log line per prune pass"
```

---

## Self-review notes

- **Spec coverage.** Every spec test is mapped: keep-N, keep-N-protects-pin, age, combined, disabled, dry-run, per-lineage isolation, no-publication, FK-safety, delete-time pin race, invalid-policy-fails-loud (unit `RetentionPolicyTest` + command `testInvalidOverrideFailsLoud`/`testMaxAgeOverrideRejectsNonNumeric`), historical-preview-token 404, idempotency. The hard invariant — the in-statement `NOT EXISTS (SELECT 1 FROM entry_publications WHERE version_uuid = entry_versions.uuid)` delete-time guard — lives in `VersionPruner::deleteGuarded()` and is gated by `testDeleteTimePinGuardSkipsRowPinnedAfterSelection`. CLI-only (no cron, no admin API) is honored: the only deletion path is `PruneVersionsCommand`.
- **Validation barrier is single-homed.** `RetentionPolicy::fromValues()` is the one place that decides valid/invalid (null/'' ⇒ off; else int ≥ 1; 0/negative/non-numeric/float throw). Config passes raw env (no cast); the command's `--keep`/`--max-age-days` overrides flow through the same `fromValues`, so the barrier can't be bypassed — and a future cron job would inherit it.
- **Preview-token tradeoff.** `testHistoricalPreviewTokenReturns404AfterPruneWhileDraftTokenSurvives` creates a REAL en draft (`createLocaleDraft`), mints both a historical-version token (on `v[0]`) and a draft token, prunes `v[0]`, then asserts the historical token throws `PreviewNotFoundException` (the reader fails closed to 404 on a missing version) **and** the draft token `read()` still succeeds (`version_uuid` null, entry/locale match) — proving the prune spared draft tokens, not merely that both 404.
- **PDO/PostgreSQL correctness.** Survivor flags are cast `::int` and read with `=== 1`, because PDO_pgsql fetches a boolean as the string `'f'`/`'t'` and `(bool) 'f' === true`. Policy dimensions bind as **nullable ints** (`:keep`, `:age_days`), never SQL boolean params; a NULL makes its `… IS NOT NULL` guard false. The age cutoff is `now() - (:age_days::int * interval '1 day')`. `:keep`/`:age_days` are each referenced twice — supported on PHP 8.3 PDO (repeated named parameters).
- **Postgres-only is intended** (`now()`, window `ROW_NUMBER()`, interval arithmetic, bound params) — consistent with V1. `entry_versions.created_at` is a naive `Y-m-d H:i:s` timestamp (migration 004), so the age cutoff is computed server-side against `now()`, never a client clock.
- **No schema change / no migration / no new event** — pruning is `DELETE FROM entry_versions WHERE …`; the only FK-style reference is `entry_publications.version_uuid`, excluded at delete time (FK-safety test asserts no orphaned publication). `entry_references` is rebuilt from the pinned version and holds no per-historical-version reference.
- **Type/signature consistency** (checked against real code): `VersionRepository::appendVersion($entryUuid,$locale,$version,$fields,$schemaVersion,$actor)`, `pin($entryUuid,$locale,$versionUuid,$actor)`, `findPublication`, `findVersionByUuid`, `versionsFor` — all used as defined. `Connection::getPDO()`, `Connection::table()->select()->distinct()->get()` — as used elsewhere in the repos. `PreviewMinter::mint($entry,$locale,?$versionUuid)` and `PreviewReader(__construct(context, EntryRepository, VersionRepository))` — as defined. `BaseCommand` `$this->context`, `getService()`, `info/warning/error/success/line` — verified present. `EntryRepository` constructor `(Connection, ApplicationContext, ContentTypeRepository)` — as in `PublishServiceTest`.
- **Placeholder scan:** no `TBD`, no "similar to Task N", no "add error handling" — every code step is complete PHP.
- **Test commands:** `composer test:phpunit -- --filter <Name>` matches `composer.json` (`test:phpunit` = `vendor/bin/phpunit`); `composer ci` runs phpcs + the full reset/migrate/phpunit cycle. New tests need no new tables, so `tests/Support/LemmaTestCase::TABLES` and `scripts/run-test-migrations.php` are untouched (`entry_versions`/`entry_publications` already truncate between tests).
