# Schema-on-Enable Program — Plan 2 of 3: First-Party Extension Adoption

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every first-party Glueful extension Thallo installs adopts the manifest migration
contract (spec `docs/internal/superpowers/specs/2026-08-17-schema-creation-policy-design.md`
B1/B3/B7, as implemented by framework 1.79.0): descriptor declarations (or explicit
`"migrations": "none"`), accurate `requires.extensions`, and a structural verifier per
schema-owning package.

**Architecture:** Pure adoption — no framework behavior changes except one small named
addition to the priority enum (Task 1) that tenancy's existing ordering requires. Each
package's ledger identity is already its composer name, so **no legacy aliases and no receipt
normalization are needed anywhere in this plan**. Verifiers are small per-package classes with
a basename→required-tables map.

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
- Framework 1.79.0 is NOT yet published: for any task that runs code against the new APIs, wire the local framework via `composer config repositories.framework path ../../framework && composer update glueful/framework` **without committing** the `repositories` entry or a lock file (`git checkout composer.json` the repositories key before committing; the require-dev constraint bump IS committed).
- Ledger identity is untouchable: every descriptor uses `id: "default"` so `source()` equals the composer package name — byte-identical to today's receipts. No `legacyAliases` anywhere.
- Manifest values must use the closed enums exactly: priority `foundation|identity|platform|default|dependent` (Task 1 adds `platform`), mode `core|on_enable`.
- Verifiers: public zero-required-argument constructor, `source()` returns the package name exactly, `verify()` uses schema existence checks (verifiers are the one place `hasTable` probing is the point).
- Per-repo gate: that repo's own `composer test` (and `composer analyse`/phpstan where the repo defines it) green before its commit.
- Update each repo's CHANGELOG (if the repo keeps one) under Unreleased: "Declares the Glueful schema manifest (migration descriptors, requires.extensions, structural verifier); requires framework ^1.79 for schema-on-enable participation."

---

### Task 1: Framework — add the `platform` priority name (tenancy's ordering slot)

**Files (framework repo `/Users/michaeltawiahsowah/Sites/glueful/framework`, branch `schema-adoption` off `dev`):**
- Modify: `src/Database/Migrations/MigrationPriority.php`
- Modify: `src/Extensions/PackageManifest.php` (priority map in `migrationDescriptors()`)
- Modify: `CHANGELOG.md` (Unreleased)
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
  `$priorities` map add `'platform' => MigrationPriority::PLATFORM,`. In the Thallo spec's B1
  closed-enum sentence, add `platform` to the priority list with one clause of rationale.
  CHANGELOG Unreleased: `### Added — 'platform' (-50) migration priority name; used by
  glueful/tenancy's control-plane descriptor.`
- [ ] **Step 4: Run to verify pass** + `vendor/bin/phpunit tests/Unit/Extensions/Schema` + `composer phpcs`.
- [ ] **Step 5: Commit** (framework) `git commit -m "feat(migrations): 'platform' priority name for post-identity pre-default control planes"`; (thallo) `git commit -m "docs(specs): closed priority enum gains 'platform'"`.

---

### Task 2: Engine extensions — aegis, commerce, payvia, subscriptions

**Files (per repo, branch `schema-adoption` off `dev`):**
- Modify: `composer.json` (`extra.glueful.migrations`, `extra.glueful.requires.extensions`, require-dev framework `^1.79`)
- Create: `src/Schema/<Name>SchemaVerifier.php`
- Test: `tests/Unit/Schema/SchemaManifestTest.php` (new, per repo)
- Modify: `CHANGELOG.md` per the global constraint.

**Interfaces:**
- Consumes: framework 1.79.0's `StructuralVerifierInterface`, `MigrationDescriptor` rules.
- Produces, per repo, the manifest block (exact values — all four are `dependent`/`on_enable`,
  `id "default"`, `path "migrations"`):

```json
"extra": { "glueful": {
  "provider": "<existing provider FQCN, unchanged>",
  "requires": { "extensions": [ /* Step 2's evidence-based list */ ] },
  "migrations": [
    { "id": "default", "path": "migrations", "priority": "dependent",
      "mode": "on_enable",
      "verifier": "<PackageNamespace>\\Schema\\<Name>SchemaVerifier" }
  ]
}}
```

- [ ] **Step 1: Derive each verifier's basename→tables map** — for every file in the repo's
  `migrations/`, list the tables its `up()` creates:
  `grep -n "createTable('" migrations/*.php` (include `hasTable` guards to know conditional
  creates). The map is `['001_Foo.php' => ['foo_table'], …]` — every migration file MUST have
  an entry; a migration that creates no table (pure seed/alter) maps to the tables it alters
  (existence is still the structural signal). Record the map in the verifier class.

- [ ] **Step 2: Derive `requires.extensions` with evidence** — for each repo,
  `grep -rn "Glueful\\\\Extensions\\\\" src/ --include="*.php" | grep -v "<own namespace>"`
  plus provider docblocks (aegis's already documents ordering after `glueful/users`). An entry
  is added ONLY for a hard runtime dependency on another extension's provider being enabled
  (class references to its services/tables), listed as that extension's provider FQCN (the
  `ExtensionCandidate::requiresExtensions` convention). Record the grep evidence in the commit
  message body. Expected shape from today's code (verify, do not assume): aegis →
  `[Glueful\Extensions\Users\UsersServiceProvider FQCN]`; subscriptions → commerce and/or
  payvia providers if referenced; commerce/payvia → likely none or payvia←commerce; empty
  lists are CORRECT when the grep shows no hard dependency — accuracy over completeness
  theater.

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

    public function testVerifierMapCoversEveryShippedMigration(): void
    {
        $class = $this->manifest()['migrations'][0]['verifier'];
        $mapped = array_keys((new $class())->map());
        $shipped = array_map('basename', glob(dirname(__DIR__, 3) . '/migrations/*.php'));
        sort($mapped);
        sort($shipped);
        self::assertSame($shipped, $mapped, 'every migration file needs a verifier map entry');
    }

    public function testRequiresExtensionsIsDeclared(): void
    {
        self::assertIsArray($this->manifest()['requires']['extensions']);
    }
}
```

- [ ] **Step 4: Run to verify failure** (per repo, after the framework path-repo wiring from Global Constraints).

- [ ] **Step 5: Implement the verifier** (full example for aegis; the other three differ only in
  namespace, source, and map):

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/aegis (schema policy spec B7): a migration's receipt may be
 * adopted only when every table that migration owns actually exists. hasTable probing is the
 * POINT here — this class is the structural evidence the generic adoption command must not
 * infer on its own.
 */
final class AegisSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/aegis';
    }

    /** @return array<string, list<string>> migration basename => tables that must exist */
    public function map(): array
    {
        return [
            // Step 1's derived map goes here, one entry per shipped migration file.
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        $tables = $this->map()[$migrationBasename] ?? null;
        if ($tables === null) {
            return false; // unknown basename: never adoptable
        }
        $schema = $db->getSchemaBuilder();
        foreach ($tables as $table) {
            if (!$schema->hasTable($table)) {
                return false;
            }
        }
        return true;
    }
}
```

  Then write the manifest block and the require-dev bump (`"glueful/framework": "^1.79"`).

- [ ] **Step 6: Run to verify pass** — the repo's `SchemaManifestTest` + full `composer test`.
- [ ] **Step 7: Commit per repo** — `git commit -m "feat(schema): manifest migration descriptor, requires.extensions, structural verifier"` with the Step 2 evidence in the body. Revert the uncommitted `repositories` key.

---

### Task 3: Service extensions — audit, email-notification, i18n, import-export

Identical steps 1-7 to Task 2 with these exact value differences per repo:
- `priority` is `"default"` (matching today's `MigrationPriority::DEFAULT` calls) — the
  `SchemaManifestTest` asserts `'default'` instead of `'dependent'`.
- Sources: `glueful/audit`, `glueful/email-notification`, `glueful/i18n`,
  `glueful/import-export`; verifier classes `AuditSchemaVerifier`,
  `EmailNotificationSchemaVerifier`, `I18nSchemaVerifier`, `ImportExportSchemaVerifier` in each
  repo's `src/Schema/`.
- These repos ship 1-2 migrations each; the maps are small. `requires.extensions` follows the
  same evidence rule (expected empty for all four — verify with the grep).

- [ ] Steps 1-7 as in Task 2, per repo, with the values above. (Write the full test and
  verifier files per repo — copy the Task 2 code with the adjusted namespace/source/priority.)

---

### Task 4: Identity extension — glueful/users

Task 2's steps with:
- `priority`: `"identity"` (today's `MigrationPriority::IDENTITY`); assert `'identity'` in the test.
- Verifier `Glueful\Extensions\Users\Schema\UsersSchemaVerifier`, source `glueful/users`,
  map over its 2 migrations.
- `requires.extensions`: expected empty (users is the identity ROOT others depend on) — verify.

- [ ] Steps 1-7 as in Task 2 with these values.

---

### Task 5: Tenancy — core mode, platform priority, dedicated-lifecycle note

Task 2's steps with these differences (spec B6):
- The descriptor is **`mode: "core"`** — tenancy's control-plane migrations
  (`TenancyControlPlaneProvider`, always loaded) provision with every install; its
  ENFORCEMENT lifecycle stays in Thallo's protected state machine (Plan 3 swaps that machine
  onto the shared executor — nothing in this task touches it).
- `priority`: `"platform"` (Task 1's slot — byte-identical ordering to today's `DEFAULT - 50`).
- Verifier `Glueful\Extensions\Tenancy\Schema\TenancySchemaVerifier` (adjust to the repo's real
  root namespace), source `glueful/tenancy`, map over its 4 migrations.
- `requires.extensions`: evidence rule as usual.
- The `SchemaManifestTest` asserts `'core'` + `'platform'`.
- Add one doc line to the repo README or provider docblock: generic `extensions:enable` refuses
  tenancy (protected); the descriptor exists for provision/readiness/adoption, not for the
  generic enable flow.

- [ ] Steps 1-7 as in Task 2 with these values.

---

### Task 6: Schema-free extensions — media, meilisearch

**Files (per repo):** `composer.json` only (+ CHANGELOG line).

- [ ] **Step 1:** Confirm no migrations dir and no `loadMigrationsFrom()` call:
  `ls migrations 2>/dev/null; grep -rn loadMigrationsFrom src/`. If either exists, STOP — the
  repo belongs in Task 3's shape instead.
- [ ] **Step 2:** Add `"migrations": "none"` and `"requires": {"extensions": []}` (evidence rule)
  to `extra.glueful`; bump require-dev framework to `^1.79`.
- [ ] **Step 3:** Run the repo's `composer test`.
- [ ] **Step 4: Commit** — `git commit -m "feat(schema): declares migrations: none — schema-free extension"`.

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
foreach ($names as $name) {
    $composer = json_decode(file_get_contents("$extensionsRoot/$name/composer.json"), true);
    $composer['install-path'] = "../../../../glueful/extensions/$name"; // resolves to the real dir
    $packages[] = $composer;
}
file_put_contents($base . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));

$manifest = new Glueful\Extensions\PackageManifest(new Glueful\Bootstrap\ApplicationContext($base));
$inventory = Glueful\Extensions\Schema\DescriptorInventory::fromManifest(
    $manifest,
    '/Users/michaeltawiahsowah/Sites/glueful/framework',
    new Glueful\Services\FileFinder()
); // throws on ANY fail-closed violation across the whole set

assert($manifest->undeclaredGluefulPackages() === [], 'no first-party package may remain undeclared');
foreach ($inventory->all() as $d) {
    if ($d->verifierClass === null) { continue; } // media/meilisearch/none + framework built-ins
    // Verifier conformance, autoloaded from each repo (composer autoload-dev must reach it;
    // require the class file directly from the package dir if the sweep runs without it).
    assert(is_subclass_of($d->verifierClass, Glueful\Extensions\Schema\StructuralVerifierInterface::class));
    $v = new ($d->verifierClass)();
    assert($v->source() === $d->source(), "verifier source mismatch for {$d->source()}");
}
echo "adoption sweep: OK — " . count($inventory->all()) . " descriptors\n";
```

  Expected: `adoption sweep: OK` with the 12 packages' descriptors + framework built-ins, zero
  exceptions. Any `DescriptorValidationException` here is a Task 2-6 bug — fix it there.
- [ ] **Step 2: Ledger** — append the outcome (descriptor count, per-repo commit ids, the
  evidence-based `requires.extensions` results) to
  `.superpowers/sdd/2026-08-15-distribution-cycle/progress.md` in Thallo.
- [ ] **Step 3: Commit (thallo)** — the ledger note travels with Thallo's next docs commit (ledger is untracked working notes; no commit needed).

## Self-review (completed)

- **Spec coverage:** B9 step 2 in full — `on_enable` descriptors (Tasks 2-4), tenancy's core
  exception (Task 5, per B6), `migrations: none` (Task 6), `requires.extensions` populated
  with evidence everywhere, structural verifiers per B7's manifest-declared contract. The one
  contract addition (`platform`) is an explicit spec amendment (Task 1), not drift.
- **Identity safety:** every descriptor id is `default` ⇒ sources byte-match today's ledger
  receipts; no aliases, no normalization, no re-ordering (tenancy keeps -50 via `platform`).
- **Placeholders:** the per-repo maps and dependency lists are DERIVED DATA with the exact
  extraction method, a fully worked verifier example, and tests that fail if a map misses a
  shipped migration — not deferred decisions.
- **Type consistency:** `map(): array<string, list<string>>` is asserted by every repo's
  `SchemaManifestTest`; `source()` values are pinned per task.
