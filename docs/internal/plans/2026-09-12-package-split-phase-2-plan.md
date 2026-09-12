# Package split — Phase 2 (namespace and layout move) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Thallo's application out of the `create-project` root's `App\` namespace and top-level directories into a self-contained `core/` tree that runs entirely through the framework's provider seams, while the repo remains one root package and every gate stays green.

**Architecture:** `App\` becomes `Thallo\Core\` under `core/src/`. Routes, migrations, config defaults and the admin bundle move under `core/` and are loaded by `CoreServiceProvider` via `loadRoutesFrom`, `loadMigrationsFrom` (source `app` and `app:dependent`, so existing ledgers keep matching), `mergeConfig` and `serveFrontend`. The root keeps only what the future skeleton keeps: `public/`, `bootstrap/`, `config/` (framework-shaped files plus overrides), `routes/` (empty, the operator's), `database/migrations/` (empty, the operator's), `storage/`, `themes/`, `packages/` (moved in phase 3), tests and tooling.

**Tech Stack:** PHP 8.4, Glueful framework 1.84 (`ServiceProvider` seams), Composer PSR-4, PHPUnit 10, phpcs, Vite (admin bundle), bash release scripts.

**Spec:** `docs/internal/plans/2026-09-12-composer-updatable-thallo.md` (phase 2), `docs/internal/DISTRIBUTION.md` decision 10.

## Global Constraints

- Work on a branch (`package-split`), not `dev`: `dev` must stay releasable for beta.21. Merge with `--no-ff` when the plan is complete.
- Gates before every commit: `composer phpcs`, `composer boundaries`, and the PHP suite relevant to the task; the FULL suite (`COMPOSER_PROCESS_TIMEOUT=0 composer test`, ~10 min, never concurrently with anything else on the test DB) at Tasks 1, 4, 5 and 7; admin `type-check`/`vitest`/`build` at Task 6; `composer test:distribution` at Task 7.
- No file is deleted from the root before its replacement under `core/` is proven by a test.
- Migration ledger sources stay `app` and `app:dependent`: an existing beta.20 database must show zero pending migrations after this phase.
- Commit messages carry no attribution trailers; never push or tag.
- Operator contract from the spec: `.env`, `storage/`, the database, `themes/{name}/`, and the operator's own `app/`, `routes/`, `database/migrations/` are never Thallo's after this phase.

---

### Task 0: Branch and baseline

**Files:** none modified.

- [ ] **Step 1: Confirm a clean tree on dev and create the branch**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/thallo
git status --short | wc -l        # expect 0
git checkout -b package-split
```

- [ ] **Step 2: Record the baseline route table and migration sources (used by later tasks' tests)**

```bash
php glueful route:debug 2>/dev/null | sort > /tmp/routes-before.txt || true
wc -l /tmp/routes-before.txt
```

Expected: a non-empty listing. If `route:debug` output differs in shape, the later route tests use `findRoute()` anchors instead and this file is informational only.

---

### Task 1: Rename the namespace `App\` → `Thallo\Core\` in place

**Files:**
- Modify: every `*.php` under `app/`, `tests/`, `routes/`, `config/`, `database/` that references `App\`; `composer.json` (`autoload`, `autoload-dev`); `scripts/check-pack-boundaries.php`; docblocks in `packages/thallo-contracts/src/**`; comments in `admin/src/queries/*.ts`.
- Create: `scripts/rename-app-namespace.py` (one-shot, deleted at the end of the task).
- Test: `tests/Unit/Support/NamespaceMoveTest.php`

**Interfaces:**
- Produces: PSR-4 root `Thallo\Core\` → `app/` (moved in Task 2), `Thallo\Core\Tests\` → `tests/`. Provider class `Thallo\Core\Providers\ThalloServiceProvider` (renamed to `CoreServiceProvider` in Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Support/NamespaceMoveTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/** After the move nothing in the product or its tests lives in App\ — that namespace is the operator's. */
final class NamespaceMoveTest extends TestCase
{
    public function testNoProductCodeRemainsInTheAppNamespace(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        foreach (['app', 'core/src', 'routes', 'core/routes', 'config', 'database', 'core/database', 'tests'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents((string) $file);
                if (preg_match('/(^|[^\w\\\\])App\\\\(?!Tests\\\\Support\\\\Fixtures)/m', $src) === 1) {
                    $offenders[] = substr((string) $file, strlen($root) + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'App\\ is the operator\'s namespace now');
        self::assertTrue(class_exists(\Thallo\Core\Providers\ThalloServiceProvider::class));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/NamespaceMoveTest.php`
Expected: FAIL — the offenders list is hundreds of files (and `Thallo\Core\Providers\ThalloServiceProvider` does not exist).

- [ ] **Step 3: Write the one-shot rename script**

```python
# scripts/rename-app-namespace.py — run once from the repo root, then delete.
import os, re, sys
ROOT = os.getcwd()
TARGETS = ['app', 'tests', 'routes', 'config', 'database', 'packages/thallo-contracts/src',
           'scripts/run-test-migrations.php', 'scripts/reset-test-db.php', 'admin/src/queries']
EXT = ('.php', '.ts')
double = re.compile(r'(^|[^\w\\])App\\\\')          # 'App\\Providers' inside single-quoted PHP strings
single = re.compile(r'(^|[^\w\\])App\\')            # use App\...; \App\...; {@see \App\...}
changed = 0
def walk(p):
    if os.path.isfile(p):
        yield p; return
    for d, _, files in os.walk(p):
        for f in files:
            yield os.path.join(d, f)
for t in TARGETS:
    for path in walk(os.path.join(ROOT, t)):
        if not path.endswith(EXT): continue
        src = open(path, encoding='utf-8').read()
        out = double.sub(r'\1Thallo\\\\Core\\\\', src)
        out = single.sub(r'\1Thallo\\Core\\', out)
        if out != src:
            open(path, 'w', encoding='utf-8').write(out); changed += 1
print(f'rewrote {changed} files')
```

- [ ] **Step 4: Run it, then fix the three places the regex cannot know about**

```bash
python3 scripts/rename-app-namespace.py
```

Then by hand:

1. `composer.json`:
   ```json
   "autoload":     { "psr-4": { "Thallo\\Core\\": "app/" } },
   "autoload-dev": { "psr-4": { "Thallo\\Core\\Tests\\": "tests/" } }
   ```
2. `scripts/check-pack-boundaries.php` line 24–48: the rule text and regex become
   ```php
   // Source-level boundary: no first-party pack (except the contracts package) may reference Thallo\Core\*.
   if (preg_match('/(^|[^\\w])Thallo\\\\Core\\\\/m', $src) === 1) {
       $violations[] = "{$name} references Thallo\\Core\\ (packs must use contracts, not the core)";
   ```
3. `rm -rf bootstrap/cache/extensions.php storage/cache/container storage/cache/routes_*.php` — the generated caches name the old provider class.

Then `composer dump-autoload`.

- [ ] **Step 5: Run the test and the fast gates**

Run: `vendor/bin/phpunit tests/Unit/Support/NamespaceMoveTest.php && composer phpcs && composer boundaries`
Expected: PASS, no phpcs errors, boundaries OK.

- [ ] **Step 6: Full suite**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test`
Expected: OK with the usual 71 skips. If a test references a class-string literal like `'App\\Something'` in a data provider that the regex missed, fix it and rerun.

- [ ] **Step 7: Delete the one-shot script and commit**

```bash
rm scripts/rename-app-namespace.py
git add -A
git commit -m "refactor(core): rename the application namespace App\\ to Thallo\\Core\\ (App\\ is the operator's)"
```

---

### Task 2: Move `app/` to `core/src/`

**Files:**
- Move: `app/` → `core/src/` (git mv).
- Modify: `composer.json` autoload path; `phpunit.xml` `<source><include><directory>`; `phpcs.xml.dist` `<file>app</file>` → `<file>core</file>`; any `dirname(__DIR__, N)` in the provider that computed the repo root from `app/Providers/` (depth changes from 2 to 3 — see step 3).
- Test: `tests/Unit/Support/CoreLayoutTest.php`

**Interfaces:**
- Produces: `core/src/` as the PSR-4 root of `Thallo\Core\`; `Thallo\Core\Providers\ThalloServiceProvider::corePath(string $relative = ''): string` returning `<repo>/core/<relative>`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Support/CoreLayoutTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Providers\ThalloServiceProvider;

final class CoreLayoutTest extends TestCase
{
    public function testTheApplicationLivesUnderCore(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertDirectoryExists("$root/core/src/Providers");
        self::assertDirectoryDoesNotExist("$root/app/Providers", 'app/ is the operator\'s directory');
        self::assertSame("$root/core", ThalloServiceProvider::corePath());
        self::assertSame("$root/core/routes/admin.php", ThalloServiceProvider::corePath('routes/admin.php'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/CoreLayoutTest.php`
Expected: FAIL — `core/src/Providers` missing, `corePath` undefined.

- [ ] **Step 3: Move and rewire**

```bash
git mv app core/src
```

`composer.json`: `"Thallo\\Core\\": "core/src/"`. `phpunit.xml`: `<directory>core/src</directory>`. `phpcs.xml.dist`: replace `<file>app</file>` with `<file>core</file>`.

In `core/src/Providers/ThalloServiceProvider.php` add, and use everywhere the file computed a repo path with `dirname(__DIR__, 2)`:

```php
    /** Absolute path under core/ (the product's own tree; the repo root is the operator's). */
    public static function corePath(string $relative = ''): string
    {
        $core = dirname(__DIR__, 2);
        return $relative === '' ? $core : $core . '/' . ltrim($relative, '/');
    }
```

Existing `dirname(__DIR__, 2) . '/database/dependent-migrations'` becomes `self::corePath('database/dependent-migrations')` in Task 4 (leave it pointing at the root path for now: `dirname(__DIR__, 3) . '/database/dependent-migrations'`). The `thallo.admin.bundle_path` default in `config/thallo.php` reads `dirname(__DIR__) . '/public/admin'` and is unchanged until Task 6.

Grep for any other root-relative computation in `core/src`: `grep -rn "dirname(__DIR__" core/src | grep -v corePath` — every hit must be reviewed and given the right depth.

- [ ] **Step 4: Run the test and the gates**

Run: `composer dump-autoload && vendor/bin/phpunit tests/Unit/Support && composer phpcs && vendor/bin/phpunit --testsuite Unit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(core): move the application to core/src"
```

---

### Task 3: Routes load from `core/routes` through the provider

**Files:**
- Move: `routes/*.php` (admin, admin_spa, content, forms, preview, signup) → `core/routes/`.
- Create: `routes/.gitkeep` and a `routes/README.md` (three lines: this directory is the operator's; Thallo's routes are loaded by the core provider).
- Modify: `core/src/Providers/ThalloServiceProvider.php::boot()` — add `loadRoutesFrom` for each file; delete the docblock paragraph that says routes must not be loaded here.
- Test: `tests/Integration/Http/CoreRoutesTest.php`

**Interfaces:**
- Consumes: `ThalloServiceProvider::corePath()` (Task 2).
- Produces: the same route table as before; the root `routes/` is empty.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Integration/Http/CoreRoutesTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Tests\Support\AppTestCase;

/** Every route file now lives under core/routes and is loaded by the provider, not discovered from the root. */
final class CoreRoutesTest extends AppTestCase
{
    public function testEveryCoreRouteFileIsRegistered(): void
    {
        self::assertNotNull($this->findRoute('GET', '/admin/config'), 'admin_spa.php');
        self::assertNotNull($this->findRoute('GET', '/v1/admin/block-types'), 'admin.php');
        self::assertNotNull($this->findRoute('GET', '/v1/content/{type}'), 'content.php');
        self::assertNotNull($this->findRoute('POST', '/_forms/submit'), 'forms.php');
        self::assertNotNull($this->findRoute('GET', '/v1/preview/{token}'), 'preview.php');
        self::assertNotNull($this->findRoute('POST', '/v1/signup'), 'signup.php');
    }

    public function testTheRootRoutesDirectoryBelongsToTheOperator(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame([], glob("$root/routes/*.php"), 'no product route file may remain in routes/');
        self::assertFileExists("$root/core/routes/admin.php");
    }
}
```

If a path above does not match the registered path exactly (check `php glueful route:debug`), correct the literal — the intent is one anchor per file.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/CoreRoutesTest.php`
Expected: the second test FAILS (files still under `routes/`); the first passes (discovery still on).

- [ ] **Step 3: Move the files and load them from the provider**

```bash
git mv routes/admin.php routes/admin_spa.php routes/content.php routes/forms.php routes/preview.php routes/signup.php core/routes/
touch routes/.gitkeep
```

`routes/README.md`:

```
# routes/

Your application's routes. Thallo's own routes live in core/routes and are loaded by the core
provider — do not copy them here.
```

In `boot()`, before `$this->registerEventListeners($context);`:

```php
        // Thallo's routes live under core/ and are loaded here; the root routes/ directory is
        // the operator's and is still auto-discovered by RouteManifest. (Loading a file from
        // BOTH mechanisms would register duplicates and the Router throws — which is why the
        // product's files no longer sit in the discovered directory.)
        foreach (['admin', 'admin_spa', 'content', 'forms', 'preview', 'signup'] as $file) {
            $this->loadRoutesFrom(self::corePath("routes/{$file}.php"));
        }
```

Delete the class docblock paragraph beginning "Routes: routes/admin.php is NOT loaded here." Clear the route cache: `rm -f storage/cache/routes_*.php`.

- [ ] **Step 4: Run the test and the HTTP suites**

Run: `vendor/bin/phpunit tests/Integration/Http tests/Integration/Content tests/Integration/Render && composer phpcs`
Expected: PASS. A `LogicException: duplicate route` means a file is still in `routes/`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(core): load Thallo's routes from core/routes through the provider; routes/ is the operator's"
```

---

### Task 4: Migrations load from `core/database` with unchanged ledger sources

**Files:**
- Move: `database/migrations/*.php` → `core/database/migrations/`; `database/dependent-migrations/*.php` → `core/database/dependent-migrations/`.
- Create: `database/migrations/.gitkeep`.
- Modify: `core/src/Providers/ThalloServiceProvider.php::boot()` (two `loadMigrationsFrom` calls); `scripts/run-test-migrations.php` (add the two core paths with sources `app` and `app:dependent`).
- Test: `tests/Integration/Setup/CoreMigrationSourcesTest.php`

**Interfaces:**
- Consumes: `corePath()`.
- Produces: migration sources `app` (DEFAULT priority, `core/database/migrations`) and `app:dependent` (DEPENDENT priority) — the SAME source names existing databases carry.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Integration/Setup/CoreMigrationSourcesTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Database\Migrations\MigrationManager;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The product's migrations moved under core/ but their ledger SOURCE names did not: a beta.20
 * database must show nothing pending after the move (the pre-beta.3 ledger break must not repeat).
 */
final class CoreMigrationSourcesTest extends AppTestCase
{
    public function testCoreMigrationsAreRegisteredUnderTheHistoricalSources(): void
    {
        $manager = $this->container()->get(MigrationManager::class);
        self::assertTrue($manager->hasSource('app:dependent'));

        $root = dirname(__DIR__, 3);
        self::assertCount(0, glob("$root/database/migrations/*.php"), 'database/migrations is the operator\'s');
        self::assertGreaterThanOrEqual(21, count(glob("$root/core/database/migrations/*.php")));
        self::assertGreaterThanOrEqual(11, count(glob("$root/core/database/dependent-migrations/*.php")));
    }

    public function testNothingIsPendingOnTheMigratedTestDatabase(): void
    {
        $manager = $this->container()->get(MigrationManager::class);
        self::assertSame([], $manager->getPendingMigrations(), 'a moved file must not look like a new migration');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Setup/CoreMigrationSourcesTest.php`
Expected: the first test FAILS on the directory assertions.

- [ ] **Step 3: Move the files and register both tiers from the provider**

```bash
mkdir -p core/database
git mv database/migrations core/database/migrations
git mv database/dependent-migrations core/database/dependent-migrations
mkdir -p database/migrations && touch database/migrations/.gitkeep
```

In `boot()`, replace the existing dependent-tier call with:

```php
        // Thallo's migrations live under core/. The ledger SOURCE names are the historical ones
        // ('app' for the default tier — the same name the framework gives the root
        // database/migrations directory, which is now the operator's and empty — and
        // 'app:dependent' for rows seeded after Aegis' RBAC tables), so a database migrated by
        // any earlier release shows nothing pending after the move.
        $this->loadMigrationsFrom(self::corePath('database/migrations'), MigrationPriority::DEFAULT, 'app');
        $this->loadMigrationsFrom(
            self::corePath('database/dependent-migrations'),
            MigrationPriority::DEPENDENT,
            'app:dependent',
        );
```

In `scripts/run-test-migrations.php`, after the `MigrationManager` is constructed:

```php
$manager->addMigrationPath($root . '/core/database/migrations', MigrationPriority::DEFAULT, 'app');
$manager->addMigrationPath($root . '/core/database/dependent-migrations', MigrationPriority::DEPENDENT, 'app:dependent');
```

and remove any existing line that added `database/dependent-migrations` from the root.

- [ ] **Step 4: Prove the ledger on a fresh AND an existing database**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test:migrate && vendor/bin/phpunit tests/Integration/Setup/CoreMigrationSourcesTest.php`
Expected: PASS. Then, against the local dev database that was migrated before this task:

Run: `php glueful migrate:verify`
Expected: every source Ready, nothing pending. If `app` shows the 21 files pending, the source name is wrong — fix the provider, not the ledger.

- [ ] **Step 5: Full suite, then commit**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test && composer phpcs`
Expected: OK.

```bash
git add -A
git commit -m "refactor(core): load Thallo's migrations from core/database under the historical ledger sources"
```

---

### Task 5: Config defaults come from `core/config`

**Files:**
- Move: `config/thallo.php`, `config/forms.php`, `config/signup.php`, `config/theme.php`, `config/i18n.php`, `config/tenancy.php`, `config/import_export.php` → `core/config/`.
- Modify: `core/src/Providers/ThalloServiceProvider.php::register()` — merge each as defaults; class docblock paragraph about config.
- Modify: `config/schedule.php`, `config/documentation.php` (they reference `Thallo\Core\` classes after Task 1 — they stay in the root because they are framework-shaped files; no move).
- Test: `tests/Integration/Setup/CoreConfigDefaultsTest.php`

**Interfaces:**
- Produces: `config('thallo.*')` etc. resolved from `core/config/*.php` when the root has no such file; a root `config/thallo.php` (or `config/testing/thallo.php`) still wins key by key.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Integration/Setup/CoreConfigDefaultsTest.php
declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Thallo\Core\Tests\Support\AppTestCase;

use function config;

final class CoreConfigDefaultsTest extends AppTestCase
{
    public function testProductConfigResolvesFromCoreDefaults(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['thallo', 'forms', 'signup', 'theme', 'i18n', 'tenancy', 'import_export'] as $name) {
            self::assertFileExists("$root/core/config/$name.php");
            self::assertFileDoesNotExist("$root/config/$name.php", "$name.php is a core default now");
        }
        self::assertSame('/v1/admin', config($this->appContext(), 'thallo.admin.api_base'));
        self::assertIsArray(config($this->appContext(), 'thallo.capabilities'));
    }

    public function testARootOverrideStillWinsKeyByKey(): void
    {
        // config/testing/thallo.php is the suite's overlay; it must keep winning over core defaults.
        $root = dirname(__DIR__, 3);
        self::assertFileExists("$root/config/testing/extensions.php", 'sanity: the overlay dir exists');
        $ctx = $this->appContext();
        $ctx->mergeConfigDefaults('thallo', ['probe' => ['from' => 'core']]);
        $ctx->overrideConfig('thallo.probe.from', 'root');
        self::assertSame('root', config($ctx, 'thallo.probe.from'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Setup/CoreConfigDefaultsTest.php`
Expected: FAIL — `core/config/thallo.php` missing.

- [ ] **Step 3: Move the files and merge them as defaults**

```bash
mkdir -p core/config
for f in thallo forms signup theme i18n tenancy import_export; do git mv config/$f.php core/config/$f.php; done
```

In `core/config/thallo.php`, the admin bundle default becomes `dirname(__DIR__) . '/resources/admin'` (Task 6 creates it; until then the build still lands in `public/admin`, so keep the OLD default for this task and change it in Task 6).

In `register()`, before `$this->commands([...])`:

```php
        // Thallo's configuration ships as DEFAULTS from core/config: the operator's config/
        // directory holds only overrides, so a new key in a new release reaches every install
        // without touching their files. Root files and environment overlays still win key by key.
        foreach (['thallo', 'forms', 'signup', 'theme', 'i18n', 'tenancy', 'import_export'] as $name) {
            /** @var array<string,mixed> $defaults */
            $defaults = require self::corePath("config/{$name}.php");
            $this->mergeConfig($name, $defaults);
        }
```

Replace the class docblock paragraph beginning "Config: config/thallo.php lives in the app config directory" with one sentence: "Config: core/config/*.php are merged as defaults in register(); the root config/ is the operator's overrides."

Check `config/development/` and `config/testing/` overlays for the seven names — they stay where they are (overlays of the operator's config dir) and keep winning.

- [ ] **Step 4: Run the test, then the suites that read these configs**

Run: `vendor/bin/phpunit tests/Integration/Setup tests/Integration/Capabilities tests/Integration/Tenancy tests/Integration/Forms tests/Integration/Signup && composer phpcs`
Expected: PASS. A test failing on a config value read BEFORE providers register (framework boot) means that key must stay in a root file — move only that file back and note why in the provider comment.

- [ ] **Step 5: Full suite, then commit**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test`
Expected: OK.

```bash
git add -A
git commit -m "refactor(core): Thallo's config ships as core/config defaults; config/ is the operator's overrides"
```

---

### Task 6: The admin bundle is built into `core/resources/admin`

**Files:**
- Modify: `admin/vite.config.ts` (`outDir`), `core/config/thallo.php` (`bundle_path` default), `.gitignore` (`/public/admin/` → `/core/resources/admin/`), `scripts/release-bake` (build check + `git add -f core/resources/admin`), `scripts/verify-dist-archive` (`must_contain "core/resources/admin/index.html"`, `BUNDLE_JS` archive path), `docs/internal/RELEASING.md` and `docs/internal/DISTRIBUTION.md` decision 9 wording (path only).
- Remove from git: the currently tracked `public/admin/**` (the beta.20 bake) — `git rm -r --cached public/admin` is NOT enough; the files must go so a stale bundle can never be served from `public/admin`: `git rm -r public/admin`.
- Test: `tests/Integration/Http/AdminSpaServingTest.php` (existing) gains one assertion; `tests/Unit/Scripts/VerifyDistArchiveTest.php` (new, reads the script text).

**Interfaces:**
- Produces: `thallo.admin.bundle_path` default `core/resources/admin`; `serveFrontend('/admin', <that>)` unchanged.

- [ ] **Step 1: Write the failing tests**

Add to `AdminSpaServingTest`:

```php
    public function testTheBundleDefaultLivesUnderCore(): void
    {
        $default = (string) config($this->appContext(), 'thallo.admin.bundle_path');
        self::assertStringEndsWith('/core/resources/admin', $default);
        self::assertDirectoryDoesNotExist(dirname(__DIR__, 3) . '/public/admin', 'public/ is the operator\'s');
    }
```

New `tests/Unit/Scripts/VerifyDistArchiveTest.php`:

```php
<?php
declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class VerifyDistArchiveTest extends TestCase
{
    public function testTheReleaseScriptsBakeAndCheckTheBundleUnderCore(): void
    {
        $root = dirname(__DIR__, 3);
        $verify = (string) file_get_contents("$root/scripts/verify-dist-archive");
        $bake = (string) file_get_contents("$root/scripts/release-bake");
        self::assertStringContainsString('must_contain "core/resources/admin/index.html"', $verify);
        self::assertStringContainsString('core/resources/admin/assets', $verify);
        self::assertStringContainsString('git add -f core/resources/admin', $bake);
        self::assertStringNotContainsString('public/admin', $verify . $bake);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/Http/AdminSpaServingTest.php tests/Unit/Scripts/VerifyDistArchiveTest.php`
Expected: FAIL on the new assertions.

- [ ] **Step 3: Move the build target and every script that names it**

`admin/vite.config.ts`: `outDir: fileURLToPath(new URL('../core/resources/admin', import.meta.url))`.
`core/config/thallo.php`: `'bundle_path' => env('ADMIN_BUNDLE_PATH', dirname(__DIR__) . '/resources/admin')`.
`.gitignore`: replace `/public/admin/` with `/core/resources/admin/`.
`scripts/release-bake`: every `public/admin` → `core/resources/admin`.
`scripts/verify-dist-archive`: `must_contain "core/resources/admin/index.html"`; `git archive "$REF" core/resources/admin/assets`.
`git rm -r public/admin`.
Docs: `docs/internal/RELEASING.md` and decision 9 in `DISTRIBUTION.md`: the path only.

Then `cd admin && pnpm run build && cd ..` and confirm `core/resources/admin/index.html` exists and `git status` shows it ignored.

- [ ] **Step 4: Run the tests and the admin gates**

Run: `vendor/bin/phpunit tests/Integration/Http/AdminSpaServingTest.php tests/Unit/Scripts && (cd admin && npm run -s type-check && npx vitest run && npm run -s build-only)`
Expected: PASS everywhere; the SPA serves at `/admin` from the new path (AdminSpaServingTest covers it).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(core): the admin bundle builds into core/resources/admin; public/ is the operator's"
```

---

### Task 7: Rename the provider, document, full gates, merge

**Files:**
- Rename: `core/src/Providers/ThalloServiceProvider.php` → `CoreServiceProvider.php` (class `Thallo\Core\Providers\CoreServiceProvider`); update `config/serviceproviders.php`, every `use`/reference (`grep -rn ThalloServiceProvider core tests config scripts`), the two tests from Tasks 2–3.
- Modify: `CHANGELOG.md` (Unreleased), `docs/internal/plans/2026-09-12-composer-updatable-thallo.md` (phase 2 status), `README.md` (one line: "Thallo's code lives in `core/`; `app/`, `routes/`, `database/migrations/` are yours").
- Test: `NamespaceMoveTest` from Task 1 asserts `CoreServiceProvider` exists instead.

- [ ] **Step 1: Update the test, watch it fail, rename, watch it pass**

In `NamespaceMoveTest`: `self::assertTrue(class_exists(\Thallo\Core\Providers\CoreServiceProvider::class));`

Run: `vendor/bin/phpunit tests/Unit/Support/NamespaceMoveTest.php` → FAIL.

```bash
git mv core/src/Providers/ThalloServiceProvider.php core/src/Providers/CoreServiceProvider.php
grep -rl ThalloServiceProvider core tests config scripts docs/internal | xargs sed -i '' 's/ThalloServiceProvider/CoreServiceProvider/g'
rm -f bootstrap/cache/extensions.php
composer dump-autoload
```

Run again → PASS.

- [ ] **Step 2: Changelog and docs**

`CHANGELOG.md` under `## [Unreleased]`:

```
### Changed
- **Thallo's application now lives under `core/`, namespace `Thallo\Core`.** `app/`, `routes/`
  and `database/migrations/` at the root are the operator's own (empty on a fresh install);
  Thallo loads its routes, migrations (under the historical ledger sources `app` and
  `app:dependent` — nothing re-runs), config defaults (`core/config`, overridable key by key
  from `config/`) and the admin bundle (`core/resources/admin`) through its core provider.
  This is phase 2 of making Thallo Composer-updatable (docs/internal decision 10); the package
  itself is still installed with `create-project` in this release.

### Upgrade Notes
- If you customised any file under `app/`, `routes/` or `database/migrations/` of a previous
  release, those directories are now yours and start empty: re-apply customisations against
  `core/` only through overrides in `config/` and your own files. `thallo:provision` after
  deploying reports zero pending migrations.
```

- [ ] **Step 3: Every gate on the final tree**

Run, one at a time: `composer phpcs`, `composer boundaries`, `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:distribution`, `(cd admin && npm run -s type-check && npm run -s lint && npx vitest run && npm run -s build-only)`, then `git checkout -- core/resources/admin 2>/dev/null; git status --short` must be clean apart from ignored build output.

Expected: all green.

- [ ] **Step 4: Commit and merge**

```bash
git add -A
git commit -m "refactor(core): CoreServiceProvider; changelog and docs for the core/ layout"
git checkout dev
git merge --no-ff package-split -m "Merge package-split: Thallo's application under core/ (phase 2 of the Composer-updatable split)"
```

Do not push. Report the merge commit.

---

## Out of scope for this plan (separate plans)

- **Phase 3 — publishing the split**: `core/composer.json` as `glueful/thallo-core` (type `glueful-extension`, provider via `extra.glueful.provider`), `packages/` moved under `core/`, the skeleton `composer.json`, subtree-split release script, two dist gates, `docs/upgrading.md` rewritten to `composer update`. Written after this plan lands, from the shape it produces.
- **Phase 4 — update notice**: scheduled Packagist check, `update` in `/admin/config`, admin badge/card, `UPDATE_CHECK_ENABLED`. Depends on phase 3's package name.
