# SEO / Routing Module — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Shipped (2026-06-17) — implemented, reviewed, and merged. Steps left as `[ ]` for historical reference.

**Goal:** Add an in-app SEO/routing module to Lemma — auto-captured + manual redirects (301/302/308) with chain-free single-hop resolution, plus self-canonical / hreflang metadata on delivery — so a headless frontend never 404s a renamed URL and can emit correct SEO signals.

**Architecture:** A new `app/Content/Seo/` subsystem hooks the existing core delivery + route-assignment paths directly (not a separate package). A new `entry_redirects` table stores one redirect per source path, with a FULL internal target triple `{target_entry_uuid, target_content_type_uuid, target_locale}` XOR a literal `target_url` (exclusive Postgres CHECK). `RouteResolver::resolve()` returns a discriminated `ResolutionResult` — `Content | Redirect | Gone | NotFound` — driving `DeliveryController::show`: live routes walk the locale fallback chain (unchanged), redirects match the **requested locale only**, internal targets resolve in exactly one hop to the target entry's *current* live slug (chains structurally impossible). `RouteRepository::assign` gains an auto-capture hook (slug rename → upsert a 301 from the old path; delete any redirect colliding with the new live slug). `PathRenderer` renders every emitted URL as a **relative path** from `config('lemma.seo.route_template')` + a structured `{content_type, locale, slug}` identity (absolute when `public_url_base` is set; default-locale variant omits the locale segment). `CanonicalProjector` derives self-canonical + hreflang alternates + `x_default` from `entry_publications` + `entry_routes` (no new storage). A `RedirectController` (perm `lemma.routes.manage`, seeded to `lemma_admin` only) exposes create/list/delete under `/v1/admin`. Redirect/`Gone` responses carry a derived ETag + the target-entry cache tag + a short TTL (`lemma.seo.redirect_ttl`).

**Tech Stack:** PHP 8.3, PostgreSQL (required — CHECK constraints, `now()`), PHPUnit 10, Glueful framework (migrations, `RequestData` DTOs, fluent router, `lemma_permission` middleware, `config()`/`app()` helpers). Conventions: `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col.

**Spec:** `docs/superpowers/specs/2026-06-16-seo-routing-module-design.md`

---

## File map

> **Migration number:** `010` assumes `009` is the current max on disk. Sibling POST_V1
> features (scheduled-publish `010_CreateEntrySchedulesTable`, backfill
> `010_CreateEntrySchemaMigrationsTable`) number from the same base — they are **not** all
> `010`. Before creating the migration, run `ls database/migrations/` and use the **next
> available** number, renaming the class/file accordingly (table name + code unaffected).
- Create: `database/migrations/010_CreateEntryRedirectsTable.php` — `entry_redirects` table (source key + internal triple XOR literal url + exclusive CHECK + status/origin CHECK + unique source + target-entry index), raw-PDO DDL for the CHECKs. *(Number is `010` only if no sibling POST_V1 migration landed first — see note above.)*
- Create: `app/Content/Seo/RedirectRepository.php` — CRUD + lookup: `findForSource`, `upsertAuto`, `deleteBySource`, `create`, `delete`, `forType`, `findByUuid`.
- Create: `app/Content/Seo/ResolutionResult.php` — discriminated value object with four named constructors (`content`/`redirect`/`gone`/`notFound`) + accessors.
- Create: `app/Content/Seo/PathRenderer.php` — render a relative (or `public_url_base`-absolute) path from `lemma.seo.route_template` for a `{content_type, locale, slug}` identity; default-locale variant omits the locale segment.
- Create: `app/Content/Seo/RouteResolver.php` — the resolution unit: live-route-across-chain → `Content`; requested-locale-only redirect → `Redirect`/`Gone`; else `NotFound`.
- Create: `app/Content/Seo/CanonicalProjector.php` — `for(entryUuid, contentTypeUuid, locale)` → `{canonical, alternates, x_default}` (relative paths + identity), derived from publications + routes.
- Modify: `app/Content/Repositories/RouteRepository.php` — auto-capture hook inside `assign()` (upsert 301 from old slug; delete redirect colliding with new slug; old==new no-op).
- Modify: `app/Content/Http/Controllers/DeliveryController.php` — drive `show()` through `RouteResolver`; map `Content`→200+`seo`, `Redirect`→200 `{redirect:{…}}`, `Gone`→404, `NotFound`→uuid fallback→404; redirect caching (derived ETag + target-entry cache tag + short TTL).
- Create: `app/Content/Http/DTOs/CreateRedirectData.php` — request body for manual redirect create.
- Create: `app/Content/Http/Controllers/RedirectController.php` — `store`/`index`/`destroy` (perm `lemma.routes.manage`); `index` rows carry computed `target_state` (`live`/`broken`).
- Modify: `routes/lemma_admin.php` — 3 redirect routes under `/v1/admin`, each `->middleware('lemma_permission:lemma.routes.manage')`.
- Modify: `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php` — add `lemma.routes.manage`, grant to `lemma_admin` only.
- Modify: `config/lemma.php` — `seo` keys (`route_template`, `public_url_base`, `redirect_ttl`).
- Modify: `app/Providers/LemmaServiceProvider.php` — register `RedirectRepository`, `PathRenderer`, `RouteResolver`, `CanonicalProjector`, `RedirectController` (autowire).
- Modify: `tests/Support/LemmaTestCase.php` — add `'entry_redirects'` to `TABLES` (first, child→parent order).
- Create tests: `tests/Integration/Seo/RedirectRepositoryTest.php`, `tests/Integration/Seo/RouteResolverTest.php`, `tests/Integration/Seo/PathRendererTest.php`, `tests/Integration/Seo/CanonicalProjectorTest.php`, `tests/Integration/Seo/AutoCaptureTest.php`, `tests/Integration/Http/RedirectApiTest.php`, `tests/Integration/Http/DeliverySeoTest.php`; extend `tests/Integration/Content/RouteRepositoryTest.php`; add seed assertions to a new `tests/Integration/Seo/RoutesAndPermissionSeedTest.php`.

All tests run on Postgres via `LemmaTestCase`. Test command: `composer test:phpunit -- --filter <Name>` (confirmed: `composer.json` maps `test:phpunit` → `vendor/bin/phpunit`; `--filter` is the PHPUnit class/method filter). A schema change needs `composer test:reset-db && composer test:migrate` first.

---

### Task 1: `entry_redirects` migration + table registration

**Files:**
- Create: `database/migrations/010_CreateEntryRedirectsTable.php`
- Modify: `tests/Support/LemmaTestCase.php`, `scripts/run-test-migrations.php`
- Test: `tests/Integration/Seo/RedirectRepositoryTest.php` (schema assertions here; repo methods in Task 2)

- [ ] **Step 1: Write the failing schema test.** New `tests/Integration/Seo/RedirectRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\LemmaTestCase;

final class RedirectRepositoryTest extends LemmaTestCase
{
    public function testEntryRedirectsTableShapeAndConstraints(): void
    {
        $pdo = $this->connection()->getPDO();

        // A valid internal-triple redirect inserts.
        self::assertTrue((bool) $pdo->prepare(
            "INSERT INTO entry_redirects
               (uuid, content_type_uuid, locale, source_slug,
                target_entry_uuid, target_content_type_uuid, target_locale, status, origin, created_at)
             VALUES ('rdaaaaaaaaaa', 'type00000001', 'en', 'old-path',
                'entry0000001', 'type00000001', 'en', 301, 'auto', now())"
        )->execute());

        // A valid literal-url redirect inserts.
        self::assertTrue((bool) $pdo->prepare(
            "INSERT INTO entry_redirects
               (uuid, content_type_uuid, locale, source_slug, target_url, status, origin, created_at)
             VALUES ('rdbbbbbbbbbb', 'type00000001', 'en', 'gone-ext',
                'https://example.com/x', 308, 'manual', now())"
        )->execute());

        // Exclusive-target CHECK: BOTH a triple and a url is rejected.
        $this->expectException(\PDOException::class);
        $pdo->prepare(
            "INSERT INTO entry_redirects
               (uuid, content_type_uuid, locale, source_slug,
                target_entry_uuid, target_content_type_uuid, target_locale, target_url, status, origin, created_at)
             VALUES ('rdcccccccccc', 'type00000001', 'en', 'both',
                'entry0000001', 'type00000001', 'en', 'https://x', 301, 'manual', now())"
        )->execute();
    }

    public function testStatusCheckRejectsBadCode(): void
    {
        $pdo = $this->connection()->getPDO();
        $this->expectException(\PDOException::class);
        $pdo->prepare(
            "INSERT INTO entry_redirects
               (uuid, content_type_uuid, locale, source_slug, target_url, status, origin, created_at)
             VALUES ('rddddddddddd', 'type00000001', 'en', 'bad', 'https://x', 307, 'manual', now())"
        )->execute();
    }

    public function testUniqueSourceRejectsDuplicate(): void
    {
        $pdo = $this->connection()->getPDO();
        $ins = static fn (string $uuid): bool => (bool) $pdo->prepare(
            "INSERT INTO entry_redirects
               (uuid, content_type_uuid, locale, source_slug, target_url, status, origin, created_at)
             VALUES (?, 'type00000001', 'en', 'dup', 'https://x', 301, 'manual', now())"
        )->execute([$uuid]);

        self::assertTrue($ins('rdeeeeeeeeee'));
        $this->expectException(\PDOException::class);
        $ins('rdffffffffff'); // same (content_type_uuid, locale, source_slug) → unique violation
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter RedirectRepositoryTest`
Expected: FAIL — `relation "entry_redirects" does not exist`.

- [ ] **Step 3: Create the migration + register the table.**

`database/migrations/010_CreateEntryRedirectsTable.php`:
```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryRedirectsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_redirects')) {
            return;
        }
        $schema->createTable('entry_redirects', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('content_type_uuid', 12);     // SOURCE content type (lookup key)
            $table->string('locale', 16);                // SOURCE locale (lookup key)
            $table->string('source_slug', 200);          // the moved/source path
            // internal target identity (resolves to the target entry's CURRENT slug):
            $table->string('target_entry_uuid', 12)->nullable();
            $table->string('target_content_type_uuid', 12)->nullable();
            $table->string('target_locale', 16)->nullable();
            // OR external/literal target (terminal):
            $table->text('target_url')->nullable();
            $table->integer('status');                   // CHECK (301,302,308)
            $table->string('origin', 16);                // CHECK ('auto','manual')
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['content_type_uuid', 'locale', 'source_slug'], 'uniq_redirect_source');
            $table->index('target_entry_uuid', 'idx_redirect_target_entry');
        });

        // CHECK guards are Postgres DDL the schema builder does not express, so run them
        // as raw SQL (same approach as the scheduled-publish migration's CHECK constraints).
        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec("ALTER TABLE entry_redirects ADD CONSTRAINT chk_redirect_status "
            . "CHECK (status IN (301,302,308))");
        $pdo->exec("ALTER TABLE entry_redirects ADD CONSTRAINT chk_redirect_origin "
            . "CHECK (origin IN ('auto','manual'))");
        // Exactly one target shape: a full internal triple, or a literal url (never both/neither).
        $pdo->exec(
            "ALTER TABLE entry_redirects ADD CONSTRAINT chk_redirect_target_exclusive CHECK (\n"
            . "  (target_entry_uuid IS NOT NULL AND target_content_type_uuid IS NOT NULL\n"
            . "     AND target_locale IS NOT NULL AND target_url IS NULL)\n"
            . "  OR\n"
            . "  (target_entry_uuid IS NULL AND target_content_type_uuid IS NULL\n"
            . "     AND target_locale IS NULL AND target_url IS NOT NULL)\n"
            . ")"
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_redirects');
    }

    public function getDescription(): string
    {
        return 'Create entry_redirects (per-source redirects: internal triple XOR literal url).';
    }
}
```

In `tests/Support/LemmaTestCase.php`, add `'entry_redirects'` first in `TABLES` (child→parent order; no FKs, but keep deterministic):
```php
private const TABLES = [
    'entry_redirects',
    'import_export_reports', 'import_export_errors', 'import_export_files',
    'import_export_batches', 'import_export_jobs',
    'entry_references', 'entry_routes', 'entry_publications',
    'entry_versions', 'entry_drafts', 'entries', 'content_types',
];
```
In `scripts/run-test-migrations.php`, add `'entry_redirects'` to the `$requiredTables` list so migration-verification covers it.

- [ ] **Step 4: Run it; verify it passes.**

Run: `composer test:reset-db && composer test:migrate && composer test:phpunit -- --filter RedirectRepositoryTest`
Expected: PASS (table created; both valid inserts succeed; the both-targets, bad-status, and duplicate-source inserts each throw).

- [ ] **Step 5: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add database/migrations/010_CreateEntryRedirectsTable.php tests/Support/LemmaTestCase.php scripts/run-test-migrations.php tests/Integration/Seo/RedirectRepositoryTest.php
git commit -m "Add entry_redirects table with exclusive internal/external target"
```

---

### Task 2: `RedirectRepository` — lookup, upsert, CRUD

**Files:**
- Create: `app/Content/Seo/RedirectRepository.php`
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Seo/RedirectRepositoryTest.php`

- [ ] **Step 1: Write failing tests.** Append to `RedirectRepositoryTest`:
```php
public function testUpsertAutoCreatesThenUpdatesSameSource(): void
{
    $repo = new \App\Content\Seo\RedirectRepository($this->connection());

    $repo->upsertAuto('type00000001', 'en', 'old-a', 'entry0000001', 'type00000001', 'en');
    $row = $repo->findForSource('type00000001', 'en', 'old-a');
    self::assertSame('entry0000001', $row['target_entry_uuid']);
    self::assertSame(301, (int) $row['status']);
    self::assertSame('auto', $row['origin']);

    // Re-pointing the same source (a later rename collapsing onto it) updates in place.
    $repo->upsertAuto('type00000001', 'en', 'old-a', 'entry0000002', 'type00000001', 'en');
    $row = $repo->findForSource('type00000001', 'en', 'old-a');
    self::assertSame('entry0000002', $row['target_entry_uuid']);
    self::assertCount(1, $repo->forType('type00000001', null));
}

public function testCreateInternalAndExternalAndDeleteBySource(): void
{
    $repo = new \App\Content\Seo\RedirectRepository($this->connection());

    $a = $repo->create([
        'content_type_uuid' => 'type00000001', 'locale' => 'en', 'source_slug' => 'moved',
        'target_entry_uuid' => 'entry0000001', 'target_content_type_uuid' => 'type00000001',
        'target_locale' => 'en', 'target_url' => null, 'status' => 302, 'origin' => 'manual',
        'created_by' => 'user00000001',
    ]);
    self::assertSame(12, strlen($a['uuid']));

    $repo->create([
        'content_type_uuid' => 'type00000001', 'locale' => 'en', 'source_slug' => 'ext',
        'target_entry_uuid' => null, 'target_content_type_uuid' => null, 'target_locale' => null,
        'target_url' => 'https://example.com', 'status' => 308, 'origin' => 'manual', 'created_by' => null,
    ]);

    self::assertCount(2, $repo->forType('type00000001', null));
    $repo->deleteBySource('type00000001', 'en', 'moved');
    self::assertNull($repo->findForSource('type00000001', 'en', 'moved'));
    self::assertCount(1, $repo->forType('type00000001', null));
}

public function testDeleteByUuidAndListFilteredByLocale(): void
{
    $repo = new \App\Content\Seo\RedirectRepository($this->connection());
    $repo->create([
        'content_type_uuid' => 'type00000001', 'locale' => 'en', 'source_slug' => 'a',
        'target_entry_uuid' => null, 'target_content_type_uuid' => null, 'target_locale' => null,
        'target_url' => 'https://e', 'status' => 301, 'origin' => 'manual', 'created_by' => null,
    ]);
    $fr = $repo->create([
        'content_type_uuid' => 'type00000001', 'locale' => 'fr', 'source_slug' => 'a',
        'target_entry_uuid' => null, 'target_content_type_uuid' => null, 'target_locale' => null,
        'target_url' => 'https://f', 'status' => 301, 'origin' => 'manual', 'created_by' => null,
    ]);

    self::assertCount(1, $repo->forType('type00000001', 'fr'));
    self::assertTrue($repo->delete($fr['uuid']));
    self::assertFalse($repo->delete($fr['uuid'])); // already gone
    self::assertCount(1, $repo->forType('type00000001', null));
}
```

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter RedirectRepositoryTest` → FAIL: `Class "App\Content\Seo\RedirectRepository" not found`.

- [ ] **Step 3: Implement `RedirectRepository`:**
```php
<?php

declare(strict_types=1);

namespace App\Content\Seo;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * Storage for entry_redirects: one redirect per (source content type, source locale,
 * source slug). A redirect is either INTERNAL (full {entry, content_type, locale} target
 * triple, resolved by the resolver to the target's current slug) or EXTERNAL (a literal
 * terminal url). The exclusive-target invariant is enforced by a DB CHECK; this class only
 * reads/writes whole rows.
 */
final class RedirectRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null the single redirect for a source path, or null. */
    public function findForSource(string $contentTypeUuid, string $locale, string $sourceSlug): ?array
    {
        return $this->db->table('entry_redirects')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('source_slug', '=', $sourceSlug)
            ->first() ?: null;
    }

    /**
     * Auto-capture upsert (origin='auto', status=301): create or re-point the redirect for a
     * source path onto an internal target triple. Used by the RouteRepository::assign hook,
     * which always passes the same-entry/type/locale triple (never cross-type/locale).
     */
    public function upsertAuto(
        string $contentTypeUuid,
        string $locale,
        string $sourceSlug,
        string $targetEntryUuid,
        string $targetContentTypeUuid,
        string $targetLocale,
    ): void {
        $existing = $this->findForSource($contentTypeUuid, $locale, $sourceSlug);
        if ($existing === null) {
            $this->db->table('entry_redirects')->insert([
                'uuid' => Utils::generateNanoID(12),
                'content_type_uuid' => $contentTypeUuid,
                'locale' => $locale,
                'source_slug' => $sourceSlug,
                'target_entry_uuid' => $targetEntryUuid,
                'target_content_type_uuid' => $targetContentTypeUuid,
                'target_locale' => $targetLocale,
                'target_url' => null,
                'status' => 301,
                'origin' => 'auto',
                'created_at' => $this->now(),
            ]);
            return;
        }
        $this->db->table('entry_redirects')
            ->where('id', '=', $existing['id'])
            ->update([
                'target_entry_uuid' => $targetEntryUuid,
                'target_content_type_uuid' => $targetContentTypeUuid,
                'target_locale' => $targetLocale,
                'target_url' => null,
                'status' => 301,
                'origin' => 'auto',
                'updated_at' => $this->now(),
            ]);
    }

    /** Delete the redirect (if any) whose source is this path — used when a live route claims it. */
    public function deleteBySource(string $contentTypeUuid, string $locale, string $sourceSlug): void
    {
        $this->db->table('entry_redirects')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('source_slug', '=', $sourceSlug)
            ->delete();
    }

    /**
     * Insert a manual (or arbitrary) redirect row from a fully-formed array; returns the row.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entry_redirects')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => $data['content_type_uuid'],
            'locale' => $data['locale'],
            'source_slug' => $data['source_slug'],
            'target_entry_uuid' => $data['target_entry_uuid'] ?? null,
            'target_content_type_uuid' => $data['target_content_type_uuid'] ?? null,
            'target_locale' => $data['target_locale'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'status' => (int) $data['status'],
            'origin' => $data['origin'] ?? 'manual',
            'created_by' => $data['created_by'] ?? null,
            'created_at' => $this->now(),
        ]);
        return $this->findByUuid($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->table('entry_redirects')->where('uuid', '=', $uuid)->first() ?: null;
    }

    /** Delete by public uuid; returns true when a row was removed. */
    public function delete(string $uuid): bool
    {
        return $this->db->table('entry_redirects')->where('uuid', '=', $uuid)->delete() >= 1;
    }

    /**
     * List redirects for a source content type (auto + manual), optionally filtered by locale.
     *
     * @return list<array<string,mixed>>
     */
    public function forType(string $contentTypeUuid, ?string $locale): array
    {
        $q = $this->db->table('entry_redirects')
            ->where('content_type_uuid', '=', $contentTypeUuid);
        if ($locale !== null && $locale !== '') {
            $q->where('locale', '=', $locale);
        }
        return $q->orderBy('id', 'DESC')->get();
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
```

- [ ] **Step 4: Register in `LemmaServiceProvider::services()`** (mirror the existing repos; add `use App\Content\Seo\RedirectRepository;`):
```php
RedirectRepository::class => ['class' => RedirectRepository::class, 'shared' => true, 'autowire' => true],
```

- [ ] **Step 5: Run; verify pass.** `composer test:phpunit -- --filter RedirectRepositoryTest` → PASS.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Seo/RedirectRepository.php app/Providers/LemmaServiceProvider.php tests/Integration/Seo/RedirectRepositoryTest.php
git commit -m "Add RedirectRepository lookup/upsert/create/delete"
```

---

### Task 3: `ResolutionResult` value object + `PathRenderer`

**Files:**
- Create: `app/Content/Seo/ResolutionResult.php`, `app/Content/Seo/PathRenderer.php`
- Modify: `config/lemma.php`, `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Seo/PathRendererTest.php`

- [ ] **Step 1: Write the failing `PathRenderer` test** (the URL contract — P1). New `tests/Integration/Seo/PathRendererTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Seo\PathRenderer;
use App\Tests\Support\LemmaTestCase;

final class PathRendererTest extends LemmaTestCase
{
    private function renderer(): PathRenderer
    {
        return $this->container()->get(PathRenderer::class);
    }

    public function testRendersRelativePathFromDefaultTemplate(): void
    {
        // default template '/{locale}/{type}/{slug}', default locale 'en' (test env).
        $out = $this->renderer()->render('post', 'fr', 'bonjour');
        self::assertSame('/fr/post/bonjour', $out);
    }

    public function testDefaultLocaleOmitsLocaleSegment(): void
    {
        // x_default variant: the default locale renders without the /{locale} prefix.
        self::assertSame('/post/hello', $this->renderer()->renderDefaultLocale('post', 'hello'));
    }

    public function testPublicUrlBaseProducesAbsoluteUrl(): void
    {
        // Asserted by passing an explicit base (the controller/projector read config; the
        // renderer accepts the resolved base so the unit is config-free and deterministic).
        $r = new PathRenderer('/{locale}/{type}/{slug}', 'https://site.test');
        self::assertSame('https://site.test/fr/post/bonjour', $r->render('post', 'fr', 'bonjour'));
        self::assertSame('https://site.test/post/hello', $r->renderDefaultLocale('post', 'hello'));
    }
}
```

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter PathRendererTest` → FAIL: `Class "App\Content\Seo\PathRenderer" not found`.

- [ ] **Step 3a: Add config keys** to `config/lemma.php` (append a `seo` block before the closing `];`):
```php
    // SEO / routing module (see docs/superpowers/specs/2026-06-16-seo-routing-module-design.md).
    'seo' => [
        // Relative public-path template the module renders for redirect `to` + canonical/hreflang.
        // The default-locale variant drops the leading /{locale} segment (x_default).
        'route_template' => env('LEMMA_SEO_ROUTE_TEMPLATE', '/{locale}/{type}/{slug}'),
        // Optional absolute base; when set, rendered paths become absolute URLs.
        'public_url_base' => env('LEMMA_SEO_PUBLIC_URL_BASE', ''),
        // Short Cache-Control max-age (seconds) for redirect/Gone descriptors (operationally mutable).
        'redirect_ttl' => (int) env('LEMMA_SEO_REDIRECT_TTL', 60),
    ],
```

- [ ] **Step 3b: Create `PathRenderer`:**
```php
<?php

declare(strict_types=1);

namespace App\Content\Seo;

/**
 * Renders the RELATIVE public path the module emits for a {content_type, locale, slug}
 * identity, from a per-install route template (config lemma.seo.route_template, default
 * '/{locale}/{type}/{slug}'). When a public_url_base is set the path is prefixed to an
 * absolute URL; otherwise it stays relative and the frontend prefixes. The module NEVER
 * emits API paths. The default-locale variant (renderDefaultLocale) drops the /{locale}
 * segment so x_default carries no locale prefix.
 */
final class PathRenderer
{
    public function __construct(
        private readonly string $template = '/{locale}/{type}/{slug}',
        private readonly string $publicUrlBase = '',
    ) {
    }

    public function render(string $type, string $locale, string $slug): string
    {
        return $this->prefix($this->fill($this->template, $type, $locale, $slug));
    }

    /** The default-locale variant: render with the /{locale} segment removed (x_default). */
    public function renderDefaultLocale(string $type, string $slug): string
    {
        // Drop a leading or embedded '/{locale}' segment, then fill the rest.
        $template = (string) preg_replace('#/?\{locale\}#', '', $this->template);
        $path = $this->fill($template, $type, '', $slug);
        // Collapse any doubled slash left by the removal, keep a single leading slash.
        $path = '/' . ltrim((string) preg_replace('#/{2,}#', '/', $path), '/');
        return $this->prefix($path);
    }

    private function fill(string $template, string $type, string $locale, string $slug): string
    {
        return strtr($template, [
            '{locale}' => $locale,
            '{type}' => $type,
            '{slug}' => $slug,
        ]);
    }

    private function prefix(string $path): string
    {
        $base = rtrim($this->publicUrlBase, '/');
        return $base === '' ? $path : $base . $path;
    }
}
```

- [ ] **Step 3c: Register `PathRenderer`** in `LemmaServiceProvider::services()` with an explicit factory that injects the config (autowire cannot fill the two string scalars). Add `use App\Content\Seo\PathRenderer;`:
```php
PathRenderer::class => [
    'class' => PathRenderer::class,
    'shared' => true,
    'factory' => static fn (\Glueful\Bootstrap\ApplicationContext $ctx): PathRenderer => new PathRenderer(
        (string) config($ctx, 'lemma.seo.route_template', '/{locale}/{type}/{slug}'),
        (string) config($ctx, 'lemma.seo.public_url_base', ''),
    ),
],
```
> If the provider's `services()` definitions do not support a `factory` closure (verify against an existing factory-style registration, e.g. `PreviewMinter`/`ContentLocaleService`), fall back to autowiring `PathRenderer(ApplicationContext $context)` and reading the two config keys in the constructor. Either way `render`/`renderDefaultLocale` keep the same signatures the tests use; the second `PathRendererTest` case constructs the renderer directly so it is independent of the wiring choice.

- [ ] **Step 4: Run; verify pass.** `composer test:phpunit -- --filter PathRendererTest` → PASS.

- [ ] **Step 5: Create `ResolutionResult`** (no test of its own — exercised by `RouteResolverTest` in Task 4; create it here so Task 4 has the type):
```php
<?php

declare(strict_types=1);

namespace App\Content\Seo;

/**
 * Discriminated result of RouteResolver::resolve(): exactly one of four cases.
 *   - Content: a live entry_routes row matched -> serve the entry row.
 *   - Redirect: a redirect matched and its target is resolvable. $to is the rendered
 *     relative path; for an internal target $target = {content_type, locale, slug} and
 *     $external = false; for a literal target $to = target_url, $external = true, $target = null.
 *   - Gone: a redirect matched but its internal target has no current live route.
 *   - NotFound: nothing matched.
 */
final class ResolutionResult
{
    public const CONTENT = 'content';
    public const REDIRECT = 'redirect';
    public const GONE = 'gone';
    public const NOT_FOUND = 'not_found';

    /**
     * @param array<string,mixed>|null $entry   the published row (CONTENT case)
     * @param array<string,mixed>|null $target  {content_type, locale, slug} (internal REDIRECT)
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?array $entry = null,
        public readonly ?string $to = null,
        public readonly ?int $status = null,
        public readonly bool $external = false,
        public readonly ?array $target = null,
        public readonly ?string $redirectUuid = null,
    ) {
    }

    /** @param array<string,mixed> $entry */
    public static function content(array $entry): self
    {
        return new self(self::CONTENT, entry: $entry);
    }

    /** @param array<string,mixed>|null $target */
    public static function redirect(
        string $to,
        int $status,
        bool $external,
        ?array $target,
        string $redirectUuid,
    ): self {
        return new self(self::REDIRECT, to: $to, status: $status, external: $external, target: $target, redirectUuid: $redirectUuid);
    }

    public static function gone(string $redirectUuid): self
    {
        return new self(self::GONE, redirectUuid: $redirectUuid);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public function isContent(): bool
    {
        return $this->kind === self::CONTENT;
    }

    public function isRedirect(): bool
    {
        return $this->kind === self::REDIRECT;
    }

    public function isGone(): bool
    {
        return $this->kind === self::GONE;
    }
}
```

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Seo/ResolutionResult.php app/Content/Seo/PathRenderer.php config/lemma.php app/Providers/LemmaServiceProvider.php tests/Integration/Seo/PathRendererTest.php
git commit -m "Add ResolutionResult value object, PathRenderer, and seo config keys"
```

---

### Task 4: `RouteResolver` — precedence, single-hop, requested-locale redirects

**Files:**
- Create: `app/Content/Seo/RouteResolver.php`
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Seo/RouteResolverTest.php`

The resolver leans on the existing `DeliveryRepository` (published-only reads) so a redirect whose target is unpublished resolves `Gone`. Setup helpers in the test create a content type + a published entry + a route (reuse the controller/repository helpers the existing integration tests use — `ContentTypeRepository::create`, an entry+draft+`PublishService::publish`, and `RouteRepository::assign`).

- [ ] **Step 1: Write failing tests.** New `tests/Integration/Seo/RouteResolverTest.php` — one method per spec case. Use a small private helper that publishes an entry of a type+locale at a slug and returns its entry uuid (build it from `ContentTypeRepository`, `EntryRepository`, draft save, `PublishService::publish`, `RouteRepository::assign` — copy the arrange block from `DeliveryRepositoryTest`/`PublishServiceTest`). Cases:
```php
// testLiveRouteResolvesToContent: published entry at /post/hello (en) -> resolve returns
//   Content with that entry row.
// testMovedSlugResolvesToRedirectWithCurrentSlugAnd301: entry published at slug 'new';
//   a redirect {source 'old' -> entry, 301} exists -> resolve('old') returns
//   Redirect{status:301, external:false, to:'/en/post/new', target:{content_type:'post',locale:'en',slug:'new'}}.
// testExternalRedirectResolvesVerbatim: redirect {source 'ext' -> url 'https://x', 302}
//   -> Redirect{external:true, to:'https://x', target:null, status:302}.
// testRedirectToEntryWithNoLiveRouteResolvesGone: redirect points at an entry that is NOT
//   published (or whose route was removed) -> Gone.
// testNoMatchResolvesNotFound: unknown slug, no redirect -> NotFound.
// testCrossTypeCrossLocaleInternalTarget (P1): a redirect on (post, en, 'old') whose target
//   triple is {entry, docs-type, fr} and the docs entry is published in fr at 'guide' ->
//   Redirect to '/fr/docs/guide', target.content_type='docs', target.locale='fr'.
// testRedirectsAreRequestedLocaleOnly (P2): an 'en' redirect for 'old' exists but NO 'fr'
//   redirect; resolve(type, [fr,en], requested='fr', 'old') -> NotFound (the en redirect is
//   not used). Assert separately that a live fr-miss still falls back to en CONTENT via the
//   existing DeliveryController chain (covered in DeliverySeoTest).
// testSingleHopNoChainFollowing: A->B redirect and B is itself a live route; resolving A
//   returns B's rendered path in exactly one hop (the resolver reads the TARGET ENTRY's
//   current live slug, never another redirect).
```
The resolver test calls `resolve($typeUuid, $localeChain, $requestedLocale, $path)` directly and asserts on the returned `ResolutionResult`.

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter RouteResolverTest` → FAIL: `Class "App\Content\Seo\RouteResolver" not found`.

- [ ] **Step 3: Implement `RouteResolver`:**
```php
<?php

declare(strict_types=1);

namespace App\Content\Seo;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;

/**
 * Resolves a delivery path to a discriminated ResolutionResult (Content/Redirect/Gone/
 * NotFound) — the SEO module's hook into core delivery resolution.
 *
 * Precedence (spec §2):
 *   1. Live route across the full locale fallback chain (unchanged from today) -> Content.
 *   2. Redirect for the REQUESTED locale ONLY (redirects never walk the fallback chain) ->
 *      Redirect (target resolvable) or Gone (internal target has no current live route).
 *   3. Otherwise NotFound (the caller then tries the nanoid/uuid fallback, then 404s).
 *
 * Single-hop by construction: an internal target stores the target entry's identity, and we
 * read that entry's CURRENT live slug once — never another redirect — so chains/loops cannot
 * form. A literal target is returned verbatim.
 */
final class RouteResolver
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly RedirectRepository $redirects,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
        private readonly PathRenderer $paths,
    ) {
    }

    /**
     * @param non-empty-list<string> $localeChain requested locale + fallbacks (live-route lookup)
     */
    public function resolve(string $typeUuid, array $localeChain, string $requestedLocale, string $path): ResolutionResult
    {
        // 1. Live route across the fallback chain (published-only).
        foreach ($localeChain as $locale) {
            $row = $this->delivery->findPublishedByRoute($typeUuid, $locale, $path);
            if ($row !== null) {
                return ResolutionResult::content($row);
            }
        }

        // 2. Redirect for the REQUESTED locale only.
        $redirect = $this->redirects->findForSource($typeUuid, $requestedLocale, $path);
        if ($redirect === null) {
            return ResolutionResult::notFound();
        }

        // External/literal target: terminal, returned verbatim.
        if (($redirect['target_url'] ?? null) !== null && $redirect['target_url'] !== '') {
            return ResolutionResult::redirect(
                (string) $redirect['target_url'],
                (int) $redirect['status'],
                true,
                null,
                (string) $redirect['uuid'],
            );
        }

        // Internal target: resolve the target entry's CURRENT live slug in its own type/locale
        // (single hop). No current live route -> Gone.
        $targetTypeUuid = (string) $redirect['target_content_type_uuid'];
        $targetLocale = (string) $redirect['target_locale'];
        $targetEntry = (string) $redirect['target_entry_uuid'];

        $live = $this->delivery->findPublishedByUuid($targetTypeUuid, $targetLocale, $targetEntry);
        $routeRow = $this->routes->forEntry($targetEntry);
        $slug = $this->slugFor($routeRow, $targetLocale);
        if ($live === null || $slug === null) {
            return ResolutionResult::gone((string) $redirect['uuid']);
        }

        $typeSlug = $this->typeSlug($targetTypeUuid);
        return ResolutionResult::redirect(
            $this->paths->render($typeSlug, $targetLocale, $slug),
            (int) $redirect['status'],
            false,
            ['content_type' => $typeSlug, 'locale' => $targetLocale, 'slug' => $slug],
            (string) $redirect['uuid'],
        );
    }

    /**
     * The current slug for an entry+locale from its route rows, or null when none exists.
     *
     * @param list<array<string,mixed>> $routeRows
     */
    private function slugFor(array $routeRows, string $locale): ?string
    {
        foreach ($routeRows as $r) {
            if ((string) $r['locale'] === $locale) {
                return (string) $r['slug'];
            }
        }
        return null;
    }

    private function typeSlug(string $typeUuid): string
    {
        $row = $this->types->findByUuid($typeUuid);
        return $row === null ? '' : (string) $row['slug'];
    }
}
```
> Verify `ContentTypeRepository::findByUuid(string): ?array` exists (it is called in `ContentTypeController::store`/`updateSchema`, so it does). If its key is `slug`, the above is correct.

- [ ] **Step 4: Register `RouteResolver`** in `LemmaServiceProvider::services()` (autowire — all five deps are container-known; add `use App\Content\Seo\RouteResolver;`):
```php
RouteResolver::class => ['class' => RouteResolver::class, 'shared' => true, 'autowire' => true],
```

- [ ] **Step 5: Run; verify pass.** `composer test:phpunit -- --filter RouteResolverTest` → PASS (all cases, incl. cross-type/locale, requested-locale-only, single-hop, Gone).

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Seo/RouteResolver.php app/Providers/LemmaServiceProvider.php tests/Integration/Seo/RouteResolverTest.php
git commit -m "Add RouteResolver with single-hop, requested-locale-only redirect precedence"
```

---

### Task 5: Auto-capture hook on `RouteRepository::assign`

**Files:**
- Modify: `app/Content/Repositories/RouteRepository.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (RouteRepository now depends on RedirectRepository)
- Test: `tests/Integration/Seo/AutoCaptureTest.php`, extend `tests/Integration/Content/RouteRepositoryTest.php`

- [ ] **Step 1: Write failing tests.** New `tests/Integration/Seo/AutoCaptureTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Repositories\RouteRepository;
use App\Content\Seo\RedirectRepository;
use App\Tests\Support\LemmaTestCase;

final class AutoCaptureTest extends LemmaTestCase
{
    private function routes(): RouteRepository
    {
        return $this->container()->get(RouteRepository::class);
    }

    private function redirects(): RedirectRepository
    {
        return new RedirectRepository($this->connection());
    }

    public function testRenameCapturesAuto301FromOldSlug(): void
    {
        $r = $this->routes();
        $r->assign('entry0000001', 'type00000001', 'en', 'a');     // initial assign, no old slug
        self::assertNull($this->redirects()->findForSource('type00000001', 'en', 'a'));

        $r->assign('entry0000001', 'type00000001', 'en', 'b');     // a -> b
        $redirect = $this->redirects()->findForSource('type00000001', 'en', 'a');
        self::assertNotNull($redirect);
        self::assertSame('entry0000001', $redirect['target_entry_uuid']);
        self::assertSame('type00000001', $redirect['target_content_type_uuid']);
        self::assertSame('en', $redirect['target_locale']);
        self::assertSame(301, (int) $redirect['status']);
        self::assertSame('auto', $redirect['origin']);
    }

    public function testRepeatedRenamesLeaveBothOldSlugsRedirecting(): void
    {
        $r = $this->routes();
        $r->assign('entry0000001', 'type00000001', 'en', 'a');
        $r->assign('entry0000001', 'type00000001', 'en', 'b'); // a -> entry
        $r->assign('entry0000001', 'type00000001', 'en', 'c'); // b -> entry

        // BOTH a and b are redirects pointing at the entry (no chain a->b->c).
        self::assertSame('entry0000001', $this->redirects()->findForSource('type00000001', 'en', 'a')['target_entry_uuid']);
        self::assertSame('entry0000001', $this->redirects()->findForSource('type00000001', 'en', 'b')['target_entry_uuid']);
        self::assertCount(2, $this->redirects()->forType('type00000001', null));
    }

    public function testNewLiveSlugClearsItsOwnRedirect(): void
    {
        $r = $this->routes();
        $r->assign('entry0000001', 'type00000001', 'en', 'a');
        $r->assign('entry0000001', 'type00000001', 'en', 'b'); // captures a -> entry
        // Now a SECOND entry takes slug 'a' as a live route: the redirect at 'a' must be deleted.
        $r->assign('entry0000002', 'type00000001', 'en', 'a');
        self::assertNull($this->redirects()->findForSource('type00000001', 'en', 'a'), 'live route wins');
    }

    public function testOldEqualsNewIsNoOp(): void
    {
        $r = $this->routes();
        $r->assign('entry0000001', 'type00000001', 'en', 'a');
        $r->assign('entry0000001', 'type00000001', 'en', 'a'); // same slug
        self::assertNull($this->redirects()->findForSource('type00000001', 'en', 'a'));
        self::assertCount(0, $this->redirects()->forType('type00000001', null));
    }
}
```
Also extend `tests/Integration/Content/RouteRepositoryTest.php`: change `RouteRepositoryTest` to resolve the repo from the container (`$this->container()->get(RouteRepository::class)`) instead of `new RouteRepository(...)`, since `assign` now needs the injected `RedirectRepository`. Keep the existing two assertions.

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter "AutoCaptureTest|RouteRepositoryTest"` → FAIL (no redirect captured; `RouteRepository` constructor mismatch once you wire the new dep).

- [ ] **Step 3: Implement the hook** in `RouteRepository`. Add the `RedirectRepository` dependency and capture/clear logic in `assign`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Seo\RedirectRepository;
use Glueful\Database\Connection;

final class RouteRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedirectRepository $redirects,
    ) {
    }

    /**
     * Upsert the route for an entry+locale (one slug per entry+locale). On a slug rename the
     * SEO module auto-captures a 301 from the old path (chain-free: the redirect targets the
     * entry, which resolves to its current slug), and any redirect colliding with the NEW
     * live slug is dropped (a live route always wins over a redirect of the same path).
     */
    public function assign(string $entryUuid, string $contentTypeUuid, string $locale, string $slug): void
    {
        $existing = $this->db->table('entry_routes')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();

        if ($existing === null) {
            $this->db->table('entry_routes')->insert([
                'entry_uuid' => $entryUuid,
                'content_type_uuid' => $contentTypeUuid,
                'locale' => $locale,
                'slug' => $slug,
            ]);
        } else {
            $oldSlug = (string) $existing['slug'];
            if ($oldSlug !== $slug) {
                // Capture a 301 from the OLD path onto this same entry/type/locale (auto-capture
                // is never cross-type/locale). Single-hop: resolution reads the entry's current slug.
                $this->redirects->upsertAuto($contentTypeUuid, $locale, $oldSlug, $entryUuid, $contentTypeUuid, $locale);
            }
            $this->db->table('entry_routes')
                ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
                ->update(['slug' => $slug, 'content_type_uuid' => $contentTypeUuid]);
        }

        // A live route cannot also be a redirect source (live wins): drop any redirect at this slug.
        $this->redirects->deleteBySource($contentTypeUuid, $locale, $slug);
    }

    // ... isSlugAvailable / findBySlug / forEntry / remove unchanged ...
```
> Keep the remaining methods (`isSlugAvailable`, `findBySlug`, `forEntry`, `remove`) exactly as they are. `LemmaServiceProvider` already registers `RouteRepository` with `autowire => true`, so the new `RedirectRepository` constructor arg is filled automatically — no provider edit needed beyond confirming `RedirectRepository` is registered (Task 2). (There is no circular dependency: `RedirectRepository` depends only on `Connection`.)

- [ ] **Step 4: Run; verify pass.** `composer test:phpunit -- --filter "AutoCaptureTest|RouteRepositoryTest"` → PASS.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Repositories/RouteRepository.php tests/Integration/Seo/AutoCaptureTest.php tests/Integration/Content/RouteRepositoryTest.php
git commit -m "Auto-capture a 301 redirect on entry slug rename"
```

---

### Task 6: `CanonicalProjector` — self-canonical + hreflang + x_default

**Files:**
- Create: `app/Content/Seo/CanonicalProjector.php`
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Seo/CanonicalProjectorTest.php`

- [ ] **Step 1: Write failing tests.** New `tests/Integration/Seo/CanonicalProjectorTest.php` (reuse the publish helper from Task 4's test — publish the SAME entry in en + fr, each with its own route slug):
```php
// testSelfCanonicalPlusAlternatesAndXDefault: entry published in en (slug 'hello') + fr
//   (slug 'bonjour'), default locale 'en'. projector->for(entryUuid, typeUuid, 'fr') returns:
//     canonical: {locale:'fr', href:'/fr/post/bonjour', content_type:'post', slug:'bonjour'}
//     alternates: [ {locale:'en', href:'/en/post/hello', ...} ]   (the OTHER published locale)
//     x_default: {locale:'en', href:'/post/hello', ...}           (default locale, no /{locale})
// testUnpublishedLocaleExcluded: entry published only in en; for(entryUuid, typeUuid, 'en')
//   -> canonical en, alternates empty, x_default = en path.
// testEachHrefCarriesIdentity: every canonical/alternate/x_default node has
//   content_type+locale+slug+href keys (URL contract: rendered path + structured identity).
```

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter CanonicalProjectorTest` → FAIL: class not found.

- [ ] **Step 3: Implement `CanonicalProjector`:**
```php
<?php

declare(strict_types=1);

namespace App\Content\Seo;

use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use Glueful\Database\Connection;

/**
 * Derives the delivery `seo` block — self-canonical + hreflang alternates + x_default — from
 * entry_publications (which locales are published) joined to entry_routes (their slugs). No
 * new storage. Every href is a rendered RELATIVE path (PathRenderer) carrying its
 * {content_type, locale, slug} identity. Unpublished locales are excluded. The default-locale
 * node (x_default) renders without the /{locale} segment.
 *
 * @phpstan-type SeoNode array{content_type:string, locale:string, slug:string, href:string}
 */
final class CanonicalProjector
{
    public function __construct(
        private readonly Connection $db,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
        private readonly ContentLocaleService $locales,
        private readonly PathRenderer $paths,
    ) {
    }

    /**
     * @return array{canonical: array<string,string>|null, alternates: list<array<string,string>>, x_default: array<string,string>|null}
     */
    public function for(string $entryUuid, string $contentTypeUuid, string $locale): array
    {
        $typeSlug = $this->typeSlug($contentTypeUuid);

        // Published locales for this entry.
        $publishedLocales = array_map(
            static fn (array $r): string => (string) $r['locale'],
            $this->db->table('entry_publications')->where('entry_uuid', '=', $entryUuid)->get()
        );

        // Current slug per locale from the entry's routes.
        $slugByLocale = [];
        foreach ($this->routes->forEntry($entryUuid) as $r) {
            $slugByLocale[(string) $r['locale']] = (string) $r['slug'];
        }

        $node = function (string $loc) use ($typeSlug, $slugByLocale): ?array {
            if (!isset($slugByLocale[$loc])) {
                return null;
            }
            $slug = $slugByLocale[$loc];
            return [
                'content_type' => $typeSlug,
                'locale' => $loc,
                'slug' => $slug,
                'href' => $this->paths->render($typeSlug, $loc, $slug),
            ];
        };

        $canonical = $node($locale);

        $alternates = [];
        foreach ($publishedLocales as $loc) {
            if ($loc === $locale) {
                continue;
            }
            $alt = $node($loc);
            if ($alt !== null) {
                $alternates[] = $alt;
            }
        }

        // x_default = the default locale, rendered WITHOUT the /{locale} segment.
        $default = $this->locales->default();
        $xDefault = null;
        if (in_array($default, $publishedLocales, true) && isset($slugByLocale[$default])) {
            $slug = $slugByLocale[$default];
            $xDefault = [
                'content_type' => $typeSlug,
                'locale' => $default,
                'slug' => $slug,
                'href' => $this->paths->renderDefaultLocale($typeSlug, $slug),
            ];
        }

        return ['canonical' => $canonical, 'alternates' => $alternates, 'x_default' => $xDefault];
    }

    private function typeSlug(string $typeUuid): string
    {
        $row = $this->types->findByUuid($typeUuid);
        return $row === null ? '' : (string) $row['slug'];
    }
}
```

- [ ] **Step 4: Register `CanonicalProjector`** in `LemmaServiceProvider::services()` (autowire; add `use App\Content\Seo\CanonicalProjector;`):
```php
CanonicalProjector::class => ['class' => CanonicalProjector::class, 'shared' => true, 'autowire' => true],
```

- [ ] **Step 5: Run; verify pass.** `composer test:phpunit -- --filter CanonicalProjectorTest` → PASS.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Seo/CanonicalProjector.php app/Providers/LemmaServiceProvider.php tests/Integration/Seo/CanonicalProjectorTest.php
git commit -m "Add CanonicalProjector (self-canonical + hreflang + x_default)"
```

---

### Task 7: Delivery integration — resolution mapping + `seo` block + redirect caching

**Files:**
- Modify: `app/Content/Http/Controllers/DeliveryController.php`
- Test: `tests/Integration/Http/DeliverySeoTest.php`

The controller resolves `show()` through `RouteResolver`, then maps each case. `Content` keeps today's version-UUID ETag but adds the `seo` block (and extends the cache-tag set with the alternate entry uuids). `Redirect`/`Gone` get the derived ETag + short TTL + target-entry cache tag.

- [ ] **Step 1: Write failing tests.** New `tests/Integration/Http/DeliverySeoTest.php` — drive the controller's `show()` (resolve it from the container) or the full kernel via `handle()`; both are available in `LemmaTestCase`. For the mapping/caching cases use a `public_delivery=true` type so anonymous delivery passes the access middleware when going through `handle()` (direct `show()` calls need no API key). The access case (`testPrivateTypeDeniesAnonymousRedirectAndGone`) deliberately uses a `public_delivery=false` type via `handle()` to prove the resolver does not bypass `DeliveryAccessMiddleware`. Cases:
```php
// testContentResponseCarriesSeoBlock: published en entry at 'hello' (+ fr alternate) ->
//   show() 200, body data has 'seo' => {canonical, alternates, x_default} with relative paths.
// testRedirectResponseIs200WithDescriptorNoContent: redirect 'old' -> entry (live at 'new') ->
//   show('old') 200, body == {redirect:{to:'/en/post/new', status:301, external:false,
//   target:{content_type:'post',locale:'en',slug:'new'}}}, and NO 'fields'/content envelope.
// testGoneResponseIs404: redirect 'old' -> unpublished entry -> show('old') 404.
// testNotFoundFallsBackToUuidThen404: unknown slug that is NOT a nanoid -> 404; a published
//   entry's 12-char uuid -> 200 content (existing nanoid->uuid fallback preserved).
// testLiveLocaleFallbackStillWorks (P2 pair): entry published only in 'en' at 'hello';
//   request locale 'fr' (chain [fr,en]) with NO fr redirect -> 200 en CONTENT (live fallback),
//   proving redirects-are-requested-locale-only does NOT break live content fallback.
// testRedirectCarriesDerivedEtagShortTtlAndTargetCacheTag: redirect response has an ETag,
//   Cache-Control max-age == lemma.seo.redirect_ttl (60), and Cache-Tag includes
//   'lemma:entry:{targetEntryUuid}'.
// testChangingTargetSlugChangesRedirectEtag (P2 busting): capture the redirect ETag; rename
//   the target's live slug 'new'->'newer'; the SAME redirect request now yields a DIFFERENT
//   ETag and 'to' == '/en/post/newer'.
// testExternalRedirectTagsOnlySource: external redirect response Cache-Tag has the source
//   type tag but no 'lemma:entry:' target tag.
// testPrivateTypeDeniesAnonymousRedirectAndGone (P2 access): drive the FULL kernel via
//   handle() (NOT direct show()) for a type with public_delivery=FALSE. An anonymous request
//   to a moved slug (would be a Redirect) AND to a Gone slug both return 401/403 from
//   DeliveryAccessMiddleware — the resolver must NOT create a public side channel that leaks
//   old paths / target identity. The SAME requests succeed (200 redirect descriptor / 404
//   Gone) only with a valid API key. (Use a published+routed private type for the Redirect
//   target so the only thing under test is the access gate.)
```

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter DeliverySeoTest` → FAIL (no `seo` block / redirect descriptor / Gone 404 yet).

- [ ] **Step 3: Implement the delivery integration.** In `DeliveryController`:

(a) Inject `RouteResolver` + `CanonicalProjector` (extend the constructor — append two `private readonly` params after `$locales`; autowire fills them since the provider registers `DeliveryController` with `autowire => true`):
```php
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\ResolutionResult;
use App\Content\Seo\RouteResolver;
// ... constructor: add after LocaleManagerInterface $locales:
        private readonly RouteResolver $resolver,
        private readonly CanonicalProjector $canonical,
```

(b) Replace the resolution block at the top of `show()` (the `findPublishedByRoute` + nanoid fallback section, lines ~264–296) with a resolver-driven branch. The `Content` case keeps the existing shape/ETag path but adds the `seo` block + alternate cache tags; the other cases are new:
```php
public function show(Request $request, string $type, string $slugOrUuid): Response
{
    $typeRow = $this->types->findBySlug($type);
    if ($typeRow === null) {
        return Response::notFound('Content type not found.');
    }
    $schema = ContentTypeSchema::fromArray($typeRow['schema']);
    $typeUuid = (string) $typeRow['uuid'];
    $locales = $this->localeChain($request);
    $requested = $this->locale($request);

    $result = $this->resolver->resolve($typeUuid, $locales, $requested, $slugOrUuid);

    if ($result->isRedirect()) {
        return $this->redirectResponse($request, $result, (string) $typeRow['slug']);
    }
    if ($result->isGone()) {
        return Response::notFound('Content not found.');
    }

    $row = $result->isContent() ? $result->entry : null;
    // NotFound: preserve the existing nanoid -> uuid fallback, then 404.
    if ($row === null && $this->looksLikeNanoid($slugOrUuid)) {
        $row = $this->findPublishedByUuid($typeUuid, $locales, $slugOrUuid);
    }
    if ($row === null) {
        return Response::notFound('Content not found.');
    }

    $selector = FieldSelector::fromRequest($request);
    $shaped = $this->shape([$row], $schema, $selector, (string) $row['locale']);
    $item = $this->item($shaped[0]);

    // SEO block (relative paths + identity). Additive to the existing item envelope.
    $seo = $this->canonical->for((string) $row['entry_uuid'], $typeUuid, (string) $row['locale']);
    $item['seo'] = $seo;

    // Cache tag set also includes the hreflang-alternate entry uuids so a sibling-locale
    // publish/unpublish refreshes the seo block. (Alternates are same-entry locales, so the
    // entry tag already covers them; include any cross-entry identities defensively.)
    $entryTags = [(string) $row['entry_uuid']];

    $etag = $this->etags->forItem((string) $row['version_uuid'], $this->selectionKey($request));
    $cacheTag = $this->etags->cacheTag($entryTags, $type);
    if ($this->etags->matches($request, $etag)) {
        return $this->etags->notModified($etag, $this->ttl($typeRow), $cacheTag);
    }
    $response = Response::success($item, 'Content retrieved.');
    return $this->etags->applyHeaders($response, $etag, $this->ttl($typeRow), $cacheTag);
}
```

(c) Add the redirect responder + its derived ETag/cache contract:
```php
/**
 * Build the 200 redirect descriptor response. The body is ONLY {redirect:{to,status,
 * external,target}} (no content — clients must not auto-follow; the frontend emits the
 * real browser 30x). Cached briefly (lemma.seo.redirect_ttl) with a derived ETag and a
 * Cache-Tag on the TARGET entry (internal) so a target slug change / unpublish busts it.
 */
private function redirectResponse(Request $request, ResolutionResult $result, string $sourceTypeSlug): Response
{
    $body = ['redirect' => [
        'to' => $result->to,
        'status' => $result->status,
        'external' => $result->external,
        'target' => $result->target,
    ]];

    // Derived ETag: redirect identity + resolved target state (changes when the target slug
    // changes, the target unpublishes, or the row is edited).
    $etag = '"' . sha1(implode('|', [
        (string) $result->redirectUuid,
        (string) $result->status,
        (string) $result->to,
        $result->external ? 'ext' : 'int',
    ])) . '"';

    // Internal target: tag the TARGET entry (route assignment / publish / unpublish already
    // bust this tag, so a target slug change / unpublish invalidates this descriptor).
    // External: tag only the source type. `ResolutionResult::$targetEntryUuid` is set by the
    // resolver for internal targets (see Step 3 / Task 4 note below).
    $targetEntryUuids = [];
    if (!$result->external && $result->targetEntryUuid !== null) {
        $targetEntryUuids[] = $result->targetEntryUuid;
    }
    $ttl = (int) config($this->context, 'lemma.seo.redirect_ttl', 60);
    $cacheTag = $this->etags->cacheTag($targetEntryUuids, $sourceTypeSlug);

    if ($this->etags->matches($request, $etag)) {
        return $this->etags->notModified($etag, $ttl, $cacheTag);
    }
    $response = Response::success($body, 'Redirect.');
    return $this->etags->applyHeaders($response, $etag, $ttl, $cacheTag);
}
```
> **Required `ResolutionResult` + `RouteResolver` change (load-bearing for the cache tag above):** `ResolutionResult::redirect(...)` must carry a `?string $targetEntryUuid` field (the target entry uuid for internal targets, `null` for external). In Task 4, `RouteResolver`'s internal-target branch passes the resolved `$targetEntry` uuid into `ResolutionResult::redirect(...)`; the external branch passes `null`. Do this in Step 3 (`ResolutionResult`) + Task 4 (`RouteResolver`). The snippet above already reads `$result->targetEntryUuid` directly — no separate tweak to carry forward.

- [ ] **Step 4: Run; verify pass.** `composer test:phpunit -- --filter DeliverySeoTest` → PASS (all mapping + caching + locale-fallback cases). Also re-run the existing delivery suite to confirm no regression: `composer test:phpunit -- --filter DeliveryApiTest`.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Http/Controllers/DeliveryController.php app/Content/Seo/ResolutionResult.php app/Content/Seo/RouteResolver.php tests/Integration/Http/DeliverySeoTest.php
git commit -m "Drive delivery show() through RouteResolver: seo block, redirect descriptor, Gone 404, redirect caching"
```

---

### Task 8: Admin API — `CreateRedirectData` DTO + `RedirectController` + routes

**Files:**
- Create: `app/Content/Http/DTOs/CreateRedirectData.php`, `app/Content/Http/Controllers/RedirectController.php`
- Modify: `routes/lemma_admin.php`, `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Http/RedirectApiTest.php`

- [ ] **Step 1: Write failing tests.** New `tests/Integration/Http/RedirectApiTest.php` (resolve the controller from the container, hydrate the DTO with `RequestDataHydrator` like `ContentTypeApiTest`). Cases:
```php
// testCreateInternalSameTypeLocaleDefault: POST {locale:'en', source_slug:'old',
//   target:{entry_uuid:<published entry>}, status:301} on type 'post' -> 201; redirect row
//   has target triple = source type/locale.
// testCreateCrossTypeCrossLocale (P1/decision 8): target:{entry_uuid, content_type:'docs',
//   locale:'fr'} -> 201; row stores the docs/fr triple.
// testCreateExternal: target:{url:'https://x'}, status:308 -> 201; row has target_url, null triple.
// testCreateRejectsSourceCollidingWithLiveRoute: a live route exists at 'taken' -> POST
//   source_slug 'taken' -> 409.
// testCreateRejectsBadStatus: status 307 -> 422.
// testCreateRejectsInvalidTarget: neither a valid internal triple nor a url -> 422.
// testCreateRejectsTargetWithNoRouteForTypeLocale (P1): target entry exists but has NO route
//   in the declared {content_type, locale} (nonexistent, or wrong type/locale) -> 422.
// testCreateAllowsUnpublishedRoutedTargetAsBroken (P1): target entry IS routed in the declared
//   {type, locale} but is NOT published -> 201 (allowed); GET lists it target_state:'broken'.
//   (Broken targets stay visible in admin; public delivery 404s — spec.)
// URL safety (P2) — testCreateRejectsUnsafeUrl with a data provider:
//   'javascript:alert(1)' -> 422; '//evil.example' (protocol-relative) -> 422; '' / '   '
//   (empty/whitespace) -> 422; "https://x/\x01" (control char) -> 422; 'ftp://x' -> 422.
//   testCreateAcceptsSafeUrl: 'https://ok.example/p' -> 201; 'http://ok.example' -> 201;
//   '/site-relative/path' -> 201.
// testListShowsAutoAndManualWithTargetState: an auto redirect (live target) + a manual one
//   to an unpublished-but-routed entry -> GET lists both; the live one target_state:'live',
//   the broken one target_state:'broken'; external target_state:'live'.
// testTargetStateChecksFullTriple (P2 cross-type): directly seed (via RedirectRepository) a
//   redirect whose target triple is {entry E, type 'post', locale 'fr'} where E is
//   published+routed ONLY under a DIFFERENT type 'docs' in 'fr' (no route under post/fr) ->
//   GET lists it target_state:'broken' (a wrong-type route must NOT make it live).
// testListFilterByLocale: ?locale=fr returns only fr redirects.
// testDelete: DELETE /v1/admin/redirects/{uuid} -> 200 and the row is gone.
// testDeleteUnknownReturns404.
```

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter RedirectApiTest` → FAIL (classes/routes missing).

- [ ] **Step 3: Create `CreateRedirectData`** (structural rules only; the target shape + collision + entry validity are domain checks in the controller):
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for POST /v1/admin/content-types/{slug}/redirects. `target` is a free-form
 * array — exactly one of {entry_uuid (+ optional content_type/locale)} or {url} — validated
 * in the controller (the built-in rules cannot express the XOR + entry-existence check).
 */
final class CreateRedirectData implements RequestData
{
    /**
     * @param array<string,mixed> $target
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $locale = '',
        #[Rule('required|string')]
        public readonly string $source_slug = '',
        #[Rule('required|integer')]
        public readonly int $status = 301,
        public readonly array $target = [],
    ) {
    }
}
```

- [ ] **Step 4: Create `RedirectController`:**
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Http\DTOs\CreateRedirectData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\RedirectRepository;
use App\Http\DTOs\ErrorResponse;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin API for redirects (perm lemma.routes.manage). Create a manual redirect (internal —
 * same- or cross-type/locale — or external/literal), list auto+manual with a computed
 * target_state (live/broken), and delete. Source content type comes from the {slug} path.
 */
final class RedirectController
{
    private const VALID_STATUS = [301, 302, 308];

    public function __construct(
        private readonly RedirectRepository $redirects,
        private readonly ContentTypeRepository $types,
        private readonly RouteRepository $routes,
        private readonly DeliveryRepository $delivery,
        private readonly ContentLocaleService $locales,
    ) {
    }

    #[ApiOperation(summary: 'Create a redirect', tags: ['Lemma Admin'])]
    #[ApiResponse(201, description: 'Redirect created.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown source content type.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Source collides with a live route.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Bad status, locale, or invalid target.')]
    public function store(CreateRedirectData $input, Request $request, string $slug): Response
    {
        $typeRow = $this->types->findBySlug($slug);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $sourceTypeUuid = (string) $typeRow['uuid'];

        if (($errors = $this->locales->validate($input->locale)) !== []) {
            return Response::validation($errors);
        }
        if (!in_array($input->status, self::VALID_STATUS, true)) {
            return Response::validation(['status' => 'must be one of: 301, 302, 308']);
        }
        // Source cannot collide with a live route (live wins).
        if ($this->routes->findBySlug($sourceTypeUuid, $input->locale, $input->source_slug) !== null) {
            return Response::error(
                'Source slug is a live route.',
                Response::HTTP_CONFLICT,
                ['code' => 'ROUTE_TAKEN']
            );
        }

        $target = $input->target;
        $hasEntry = isset($target['entry_uuid']) && is_string($target['entry_uuid']) && $target['entry_uuid'] !== '';
        $hasUrl = isset($target['url']) && is_string($target['url']) && $target['url'] !== '';
        if ($hasEntry === $hasUrl) {
            // neither, or both
            return Response::validation(['target' => 'provide exactly one of {entry_uuid} or {url}']);
        }

        $row = ['content_type_uuid' => $sourceTypeUuid, 'locale' => $input->locale,
            'source_slug' => $input->source_slug, 'status' => $input->status,
            'origin' => 'manual', 'created_by' => $this->actor($request)];

        if ($hasUrl) {
            // URL safety: this value is handed to frontends to emit a real browser redirect,
            // so only safe destination forms are accepted. Allow absolute http(s) and a
            // site-relative '/path'; reject empty/whitespace, control chars, protocol-relative
            // '//host', and dangerous schemes (javascript:, data:, etc.).
            $url = trim((string) $target['url']);
            if (!$this->isSafeRedirectUrl($url)) {
                return Response::validation([
                    'target' => 'unsafe or unsupported redirect url; use https://, http://, or a site-relative /path',
                ]);
            }
            $row += ['target_url' => $url];
        } else {
            // Internal: content_type/locale default to the SOURCE's; may cross type/locale.
            $targetTypeSlug = isset($target['content_type']) && is_string($target['content_type']) && $target['content_type'] !== ''
                ? $target['content_type'] : $slug;
            $targetLocale = isset($target['locale']) && is_string($target['locale']) && $target['locale'] !== ''
                ? $target['locale'] : $input->locale;
            $targetTypeRow = $this->types->findBySlug($targetTypeSlug);
            if ($targetTypeRow === null) {
                return Response::validation(['target' => "unknown target content type '{$targetTypeSlug}'"]);
            }
            $targetTypeUuid = (string) $targetTypeRow['uuid'];
            // Validate the target identity, NOT its publish state. The target must have a route
            // in the declared {type, locale} triple (that route's slug is what the redirect
            // resolves to). A nonexistent entry, or one with no route under the declared
            // type/locale, is rejected (422). An entry that IS routed there but is currently
            // unpublished is ALLOWED — it lists as target_state:'broken' and public delivery
            // 404s until it goes live (spec: broken targets stay visible in admin). Publish
            // state is therefore NOT checked here.
            $hasRoute = false;
            foreach ($this->routes->forEntry((string) $target['entry_uuid']) as $r) {
                if ((string) $r['content_type_uuid'] === $targetTypeUuid && (string) $r['locale'] === $targetLocale) {
                    $hasRoute = true;
                    break;
                }
            }
            if (!$hasRoute) {
                return Response::validation(['target' => 'target entry has no route for that content type/locale']);
            }
            $row += [
                'target_entry_uuid' => (string) $target['entry_uuid'],
                'target_content_type_uuid' => $targetTypeUuid,
                'target_locale' => $targetLocale,
            ];
        }

        $created = $this->redirects->create($row);
        return Response::created(['redirect' => $created], 'Redirect created.');
    }

    #[ApiOperation(summary: 'List redirects for a content type', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Redirects (auto + manual) with target_state.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown source content type.')]
    public function index(Request $request, string $slug): Response
    {
        $typeRow = $this->types->findBySlug($slug);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $locale = $request->query->get('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : null;

        $rows = $this->redirects->forType((string) $typeRow['uuid'], $locale);
        foreach ($rows as $i => $row) {
            $rows[$i]['target_state'] = $this->targetState($row);
        }
        return Response::success(['redirects' => $rows], 'Redirects retrieved.');
    }

    #[ApiOperation(summary: 'Delete a redirect', tags: ['Lemma Admin'])]
    #[ApiResponse(200, description: 'Redirect deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown redirect.')]
    public function destroy(Request $request, string $uuid): Response
    {
        return $this->redirects->delete($uuid)
            ? Response::success([], 'Redirect deleted.')
            : Response::notFound('Redirect not found.');
    }

    /**
     * Computed visibility for admin: 'live' when the target resolves to a current slug (or is
     * external), 'broken' when an internal target has no current live route. This surfaces a
     * Gone-resolving redirect in admin even though public delivery returns 404 for it.
     *
     * @param array<string,mixed> $row
     */
    private function targetState(array $row): string
    {
        if (($row['target_url'] ?? null) !== null && $row['target_url'] !== '') {
            return 'live';
        }
        $type = (string) $row['target_content_type_uuid'];
        $locale = (string) $row['target_locale'];
        $entryUuid = (string) $row['target_entry_uuid'];
        // 'live' mirrors what the resolver returns Redirect for: the target is PUBLISHED in
        // that locale AND has a route in its FULL {content_type, locale} triple. Cross-type
        // targets must match the target content type — a route for the same entry+locale under
        // a DIFFERENT type does NOT make this redirect live. Otherwise 'broken'
        // (unpublished/deleted/no route) — visible in admin while public delivery 404s.
        if ($this->delivery->findPublishedByUuid($type, $locale, $entryUuid) === null) {
            return 'broken';
        }
        foreach ($this->routes->forEntry($entryUuid) as $r) {
            if ((string) $r['content_type_uuid'] === $type && (string) $r['locale'] === $locale) {
                return 'live';
            }
        }
        return 'broken';
    }

    /**
     * Allowed redirect destination forms (the descriptor is emitted by frontends as a real
     * browser redirect, so the contract is strict): absolute http(s) URLs, or a site-relative
     * '/path'. Rejects empty/whitespace, ASCII control chars, protocol-relative '//host', and
     * any non-http(s) scheme (javascript:, data:, file:, …).
     */
    private function isSafeRedirectUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        // Site-relative path: exactly one leading slash (NOT protocol-relative '//').
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        // Absolute: http(s) scheme only.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
```

- [ ] **Step 5: Register controller + routes.** In `LemmaServiceProvider::services()` (add `use App\Content\Http\Controllers\RedirectController;`):
```php
RedirectController::class => ['class' => RedirectController::class, 'shared' => true, 'autowire' => true],
```
In `routes/lemma_admin.php` (inside the `/v1/admin` `auth` group; add the `RedirectController` import):
```php
    // Redirects (SEO/routing module). Site-wide/external redirect power -> lemma.routes.manage.
    $router->post('/content-types/{slug}/redirects', [RedirectController::class, 'store'])
        ->middleware('lemma_permission:lemma.routes.manage');
    $router->get('/content-types/{slug}/redirects', [RedirectController::class, 'index'])
        ->middleware('lemma_permission:lemma.routes.manage');
    $router->delete('/redirects/{uuid}', [RedirectController::class, 'destroy'])
        ->middleware('lemma_permission:lemma.routes.manage');
```

- [ ] **Step 6: Run; verify pass.** `composer test:phpunit -- --filter RedirectApiTest` → PASS.

- [ ] **Step 7: phpcs + commit.**
```bash
composer phpcs
git add app/Content/Http/DTOs/CreateRedirectData.php app/Content/Http/Controllers/RedirectController.php routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Http/RedirectApiTest.php
git commit -m "Add redirect admin API (create internal/external, list with target_state, delete)"
```

---

### Task 9: Permission seed + route-manifest registration + broken-target visibility

**Files:**
- Modify: `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php`
- Test: `tests/Integration/Seo/RoutesAndPermissionSeedTest.php`

The harness cannot mint a real lemma_admin/lemma_editor JWT (no user/role/JWT fixtures — see `FoundationFlowTest`'s note), so the "admin allowed / editor 403" requirement is met structurally: assert the three redirect routes are registered in the manifest with the `lemma_permission:lemma.routes.manage` middleware (so an editor without that permission is denied by the same `PermissionManager::can()` path every other gated route uses), and assert the seed migration grants `lemma.routes.manage` to `lemma_admin` only (not editor/viewer). The editor-route-change-still-auto-captures property is already proven by `AutoCaptureTest` (route assign needs only `lemma.entries.write`, and the hook fires there).

- [ ] **Step 1: Write failing tests.** New `tests/Integration/Seo/RoutesAndPermissionSeedTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\LemmaTestCase;

final class RoutesAndPermissionSeedTest extends LemmaTestCase
{
    /** @return list<array{0:string,1:string}> method, path */
    public static function redirectRoutes(): array
    {
        return [
            ['POST', '/v1/admin/content-types/{slug}/redirects'],
            ['GET', '/v1/admin/content-types/{slug}/redirects'],
            ['DELETE', '/v1/admin/redirects/{uuid}'],
        ];
    }

    /** @dataProvider redirectRoutes */
    public function testRedirectRouteRegisteredWithManagePermission(string $method, string $path): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "{$method} {$path} not registered in the route manifest");
        $middleware = array_map('strval', (array) ($route['middleware'] ?? []));
        self::assertContains('lemma_permission:lemma.routes.manage', $middleware);
    }

    public function testManagePermissionSeededToAdminOnly(): void
    {
        $db = $this->connection();
        $perm = $db->table('permissions')->where('slug', '=', 'lemma.routes.manage')->first();
        self::assertNotNull($perm, 'lemma.routes.manage permission must be seeded');

        $adminRole = $db->table('roles')->where('slug', '=', 'lemma_admin')->first();
        $editorRole = $db->table('roles')->where('slug', '=', 'lemma_editor')->first();

        $grant = static fn (string $roleUuid): ?array => $db->table('role_permissions')
            ->where('role_uuid', '=', $roleUuid)
            ->where('permission_uuid', '=', $perm['uuid'])
            ->first() ?: null;

        self::assertNotNull($grant((string) $adminRole['uuid']), 'lemma_admin must hold lemma.routes.manage');
        self::assertNull($grant((string) $editorRole['uuid']), 'lemma_editor must NOT hold lemma.routes.manage');
    }
}
```
> The seed dependent-migration runs during `composer test:migrate` (registered at `MigrationPriority::DEPENDENT` by `LemmaServiceProvider::boot()`), so the `permissions`/`role_permissions` rows are present in the test DB. The route assertions read the same booted router `findRoute()` already used by `FoundationFlowTest`.

- [ ] **Step 2: Run; verify fail.** `composer test:phpunit -- --filter RoutesAndPermissionSeedTest` → FAIL (routes pass once Task 8 landed, but the permission seed is missing → `testManagePermissionSeededToAdminOnly` fails).

- [ ] **Step 3: Add the permission to the seed migration.** In `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php`:

Add to `PERMISSIONS`:
```php
    private const PERMISSIONS = [
        'lemma.models.manage' => 'Manage content models',
        'lemma.entries.write' => 'Create and edit entries',
        'lemma.entries.publish' => 'Publish and unpublish entries',
        'lemma.entries.read' => 'Read entries (admin)',
        'lemma.routes.manage' => 'Manage redirects (routing/SEO)',
    ];
```
Grant it to `lemma_admin` only (append to the admin grant list; leave editor/viewer unchanged):
```php
    private const ROLES = [
        'lemma_admin' => ['Lemma Admin', 80, [
            'lemma.models.manage', 'lemma.entries.write', 'lemma.entries.publish',
            'lemma.entries.read', 'lemma.routes.manage',
        ]],
        'lemma_editor' => ['Lemma Editor', 50, [
            'lemma.entries.write', 'lemma.entries.publish', 'lemma.entries.read',
        ]],
        'lemma_viewer' => ['Lemma Viewer', 20, ['lemma.entries.read']],
    ];
```
The migration's `up()` is idempotent (inserts only missing permissions / role_permission pairs), so re-running over an existing DB adds the new permission + the one new admin grant without disturbing existing rows. A fresh `composer test:reset-db && composer test:migrate` seeds it cleanly.

- [ ] **Step 4: Run; verify pass.** `composer test:reset-db && composer test:migrate && composer test:phpunit -- --filter RoutesAndPermissionSeedTest` → PASS.

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php tests/Integration/Seo/RoutesAndPermissionSeedTest.php
git commit -m "Seed lemma.routes.manage permission to lemma_admin and assert redirect routes"
```

---

### Task 10: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Full reset + migrate + suite + static checks.**

Run: `composer test:reset-db && composer test:migrate && composer ci`
Expected: green — the prior suite total plus all new SEO tests (`RedirectRepositoryTest`, `PathRendererTest`, `RouteResolverTest`, `AutoCaptureTest`, `CanonicalProjectorTest`, `DeliverySeoTest`, `RedirectApiTest`, `RoutesAndPermissionSeedTest`) passing; phpcs clean; the existing `DeliveryApiTest`/`RouteRepositoryTest` still green (no regression).

- [ ] **Step 2: Confirm composer validity.**

Run: `composer validate --strict`
Expected: valid.

- [ ] **Step 3: Final commit (only if Step 1/2 surfaced fixes; otherwise skip).**
```bash
composer phpcs
git add -A
git commit -m "Finalize SEO/routing module: full-suite green"
```

---

## Self-review notes

- **Spec coverage map.** Resolver precedence (Content/Redirect/Gone/NotFound) → Task 4 `RouteResolverTest`. Cross-type/locale internal target (P1) → Task 4 + Task 8. Locale-fallback rule (P2: fr-miss + only-en redirect → not used; live content fallback still works) → Task 4 `testRedirectsAreRequestedLocaleOnly` + Task 7 `testLiveLocaleFallbackStillWorks`. URL contract (relative + identity + public_url_base + x_default omits locale) → Task 3 `PathRendererTest` + Task 6 identity assertions. Single-hop → Task 4 `testSingleHopNoChainFollowing`. Auto-capture (A→B 301; B→C leaves A&B; new live slug clears; old==new no-op) → Task 5 `AutoCaptureTest`. Canonical/hreflang → Task 6. Delivery mapping (Content 200+seo / Redirect 200 no content / Gone 404 / NotFound→uuid→404) → Task 7 `DeliverySeoTest`. Redirect caching/ETag busting → Task 7 `testRedirectCarriesDerivedEtag…` + `testChangingTargetSlugChangesRedirectEtag` + `testExternalRedirectTagsOnlySource`. Admin API (internal same/cross + external; list target_state live/broken; delete; 409 collision; 422 bad status/invalid target) → Task 8 `RedirectApiTest`. Routes+permission seed (routes in manifest; admin granted, editor not; editor route change still auto-captures) → Task 9 `RoutesAndPermissionSeedTest` + Task 5. Broken-target visibility (lists broken while delivery 404s) → Task 8 `testListShowsAutoAndManualWithTargetState` + Task 7 `testGoneResponseIs404`.
- **Review-pass fixes (covered).** P1 broken-target contract: CREATE validates the target has a route in its full `{type, locale}` triple (rejects nonexistent/wrong-type/locale → 422) but **allows** an unpublished-but-routed target (lists `broken`) — Task 8 `testCreateRejectsTargetWithNoRouteForTypeLocale` + `testCreateAllowsUnpublishedRoutedTargetAsBroken`. P2 external-URL safety: `isSafeRedirectUrl` (http(s) or site-relative `/path`; rejects empty/whitespace/control-chars/`//host`/non-http schemes) — Task 8 `testCreateRejectsUnsafeUrl` (data provider) + `testCreateAcceptsSafeUrl`. P2 cross-type `target_state`: `targetState` requires published **and** a route matching the full triple (content_type + locale) — Task 8 `testTargetStateChecksFullTriple`. P2 delivery access: Task 7 `testPrivateTypeDeniesAnonymousRedirectAndGone` (private type via `handle()` → redirect & Gone both gated by `DeliveryAccessMiddleware`; succeed only with an API key).
- **No full authenticated round-trip for admin RBAC.** The harness has no user/role/JWT fixtures (documented in `FoundationFlowTest`). The "lemma_admin allowed / lemma_editor 403" requirement is therefore met structurally: every redirect route carries `lemma_permission:lemma.routes.manage` (asserted in the manifest), which routes through the exact `PermissionManager::can()` gate all other admin routes use, and the seed grants the permission to admin only (asserted against the DB). Behaviour is tested directly on the controller. This is the established Lemma pattern, not a gap.
- **Signature consistency.** `RouteRepository::assign($entryUuid,$contentTypeUuid,$locale,$slug)` is unchanged in signature; only its body and constructor (now `(Connection, RedirectRepository)`) change — `EntryController::assignRoute` call site is unaffected and the provider already autowires it. `RedirectRepository::upsertAuto(...)` arg order matches the `assign` hook call. `RouteResolver::resolve($typeUuid,$localeChain,$requestedLocale,$path)` matches the spec §2 signature and the `DeliveryController::show` call. `ResolutionResult::redirect()` gains a `?string $targetEntryUuid` arg (Task 7 note) — Task 4's resolver passes it; both edits are committed together where they overlap (Task 7 explicitly re-touches `ResolutionResult`/`RouteResolver`). `CanonicalProjector::for($entryUuid,$contentTypeUuid,$locale)` matches the spec and the delivery call. `ContentTypeRepository::findByUuid` / `findBySlug`, `DeliveryRepository::findPublishedByRoute`/`findPublishedByUuid`, `RouteRepository::forEntry`/`findBySlug`, `ContentLocaleService::validate`/`default`, `DeliveryEtag::forItem`/`cacheTag`/`applyHeaders`/`matches`/`notModified`, `Utils::generateNanoID` are all confirmed against the read source.
- **Placeholder scan.** No `TBD`/"similar to Task N"/elided code: every code step is complete PHP. The one deliberate forward-reference (the target-entry cache tag) is fully specified inline in Task 7's note with the exact `ResolutionResult`/`RouteResolver` change.
- **Migration numbering.** `010` is the next number (current max on disk is `009_AddFilterIndexRegistry`; `008` is absent and `010` is free). If the scheduled-publish plan lands its own `010` first, renumber this to `011`.
- **Postgres-only** is intended (CHECK constraints, `now()`), consistent with the rest of Lemma; `LemmaTestCase` runs on Postgres.
