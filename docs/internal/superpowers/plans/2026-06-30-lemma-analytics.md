# lemma-analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A self-contained `glueful/lemma-analytics` pack that consumes content/collection/auth lifecycle events and owns its own analytics facts (raw facts + daily rollups + distinct-actor presence), exposing a gated admin read API.

**Architecture:** A path package `packages/lemma-analytics` (framework + `lemma-contracts` only) mirroring `lemma-collections`: migrations, an `AnalyticsRecorder` (single write chokepoint), pack-side auth listeners, an `AnalyticsQuery` read service + gated admin controllers, and a prune command. An App-side `AnalyticsBridgeListener` (in the Lemma app) maps the events the pack can't depend on — `lemma-collections` `Collection*`/`CollectionRow*` and App `Entry*` events — into the recorder, registered in `LemmaServiceProvider` alongside the audit listener.

**Tech Stack:** PHP 8.3, Glueful framework, PostgreSQL (raw `ON CONFLICT` for atomic upsert-increment + insert-ignore), PHPUnit, PHPCS (PSR-12).

## Global Constraints

- Pack depends ONLY on `glueful/framework` (`^1.65.0`) + `glueful/lemma-contracts` (`*`). Never on the audit extension, the App, or `lemma-collections`.
- Namespace `Glueful\Lemma\Analytics\`, PSR-4 root `src/`. Provider in `extra.glueful.provider`.
- Recording is **synchronous, after-commit, best-effort** — `AnalyticsRecorder::record()` MUST NOT throw into the caller (catch-log-swallow).
- **Auth allow-list (hard rule):** auth listeners MUST NEVER call `getTokens()`/`getAccessToken()`/`getRefreshToken()`. Auth facts record only the event name + the user uuid (login/logout). `auth.login_failed` is a count only — no attempted username. `metadata` is empty for auth facts.
- `analytics_daily.subject` is **NOT NULL**; the per-event daily total uses the sentinel `'__total__'`.
- `active_users` = distinct **human** users: `admin` normalized to `user`; hash of the `actor_id` uuid alone; `api_key`/`system` excluded.
- `actor_id_hash = hash_hmac('sha256', actor_id, key)` where `key` = `config('analytics.hash_key')` (falls back to `APP_KEY`). Raw `actor_id` lives only in `analytics_facts`.
- Retention: prune `analytics_facts` older than `config('analytics.retention_days')` (default 90); never prune `analytics_daily` / `analytics_active_actors`.
- Pack code is tested via the **App** integration suite (`tests/Integration/Analytics/`) using `App\Tests\Support\LemmaTestCase` (same pattern as `tests/Integration/Collections/`), which boots the real container + Postgres test DB.
- After DB schema changes run `composer test:reset-db && composer test:migrate` before the suite.
- **Commit gate:** every "Commit" step is gated — run `git commit` ONLY when the human has authorized committing (the Lemma workflow holds commits and reviews artifacts first). Otherwise stage the work, report, and pause. The spec and this plan stay uncommitted until told.
- Reference patterns (read before implementing): `packages/lemma-collections/src/LemmaCollectionsServiceProvider.php`, `packages/lemma-collections/migrations/001_CreateCollectionDefinitionsTable.php` + `003_SeedCollectionsPermissions.php`, `packages/lemma-collections/src/Data/RowRepository.php` (`getPDO()` raw SQL + `EventService`), `app/Collections/Audit/CollectionAuditListener.php` + `tests/Integration/Collections/CollectionAuditWiringTest.php`.

---

## File Structure

**Pack `packages/lemma-analytics/`**
- `composer.json` — package manifest (provider, deps, autoload).
- `src/LemmaAnalyticsServiceProvider.php` — DI services, capability, migrations, routes, command.
- `migrations/001_CreateAnalyticsFactsTable.php`
- `migrations/002_CreateAnalyticsDailyTable.php`
- `migrations/003_CreateAnalyticsActiveActorsTable.php`
- `migrations/004_SeedAnalyticsPermissions.php`
- `config/analytics.php` — `enabled`, `retention_days`, `hash_key`.
- `src/Facts/AnalyticsFact.php` — immutable fact carrier.
- `src/Facts/ActorHasher.php` — salted HMAC of actor id.
- `src/Facts/AnalyticsRecorder.php` — the write chokepoint (facts + daily + actives).
- `src/Listeners/AuthAnalyticsListener.php` — framework auth events → recorder (allow-list).
- `src/Query/AnalyticsQuery.php` — series + summary read service.
- `src/Http/Controllers/AnalyticsController.php` — admin read endpoints.
- `routes/admin-routes.php` — gated `analytics.read` routes.
- `src/Console/PruneAnalyticsCommand.php` — `analytics:prune`.

**App `lemma/`**
- `app/Analytics/AnalyticsBridgeListener.php` — collection/content events → recorder.
- `app/Providers/LemmaServiceProvider.php` (modify) — register the bridge listener for the pack/content events.
- `tests/Integration/Analytics/*` — the pack's tests.
- `composer.json` (modify) — require `glueful/lemma-analytics`.

---

## Task 1: Pack scaffold + migrations + permission

**Files:**
- Create: `packages/lemma-analytics/composer.json`
- Create: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php`
- Create: `packages/lemma-analytics/config/analytics.php`
- Create: `packages/lemma-analytics/migrations/001_CreateAnalyticsFactsTable.php`
- Create: `packages/lemma-analytics/migrations/002_CreateAnalyticsDailyTable.php`
- Create: `packages/lemma-analytics/migrations/003_CreateAnalyticsActiveActorsTable.php`
- Create: `packages/lemma-analytics/migrations/004_SeedAnalyticsPermissions.php`
- Modify: `composer.json` (root app — add the path-package require)
- Test: `tests/Integration/Analytics/MigrationSmokeTest.php`

**Interfaces:**
- Produces: tables `analytics_facts`, `analytics_daily`, `analytics_active_actors`; permission slug `analytics.read`; capability `lemma.analytics`; config `analytics.{enabled,retention_days,hash_key}`.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/MigrationSmokeTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class MigrationSmokeTest extends LemmaTestCase
{
    public function testAnalyticsTablesExist(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        self::assertTrue($schema->hasTable('analytics_facts'));
        self::assertTrue($schema->hasTable('analytics_daily'));
        self::assertTrue($schema->hasTable('analytics_active_actors'));
    }

    public function testAnalyticsReadPermissionSeeded(): void
    {
        $row = $this->connection()->table('permissions')->where('slug', 'analytics.read')->first();
        self::assertNotNull($row);
    }

    public function testAnalyticsReadGrantedToAdministrator(): void
    {
        $perm = $this->connection()->table('permissions')->where('slug', 'analytics.read')->first();
        $role = $this->connection()->table('roles')->where('slug', 'administrator')->first();
        self::assertNotNull($perm);
        self::assertNotNull($role);
        $grant = $this->connection()->table('role_permissions')
            ->where('role_uuid', $role['uuid'])->where('permission_uuid', $perm['uuid'])->first();
        self::assertNotNull($grant, 'administrator must be granted analytics.read (App migration 004)');
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Analytics/MigrationSmokeTest.php`
Expected: FAIL (tables/permission missing, or package not loaded).

- [ ] **Step 3: Create `packages/lemma-analytics/composer.json`**

```json
{
  "name": "glueful/lemma-analytics",
  "description": "Product-analytics fact store for Lemma: consumes lifecycle events, owns its own facts, as a removable capability pack.",
  "type": "glueful-extension",
  "license": "MIT",
  "version": "0.1.0",
  "require": {
    "php": "^8.3",
    "glueful/lemma-contracts": "*",
    "glueful/framework": "^1.65.0"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Lemma\\Analytics\\": "src/"
    }
  },
  "extra": {
    "glueful": {
      "provider": "Glueful\\Lemma\\Analytics\\LemmaAnalyticsServiceProvider"
    }
  },
  "minimum-stability": "stable"
}
```

- [ ] **Step 4: Create `packages/lemma-analytics/config/analytics.php`**

```php
<?php

declare(strict_types=1);

return [
    // The capability is gated; routes + listeners are active only when enabled.
    'enabled' => env('ANALYTICS_ENABLED', true),

    // Raw analytics_facts older than this many days are pruned; rollups are kept forever.
    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 90),

    // HMAC key for the one-way actor hash in analytics_active_actors. Falls back to APP_KEY.
    'hash_key' => env('ANALYTICS_HASH_KEY', env('APP_KEY', '')),
];
```

- [ ] **Step 5: Create migration `001_CreateAnalyticsFactsTable.php`**

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAnalyticsFactsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('analytics_facts')) {
            return;
        }
        $schema->createTable('analytics_facts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->timestamp('occurred_at');
            $table->string('event', 64);
            $table->string('category', 32);
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('actor_type', 16)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->text('metadata')->nullable();
            $table->index('occurred_at');
            $table->index(['event', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('analytics_facts');
    }

    public function getDescription(): string
    {
        return 'Create analytics_facts (append-only raw analytics events).';
    }
}
```

- [ ] **Step 6: Create migration `002_CreateAnalyticsDailyTable.php`**

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAnalyticsDailyTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('analytics_daily')) {
            return;
        }
        $schema->createTable('analytics_daily', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->date('day');
            $table->string('event', 64);
            // NOT NULL; the per-event daily total uses the sentinel '__total__'.
            $table->string('subject', 191)->default('__total__');
            $table->bigInteger('count')->default(0);
            $table->unique(['day', 'event', 'subject']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('analytics_daily');
    }

    public function getDescription(): string
    {
        return 'Create analytics_daily (per-day event-count rollups).';
    }
}
```

- [ ] **Step 7: Create migration `003_CreateAnalyticsActiveActorsTable.php`**

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateAnalyticsActiveActorsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('analytics_active_actors')) {
            return;
        }
        $schema->createTable('analytics_active_actors', function ($table) {
            $table->date('day');
            $table->string('metric', 32)->default('active_users');
            $table->string('actor_type', 16);
            $table->string('actor_id_hash', 64);
            $table->unique(['day', 'metric', 'actor_type', 'actor_id_hash']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('analytics_active_actors');
    }

    public function getDescription(): string
    {
        return 'Create analytics_active_actors (distinct-actor daily presence, privacy-minimized).';
    }
}
```

- [ ] **Step 8: Create migration `004_SeedAnalyticsPermissions.php`** (model on `lemma-collections/migrations/003_SeedCollectionsPermissions.php` — additive, `down()` NO-OP)

```php
<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

final class SeedAnalyticsPermissions implements MigrationInterface
{
    private const PERMISSIONS = [
        'analytics.read' => 'Read analytics (series + summary)',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        $db = new Connection();
        $existing = [];
        foreach (
            $db->table('permissions')->select(['slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $existing[$row['slug']] = true;
        }
        $insert = [];
        foreach (self::PERMISSIONS as $slug => $label) {
            if (isset($existing[$slug])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(),
                'slug' => $slug,
                'name' => $label,
                'category' => 'analytics',
                'description' => $label,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $db->table('permissions')->insertBatch($insert);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // NO-OP: removing the pack must not strip permission rows roles may reference.
    }

    public function getDescription(): string
    {
        return 'Declare the analytics.read permission.';
    }
}
```

- [ ] **Step 9: Create `src/LemmaAnalyticsServiceProvider.php`** (services empty for now; boot registers capability + migrations; routes/command added in later tasks)

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaAnalyticsServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded by the app config system — merge under 'analytics'
        // (the standard extension pattern, cf. extensions/audit/src/AuditServiceProvider.php:98).
        $this->mergeConfig('analytics', require __DIR__ . '/../config/analytics.php');
    }

    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            'lemma.analytics',
            label: 'Analytics',
            description: 'Product-analytics fact store fed by lifecycle events.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-analytics',
        );
    }
}
```

- [ ] **Step 10: Grant `analytics.read` to administrator** — the pack *declares* the permission (migration 004 above); the host *grants* it. Mirror collections: add `'analytics.read'` to the `administrator` list in `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php` (the `ROLE_GRANTS` const — the same array that already grants `collections.manage` etc.). Do NOT add it to that file's `PERMISSIONS` const (the pack owns the row; 004 only grants it, so `down()` stays additive-safe).

```php
    private const ROLE_GRANTS = [
        'administrator' => [
            // … existing content + collections grants …
            'collections.manage', 'collections.schema.manage', 'collections.data.manage',
            'analytics.read',
        ],
    ];
```

- [ ] **Step 11: Require the package in the app** — `lemma/composer.json` already has a `path` repository for `packages/*` (see how `glueful/lemma-collections` is required). Add to `require`:

```json
"glueful/lemma-analytics": "*",
```

Then: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && composer update glueful/lemma-analytics --no-interaction && composer test:reset-db && composer test:migrate`

- [ ] **Step 12: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/MigrationSmokeTest.php`
Expected: PASS (3 tables + permission + administrator grant).

- [ ] **Step 13: phpcs**

Run: `vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics/MigrationSmokeTest.php`
Expected: no errors.

- [ ] **Step 14: Commit (when authorized)**

```bash
git add packages/lemma-analytics composer.json composer.lock database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php tests/Integration/Analytics/MigrationSmokeTest.php
git commit -m "lemma-analytics: pack scaffold, migrations, analytics.read permission + grant"
```

---

## Task 2: AnalyticsFact value object + ActorHasher

**Files:**
- Create: `packages/lemma-analytics/src/Facts/AnalyticsFact.php`
- Create: `packages/lemma-analytics/src/Facts/ActorHasher.php`
- Modify: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php` (register `ActorHasher`)
- Test: `tests/Integration/Analytics/ActorHasherTest.php`

**Interfaces:**
- Produces:
  - `AnalyticsFact` — readonly: `string $event`, `string $category`, `?string $subjectType`, `?string $subjectId`, `?string $actorType`, `?string $actorId`, `float $occurredAt`, `array $metadata = []`. Static helpers `AnalyticsFact::now(...)` not needed — occurredAt passed in.
  - `ActorHasher::hash(string $actorId): string` — `hash_hmac('sha256', $actorId, $key)`; `ActorHasher` constructed with the configured key.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/ActorHasherTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Analytics\Facts\ActorHasher;

final class ActorHasherTest extends LemmaTestCase
{
    public function testHashIsStableAndSalted(): void
    {
        $hasher = $this->container()->get(ActorHasher::class);

        $a = $hasher->hash('user-uuid-1');
        self::assertSame($a, $hasher->hash('user-uuid-1'), 'stable for the same id');
        self::assertNotSame($a, $hasher->hash('user-uuid-2'), 'distinct for different ids');
        self::assertNotSame('user-uuid-1', $a, 'never the raw id');
        self::assertSame(64, strlen($a), 'sha256 hex');

        // Salted with a different key → different digest.
        $other = new ActorHasher('a-different-key');
        self::assertNotSame($a, $other->hash('user-uuid-1'));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/ActorHasherTest.php`
Expected: FAIL (`ActorHasher` not found).

- [ ] **Step 3: Create `src/Facts/AnalyticsFact.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Facts;

/**
 * Immutable carrier of one analytics fact. Built by listeners, consumed by AnalyticsRecorder.
 */
final class AnalyticsFact
{
    /**
     * @param array<string, mixed> $metadata Small event context. MUST be empty for auth facts.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $category,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $actorType,
        public readonly ?string $actorId,
        public readonly float $occurredAt,
        public readonly array $metadata = [],
    ) {
    }

    /** True when the subject is a low-cardinality breakdown dimension (rolled by subject). */
    public function hasBreakdownSubject(): bool
    {
        return in_array($this->subjectType, ['collection', 'content_type'], true)
            && is_string($this->subjectId) && $this->subjectId !== '';
    }

    /** True when the actor is a human user (admin normalized to user); drives active_users. */
    public function isHumanActor(): bool
    {
        return in_array($this->actorType, ['user', 'admin'], true)
            && is_string($this->actorId) && $this->actorId !== '';
    }
}
```

- [ ] **Step 4: Create `src/Facts/ActorHasher.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Facts;

/**
 * One-way, per-instance salted hash of an actor id for the privacy-minimized active-actor table.
 * The raw id is never stored there; only this digest, which preserves uniqueness for counting.
 */
final class ActorHasher
{
    public function __construct(private readonly string $key)
    {
    }

    public function hash(string $actorId): string
    {
        return hash_hmac('sha256', $actorId, $this->key);
    }
}
```

- [ ] **Step 5: Register `ActorHasher` via a static factory** — the container supports `'factory' => [self::class, 'method']` (confirmed in `extensions/conversa/src/ConversaServiceProvider.php:65`); **closure factories are rejected in production**, so use the array form. Replace the empty `services()` and add the factory method to `LemmaAnalyticsServiceProvider`:

```php
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            \Glueful\Lemma\Analytics\Facts\ActorHasher::class => [
                'shared'  => true,
                'factory' => [self::class, 'makeActorHasher'],
            ],
        ];
    }

    public static function makeActorHasher(
        \Psr\Container\ContainerInterface $container
    ): \Glueful\Lemma\Analytics\Facts\ActorHasher {
        $context = $container->get(\Glueful\Bootstrap\ApplicationContext::class);
        return new \Glueful\Lemma\Analytics\Facts\ActorHasher(
            (string) config($context, 'analytics.hash_key', ''),
        );
    }
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/ActorHasherTest.php`
Expected: PASS.

- [ ] **Step 7: phpcs + Commit (when authorized)**

```bash
vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics/ActorHasherTest.php
git add packages/lemma-analytics tests/Integration/Analytics/ActorHasherTest.php
git commit -m "lemma-analytics: AnalyticsFact value object + salted ActorHasher"
```

---

## Task 3: AnalyticsRecorder (facts + daily upsert + active actors)

**Files:**
- Create: `packages/lemma-analytics/src/Facts/AnalyticsRecorder.php`
- Modify: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php` (register `AnalyticsRecorder`, autowire)
- Test: `tests/Integration/Analytics/AnalyticsRecorderTest.php`

**Interfaces:**
- Consumes: `AnalyticsFact`, `ActorHasher`, `Glueful\Database\Connection`, `Psr\Log\LoggerInterface`.
- Produces: `AnalyticsRecorder::record(AnalyticsFact $fact): void` — best-effort; writes one `analytics_facts` row, UPSERT-increments `analytics_daily` at `(day, event, '__total__')` and (when `hasBreakdownSubject()`) at `(day, event, subject_id)`, and `INSERT … ON CONFLICT DO NOTHING` into `analytics_active_actors` `(day, 'active_users', 'user', hash)` when `isHumanActor()`.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/AnalyticsRecorderTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Analytics\Facts\AnalyticsFact;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;

final class AnalyticsRecorderTest extends LemmaTestCase
{
    private function recorder(): AnalyticsRecorder
    {
        return $this->container()->get(AnalyticsRecorder::class);
    }

    private function fact(array $o = []): AnalyticsFact
    {
        return new AnalyticsFact(
            event: $o['event'] ?? 'collections.row.created',
            category: $o['category'] ?? 'collections',
            subjectType: $o['subjectType'] ?? 'collection',
            subjectId: $o['subjectId'] ?? 'posts',
            actorType: $o['actorType'] ?? 'admin',
            actorId: $o['actorId'] ?? 'u-1',
            occurredAt: $o['occurredAt'] ?? 1751299200.0, // 2025-06-30 12:00 UTC, fixed
            metadata: $o['metadata'] ?? [],
        );
    }

    public function testRecordWritesFactAndIncrementsDailyTotalAndSubject(): void
    {
        $this->recorder()->record($this->fact());
        $this->recorder()->record($this->fact());

        self::assertSame(2, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'collections.row.created')->count());

        $total = $this->connection()->table('analytics_daily')
            ->where('event', 'collections.row.created')->where('subject', '__total__')->first();
        self::assertSame(2, (int) $total['count']);

        $subject = $this->connection()->table('analytics_daily')
            ->where('event', 'collections.row.created')->where('subject', 'posts')->first();
        self::assertSame(2, (int) $subject['count']);
    }

    public function testActiveUsersIsDistinctHumanNormalizingAdminToUser(): void
    {
        // Same uuid as admin then as user → one active row (normalized to 'user').
        $this->recorder()->record($this->fact(['actorType' => 'admin', 'actorId' => 'u-9']));
        $this->recorder()->record($this->fact(['actorType' => 'user', 'actorId' => 'u-9']));
        // api_key + system actors are excluded.
        $this->recorder()->record($this->fact(['actorType' => 'api_key', 'actorId' => 'k-1']));
        $this->recorder()->record($this->fact(['actorType' => 'system', 'actorId' => null]));

        $rows = $this->connection()->table('analytics_active_actors')
            ->where('metric', 'active_users')->get();
        self::assertCount(1, $rows);
        self::assertSame('user', $rows[0]['actor_type']);
        self::assertNotSame('u-9', $rows[0]['actor_id_hash']); // hashed, not raw
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsRecorderTest.php`
Expected: FAIL (`AnalyticsRecorder` not found).

- [ ] **Step 3: Create `src/Facts/AnalyticsRecorder.php`** (raw pgsql `ON CONFLICT` for atomic increment + insert-ignore; best-effort)

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Facts;

use Glueful\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single write chokepoint for analytics. Synchronous + best-effort: it never throws into the
 * caller (a failed analytics write must not break the request that triggered the event).
 *
 * Postgres-only by design (the app's database). The count increment and distinct insert use atomic
 * `INSERT … ON CONFLICT` via raw SQL — the query builder's upsert() sets values, not increments.
 */
final class AnalyticsRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActorHasher $hasher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(AnalyticsFact $fact): void
    {
        try {
            $day = gmdate('Y-m-d', (int) $fact->occurredAt);
            $occurredAt = gmdate('Y-m-d H:i:s', (int) $fact->occurredAt);

            $this->connection->table('analytics_facts')->insert([
                'occurred_at' => $occurredAt,
                'event' => $fact->event,
                'category' => $fact->category,
                'subject_type' => $fact->subjectType,
                'subject_id' => $fact->subjectId,
                'actor_type' => $fact->actorType,
                'actor_id' => $fact->actorId,
                'metadata' => $fact->metadata === [] ? null : json_encode($fact->metadata),
            ]);

            $this->bumpDaily($day, $fact->event, '__total__');
            if ($fact->hasBreakdownSubject()) {
                $this->bumpDaily($day, $fact->event, (string) $fact->subjectId);
            }

            if ($fact->isHumanActor()) {
                $this->touchActiveUser($day, $this->hasher->hash((string) $fact->actorId));
            }
        } catch (Throwable $e) {
            // Best-effort: analytics never breaks the request that triggered the event.
            $this->logger->warning('analytics record failed', ['error' => $e->getMessage()]);
        }
    }

    private function bumpDaily(string $day, string $event, string $subject): void
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO analytics_daily (day, event, subject, count) VALUES (?, ?, ?, 1)'
            . ' ON CONFLICT (day, event, subject) DO UPDATE SET count = analytics_daily.count + 1'
        );
        $stmt->execute([$day, $event, $subject]);
    }

    private function touchActiveUser(string $day, string $hash): void
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO analytics_active_actors (day, metric, actor_type, actor_id_hash)'
            . " VALUES (?, 'active_users', 'user', ?)"
            . ' ON CONFLICT (day, metric, actor_type, actor_id_hash) DO NOTHING'
        );
        $stmt->execute([$day, $hash]);
    }
}
```

- [ ] **Step 4: Register `AnalyticsRecorder` (autowire)** in `services()`:

```php
            \Glueful\Lemma\Analytics\Facts\AnalyticsRecorder::class => [
                'class'    => \Glueful\Lemma\Analytics\Facts\AnalyticsRecorder::class,
                'shared'   => true,
                'autowire' => true,
            ],
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsRecorderTest.php`
Expected: PASS (fact rows, daily total + subject incremented, one distinct active user).

- [ ] **Step 6: phpcs + Commit (when authorized)**

```bash
vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics/AnalyticsRecorderTest.php
git add packages/lemma-analytics tests/Integration/Analytics/AnalyticsRecorderTest.php
git commit -m "lemma-analytics: AnalyticsRecorder (facts + daily upsert + distinct active users)"
```

---

## Task 4: Auth listener (allow-list) wired in the pack

**Files:**
- Create: `packages/lemma-analytics/src/Listeners/AuthAnalyticsListener.php`
- Modify: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php` (register listener + subscribe in `boot()` when enabled)
- Test: `tests/Integration/Analytics/AuthAnalyticsListenerTest.php`

**Interfaces:**
- Consumes: `AnalyticsRecorder`; framework `SessionCreatedEvent` (`getUserUuid()`), `SessionDestroyedEvent` (`getUserUuid()`), `AuthenticationFailedEvent`; `Glueful\Events\EventService`.
- Produces: `AuthAnalyticsListener` with `onLogin`, `onLogout`, `onLoginFailed` methods. Subscribed via `EventService::addListener` in `boot()`.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/AuthAnalyticsListenerTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\EventService;

final class AuthAnalyticsListenerTest extends LemmaTestCase
{
    public function testLoginRecordsFactAndActiveUserWithNoTokenMaterial(): void
    {
        $events = $this->container()->get(EventService::class);
        // Real constructor: SessionCreatedEvent(array $sessionData, array $tokens, array $metadata = []).
        // getUserUuid() reads $sessionData['uuid'].
        $events->dispatch(new SessionCreatedEvent(
            ['uuid' => 'u-1', 'username' => 'maketech'],
            ['access_token' => 'ACCESS-TOKEN-SECRET'],
        ));

        $fact = $this->connection()->table('analytics_facts')->where('event', 'auth.login')->first();
        self::assertNotNull($fact);
        self::assertSame('u-1', $fact['actor_id']);
        self::assertNull($fact['metadata']); // no token, no PII
        $serialized = json_encode($fact);
        self::assertStringNotContainsString('ACCESS-TOKEN-SECRET', (string) $serialized);

        self::assertSame(1, (int) $this->connection()->table('analytics_active_actors')
            ->where('metric', 'active_users')->count());
    }

    public function testLoginFailedIsCountOnlyWithNoIdentity(): void
    {
        $events = $this->container()->get(EventService::class);
        $events->dispatch(new AuthenticationFailedEvent('victim@example.com', 'invalid_credentials', '203.0.113.7'));

        $fact = $this->connection()->table('analytics_facts')->where('event', 'auth.login_failed')->first();
        self::assertNotNull($fact);
        self::assertNull($fact['actor_id']);
        self::assertNull($fact['subject_id']); // attempted username NOT stored
        $serialized = json_encode($fact);
        self::assertStringNotContainsString('victim@example.com', (string) $serialized);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AuthAnalyticsListenerTest.php`
Expected: FAIL (no listener wired).

- [ ] **Step 3: Create `src/Listeners/AuthAnalyticsListener.php`** (allow-list — never touches token accessors)

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Listeners;

use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Lemma\Analytics\Facts\AnalyticsFact;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;

/**
 * Maps framework auth events to analytics facts. ALLOW-LIST ONLY: never reads token accessors
 * (getTokens/getAccessToken/getRefreshToken) and never records an attempted username. Auth facts
 * carry only the event name + (for login/logout) the user uuid; metadata is always empty.
 */
final class AuthAnalyticsListener
{
    public function __construct(private readonly AnalyticsRecorder $recorder)
    {
    }

    public function onLogin(SessionCreatedEvent $event): void
    {
        $uuid = $event->getUserUuid();
        $this->recorder->record(new AnalyticsFact(
            event: 'auth.login',
            category: 'auth',
            subjectType: 'user',
            subjectId: $uuid,
            actorType: 'user',
            actorId: $uuid,
            occurredAt: $event->getTimestamp(),
        ));
    }

    public function onLogout(SessionDestroyedEvent $event): void
    {
        $uuid = $event->getUserUuid();
        $this->recorder->record(new AnalyticsFact(
            event: 'auth.logout',
            category: 'auth',
            subjectType: 'user',
            subjectId: $uuid,
            actorType: 'user',
            actorId: $uuid,
            occurredAt: $event->getTimestamp(),
        ));
    }

    public function onLoginFailed(AuthenticationFailedEvent $event): void
    {
        // Count only — no attempted username (unverified PII), no actor.
        $this->recorder->record(new AnalyticsFact(
            event: 'auth.login_failed',
            category: 'auth',
            subjectType: null,
            subjectId: null,
            actorType: null,
            actorId: null,
            occurredAt: $event->getTimestamp(),
        ));
    }
}
```

- [ ] **Step 4: Register + subscribe in the provider** — add the listener to `services()` (autowire) and, in `boot()` after the capability registration, subscribe when enabled:

```php
        if (app($context, CapabilityRegistry::class)->isEnabled('lemma.analytics')) {
            $events = app($context, \Glueful\Events\EventService::class);
            $listener = app($context, \Glueful\Lemma\Analytics\Listeners\AuthAnalyticsListener::class);
            $events->addListener(\Glueful\Events\Auth\SessionCreatedEvent::class, [$listener, 'onLogin']);
            $events->addListener(\Glueful\Events\Auth\SessionDestroyedEvent::class, [$listener, 'onLogout']);
            $events->addListener(\Glueful\Events\Auth\AuthenticationFailedEvent::class, [$listener, 'onLoginFailed']);
        }
```

(`EventService::addListener` accepts a `[object, 'method']` callable — confirmed — so this binding form
is valid.)

- [ ] **Step 5: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AuthAnalyticsListenerTest.php`
Expected: PASS.

- [ ] **Step 6: phpcs + Commit (when authorized)**

```bash
vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics/AuthAnalyticsListenerTest.php
git add packages/lemma-analytics tests/Integration/Analytics/AuthAnalyticsListenerTest.php
git commit -m "lemma-analytics: auth listener (allow-list) → facts"
```

---

## Task 5: AnalyticsQuery (series + summary)

**Files:**
- Create: `packages/lemma-analytics/src/Query/AnalyticsQuery.php`
- Modify: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php` (register, autowire)
- Test: `tests/Integration/Analytics/AnalyticsQueryTest.php`

**Interfaces:**
- Consumes: `Glueful\Database\Connection`.
- Produces:
  - `AnalyticsQuery::series(string $event, string $from, string $to, ?string $subject = null): array` — returns a zero-filled list `[{day, count}]` across `[from,to]` inclusive (dates `Y-m-d`). `$subject` null → reads `'__total__'`; otherwise that subject.
  - `AnalyticsQuery::summary(string $from, string $to): array` — returns `{from, to, totals: {<event>: int,...}, active_users: int}` where `active_users` = **distinct users** over the range (`COUNT(DISTINCT actor_id_hash)` on `analytics_active_actors` where `metric='active_users'` and `day` in range) — a user active on multiple days counts **once**, not per user-day.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/AnalyticsQueryTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Analytics\Facts\AnalyticsFact;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;
use Glueful\Lemma\Analytics\Query\AnalyticsQuery;

final class AnalyticsQueryTest extends LemmaTestCase
{
    private function record(string $event, float $ts, string $actorId): void
    {
        $this->container()->get(AnalyticsRecorder::class)->record(new AnalyticsFact(
            event: $event, category: 'collections', subjectType: 'collection', subjectId: 'posts',
            actorType: 'user', actorId: $actorId, occurredAt: $ts,
        ));
    }

    public function testSeriesIsZeroFilledAcrossRange(): void
    {
        // 2025-06-10 and 2025-06-12, query 2025-06-10..2025-06-12 → [1,0,1].
        $this->record('collections.row.created', 1749556800.0, 'u-1'); // 2025-06-10
        $this->record('collections.row.created', 1749729600.0, 'u-2'); // 2025-06-12

        $q = $this->container()->get(AnalyticsQuery::class);
        $series = $q->series('collections.row.created', '2025-06-10', '2025-06-12');

        self::assertSame(
            [['day' => '2025-06-10', 'count' => 1], ['day' => '2025-06-11', 'count' => 0], ['day' => '2025-06-12', 'count' => 1]],
            $series,
        );
    }

    public function testSummaryTotalsAndActiveUsersAreDistinctOverRange(): void
    {
        // u-1 active on 2025-06-10 AND 2025-06-11; u-2 active on 2025-06-10.
        $this->record('collections.row.created', 1749556800.0, 'u-1'); // 2025-06-10
        $this->record('collections.row.created', 1749556800.0, 'u-2'); // 2025-06-10
        $this->record('collections.row.created', 1749643200.0, 'u-1'); // 2025-06-11

        $q = $this->container()->get(AnalyticsQuery::class);
        $summary = $q->summary('2025-06-10', '2025-06-11');

        self::assertSame(3, $summary['totals']['collections.row.created']);
        // Distinct users over the range — u-1's two active days count ONCE (not user-days).
        self::assertSame(2, $summary['active_users']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsQueryTest.php`
Expected: FAIL (`AnalyticsQuery` not found).

- [ ] **Step 3: Create `src/Query/AnalyticsQuery.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Query;

use DateTimeImmutable;
use Glueful\Database\Connection;

/**
 * Read service over the rollups. Reads analytics_daily for counts and analytics_active_actors for
 * distinct active users. Series are zero-filled so callers get a contiguous daily axis.
 */
final class AnalyticsQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{day: string, count: int}>
     */
    public function series(string $event, string $from, string $to, ?string $subject = null): array
    {
        $rows = $this->connection->table('analytics_daily')
            ->select(['day', 'count'])
            ->where('event', $event)
            ->where('subject', $subject ?? '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr((string) $row['day'], 0, 10)] = (int) $row['count'];
        }

        $out = [];
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $out[] = ['day' => $day, 'count' => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    /**
     * @return array{from: string, to: string, totals: array<string, int>, active_users: int}
     */
    public function summary(string $from, string $to): array
    {
        $totals = [];
        $rows = $this->connection->table('analytics_daily')
            ->select(['event', 'count'])
            ->where('subject', '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();
        foreach ($rows as $row) {
            $event = (string) $row['event'];
            $totals[$event] = ($totals[$event] ?? 0) + (int) $row['count'];
        }

        // DISTINCT over the range — a user active on N days counts ONCE, not N (the per-(day,actor)
        // rows would otherwise be user-days). Raw SQL: the builder's count() is COUNT(*).
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT actor_id_hash) FROM analytics_active_actors'
            . " WHERE metric = 'active_users' AND day >= ? AND day <= ?"
        );
        $stmt->execute([$from, $to]);
        $activeUsers = (int) $stmt->fetchColumn();

        return ['from' => $from, 'to' => $to, 'totals' => $totals, 'active_users' => $activeUsers];
    }
}
```

Note: the query builder supports `where(col, op, val)` (three-arg comparison) and `count()` — verified
seams, no confirmation needed.

- [ ] **Step 4: Register `AnalyticsQuery` (autowire)** in `services()`:

```php
            \Glueful\Lemma\Analytics\Query\AnalyticsQuery::class => [
                'class'    => \Glueful\Lemma\Analytics\Query\AnalyticsQuery::class,
                'shared'   => true,
                'autowire' => true,
            ],
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsQueryTest.php`
Expected: PASS.

- [ ] **Step 6: phpcs + Commit (when authorized)**

```bash
vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics/AnalyticsQueryTest.php
git add packages/lemma-analytics tests/Integration/Analytics/AnalyticsQueryTest.php
git commit -m "lemma-analytics: AnalyticsQuery (zero-filled series + summary)"
```

---

## Task 6: Admin read API (controller + routes, gated analytics.read)

**Files:**
- Create: `packages/lemma-analytics/src/Http/Controllers/AnalyticsController.php`
- Create: `packages/lemma-analytics/routes/admin-routes.php`
- Modify: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php` (register controller; load routes when enabled)
- Test: `tests/Integration/Analytics/AnalyticsApiTest.php`

**Interfaces:**
- Consumes: `AnalyticsQuery`; `Glueful\Http\Response`; `Symfony\Component\HttpFoundation\Request`.
- Produces HTTP: `GET /v1/admin/analytics/series`, `GET /v1/admin/analytics/summary` (gated `lemma_permission:analytics.read`). Controller methods `series(Request)`, `summary(Request)`.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/AnalyticsApiTest.php` (call the controller directly, like `AdminSchemaApiTest` does — grep that file for how it builds `Request` + asserts `Response`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Analytics\Facts\AnalyticsFact;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;
use Glueful\Lemma\Analytics\Http\Controllers\AnalyticsController;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsApiTest extends LemmaTestCase
{
    public function testSeriesEndpointReturnsZeroFilledData(): void
    {
        $this->container()->get(AnalyticsRecorder::class)->record(new AnalyticsFact(
            event: 'collections.row.created', category: 'collections', subjectType: 'collection',
            subjectId: 'posts', actorType: 'user', actorId: 'u-1', occurredAt: 1749556800.0,
        ));

        $controller = $this->container()->get(AnalyticsController::class);
        $req = Request::create('/v1/admin/analytics/series', 'GET', [
            'metric' => 'collections.row.created', 'from' => '2025-06-10', 'to' => '2025-06-10',
        ]);
        $res = $controller->series($req);

        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertSame([['day' => '2025-06-10', 'count' => 1]], $body['data']['series']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsApiTest.php`
Expected: FAIL (`AnalyticsController` not found).

- [ ] **Step 3: Create `src/Http/Controllers/AnalyticsController.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Lemma\Analytics\Query\AnalyticsQuery;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin read API over the analytics rollups. Gated by analytics.read in the route definitions.
 */
final class AnalyticsController
{
    public function __construct(private readonly AnalyticsQuery $query)
    {
    }

    #[ApiOperation(summary: 'Analytics time-series for one metric', tags: ['Analytics'])]
    public function series(Request $request): Response
    {
        $metric = (string) $request->query->get('metric', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($metric === '' || $from === '' || $to === '') {
            return Response::error('metric, from and to are required.', 422);
        }
        $subject = $request->query->get('dimension') === 'subject'
            ? (string) $request->query->get('subject', '')
            : null;
        $subject = ($subject === '' ? null : $subject);

        return Response::success([
            'metric' => $metric,
            'from' => $from,
            'to' => $to,
            'series' => $this->query->series($metric, $from, $to, $subject),
        ]);
    }

    #[ApiOperation(summary: 'Analytics summary (KPIs incl. active users)', tags: ['Analytics'])]
    public function summary(Request $request): Response
    {
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($from === '' || $to === '') {
            return Response::error('from and to are required.', 422);
        }
        return Response::success($this->query->summary($from, $to));
    }
}
```

Note: `Response::success($data)` wraps the payload under a `data` key (verified seam) — hence the
test asserts `$body['data']['series']`.

- [ ] **Step 4: Create `routes/admin-routes.php`** (mirrors `packages/lemma-collections/routes/admin-routes.php` exactly — the `auth` group is required; `lemma_permission` alone does NOT authenticate)

```php
<?php

declare(strict_types=1);

use Glueful\Lemma\Analytics\Http\Controllers\AnalyticsController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin analytics read API. Triple-gated like collections:
 *   1. capability       — this file loads only when lemma.analytics is enabled (boot gate; else 404).
 *   2. auth             — group middleware: an authenticated session is required (401 otherwise).
 *   3. lemma_permission — per-route Aegis permission: analytics.read.
 */
$router->group(
    ['prefix' => '/v1/admin', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->get('/analytics/series', [AnalyticsController::class, 'series'])
            ->middleware('lemma_permission:analytics.read');
        $router->get('/analytics/summary', [AnalyticsController::class, 'summary'])
            ->middleware('lemma_permission:analytics.read');
    },
);
```

- [ ] **Step 5: Register the controller + load routes when enabled** — add controller to `services()` (autowire) and in `boot()`'s enabled block:

```php
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsApiTest.php`
Expected: PASS.

- [ ] **Step 7: Add a route-gating test** — `tests/Integration/Analytics/AnalyticsRoutesGatedTest.php` (mirror `tests/Integration/Collections/AdminRoutesGatedTest.php`): with the capability enabled, an unauthenticated request is **401** (route exists, behind `auth`), proving the gate works.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsRoutesGatedTest extends LemmaTestCase
{
    public function testAnalyticsAdminRouteIsRegisteredAndRequiresAuth(): void
    {
        $response = $this->handle(Request::create('/v1/admin/analytics/summary', 'GET', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]));

        self::assertSame(401, $response->getStatusCode(),
            'Enabled-boot GET /v1/admin/analytics/summary must be 401 (route exists, auth rejects '
                . 'anonymous), got: ' . $response->getStatusCode() . ' body: ' . $response->getContent());
    }
}
```

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsRoutesGatedTest.php` → PASS.

- [ ] **Step 8: Add the disabled-capability removability test** — `tests/Integration/Analytics/AnalyticsRemovabilityTest.php`. With `lemma.analytics` DISABLED at boot the route surface is entirely absent: GET returns **404** (route unregistered), not 401 from a live-but-disabled auth gate. This is the removability half of the capability contract. The disabled-boot harness is reproduced from `tests/Integration/Collections/RemovabilityTest.php` (imports pinned: `Glueful\Application`, `Glueful\Framework`, `Glueful\Routing\RouteManifest`).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Removability contract: with lemma.analytics DISABLED at boot, the admin route surface is entirely
 * absent — GET /v1/admin/analytics/summary returns 404 (route unregistered), not 401 from a
 * live-but-disabled auth gate.
 */
final class AnalyticsRemovabilityTest extends LemmaTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass(); // shared ENABLED app

        if (self::$disabledApp !== null) {
            return;
        }

        $root = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        $overrideFile = $overrideDir . '/lemma.php';

        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents(
            $overrideFile,
            "<?php\nreturn ['capabilities' => ['lemma.analytics' => false]];\n",
        );

        RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        try {
            self::$disabledApp = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        } finally {
            @unlink($overrideFile);
            if (is_dir($overrideDir) && count((array) scandir($overrideDir)) === 2) {
                @rmdir($overrideDir);
            }
        }

        RouteManifest::reset();
    }

    public function testDisabledBootAnalyticsRouteReturns404(): void
    {
        $response = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/admin/analytics/summary', 'GET', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ]),
        );

        self::assertSame(404, $response->getStatusCode(),
            'Disabled-boot GET /v1/admin/analytics/summary must be 404 (route unregistered), got: '
                . $response->getStatusCode() . ' body: ' . $response->getContent());
    }
}
```

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsRemovabilityTest.php` → PASS.

- [ ] **Step 9: phpcs + Commit (when authorized)**

```bash
vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics
git add packages/lemma-analytics tests/Integration/Analytics/AnalyticsApiTest.php tests/Integration/Analytics/AnalyticsRoutesGatedTest.php tests/Integration/Analytics/AnalyticsRemovabilityTest.php
git commit -m "lemma-analytics: gated admin read API (series + summary) + removability"
```

---

## Task 7: Prune command + retention

**Files:**
- Create: `packages/lemma-analytics/src/Console/PruneAnalyticsCommand.php`
- Modify: `packages/lemma-analytics/src/LemmaAnalyticsServiceProvider.php` (register the command)
- Test: `tests/Integration/Analytics/PruneAnalyticsTest.php`

**Interfaces:**
- Consumes: `Glueful\Database\Connection`, `ApplicationContext` (for `config('analytics.retention_days')`).
- Produces: console command `analytics:prune` that deletes `analytics_facts` with `occurred_at < now - retention_days`; leaves `analytics_daily` + `analytics_active_actors` untouched.

- [ ] **Step 1: Write the failing test** — `tests/Integration/Analytics/PruneAnalyticsTest.php` (test the prune logic via a small public method to avoid CLI plumbing in the unit; the command delegates to it)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Analytics\Console\PruneAnalyticsCommand;

final class PruneAnalyticsTest extends LemmaTestCase
{
    public function testPruneRemovesAgedFactsAndKeepsRollups(): void
    {
        // One old fact (100 days ago) + one recent.
        $old = gmdate('Y-m-d H:i:s', time() - 100 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        foreach ([$old, $recent] as $ts) {
            $this->connection()->table('analytics_facts')->insert([
                'occurred_at' => $ts, 'event' => 'auth.login', 'category' => 'auth',
                'subject_type' => 'user', 'subject_id' => 'u-1', 'actor_type' => 'user',
                'actor_id' => 'u-1', 'metadata' => null,
            ]);
        }
        $this->connection()->table('analytics_daily')->insert([
            'day' => gmdate('Y-m-d', time() - 100 * 86400), 'event' => 'auth.login',
            'subject' => '__total__', 'count' => 5,
        ]);

        $command = $this->container()->get(PruneAnalyticsCommand::class);
        $deleted = $command->prune(90); // retention days

        self::assertSame(1, $deleted);
        self::assertSame(1, (int) $this->connection()->table('analytics_facts')->count());
        self::assertSame(1, (int) $this->connection()->table('analytics_daily')->count(), 'rollups kept');
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/PruneAnalyticsTest.php`
Expected: FAIL (command not found).

- [ ] **Step 3: Create `src/Console/PruneAnalyticsCommand.php`** — it MUST extend the framework's `Glueful\Console\BaseCommand` (which extends Symfony `Command` and provides `getContext()`, `getService()`, and output helpers like `success()`), the way `Glueful\Console\Commands\InstallCommand` does — NOT raw `Symfony\Component\Console\Command\Command`. Inject `Connection` and call `parent::__construct()`; read config via `$this->getContext()`; emit output via `$this->success()`.

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Console;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'analytics:prune', description: 'Delete raw analytics_facts past the retention window.')]
final class PruneAnalyticsCommand extends BaseCommand
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    /** Delete facts older than $days; returns the row count removed. Rollups are never touched. */
    public function prune(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        return (int) $this->connection->table('analytics_facts')
            ->where('occurred_at', '<', $cutoff)
            ->delete();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) config($this->getContext(), 'analytics.retention_days', 90);
        $deleted = $this->prune($days);
        $this->success(sprintf('Pruned %d analytics fact(s) older than %d days.', $deleted, $days));
        return self::SUCCESS;
    }
}
```

Note (verified seams): `config($context, key, default)` is the helper arg order (CLAUDE.md);
`->where(col, op, val)->delete()` returns an int (see `RowRepository`); the command is registered in
`boot()` via the ServiceProvider's `commands([...])` helper.

- [ ] **Step 4: Register the command** in `boot()`:

```php
        $this->commands([\Glueful\Lemma\Analytics\Console\PruneAnalyticsCommand::class]);
```

And add it to `services()` (autowire) so its constructor deps resolve.

- [ ] **Step 5: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/PruneAnalyticsTest.php`
Expected: PASS.

- [ ] **Step 6: Verify the command is registered** — `php glueful list | grep analytics:prune` shows the command.

- [ ] **Step 7: phpcs + Commit (when authorized)**

```bash
vendor/bin/phpcs packages/lemma-analytics/src tests/Integration/Analytics/PruneAnalyticsTest.php
git add packages/lemma-analytics tests/Integration/Analytics/PruneAnalyticsTest.php
git commit -m "lemma-analytics: analytics:prune retention command"
```

---

## Task 8: App-side bridge listener (collection + content events)

**Files:**
- Create: `app/Analytics/AnalyticsBridgeListener.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (register the listener service + wire it to the events in `registerEventListeners()`, alongside the audit listener)
- Test: `tests/Integration/Analytics/AnalyticsBridgeWiringTest.php`

**Interfaces:**
- Consumes: `AnalyticsRecorder`; pack events `CollectionCreated`/`CollectionUpdated`/`CollectionDropped`/`CollectionRow{Created,Updated,Deleted}` (carry `actorType`/`actorId` or `Actor`); App content events `EntryCreated`/`EntryUpdated`/`EntryDeleted`/`EntryPublished`/`EntryUnpublished`.
- Produces: `AnalyticsBridgeListener::__invoke(object $event): void` mapping each to an `AnalyticsFact` → recorder.

- [ ] **Step 1: Confirmed source-event accessors** (pinned against the codebase — no discovery needed):
  - `Collection{Created,Updated,Dropped}`: `->collectionName` (string), `->actorType` (string), `->actorId` (`?string`).
  - `CollectionRow{Created,Updated,Deleted}`: `->collectionName` (string), `->actor` (`Glueful\Lemma\Collections\Data\Actor`, with `->type` + `->id`).
  - `Entry{Created,Updated,Deleted,Published,Unpublished}` extend `App\Content\Events\BaseEntryEvent`: `->type` (content-type slug, string), `->actor` (`?string` uuid), `->entry` (id). Constructor: `BaseEntryEvent(string $entry, string $type, ?string $locale = null, ?int $version = null, ?string $actor = null)`.

- [ ] **Step 2: Write the failing test** — `tests/Integration/Analytics/AnalyticsBridgeWiringTest.php` (mirror `tests/Integration/Collections/CollectionAuditWiringTest.php`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Content\Events\EntryPublished;
use App\Tests\Support\LemmaTestCase;
use Glueful\Events\EventService;
use Glueful\Lemma\Collections\Data\Actor;
use Glueful\Lemma\Collections\Events\CollectionCreated;
use Glueful\Lemma\Collections\Events\CollectionRowCreated;

final class AnalyticsBridgeWiringTest extends LemmaTestCase
{
    public function testCollectionEventsBecomeAnalyticsFacts(): void
    {
        $events = $this->container()->get(EventService::class);

        $events->dispatch(new CollectionCreated('posts', 'admin', 'u-1'));
        $events->dispatch(new CollectionRowCreated('posts', 'row-1', ['uuid' => 'row-1'], new Actor('admin', 'u-1')));

        self::assertSame(1, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'collections.collection.created')->count());
        self::assertSame(1, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'collections.row.created')->count());

        // Distinct active user recorded for the admin actor (normalized to 'user').
        self::assertSame(1, (int) $this->connection()->table('analytics_active_actors')
            ->where('metric', 'active_users')->count());
    }

    public function testContentEntryEventsBecomeAnalyticsFacts(): void
    {
        $events = $this->container()->get(EventService::class);

        // BaseEntryEvent(string $entry, string $type, ?string $locale, ?int $version, ?string $actor).
        $events->dispatch(new EntryPublished('entry-1', 'article', null, null, 'u-7'));

        $fact = $this->connection()->table('analytics_facts')
            ->where('event', 'content.entry.published')->first();
        self::assertNotNull($fact);
        self::assertSame('content', $fact['category']);
        self::assertSame('content_type', $fact['subject_type']);
        self::assertSame('article', $fact['subject_id']); // ->type
        self::assertSame('u-7', $fact['actor_id']);        // ->actor

        // Per-content-type breakdown rollup exists alongside the __total__ row.
        self::assertSame(1, (int) $this->connection()->table('analytics_daily')
            ->where('event', 'content.entry.published')->where('subject', 'article')->count());
    }
}
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsBridgeWiringTest.php`
Expected: FAIL (no bridge wired).

- [ ] **Step 4: Create `app/Analytics/AnalyticsBridgeListener.php`**

```php
<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Content\Events\BaseEntryEvent;
use App\Content\Events\EntryCreated;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Events\EntryUpdated;
use Glueful\Lemma\Analytics\Facts\AnalyticsFact;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;
use Glueful\Lemma\Collections\Events\CollectionCreated;
use Glueful\Lemma\Collections\Events\CollectionDropped;
use Glueful\Lemma\Collections\Events\CollectionRowCreated;
use Glueful\Lemma\Collections\Events\CollectionRowDeleted;
use Glueful\Lemma\Collections\Events\CollectionRowUpdated;
use Glueful\Lemma\Collections\Events\CollectionUpdated;
use Glueful\Events\Contracts\BaseEvent;

/**
 * Bridges pack/content lifecycle events into analytics facts — the App-side seam so the pack stays
 * dependency-pure (it cannot reference lemma-collections or App content events). Mirrors
 * CollectionAuditListener.
 */
final class AnalyticsBridgeListener
{
    public function __construct(private readonly AnalyticsRecorder $recorder)
    {
    }

    public function __invoke(object $event): void
    {
        $ts = $event instanceof BaseEvent ? $event->getTimestamp() : microtime(true);
        $fact = match (true) {
            $event instanceof CollectionCreated =>
                $this->collection('collections.collection.created', $event->collectionName, $event->actorType, $event->actorId, $ts),
            $event instanceof CollectionUpdated =>
                $this->collection('collections.collection.updated', $event->collectionName, $event->actorType, $event->actorId, $ts),
            $event instanceof CollectionDropped =>
                $this->collection('collections.collection.dropped', $event->collectionName, $event->actorType, $event->actorId, $ts),
            $event instanceof CollectionRowCreated =>
                $this->collection('collections.row.created', $event->collectionName, $event->actor->type, $event->actor->id, $ts),
            $event instanceof CollectionRowUpdated =>
                $this->collection('collections.row.updated', $event->collectionName, $event->actor->type, $event->actor->id, $ts),
            $event instanceof CollectionRowDeleted =>
                $this->collection('collections.row.deleted', $event->collectionName, $event->actor->type, $event->actor->id, $ts),
            $event instanceof EntryCreated => $this->entry('content.entry.created', $event, $ts),
            $event instanceof EntryUpdated => $this->entry('content.entry.updated', $event, $ts),
            $event instanceof EntryDeleted => $this->entry('content.entry.deleted', $event, $ts),
            $event instanceof EntryPublished => $this->entry('content.entry.published', $event, $ts),
            $event instanceof EntryUnpublished => $this->entry('content.entry.unpublished', $event, $ts),
            default => null,
        };

        if ($fact !== null) {
            $this->recorder->record($fact);
        }
    }

    private function collection(string $event, string $name, string $actorType, ?string $actorId, float $ts): AnalyticsFact
    {
        return new AnalyticsFact(
            event: $event, category: 'collections', subjectType: 'collection', subjectId: $name,
            actorType: $actorType, actorId: $actorId, occurredAt: $ts,
        );
    }

    private function entry(string $event, BaseEntryEvent $e, float $ts): AnalyticsFact
    {
        // BaseEntryEvent: public readonly string $type (content-type slug), public readonly ?string $actor.
        // A null $actor is fine — the recorder records no active user for a null/non-human actor.
        return new AnalyticsFact(
            event: $event, category: 'content', subjectType: 'content_type', subjectId: $e->type,
            actorType: 'user', actorId: $e->actor, occurredAt: $ts,
        );
    }
}
```

- [ ] **Step 5: Register + wire in `LemmaServiceProvider`** — add `AnalyticsBridgeListener::class` to `services()` (see how `CollectionAuditListener` is registered at ~line 420), and in `registerEventListeners()` next to the collection-audit block:

```php
        if (class_exists(CollectionRowCreated::class)) {
            foreach ([
                CollectionCreated::class, CollectionUpdated::class, CollectionDropped::class,
                CollectionRowCreated::class, CollectionRowUpdated::class, CollectionRowDeleted::class,
            ] as $eventClass) {
                $listeners[$eventClass][] = AnalyticsBridgeListener::class;
            }
        }
        foreach ([
            \App\Content\Events\EntryCreated::class, \App\Content\Events\EntryUpdated::class,
            \App\Content\Events\EntryDeleted::class, \App\Content\Events\EntryPublished::class,
            \App\Content\Events\EntryUnpublished::class,
        ] as $eventClass) {
            $listeners[$eventClass][] = AnalyticsBridgeListener::class;
        }
```

Add the matching `use App\Analytics\AnalyticsBridgeListener;` import. Confirm the `$listeners[...][] =`
accumulation matches the existing structure (it currently assigns `= [CollectionAuditListener::class]`;
append instead so both audit + analytics fire — see the existing loop that does
`$events->addListener($eventClass, '@' . $serviceId)`).

- [ ] **Step 6: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Analytics/AnalyticsBridgeWiringTest.php`
Expected: PASS.

- [ ] **Step 7: Run the full analytics suite + phpcs**

Run: `vendor/bin/phpunit tests/Integration/Analytics && vendor/bin/phpcs packages/lemma-analytics/src app/Analytics`
Expected: all green.

- [ ] **Step 8: Commit (when authorized)**

```bash
git add app/Analytics app/Providers/LemmaServiceProvider.php tests/Integration/Analytics/AnalyticsBridgeWiringTest.php
git commit -m "lemma-analytics: App bridge — collection + content events → facts"
```

---

## Final verification

- [ ] `composer test:reset-db && composer test:migrate`
- [ ] `vendor/bin/phpunit tests/Integration/Analytics` — all green.
- [ ] `composer ci` (or `vendor/bin/phpunit && vendor/bin/phpcs`) — full suite + style green.
- [ ] Manual smoke (optional): enable the capability, create a collection + a row, hit
  `GET /v1/admin/analytics/summary?from=…&to=…` → totals + active_users reflect the activity.

---

## Self-Review (against the spec)

- **§3 model:** `analytics_facts` (Task 1), `analytics_daily` with NOT-NULL `subject` + `__total__` (Task 1 + recorder Task 3), `analytics_active_actors` `(day, metric, actor_type, actor_id_hash)` (Task 1) — covered.
- **§3 privacy:** `ActorHasher` salted HMAC of `actor_id` alone (Task 2); raw `actor_id` only in facts; admin→user normalization + api_key/system exclusion (Task 3) — covered.
- **§4 taxonomy:** auth in pack listener (Task 4); collections + content in App bridge (Task 8) — covered.
- **§4 auth allow-list:** Task 4 (no token accessors; login_failed count-only; empty metadata) — covered, with a token-leak assertion.
- **§5 ingestion:** synchronous best-effort `AnalyticsRecorder` (Task 3, try/catch-log) — covered.
- **§6 read API:** series + summary, gated `analytics.read` (Task 6) — covered.
- **§7 capability/config/retention:** capability + config (Task 1), prune command keeping rollups (Task 7) — covered.
- **§8 testing:** recorder, query, prune, auth + bridge wiring tests — covered.
- **Open seams (§10):** not implemented (correct — out of v1 scope).
- **Placeholder scan:** all API signatures are pinned against the codebase; the earlier "confirm against pattern" guards were converted to factual "verified seam" notes. No `TBD`/`later`/placeholder accessor/unspecified behavior remains.
- **Type consistency:** `AnalyticsFact` field names + `AnalyticsRecorder::record` / `AnalyticsQuery::series|summary` / `ActorHasher::hash` signatures are used identically across tasks.

### Review findings incorporated (verified against the codebase)

- **[P1] DI factory:** `ActorHasher` uses `'factory' => [self::class, 'makeActorHasher']` (closure factories are prod-rejected; array form confirmed in `extensions/conversa`).
- **[P1] Auth constructor:** test now uses the real `SessionCreatedEvent(array $sessionData, array $tokens)` (`getUserUuid()` reads `sessionData['uuid']`); `AuthenticationFailedEvent(username, reason, ip)` already matched.
- **[P1] Admin route gate:** routes use `['prefix' => '/v1/admin', 'middleware' => ['auth']]` + per-route `lemma_permission` (mirrors collections); a `handle()`-dispatched gating test asserts 401 for anonymous.
- **[P1] Permission grant:** Task 1 grants `analytics.read` to `administrator` in `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php` (`ROLE_GRANTS`), with a grant assertion in the smoke test.
- **[P2] Config:** provider `register()` calls `mergeConfig('analytics', …)` (package configs aren't auto-loaded).
- **[P2] active_users:** summary uses `COUNT(DISTINCT actor_id_hash)` over the range (not user-days); test spans two days for one user.
- **[P3] Commit gate:** added to Global Constraints; every commit step renamed "Commit (when authorized)".
- **Confirmed seams (no change needed):** `EventService::addListener` accepts `[obj,'method']` callables; query builder `where(col, op, val)`; `Response::success()` `data` envelope; `$this->commands([...])` registration.

### Round-2 review findings incorporated

- **[P1] Content bridge accessors:** pinned to `BaseEntryEvent->type` (content-type slug) + `->actor` (`?string` uuid); `entry()` type-hinted `BaseEntryEvent`; placeholder note removed; a `content.entry.published` assertion added to the bridge wiring test.
- **[P2] active_users prose:** Task 5's interface text corrected to `COUNT(DISTINCT actor_id_hash)` (was "distinct days-actor rows / COUNT(*)"), matching the implementation.
- **[P2] Disabled-capability test:** Task 6 now includes the full `AnalyticsRemovabilityTest` (disabled-boot harness reproduced from `RemovabilityTest`, imports pinned) asserting 404 when the capability is off.
- **[P3] Stale "confirm" notes:** the three remaining guards (query `where`, `Response` envelope, config/`commands`) converted to factual "verified seam" notes.
