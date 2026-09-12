# Package split — Phase 3 (publish the split) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `composer create-project glueful/thallo` installs a thin skeleton whose `vendor/` holds `glueful/thallo-core` and the 13 packs, so `composer update && php glueful thallo:provision` upgrades Thallo — with every existing database's migration ledger still matching.

**Architecture:** The dev repo stays one monorepo. `core/` becomes the `glueful/thallo-core` Composer package (a library with a Glueful manifest: provider + migration descriptors), the 13 `packages/thallo-*` directories become published packages of the same version, and a new `skeleton/` directory is exactly what `create-project` ships. A framework feature (`legacy_sources` on migration descriptors, 1.85.0) lets thallo-core's lanes adopt the rows every database recorded under `app` / `app:dependent`. One release script subtree-splits the 15 package directories to read-only mirror repositories and tags them together; the dist gate runs per artifact; a skeleton smoke installs the skeleton against the local packages.

**Tech Stack:** PHP 8.4, Glueful framework 1.85 (`MigrationDescriptor`, `PackageManifest`, `MigrationManager`), Composer path repositories + `self.version`, `git subtree split`, PHPUnit 10, bash, GitHub Actions.

**Spec:** `docs/internal/plans/2026-09-12-composer-updatable-thallo.md` (phase 3), `docs/internal/DISTRIBUTION.md` decisions 7 (amended here), 8, 9, 10.

## Global Constraints

- Branch `package-split-3` off `dev`; merge `--no-ff` at the end. `dev` stays releasable.
- Gates before every commit: `composer phpcs`, `composer boundaries`, the task's suites; the FULL suite (`COMPOSER_PROCESS_TIMEOUT=0 composer test`, alone on the test DB) at Tasks 2, 3 and 6; `composer test:distribution` and the new `scripts/skeleton-smoke` at Tasks 4 and 6; admin gates at Task 6.
- **Ledger names never change for the operator.** After this phase a beta.20 database shows zero pending migrations; the mechanism is `legacy_sources`, never a manual ledger rewrite.
- **Packs keep their package names** (`glueful/thallo-<name>`), their ledger sources and their manifests; they are published, not folded into core (charter decision 7 amended: the consumer that forces publication is Thallo's own core in `vendor/`).
- One version for everything: core, skeleton and packs are tagged `vX.Y.Z-beta.N` together; core requires each pack at `self.version`; the skeleton pins `glueful/thallo-core` at `^X.Y.Z-beta.N` and the release script bumps it.
- No `version` field in any published `composer.json` (Packagist derives versions from tags; the dev root resolves path packages as `dev-*` under `minimum-stability: dev` + `prefer-stable`).
- Never push, never tag from the assistant's side; the release script prints the push commands unless `--push` is given and is run by the user.
- Human prerequisites, done by the user before Task 5 can be exercised end to end: 15 GitHub repositories (`glueful/thallo-core`, `glueful/thallo-skeleton`, `glueful/thallo-<pack>` ×13), Packagist entries for each, and Packagist's `glueful/thallo` repointed from the dev repo to `glueful/thallo-skeleton`. Local remotes named `split/<name>` for each.

---

### Task 0: Branch, baseline, prerequisites check

- [ ] **Step 1: Branch**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/thallo
git status --short | wc -l            # expect 0
git checkout dev && git checkout -b package-split-3
```

- [ ] **Step 2: Record the ledger baseline on the dev database (must be identical at the end)**

```bash
php glueful migrate:status | grep -E "Total|Completed|Pending" > /tmp/ledger-before.txt; cat /tmp/ledger-before.txt
```

Expected: `Pending: 0`.

---

### Task 1: Framework 1.85.0 — `legacy_sources` on migration descriptors

**Repo:** `/Users/michaeltawiahsowah/Sites/glueful/framework` (release per `.claude/skills/release/SKILL.md`, re-read it; codename after Alnitak is **Alphard**; minor: new manifest key).

**Files:**
- Modify: `src/Extensions/Schema/MigrationDescriptor.php` (new `legacySources` field), `src/Extensions/PackageManifest.php` (parse `legacy_sources`), `src/Database/Migrations/MigrationManager.php` (`addMigrationPath(..., array $legacySources = [])`, alias-aware applied check, adoption), `src/Extensions/Schema/MigrationManagerFactory.php` if it maps descriptors → paths.
- Test: `tests/Unit/Database/Migrations/LegacySourcesTest.php`, `tests/Unit/Extensions/PackageManifestLegacySourcesTest.php`.

**Interfaces:**
- Produces: manifest row key `legacy_sources: list<string>`; `MigrationDescriptor::$legacySources`; `MigrationManager::addMigrationPath(string $path, int $priority, ?string $source = null, array $legacySources = [])`; a lane's pending computation treats a row recorded under any legacy source (same file basename) as applied; `migrate:run` first rewrites such rows to the lane's current source (adoption), so `migrate:verify`/`status` report the lane whole.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Database/Migrations/LegacySourcesTest.php
declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use PHPUnit\Framework\TestCase;

/**
 * A package that takes over migrations previously recorded under another source (an app that
 * became a package; a package that renamed) declares the old names as legacy_sources: rows the
 * ledger holds under a legacy source count as applied for the lane, and the first run adopts
 * them under the current name — so nothing re-runs and nothing looks pending.
 */
final class LegacySourcesTest extends TestCase
{
    use SqliteMigrationHarness; // the harness other MigrationManager tests use: a temp sqlite DB + temp dirs

    public function testRowsUnderALegacySourceCountAsAppliedForTheLane(): void
    {
        $dir = $this->migrationDir(['001_CreateThings.php']);
        $this->recordApplied('app', '001_CreateThings.php');             // the pre-split ledger row
        $manager = $this->manager();
        $manager->addMigrationPath($dir, MigrationPriority::DEFAULT, 'glueful/thing-core', ['app']);

        self::assertSame([], $manager->pendingForSources(['glueful/thing-core']));
        self::assertSame([], $manager->getPendingMigrations());
    }

    public function testWithoutTheAliasTheSameRowLooksPending(): void
    {
        $dir = $this->migrationDir(['001_CreateThings.php']);
        $this->recordApplied('app', '001_CreateThings.php');
        $manager = $this->manager();
        $manager->addMigrationPath($dir, MigrationPriority::DEFAULT, 'glueful/thing-core');

        self::assertCount(1, $manager->pendingForSources(['glueful/thing-core']));
    }

    public function testARunAdoptsLegacyRowsUnderTheCurrentSource(): void
    {
        $dir = $this->migrationDir(['001_CreateThings.php']);
        $this->recordApplied('app:dependent', '001_CreateThings.php');
        $manager = $this->manager();
        $manager->addMigrationPath($dir, MigrationPriority::DEPENDENT, 'glueful/thing-core:dependent', ['app:dependent']);

        $manager->migrateSources(['glueful/thing-core:dependent']);

        self::assertSame(['glueful/thing-core:dependent'], $this->sourcesRecordedFor('001_CreateThings.php'));
    }
}
```

```php
<?php
// tests/Unit/Extensions/PackageManifestLegacySourcesTest.php
declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\PackageManifest;
use PHPUnit\Framework\TestCase;

final class PackageManifestLegacySourcesTest extends TestCase
{
    public function testLegacySourcesAreParsedAndValidated(): void
    {
        $descriptor = $this->descriptorsFor([
            'name' => 'glueful/thing-core', 'type' => 'library',
            'extra' => ['glueful' => ['migrations' => [[
                'id' => 'default', 'path' => 'database/migrations', 'priority' => 'default', 'mode' => 'core',
                'legacy_sources' => ['app'],
            ]]]],
        ])['glueful/thing-core'][0];

        self::assertSame(['app'], $descriptor->legacySources);
    }

    public function testAnInvalidLegacySourceIsRejected(): void
    {
        $this->expectException(\Glueful\Extensions\Schema\DescriptorValidationException::class);
        $this->descriptorsFor([
            'name' => 'glueful/thing-core', 'type' => 'library',
            'extra' => ['glueful' => ['migrations' => [[
                'id' => 'default', 'path' => 'database/migrations', 'priority' => 'default', 'mode' => 'core',
                'legacy_sources' => 'app',
            ]]]],
        ]);
    }
}
```

The `descriptorsFor()` helper builds a `PackageManifest` over an in-memory `installed.json` shape the way the existing `PackageManifest` tests do — copy their fixture idiom; `SqliteMigrationHarness` is whichever trait/base the existing `MigrationManager` unit tests use (open `tests/Unit/Database/Migrations/` first and mirror it; if there is none, build the manager over a temp sqlite `Connection` as `tests/Unit/Database/` does elsewhere).

- [ ] **Step 2: Run them to verify they fail**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit tests/Unit/Database/Migrations/LegacySourcesTest.php tests/Unit/Extensions/PackageManifestLegacySourcesTest.php`
Expected: FAIL — unknown named argument / `legacySources` undefined property / the aliased row pending.

- [ ] **Step 3: Implement**

`MigrationDescriptor`: add `public readonly array $legacySources = []` as the last constructor parameter; validate each entry with the same regex the source names satisfy (`/^[a-z0-9][a-z0-9_\-\/.:]*$/`) and reject the lane's own `source()`.

`PackageManifest` (descriptor parsing loop): read `$row['legacy_sources'] ?? []`; it must be a list of strings, else `DescriptorValidationException("Package {$name}: legacy_sources must be a list of source names")`; pass to the constructor.

`MigrationManager`:

```php
    /** @var list<array{path: string, priority: int, source: string, legacy: list<string>}> */
    private array $additionalMigrationPaths = [];

    public function addMigrationPath(string $path, int $priority, ?string $source = null, array $legacySources = []): void
    {
        // ...existing resolution of $source...
        $this->additionalMigrationPaths[] = ['path' => $path, 'priority' => $priority, 'source' => $source, 'legacy' => array_values($legacySources)];
    }

    public function registerDescriptor(MigrationDescriptor $descriptor, string $absolutePath): void
    {
        $this->addMigrationPath($absolutePath, $descriptor->priority, $descriptor->source(), $descriptor->legacySources);
        // ...existing descriptorSources bookkeeping...
    }

    /** True when $file is recorded under $source OR under any of the lane's legacy sources. */
    private function isApplied(array $lane, string $basename, array $appliedKeys): bool
    {
        if (in_array($this->sourceKey($lane['source'], $basename), $appliedKeys, true)) {
            return true;
        }
        foreach ($lane['legacy'] ?? [] as $legacy) {
            if (in_array($this->sourceKey($legacy, $basename), $appliedKeys, true)) {
                return true;
            }
        }
        return false;
    }
```

Use `isApplied()` in `pendingForSources()` and in `getPendingMigrations()` wherever `sourceKey($src['source'], basename)` is compared today (`allSources()` entries must carry `legacy` — the main app entry gets `[]`).

Adoption, called at the top of `migrateSources()` and `runPending()`-equivalent (whatever `migrate:run` uses):

```php
    /** Rewrite ledger rows recorded under a lane's legacy sources to the lane's current source. */
    private function adoptLegacySources(): void
    {
        foreach ($this->allSources() as $lane) {
            foreach ($lane['legacy'] ?? [] as $legacy) {
                $files = array_map(static fn ($f) => basename($f->getPathname()), iterator_to_array($this->fileFinder->findMigrations($lane['path'])));
                if ($files === []) {
                    continue;
                }
                $this->db->table($this->table)->where('source', '=', $legacy)->whereIn('migration', $files)
                    ->update(['source' => $lane['source']]);
            }
        }
    }
```

(Column names `source`/`migration` are the ledger's — confirm against `ensureVersionTable()` and use the same query-builder style the file already uses.)

- [ ] **Step 4: Run the tests, then the full framework suite and phpcs/phpstan**

Run: `vendor/bin/phpunit tests/Unit/Database/Migrations tests/Unit/Extensions && COMPOSER_PROCESS_TIMEOUT=0 composer test && composer phpcs && vendor/bin/phpstan analyse --no-progress --memory-limit=1G src/Database/Migrations/MigrationManager.php src/Extensions/PackageManifest.php src/Extensions/Schema/MigrationDescriptor.php`
Expected: all green.

- [ ] **Step 5: Release 1.85.0 — Alphard (the release skill's file map: CHANGELOG with Upgrade Notes "new optional manifest key; no behaviour change without it", Version.php, ROADMAP, docs releases.md + app.config.ts, api-skeleton composer.json), commit fix + release + companions; the user tags/pushes/publishes.**

- [ ] **Step 6: Back in Thallo, repin** (`composer update glueful/framework`, lock moves only the framework), full suite, commit `chore(deps): framework 1.85.0 — legacy_sources on migration descriptors`.

---

### Task 2: `core/` becomes the `glueful/thallo-core` package (path-installed in dev)

**Files:**
- Create: `core/composer.json`, `core/README.md`, `core/LICENSE` (copy of the root's).
- Modify: `composer.json` (root: name `glueful/thallo-dev`, path repo `core`, require `glueful/thallo-core: "*"`, `minimum-stability: dev`, keep `prefer-stable: true`, drop the direct `glueful/thallo-*` requires — core requires them; keep the 13 path repositories); every `packages/*/composer.json` (remove `"version"`, inter-pack requires → `"self.version"`); `core/src/Providers/CoreServiceProvider.php` (drop the two `loadMigrationsFrom` calls for core lanes — descriptors own them now; keep the operator's root `database/migrations` registration under `app`); `config/app.php` (`paths.migrations` back to `$basePath . '/database/migrations'`); `scripts/run-test-migrations.php` (register the two core lanes with their NEW sources and legacy aliases); `scripts/check-pack-boundaries.php` (the "depends on glueful/thallo" rule must also forbid `glueful/thallo-core` and `glueful/thallo-dev`).
- Test: `tests/Integration/Setup/CoreMigrationSourcesTest.php` (rewritten), `tests/Unit/Support/CorePackageTest.php` (new).

**Interfaces:**
- Produces: package `glueful/thallo-core` (type `library`, PSR-4 `Thallo\Core\` → `src/`), manifest `extra.glueful.provider = Thallo\Core\Providers\CoreServiceProvider`, migration lanes `glueful/thallo-core` (default priority, `database/migrations`, `legacy_sources: ["app"]`) and `glueful/thallo-core:dependent` (dependent priority, `database/dependent-migrations`, `legacy_sources: ["app:dependent"]`); the dev root resolves it as `dev-*` from `core/`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Support/CorePackageTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/** core/ is a Composer package the dev root installs from a path repository. */
final class CorePackageTest extends TestCase
{
    private function json(string $rel): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testCoreIsAPackageWithAManifest(): void
    {
        $core = $this->json('core/composer.json');
        self::assertSame('glueful/thallo-core', $core['name']);
        self::assertSame('library', $core['type']);
        self::assertSame(['Thallo\\Core\\' => 'src/'], $core['autoload']['psr-4']);
        self::assertArrayNotHasKey('version', $core, 'Packagist derives versions from tags');
        self::assertSame('Thallo\\Core\\Providers\\CoreServiceProvider', $core['extra']['glueful']['provider']);

        $lanes = array_column($core['extra']['glueful']['migrations'], null, 'id');
        self::assertSame(['app'], $lanes['default']['legacy_sources']);
        self::assertSame('database/migrations', $lanes['default']['path']);
        self::assertSame(['app:dependent'], $lanes['dependent']['legacy_sources']);
        self::assertSame('dependent', $lanes['dependent']['priority']);
    }

    public function testCoreRequiresEveryPackAtItsOwnVersion(): void
    {
        $core = $this->json('core/composer.json');
        foreach (glob(dirname(__DIR__, 3) . '/packages/thallo-*') as $dir) {
            $name = $this->json('packages/' . basename($dir) . '/composer.json')['name'];
            self::assertSame('self.version', $core['require'][$name] ?? null, "$name must be pinned to core's version");
        }
    }

    public function testTheDevRootInstallsCoreFromThePathRepository(): void
    {
        $root = $this->json('composer.json');
        self::assertSame('glueful/thallo-dev', $root['name'], 'the dev repo is not what create-project installs');
        self::assertContains(['type' => 'path', 'url' => 'core'], $root['repositories']);
        self::assertSame('*', $root['require']['glueful/thallo-core']);
        self::assertSame('dev', $root['minimum-stability']);
        self::assertTrue($root['prefer-stable']);
        self::assertFileExists(dirname(__DIR__, 3) . '/vendor/glueful/thallo-core/composer.json', 'run composer update');
    }

    public function testNoPublishedPackageCarriesAVersionField(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/packages/thallo-*/composer.json') as $file) {
            self::assertArrayNotHasKey('version', json_decode((string) file_get_contents($file), true), $file);
        }
    }
}
```

Rewrite `tests/Integration/Setup/CoreMigrationSourcesTest.php`:

```php
    public function testCoreLanesAreDescriptorSourcesWithLegacyAliases(): void
    {
        $manager = $this->container()->get(MigrationManager::class);
        self::assertTrue($manager->hasSource('glueful/thallo-core'));
        self::assertTrue($manager->hasSource('glueful/thallo-core:dependent'));
        self::assertTrue($manager->hasSource('app'), 'the operator\'s root database/migrations');
    }

    public function testADatabaseRecordedUnderTheOldSourcesShowsNothingPending(): void
    {
        // The test database was migrated by this same tree, so its rows carry the new names; plant
        // one legacy-named row to prove the alias: rewrite one applied row back to 'app'.
        $pdo = $this->connection()->getPDO();
        $pdo->exec("UPDATE migrations SET source = 'app' WHERE migration = '017_CreateBlockTypesTable.php'");
        $manager = $this->container()->get(MigrationManager::class);
        self::assertSame([], $manager->getPendingMigrations(), 'a legacy row counts as applied');
        $pdo->exec("UPDATE migrations SET source = 'glueful/thallo-core' WHERE migration = '017_CreateBlockTypesTable.php'");
    }
```

(The ledger table/column names are the framework's — read `ensureVersionTable()` and use them; `migrations`/`source`/`migration` are the expected ones.)

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Support/CorePackageTest.php tests/Integration/Setup/CoreMigrationSourcesTest.php`
Expected: FAIL — `core/composer.json` missing.

- [ ] **Step 3: Create the package and rewire the dev root**

`core/composer.json` (requires copied from the root's non-dev `require` minus the packs' `*` entries, which become `self.version`; the framework floor becomes `^1.85`):

```json
{
  "name": "glueful/thallo-core",
  "description": "Thallo — the application: content model, admin API, delivery, starter library, and the capability packs it composes.",
  "type": "library",
  "license": "MIT",
  "authors": [{ "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }],
  "require": {
    "php": "^8.3",
    "glueful/framework": "^1.85",
    "glueful/aegis": "^1.15",
    "glueful/audit": "^1.4",
    "glueful/commerce": "^1.13",
    "glueful/email-notification": "^1.13",
    "glueful/i18n": "^1.2",
    "glueful/import-export": "^1.2",
    "glueful/media": "^1.2",
    "glueful/meilisearch": "^1.7",
    "glueful/payvia": "^2.8",
    "glueful/subscriptions": "^2.3",
    "glueful/tenancy": "^2.1",
    "glueful/users": "^2.4",
    "league/commonmark": "^2.8",
    "symfony/brevo-mailer": "^8.1",
    "glueful/thallo-account": "self.version",
    "glueful/thallo-analytics": "self.version",
    "glueful/thallo-collections": "self.version",
    "glueful/thallo-commerce": "self.version",
    "glueful/thallo-contracts": "self.version",
    "glueful/thallo-importers": "self.version",
    "glueful/thallo-navigation": "self.version",
    "glueful/thallo-render": "self.version",
    "glueful/thallo-search": "self.version",
    "glueful/thallo-seo": "self.version",
    "glueful/thallo-subscriptions": "self.version",
    "glueful/thallo-tenancy": "self.version",
    "glueful/thallo-workflow": "self.version"
  },
  "autoload": { "psr-4": { "Thallo\\Core\\": "src/" } },
  "extra": {
    "glueful": {
      "provider": "Thallo\\Core\\Providers\\CoreServiceProvider",
      "requires": { "glueful": ">=1.85.0", "extensions": [] },
      "migrations": [
        { "id": "default", "path": "database/migrations", "priority": "default", "mode": "core", "legacy_sources": ["app"] },
        { "id": "dependent", "path": "database/dependent-migrations", "priority": "dependent", "mode": "core", "legacy_sources": ["app:dependent"] }
      ]
    }
  },
  "minimum-stability": "stable"
}
```

Root `composer.json`: `"name": "glueful/thallo-dev"`; add `{"type": "path", "url": "core"}` to `repositories`; replace the 13 `glueful/thallo-*: "*"` requires with `"glueful/thallo-core": "*"`; move the extension/library requires listed above out of the root (they arrive through core) — keep `php` and `glueful/framework`; set `"minimum-stability": "dev"`, keep `"prefer-stable": true`. Remove `"version"` from every `packages/*/composer.json` and change `"glueful/thallo-contracts": "*"` / `"glueful/thallo-tenancy": "*"` to `"self.version"` (13 files: `python3` loop over the JSON). Then:

```bash
composer update --lock 2>&1 | tail -3 && composer update 2>&1 | grep -E "thallo-core|Problem|error" | head
ls -la vendor/glueful/thallo-core        # a symlink to ../../core
composer dump-autoload -q
```

`CoreServiceProvider::boot()`: delete the two `loadMigrationsFrom(self::corePath(...))` calls; keep `$this->loadMigrationsFrom(base_path($context, 'database/migrations'), MigrationPriority::DEFAULT, 'app')` with its comment reworded: "the operator's own migrations; Thallo's lanes are declared by core/composer.json's manifest (with legacy_sources for pre-split ledgers)".

`config/app.php`: `'migrations' => $basePath . '/database/migrations',` with the comment "the operator's; Thallo's lanes come from the thallo-core manifest".

`scripts/run-test-migrations.php`: main path back to `$root . '/database/migrations'`; add
```php
$manager->addMigrationPath($root . '/core/database/migrations', MigrationPriority::DEFAULT, 'glueful/thallo-core', ['app']);
$manager->addMigrationPath($root . '/core/database/dependent-migrations', MigrationPriority::DEPENDENT, 'glueful/thallo-core:dependent', ['app:dependent']);
```
and remove the old dependent line.

`scripts/check-pack-boundaries.php`: the forbidden dependency set becomes `['glueful/thallo', 'glueful/thallo-dev', 'glueful/thallo-core']`.

`bootstrap/cache`, `storage/cache/container`, route caches: clear. `config/serviceproviders.php` keeps `Thallo\Core\Providers\CoreServiceProvider` (core is a library; listing is how library providers load).

- [ ] **Step 4: Prove the ledger, then the suites**

```bash
composer test:migrate 2>&1 | tail -2                        # fresh test DB: lanes under the NEW names
php glueful migrate:status | grep -E "Total|Completed|Pending"   # the DEV database: still Pending: 0 (legacy alias)
php glueful migrate:run 2>&1 | tail -2 && php glueful migrate:verify 2>&1 | grep -E "thallo-core|app\b"   # adoption: rows now under glueful/thallo-core
vendor/bin/phpunit tests/Unit/Support tests/Integration/Setup tests/Integration/Authority && composer phpcs && composer boundaries
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: all green; the dev database's ledger shows the same totals as `/tmp/ledger-before.txt`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(core): glueful/thallo-core is a Composer package; lanes declared by manifest with legacy_sources; packs at self.version"
```

---

### Task 3: The `skeleton/` directory — what `create-project` ships

**Files:**
- Create: `skeleton/composer.json`, `skeleton/README.md`, `skeleton/LICENSE`, `skeleton/.gitignore`, `skeleton/.env.example`, `skeleton/public/index.php`, `skeleton/public/.htaccess` (if the root has one), `skeleton/bootstrap/app.php`, `skeleton/glueful`, `skeleton/config/*.php` (every root config file — they are the framework-shaped files plus the operator's overrides), `skeleton/routes/README.md` + `.gitkeep`, `skeleton/app/.gitkeep`, `skeleton/database/migrations/.gitkeep`, `skeleton/storage/{cache,logs,cdn,backups,archives}/.gitkeep`, `skeleton/themes/.gitkeep`, `skeleton/public/storage/.gitkeep` (mirror whatever `public/` holds besides `admin/`).
- Test: `tests/Unit/Skeleton/SkeletonParityTest.php`.

**Interfaces:**
- Produces: `skeleton/` = the exact tree `composer create-project glueful/thallo` installs; parity with the dev root is enforced by test so the two cannot drift.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Skeleton/SkeletonParityTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Skeleton;

use PHPUnit\Framework\TestCase;

/**
 * skeleton/ is what create-project installs. Every file it shares with the dev root must be
 * byte-identical (the dev root IS a skeleton install plus tooling), and it must never contain
 * product code — that arrives in vendor/ as glueful/thallo-core.
 */
final class SkeletonParityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    /** @return list<string> */
    private static function shared(): array
    {
        return ['bootstrap/app.php', 'public/index.php', 'glueful', '.env.example'];
    }

    public function testSharedFilesAreIdentical(): void
    {
        foreach (self::shared() as $rel) {
            self::assertFileEquals("$this->root/$rel", "$this->root/skeleton/$rel", $rel);
        }
        foreach (glob("$this->root/config/*.php") as $file) {
            $rel = 'config/' . basename($file);
            self::assertFileEquals($file, "$this->root/skeleton/$rel", $rel);
        }
    }

    public function testTheSkeletonCarriesNoProductCodeAndRequiresCore(): void
    {
        self::assertDirectoryDoesNotExist("$this->root/skeleton/core");
        self::assertDirectoryDoesNotExist("$this->root/skeleton/packages");
        self::assertDirectoryDoesNotExist("$this->root/skeleton/tests");
        self::assertDirectoryDoesNotExist("$this->root/skeleton/admin");
        self::assertSame([], glob("$this->root/skeleton/app/*.php"));
        self::assertSame([], glob("$this->root/skeleton/routes/*.php"));
        self::assertSame([], glob("$this->root/skeleton/database/migrations/*.php"));

        $composer = json_decode((string) file_get_contents("$this->root/skeleton/composer.json"), true);
        self::assertSame('glueful/thallo', $composer['name']);
        self::assertSame('project', $composer['type']);
        self::assertMatchesRegularExpression('/^\^1\.0\.0-beta\.\d+$/', $composer['require']['glueful/thallo-core']);
        self::assertArrayNotHasKey('repositories', $composer, 'Packagist only — no path repositories in the skeleton');
        self::assertSame(['App\\' => 'app/'], $composer['autoload']['psr-4'], 'App\\ is the operator\'s');
    }

    public function testTheSkeletonGitignoreProtectsWhatIsGenerated(): void
    {
        $ignore = (string) file_get_contents("$this->root/skeleton/.gitignore");
        foreach (['/vendor/', '/.env', '/public/admin/', '/storage/cache/', '/storage/logs/'] as $line) {
            self::assertStringContainsString($line, $ignore);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/phpunit tests/Unit/Skeleton` → FAIL (skeleton missing).

- [ ] **Step 3: Build the skeleton**

```bash
mkdir -p skeleton/{public,bootstrap,config,routes,app,database/migrations,storage/{cache,logs,cdn,backups,archives},themes}
cp bootstrap/app.php skeleton/bootstrap/ && cp public/index.php skeleton/public/ && cp glueful skeleton/glueful && cp .env.example skeleton/ && cp LICENSE skeleton/ && cp config/*.php skeleton/config/
cp routes/README.md skeleton/routes/ && touch skeleton/routes/.gitkeep skeleton/app/.gitkeep skeleton/database/migrations/.gitkeep skeleton/themes/.gitkeep skeleton/storage/{cache,logs,cdn,backups,archives}/.gitkeep
```

`skeleton/composer.json`:

```json
{
  "name": "glueful/thallo",
  "description": "Thallo — a Glueful-based CMS. This is the install template; the application ships as glueful/thallo-core.",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": "^8.3",
    "glueful/thallo-core": "^1.0.0-beta.21"
  },
  "autoload": { "psr-4": { "App\\": "app/" } },
  "scripts": {
    "glueful": "php glueful",
    "serve": "php glueful serve"
  },
  "config": { "sort-packages": true, "allow-plugins": { "glueful/framework": true } },
  "minimum-stability": "beta",
  "prefer-stable": true
}
```

(Copy `config.allow-plugins` from the root's `config` block exactly; the `minimum-stability: beta` is what lets `^1.0.0-beta.21` resolve pre-releases.)

`skeleton/.gitignore`: `/vendor/`, `/.env`, `/public/admin/`, `/storage/cache/*`, `/storage/logs/*`, `/storage/cdn/*`, `/bootstrap/cache/`, `!**/.gitkeep`.

`skeleton/README.md`: install (`composer create-project --prefer-dist glueful/thallo my-site`), provision, first admin, upgrade (`composer update && php glueful thallo:provision`), and "where things live" (`app/`, `routes/`, `database/migrations/`, `themes/`, `config/` are yours; Thallo is `vendor/glueful/thallo-core`).

- [ ] **Step 4: Run the test and phpcs, commit**

Run: `vendor/bin/phpunit tests/Unit/Skeleton && composer phpcs`
Expected: PASS.

```bash
git add -A && git commit -m "feat(skeleton): the create-project template, parity-tested against the dev root"
```

---

### Task 4: Skeleton smoke — install the skeleton against the local packages

**Files:**
- Create: `scripts/skeleton-smoke`.
- Modify: `.github/workflows/ci.yml` (a `skeleton-smoke` job after the test shards, Postgres service like the shards), `composer.json` scripts (`"test:skeleton": "bash scripts/skeleton-smoke"`).
- Test: the script is its own test; `tests/Unit/Scripts/SkeletonSmokeScriptTest.php` checks `--dry-run` prints the steps.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Scripts/SkeletonSmokeScriptTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class SkeletonSmokeScriptTest extends TestCase
{
    public function testDryRunPrintsTheInstallStepsAndTouchesNothing(): void
    {
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg("$root/scripts/skeleton-smoke") . ' --dry-run 2>&1', $lines, $code);
        $out = implode("\n", $lines);
        self::assertSame(0, $code, $out);
        foreach (['create-project', 'repositories', 'path', 'composer install', 'thallo:doctor', 'thallo:provision', 'public/admin/index.html', 'migrate:status'] as $needle) {
            self::assertStringContainsString($needle, $out);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails** — script missing.

- [ ] **Step 3: Write the script**

```bash
#!/usr/bin/env bash
# Install the skeleton the way an operator will — but from the LOCAL packages: skeleton/ is
# copied to a scratch directory, path repositories for core/ and packages/* are injected, and the
# documented first-run sequence runs against the test database. Proves the split without
# Packagist; the clean-machine gate proves it with Packagist after publication.
#   scripts/skeleton-smoke [--dry-run]
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRY=0; [[ "${1:-}" == "--dry-run" ]] && DRY=1
SCRATCH="$(mktemp -d /tmp/thallo-skeleton-XXXX)"
run() { echo "+ $*"; [[ "$DRY" -eq 1 ]] || "$@"; }
echo "== skeleton-smoke: create-project (local) into $SCRATCH =="
run cp -R "$ROOT/skeleton/." "$SCRATCH/"
echo "+ inject path repositories for core and packages (composer config repositories.* path …)"
if [[ "$DRY" -eq 0 ]]; then
  (cd "$SCRATCH" && composer config repositories.core path "$ROOT/core" && composer config minimum-stability dev && composer config prefer-stable true
   for p in "$ROOT"/packages/thallo-*; do composer config "repositories.$(basename "$p")" path "$p"; done
   composer require --no-update "glueful/thallo-core:*")
fi
run_in() { local d="$1"; shift; echo "+ (cd $d && $*)"; [[ "$DRY" -eq 1 ]] || (cd "$d" && "$@"); }
run_in "$SCRATCH" composer install --no-dev --no-interaction --prefer-dist
echo "+ write .env from .env.example with the test database (DB_PGSQL_DATABASE=app_test)"
[[ "$DRY" -eq 1 ]] || (cd "$SCRATCH" && cp .env.example .env && sed -i '' 's/^DB_PGSQL_DATABASE=.*/DB_PGSQL_DATABASE=app_test/' .env && sed -i '' 's/^APP_ENV=.*/APP_ENV=testing/' .env)
(cd "$ROOT" && DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/reset-test-db.php) 2>/dev/null || true
run_in "$SCRATCH" php glueful thallo:doctor
run_in "$SCRATCH" php glueful thallo:provision --no-interaction
run_in "$SCRATCH" php glueful migrate:status
echo "+ assert public/admin/index.html was published and no app/ code exists"
if [[ "$DRY" -eq 0 ]]; then
  test -f "$SCRATCH/public/admin/index.html"
  test -z "$(ls "$SCRATCH/app"/*.php 2>/dev/null)"
  (cd "$SCRATCH" && php glueful migrate:status | grep -q "Pending: 0")
fi
echo "== skeleton-smoke: OK =="
```

(`sed -i ''` is macOS; CI is Linux — use `sed -i.bak … && rm .env.bak` for both.) Requires `core/resources/admin/index.html` to exist: the script runs `cd admin && pnpm run build` first when it is missing (guarded, printed).

Add to `composer.json` scripts and to CI as a job that installs pnpm/PHP like the shards do and runs `composer test:skeleton`.

- [ ] **Step 4: Run it for real, then commit**

Run: `bash scripts/skeleton-smoke` → `== skeleton-smoke: OK ==`; `vendor/bin/phpunit tests/Unit/Scripts && composer phpcs`.

```bash
git add -A && git commit -m "test(skeleton): skeleton-smoke installs the template against the local packages"
```

---

### Task 5: Release tooling — split, tag, verify per artifact

**Files:**
- Create: `scripts/release-split` (subtree split of `core`, `skeleton` and each `packages/thallo-*` onto `split/<name>` branches, tag `vX` on each, print the 15 push commands; `--push` executes them).
- Modify: `scripts/release-bake` (unchanged path; note that the bake precedes the split), `scripts/verify-dist-archive` (takes `--artifact core|skeleton|pack <name>` and checks the SPLIT branch's archive: core must contain `resources/admin/index.html` + icon floor + `composer.json` with the manifest; skeleton must contain `public/index.php`, `composer.json` requiring core, and must NOT contain `core/`, `tests/`, `admin/`, `docs/internal/`; a pack must contain `composer.json` and `src/`), `docs/internal/RELEASING.md` (new sequence), `docs/internal/DISTRIBUTION.md` (decision 7 amended; decision 10 progress; checklist), `composer.json` scripts (`release:split`).
- Test: `tests/Unit/Scripts/ReleaseSplitScriptTest.php` (`--dry-run vX` prints 15 splits + tags + push lines, refuses a non-tag).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Scripts/ReleaseSplitScriptTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ReleaseSplitScriptTest extends TestCase
{
    public function testDryRunListsEveryArtifactAndRefusesANonTag(): void
    {
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg("$root/scripts/release-split") . ' --dry-run v1.0.0-beta.21 2>&1', $lines, $code);
        $out = implode("\n", $lines);
        self::assertSame(0, $code, $out);
        self::assertSame(15, substr_count($out, 'git subtree split'));
        self::assertStringContainsString('--prefix=core', $out);
        self::assertStringContainsString('--prefix=skeleton', $out);
        self::assertStringContainsString('--prefix=packages/thallo-contracts', $out);
        self::assertSame(15, substr_count($out, 'git push split/'));
        self::assertStringContainsString('skeleton/composer.json → "glueful/thallo-core": "^1.0.0-beta.21"', $out);

        exec('bash ' . escapeshellarg("$root/scripts/release-split") . ' --dry-run main 2>&1', $bad, $badCode);
        self::assertSame(2, $badCode);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — script missing.

- [ ] **Step 3: Write the script**

```bash
#!/usr/bin/env bash
# Release the split (RELEASING.md): pin the skeleton to this core version, subtree-split every
# published directory onto split/<name>, tag each with the release tag, and print (or --push) the
# 15 pushes to the read-only mirror repositories (remotes named split/<name>). Tags are immutable.
#   scripts/release-split [--dry-run] [--push] vX.Y.Z[-beta.N]
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
DRY=0; PUSH=0; TAG=""
for a in "$@"; do case "$a" in --dry-run) DRY=1;; --push) PUSH=1;; *) TAG="$a";; esac; done
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "release-split: '$TAG' is not a release tag" >&2; exit 2; }
VERSION="${TAG#v}"
ARTIFACTS=(core skeleton); for p in packages/thallo-*; do ARTIFACTS+=("$p"); done
name_of() { case "$1" in core) echo thallo-core;; skeleton) echo thallo-skeleton;; *) basename "$1";; esac; }
run() { echo "+ $*"; [[ "$DRY" -eq 1 ]] || "$@"; }
echo "== release-split $TAG =="
echo "+ skeleton/composer.json → \"glueful/thallo-core\": \"^$VERSION\""
if [[ "$DRY" -eq 0 ]]; then
  php -r '$f=$argv[1];$j=json_decode(file_get_contents($f),true);$j["require"]["glueful/thallo-core"]="^".$argv[2];file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' skeleton/composer.json "$VERSION"
  git add skeleton/composer.json && git commit -q -m "chore(skeleton): require glueful/thallo-core ^$VERSION" || true
fi
for dir in "${ARTIFACTS[@]}"; do
  name="$(name_of "$dir")"
  run git subtree split --prefix="$dir" -b "split/$name" --rejoin
  run git tag -f -a "$TAG" -m "$TAG" "split/$name"   # NOTE: tag objects are per-branch — see below
  echo "+ git push split/$name split/$name:main $TAG"
  [[ "$PUSH" -eq 1 && "$DRY" -eq 0 ]] && git push "split/$name" "split/$name:main" "refs/tags/$TAG"
done
echo "== release-split: done (tags on split branches; push with --push or the printed commands) =="
```

Tags: git tags are repo-global, so one tag name cannot point at 15 commits. Use per-artifact tag names locally (`split/<name>/vX`) and push them AS `vX` to each mirror: `git tag -f -a "split/$name/$TAG" "split/$name"` and `git push split/$name "refs/tags/split/$name/$TAG:refs/tags/$TAG"`. Update the script and the test's expected push line accordingly (`git push split/`). The dry run must still count 15 pushes.

`verify-dist-archive`: add an `--artifact <name>` mode that archives `split/<name>` (or, before a split exists, `git archive HEAD:<dir>` via `git archive --format=tar HEAD:<dir>`) and applies the per-artifact assertions listed in Files. The default (no flag) verifies all 15 from `HEAD:<dir>`, so it can run BEFORE splitting.

- [ ] **Step 4: Verify locally without pushing**

Run: `bash scripts/release-split --dry-run v1.0.0-beta.21`, `bash scripts/verify-dist-archive` (all artifacts from HEAD), `vendor/bin/phpunit tests/Unit/Scripts`, `composer phpcs`.
Expected: green; the verify output lists 15 `ok:` artifact lines.

- [ ] **Step 5: Docs, then commit**

`RELEASING.md` sequence becomes: bake → changelog cut → release commit → `verify-dist-archive` (all artifacts) → `release-split vX` → user: `--push` (or the printed pushes) → Packagist updates → clean-machine gate: `composer create-project glueful/thallo t-gate vX` AND an upgrade from the previous tag. `DISTRIBUTION.md`: decision 7 amended ("packs are published; consumer: thallo-core"), decision 10 marked "phase 3 shipped in vX", checklist items ticked/updated, the human prerequisites recorded.

```bash
git add -A && git commit -m "chore(release): split tooling — subtree split of core, skeleton and packs; per-artifact dist gate"
```

---

### Task 6: Docs tell the new truth; full gates; merge

**Files:**
- Modify: `docs/upgrading.md` (top: `composer update && php glueful thallo:provision` is the upgrade; the one-time move for installs ≤ beta.20: `create-project` the skeleton beside the old install, copy `.env`, `storage/`, `themes/`, operator `app/`/`routes/`/`database/migrations/`, `composer install`, provision, switch the vhost; `scripts/deploy-site` stays documented for tag-pinned deploys of the website), `README.md` (install/upgrade), `docs/production.md` (nothing about paths changes; mention `vendor/glueful/thallo-core` as where the app lives), `CHANGELOG.md` (Unreleased: the split, upgrade notes), `docs/internal/plans/2026-09-12-composer-updatable-thallo.md` (phase 3 status).
- Test: none new; every gate.

- [ ] **Step 1: Write the docs** (content as listed; keep the phase 1 wording where it still applies).

- [ ] **Step 2: Every gate, one at a time**

`composer phpcs`, `composer boundaries`, `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:distribution`, `bash scripts/skeleton-smoke`, `bash scripts/verify-dist-archive`, `(cd admin && npm run -s type-check && npm run -s lint && npx vitest run && npm run -s build-only)`.

- [ ] **Step 3: Commit and merge**

```bash
git add -A && git commit -m "docs: Thallo is Composer-updatable — upgrade guide, README, changelog for the split"
git checkout dev && git merge --no-ff package-split-3 -m "Merge package-split-3: publish the split — glueful/thallo-core, the skeleton, the packs"
```

Report the merge commit. Then, with the user: create the 15 repositories and remotes, cut beta.21 (bake → release commit → verify → split → push), publish, run the clean-machine install AND upgrade gate, move thallo.dev onto the skeleton with the one-time procedure.

---

## Out of scope

- **Phase 4 — update notice** (`glueful/thallo-core` on Packagist is what it polls): its own plan after beta.21 is published.
- Per-version docs, the website's landing page and docs corpus (website plan).
