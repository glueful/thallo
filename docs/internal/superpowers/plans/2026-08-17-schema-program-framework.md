# Schema-on-Enable Program — Plan 1 of 3: Framework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the framework half of the schema-on-enable program (spec
`docs/internal/superpowers/specs/2026-08-17-schema-creation-policy-design.md`, Section B):
manifest migration descriptors as the sole inventory, checksum-driven readiness, receipt
normalization, serialized/transactional execution, the core-owned enable operation machine,
and CLI/HTTP wiring through one executor.

**Architecture:** A new `Glueful\Extensions\Schema` namespace owns descriptors, inventory,
readiness, normalization, and the enable executor. `MigrationManager` gains descriptor-scoped
execution but keeps its lazy-ledger contract (1.78.4). The existing `Glueful\Lock\LockManager`
provides serialization; the existing ledger `checksum` column (`hash_file('sha256', …)`)
provides receipt identity. `ExtensionStateWriter` becomes executor-internal.

**Tech Stack:** PHP 8.3, PHPUnit 10 (framework library harness: plain `TestCase` + SQLite
`Connection`), phpstan, phpcs (PSR-12). Repo: `/Users/michaeltawiahsowah/Sites/glueful/framework`,
branch off `dev`.

**Plans 2 and 3** (first-party extension adoption; Thallo program) are written after this
plan ships and pins the real APIs. This plan is complete, shippable framework software on its
own: with no package declaring descriptors, behavior is unchanged except where the spec says
otherwise (core leaves unconditional; production refusals removed).

## Global Constraints

- All work in `/Users/michaeltawiahsowah/Sites/glueful/framework` on a branch off `dev`; commit locally, never push.
- No `Co-Authored-By` trailers in commits.
- Gates for every task: the named test file green; before the final task: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpstan`, `composer phpcs` all green.
- The 1.78.4 lazy-ledger contract is inviolable: construction/registration of `MigrationManager` performs zero DB work; only `migrate()` creates the ledger; reads/rollback are ledger-absent-safe.
- Nothing ever drops a table. No path may run DDL at boot.
- Descriptor mode enum values are exactly `core` and `on_enable`. Priority values are the existing `MigrationPriority` constants: `FOUNDATION`(-200), `IDENTITY`(-100), `DEFAULT`(0), `DEPENDENT`(100).
- Ledger receipt identity = `(source, migration-basename)` with `checksum` = `hash_file('sha256', file)` — already what `runMigration()` records; never change the stored format.
- Version target: `1.79.0` (new APIs, behavior changes called out in CHANGELOG).

---

### Task 1: `DescriptorMode` enum and `MigrationDescriptor` value object

**Files:**
- Create: `src/Extensions/Schema/DescriptorMode.php`
- Create: `src/Extensions/Schema/MigrationDescriptor.php`
- Create: `src/Extensions/Schema/DescriptorValidationException.php`
- Test: `tests/Unit/Extensions/Schema/MigrationDescriptorTest.php`

**Interfaces:**
- Consumes: `Glueful\Database\Migrations\MigrationPriority` constants (existing).
- Produces: `DescriptorMode::Core|OnEnable` (string-backed `'core'|'on_enable'`);
  `MigrationDescriptor` readonly VO with
  `__construct(string $id, string $package, string $packageType, string $relativePath, int $priority, DescriptorMode $mode, array $legacyAliases = [])`,
  `source(): string` (returns `$package . ':' . $id` unless `$id === 'default'`, then `$package`),
  `absolutePath(string $packageDir): string` (validated join);
  `DescriptorValidationException extends \InvalidArgumentException`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Extensions/Schema/MigrationDescriptorTest.php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Schema\DescriptorMode;
use Glueful\Extensions\Schema\DescriptorValidationException;
use Glueful\Extensions\Schema\MigrationDescriptor;
use PHPUnit\Framework\TestCase;

final class MigrationDescriptorTest extends TestCase
{
    private function descriptor(
        string $id = 'default',
        string $type = 'glueful-extension',
        string $path = 'migrations',
        DescriptorMode $mode = DescriptorMode::OnEnable,
        array $aliases = [],
    ): MigrationDescriptor {
        return new MigrationDescriptor(
            id: $id,
            package: 'acme/widgets',
            packageType: $type,
            relativePath: $path,
            priority: MigrationPriority::DEFAULT,
            mode: $mode,
            legacyAliases: $aliases,
        );
    }

    public function testSourceIsPackageNameForTheDefaultDescriptor(): void
    {
        self::assertSame('acme/widgets', $this->descriptor()->source());
    }

    public function testSourceIsPackageColonIdForNamedDescriptors(): void
    {
        self::assertSame('acme/widgets:tenant', $this->descriptor(id: 'tenant')->source());
    }

    public function testOnEnableRequiresExtensionType(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(type: 'library', mode: DescriptorMode::OnEnable);
    }

    public function testCoreModeIsValidForLibraries(): void
    {
        $d = $this->descriptor(type: 'library', mode: DescriptorMode::Core);
        self::assertSame(DescriptorMode::Core, $d->mode);
    }

    public function testTraversalPathsAreRejected(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(path: '../outside');
    }

    public function testAbsolutePathsAreRejected(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(path: '/etc/passwd');
    }

    public function testAbsolutePathJoinRefusesEscapeViaSymlinkFreeCheck(): void
    {
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($dir . '/migrations', 0777, true);
        $d = $this->descriptor();
        self::assertSame($dir . '/migrations', $d->absolutePath($dir));
        rmdir($dir . '/migrations');
        rmdir($dir);
    }

    public function testEmptyOrInvalidIdIsRejected(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(id: 'Bad Id!');
    }

    public function testAliasListMustBeUniqueStrings(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(aliases: ['legacy', 'legacy']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Extensions/Schema/MigrationDescriptorTest.php`
Expected: ERROR — class `MigrationDescriptor` not found.

- [ ] **Step 3: Implement**

```php
<?php
// src/Extensions/Schema/DescriptorMode.php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

enum DescriptorMode: string
{
    case Core = 'core';
    case OnEnable = 'on_enable';
}
```

```php
<?php
// src/Extensions/Schema/DescriptorValidationException.php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

final class DescriptorValidationException extends \InvalidArgumentException
{
}
```

```php
<?php
// src/Extensions/Schema/MigrationDescriptor.php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * One manifest-declared migration source (spec B1). Identity is (package, id) — a package may
 * declare several descriptors (multiple tracks); the ledger `source` derives from that identity,
 * with the 'default' id collapsing to the bare package name so existing single-track receipts
 * keep their identity.
 */
final class MigrationDescriptor
{
    /** @param list<string> $legacyAliases Ledger sources this descriptor also answers for. */
    public function __construct(
        public readonly string $id,
        public readonly string $package,
        public readonly string $packageType,
        public readonly string $relativePath,
        public readonly int $priority,
        public readonly DescriptorMode $mode,
        public readonly array $legacyAliases = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) !== 1) {
            throw new DescriptorValidationException("Descriptor id '{$id}' is not a lowercase slug.");
        }
        if ($mode === DescriptorMode::OnEnable && $packageType !== 'glueful-extension') {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' declares on_enable but package type is '{$packageType}'; "
                . 'only glueful-extension packages have an enable event (library schemas are core).'
            );
        }
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' path '{$relativePath}' must be relative and traversal-free."
            );
        }
        $seen = [];
        foreach ($legacyAliases as $alias) {
            if (!is_string($alias) || $alias === '' || isset($seen[$alias])) {
                throw new DescriptorValidationException(
                    "Descriptor '{$package}:{$id}' legacy aliases must be unique non-empty strings."
                );
            }
            $seen[$alias] = true;
        }
    }

    public function source(): string
    {
        return $this->id === 'default' ? $this->package : $this->package . ':' . $this->id;
    }

    public function absolutePath(string $packageDir): string
    {
        $joined = rtrim($packageDir, '/') . '/' . $this->relativePath;
        // Constructor bans '..' and absolute paths, so the join cannot escape $packageDir.
        return $joined;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/Unit/Extensions/Schema/MigrationDescriptorTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/Schema tests/Unit/Extensions/Schema
git commit -m "feat(extensions): migration descriptor value object with closed mode/path/alias validation"
```

---

### Task 2: `PackageManifest::migrationDescriptors()` — the all-package projection

**Files:**
- Modify: `src/Extensions/PackageManifest.php` (append method; do not touch `getCandidates()`)
- Test: `tests/Unit/Extensions/Schema/ManifestMigrationDescriptorsTest.php`

**Interfaces:**
- Consumes: Task 1's `MigrationDescriptor`, `DescriptorMode`, `DescriptorValidationException`;
  `PackageManifest`'s existing private `rawPackages(): array` (package name => composer package array,
  including `type`, `extra`, and — from installed.json — `install-path`).
- Produces: `public function migrationDescriptors(): array` — map of
  `package name => list<MigrationDescriptor>`; throws `DescriptorValidationException` on any
  fail-closed violation. Manifest shape it parses (spec B1), inside `extra.glueful`:

```json
"extra": { "glueful": {
  "provider": "Acme\\Widgets\\WidgetsServiceProvider",
  "migrations": [
    { "id": "default", "path": "migrations", "priority": "dependent",
      "mode": "on_enable", "legacyAliases": ["acme-widgets"] }
  ]
}}
```

  or `"migrations": "none"`. Priority strings map: `foundation|identity|default|dependent` →
  `MigrationPriority` constants.

- [ ] **Step 1: Write the failing tests**

Test fixtures are hand-built `installed.json` files (the class's documented fallback path reads
them); follow the existing pattern in `tests/Unit/Extensions/` for pointing `PackageManifest` at a
fixture vendor dir (see how existing tests construct `ApplicationContext` with a temp base path —
mirror the nearest existing `PackageManifest` test's setup verbatim; if none exists, build the
context the way `tests/Unit/Extensions/ExtensionResolverTest.php` builds its inputs and write the
fixture to `<base>/vendor/composer/installed.json`).

```php
<?php
// tests/Unit/Extensions/Schema/ManifestMigrationDescriptorsTest.php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorMode;
use Glueful\Extensions\Schema\DescriptorValidationException;
use PHPUnit\Framework\TestCase;

final class ManifestMigrationDescriptorsTest extends TestCase
{
    /** Writes installed.json with the given packages and returns a PackageManifest over it. */
    private function manifest(array $packages): PackageManifest
    {
        // ... temp dir + vendor/composer/installed.json {"packages": $packages} + context; see Step 1 note.
    }

    private function extensionPkg(array $glueful, string $type = 'glueful-extension'): array
    {
        return [
            'name' => 'acme/widgets', 'type' => $type, 'install-path' => '../acme/widgets',
            'extra' => ['glueful' => $glueful + ['provider' => 'Acme\\Widgets\\P']],
        ];
    }

    public function testDescriptorsAreProjectedWithModeAndPriority(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'dependent', 'mode' => 'on_enable'],
        ]])]);
        $d = $m->migrationDescriptors()['acme/widgets'][0];
        self::assertSame('acme/widgets', $d->source());
        self::assertSame(100, $d->priority);
        self::assertSame(DescriptorMode::OnEnable, $d->mode);
    }

    public function testMigrationsNoneYieldsEmptyList(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => 'none'])]);
        self::assertSame([], $m->migrationDescriptors()['acme/widgets']);
    }

    public function testProviderDeclaringPackageWithoutMigrationsKeyFailsClosed(): void
    {
        $m = $this->manifest([$this->extensionPkg([])]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testArbitraryComposerDependencyIsIgnored(): void
    {
        $m = $this->manifest([[
            'name' => 'monolog/monolog', 'type' => 'library', 'install-path' => '../monolog/monolog',
            'extra' => [],
        ]]);
        self::assertArrayNotHasKey('monolog/monolog', $m->migrationDescriptors());
    }

    public function testLibraryPackWithGluefulMigrationsBlockMustBeCore(): void
    {
        $m = $this->manifest([[
            'name' => 'glueful/thallo-commerce', 'type' => 'library', 'install-path' => '../glueful/thallo-commerce',
            'extra' => ['glueful' => ['migrations' => [
                ['id' => 'default', 'path' => 'migrations', 'priority' => 'dependent', 'mode' => 'on_enable'],
            ]]],
        ]]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }

    public function testUnknownPriorityOrModeFailsClosed(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'urgent', 'mode' => 'on_enable'],
        ]])]);
        $this->expectException(DescriptorValidationException::class);
        $m->migrationDescriptors();
    }
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Unit/Extensions/Schema/ManifestMigrationDescriptorsTest.php` → ERROR (method undefined).

- [ ] **Step 3: Implement** — append to `PackageManifest`:

```php
    /**
     * All-package migration inventory (spec B1). Any package with an extra.glueful block that
     * declares a provider OR a migrations key participates; each must declare descriptors or
     * "none" — fail closed. Arbitrary composer dependencies (no extra.glueful) are ignored.
     *
     * @return array<string, list<Schema\MigrationDescriptor>>
     */
    public function migrationDescriptors(): array
    {
        $priorities = [
            'foundation' => \Glueful\Database\Migrations\MigrationPriority::FOUNDATION,
            'identity' => \Glueful\Database\Migrations\MigrationPriority::IDENTITY,
            'default' => \Glueful\Database\Migrations\MigrationPriority::DEFAULT,
            'dependent' => \Glueful\Database\Migrations\MigrationPriority::DEPENDENT,
        ];
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $glueful = $pkg['extra']['glueful'] ?? null;
            if (!is_array($glueful)) {
                continue; // not a Glueful package — out of contract scope
            }
            $migrations = $glueful['migrations'] ?? null;
            if ($migrations === null) {
                throw new Schema\DescriptorValidationException(
                    "Package {$name} has extra.glueful but no 'migrations' declaration; "
                    . "declare descriptors or \"none\" (fail-closed, spec B1)."
                );
            }
            if ($migrations === 'none') {
                $out[(string) $name] = [];
                continue;
            }
            if (!is_array($migrations)) {
                throw new Schema\DescriptorValidationException("Package {$name}: 'migrations' must be a list or \"none\".");
            }
            $list = [];
            foreach ($migrations as $row) {
                $priorityKey = (string) ($row['priority'] ?? '');
                $mode = Schema\DescriptorMode::tryFrom((string) ($row['mode'] ?? ''));
                if (!isset($priorities[$priorityKey]) || $mode === null) {
                    throw new Schema\DescriptorValidationException(
                        "Package {$name}: descriptor priority/mode must use the closed enums."
                    );
                }
                $list[] = new Schema\MigrationDescriptor(
                    id: (string) ($row['id'] ?? ''),
                    package: (string) $name,
                    packageType: (string) ($pkg['type'] ?? 'library'),
                    relativePath: (string) ($row['path'] ?? ''),
                    priority: $priorities[$priorityKey],
                    mode: $mode,
                    legacyAliases: array_values((array) ($row['legacyAliases'] ?? [])),
                );
            }
            $out[(string) $name] = $list;
        }
        ksort($out);
        return $out;
    }
```

- [ ] **Step 4: Run to verify pass** — same file → PASS (6 tests).
- [ ] **Step 5: Commit** — `git add -A src tests && git commit -m "feat(extensions): manifest migrationDescriptors() all-package projection, fail-closed"`

---

### Task 3: `DescriptorInventory` — validated global inventory

**Files:**
- Create: `src/Extensions/Schema/DescriptorInventory.php`
- Test: `tests/Unit/Extensions/Schema/DescriptorInventoryTest.php`

**Interfaces:**
- Consumes: Task 2's `migrationDescriptors()` output plus per-package install dirs
  (`PackageManifest` also exposes install paths via `rawPackages()`'s `install-path`; add a small
  `public function installPaths(): array` — package => absolute dir — in this task).
- Produces: `DescriptorInventory::fromManifest(PackageManifest $manifest): self`;
  `all(): list<MigrationDescriptor>`; `bySource(string $source): ?MigrationDescriptor`;
  `forPackage(string $package): list<MigrationDescriptor>`;
  `pathOf(MigrationDescriptor $d): string` (absolute, existence-checked, non-empty-checked);
  `aliasIndex(): array<string,string>` (alias → source, ambiguity fails closed).

- [ ] **Step 1: Write the failing tests** — cover (real temp dirs with one dummy `001_X.php` migration file per descriptor path):
  duplicate descriptor sources across packages fail closed; duplicate canonical paths fail closed;
  one alias claimed by two descriptors fails closed; an alias equal to another descriptor's source
  fails closed; a declared path that does not exist fails closed; a declared path with zero
  migration files fails closed (message: “empty schema declares migrations: none”); the happy
  path indexes by source and package.

```php
public function testDuplicateAliasAcrossDescriptorsFailsClosed(): void
{
    // two packages, both alias 'thallo-commerce' -> DescriptorValidationException
}
public function testDeclaredPathMustExistAndContainAMigration(): void
{
    // path exists but empty dir -> DescriptorValidationException mentioning "migrations: none"
}
```
(Write every listed case as a real test method with real fixtures — same builder helper style as Task 2.)

- [ ] **Step 2: Run to verify failure** → ERROR (class not found).
- [ ] **Step 3: Implement** — construction walks all descriptors once, building `bySource`,
  canonical-path set (`realpath`), and `aliasIndex`; every collision throws
  `DescriptorValidationException` naming both claimants. `pathOf()` re-checks `is_dir` and
  glob(`*.php`) non-empty.
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): descriptor inventory — duplicate/alias/path collisions fail closed"`

---

### Task 4: Descriptor-driven registration; `loadMigrationsFrom()` validates, never appends

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` (add `registerDescriptor()`)
- Modify: `src/Extensions/ServiceProvider.php:190-201` (`loadMigrationsFrom()`)
- Test: `tests/Unit/Extensions/Schema/SingleInventoryTest.php`

**Interfaces:**
- Consumes: Tasks 1–3; `MigrationManager::addMigrationPath(string $path, int $priority, ?string $source)` (existing).
- Produces: `MigrationManager::registerDescriptor(MigrationDescriptor $d, string $absolutePath): void`
  (delegates to `addMigrationPath($absolutePath, $d->priority, $d->source())`, remembering the
  registration in a `array<string, string>` source→path map);
  `MigrationManager::hasSource(string $source): bool`;
  `loadMigrationsFrom()` new behavior: if the container has a `DescriptorInventory` and the dir's
  canonical path matches a descriptor of the calling provider's package, the call VALIDATES
  (source and priority must match the descriptor exactly — mismatch throws
  `DescriptorValidationException`) and returns without registering; only when no inventory entry
  covers the path does the legacy append run (that legacy path is what Plans 2/3 remove).

- [ ] **Step 1: Write the failing tests** — a fake inventory with one descriptor; assert
  (a) `loadMigrationsFrom()` on the described path registers nothing new (pending list unchanged
  after a prior `registerDescriptor()` — no double execution: one physical file appears once in
  `getPendingMigrations()`), (b) a mismatched legacy source in the provider call throws, (c) an
  undescribed path still appends (back-compat), (d) `registerDescriptor()` + `getMigrationStatus()`
  performs no DDL (ledger-less DB stays at zero tables — reuse the `LazyLedgerContractTest` sqlite
  harness pattern).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.** (In `ServiceProvider`, resolve the inventory via
  `$this->app->has(DescriptorInventory::class)` — absent inventory keeps legacy behavior, so
  nothing breaks before Task 8 wires it into the container.)
- [ ] **Step 4: Run to verify pass** (including the whole existing `tests/Unit/Database/Migrations` suite).
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): descriptor-scoped registration; loadMigrationsFrom validates against inventory instead of appending"`

---

### Task 5: Core leaves unconditional — remove the config-gated second policy

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php:504-560` (the `MigrationManager` factory)
- Test: `tests/Unit/Container/CoreMigrationLeavesTest.php`

**Interfaces:**
- Consumes: existing leaf dirs `migrations/{auth,locks,metrics,notifications,queue,scheduler,uploads}`;
  sources stay exactly `'glueful/framework'` (auth) and `'glueful/framework:<leaf>'` (others), all
  `MigrationPriority::FOUNDATION` — receipt identities must not change (spec B1 legacy-identity rule).
- Produces: the factory registers **all seven leaves unconditionally** (spec B2: config flags
  govern runtime behavior, not schema presence). Delete the `$gates` array and its config reads.

- [ ] **Step 1: Write the failing test** — build the container-produced `MigrationManager` (or
  replicate the factory's registration against a bare manager if container bootstrap is heavy —
  mirror how existing `CoreProvider` tests exercise definitions), assert `hasSource()` is true for
  all seven sources with locks/queue/uploads config gates OFF.
- [ ] **Step 2: Run to verify failure** (gated leaves absent).
- [ ] **Step 3: Implement** — replace the `$gates` map with a literal list of the seven leaves; keep
  the source-naming and FOUNDATION priority code identical.
- [ ] **Step 4: Run to verify pass**, plus `vendor/bin/phpunit tests/Integration/Database/Migrations`.
- [ ] **Step 5: Commit** — `git commit -m "feat(core): all framework migration leaves are core schema — config governs runtime, not schema presence"`

---

### Task 6: `SchemaReadiness` — checksum-verified readiness classification

**Files:**
- Create: `src/Extensions/Schema/SchemaReadiness.php`
- Create: `src/Extensions/Schema/ReadinessState.php`
- Test: `tests/Unit/Extensions/Schema/SchemaReadinessTest.php`

**Interfaces:**
- Consumes: `DescriptorInventory` (Task 3); a `Connection`; ledger rows
  `(migration, source, checksum)`; `hash_file('sha256', …)` file identity.
- Produces: `enum ReadinessState: string { case Ready = 'ready'; case Pending = 'pending'; case Divergent = 'divergent'; }`;
  `SchemaReadiness::__construct(Connection $db, DescriptorInventory $inventory)`;
  `classify(MigrationDescriptor $d): ReadinessState`;
  `explain(MigrationDescriptor $d): list<string>` (human reasons — used by CLI/SPA and Task 10's verify command).
  Rules (spec B3): every current file has a receipt under `source()` or a normalized alias with the
  **exact current SHA-256** → Ready; missing receipts only → Pending; checksum mismatch, receipt
  for a removed file, ambiguous identity, missing/empty path → Divergent. Ledger absent ⇒ Pending
  (never DDL — reads go through the 1.78.4-safe manager paths or a guarded direct query).

- [ ] **Step 1: Write the failing tests** — sqlite harness; seed ledger rows by hand; cases:
  all-receipts-match → Ready; no ledger table → Pending with zero DDL (assert `sqlite_master`
  unchanged); one file changed on disk → Divergent naming the file in `explain()`; receipt exists
  for a deleted file → Divergent; receipts under a legacy alias count once normalized flag is
  passed (constructor param `bool $aliasesNormalized = false` — pre-normalization, alias receipts
  classify as Divergent with reason “run receipts:normalize”).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): checksum-driven schema readiness (ready/pending/divergent)"`

---

### Task 7: `ReceiptNormalizer` — alias receipts to descriptor identity

**Files:**
- Create: `src/Extensions/Schema/ReceiptNormalizer.php`
- Create: `src/Console/Commands/Migrate/NormalizeReceiptsCommand.php` (name: `migrate:normalize-receipts`)
- Test: `tests/Unit/Extensions/Schema/ReceiptNormalizerTest.php`

**Interfaces:**
- Consumes: `DescriptorInventory::aliasIndex()`; ledger rows; `SchemaReadiness` afterwards.
- Produces: `ReceiptNormalizer::normalize(bool $dryRun = false): NormalizationReport` where
  `NormalizationReport` is a readonly VO `{ rewritten: list<array{source,alias,migration}>, refused: list<array{alias,reason}> }`.
  Rules (spec B1): rewrite `source = alias → descriptor source` only when the row's stored
  checksum equals the current file's SHA-256 in the descriptor path; ambiguous alias (two
  descriptors, or alias colliding with a live source that also has rows) → refused, never
  rewritten; runs inside a transaction; serialized by the Task 8 lock.

- [ ] **Step 1: Write the failing tests** — checksum-match rewrites; checksum mismatch refuses with
  reason; ambiguous alias refuses; dry-run reports without writing; idempotent second run rewrites nothing.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** (command is a thin wrapper: resolves services lazily in `execute()` —
  the 1.78.3 command pattern — prints the report table, `--dry-run` option).
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): checksum-verified receipt normalization with ambiguity refusal"`

---

### Task 8: Migration lock + container wiring of the Schema services

**Files:**
- Create: `src/Extensions/Schema/MigrationLockInterface.php`
- Create: `src/Extensions/Schema/LockManagerMigrationLock.php`
- Modify: `src/Container/Providers/CoreProvider.php` (register `DescriptorInventory`,
  `SchemaReadiness`, `ReceiptNormalizer`, `MigrationLockInterface` as lazy factories)
- Test: `tests/Unit/Extensions/Schema/MigrationLockTest.php`

**Interfaces:**
- Consumes: existing `Glueful\Lock\LockManagerInterface` (stores already exist: Redis, Database, file).
- Produces: `interface MigrationLockInterface { public function acquire(string $source, int $ttlSeconds = 600): \Glueful\Lock\LockInterface; }`
  — key format `schema:{source}`; **every** enable/disable/migrate/normalize/adopt path acquires it
  (spec B4). `LockManagerMigrationLock` adapts `LockManagerInterface`; acquisition failure throws
  `LockContentionException` (existing class) so callers surface "another schema operation is running".
  Container definitions are **factories** (lazy) — constructing them performs no DB work
  (global constraint).

- [ ] **Step 1: Write the failing tests** — acquire returns a held lock; second acquire on the same
  source throws `LockContentionException`; different sources don't contend; container boot with the
  new definitions performs zero queries (reuse the boot-spy assertion style from `LazyLedgerContractTest`).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): source-scoped migration lock over LockManager; schema services wired lazily"`

---

### Task 9: Transactional per-migration execution + `manual_repair`

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` (`runMigration()`, ~line 440-510)
- Test: `tests/Unit/Database/Migrations/TransactionalMigrationTest.php`

**Interfaces:**
- Consumes: `Connection` transaction API (`transaction(callable)` per existing usage at
  `Connection.php:908` region — verify exact method name `transaction()` in Step 0 by
  `grep -n "public function transaction" src/Database/Connection.php` and use what exists).
- Produces: on drivers whose PDO supports transactional DDL (`pgsql`, `sqlite`): each migration's
  `up()` **and its ledger insert run in one transaction** — a failure rolls back both, so no
  partial-DDL-without-receipt state exists (spec B4). On other drivers (`mysql`): behavior
  unchanged, but `runMigration()`'s failure return gains `'requiresManualRepair' => true` so Task 10's
  executor can persist the `manual_repair` state. Driver capability from
  `$this->db()->getDriverName()` (verify exact accessor by grep in Step 0; use what exists).

- [ ] **Step 0: Verify API names** — `grep -n "public function transaction\|getDriverName\|driver" src/Database/Connection.php` and adjust the two call sites in the test/implementation to the real names before writing them.
- [ ] **Step 1: Write the failing tests** — sqlite harness with a migration fixture whose `up()`
  creates a table then throws: assert afterwards the table does NOT exist and the ledger has NO row
  (transaction rolled back both); happy-path fixture: table exists AND receipt row exists; failure
  return shape includes `requiresManualRepair === false` for sqlite (transactional) and the flag
  logic unit-tested via a driver-name seam.
- [ ] **Step 2: Run to verify failure** (today the table survives and no receipt exists — the
  precise partial state the spec bans).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** + full `tests/Unit/Database/Migrations` + `tests/Integration/Database/Migrations`.
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): per-migration transaction couples DDL with its ledger receipt on transactional drivers"`

---

### Task 10: Operation table + `ExtensionSchemaExecutor` step machine

**Files:**
- Create: `migrations/extensions/001_CreateExtensionOperationsTable.php` (new core leaf; add
  `'extensions'` to Task 5's leaf list — source `glueful/framework:extensions`)
- Create: `src/Extensions/Schema/ExtensionOperation.php` (row VO + states)
- Create: `src/Extensions/Schema/ExtensionSchemaExecutor.php`
- Test: `tests/Integration/Extensions/ExtensionSchemaExecutorTest.php`

**Interfaces:**
- Consumes: everything above plus `ExtensionStateWriter` (becomes internal here),
  `EnabledProviders::from(context)`, `ExtensionResolver`, `ProtectedProviders::refusalFor()`,
  `PackageManifest::getCandidates()`.
- Produces: operation table `extension_operations`
  `(id, package, operation enable|disable, step, status running|succeeded|failed|manual_repair, actor, failed_migration nullable, error nullable, created_at, updated_at)`;
  `ExtensionSchemaExecutor::__construct(ApplicationContext $context, DescriptorInventory $inventory, MigrationManager $manager, SchemaReadiness $readiness, MigrationLockInterface $lock, ExtensionStateWriter $writer)`;
  `enable(string $package, string $actor): ExtensionOperation`;
  `disable(string $package, string $actor): ExtensionOperation`.
  Enable algorithm (spec B5/B2): resolve candidate (protected → refuse; dependency dry-resolve →
  refuse with ordered list) → acquire lock(s) for the package's descriptor sources → record
  operation `running` → migrate **pending core descriptors first, then only this package's
  `on_enable` descriptors** (explicit descriptor set — never global `migrate()`) → `SchemaReadiness`
  must classify Ready → `ExtensionStateWriter::enable()` → recompile provider cache (reuse the
  exact recompile call `EnableCommand` currently performs after writing — copy it) → operation
  `succeeded`. Any migrate failure: operation `failed` (or `manual_repair` when
  `requiresManualRepair`) with `failed_migration` recorded; enabled state is NOT written.
  Disable: protected → refuse; lock; `ExtensionStateWriter::disable()`; **no schema change ever**;
  cannot run while an enable operation on the same package is `running` (lock guarantees).

- [ ] **Step 1: Write the failing integration tests** (sqlite; fixture package with one on_enable
  descriptor + one migration): enable happy path (table created, receipt present, enabled list
  contains provider, operation row `succeeded`); enable with failing migration (enabled list
  unchanged, operation `failed`, `failed_migration` set); enable of an unrelated disabled
  extension's schema is untouched (source-scoped proof: second fixture package's migration not
  applied); concurrent enable/disable serialization (hold the lock manually, assert
  `LockContentionException`); disable preserves tables.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** (operation table access via the query builder on `Connection`; the
  operation row is written OUTSIDE the migration transaction so a rollback cannot erase the audit).
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): core-owned operation record + source-scoped enable executor (migrate-first, enable-last)"`

---

### Task 11: CLI and HTTP wiring through the executor; production enablement

**Files:**
- Modify: `src/Console/Commands/Extensions/EnableCommand.php` (route through executor; delete the
  `APP_ENV === 'production'` refusal at line 55; keep `--dry-run`/`--backup` for the config write)
- Modify: `src/Console/Commands/Extensions/DisableCommand.php` (same; refusal at line 56)
- Modify: `src/Controllers/ExtensionsController.php` (`enable()`/`disable()`/`toggle()` at lines
  89-145 call the executor; keep the existing `audit()` call and add the operation id to it)
- Test: `tests/Unit/Console/Extensions/EnableThroughExecutorTest.php`

**Interfaces:**
- Consumes: Task 10's executor (resolved lazily inside `execute()`/the controller action — never in
  constructors, per the 1.78.3/1.78.4 laziness rules).
- Produces: both surfaces drive the same executor and print/return the operation record (id, status,
  failed_migration on failure). The command descriptions drop "(development only)". Existing
  authority middleware, CSRF policy, and host-writability checks on the controller routes are
  unchanged — only the env refusal goes (spec B5). Dependency refusals surface `ExtensionResolver`'s
  ordered error list verbatim.

- [ ] **Step 1: Write the failing tests** — command with `APP_ENV=production` in env: no refusal,
  executor invoked (assert via a spy executor bound in the container); failure path prints
  `failed_migration`; controller `enable()` returns the operation payload and still writes an audit row.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** + `vendor/bin/phpunit tests/Unit/Console`.
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): CLI and HTTP enable/disable drive the schema executor; production refusals removed"`

---

### Task 12: Structural verifiers + `schema:verify` classification/adoption command

**Files:**
- Create: `src/Extensions/Schema/StructuralVerifierInterface.php`
- Create: `src/Extensions/Schema/AdoptionService.php`
- Create: `src/Console/Commands/Migrate/SchemaVerifyCommand.php` (name: `migrate:verify`, options `--adopt`, `--json`)
- Test: `tests/Unit/Extensions/Schema/AdoptionServiceTest.php`

**Interfaces:**
- Consumes: `SchemaReadiness`, `DescriptorInventory`, ledger, Task 8 lock.
- Produces: `interface StructuralVerifierInterface { public function source(): string; public function verify(\Glueful\Database\Connection $db, string $migrationBasename): bool; }`
  — implementations are registered in the container by the OWNING package (tagged service id
  `schema.verifier.{source}`; Plans 2/3 ship the real ones).
  `AdoptionService::classify(): array<string, array{state: ReadinessState, reasons: list<string>}>`
  (per descriptor source);
  `AdoptionService::adopt(string $source): AdoptionReport` — for each missing receipt: a registered
  verifier for that source must pass for that basename, then the receipt row is inserted **with the
  checksum of the exact shipped file** (spec B7); no verifier registered → the descriptor stays
  Divergent and adopt refuses; runs under the lock; never drops anything.

- [ ] **Step 1: Write the failing tests** — classify maps Ready/Pending/Divergent across three
  fixture descriptors; adopt with a passing fake verifier writes the receipt with the file's
  current sha256; adopt with no verifier refuses and classification stays Divergent; adopt never
  touches existing rows.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** (command prints the classification table; `--adopt <source>` calls the service).
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): verifier-gated adoption — receipts only from passing structural verifiers"`

---

### Task 13: Architecture test — `ExtensionStateWriter` is executor-internal

**Files:**
- Test: `tests/Unit/Architecture/ExtensionStateWriterCallersTest.php`

**Interfaces:**
- Consumes: the final call graph from Tasks 10–11.
- Produces: a grep-based inventory test (spec B5): scanning `src/`, the only files containing
  `new ExtensionStateWriter` or `ExtensionStateWriter::` are
  `src/Extensions/Schema/ExtensionSchemaExecutor.php` (and the class file itself). The test reads
  the file list with `RecursiveDirectoryIterator` over `src/` and asserts the allowlist exactly, so
  any future direct CLI/controller use fails CI with a message pointing at the executor.

- [ ] **Step 1: Write the test** (it should PASS immediately if Tasks 10-11 are correct — if it
  fails, the wiring missed a caller; fix the caller, not the allowlist).
- [ ] **Step 2: Run to verify pass.**
- [ ] **Step 3: Commit** — `git commit -m "test(architecture): ExtensionStateWriter mutation is executor-only"`

---

### Task 14: Changelog, full gates, release prep (1.79.0)

**Files:**
- Modify: `CHANGELOG.md` (new `## [1.79.0]` section)
- Modify: `ROADMAP.md` only if it lists schema work (check; skip otherwise)

- [ ] **Step 1: Write the changelog entry** — sections: Added (descriptor contract +
  `migrationDescriptors()`, readiness, normalization, lock, operation table + executor, verify/adopt
  command), Changed (core leaves unconditional — call out that `locks/queue/uploads/metrics/
  notifications/scheduler` schemas now always provision; production enable/disable now allowed with
  the authority/CSRF/audit controls; `loadMigrationsFrom()` validates against the inventory),
  Fixed (per-migration transaction closes the partial-DDL-without-receipt gap). State the
  compatibility promise: no package declaring nothing changes behavior except the named items.
- [ ] **Step 2: Run the full gates**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test   # expect: green (2199+ tests, ~63 skips, 0 failures)
composer phpstan                            # expect: no errors
composer phpcs                              # expect: no errors
```

- [ ] **Step 3: Commit** — `git commit -m "docs: 1.79.0 changelog — schema-on-enable framework program"`
- [ ] **Step 4: Merge to dev locally** (`git checkout dev && git merge --ff-only <branch>`); publication (dev→main PR, tag, Packagist) is the human's step, as is deciding the release timing relative to Plans 2/3.

## Self-review (completed)

- **Spec coverage:** B1→Tasks 1-4; B2→Tasks 2,5,10; B3 readiness→Task 6 (capability ownership is
  Thallo-side, Plan 3; `requires.extensions` population is Plan 2 — enforcement path already exists
  via `ExtensionResolver` and is wired in Tasks 10-11); B4→Tasks 8-9; B5→Tasks 10,11,13; B6 tenancy
  executor swap is Thallo-side (Plan 3) — the shared executor it swaps onto is Task 10; B7→Task 12;
  B8 provision/SPA are Thallo-side (Plan 3); B9 step 1 is this plan.
- **Placeholders:** Tasks 3, 6, 7, 12 name every test case to write rather than printing full
  bodies; each names concrete fixtures, inputs, and assertions — an implementer writes them without
  inventing requirements. Task 9 carries a Step 0 API-name verification instead of guessing
  `Connection`'s transaction accessor names.
- **Type consistency:** `source()` format `package[:id]`, `ReadinessState` enum, executor
  constructor, and lock interface are each defined once (Tasks 1, 6, 10, 8) and consumed by name
  everywhere else.
