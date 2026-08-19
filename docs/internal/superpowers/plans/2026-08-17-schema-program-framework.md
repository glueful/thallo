# Schema-on-Enable Program — Plan 1 of 3: Framework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the framework half of the schema-on-enable program (spec
`docs/internal/superpowers/specs/2026-08-17-schema-creation-policy-design.md`, Section B):
manifest migration descriptors as the sole inventory, checksum-driven readiness, receipt
normalization, serialized/transactional execution, the core-owned enable operation machine,
and CLI/HTTP wiring through one executor.

**Architecture:** A new `Glueful\Extensions\Schema` namespace owns descriptors, inventory,
readiness, normalization, and the enable executor. Framework core leaves become built-in
descriptors in the same inventory (their existing receipt sources preserved verbatim).
`MigrationManager` gains source-scoped pending/migrate APIs but keeps its lazy-ledger contract
(1.78.4). Locking is schema-independent (pg advisory / mysql named lock / file), never the
configured `LockManager`. `ExtensionStateWriter` becomes executor-internal.

**Compatibility stance (corrects the earlier claim):** an installed Glueful package that has
NOT adopted the manifest contract stays fully bootable — projection never throws for mere
absence, and `loadMigrationsFrom()` keeps its legacy append for undescribed paths. Fail-closed
bites exactly where the spec needs it: the **new schema-on-enable operations** (enable executor,
readiness, normalization, adoption) refuse undeclared packages with a typed error. Legacy global
`migrate:run` still executes undeclared providers' appended paths in 1.79.0 — that compatibility
window closes when Plans 2/3 finish adoption. Malformed declarations still throw at projection.

**Tech Stack:** PHP 8.3, PHPUnit 10 (framework library harness: plain `TestCase` + SQLite
`Connection`), phpstan, phpcs (PSR-12). Repo: `/Users/michaeltawiahsowah/Sites/glueful/framework`,
branch off `dev`.

**Plans 2 and 3** (first-party extension adoption; Thallo program) are written after this plan
ships and pins the real APIs.

## Global Constraints

- All work in `/Users/michaeltawiahsowah/Sites/glueful/framework` on a branch off `dev`; commit locally, never push.
- No `Co-Authored-By` trailers in commits.
- Gates for every task: the named test file green; before the final task: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpstan`, `composer phpcs` all green.
- The 1.78.4 lazy-ledger contract is inviolable: construction/registration of `MigrationManager` performs zero DB work; only migrate operations create the ledger; reads/rollback are ledger-absent-safe.
- Nothing ever drops a table. No path may run DDL at boot. No schema operation may depend on a table it has not yet migrated (bootstrap ordering is explicit — Task 10).
- Descriptor mode enum values are exactly `core` and `on_enable`. Priority values are the existing `MigrationPriority` constants: `FOUNDATION`(-200), `IDENTITY`(-100), `DEFAULT`(0), `DEPENDENT`(100).
- Ledger receipt identity = `(source, migration-basename)` with `checksum` = `hash_file('sha256', file)` — already what `runMigration()` records; never change the stored format. Existing sources (`glueful/framework`, `glueful/framework:<leaf>`, package names) must keep their identities.
- Migration file discovery anywhere in this program uses `FileFinder::findMigrations()` (recursive) — never a shallow glob — so inventory and runtime can never disagree about a file set.
- Version target: `1.79.0`.

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
  `__construct(string $id, string $package, string $packageType, string $relativePath, int $priority, DescriptorMode $mode, array $legacyAliases = [], ?string $verifierClass = null)`
  (`$verifierClass`: optional FQCN of the descriptor's structural verifier — manifest-declared so
  adoption can discover it even while the owning extension is disabled; syntax-validated at
  construction, class-existence checked at use),
  `source(): string` (returns `$package . ':' . $id` unless `$id === 'default'`, then `$package`),
  `absolutePath(string $packageDir): string` — **canonical containment**: both `realpath($packageDir)`
  and `realpath($joined)` must resolve, and the resolved path must sit inside the resolved package
  dir; a symlink pointing outside is rejected. Throws `DescriptorValidationException` otherwise.

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

    public function testCoreModeIsValidForLibrariesAndTheFrameworkItself(): void
    {
        self::assertSame(DescriptorMode::Core, $this->descriptor(type: 'library', mode: DescriptorMode::Core)->mode);
        self::assertSame(DescriptorMode::Core, $this->descriptor(type: 'framework', mode: DescriptorMode::Core)->mode);
    }

    public function testTraversalAndAbsolutePathsAreRejectedAtConstruction(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(path: '../outside');
    }

    public function testAbsolutePathResolvesInsideThePackageDir(): void
    {
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($dir . '/migrations', 0777, true);
        try {
            self::assertSame(realpath($dir . '/migrations'), $this->descriptor()->absolutePath($dir));
        } finally {
            rmdir($dir . '/migrations');
            rmdir($dir);
        }
    }

    public function testSymlinkEscapingThePackageDirIsRejected(): void
    {
        $outside = sys_get_temp_dir() . '/desc_out_' . uniqid();
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($outside, 0777, true);
        mkdir($dir, 0777, true);
        symlink($outside, $dir . '/migrations'); // relativePath 'migrations' resolves OUTSIDE $dir
        try {
            $this->expectException(DescriptorValidationException::class);
            $this->descriptor()->absolutePath($dir);
        } finally {
            unlink($dir . '/migrations');
            rmdir($dir);
            rmdir($outside);
        }
    }

    public function testMissingPathIsRejectedByAbsolutePath(): void
    {
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($dir, 0777, true);
        try {
            $this->expectException(DescriptorValidationException::class);
            $this->descriptor()->absolutePath($dir); // no migrations/ inside
        } finally {
            rmdir($dir);
        }
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

    public function testVerifierClassMustLookLikeAnFqcnWhenGiven(): void
    {
        $this->expectException(DescriptorValidationException::class);
        new MigrationDescriptor(
            id: 'default',
            package: 'acme/widgets',
            packageType: 'glueful-extension',
            relativePath: 'migrations',
            priority: MigrationPriority::DEFAULT,
            mode: DescriptorMode::OnEnable,
            verifierClass: 'not a class name',
        );
    }
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Unit/Extensions/Schema/MigrationDescriptorTest.php` → ERROR (class not found).

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
 * One manifest-declared migration source (spec B1). Identity is (package, id); the ledger
 * `source` derives from it, with the 'default' id collapsing to the bare package name so
 * existing single-track receipts keep their identity.
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
        public readonly ?string $verifierClass = null,
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
        if (
            $verifierClass !== null
            && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/D', $verifierClass) !== 1
        ) {
            throw new DescriptorValidationException(
                "Descriptor '{$package}:{$id}' verifier must be a canonical, non-leading-slash FQCN."
            );
        }
    }

    public function source(): string
    {
        return $this->id === 'default' ? $this->package : $this->package . ':' . $this->id;
    }

    public function absolutePath(string $packageDir): string
    {
        $baseReal = realpath($packageDir);
        $joinedReal = realpath(rtrim($packageDir, '/') . '/' . $this->relativePath);
        if ($baseReal === false || $joinedReal === false) {
            throw new DescriptorValidationException(
                "Descriptor '{$this->source()}' path '{$this->relativePath}' does not resolve under {$packageDir}."
            );
        }
        if ($joinedReal !== $baseReal && !str_starts_with($joinedReal, $baseReal . '/')) {
            throw new DescriptorValidationException(
                "Descriptor '{$this->source()}' path resolves outside its package directory (symlink escape)."
            );
        }
        return $joinedReal;
    }
}
```

- [ ] **Step 4: Run to verify pass** (all listed cases).
- [ ] **Step 5: Commit** — `git add src/Extensions/Schema tests/Unit/Extensions/Schema && git commit -m "feat(extensions): migration descriptor VO with canonical path containment"`

---

### Task 2: `PackageManifest::migrationDescriptors()` — declared projection + undeclared listing

**Files:**
- Modify: `src/Extensions/PackageManifest.php` (append three methods; do not touch `getCandidates()`)
- Test: `tests/Unit/Extensions/Schema/ManifestMigrationDescriptorsTest.php`

**Interfaces:**
- Consumes: Task 1's types; `PackageManifest`'s private `rawPackages()` (includes `type`, `extra`,
  `install-path`).
- Produces:
  - `public function migrationDescriptors(): array` — `package => list<MigrationDescriptor>` for
    packages that **declare** (`migrations` list or `"none"` → empty list). Throws
    `DescriptorValidationException` only for **malformed** declarations (bad priority/mode/id/path
    shape, non-list non-"none" value).
  - `public function undeclaredGluefulPackages(): array` — `list<string>` of packages that have an
    `extra.glueful` block but no `migrations` key. **They stay bootable** (compatibility stance);
    schema operations refuse them later (Tasks 6/10/12) with `UndeclaredSchemaException`.
  - `public function installPaths(): array` — `package => absolute install dir` (resolved from
    `install-path` relative to `vendor/composer/`).
  Manifest shape inside `extra.glueful` (spec B1):

```json
"migrations": [
  { "id": "default", "path": "migrations", "priority": "dependent",
    "mode": "on_enable", "legacyAliases": ["acme-widgets"],
    "verifier": "Acme\\Widgets\\Schema\\WidgetsStructuralVerifier" }
]
```

  or `"migrations": "none"`. Priority strings `foundation|identity|default|dependent` map to
  `MigrationPriority` constants. Shape rules (fail closed as malformed): `migrations: []` is
  rejected — an empty schema must say `"none"` explicitly; the list must satisfy
  `array_is_list()`; every row must itself be an array.

- [ ] **Step 1: Write the failing tests** — fixture `installed.json` files (the class's documented
  fallback path); build `ApplicationContext` over a temp base the same way the nearest existing
  `PackageManifest`/`ExtensionResolver` test does. Cases:
  - declared descriptor projects with mode/priority/source;
  - `"none"` yields an empty list and the package is NOT in `undeclaredGluefulPackages()`;
  - provider-declaring package without a `migrations` key: projection does NOT throw, package
    appears in `undeclaredGluefulPackages()`;
  - arbitrary composer dependency (no `extra.glueful`) appears in neither;
  - library-typed package declaring `on_enable` throws (malformed — Task 1 rule);
  - unknown priority or mode string throws;
  - `migrations: []` throws (must declare `"none"` explicitly);
  - a non-list `migrations` map and a non-array descriptor row each throw;
  - `installPaths()` resolves `../acme/widgets` against the fixture vendor dir.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** (projection loop as in the JSON shape above; absence → collect name into
  the undeclared list instead of throwing).
- [ ] **Step 4: Run to verify pass** (all listed cases; `verifier` maps to `$verifierClass`).
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): manifest migration projection — declared descriptors plus undeclared listing, malformed fails closed"`

---

### Task 3: `DescriptorInventory` — validated global inventory (manifest + framework built-ins)

**Files:**
- Create: `src/Extensions/Schema/DescriptorInventory.php`
- Create: `src/Extensions/Schema/FrameworkDescriptors.php`
- Create: `src/Extensions/Schema/UndeclaredSchemaException.php`
- Test: `tests/Unit/Extensions/Schema/DescriptorInventoryTest.php`

**Interfaces:**
- Consumes: Tasks 1-2; `FileFinder::findMigrations()` (recursive — the runtime's own discovery).
- Produces:
  - `FrameworkDescriptors::all(string $frameworkRoot): list<MigrationDescriptor>` — built-in
    descriptors for the framework's own leaves so the root package participates in the sole
    inventory (P1 fix). Identities preserve today's receipt sources exactly:
    `id 'default'` → source `glueful/framework` for `migrations/auth`; ids
    `locks|metrics|notifications|queue|scheduler|uploads` → sources
    `glueful/framework:<leaf>`; all `packageType 'framework'`, `DescriptorMode::Core`,
    `MigrationPriority::FOUNDATION`. (Task 10 appends the new `extensions` leaf here.)
  - `DescriptorInventory::fromManifest(PackageManifest $manifest, string $frameworkRoot, FileFinder $files): self`
    — merges manifest descriptors with the framework built-ins;
  - `all(): list<MigrationDescriptor>`; `bySource(string $source): ?MigrationDescriptor`;
    `forPackage(string $package): list<MigrationDescriptor>`;
    `isDeclared(string $package): bool` (false for `undeclaredGluefulPackages()` members —
    callers throw `UndeclaredSchemaException` naming the package and the manifest remedy);
    `packageOfProvider(string $providerClass): ?string` (provider FQCN → owning package, built
    from every `extra.glueful.provider` in the manifest — extensions AND library packs; null for
    unknown classes);
    `pathOf(MigrationDescriptor $d): string`;
    `filesOf(MigrationDescriptor $d): list<string>` (via `FileFinder::findMigrations`, sorted);
    `aliasIndex(): array<string,string>` (alias → source).
  - Fail-closed construction rules: duplicate descriptor **sources**, duplicate **canonical paths**,
    an alias claimed twice, an alias equal to any live source, a declared path that does not
    resolve (via `absolutePath()`), a path whose `filesOf()` is empty (message: “an empty schema
    declares migrations: none”), **duplicate migration basenames within one descriptor**
    (ledger identity is `(source, basename)`), or — because `FileFinder` discovery is recursive —
    **one descriptor's canonical path being an ancestor or descendant of another's** (a nested
    file would enter the batch twice under two sources) — all throw
    `DescriptorValidationException` naming every claimant.

- [ ] **Step 1: Write the failing tests** — real temp package dirs, each with `migrations/` and
  dummy `NNN_*.php` files (nested subdir in one fixture to prove recursive discovery matches
  runtime). One test per fail-closed rule above — including two descriptors at `migrations/` and
  `migrations/tenant/` of the same fixture package rejected as ancestor/descendant — plus: framework built-ins are present with
  the exact legacy sources; `isDeclared()` false for an undeclared package; `filesOf()` returns the
  nested file.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): global descriptor inventory — framework built-ins included, collisions and dup basenames fail closed"`

---

### Task 4: Source-scoped `MigrationManager` API; `loadMigrationsFrom()` validates, never appends

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php`
- Modify: `src/Extensions/ServiceProvider.php:190-201` (`loadMigrationsFrom()`)
- Test: `tests/Unit/Extensions/Schema/SingleInventoryTest.php`

**Interfaces:**
- Consumes: Tasks 1-3; existing `addMigrationPath()`.
- Produces:
  - `MigrationManager::registerDescriptor(MigrationDescriptor $d, string $absolutePath): void` —
    delegates to `addMigrationPath($absolutePath, $d->priority, $d->source())` and records
    source→path in a private map;
  - `MigrationManager::hasSource(string $source): bool`;
  - `MigrationManager::setGlobalSourcePolicy(\Closure $enabledPackages): void` — the closure
    returns `list<string>` package names and is evaluated at EACH global read/run, never at manager
    construction; `globalSources(): list<string>` returns `app` + every non-descriptor legacy
    source + every `core` descriptor + only the `on_enable` descriptors whose package the closure
    currently reports enabled. With no policy (bare construction/back-compat tests), every
    registered source remains global;
  - `MigrationManager::pendingForSources(array $sources): array` — `list<array{file: string, source: string}>`,
    discovers candidates DIRECTLY from exactly the named registered sources, in global priority
    order; it MUST NOT call/filter `getPendingMigrations()`, because that public global view omits
    disabled `on_enable` descriptors while this is the executor's intentional explicit-source API.
    `getPendingMigrations()` is instead the projection of
    `pendingForSources(globalSources())` down to its legacy `list<string>` file shape;
  - `loadMigrationsFrom()` new behavior (bypass-proof): when the container has a
    `DescriptorInventory`, resolve the calling provider's owning package — the declared
    `packageOfProvider(static::class)` map is only the fast path; on a miss, ownership is resolved
    by **file containment**: `realpath((new \ReflectionClass(static::class))->getFileName())`
    matched against the inventory's canonical `installPaths()` roots (longest-prefix wins). A
    second, undeclared ServiceProvider class shipped inside a declared package therefore still
    answers to that package's manifest. Only a provider whose file lies in no installed package
    root (an app-local provider) is ownerless.
    - Owning package **declared**: the dir's canonical path must equal one of that package's
      descriptors' `pathOf()`; then VALIDATE (call-site source+priority must match exactly;
      mismatch throws `DescriptorValidationException`) and return WITHOUT registering — the
      container factory (Task 5) is the only registrar. A declared package registering any path
      its manifest does not describe — including a package that declared `migrations: none` —
      throws `DescriptorValidationException` (a declared manifest cannot be bypassed).
    - Owning package **undeclared**, or provider ownerless (app-local): legacy append,
      unchanged — bootable.

- [ ] **Step 1: Write the failing tests** (sqlite lazy-ledger harness):
  - `registerDescriptor()` then `loadMigrationsFrom()` on the same path: one physical file appears
    exactly once in `getPendingMigrations()` (single-inventory / no double execution);
  - mismatched source in the legacy call throws;
  - a provider whose package declared `migrations: none` calling `loadMigrationsFrom()` throws;
  - a declared package registering a second, undescribed path throws;
  - a SECOND provider class inside a declared package's install root (not the manifest-named
    provider) registering an undescribed path throws (file-containment ownership);
  - a provider from an UNDECLARED package still appends (legacy compatibility);
  - an app-local provider (file outside every package root) still appends;
  - global source policy includes app, legacy, core, and enabled `on_enable` sources while
    excluding a disabled `on_enable` source; changing the closure's returned package set AFTER
    manager construction changes `globalSources()` on the next call (call-time evaluation);
  - `pendingForSources(['a'])` returns only source-a files, each row carrying `file` + `source`;
    a disabled descriptor omitted from `getPendingMigrations()` remains discoverable through
    `pendingForSources([$disabledSource])` (proves the executor path is not accidentally filtered);
  - `registerDescriptor()` + `pendingForSources()` on a ledger-less DB: zero tables afterwards.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** + existing `tests/Unit/Database/Migrations` suite.
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): source-scoped pending API; loadMigrationsFrom validates against the inventory"`

---

### Task 5: The manager factory registers the whole inventory (core leaves unconditional)

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php:504-560` (the `MigrationManager` factory)
- Test: `tests/Unit/Container/CoreMigrationLeavesTest.php`

**Interfaces:**
- Consumes: Task 3's `FrameworkDescriptors::all()` + `DescriptorInventory`; Task 4's
  `registerDescriptor()`.
- Produces: the factory drops the `$gates` config reads and the direct `addMigrationPath()` calls;
  it registers **every descriptor in `DescriptorInventory::all()`** — framework built-ins AND
  declared manifest descriptors — via `registerDescriptor($d, $inventory->pathOf($d))`, exactly
  once each (this factory is the sole registrar; `loadMigrationsFrom()` never registers a
  described path). Without this, a declared extension's `pendingForSources()` would be empty.
  Framework sources and FOUNDATION priority are byte-identical to today (receipt identities
  preserved). Undeclared packages still reach the manager only through their providers' legacy
  `loadMigrationsFrom()` appends. After registration, the factory calls Task 4's
  `setGlobalSourcePolicy()` with a closure that computes enabled PACKAGE names at call time from
  `EnabledProviders::from($context)` and `PackageManifest::getCandidates()` (invert each
  candidate's provider back to its package; never infer by slug). This wiring belongs here, after
  the API already exists — Task 9 does not retroactively revisit this factory.

- [ ] **Step 1: Write the failing test** — build the factory's manager with locks/queue/uploads
  config gates OFF; assert `hasSource()` true for all seven current sources
  (`glueful/framework`, `glueful/framework:locks`, `:metrics`, `:notifications`, `:queue`,
  `:scheduler`, `:uploads`); assert the registered pending set for `glueful/framework:locks`
  is non-empty via `pendingForSources()`; with a fixture manifest declaring one extension
  descriptor, assert `hasSource('acme/widgets')` is true and its file appears in
  `pendingForSources(['acme/widgets'])` (manifest descriptors registered by the factory, not by
  provider boot). Toggle the fixture provider in `config/extensions.php` after constructing the
  manager, clear `ApplicationContext`'s config cache after each on-disk edit (the production
  writer→`writeCacheNow()` path does this at `ExtensionManager.php:445-452`), and assert
  `globalSources()` excludes/includes `acme/widgets` on successive calls. This proves the factory
  closure reads current context state rather than capturing the enabled list at manager creation;
  it does not pretend `config()` bypasses the context's deliberate cache.
- [ ] **Step 2: Run to verify failure** (gated leaves absent today).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** + `vendor/bin/phpunit tests/Integration/Database/Migrations`.
- [ ] **Step 5: Commit** — `git commit -m "feat(core): framework leaves are inventory descriptors, unconditional — config governs runtime, not schema"`

---

### Task 6: `SchemaReadiness` — checksum-verified classification

**Files:**
- Create: `src/Extensions/Schema/SchemaReadiness.php`
- Create: `src/Extensions/Schema/ReadinessState.php`
- Test: `tests/Unit/Extensions/Schema/SchemaReadinessTest.php`

**Interfaces:**
- Consumes: `DescriptorInventory` (incl. `filesOf()`), `Connection`, ledger rows
  `(migration, source, checksum)`.
- Produces: `enum ReadinessState: string { case Ready = 'ready'; case Pending = 'pending'; case Divergent = 'divergent'; }`;
  `SchemaReadiness::__construct(Connection $db, DescriptorInventory $inventory, bool $aliasesNormalized = false)`;
  `classify(MigrationDescriptor $d): ReadinessState`; `explain(MigrationDescriptor $d): list<string>`.
  Rules (spec B3): every `filesOf()` basename has a receipt under `source()` with the exact current
  SHA-256 → Ready; missing receipts only → Pending; checksum mismatch, receipt for a removed file,
  receipts still under a legacy alias when `!$aliasesNormalized` (reason: “run
  migrate:normalize-receipts”), or unresolved path → Divergent. Ledger absent ⇒ Pending, zero DDL.
  A package for which `isDeclared()` is false → `UndeclaredSchemaException` (schema ops fail
  closed; boot does not call this).

- [ ] **Step 1: Write the failing tests** — sqlite harness, hand-seeded ledger rows: Ready;
  no-ledger Pending with `sqlite_master` unchanged; changed file → Divergent naming it; deleted
  file with receipt → Divergent; alias receipts pre-normalization → Divergent with the normalize
  reason, post-flag → Ready; undeclared package → `UndeclaredSchemaException`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): checksum-driven schema readiness"`

---

### Task 7: Bootstrap-safe migration lock

**Files:**
- Create: `src/Extensions/Schema/MigrationLockInterface.php`
- Create: `src/Extensions/Schema/PgsqlAdvisoryMigrationLock.php`
- Create: `src/Extensions/Schema/MysqlNamedMigrationLock.php`
- Create: `src/Extensions/Schema/FileMigrationLock.php`
- Create: `src/Extensions/Schema/MigrationLockFactory.php`
- Test: `tests/Unit/Extensions/Schema/MigrationLockTest.php`

**Interfaces:**
- Consumes: `Connection` (driver name + PDO for pgsql), storage path helper for file locks,
  existing `Glueful\Database\Exceptions\LockContentionException`.
- Produces (P1 fix — NOT the configured `LockManager`, whose database store needs the locks table
  this program may still be migrating):

```php
interface MigrationLockInterface
{
    /**
     * Acquire all sources in deterministic sorted order. Bounded wait: each source is tried in a
     * loop (100ms sleep) up to $waitSeconds; on failure at source N, sources 1..N-1 already
     * acquired are RELEASED before LockContentionException is thrown (no partial custody).
     * The returned handle holds until release() — no TTL expiry.
     */
    public function acquireAll(array $sources, int $waitSeconds = 10): MigrationLockHandle;
}
final class MigrationLockHandle { public function release(): void; }
```

  - `PgsqlAdvisoryMigrationLock`: `pg_try_advisory_lock(hashtext('schema:'||source))` in the
    bounded loop above (NEVER the blocking `pg_advisory_lock()`, which cannot honor
    `$waitSeconds`) — session-scoped (connection close releases; no expiry mid-migration),
    schema-independent, serializes across hosts.
  - `MysqlNamedMigrationLock`: `GET_LOCK('schema:'||sha1(source), 0)` in the bounded loop,
    `RELEASE_LOCK` on release — session-scoped, schema-independent, serializes across hosts
    (host-local flock cannot).
  - `FileMigrationLock`: `flock()` on `storage/framework/locks/schema-<sha1(source)>.lock` —
    used for sqlite/local operation and tests only.
  - `MigrationLockFactory::forConnection(Connection $db, ?ApplicationContext $context):
    MigrationLockInterface` is the ONE driver selector: pgsql → advisory; mysql → named lock;
    otherwise file. CoreProvider's lazy binding and the framework Installer both use this factory,
    so the preflight/injected installer connection cannot drift onto a different lock backend.
  - Every
    schema operation — normalization (Task 8), **ordinary global migration (Task 9's `migrate()`
    path via the Task 11 `RunCommand` and `Installer` wiring)**, the executor (Task 10), adoption
    (Task 12) —
    wraps work in `try { … } finally { $handle->release(); }`.

- [ ] **Step 1: Write the failing tests** — sorted acquisition order observable via a spy subclass;
  partial-acquisition rollback: a spy whose source-2 acquire fails must see source-1 released
  before the exception propagates; bounded wait: a held lock makes `acquireAll(waitSeconds: 1)`
  throw in ~1s, not block indefinitely;
  second `acquireAll()` on an overlapping source in another handle (file lock via a second flock in
  the same test process on the same path — use `proc_open` of a 3-line PHP script if same-process
  flock is reentrant; keep the helper script inline in the test) → `LockContentionException`;
  disjoint sources don't contend; release frees; handle survives longer than any TTL (no expiry
  field exists to assert — assert the interface exposes none); factory selection returns the pgsql,
  mysql, and file implementations for the three driver fixtures respectively.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): bootstrap-safe migration locks — pg advisory, mysql named locks, flock; no TTL"`

---

### Task 8: Receipt normalization (lock-serialized) + container wiring

**Files:**
- Create: `src/Extensions/Schema/ReceiptNormalizer.php`
- Create: `src/Extensions/Schema/NormalizationReport.php`
- Create: `src/Console/Commands/Migrate/NormalizeReceiptsCommand.php` (`migrate:normalize-receipts`, `--dry-run`)
- Modify: `src/Container/Providers/CoreProvider.php` (register `DescriptorInventory`,
  `SchemaReadiness`, `ReceiptNormalizer`, `MigrationLockInterface` as lazy factories)
- Test: `tests/Unit/Extensions/Schema/ReceiptNormalizerTest.php`

**Interfaces:**
- Consumes: `DescriptorInventory::aliasIndex()`, ledger, Task 7 lock.
- Produces: `ReceiptNormalizer::__construct(Connection $db, DescriptorInventory $inventory, MigrationLockInterface $lock)`;
  `normalize(bool $dryRun = false): NormalizationReport` — readonly VO
  `{ rewritten: list<array{source: string, alias: string, migration: string}>, refused: list<array{alias: string, reason: string}> }`.
  Rules: acquire the lock over all affected target sources (sorted) with `finally` release; inside
  one transaction rewrite `source = alias → descriptor source` only when the row's stored checksum
  equals the current file's SHA-256; **duplicate reconciliation** (P2 fix): if the target
  `(source, migration)` receipt already exists — checksums identical → delete the alias row
  (reconciled, reported under `rewritten` with reason suffix), checksums differ → refuse the alias
  as ambiguous; alias mapping ambiguous in the inventory → refuse; dry-run reports, writes nothing.
  When the migration ledger table is absent, `normalize()` returns an empty report WITHOUT
  querying (guarded existence check, zero DDL) — only migrate operations create the ledger.
  Container definitions are lazy factories — constructing them performs no DB work.

- [ ] **Step 1: Write the failing tests** — checksum-match rewrites; mismatch refuses; duplicate
  target row identical-checksum reconciles by deleting the alias row; duplicate with differing
  checksum refuses; dry-run writes nothing; idempotent second run; ledger-absent database returns
  an empty report with zero tables created; lock is acquired and released (spy lock); container
  boot with the new definitions performs zero queries.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): lock-serialized checksum-verified receipt normalization; schema services wired lazily"`

---

### Task 9: Transactional per-migration execution + `MigrationRunReport`

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` (`runMigration()` ~440-510; new `migrateSources()`)
- Create: `src/Database/Migrations/MigrationRunReport.php`
- Create: `src/Database/Migrations/MigrationScopeException.php`
- Test: `tests/Unit/Database/Migrations/TransactionalMigrationTest.php`

**Interfaces:**
- Consumes: `Connection` transaction API and driver-name accessor — **Step 0 verifies the exact
  names** (`grep -n "public function transaction\|getDriverName\|public function getPDO" src/Database/Connection.php`)
  and the implementation uses what exists.
- Produces (P1 fix — the executor's concrete migrate contract):

```php
final class MigrationRunReport
{
    /** @param list<array{file: string, source: string, status: 'applied'|'failed',
     *                    requiresManualRepair: bool, error: ?string}> $outcomes */
    public function __construct(public readonly array $outcomes) {}
    public function failed(): array;          // the failed subset
    public function firstFailure(): ?array;   // null when clean
}
```

  - `MigrationManager::migrateSources(array $sources): MigrationRunReport` — ensures the ledger,
    then runs exactly `pendingForSources($sources)` in order, stopping at the first failure
    (later files stay pending);
  - **Global source policy enforcement (consumes Task 4's API):** `getPendingMigrations()` and
    every global `migrate()` overload operate over `globalSources()` only. The no-argument form
    discovers only those sources. The legacy string and caller-supplied-array forms first resolve
    EVERY requested canonical file to its registered source and reject the WHOLE request with
    `MigrationScopeException` if any file belongs to a non-global descriptor; no earlier allowed
    file may run before that validation completes. `migrateSources()` is the sole intentional,
    named scoped bypass used by the executor. With no policy set, the compatibility behavior is
    unchanged;
  - `runMigration()`: on transactional-DDL drivers (`pgsql`, `sqlite`) the migration's `up()` AND
    its ledger insert run in one transaction — failure rolls back both (no partial DDL without a
    receipt); on other drivers behavior is unchanged and the outcome row carries
    `requiresManualRepair: true` on failure;
  - global `migrate()` keeps its existing return shape (back-compat) but is re-implemented over the
    same per-file runner.

- [ ] **Step 0: Verify API names** (grep above) and adjust call sites.
- [ ] **Step 1: Write the failing tests** — sqlite fixtures: an `up()` that creates a table then
  throws → afterwards NO table and NO receipt (the exact partial state the spec bans — this fails
  today); happy path → table + receipt; `migrateSources(['a'])` applies only source-a files and the
  report rows carry file/source/status; stop-at-first-failure leaves later files pending;
  `requiresManualRepair` true via a driver-name seam stub, false for sqlite; global policy: a
  registered `on_enable` descriptor whose package the policy closure reports DISABLED does not
  appear in `getPendingMigrations()` and global `migrate()` does not apply it, while an enabled
  one and a `core` descriptor do (a disabled extension's schema stays uncreated by `migrate:run`);
  `migrate($disabledFile)` and a mixed `migrate([$allowedFile, $disabledFile])` both throw
  `MigrationScopeException` BEFORE any DDL/receipt, while `migrateSources([$disabledSource])`
  remains the explicit executor path.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** + full `tests/Unit/Database/Migrations` + `tests/Integration/Database/Migrations`.
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): per-migration transaction couples DDL with receipt; source-scoped migrateSources report"`

---

### Task 10: Operation table + `ExtensionSchemaExecutor` (bootstrap-ordered)

**Files:**
- Create: `migrations/extensions/001_CreateExtensionOperationsTable.php`
- Modify: `src/Extensions/Schema/FrameworkDescriptors.php` (add the `extensions` leaf —
  id `extensions`, source `glueful/framework:extensions`, Core, FOUNDATION)
- Create: `src/Extensions/Schema/ExtensionOperation.php`
- Create: `src/Extensions/Schema/SchemaNotBootstrappedException.php`
- Create: `src/Extensions/Schema/ExtensionSchemaExecutor.php`
- Modify: `src/Container/Providers/CoreProvider.php` (register the executor as a lazy
  `FactoryDefinition` — this container does not autowire unknown ids, so without an explicit
  definition Task 11's resolution would fail)
- Test: `tests/Integration/Extensions/ExtensionSchemaExecutorTest.php`

**Interfaces:**
- Consumes: everything above plus `ExtensionStateWriter`, `EnabledProviders::from()`,
  `ExtensionResolver` (+ `ResolverError::MISSING_DEPENDENCY`), `ProtectedProviders::refusalFor()`,
  `PackageManifest::getCandidates()`.
- Produces: table `extension_operations`
  `(id, package, operation enable|disable, step, status running|succeeded|failed|manual_repair|enabled_cache_stale, actor, failed_migration nullable, error nullable, created_at, updated_at)`;
  `ExtensionOperation` row VO with those fields;
  `ExtensionSchemaExecutor::__construct(ApplicationContext $context, DescriptorInventory $inventory, MigrationManager $manager, SchemaReadiness $readiness, MigrationLockInterface $lock)`
  — the executor constructs its `ExtensionStateWriter` INTERNALLY (`new ExtensionStateWriter()`
  in its constructor): the writer needs no configuration, no other binding for it exists, and the
  Task 13 architecture test forbids the class token anywhere else — including in a container
  provider, which is why it must not be a constructor parameter;
  `enable(string $package, string $actor, bool $dryRun = false, bool $backup = false): ExtensionOperation`;
  `disable(string $package, string $actor, bool $dryRun = false, bool $backup = false): ExtensionOperation`.

  **Bootstrap ordering (P1 fix):** before anything else, `enable()`/`disable()` check the
  `glueful/framework:extensions` descriptor is Ready. Not ready → throw
  `SchemaNotBootstrappedException` with the message
  `"Run 'php glueful migrate:run' once after upgrading — the schema operation ledger is not yet migrated."`
  No operation row is attempted (its table may not exist); nothing else runs. A 1.78.x→1.79.0
  upgrade therefore requires one deliberate core migrate before the first enable — Task 14's
  changelog states this prominently.

  **Enable algorithm** (after bootstrap check): resolve candidate (undeclared package →
  `UndeclaredSchemaException`; protected → refusal; dependency dry-resolve over
  `[...current, provider]` → refuse with the resolver's ordered error list) →
  `acquireAll(sorted core-pending sources + this package's descriptor sources)` → record operation
  `running` (outside any migration transaction) → `migrateSources(pending core sources)` then
  `migrateSources(this package's on_enable sources)` — never global `migrate()` → readiness must
  classify Ready → `writer->enable($configPath, $provider, $dryRun, $backup)` → recompile the
  provider cache exactly as `EnableCommand` does today (copy that call) — recompile failure sets
  status `enabled_cache_stale` (config written, cache stale; remedy in `error`) → else `succeeded`.
  Migration failure: status `failed` or `manual_repair` (from the report's flag), `failed_migration`
  recorded, enabled state NOT written. `finally` releases the lock. Dry-run: performs the
  dependency/bootstrap/readiness checks and the writer dry-run, but NO migrations and NO operation
  row; returns a synthetic `ExtensionOperation` with status `succeeded` and step `dry-run`.

  **Disable algorithm** (P1 fix — guarantees preserved): bootstrap check → protected refusal →
  **dependency dry-resolve with the provider removed** — any `MISSING_DEPENDENCY` error refuses
  (exactly `DisableCommand:84-95`'s current behavior) → lock → operation `running` →
  `writer->disable(...)` → recompile (same `enabled_cache_stale` semantics) → `succeeded`.
  Never any schema change.

- [ ] **Step 1: Write the failing integration tests** (sqlite; fixture packages with on_enable
  descriptors): bootstrap-not-migrated → `SchemaNotBootstrappedException`, zero rows/tables
  touched; enable happy path (table + receipt + enabled list + operation `succeeded`); failing
  migration (enabled unchanged, `failed`, `failed_migration` set, later files pending); unrelated
  disabled package's schema untouched (source-scope proof); disable of a depended-on provider
  refused with the resolver message; held lock → `LockContentionException`; recompile-failure seam →
  `enabled_cache_stale`; disable preserves tables; dry-run writes nothing anywhere.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): bootstrap-ordered enable executor — migrate-first, enable-last, truthful terminal states"`

---

### Task 11: CLI and HTTP wiring through the executor; production enablement

**Files:**
- Modify: `src/Console/Commands/Extensions/EnableCommand.php` (executor; delete the production
  refusal at line 55; `--dry-run`/`--backup` forwarded to the executor)
- Modify: `src/Console/Commands/Extensions/DisableCommand.php` (same; line 56)
- Modify: `src/Console/Commands/Migrate/RunCommand.php` (serialize ordinary migration: acquire
  one `$sourceSnapshot = globalSources()`, acquire the migration lock over THAT snapshot, then take
  a FRESH `pendingForSources($sourceSnapshot)` read inside the lock — the pre-lock status read at
  RunCommand.php:88 is display-only — run only those returned files, `finally` release. Never call
  `globalSources()` again inside the custody window: an extension enabled after the snapshot was
  not locked and therefore must not join this run; its enable executor migrates it)
- Modify: `src/Installer/Installer.php` (use Task 7's `MigrationLockFactory` with the SAME injected
  preflight connection; acquire source `app`, take a fresh pending read, migrate, finally release)
- Modify: `src/Controllers/ExtensionsController.php:89-145` (executor; keep `audit()`, add the
  operation id/status to the audit row and the response payload)
- Test: `tests/Unit/Console/Extensions/EnableThroughExecutorTest.php`
- Test: `tests/Unit/Installer/InstallerMigrationLockTest.php`

**Interfaces:**
- Consumes: Task 10's executor, resolved lazily inside `execute()`/action methods (never constructors).
- Produces: both surfaces drive the same executor and surface the operation record (id, status,
  failed_migration, error). Command descriptions drop "(development only)". Existing authority
  middleware, CSRF policy, and host-writability checks stay; only the env refusals go (spec B5).
  `SchemaNotBootstrappedException` and `UndeclaredSchemaException` render as clear errors with
  their remedies, exit `FAILURE`/HTTP 409.

- [ ] **Step 1: Write the failing tests** — `APP_ENV=production`: no refusal, spy executor invoked;
  `RunCommand` acquires and releases the migration lock around a fresh in-lock pending read over
  the exact captured source snapshot (spy lock + spy manager call order); mutate the enabled-set
  closure immediately after lock acquisition and assert the newly enabled, therefore-unlocked
  source is NOT read or migrated in that run; Installer contending on an already-held `app` lock refuses
  before migration, and releases its own lock after both success and failure (this is what makes
  the migrate:run/provision/enable serialization claim true for every framework operation path);
  `--dry-run`/`--backup` reach the executor arguments; failure path prints `failed_migration`;
  bootstrap exception renders its remedy; controller `enable()` returns the operation payload and
  writes the audit row with the operation id.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** + `vendor/bin/phpunit tests/Unit/Console`.
- [ ] **Step 5: Commit** — `git commit -m "feat(extensions): CLI and HTTP drive the schema executor; production refusals removed"`

---

### Task 12: `AdoptionState`, structural verifiers, `migrate:verify`

**Files:**
- Create: `src/Extensions/Schema/AdoptionState.php`
- Create: `src/Extensions/Schema/StructuralVerifierInterface.php`
- Create: `src/Extensions/Schema/AdoptionService.php`
- Create: `src/Extensions/Schema/AdoptionReport.php`
- Create: `src/Console/Commands/Migrate/SchemaVerifyCommand.php` (`migrate:verify`, `--adopt <source>`, `--json`)
- Test: `tests/Unit/Extensions/Schema/AdoptionServiceTest.php`

**Interfaces:**
- Consumes: `SchemaReadiness`, `DescriptorInventory`, ledger, Task 7 lock.
- Produces (P1 fix — three adoption states, distinct from readiness):

```php
enum AdoptionState: string { case Ready = 'ready'; case Adoptable = 'adoptable'; case Divergent = 'divergent'; }

interface StructuralVerifierInterface
{
    /** Must equal the descriptor source that names this verifier. */
    public function source(): string;
    public function verify(\Glueful\Database\Connection $db, string $migrationBasename): bool;
}
```

  `AdoptionService::__construct(Connection $db, DescriptorInventory $inventory, SchemaReadiness $readiness, MigrationLockInterface $lock)`
  (verifiers come from the descriptors themselves — see the discovery rule below);
  `classify(): array<string, array{state: AdoptionState, reasons: list<string>}>` — mapping:
  readiness Ready → `Ready`; readiness Pending: **run the registered verifier for every missing
  basename** — all pass → `Adoptable`, any refusal → `Divergent` (reason names the failing
  basename), no verifier registered → `Divergent` (reason: "no structural verifier registered for
  {source}"); readiness Divergent → `Divergent` (readiness reasons pass through). Registration
  alone never yields Adoptable — verification must PASS (spec B7).
  `adopt(string $source): AdoptionReport` — refuses unless classified `Adoptable`; under the lock
  (finally-released) it RE-verifies every missing basename, then writes all receipts atomically in
  one transaction with each file's current SHA-256 (a re-verification failure writes nothing);
  existing rows are never touched; nothing is ever dropped. When the migration ledger table is
  absent, `classify()` derives states from an empty receipt set WITHOUT querying the missing table
  and `adopt()` refuses with "run a migrate operation first" — only migrate operations create the
  ledger. Verifiers are discovered from the MANIFEST, not the container: each descriptor's
  `$verifierClass` (Tasks 1/2) is instantiated directly (`new $verifierClass()`) after checking
  `class_exists` + `implements StructuralVerifierInterface`. A verifier MUST be an instantiable
  class with a public zero-required-argument constructor, and its `source()` MUST exactly equal the
  descriptor's `source()`; otherwise classification is Divergent before `verify()` runs. Composer
  autoload ships tier-2 code
  regardless of enablement, so a DISABLED extension's verifier is still discoverable, exactly when
  adoption needs it (a container tag from an unbooted provider would not exist). A declared
  verifier class that does not exist or does not implement the interface classifies the descriptor
  Divergent with that reason. Plans 2/3 ship the real verifier classes.

- [ ] **Step 1: Write the failing tests** — classification truth table across five fixture
  descriptors (Ready / Pending+verifier-passes → Adoptable / Pending+verifier-fails → Divergent
  naming the basename / Pending−verifier → Divergent / Divergent-readiness); adopt writes all
  receipts atomically with current sha256; adopt refuses non-Adoptable; a re-verification failure
  during adopt writes zero rows (atomicity); ledger-absent database: classify works without
  querying the missing table (zero DDL, no error) and adopt refuses with the migrate-first remedy;
  lock acquired and released (spy); declared verifier class missing, wrong-interface class,
  constructor-with-required-argument, and verifier-source mismatch each classify Divergent with a
  precise reason and never call/write a receipt.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(migrations): three-state adoption — verifier-gated receipts, divergent without a verifier"`

---

### Task 13: Architecture test — `ExtensionStateWriter` is executor-internal

**Files:**
- Test: `tests/Unit/Architecture/ExtensionStateWriterCallersTest.php`

**Interfaces:**
- Consumes: the final call graph from Tasks 10-11.
- Produces: a token-level inventory (P2 fix): scan every `src/**/*.php` file's contents for the
  word-boundary regex `/\bExtensionStateWriter\b/` — this catches `new`, static refs, `use`
  imports, constructor typehints, and docblock-free property types alike. Allowlist exactly:
  `src/Extensions/ExtensionStateWriter.php` (the class) and
  `src/Extensions/Schema/ExtensionSchemaExecutor.php`. Any other match fails with a message
  pointing at the executor.

- [ ] **Step 1: Write the test** (should PASS if Tasks 10-11 wired correctly; a failure means a
  missed caller — fix the caller, never the allowlist).
- [ ] **Step 2: Run to verify pass.**
- [ ] **Step 3: Commit** — `git commit -m "test(architecture): ExtensionStateWriter references are executor-only"`

---

### Task 14: Changelog, full gates, release prep (1.79.0)

**Files:**
- Modify: `CHANGELOG.md` (new `## [1.79.0]` section)
- Modify: `ROADMAP.md` only if it lists schema work (check; skip otherwise)

- [ ] **Step 1: Write the changelog entry** — Added (descriptor contract, `migrationDescriptors()` +
  undeclared listing, manifest-owned structural-verifier metadata, inventory with framework
  built-ins, readiness, normalization, bootstrap-safe locks, `migrateSources` report, operation
  table + executor, `migrate:verify`/adopt,
  `migrate:normalize-receipts`); Changed (**UPGRADE NOTE, prominent:** run `php glueful migrate:run`
  once after upgrading, before any extension enable — the operation ledger is a new core migration;
  core leaves now provision unconditionally — name all seven plus `extensions`; production
  enable/disable now allowed with authority/CSRF/audit controls; `loadMigrationsFrom()` validates
  descriptor-covered paths; global migrate/provision operations are source-locked and global runs
  skip disabled `on_enable` descriptors); Fixed (per-migration transaction closes the
  partial-DDL-without-receipt gap). Compatibility: packages without manifest declarations boot unchanged, and legacy global
  `migrate:run` still executes their appended paths; the NEW schema-on-enable, readiness,
  normalization, and adoption operations fail closed on them.
- [ ] **Step 2: Run the full gates**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test   # expect: green, 0 failures
composer phpstan                            # expect: no errors
composer phpcs                              # expect: no errors
```

- [ ] **Step 3: Commit** — `git commit -m "docs: 1.79.0 changelog — schema-on-enable framework program"`
- [ ] **Step 4: Merge to dev locally** (`git checkout dev && git merge --ff-only <branch>`); publication and release timing relative to Plans 2/3 are the human's call.

## Self-review (completed; fourth review round additionally pins the verifier field in executable
code, orders global policy before factory wiring, locks Installer as well as RunCommand, rejects
every explicit global-migrate scope bypass, and closes verifier construction/source identity)

- **Review findings addressed:** compatibility stance rewritten (undeclared = bootable, schema-ops
  fail closed) — Tasks 2/3/6/10; framework built-ins in the sole inventory — Tasks 3/5/10;
  `pendingForSources`/`migrateSources`/`MigrationRunReport` defined before the executor consumes
  them — Tasks 4/9/10; bootstrap ordering via `SchemaNotBootstrappedException` + upgrade note —
  Tasks 10/14; locks are schema-independent with sorted acquisition, no TTL, finally-release —
  Task 7; disable dependency dry-resolve, dry-run/backup pass-through, `enabled_cache_stale` —
  Tasks 10/11; `AdoptionState` three-state mapping — Task 12; canonical realpath containment with a
  real symlink test, `FileFinder` discovery, duplicate-basename rejection — Tasks 1/3; normalizer
  lock wiring, duplicate reconciliation, `NormalizationReport.php` in Files — Task 8;
  architecture test scans word-boundary tokens — Task 13.
- **Type consistency:** `source()` format, `ReadinessState` vs `AdoptionState`,
  `MigrationRunReport` rows, lock handle, and executor signatures are each defined once and
  consumed by name.
