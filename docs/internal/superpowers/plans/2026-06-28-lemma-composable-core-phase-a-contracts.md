# Lemma Composable Core — Phase A: Contracts Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract a thin, in-repo `glueful/lemma-contracts` package containing the *content-engine* seams (delivery read, content write, schema-read, lifecycle events, pack context) and make the existing Lemma engine implement them — without changing any runtime behavior.

**Architecture:** A new Composer **path package** at `packages/lemma-contracts` (PSR-4 `Glueful\Lemma\Contracts\`, library, `0.x`). The package holds interfaces + DTO/VO contracts only — no engine logic, storage, or I/O. The existing `App\Content\*` engine classes implement these interfaces (directly where additive, via thin adapters where the contract must stay high-level). The DI container binds each contract interface to its engine implementation. This is **Phase A of four** (A contracts → B capability spine → C admin registry → D reference extraction); it ships independently and changes no behavior.

**Tech Stack:** PHP 8.3, Glueful framework 1.64, Composer path repositories, PHPUnit 10.5. Pure-unit tests extend `PHPUnit\Framework\TestCase` (reflection, no DB); integration/binding tests extend `App\Tests\Support\LemmaTestCase` (boots the app, `$this->container()`, DB-backed, requires `composer test:migrate`).

## Global Constraints

- **Contracts hold interfaces + DTOs + events only** — no engine logic, storage, or I/O in `packages/lemma-contracts`.
- **`lemma-contracts` must not depend on `glueful/lemma` (the app) nor reference `App\*` classes.** Any field/schema/entity a contract method touches must itself be a contract type (e.g. `FieldDescriptor`, not `App\Content\Schema\FieldDefinition`).
- **Namespace:** `Glueful\Lemma\Contracts\` → `packages/lemma-contracts/src/`.
- **Package version starts `0.1.0`** and stays `0.x` while only first-party packs exist (per spec §8 — move to `1.0` only before documenting third-party pack authoring).
- **No behavior change.** Existing routes, events, delivery output, and tests must remain green; contracts are additive seams over current code.
- **Scope is Phase A only.** Do **not** build: the capability switchboard / discovery (Phase B), the admin `registerAdminModule` registry (Phase C), the reference-pack extraction or `ContentBundleReader/Writer` (Phase D), the field-type *registry* write-side, or rich return VOs. Those are explicitly deferred; keep contract returns as `array` where the engine returns `array` today.
- **Glueful is NOT Laravel.** Service definitions are a static `services()` array in `App\Providers\LemmaServiceProvider`; resolve via `$this->container()->get(X::class)`; context-first helpers (`app($context, X::class)`).
- **DI alias direction (footgun — verified in `DefaultServicesLoader`):** `Id => ['alias' => X]` makes the name `X` resolve to `Id`, **not** the reverse. To bind an interface to a concrete, write the alias on the **concrete**: `Concrete::class => ['class' => Concrete::class, 'alias' => [Interface::class]]`. Never write `Interface::class => ['alias' => Concrete::class]` (it leaves the interface bound to `new Interface()` and is rejected as non-instantiable).
- **Commit gate (workflow):** Do **not** run `git commit` until the human explicitly authorizes it. Each task's final step keeps its `git add` (staging) but its `git commit` line runs **only after authorization** — until then, stage, stop, and report. This applies to every task below.

---

### Task 1: Scaffold the `lemma-contracts` path package

**Files:**
- Create: `packages/lemma-contracts/composer.json`
- Create: `packages/lemma-contracts/src/ContractsManifest.php`
- Modify: `composer.json` (root — add path repository + require)
- Test: `tests/Unit/Contracts/ContractsPackageTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\ContractsManifest` with `public const VERSION = '0.1.0';` — a marker proving the package autoloads. Later tasks add real contracts under `Glueful\Lemma\Contracts\*`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Contracts;

use Glueful\Lemma\Contracts\ContractsManifest;
use PHPUnit\Framework\TestCase;

final class ContractsPackageTest extends TestCase
{
    public function testPackageAutoloadsUnderContractsNamespace(): void
    {
        self::assertTrue(class_exists(ContractsManifest::class));
        self::assertSame('0.1.0', ContractsManifest::VERSION);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Contracts/ContractsPackageTest.php`
Expected: FAIL — `Class "Glueful\Lemma\Contracts\ContractsManifest" not found`.

- [ ] **Step 3: Create the package `composer.json`**

`packages/lemma-contracts/composer.json`:
```json
{
  "name": "glueful/lemma-contracts",
  "description": "Thin, stable contracts (interfaces, DTOs, events, VOs) that Lemma capability packs compile against.",
  "type": "library",
  "license": "MIT",
  "version": "0.1.0",
  "require": {
    "php": "^8.3"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Lemma\\Contracts\\": "src/"
    }
  },
  "minimum-stability": "stable"
}
```

- [ ] **Step 4: Create the marker class**

`packages/lemma-contracts/src/ContractsManifest.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts;

/**
 * Marker for the lemma-contracts package. Holds nothing but the package version —
 * proof the package is installed and autoloading. Real contracts live in subnamespaces.
 */
final class ContractsManifest
{
    public const VERSION = '0.1.0';
}
```

- [ ] **Step 5: Wire the path package into the root `composer.json`**

In `/Users/michaeltawiahsowah/Sites/glueful/lemma/composer.json`, add a top-level `repositories` key and add the require. Insert `repositories` before `require`:
```json
  "repositories": [
    { "type": "path", "url": "packages/lemma-contracts" }
  ],
```
And add to the `require` block (keep `sort-packages` ordering — it sorts on `composer update`):
```json
    "glueful/lemma-contracts": "*",
```

- [ ] **Step 6: Install the path package**

Run: `composer update glueful/lemma-contracts --no-interaction`
Expected: Composer symlinks `packages/lemma-contracts` into `vendor/glueful/lemma-contracts`; autoloader regenerated. Verify: `php -r "require 'vendor/autoload.php'; echo Glueful\Lemma\Contracts\ContractsManifest::VERSION;"` prints `0.1.0`.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Contracts/ContractsPackageTest.php`
Expected: PASS.

- [ ] **Step 8: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts composer.json composer.lock tests/Unit/Contracts/ContractsPackageTest.php
# When authorized:
git commit -m "Scaffold glueful/lemma-contracts path package"
```

---

### Task 2: Schema-read contracts (`FieldDescriptor`, `ContentSchemaReader`)

**Why first among the contracts:** later contracts (the reference resolver, content writer) must reference *fields* and *schemas* as contract types, never the concrete `App\Content\Schema\*`. This task provides those types. The engine's concrete classes implement them **additively** (no signature changes to existing methods).

**Files:**
- Create: `packages/lemma-contracts/src/Schema/FieldDescriptor.php`
- Create: `packages/lemma-contracts/src/Schema/ContentSchemaReader.php`
- Modify: `app/Content/Schema/FieldDefinition.php` (add `implements FieldDescriptor` + accessor methods)
- Modify: `app/Content/Schema/ContentTypeSchema.php` (add `implements ContentSchemaReader`)
- Test: `tests/Unit/Contracts/SchemaReadContractTest.php`

**Interfaces:**
- Consumes: nothing (first contract).
- Produces:
  - `Glueful\Lemma\Contracts\Schema\FieldDescriptor` — `name(): string`, `type(): string`, `isMultiple(): bool`, `referenceType(): ?string`, `referenceSlugField(): ?string`.
  - `Glueful\Lemma\Contracts\Schema\ContentSchemaReader` — `fields(): array` (`list<FieldDescriptor>`), `field(string $name): ?FieldDescriptor`.
  - `App\Content\Schema\FieldDefinition` now implements `FieldDescriptor`; `App\Content\Schema\ContentTypeSchema` now implements `ContentSchemaReader`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Contracts;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class SchemaReadContractTest extends TestCase
{
    public function testFieldDefinitionImplementsDescriptor(): void
    {
        $f = FieldDefinition::fromArray([
            'name' => 'tags', 'type' => 'reference', 'multiple' => true,
            'reference_type' => 'tag', 'reference_slug_field' => 'slug',
        ]);
        self::assertInstanceOf(FieldDescriptor::class, $f);
        self::assertSame('tags', $f->name());
        self::assertSame('reference', $f->type());
        self::assertTrue($f->isMultiple());
        self::assertSame('tag', $f->referenceType());
        self::assertSame('slug', $f->referenceSlugField());
    }

    public function testContentTypeSchemaImplementsReader(): void
    {
        $s = ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
        ]);
        self::assertInstanceOf(ContentSchemaReader::class, $s);
        self::assertInstanceOf(FieldDescriptor::class, $s->field('title'));
        self::assertNull($s->field('nope'));
        self::assertContainsOnlyInstancesOf(FieldDescriptor::class, $s->fields());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Contracts/SchemaReadContractTest.php`
Expected: FAIL — `Interface "Glueful\Lemma\Contracts\Schema\FieldDescriptor" not found`.

- [ ] **Step 3: Create the contract interfaces**

`packages/lemma-contracts/src/Schema/FieldDescriptor.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Schema;

/**
 * Read-only view of a single content-type field. Packs and core contracts depend on
 * this, never on the concrete engine field class.
 */
interface FieldDescriptor
{
    public function name(): string;
    public function type(): string;
    public function isMultiple(): bool;
    public function referenceType(): ?string;
    public function referenceSlugField(): ?string;
}
```

`packages/lemma-contracts/src/Schema/ContentSchemaReader.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Schema;

/**
 * Read-only view of a content-type schema.
 */
interface ContentSchemaReader
{
    /** @return list<FieldDescriptor> */
    public function fields(): array;

    public function field(string $name): ?FieldDescriptor;
}
```

- [ ] **Step 4: Make `FieldDefinition` implement `FieldDescriptor` (additive)**

In `app/Content/Schema/FieldDefinition.php`: add the `use` + `implements`, and add accessor methods that return the existing readonly props. Do **not** remove or rename any existing property.

Class declaration:
```php
use Glueful\Lemma\Contracts\Schema\FieldDescriptor;

final class FieldDefinition implements FieldDescriptor
```
Add these methods to the class body:
```php
    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function referenceType(): ?string
    {
        return $this->referenceType;
    }

    public function referenceSlugField(): ?string
    {
        return $this->referenceSlugField;
    }
```

- [ ] **Step 5: Make `ContentTypeSchema` implement `ContentSchemaReader`**

In `app/Content/Schema/ContentTypeSchema.php`, add the `use` + `implements`. Its existing `fields(): array` and `field(string $name): ?FieldDefinition` already satisfy the interface — `?FieldDefinition` is a covariant return for `?FieldDescriptor` because `FieldDefinition implements FieldDescriptor` (Task 2 Step 4). No method-body changes.
```php
use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;

final class ContentTypeSchema implements ContentSchemaReader
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Contracts/SchemaReadContractTest.php`
Expected: PASS.

- [ ] **Step 7: Run the existing schema/validation tests to confirm no regression**

Run: `vendor/bin/phpunit tests/Unit/Content/FieldValidatorTest.php`
Expected: PASS (unchanged behavior).

- [ ] **Step 8: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Schema app/Content/Schema/FieldDefinition.php app/Content/Schema/ContentTypeSchema.php tests/Unit/Contracts/SchemaReadContractTest.php
# When authorized:
git commit -m "Add schema-read contracts; engine schema classes implement them"
```

---

### Task 3: Promote `ContentReindexerInterface` → `Contracts\Search\ContentReindexer`

**Files:**
- Create: `packages/lemma-contracts/src/Search/ContentReindexer.php`
- Modify: `app/Content/Search/ContentReindexerInterface.php` (re-point to extend the contract, keep as deprecated alias).
- No implementor/consumer changes needed — because the old interface *extends* the new contract, every existing implementor and the `ReindexSearchListener` keep working unchanged (Step 2's grep is for awareness only).
- Test: `tests/Unit/Contracts/ContentReindexerContractTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\Search\ContentReindexer` — `reindexEntry(string $entryUuid, string $locale): void` (identical signature to today's `ContentReindexerInterface`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Contracts;

use Glueful\Lemma\Contracts\Search\ContentReindexer;
use PHPUnit\Framework\TestCase;

final class ContentReindexerContractTest extends TestCase
{
    public function testContractExistsWithReindexEntry(): void
    {
        self::assertTrue(interface_exists(ContentReindexer::class));
        $m = new \ReflectionMethod(ContentReindexer::class, 'reindexEntry');
        self::assertSame('entryUuid', $m->getParameters()[0]->getName());
        self::assertSame('locale', $m->getParameters()[1]->getName());
    }
}
```

- [ ] **Step 2: Find all references to the old interface**

Run: `grep -rn "ContentReindexerInterface" app/ tests/`
Note every file. Expected consumers: a registration in `app/Providers/LemmaServiceProvider.php` and any no-op/real reindexer implementation under `app/Content/Search/`.

- [ ] **Step 3: Create the contract**

`packages/lemma-contracts/src/Search/ContentReindexer.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Search;

/**
 * Reindex a single published entry/locale into a pack-owned search index.
 * Implemented by core's no-op default and by a search pack when installed.
 */
interface ContentReindexer
{
    public function reindexEntry(string $entryUuid, string $locale): void;
}
```

- [ ] **Step 4: Make the old interface extend the contract (back-compat alias)**

Replace the body of `app/Content/Search/ContentReindexerInterface.php` so the old name keeps working but is now the contract:
```php
<?php
declare(strict_types=1);
namespace App\Content\Search;

use Glueful\Lemma\Contracts\Search\ContentReindexer;

/**
 * @deprecated Use Glueful\Lemma\Contracts\Search\ContentReindexer. Retained as an
 *             alias so existing bindings/implementors keep resolving during migration.
 */
interface ContentReindexerInterface extends ContentReindexer
{
}
```

- [ ] **Step 5: Do NOT add a binding — the search seam is optional**

Add **no** container binding for `ContentReindexer`. Reindexing is an optional seam: `app/Content/Pipeline/Listeners/ReindexSearchListener.php` gates on `$this->container->has(ContentReindexerInterface::class)` and no-ops when nothing is bound, and there is no core implementation to bind to. Binding the bare interface would be wrong-direction and rejected as non-instantiable (see Global Constraints). A search pack will bind a concrete reindexer in a later phase.

> **Forward note (not for this task):** the listener still gates on the *old* id `ContentReindexerInterface::class`. Because the old interface now extends the new contract, a pack binding the old id keeps working. Migrating the listener's gate to check `Glueful\Lemma\Contracts\Search\ContentReindexer::class` is deferred to the search pack's phase — do not change the listener here.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Contracts/ContentReindexerContractTest.php`
Expected: PASS.
Run: `vendor/bin/phpunit --testsuite Unit`
Expected: PASS (the alias-by-extension changes no runtime behavior; the listener still no-ops with nothing bound).

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Search app/Content/Search/ContentReindexerInterface.php tests/Unit/Contracts/ContentReindexerContractTest.php
# When authorized:
git commit -m "Promote ContentReindexer to lemma-contracts (old interface extends it; seam stays optional)"
```

---

### Task 4: Lifecycle-event contract (`ContentLifecycleEvent`)

**Files:**
- Create: `packages/lemma-contracts/src/Events/ContentLifecycleEvent.php`
- Modify: `app/Content/Events/BaseContentEvent.php` (implement the contract; expose stable identity)
- Test: `tests/Unit/Contracts/ContentLifecycleEventContractTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\Events\ContentLifecycleEvent` — `name(): string`, `payload(): array`. `App\Content\Events\BaseContentEvent` (abstract) implements it; all concrete entry/model/asset events inherit the implementation. Packs type-hint the interface to react to "something happened" and read `name()` + `payload()` for identity, without depending on `App\*` event classes.

**Note:** `BaseContentEvent` already declares `abstract public function name(): string;` and `abstract public function payload(): array;` (verified in the engine). So implementing the interface is purely adding `implements` — the concrete events already satisfy it.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Contracts;

use App\Content\Events\EntryCreated;
use Glueful\Lemma\Contracts\Events\ContentLifecycleEvent;
use PHPUnit\Framework\TestCase;

final class ContentLifecycleEventContractTest extends TestCase
{
    public function testConcreteEventIsALifecycleEvent(): void
    {
        $e = new EntryCreated(
            entry: 'ent000000001', type: 'type00000001', locale: 'en', version: null, actor: null,
        );
        self::assertInstanceOf(ContentLifecycleEvent::class, $e);
        self::assertSame('entry.created', $e->name());
        self::assertIsArray($e->payload());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Contracts/ContentLifecycleEventContractTest.php`
Expected: FAIL — `Interface "Glueful\Lemma\Contracts\Events\ContentLifecycleEvent" not found`.

- [ ] **Step 3: Create the contract**

`packages/lemma-contracts/src/Events/ContentLifecycleEvent.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Events;

/**
 * Stable subscription surface for content lifecycle events. Packs (search, render,
 * analytics) type-hint this and read name()/payload() to react, without depending on
 * the concrete engine event classes.
 */
interface ContentLifecycleEvent
{
    /** Stable event name, e.g. "entry.created", "entry.published". */
    public function name(): string;

    /** @return array<string,mixed> Identity + change summary; never the full field set. */
    public function payload(): array;
}
```

- [ ] **Step 4: Make `BaseContentEvent` implement the contract**

In `app/Content/Events/BaseContentEvent.php`, add the contract to the `implements` list (it already implements `AuditableEvent`). Keep the existing abstract `name()`/`payload()` declarations — they satisfy the interface.
```php
use Glueful\Lemma\Contracts\Events\ContentLifecycleEvent;

// e.g.:  abstract class BaseContentEvent implements AuditableEvent, ContentLifecycleEvent
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Contracts/ContentLifecycleEventContractTest.php`
Expected: PASS.
Run: `vendor/bin/phpunit tests/Unit/Content/ContentEventAuditActorTest.php`
Expected: PASS (audit-actor behavior unchanged).

- [ ] **Step 6: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Events app/Content/Events/BaseContentEvent.php tests/Unit/Contracts/ContentLifecycleEventContractTest.php
# When authorized:
git commit -m "Add ContentLifecycleEvent contract; BaseContentEvent implements it"
```

---

### Task 5: Promote `ReferenceTargetResolver` → contracts (re-typed to `FieldDescriptor`)

**Files:**
- Create: `packages/lemma-contracts/src/Delivery/ReferenceTargetResolver.php`
- Modify: `app/Content/Delivery/ReferenceTargetResolver.php` (convert to deprecated alias extending the contract)
- Modify: every implementor of the old interface (find in Step 2) — re-type the `$field` param to the contract `FieldDescriptor`.
- Test: `tests/Unit/Contracts/ReferenceTargetResolverContractTest.php`

**Interfaces:**
- Consumes: `Glueful\Lemma\Contracts\Schema\FieldDescriptor` (Task 2).
- Produces: `Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver` — `resolve(FieldDescriptor $field, string $locale, array $values): array` (`@param list<string> $values`, `@return list<string>`). The old `App\Content\Delivery\ReferenceTargetResolver` becomes a deprecated alias.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Contracts;

use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Lemma\Contracts\Schema\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class ReferenceTargetResolverContractTest extends TestCase
{
    public function testContractTakesFieldDescriptorNotEngineClass(): void
    {
        self::assertTrue(interface_exists(ReferenceTargetResolver::class));
        $p = (new \ReflectionMethod(ReferenceTargetResolver::class, 'resolve'))->getParameters();
        self::assertSame(FieldDescriptor::class, (string) $p[0]->getType());
        self::assertSame('locale', $p[1]->getName());
        self::assertSame('values', $p[2]->getName());
    }
}
```

- [ ] **Step 2: Find all implementors/consumers**

Run: `grep -rn "ReferenceTargetResolver" app/ tests/`
Note every implementor (a class `implements ReferenceTargetResolver`) and every binding in `LemmaServiceProvider`.

- [ ] **Step 3: Create the contract (typed to `FieldDescriptor`)**

`packages/lemma-contracts/src/Delivery/ReferenceTargetResolver.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Delivery;

use Glueful\Lemma\Contracts\Schema\FieldDescriptor;

/**
 * Resolve a reference field's raw values (uuids and/or slugs) to canonical target uuids.
 */
interface ReferenceTargetResolver
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function resolve(FieldDescriptor $field, string $locale, array $values): array;
}
```

- [ ] **Step 4: Convert the old interface to a deprecated alias**

Replace `app/Content/Delivery/ReferenceTargetResolver.php`:
```php
<?php
declare(strict_types=1);
namespace App\Content\Delivery;

use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver as Contract;

/**
 * @deprecated Use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver.
 */
interface ReferenceTargetResolver extends Contract
{
}
```

- [ ] **Step 5: Re-type implementors to the contract's `FieldDescriptor`**

For each implementor found in Step 2 (e.g. `app/Content/Delivery/ReferenceFilterResolver.php`), change its `resolve()` signature param from `App\Content\Schema\FieldDefinition $field` to `Glueful\Lemma\Contracts\Schema\FieldDescriptor $field`, and update the `use`. Because `FieldDefinition implements FieldDescriptor` (Task 2), all call sites that pass a `FieldDefinition` still satisfy the widened param. Inside the method, replace any concrete-only property access (`$field->multiple`, `$field->referenceSlugField`) with the contract accessors (`$field->isMultiple()`, `$field->referenceSlugField()`).

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Contracts/ReferenceTargetResolverContractTest.php`
Expected: PASS.
Run: `vendor/bin/phpunit --testsuite Unit`
Expected: PASS. If a reference/delivery test fails, the cause is a missed concrete-property access in Step 5 — convert it to the accessor.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Delivery app/Content/Delivery tests/Unit/Contracts/ReferenceTargetResolverContractTest.php app/Providers/LemmaServiceProvider.php
# When authorized:
git commit -m "Promote ReferenceTargetResolver to lemma-contracts, typed to FieldDescriptor"
```

---

### Task 6: High-level `ContentWriter` contract + engine adapter

**Files:**
- Create: `packages/lemma-contracts/src/Authoring/ContentWriter.php`
- Create: `app/Content/Authoring/EngineContentWriter.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (bind the contract → adapter)
- Test: `tests/Integration/Contracts/ContentWriterContractTest.php`

**Interfaces:**
- Consumes:
  - `App\Content\Repositories\ContentTypeRepository` — `findByUuid(string $uuid): ?array` (row carries `schema` as an array and `schema_version` as int).
  - `App\Content\Schema\ContentTypeSchema` — `fromArray(array $raw): self`.
  - `App\Content\Validation\FieldValidator` — `validate(ContentTypeSchema $schema, array $payload): array` (returns the validated, cleaned payload; throws `App\Content\Validation\ValidationException` on bad input).
  - `App\Content\Repositories\EntryRepository` — `createEntry(string $contentTypeUuid, string $locale, int $schemaVersion, ?string $actor): string` (also seeds an empty draft at `lock_version: 0`), `saveDraft(string $entryUuid, string $locale, array $fields, int $schemaVersion, int $expectedLockVersion, ?string $actor): void` (**requires an already-validated, cleaned payload**).
  - `App\Content\Services\PublishService` — `publish(string $entryUuid, string $locale, ?string $actor): string`.
- Produces: `Glueful\Lemma\Contracts\Authoring\ContentWriter`:
  - `createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string` — returns the new entry uuid.
  - `publish(string $entryUuid, string $locale, ?string $actor = null): string` — returns the publication/version uuid.

  Kept deliberately **high-level** (no repository primitives, no lock-version, no schema-version — the adapter resolves those from the content type). This is the only sanctioned content-write path for a pack.

- [ ] **Step 1: Write the failing test** (DB-backed; extends `LemmaTestCase`)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Integration\Contracts;

use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;

final class ContentWriterContractTest extends LemmaTestCase
{
    public function testContractResolvesToEngineAdapter(): void
    {
        self::assertInstanceOf(ContentWriter::class, $this->container()->get(ContentWriter::class));
    }

    public function testCreateDraftPersistsAnEntryAndCleanedDraft(): void
    {
        $typeUuid = $this->seedContentType();           // helper below
        $writer = $this->container()->get(ContentWriter::class);

        // 'sneaky' is not in the schema — validation must drop it, proving the writer
        // runs core validation rather than persisting raw input.
        $entryUuid = $writer->createDraft($typeUuid, 'en', ['title' => 'Hello', 'sneaky' => 'x'], 'usr000000001');

        self::assertNotEmpty($entryUuid);
        $entry = $this->connection()->table('entries')->where('uuid', $entryUuid)->first();
        self::assertNotNull($entry);
        $draft = $this->connection()->table('entry_drafts')
            ->where('entry_uuid', $entryUuid)->where('locale', 'en')->first();
        self::assertNotNull($draft);
        $fields = json_decode((string) $draft['fields'], true);
        self::assertSame(['title' => 'Hello'], $fields); // unknown key dropped by validation
    }

    public function testCreateDraftRejectsInvalidFields(): void
    {
        $typeUuid = $this->seedContentType();
        $writer = $this->container()->get(ContentWriter::class);

        // 'title' is required; omitting it must throw, not silently persist.
        $this->expectException(ValidationException::class);
        $writer->createDraft($typeUuid, 'en', ['title' => ''], 'usr000000001');
    }

    /** Minimal content type row so createDraft has a schema to resolve. */
    private function seedContentType(): string
    {
        $uuid = 'type00000001';
        $this->connection()->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => 'post',
            'name' => 'Post',
            'schema' => json_encode([['name' => 'title', 'type' => 'string', 'required' => true]]),
            'schema_version' => 1,
        ]);
        return $uuid;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:migrate && vendor/bin/phpunit tests/Integration/Contracts/ContentWriterContractTest.php`
Expected: FAIL — `Glueful\Lemma\Contracts\Authoring\ContentWriter` not found / not bound.

> If `seedContentType()`'s columns don't match the real `content_types` schema, adjust the insert to the actual columns (inspect with `\d content_types` or read the content-types migration). The test's intent — "createDraft makes an entry + draft" — stays fixed.

- [ ] **Step 3: Create the contract**

`packages/lemma-contracts/src/Authoring/ContentWriter.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Authoring;

/**
 * High-level content authoring for packs (e.g. importers). Packs ask core to create
 * drafts and publish; they never touch content repositories or tables directly.
 */
interface ContentWriter
{
    /**
     * Create a new entry with an initial draft for $locale.
     *
     * @param array<string,mixed> $fields
     * @return string The new entry uuid.
     */
    public function createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string;

    /**
     * Publish the current draft for $entryUuid/$locale.
     *
     * @return string The publication (version) uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor = null): string;
}
```

- [ ] **Step 4: Create the engine adapter**

`app/Content/Authoring/EngineContentWriter.php`:
```php
<?php
declare(strict_types=1);
namespace App\Content\Authoring;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;

/**
 * Adapts the engine's authoring services to the high-level ContentWriter contract.
 * This is the sanctioned content-write path for packs: it ENFORCES core validation
 * (FieldValidator) before persisting, exactly like the HTTP EntryController does, and
 * resolves schema/lock versions internally so packs never see them.
 */
final class EngineContentWriter implements ContentWriter
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly PublishService $publisher,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
    ) {
    }

    public function createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new \RuntimeException("content type {$contentTypeUuid} not found");
        }
        $schema = ContentTypeSchema::fromArray($type['schema']);
        $schemaVersion = (int) $type['schema_version'];

        // Enforce core validation up front — saveDraft() requires an already-cleaned
        // payload. Throws ValidationException on bad input (same contract as the HTTP path).
        $clean = $this->validator->validate($schema, $fields);

        // createEntry() also seeds an empty draft at lock_version 0, so saveDraft() with
        // expectedLockVersion 0 CAS-matches and writes the validated fields.
        $entryUuid = $this->entries->createEntry($contentTypeUuid, $locale, $schemaVersion, $actor);
        $this->entries->saveDraft($entryUuid, $locale, $clean, $schemaVersion, 0, $actor);
        return $entryUuid;
    }

    public function publish(string $entryUuid, string $locale, ?string $actor = null): string
    {
        return $this->publisher->publish($entryUuid, $locale, $actor);
    }
}
```

All method names above are verified against the engine: `ContentTypeRepository::findByUuid()` (row has `schema` array + `schema_version` int), `FieldValidator::validate(ContentTypeSchema, array): array`, and `EntryRepository::createEntry()` seeding a `lock_version: 0` draft.

- [ ] **Step 5: Bind the contract to the adapter**

In `app/Providers/LemmaServiceProvider.php` `services()`, add:
```php
\Glueful\Lemma\Contracts\Authoring\ContentWriter::class => [
    'class'    => \App\Content\Authoring\EngineContentWriter::class,
    'shared'   => true,
    'autowire' => true,
],
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContentWriterContractTest.php`
Expected: PASS.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Authoring app/Content/Authoring app/Providers/LemmaServiceProvider.php tests/Integration/Contracts/ContentWriterContractTest.php
# When authorized:
git commit -m "Add high-level ContentWriter contract + engine adapter"
```

---

### Task 7: `ContentDeliveryReader` contract + engine adapter

**Files:**
- Create: `packages/lemma-contracts/src/Delivery/ContentDeliveryReader.php`
- Create: `app/Content/Delivery/EngineContentDeliveryReader.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (bind contract → adapter)
- Test: `tests/Integration/Contracts/ContentDeliveryReaderContractTest.php`

**Interfaces:**
- Consumes: `App\Content\Delivery\DeliveryRepository` (`listPublished(string $contentTypeUuid, string $locale, int $limit = 20, ?array $filter = null, ?array $order = null, ?array $cursor = null): array`; `findPublishedByUuid(string $contentTypeUuid, string $locale, string $entryUuid): ?array`; `findPublishedByRoute(string $contentTypeUuid, string $locale, string $slug): ?array`).
- Produces: `Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader`:
  - `listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array` — list of published rows (`array<int,array<string,mixed>>`).
  - `findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array` — one published row or null; tries route(slug) then uuid.

  Return shape is `array` (matching the engine today). Rich return VOs are deferred per Global Constraints.

- [ ] **Step 1: Write the failing test** (DB-backed)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;

final class ContentDeliveryReaderContractTest extends LemmaTestCase
{
    public function testContractResolvesToEngineAdapter(): void
    {
        self::assertInstanceOf(ContentDeliveryReader::class, $this->container()->get(ContentDeliveryReader::class));
    }

    public function testListPublishedReturnsArrayForUnknownTypeWithoutError(): void
    {
        $reader = $this->container()->get(ContentDeliveryReader::class);
        // No published content seeded: an empty, well-typed result, not an exception.
        self::assertSame([], $reader->listPublished('type00000001', 'en', 10));
        self::assertNull($reader->findPublished('type00000001', 'en', 'missing-slug'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContentDeliveryReaderContractTest.php`
Expected: FAIL — contract not found / not bound.

- [ ] **Step 3: Create the contract**

`packages/lemma-contracts/src/Delivery/ContentDeliveryReader.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Read-only access to PUBLISHED content. Never exposes drafts. Consumed by render,
 * search, and reference-resolving packs.
 */
interface ContentDeliveryReader
{
    /** @return array<int,array<string,mixed>> Published rows for the type/locale. */
    public function listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array;

    /** @return array<string,mixed>|null One published row (by slug, else uuid) or null. */
    public function findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array;
}
```

- [ ] **Step 4: Create the engine adapter**

`app/Content/Delivery/EngineContentDeliveryReader.php`:
```php
<?php
declare(strict_types=1);
namespace App\Content\Delivery;

use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;

/**
 * Adapts DeliveryRepository (publication-spine queries) to the ContentDeliveryReader
 * contract. find tries route(slug) first, then falls back to uuid lookup.
 */
final class EngineContentDeliveryReader implements ContentDeliveryReader
{
    public function __construct(private readonly DeliveryRepository $delivery)
    {
    }

    public function listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array
    {
        return $this->delivery->listPublished($contentTypeUuid, $locale, $limit);
    }

    public function findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array
    {
        return $this->delivery->findPublishedByRoute($contentTypeUuid, $locale, $slugOrUuid)
            ?? $this->delivery->findPublishedByUuid($contentTypeUuid, $locale, $slugOrUuid);
    }
}
```

> **Verify against the engine:** `DeliveryRepository::listPublished` returns a list under some key — confirm whether it returns the rows directly or a `['data' => [...], 'cursor' => ...]` envelope (read the method). If it returns an envelope, the adapter must return the rows array (e.g. `$result['data'] ?? []`) so the contract's `array<int,array>` return holds. Keep the test assertion (`[]` for no content) as the fixed truth.

- [ ] **Step 5: Bind the contract**

In `app/Providers/LemmaServiceProvider.php` `services()`, add:
```php
\Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader::class => [
    'class'    => \App\Content\Delivery\EngineContentDeliveryReader::class,
    'shared'   => true,
    'autowire' => true,
],
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContentDeliveryReaderContractTest.php`
Expected: PASS.
Run: `vendor/bin/phpunit --testsuite Feature`
Expected: PASS (delivery HTTP output unchanged — the adapter only wraps existing methods).

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Delivery/ContentDeliveryReader.php app/Content/Delivery/EngineContentDeliveryReader.php app/Providers/LemmaServiceProvider.php tests/Integration/Contracts/ContentDeliveryReaderContractTest.php
# When authorized:
git commit -m "Add ContentDeliveryReader contract + engine adapter"
```

---

### Task 8: `LemmaContext` contract + engine implementation

**Files:**
- Create: `packages/lemma-contracts/src/Context/LemmaContext.php`
- Create: `app/Content/Context/EngineLemmaContext.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (bind contract → impl)
- Test: `tests/Integration/Contracts/LemmaContextContractTest.php`

**Interfaces:**
- Consumes: `App\Content\Localization\ContentLocaleService` (`default(): string`, `enabled(): array`, `isEnabled(string $locale): bool`); `App\Settings\GeneralSettings` (`all(): array`, `siteName(): string`); `App\Content\Seo\PathRenderer` (`render(string $contentTypeSlug, string $locale, string $slug): string`).
- Produces: `Glueful\Lemma\Contracts\Context\LemmaContext`:
  - `defaultLocale(): string`
  - `enabledLocales(): array` (`list<string>`)
  - `setting(string $key, mixed $default = null): mixed`
  - `renderPath(string $contentTypeSlug, string $locale, string $slug): string`

  The sanctioned scoped-service seam — the alternative to packs reaching for global helpers. (Actor identity is request-scoped and is threaded through the write/event contracts rather than this process-scoped context, so it is intentionally **not** on `LemmaContext` in Phase A.)

- [ ] **Step 1: Write the failing test** (DB-backed; settings/locale come from the booted app)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Context\LemmaContext;

final class LemmaContextContractTest extends LemmaTestCase
{
    public function testContextResolvesAndExposesLocaleAndSettings(): void
    {
        $ctx = $this->container()->get(LemmaContext::class);
        self::assertInstanceOf(LemmaContext::class, $ctx);

        self::assertNotSame('', $ctx->defaultLocale());
        self::assertContains($ctx->defaultLocale(), $ctx->enabledLocales());

        // Unknown setting returns the provided default.
        self::assertSame('fallback', $ctx->setting('definitely.missing.key', 'fallback'));

        // Path rendering delegates to the SEO PathRenderer.
        self::assertStringContainsString('post', $ctx->renderPath('post', $ctx->defaultLocale(), 'hello'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Contracts/LemmaContextContractTest.php`
Expected: FAIL — contract not found / not bound.

- [ ] **Step 3: Create the contract**

`packages/lemma-contracts/src/Context/LemmaContext.php`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Lemma\Contracts\Context;

/**
 * Scoped access to the core services a pack is allowed to use — the sanctioned
 * alternative to reaching for global helpers or app internals.
 */
interface LemmaContext
{
    public function defaultLocale(): string;

    /** @return list<string> */
    public function enabledLocales(): array;

    public function setting(string $key, mixed $default = null): mixed;

    /** Public path for an entry, e.g. "/en/post/hello". */
    public function renderPath(string $contentTypeSlug, string $locale, string $slug): string;
}
```

- [ ] **Step 4: Create the engine implementation**

`app/Content/Context/EngineLemmaContext.php`:
```php
<?php
declare(strict_types=1);
namespace App\Content\Context;

use App\Content\Localization\ContentLocaleService;
use App\Content\Seo\PathRenderer;
use App\Settings\GeneralSettings;
use Glueful\Lemma\Contracts\Context\LemmaContext;

final class EngineLemmaContext implements LemmaContext
{
    public function __construct(
        private readonly ContentLocaleService $locales,
        private readonly GeneralSettings $settings,
        private readonly PathRenderer $paths,
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->locales->default();
    }

    public function enabledLocales(): array
    {
        return array_values($this->locales->enabled());
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->all()[$key] ?? $default;
    }

    public function renderPath(string $contentTypeSlug, string $locale, string $slug): string
    {
        return $this->paths->render($contentTypeSlug, $locale, $slug);
    }
}
```

> **Verify against the engine:** confirm `GeneralSettings::all()` returns a flat `key => value` map keyed the way `setting()` callers will expect; if settings are nested, either flatten in `setting()` or expose the specific getters the contract needs. Confirm `ContentLocaleService::enabled()` returns a `list<string>` of locale codes (adjust `array_values(...)` / mapping if it returns richer rows). Confirm `PathRenderer` is constructible by the container (it has scalar constructor defaults — if it's not already a bound service, add a `services()` entry mirroring how other SEO services are registered).

- [ ] **Step 5: Bind the contract**

In `app/Providers/LemmaServiceProvider.php` `services()`, add:
```php
\Glueful\Lemma\Contracts\Context\LemmaContext::class => [
    'class'    => \App\Content\Context\EngineLemmaContext::class,
    'shared'   => true,
    'autowire' => true,
],
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/Integration/Contracts/LemmaContextContractTest.php`
Expected: PASS.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Context app/Content/Context app/Providers/LemmaServiceProvider.php tests/Integration/Contracts/LemmaContextContractTest.php
# When authorized:
git commit -m "Add LemmaContext scoped-service contract + engine implementation"
```

---

### Task 9: Contract-conformance suite + boundary guard + version policy

**Files:**
- Create: `tests/Integration/Contracts/ContractConformanceTest.php`
- Create: `scripts/check-pack-boundaries.php`
- Modify: `composer.json` (root — add a `boundaries` script)
- Create/Modify: `packages/lemma-contracts/README.md` (record the 0.x → 1.0 freeze policy)
- Test: the conformance test itself + a run of the boundary script.

**Interfaces:**
- Consumes: every contract bound in Tasks 3–8.
- Produces: a single conformance test asserting all Phase-A contracts resolve from the container to a concrete implementation; a CI-runnable `scripts/check-pack-boundaries.php` enforcing "no `glueful/lemma-*` pack depends on `glueful/lemma`" (forward-looking — passes vacuously until packs exist in Phase D).

- [ ] **Step 1: Write the failing conformance test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Integration\Contracts;

use App\Content\Delivery\ReferenceTargetResolver as OldReferenceTargetResolver;
use App\Content\Search\ContentReindexerInterface as OldContentReindexer;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;
use Glueful\Lemma\Contracts\Context\LemmaContext;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;
use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Lemma\Contracts\Search\ContentReindexer;

final class ContractConformanceTest extends LemmaTestCase
{
    /**
     * Contracts that core binds to a concrete implementation (Tasks 6–8). These MUST
     * resolve from the container.
     *
     * @return list<array{0:class-string}>
     */
    public static function boundContractProvider(): array
    {
        return [
            [ContentWriter::class],
            [ContentDeliveryReader::class],
            [LemmaContext::class],
        ];
    }

    /** @dataProvider boundContractProvider */
    public function testBoundContractResolvesToAConcreteImplementation(string $contract): void
    {
        $impl = $this->container()->get($contract);
        self::assertInstanceOf($contract, $impl);
        self::assertFalse((new \ReflectionClass($impl))->isAbstract());
    }

    /**
     * Promoted seams that are intentionally OPTIONAL/unbound in core (a pack binds them
     * later): ContentReindexer (search) and ReferenceTargetResolver. We don't require
     * them to resolve — only that the old engine interface now extends the new contract,
     * so existing implementors satisfy the contract for free.
     */
    public function testPromotedInterfacesExtendTheirContracts(): void
    {
        self::assertTrue(is_subclass_of(OldContentReindexer::class, ContentReindexer::class));
        self::assertTrue(is_subclass_of(OldReferenceTargetResolver::class, ReferenceTargetResolver::class));
    }
}
```

- [ ] **Step 2: Run it to verify it passes** (the three bindings exist after Tasks 6–8; the extension assertions hold after Tasks 3 & 5)

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContractConformanceTest.php`
Expected: PASS.

- [ ] **Step 3: Write the boundary-guard script**

`scripts/check-pack-boundaries.php`:
```php
<?php
declare(strict_types=1);

/**
 * Fails if any first-party pack under packages/ declares a Composer dependency on
 * glueful/lemma (the engine app). Packs may depend on glueful/lemma-contracts,
 * glueful/framework, and pack-specific deps — never on the engine package.
 */
$root = dirname(__DIR__);
$violations = [];
foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifest) {
    $json = json_decode((string) file_get_contents($manifest), true);
    $name = $json['name'] ?? $manifest;
    if (($json['name'] ?? '') === 'glueful/lemma-contracts') {
        continue; // the contracts package itself is exempt
    }
    $deps = array_merge($json['require'] ?? [], $json['require-dev'] ?? []);
    if (array_key_exists('glueful/lemma', $deps)) {
        $violations[] = "{$name} depends on glueful/lemma (forbidden — use glueful/lemma-contracts)";
    }
}
if ($violations !== []) {
    fwrite(STDERR, "Pack boundary violations:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}
echo "Pack boundaries OK (" . count(glob($root . '/packages/*/composer.json') ?: []) . " package(s) checked)\n";
exit(0);
```

- [ ] **Step 4: Add the composer script**

In root `composer.json` `scripts`, add:
```json
    "boundaries": "php scripts/check-pack-boundaries.php",
```

- [ ] **Step 5: Run the boundary guard**

Run: `composer boundaries`
Expected: `Pack boundaries OK (1 package(s) checked)` (only `lemma-contracts` exists; it's exempt, so vacuously clean).

- [ ] **Step 6: Record the version policy**

`packages/lemma-contracts/README.md`:
```markdown
# glueful/lemma-contracts

Thin, stable contracts (interfaces, DTOs, events, VOs) that Lemma capability packs
compile against. **No engine logic, storage, or I/O.**

## Stability policy

- Strict semver. Additive change = minor; any interface / DTO / event / capability-id
  break = major.
- **0.x freeze trigger:** this package stays `0.x` while only first-party packs exist.
  It moves to `1.0` only **before** documenting third-party pack authoring or accepting
  external packs — so the seams are proven (by the Phase D reference extraction) first.

## Boundary rule

A pack may depend on `glueful/lemma-contracts`, `glueful/framework`, and pack-specific
deps — **never on `glueful/lemma`** (the engine app). Enforced by
`composer boundaries` (`scripts/check-pack-boundaries.php`).
```

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add tests/Integration/Contracts/ContractConformanceTest.php scripts/check-pack-boundaries.php composer.json packages/lemma-contracts/README.md
# When authorized:
git commit -m "Add contract-conformance test, pack-boundary guard, and 0.x version policy"
```

---

## Phase A — Definition of Done

- `glueful/lemma-contracts` installs as a path package and autoloads.
- Three content-engine contracts are container-bound to engine implementations: `ContentWriter` (validation-enforcing), `ContentDeliveryReader`, `LemmaContext`.
- Two seams are promoted as **optional/unbound** contracts whose old engine interfaces now extend them: `ContentReindexer` (search), `ReferenceTargetResolver`.
- The read-only `ContentSchemaReader`/`FieldDescriptor` and the `ContentLifecycleEvent` marker exist and are implemented additively by the engine schema/event classes.
- No runtime behavior changed: `composer test` (Unit + Feature + Integration) is green.
- `composer boundaries` passes.
- The contracts package is documented as `0.x` with the 1.0 freeze trigger.

**Deferred to later phases (do not build here):** capability switchboard + `extra.glueful` discovery + `GET /v1/admin/capabilities` (Phase B); `registerAdminModule` admin registry + admin-contribution descriptor DTOs (Phase C); reference-pack extraction + the coupling audit + `ContentBundleReader`/`ContentBundleWriter` snapshot contracts (Phase D); the field-type *registry* write-side and rich return VOs (when a real pack pulls on them).

---

## Self-Review

**Spec coverage (Phase A scope = spec §9.A + the content-engine subset of §4):**
- §4.1 Delivery reader → Task 7 ✅
- §4.2 Content writer (high-level, **validation-enforcing** — mirrors the HTTP path) → Task 6 ✅
- §4.3 Schema/validation — *read* side → Tasks 2 (schema read) ✅; field-type *registry* explicitly deferred (Global Constraints) ✅
- §4.4 Lifecycle events → Task 4 ✅
- §4.7 Pack context → Task 8 ✅
- §4.5 Capability discovery → deferred to Phase B (noted) ✅
- §4.6 Admin descriptors → deferred to Phase C (noted) ✅
- §9.A "promote ReferenceTargetResolver, ContentReindexerInterface, BaseContentEvent hierarchy" → Tasks 5, 3, 4 ✅
- §8 versioning / 0.x freeze → Task 9 ✅
- §10 boundary guard → Task 9 ✅
- No spec gap within Phase A scope.

**Placeholder scan:** No TBD/TODO. Task 6's adapter is now fully concrete (method names verified against the engine). Two `> Verify against the engine` notes remain (Task 7's delivery-list return shape, Task 8's settings/locale accessors) — these are not placeholders: each names the exact method to confirm, the fallback if the shape differs, and the fixed test truth that must hold regardless. They exist because those adapters wrap engine methods whose return *shape* the implementer must confirm at the keyboard.

**Type consistency:**
- `FieldDescriptor` accessors (`name()`, `type()`, `isMultiple()`, `referenceType()`, `referenceSlugField()`) are defined in Task 2 and consumed identically in Task 5.
- `ContentWriter::createDraft(...)/publish(...)` defined in Task 6 match the conformance test in Task 9.
- `ContentDeliveryReader::listPublished/findPublished` defined in Task 7 match Task 9.
- `LemmaContext::defaultLocale/enabledLocales/setting/renderPath` defined in Task 8 match its test and Task 9.
- Contract namespaces are uniform: `Glueful\Lemma\Contracts\{Schema,Search,Events,Delivery,Authoring,Context}\*`.
- No type referenced that isn't created in a prior task.
