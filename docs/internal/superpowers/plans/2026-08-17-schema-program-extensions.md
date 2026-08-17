# Schema-on-Enable Program — Plan 2 of 3: First-Party Extension Adoption

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every first-party Glueful extension Thallo installs adopts the manifest migration
contract (spec `docs/internal/superpowers/specs/2026-08-17-schema-creation-policy-design.md`
B1/B3/B7, as implemented by framework 1.79.0): descriptor declarations (or explicit
`"migrations": "none"`), accurate `requires.extensions`, and a structural verifier per
schema-owning package; all twelve artifacts are released and proven installable together before
Plan 3 consumes them.

**Architecture:** Pure adoption — no framework behavior changes except one small named
addition to the priority enum (Task 1) that tenancy's existing ordering requires. Each
package's ledger identity is already its composer name, so **no legacy aliases and no receipt
normalization are needed anywhere in this plan**. Verifiers are package-owned, effect-specific
proofs: table-creating migrations prove their full table set; ALTER/index/constraint migrations
prove the changed shape; seed/backfill migrations prove their required data invariant. A table's
mere existence never proves a later migration ran.

**Recon facts this plan is built on** (verified 2026-08-17): the 12 packages live as sibling
git repos under `/Users/michaeltawiahsowah/Sites/glueful/extensions/`, each on `dev`.
Current `loadMigrationsFrom()` calls: users = IDENTITY/`glueful/users`;
aegis, commerce, payvia, subscriptions = DEPENDENT/`glueful/<name>`;
audit, email-notification, i18n, import-export = DEFAULT/`glueful/<name>`;
tenancy = `MigrationPriority::DEFAULT - 50`/`glueful/tenancy` (out of enum — Task 1);
media, meilisearch have no migrations directory. `extra.glueful` currently carries only
`provider` (+ empty `requires.extensions`). Framework is a require-dev dependency
(`^1.63.0`-era constraints).

## Global Constraints

- Work in each extension repo on a branch `schema-adoption` off `dev`; commit locally, never push. No `Co-Authored-By` trailers.
- Framework 1.79.0 is NOT yet published. For any task that runs code against the new APIs:
  1. set the package's committed `require-dev.glueful/framework` to `^1.79`;
  2. add an **uncommitted** path repository with an explicit version override (the framework
     checkout identifies as `dev-main`, which does not satisfy `^1.79`):
     `composer config repositories.framework '{"type":"path","url":"../../framework","options":{"symlink":true,"versions":{"glueful/framework":"1.79.0"}}}'`;
  3. in Commerce (the only repo with a tracked lock), run
     `composer update glueful/framework --with-all-dependencies`; in the other eleven repos,
     which have no lock, run a full `composer update` (Composer refuses a partial update without
     an existing lock). Verify `composer show glueful/framework` reports `1.79.0` from the path
     repository;
  4. after the gates, run `composer config --unset repositories.framework`, then restore a
     tracked `composer.lock` or remove the generated untracked lock. Never use
     `git checkout composer.json`: that would also discard the committed requirement and
     manifest edits.
- Ledger identity is untouchable: every descriptor uses `id: "default"` so `source()` equals the composer package name — byte-identical to today's receipts. No `legacyAliases` anywhere.
- Manifest values must use the closed enums exactly: priority `foundation|identity|platform|default|dependent` (Task 1 adds `platform`), mode `core|on_enable`.
- Every package updates the runtime authority `extra.glueful.requires.glueful` to `>=1.79.0`;
  changing only `require-dev` is insufficient because `ExtensionResolver` reads this manifest
  field in installed applications.
- Every schema-owning first-party provider removes its manifest-described
  `loadMigrationsFrom()` call. Framework 1.79's compatibility method may validate a retained
  call, but the governing B1 contract makes the manifest the sole first-party inventory.
- Verifiers: public zero-required-argument constructor; `source()` returns the package name
  exactly; `migrationBasenames()` returns the recursively discovered set; unknown basenames
  return false. `verify()` proves the effect of that specific migration, using `hasTable`,
  `hasColumn`, schema metadata, and narrowly scoped data queries as appropriate.
- Per-repo gate: run `composer test`, `composer phpcs` where defined, and the repository's
  actual static-analysis script (`composer analyze` in commerce/payvia/subscriptions/audit/
  email-notification/i18n/import-export/tenancy/media/meilisearch; `composer analyse` in
  aegis/users). All must be green before its commit.
- Update each repo's CHANGELOG (if the repo keeps one) under Unreleased: "Declares the Glueful schema manifest (migration descriptors, requires.extensions, structural verifier); requires framework >=1.79.0 for schema-on-enable participation."

---

### Task 1: Framework — add the `platform` priority name (tenancy's ordering slot)

**Files (framework repo `/Users/michaeltawiahsowah/Sites/glueful/framework`, branch `schema-adoption` off `dev`):**
- Modify: `src/Database/Migrations/MigrationPriority.php`
- Modify: `src/Extensions/PackageManifest.php` (priority map in `migrationDescriptors()`)
- Modify: `CHANGELOG.md` (the pending `1.79.0` section)
- Modify (Thallo repo): `docs/internal/superpowers/specs/2026-08-17-schema-creation-policy-design.md` (B1: the closed priority enum gains `platform`)
- Test: `tests/Unit/Extensions/Schema/ManifestMigrationDescriptorsTest.php` (one added case)

**Why:** tenancy's control-plane migrations run at `-50` — after identity (`-100`), before
app/default (`0`) — an ordering the four existing names cannot express. Mapping tenancy onto
`identity` or `default` would silently change fresh-install chain order (the exact class of
bug the beta.1 seed-ordering incident taught us to respect). The enum stays closed; it gains
one name with today's exact value.

- [ ] **Step 1: Write the failing test** — add to `ManifestMigrationDescriptorsTest`:

```php
    public function testPlatformPriorityMapsToItsDedicatedSlot(): void
    {
        $m = $this->manifest([$this->extensionPkg(['migrations' => [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'platform', 'mode' => 'on_enable'],
        ]])]);
        self::assertSame(-50, $m->migrationDescriptors()['acme/widgets'][0]->priority);
    }
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Unit/Extensions/Schema/ManifestMigrationDescriptorsTest.php` → FAILURE (unknown priority fails closed).
- [ ] **Step 3: Implement** — in `MigrationPriority`: add `public const PLATFORM = -50;` between
  IDENTITY and DEFAULT with a comment naming its consumer ("control-plane tiers that must land
  after identity but before app/default — e.g. glueful/tenancy"). In `migrationDescriptors()`'s
  `$priorities` map add `'platform' => MigrationPriority::PLATFORM,` and update the malformed-
  descriptor exception's closed-enum vocabulary to include `platform`. In the Thallo spec's B1
  closed-enum sentence, add `platform` to the priority list with one clause of rationale.
  Add the changelog bullet under the **pending 1.79.0 Added section**, because this priority must
  be present in the first published 1.79.0 artifact: `'platform' (-50) migration priority name;
  used by glueful/tenancy's control-plane descriptor.`
- [ ] **Step 4: Run to verify pass** + `vendor/bin/phpunit tests/Unit/Extensions/Schema` + `composer phpcs`.
- [ ] **Step 5: Commit** (framework) `git commit -m "feat(migrations): 'platform' priority name for post-identity pre-default control planes"`; (thallo) `git commit -m "docs(specs): closed priority enum gains 'platform'"`.

---

### Task 2: Engine extensions — aegis, commerce, payvia, subscriptions

**Files (per repo, branch `schema-adoption` off `dev`):**
- Modify: `composer.json` (`extra.glueful.migrations`, `extra.glueful.requires.glueful`,
  `extra.glueful.requires.extensions`, require-dev framework `^1.79`)
- Modify: the existing provider that calls `loadMigrationsFrom()` (remove that call and its
  now-unused `MigrationPriority` import)
- Create: `src/Schema/<Name>SchemaVerifier.php`
- Test: `tests/Unit/Schema/SchemaManifestTest.php` (new, per repo)
- Test: `tests/Integration/Schema/SchemaVerifierBehaviorTest.php` (new, placed under the repo's
  existing integration-test namespace/layout)
- Modify: `CHANGELOG.md` per the global constraint.

**Interfaces:**
- Consumes: framework 1.79.0's `StructuralVerifierInterface`, `MigrationDescriptor` rules.
- Produces, per repo, the manifest block (exact values — all four are `dependent`/`on_enable`,
  `id "default"`, `path "migrations"`):

```json
"extra": { "glueful": {
  "provider": "<existing provider FQCN, unchanged>",
  "requires": { "glueful": ">=1.79.0", "extensions": [] },
  "migrations": [
    { "id": "default", "path": "migrations", "priority": "dependent",
      "mode": "on_enable",
      "verifier": "<PackageNamespace>\\Schema\\<Name>SchemaVerifier" }
  ]
}}
```

- [ ] **Step 1: Derive and record each migration's exact observable effect** — discover files
  recursively with framework `FileFinder::findMigrations()` (never `glob('migrations/*.php')`).
  For every basename, inspect the complete `up()` body and record the minimum sufficient proof:
  - create-only: every table created by that migration;
  - ALTER: the exact columns, nullability/defaults, indexes, and constraints introduced;
  - seed/backfill: the canonical rows/data invariant plus the resulting schema constraint;
  - mixed migrations: all applicable proofs above.

  The known non-table-only cases that MUST receive effect-specific checks are Aegis
  `003_SeedDefaultRoles.php`; Commerce `021_EnforceStockQuantityTrackedNotNull.php` and
  `022_AddWalkInOrderFieldsAndDraftAttemptLedger.php`; Payvia
  `006_AddProviderEventsDispatchIndex.php`, `009_AddProviderEventDispatchClaimToken.php`,
  `011_AddCheckoutOriginationReconciliationColumns.php`, and
  `012_AddPaymentIntentAttemptLifecycle.php`; Subscriptions `006_SubjectModel.php`,
  `007_CheckoutReservations.php`, and `008_PlanProviderIdentifiers.php`. A parent table's
  existence is explicitly insufficient for all of these. Unknown basenames return false.

- [ ] **Step 2: Pin `requires.extensions` from behavioral authority, not source grep.** The
  current first-party matrix for these four packages is exactly `[]` for each:
  - Aegis's Users integration is a tagged enricher and its migrations deliberately avoid a
    cross-package Users foreign key; migration priority supplies ordering, not enable dependency.
  - Commerce uses host/framework seams and guards optional tenancy integration.
  - Payvia has no hard first-party extension dependency.
  - Subscriptions deliberately boots without Payvia or Tenancy; those integrations are
    runtime-detected/optional and MUST NOT become `requires.extensions` edges.

  Add a resolver test per package proving it resolves when enabled alone. For every optional
  integration named by `composer suggest`, `interface_exists`, `class_exists`, a container tag,
  or a provider docblock, add/retain a provider boot smoke test with that extension absent.
  Record this evidence in the commit body. A future non-empty edge requires the converse proof:
  provider resolution/boot must fail without that provider and pass with it.

- [ ] **Step 3: Write the failing test** (identical file per repo, namespaces adjusted):

```php
<?php

declare(strict_types=1);

namespace <PackageTestNamespace>\Unit\Schema;

use PHPUnit\Framework\TestCase;

final class SchemaManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        return $composer['extra']['glueful'];
    }

    public function testDeclaresExactlyOneDefaultDependentOnEnableDescriptor(): void
    {
        $migrations = $this->manifest()['migrations'];
        self::assertCount(1, $migrations);
        self::assertSame('default', $migrations[0]['id']);
        self::assertSame('migrations', $migrations[0]['path']);
        self::assertSame('dependent', $migrations[0]['priority']);
        self::assertSame('on_enable', $migrations[0]['mode']);
        self::assertSame('>=1.79.0', $this->manifest()['requires']['glueful']);
        self::assertSame([], $this->manifest()['requires']['extensions']);
    }

    public function testVerifierClassConformsToTheManifestContract(): void
    {
        $class = $this->manifest()['migrations'][0]['verifier'];
        self::assertTrue(class_exists($class));
        self::assertTrue(is_subclass_of($class, \Glueful\Extensions\Schema\StructuralVerifierInterface::class));
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertTrue($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
        self::assertSame('<package-name>', (new $class())->source());
    }

    public function testVerifierCoversEveryRecursivelyDiscoveredMigration(): void
    {
        $class = $this->manifest()['migrations'][0]['verifier'];
        $mapped = (new $class())->migrationBasenames();
        $root = dirname(__DIR__, 3) . '/migrations';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $shipped = [];
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $shipped[] = $file->getBasename();
            }
        }
        sort($shipped);
        sort($mapped);
        self::assertSame($shipped, $mapped, 'every migration file needs a verifier proof');
    }

    public function testProviderNoLongerRegistersTheManifestOwnedPath(): void
    {
        $provider = $this->manifest()['provider'];
        $file = (new \ReflectionClass($provider))->getFileName();
        self::assertIsString($file);
        self::assertStringNotContainsString('loadMigrationsFrom(', (string) file_get_contents($file));
    }
}
```

- [ ] **Step 4: Write the failing behavioral verifier test** using that repository's existing
  SQLite migration harness. For EACH basename, create two isolated fixtures:
  1. a predecessor/incomplete fixture where prerequisites exist but at least one effect owned by
     that migration is absent (empty schema for first creates; prior migrations or the repo's
     existing legacy-schema fixture for ALTER/seed/backfill work), and assert `verify()` is false;
  2. the same fixture after that migration's `up()`, and assert `verify()` is true.

  Do not assume the current fresh-install chain is always a valid pre-effect fixture: some repos
  fold final columns into an earlier create migration while retaining a later migration for
  upgrades. The deliberately incomplete predecessor fixture is the authority. Also assert an
  unknown basename returns false. Where an effect is driver-specific (named indexes/constraints
  or nullability), add the repository's existing PostgreSQL-gated sibling so SQLite cannot
  approve a weak verifier.

- [ ] **Step 5: Run to verify failure** (per repo, after the framework path-repo wiring from
  Global Constraints): manifest test fails because metadata/verifier is absent; behavior test
  fails because no effect proofs exist.

- [ ] **Step 6: Implement the verifier** as a closed `match ($migrationBasename)` with
  `default => false`, plus a public `migrationBasenames(): array`. The Aegis proof is pinned:
  - `001` requires `roles` and `user_roles` with the columns/unique keys the migration creates;
  - `002` requires `permissions`, `role_permissions`, `user_permissions`, and
    `permission_audit` with their created columns/unique keys;
  - `003` requires active system roles `superuser|administrator|user`, all 15 seeded system
    permissions (`system.access`, `system.config`, the four `users.*`, five `roles.*`, and four
    `content.*` slugs), and all 30 migration-defined role/permission pairs. Extra host-managed
    rows do not invalidate the proof.

  Commerce, Payvia, and Subscriptions implement the Step 1 effect inventory with the same closed
  match. Use `SchemaBuilderInterface::getTableColumns()` / `getTableSchema()` and narrowly scoped
  driver metadata queries where `hasColumn()` cannot prove nullability, defaults, indexes, or
  constraints. Then write the manifest block, update `requires.glueful`, bump require-dev
  (`"glueful/framework": "^1.79"`), and remove the provider's `loadMigrationsFrom()` call/import.

- [ ] **Step 7: Run to verify pass** — manifest + behavioral verifier tests, full
  `composer test`, `composer phpcs`, and the correctly spelled analysis command from Global
  Constraints.
- [ ] **Step 8: Commit per repo** — `git commit -m "feat(schema): manifest descriptor and effect-verified adoption"` with the Step 2 evidence in the body. Remove only the uncommitted path-repository entry and lock change per Global Constraints.

---

### Task 3: Service extensions — audit, email-notification, i18n, import-export

Identical steps 1-8 to Task 2 with these exact value differences per repo:
- `priority` is `"default"` (matching today's `MigrationPriority::DEFAULT` calls) — the
  `SchemaManifestTest` asserts `'default'` instead of `'dependent'`.
- Sources: `glueful/audit`, `glueful/email-notification`, `glueful/i18n`,
  `glueful/import-export`; verifier classes `AuditSchemaVerifier`,
  `EmailNotificationSchemaVerifier`, `I18nSchemaVerifier`, `ImportExportSchemaVerifier` in each
  repo's `src/Schema/`.
- These repos ship 1-2 create migrations each; their effect-specific verifiers prove every
  created table and its load-bearing columns/unique keys. The sequential pre/post behavior test
  remains required even when the proof is table-oriented.
- `requires.extensions` is exactly `[]` for all four. Audit's Aegis integration is explicitly
  optional (`composer suggest`); the other three have no hard extension provider dependency.
  Resolver-alone and optional-provider-absent boot tests pin that result.

- [ ] Steps 1-8 as in Task 2, per repo, with the values above. Write the full manifest and
  behavioral verifier tests per repo; remove each provider's migration-registration call.

---

### Task 4: Identity extension — glueful/users

Task 2's steps with:
- `priority`: `"identity"` (today's `MigrationPriority::IDENTITY`); assert `'identity'` in the test.
- Verifier `Glueful\Extensions\Users\Schema\UsersSchemaVerifier`, source `glueful/users`,
  with effect proofs for its 2 migrations.
- `requires.extensions`: exactly empty. Email notification is a documented optional channel, so
  Users must resolve and boot without that provider.

- [ ] Steps 1-8 as in Task 2 with these values, including provider-call removal and the
  sequential verifier behavior test.

---

### Task 5: Tenancy — core mode, platform priority, dedicated-lifecycle note

Task 2's steps with these differences (spec B6):
- The descriptor is **`mode: "core"`** — tenancy's control-plane migrations
  (`TenancyControlPlaneProvider`, always loaded) provision with every install; its
  ENFORCEMENT lifecycle stays in Thallo's protected state machine (Plan 3 swaps that machine
  onto the shared executor — nothing in this task touches it).
- `priority`: `"platform"` (Task 1's slot — byte-identical ordering to today's `DEFAULT - 50`).
- Verifier `Glueful\Extensions\Tenancy\Schema\TenancySchemaVerifier` (adjust to the repo's real
  root namespace), source `glueful/tenancy`, with effect proofs for its 4 migrations.
- `requires.extensions`: exactly empty. Aegis is a suggested optional bypass authority, not a
  hard enable dependency; Tenancy must resolve and boot without it.
- The `SchemaManifestTest` asserts `'core'` + `'platform'`.
- Override Task 2's provider-call architecture assertion to inspect
  `TenancyControlPlaneProvider` explicitly (the manifest provider is `TenancyServiceProvider`,
  but the control-plane provider owns today's `loadMigrationsFrom()` call). The test must fail
  while either provider contains a manifest-owned registration call.
- Add one doc line to the repo README or provider docblock: generic `extensions:enable` refuses
  tenancy (protected); the descriptor exists for provision/readiness/adoption, not for the
  generic enable flow.

- [ ] Steps 1-8 as in Task 2 with these values, including removal of
  `TenancyControlPlaneProvider`'s manifest-owned migration-registration call.

---

### Task 6: Schema-free extensions — media, meilisearch

**Files (per repo):** `composer.json`, `tests/Unit/Schema/SchemaManifestTest.php`, and
`CHANGELOG.md`.

- [ ] **Step 1:** Confirm no migrations dir and no `loadMigrationsFrom()` call:
  `ls migrations 2>/dev/null; grep -rn loadMigrationsFrom src/`. If either exists, STOP — the
  repo belongs in Task 3's shape instead.
- [ ] **Step 2:** Add `"migrations": "none"` and set
  `"requires": {"glueful": ">=1.79.0", "extensions": []}` in `extra.glueful`; bump require-dev
  framework to `^1.79`. Add a unit test that projects the real composer manifest and asserts the
  package appears in `migrationDescriptors()` with an empty descriptor list, does not appear in
  `undeclaredGluefulPackages()`, and resolves with no other extension enabled.
- [ ] **Step 3:** Run `composer test`, `composer phpcs`, and `composer analyze`.
- [ ] **Step 4: Commit** — `git commit -m "feat(schema): declares schema-free manifest"`.

---

### Task 7: Cross-package validation sweep + ledger

**Files:** none committed (a throwaway script + a Thallo ledger append).

- [ ] **Step 1: Run the sweep** — from the framework repo (branch with Tasks 1's commit), a
  one-off script that assembles a synthetic `installed.json` from the 12 REAL `composer.json`
  files + REAL package dirs and proves the whole set projects and conforms:

```php
<?php // /tmp scratch: validate-adoption.php — run: php validate-adoption.php
require '/Users/michaeltawiahsowah/Sites/glueful/framework/vendor/autoload.php';

$extensionsRoot = '/Users/michaeltawiahsowah/Sites/glueful/extensions';
$names = ['aegis','commerce','payvia','subscriptions','tenancy','users',
          'audit','email-notification','i18n','import-export','media','meilisearch'];
$base = sys_get_temp_dir() . '/adoption-sweep-' . uniqid();
mkdir($base . '/vendor/composer', 0777, true);
$packages = [];
$must = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
foreach ($names as $name) {
    // installed.json metadata does not register Composer autoloaders. Load each package's
    // real autoloader deterministically before checking its manifest-declared verifier.
    require_once "$extensionsRoot/$name/vendor/autoload.php";
    $composer = json_decode(
        (string) file_get_contents("$extensionsRoot/$name/composer.json"),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $composer['install-path'] = "$extensionsRoot/$name"; // absolute real package root
    $packages[] = $composer;
}
file_put_contents(
    $base . '/vendor/composer/installed.json',
    json_encode(['packages' => $packages], JSON_THROW_ON_ERROR)
);

$manifest = new Glueful\Extensions\PackageManifest(new Glueful\Bootstrap\ApplicationContext($base));
$inventory = Glueful\Extensions\Schema\DescriptorInventory::fromManifest(
    $manifest,
    '/Users/michaeltawiahsowah/Sites/glueful/framework',
    new Glueful\Services\FileFinder()
); // throws on ANY fail-closed violation across the whole set

$declared = $manifest->migrationDescriptors();
$must($manifest->undeclaredGluefulPackages() === [], 'first-party package remains undeclared');
$must(array_keys($declared) === [
    'glueful/aegis', 'glueful/audit', 'glueful/commerce', 'glueful/email-notification',
    'glueful/i18n', 'glueful/import-export', 'glueful/media', 'glueful/meilisearch',
    'glueful/payvia', 'glueful/subscriptions', 'glueful/tenancy', 'glueful/users',
], 'declared package set drifted');
$must($declared['glueful/media'] === [], 'media must declare migrations: none');
$must($declared['glueful/meilisearch'] === [], 'meilisearch must declare migrations: none');
$must(count($inventory->all()) === 18, 'expected 10 extension + 8 framework descriptors');
foreach ($inventory->all() as $d) {
    if ($d->verifierClass === null) { continue; } // framework built-ins
    $must(class_exists($d->verifierClass), "missing verifier {$d->verifierClass}");
    $must(
        is_subclass_of($d->verifierClass, Glueful\Extensions\Schema\StructuralVerifierInterface::class),
        "non-conforming verifier {$d->verifierClass}"
    );
    $reflection = new ReflectionClass($d->verifierClass);
    $constructor = $reflection->getConstructor();
    $must(
        $constructor === null || ($constructor->isPublic() && $constructor->getNumberOfRequiredParameters() === 0),
        "verifier constructor is not public/zero-required for {$d->source()}"
    );
    $v = new ($d->verifierClass)();
    $must($v->source() === $d->source(), "verifier source mismatch for {$d->source()}");
    $expected = array_map('basename', $inventory->filesOf($d));
    $actual = $v->migrationBasenames();
    sort($expected);
    sort($actual);
    $must($actual === $expected, "verifier basename coverage mismatch for {$d->source()}");
}
echo "adoption sweep: OK — " . count($inventory->all()) . " descriptors\n";
```

  Expected exactly: `adoption sweep: OK — 18 descriptors` (10 schema-owning extension
  descriptors + 8 framework built-ins; the two `migrations: none` packages are declared but
  correctly contribute zero descriptors). Any exception here is a Task 2-6 bug — fix it there.
- [ ] **Step 2: Ledger** — append the outcome (descriptor count, per-repo commit ids, the
  pinned empty `requires.extensions` matrix, and verifier behavior-test results) to
  `.superpowers/sdd/2026-08-15-distribution-cycle/progress.md` in Thallo.
- [ ] **Step 3: Keep the ledger uncommitted** — it is working-state evidence and travels with no
  source commit. Record the 12 extension commit ids in the implementation handoff.

---

### Task 8: Release train and hard publication gate

Plan 3 must consume released artifacts, not sibling checkouts. Thallo's root Composer file installs
all 12 extensions from Packagist/dist, so local adoption commits are not a deliverable by themselves.

- [ ] **Step 1: Publish framework 1.79.0 first.** Task 1's `platform` priority must be inside the
  same artifact as the descriptor contract. Run the framework release gates, update
  `Glueful\Support\Version::VERSION` through the normal release process, and stop for the user to
  tag/publish `v1.79.0`. Verify a clean temporary Composer project resolves
  `glueful/framework:1.79.0` from Packagist and that `PackageManifest` accepts `platform`.

- [ ] **Step 2: Prepare one minor release per extension** (manifest contract + raised framework
  runtime floor are additive but operationally significant):
  - aegis `1.15.0`; commerce `1.13.0`; payvia `2.8.0`; subscriptions `2.3.0`;
  - audit `1.4.0`; email-notification `1.13.0`; i18n `1.2.0`; import-export `1.2.0`;
  - users `2.4.0`; tenancy `2.1.0`; media `1.2.0`; meilisearch `1.7.0`.

  In each repo, set `extra.glueful.version`, promote the schema-adoption changelog entry into the
  dated release section (create `Unreleased` first where absent), run the full per-repo gate again
  against the **published** framework 1.79.0 with no path repository, and commit release prep.

- [ ] **Step 3: User publication sitting.** The user tags/publishes all 12 versions above. Do not
  begin Plan 3 until every version resolves from Packagist. Corrections after a tag get a new patch
  release; tags are never moved.

- [ ] **Step 4: Distribution proof.** In one clean temporary Composer project, require all 12
  exact released versions plus framework `1.79.0`, build a real `DescriptorInventory` from the
  generated `installed.json`, and rerun Task 7's assertions without path repositories. Expected:
  the same 12 declared packages, 18 descriptors, zero undeclared packages, all verifier classes
  autoloadable. Record the exact resolved versions and artifact commit ids in the handoff to Plan 3.

## Self-review (completed)

- **Spec coverage:** B9 step 2 in full — `on_enable` descriptors (Tasks 2-4), tenancy's core
  exception (Task 5, per B6), `migrations: none` (Task 6), `requires.extensions` populated
  with behavioral evidence everywhere, effect-specific structural verifiers per B7's
  manifest-declared contract, provider-owned registration removed, and released artifacts proven
  before Plan 3 (Task 8). The one contract addition (`platform`) is an explicit spec amendment
  (Task 1), not drift.
- **Identity safety:** every descriptor id is `default` ⇒ sources byte-match today's ledger
  receipts; no aliases, no normalization, no re-ordering (tenancy keeps -50 via `platform`).
- **Adoption proof:** every migration is tested against an isolated predecessor fixture that
  lacks one of its effects, then against the post-migration fixture; table existence cannot
  accidentally certify later ALTER/seed/backfill work, and folded fresh-install schemas do not
  invalidate the test model. Recursive basename coverage and unknown-basename refusal are
  ratchets in every repo.
- **Dependency safety:** the current matrix is explicitly empty and behavior-tested. Optional
  Payvia/Tenancy/Aegis/email integrations remain optional rather than becoming false hard edges.
- **Type/runtime consistency:** `migrationBasenames(): list<string>` is asserted against recursive
  inventory; `source()` values and public zero-required constructors are pinned; runtime
  `requires.glueful` and development constraints both require 1.79.
- **Distribution safety:** temporary path repositories carry an explicit 1.79.0 version override
  and are removed surgically; the final gate re-runs inventory assertions against Packagist/dist
  artifacts only.
