# Lemma Composable Core — Phase D: Reference Pack (`glueful/lemma-importers`) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the content **format importers** into a real `glueful/lemma-importers` Composer extension that depends only on `lemma-contracts` + framework + `glueful/import-export`, declares the `lemma.importers` capability, contributes a capability-gated admin surface, writes content **only via `ContentWriter`**, and can be `composer remove`d without breaking the headless core or the (core-owned) snapshot export/import — proving the whole composable-core loop end-to-end.

**Architecture:** Phase D of four (A contracts ✅ → B capability spine ✅ → C admin registry ✅ → **D reference pack**). Per the §7 coupling audit, the importers module is two things: **format adapters** (CSV/Markdown/WordPress → entries) which are pack-shaped, and the **snapshot engine** (`LemmaContentExporter`/`Importer`, raw read/write of Lemma's own tables) which is core-shaped. This phase extracts the format adapters (refactored from direct `EntryRepository`/`PublishService`/`FieldValidator` calls to the `ContentWriter` contract), **keeps the snapshot engine + its upload/download API + snapshot UI in core**, and adds two small contracts the adapters need (`ContentWriter::validate`, `ContentTypeReader`). No `ContentBundleReader/Writer` — exposing raw-table restore through `lemma-contracts` would weaken the boundary this phase exists to prove.

**Tech Stack:** PHP 8.3, Glueful framework (`Glueful\Extensions\ServiceProvider`, `extra.glueful` discovery, container tags), `glueful/lemma-contracts` (0.x path package), `glueful/import-export` (`ImporterInterface`/`ExporterInterface` + Import/Export support VOs), PHPUnit 10.5 (PHP); Vue 3 + Pinia + Vitest (the admin gating). PHP work under `packages/`, `app/`, `config/`; admin work under `admin/`.

## Scope decisions (the §7 audit verdict — confirmed)

- **Reference pack = the four format adapters** (`CsvContentImporter`, `MarkdownContentImporter`, `WordpressContentImporter`, `CsvUserImporter`) **+ their shared `AbstractCsvImporter` base.** They move into `glueful/lemma-importers`.
- **The snapshot engine stays in core:** `LemmaContentExporter`, `LemmaContentImporter`, the `ImportExportController` (upload/download), its routes, and the snapshot section of the admin page are **untouched** and remain available with the pack removed.
- **No bundle contract.** Snapshot restore is core-shaped (it understands Lemma's internal tables/versions/routes/publications/blobs); it is not "import through the public content API."
- **Two small contract additions** (justified normal pack needs, preserving core ownership of schema/validation/write): `ContentWriter::validate()` (validate-only, for the adapters' dry-run mode) and `ContentTypeReader` (resolve a content type by slug → uuid and read its schema for column mapping). These dissolve the adapters' `FieldValidator` + `ContentTypeRepository` coupling.
- **Gate only the adapter surface** with `lemma.importers`: the format-import controls in `settings/import-export` and the bulk-CSV-import in the users page. The snapshot export/import UI stays always-available.

## Global Constraints

- **The pack must not depend on `glueful/lemma` (the app), nor reference `App\*`.** It depends on `glueful/lemma-contracts`, `glueful/framework`, `glueful/import-export`, and (for the content/user adapters) `league/commonmark`, `glueful/users`, `glueful/aegis`. Enforced by `composer boundaries` (`scripts/check-pack-boundaries.php`) — which Task 3 hardens to scan pack **source** for `App\` references, not just Composer deps (a monorepo path package could `use App\…` and still resolve without the source scan).
- **Adapters write content ONLY via `ContentWriter`** (`createDraft`/`publish`/`validate`) and read schema ONLY via `ContentTypeReader`/`ContentSchemaReader`/`FieldDescriptor`. No `EntryRepository`, `PublishService`, `FieldValidator`, `ContentTypeRepository`, `ContentTypeSchema`, or `FieldDefinition` in the pack.
- **Contracts stay thin and `0.x`** (interfaces/DTOs/VOs only; no engine logic/storage/IO). The additions are the reference extraction shaking out the contracts before any 1.0 freeze (spec §8).
- **No behavior change to the snapshot path or to content delivery.** With the pack installed + enabled, the format importers behave exactly as today.
- **Every new Lemma HTTP controller** (none expected here) **must be registered in its provider.** The pack registers its services via its own `ServiceProvider::services()`.
- **Capability:** the pack registers `Capability('lemma.importers', …)` into the core `CapabilityRegistry` in its `boot()`; it is enabled by default (the `lemma.capabilities` switchboard, Phase B).
- **Enabled-state gate is BACKEND, not just UI.** A disabled `lemma.importers` must stop the adapters from *running*, not merely hide the SPA controls — otherwise a direct `POST /import-export/imports` for `csv.content`/`wordpress.content`/… still executes. Each adapter checks `CapabilityRegistry::isEnabled('lemma.importers')` at the start of its import-plan entry and **fails closed** when disabled (the tagged services stay registered — the framework's import-export registry discovers them regardless — so the gate lives at the adapter's run boundary). This matches the spec's "jobs/subscribers gated by *enabled* state" (§5).
- **Lint/format/types:** PHP — `vendor/bin/phpcbf` + `vendor/bin/phpcs` clean on changed files (`packages/` is in phpcs scope as of Phase C); `composer test` green. Admin — `npx oxlint`/`npx oxfmt --check` clean, `npm run type-check` exit 0 (**run type-check directly; do NOT pipe through `tail`/`head`**), `npm run test` green.
- **Commit gate:** Do **NOT** run `git commit` until the human explicitly authorizes it. Each task's final step keeps its `git add` (staging) but its `git commit` line runs **only after authorization** — until then, stage, stop, and report. This applies to every task below. Never push or open a PR.
- **Glueful is NOT Laravel.** Static `services()` arrays; `container($context)->get(X)`; `Glueful\Extensions\ServiceProvider` lifecycle (`services()`/`register()`/`boot()`); container `tags` for import/export adapter discovery.

---

### Task 1: Add `ContentWriter::validate()` (validate-only)

**Files:**
- Create: `packages/lemma-contracts/src/Authoring/ValidationFailed.php` (contracts-level validation exception)
- Modify: `packages/lemma-contracts/src/Authoring/ContentWriter.php` (add `validate`)
- Modify: `app/Content/Validation/ValidationException.php` (implement `ValidationFailed`)
- Modify: `app/Content/Authoring/EngineContentWriter.php` (implement `validate`; have `createDraft` reuse it)
- Test: `tests/Integration/Contracts/ContentWriterValidateTest.php`

**Interfaces:**
- Consumes: `App\Content\Repositories\ContentTypeRepository::findByUuid` + `App\Content\Schema\ContentTypeSchema::fromArray` + `App\Content\Validation\FieldValidator::validate` (already used inside `EngineContentWriter::createDraft`).
- Produces:
  - `Glueful\Lemma\Contracts\Authoring\ValidationFailed` — an exception **interface** `extends \Throwable` with `errors(): array<string,string>`. `App\Content\Validation\ValidationException` implements it (additive — no parent change), so the *same* thrown object is catchable by packs as the contract type AND by existing app code as `ValidationException`.
  - `ContentWriter::validate(string $contentTypeUuid, string $locale, array $fields): array` — returns the validated, cleaned payload; throws a `ValidationFailed` on invalid input; does **not** persist. `createDraft` is refactored to call `validate()` internally (DRY) before `createEntry`/`saveDraft`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;
use Glueful\Lemma\Contracts\Authoring\ValidationFailed;

final class ContentWriterValidateTest extends LemmaTestCase
{
    private function seedType(): string
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

    public function testValidateReturnsCleanedFieldsWithoutPersisting(): void
    {
        $type = $this->seedType();
        $writer = $this->container()->get(ContentWriter::class);

        $clean = $writer->validate($type, 'en', ['title' => 'Hi', 'sneaky' => 'x']);
        self::assertSame(['title' => 'Hi'], $clean); // unknown key dropped

        // Nothing persisted by validate().
        self::assertSame(0, (int) $this->connection()->table('entries')->count());
    }

    public function testValidateRejectsInvalidWithContractException(): void
    {
        // A pack (which cannot reference App\*) must be able to catch the failure as the
        // CONTRACT exception and read its errors — proving the exception doesn't leak across
        // the boundary.
        $type = $this->seedType();
        $writer = $this->container()->get(ContentWriter::class);
        try {
            $writer->validate($type, 'en', []); // missing required 'title'
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('title', $e->errors());
        }
    }
}
```

> If `seedType()`'s columns don't match the real `content_types` table, adjust the insert (mirror the working insert in `tests/Integration/Contracts/ContentWriterContractTest.php` from Phase A — it seeds the same table). Keep the test's intent fixed.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:migrate && vendor/bin/phpunit tests/Integration/Contracts/ContentWriterValidateTest.php`
Expected: FAIL — `Call to undefined method …ContentWriter::validate()` (or not-found on the interface).

- [ ] **Step 3: Create the `ValidationFailed` contract exception + add `validate` to the writer**

`packages/lemma-contracts/src/Authoring/ValidationFailed.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Authoring;

/**
 * Thrown by content validation/write when a payload fails the content type's schema. Lives in
 * contracts so packs can catch it without referencing the engine's exception class.
 */
interface ValidationFailed extends \Throwable
{
    /** @return array<string,string> field name => error message */
    public function errors(): array;
}
```
In `packages/lemma-contracts/src/Authoring/ContentWriter.php`, add to the interface:
```php
    /**
     * Validate (and clean) a content payload against the content type's schema WITHOUT
     * persisting — for dry-run / preview flows. Returns the cleaned payload; throws a
     * {@see ValidationFailed} on invalid input.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     * @throws ValidationFailed
     */
    public function validate(string $contentTypeUuid, string $locale, array $fields): array;
```

- [ ] **Step 4: Make the engine exception implement the contract; implement `validate`; reuse it from `createDraft`**

First, in `app/Content/Validation/ValidationException.php`, add the contract interface to its declaration (additive — it already has `errors(): array`, so this is a one-line change, no body change):
```php
use Glueful\Lemma\Contracts\Authoring\ValidationFailed;

final class ValidationException extends \RuntimeException implements ValidationFailed
```
The same thrown object is now catchable as `ValidationFailed` (packs) and `ValidationException` (existing app code) — no existing `catch (ValidationException …)` breaks.

Then in `app/Content/Authoring/EngineContentWriter.php`, add the `validate` method and refactor `createDraft` to call it (removing the duplicated load-schema + validate lines):
```php
    public function validate(string $contentTypeUuid, string $locale, array $fields): array
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new \RuntimeException("content type {$contentTypeUuid} not found");
        }
        $schema = ContentTypeSchema::fromArray($type['schema']);

        return $this->validator->validate($schema, $fields);
    }

    public function createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new \RuntimeException("content type {$contentTypeUuid} not found");
        }
        $schemaVersion = (int) $type['schema_version'];
        $clean = $this->validate($contentTypeUuid, $locale, $fields);

        $entryUuid = $this->entries->createEntry($contentTypeUuid, $locale, $schemaVersion, $actor);
        $this->entries->saveDraft($entryUuid, $locale, $clean, $schemaVersion, 0, $actor);
        return $entryUuid;
    }
```
(`$locale` is currently unused by `validate`/the engine validator; keep the parameter — it is part of the contract and future per-locale validation may use it.)

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContentWriterValidateTest.php tests/Integration/Contracts/ContentWriterContractTest.php`
Expected: PASS (the Phase A `createDraft` tests still pass — behavior unchanged, just refactored).

- [ ] **Step 6: Lint**

Run: `vendor/bin/phpcbf packages/lemma-contracts/src/Authoring app/Content/Authoring tests/Integration/Contracts/ContentWriterValidateTest.php && vendor/bin/phpcs packages/lemma-contracts/src/Authoring app/Content/Authoring tests/Integration/Contracts/ContentWriterValidateTest.php`
Expected: phpcs clean.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Authoring/ValidationFailed.php packages/lemma-contracts/src/Authoring/ContentWriter.php app/Content/Validation/ValidationException.php app/Content/Authoring/EngineContentWriter.php tests/Integration/Contracts/ContentWriterValidateTest.php
# When authorized:
git commit -m "Add ContentWriter::validate() + ValidationFailed contract; createDraft reuses validate"
```

---

### Task 2: Add `ContentTypeReader` contract + `FieldDescriptor::format()` + engine impl

**Files:**
- Create: `packages/lemma-contracts/src/Schema/ContentTypeReader.php`
- Modify: `packages/lemma-contracts/src/Schema/FieldDescriptor.php` (add `format()`)
- Modify: `app/Content/Schema/FieldDefinition.php` (add `format()` accessor)
- Create: `app/Content/Schema/EngineContentTypeReader.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (bind the contract)
- Test: `tests/Integration/Contracts/ContentTypeReaderTest.php`

**Interfaces:**
- Consumes: `App\Content\Repositories\ContentTypeRepository::findBySlug(string $slug): ?array` (row with `uuid`), `findByUuid(string $uuid): ?array` (row with `schema`), `schemaFor(string $uuid): ContentTypeSchema` (which implements `ContentSchemaReader` from Phase A); `Glueful\Lemma\Contracts\Schema\ContentSchemaReader`; `App\Content\Schema\FieldDefinition::$format` (the existing `?string` readonly prop, values `'plain'`/`'rich'`/null).
- Produces:
  - `Glueful\Lemma\Contracts\Schema\FieldDescriptor::format(): ?string` — the text-field presentation format (`'plain'`/`'rich'`; null for non-text fields). `App\Content\Schema\FieldDefinition` implements it (returns `$this->format`). The content format adapters (Markdown/WordPress) read this to decide raw vs HTML body.
  - `Glueful\Lemma\Contracts\Schema\ContentTypeReader` — `findUuidBySlug(string $slug): ?string`; `schemaFor(string $uuid): ?ContentSchemaReader`.
  - `App\Content\Schema\EngineContentTypeReader implements ContentTypeReader` (wraps `ContentTypeRepository`), bound under the contract id.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;

final class ContentTypeReaderTest extends LemmaTestCase
{
    public function testResolvesBySlugAndReadsSchema(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'type00000001',
            'slug' => 'post',
            'name' => 'Post',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ]),
            'schema_version' => 1,
        ]);

        $reader = $this->container()->get(ContentTypeReader::class);
        self::assertInstanceOf(ContentTypeReader::class, $reader);

        self::assertSame('type00000001', $reader->findUuidBySlug('post'));
        self::assertNull($reader->findUuidBySlug('nope'));

        $schema = $reader->schemaFor('type00000001');
        self::assertInstanceOf(ContentSchemaReader::class, $schema);
        self::assertNotNull($schema->field('title'));
        // FieldDescriptor::format() is exposed for the importers' raw-vs-HTML body decision.
        self::assertSame('rich', $schema->field('body')?->format());
        self::assertNull($schema->field('title')?->format()); // non-text field has no format
        self::assertNull($reader->schemaFor('missing'));
    }
}
```

> Adjust the `content_types` insert columns if needed (match Task 1 / the Phase A insert).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContentTypeReaderTest.php`
Expected: FAIL — `Glueful\Lemma\Contracts\Schema\ContentTypeReader` not found / not bound.

- [ ] **Step 3: Create the `ContentTypeReader` contract + add `format()` to `FieldDescriptor`/`FieldDefinition`**

`packages/lemma-contracts/src/Schema/ContentTypeReader.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Schema;

/**
 * Resolve content types and read their schemas — for packs (e.g. importers) that need to map
 * external data onto a content type's fields. Read-only; the engine owns schema storage.
 */
interface ContentTypeReader
{
    /** The content type's uuid for a slug, or null if no such (non-deleted) type. */
    public function findUuidBySlug(string $slug): ?string;

    /** The schema for a content type uuid, or null if the type does not exist. */
    public function schemaFor(string $uuid): ?ContentSchemaReader;
}
```
Add `format()` to the existing `FieldDescriptor` interface (`packages/lemma-contracts/src/Schema/FieldDescriptor.php`):
```php
    /** Text-field presentation format ('plain' | 'rich'); null for non-text fields. */
    public function format(): ?string;
```
And the accessor on `App\Content\Schema\FieldDefinition` (it already has the `?string $format` readonly prop):
```php
    public function format(): ?string
    {
        return $this->format;
    }
```

- [ ] **Step 4: Create the engine implementation**

`app/Content/Schema/EngineContentTypeReader.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Schema;

use App\Content\Repositories\ContentTypeRepository;
use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;

final class EngineContentTypeReader implements ContentTypeReader
{
    public function __construct(private readonly ContentTypeRepository $types)
    {
    }

    public function findUuidBySlug(string $slug): ?string
    {
        $row = $this->types->findBySlug($slug);
        return $row === null ? null : (string) $row['uuid'];
    }

    public function schemaFor(string $uuid): ?ContentSchemaReader
    {
        $row = $this->types->findByUuid($uuid);
        return $row === null ? null : ContentTypeSchema::fromArray($row['schema']);
    }
}
```
> Confirm `findBySlug`/`findByUuid` return the hydrated row with `schema` as an array (they do — see `ContentTypeRepository`). `ContentTypeSchema` already implements `ContentSchemaReader` (Phase A Task 2), so the `?ContentSchemaReader` return is covariant-valid.

- [ ] **Step 5: Bind the contract**

In `app/Providers/LemmaServiceProvider.php` `services()`, add (with the `use` imports for `EngineContentTypeReader` + the contract):
```php
            \Glueful\Lemma\Contracts\Schema\ContentTypeReader::class => [
                'class'    => \App\Content\Schema\EngineContentTypeReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
```

- [ ] **Step 6: Run tests + lint**

Run: `vendor/bin/phpunit tests/Integration/Contracts/ContentTypeReaderTest.php`
Expected: PASS.
Run: `vendor/bin/phpcbf packages/lemma-contracts/src/Schema app/Content/Schema app/Providers/LemmaServiceProvider.php tests/Integration/Contracts/ContentTypeReaderTest.php && vendor/bin/phpcs packages/lemma-contracts/src/Schema app/Content/Schema app/Providers/LemmaServiceProvider.php tests/Integration/Contracts/ContentTypeReaderTest.php`
Expected: clean.
Also re-run the Phase A schema-contract test to confirm the additive `format()` didn't break it: `vendor/bin/phpunit tests/Unit/Contracts/SchemaReadContractTest.php` → PASS.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Schema/ContentTypeReader.php packages/lemma-contracts/src/Schema/FieldDescriptor.php app/Content/Schema/FieldDefinition.php app/Content/Schema/EngineContentTypeReader.php app/Providers/LemmaServiceProvider.php tests/Integration/Contracts/ContentTypeReaderTest.php
# When authorized:
git commit -m "Add ContentTypeReader contract + FieldDescriptor::format(); engine impls"
```

---

### Task 3: Scaffold the `glueful/lemma-importers` extension package

**Files:**
- Create: `packages/lemma-importers/composer.json`
- Create: `packages/lemma-importers/src/LemmaImportersServiceProvider.php`
- Modify: `composer.json` (root — add the path repo + require)
- Test: `tests/Integration/Importers/ImportersCapabilityTest.php`

**Interfaces:**
- Consumes: `Glueful\Extensions\ServiceProvider` (base); `Glueful\Lemma\Contracts\Capability\{Capability, CapabilityRegistry}` (Phase B); the `container($context)` helper; the Glueful extension discovery (`extra.glueful.provider`).
- Produces: a `glueful-extension` package `glueful/lemma-importers` (namespace `Glueful\Lemma\Importers\`) whose provider registers the `lemma.importers` capability in `boot()`. Adapter services are added in Tasks 4–5. Enabled by default via the `lemma.capabilities` switchboard.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class ImportersCapabilityTest extends LemmaTestCase
{
    public function testImportersCapabilityIsRegisteredAndEnabled(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        $ids = array_map(fn (Capability $c) => $c->id, $reg->enabled());
        self::assertContains('lemma.importers', $ids, 'the lemma-importers pack must register its capability');
    }
}
```
> This test passes only once the pack's provider boots in the test app. The provider is discovered via `extra.glueful` once the path package is required (Step 5) and the app re-reads its extension manifest. If the test app caches the extension/command manifest, clear it first (`php glueful commands:cache` or remove `storage/cache/*manifest*` — see the project's manifest-staleness note) so the new provider is discovered.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Importers/ImportersCapabilityTest.php`
Expected: FAIL — `lemma.importers` is not in the enabled set (pack not yet installed).

- [ ] **Step 3: Create the package manifest**

`packages/lemma-importers/composer.json`:
```json
{
  "name": "glueful/lemma-importers",
  "description": "Lemma content format importers (CSV / Markdown / WordPress) as a removable capability pack.",
  "type": "glueful-extension",
  "license": "MIT",
  "version": "0.1.0",
  "require": {
    "php": "^8.3",
    "glueful/lemma-contracts": "*",
    "glueful/framework": "^1.64.0",
    "glueful/import-export": "^1.1.1",
    "glueful/users": "^2.3.0",
    "glueful/aegis": "^1.13.1",
    "league/commonmark": "^2.8"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Lemma\\Importers\\": "src/"
    }
  },
  "extra": {
    "glueful": {
      "provider": "Glueful\\Lemma\\Importers\\LemmaImportersServiceProvider"
    }
  },
  "minimum-stability": "stable"
}
```
> Every package whose classes the pack imports is an **explicit `require`** — a removable Composer package declares its own dependencies, not inherits them from the host: `glueful/framework` (ApplicationContext, Connection, the extension base), `glueful/import-export` (`ImporterInterface` + VOs), `glueful/users` + `glueful/aegis` (the user importer), `league/commonmark` (Markdown). Match the host app's version constraints (above mirror `lemma/composer.json`). **Never** add `glueful/lemma`. Confirm the constraints against `lemma/composer.json` at implementation time and adjust if they've moved.

- [ ] **Step 4: Create the ServiceProvider (capability registration)**

`packages/lemma-importers/src/LemmaImportersServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Importers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaImportersServiceProvider extends ServiceProvider
{
    /** @return array<string,mixed> */
    public static function services(): array
    {
        // Adapter services (tagged import_export.importer) are added in Tasks 4-5.
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // No routes/config to load; adapters are tag-discovered by glueful/import-export.
    }

    public function boot(ApplicationContext $context): void
    {
        container($context)->get(CapabilityRegistry::class)->register(
            new Capability(
                'lemma.importers',
                label: 'Content importers',
                description: 'CSV, Markdown and WordPress content/user import adapters.',
            ),
        );
    }
}
```
> Confirm the base `Glueful\Extensions\ServiceProvider` signatures (`services(): array`, `register(ApplicationContext): void`, `boot(ApplicationContext): void`) against the framework's base class and an existing first-party extension; match them exactly.

- [ ] **Step 5: Wire the path package into the root `composer.json` and install**

Add to root `composer.json` `repositories` (the array already exists from Phase A): `{ "type": "path", "url": "packages/lemma-importers" }`. Add to `require`: `"glueful/lemma-importers": "*"`.
Run: `composer update glueful/lemma-importers --no-interaction`
Then clear any cached extension/command manifest so discovery picks up the new provider (see Step 1's note), and re-run migrations bootstrap if the test harness needs it.

- [ ] **Step 6: Harden the boundary guard to scan pack SOURCE for `App\` references**

The Phase A `scripts/check-pack-boundaries.php` only checks Composer `require` for `glueful/lemma` — but in this monorepo (path packages, shared autoloader) a pack file could `use App\Foo` and still resolve at runtime. Extend the guard to also fail if any pack's **source** references `App\`. Add this block before the script's final success `echo`/`exit(0)` (and include any new violations in the existing `$violations` list):
```php
// Source-level boundary: no first-party pack (except the contracts package) may reference App\*.
foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
    if (basename($pkgDir) === 'lemma-contracts') {
        continue;
    }
    foreach (glob($pkgDir . '/src/*.php') ?: [] as $file) {
        $src = (string) file_get_contents($file);
        // Matches `use App\...`, `\App\...`, or a bare `App\` namespace reference.
        if (preg_match('/(^|[^\\\\\\w])App\\\\/m', $src) === 1) {
            $violations[] = basename($pkgDir) . '/src/' . basename($file) . ' references App\\ (packs must use contracts, not the app)';
        }
    }
}
```
> If a pack's `src/` has subdirectories, widen the inner glob to a recursive scan (e.g. a `RecursiveDirectoryIterator` over `$pkgDir.'/src'` filtering `*.php`). Keep the contracts-package exemption.

- [ ] **Step 7: Run the test + the hardened boundary guard**

Run: `composer test:migrate && vendor/bin/phpunit tests/Integration/Importers/ImportersCapabilityTest.php`
Expected: PASS (`lemma.importers` enabled).
Run: `composer boundaries`
Expected: OK — the new pack declares no `glueful/lemma` dependency and (currently) has no `App\` source references. (This guard is what catches a stray `App\` import in Tasks 4–5.)

- [ ] **Step 8: Lint + stage (commit only when authorized — see Global Constraints)**

Run: `vendor/bin/phpcbf packages/lemma-importers scripts/check-pack-boundaries.php && vendor/bin/phpcs packages/lemma-importers scripts/check-pack-boundaries.php`
Expected: clean.
```bash
git add packages/lemma-importers composer.json composer.lock scripts/check-pack-boundaries.php tests/Integration/Importers/ImportersCapabilityTest.php
# When authorized:
git commit -m "Scaffold glueful/lemma-importers extension + harden boundary guard (App\\ source scan)"
```

---

### Task 4: Move the CSV base + user importer into the pack

**Files:**
- Create: `packages/lemma-importers/src/AbstractCsvImporter.php` (moved from `app/ImportExport/AbstractCsvImporter.php`)
- Create: `packages/lemma-importers/src/CsvUserImporter.php` (moved from `app/ImportExport/CsvUserImporter.php`)
- Create: `packages/lemma-importers/src/Concerns/RequiresImportersCapability.php` (the backend capability guard trait)
- Delete: `app/ImportExport/AbstractCsvImporter.php`, `app/ImportExport/CsvUserImporter.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (remove their service entries + `use` imports)
- Modify: `packages/lemma-importers/src/LemmaImportersServiceProvider.php` (register `CsvUserImporter`, tagged)
- Test: `tests/Integration/Importers/CsvUserImporterRelocationTest.php`; `tests/Unit/Importers/RequiresImportersCapabilityTest.php`

**Interfaces:**
- Consumes: `Glueful\Extensions\ImportExport\Contracts\ImporterInterface` (+ the Import support VOs); `Glueful\Auth\PasswordHasher`, `Glueful\Extensions\Users\Repositories\UserRepository`, `Glueful\Extensions\Aegis\AegisPermissionProvider` (all framework/extension, allowed); `Glueful\Lemma\Contracts\Capability\CapabilityRegistry` (the guard); `AbstractCsvImporter` (CSV lifecycle base).
- Produces: `Glueful\Lemma\Importers\{AbstractCsvImporter, CsvUserImporter}` + the `Concerns\RequiresImportersCapability` trait. `CsvUserImporter` injects `CapabilityRegistry` and `use`s the trait, guarding its plan entry. No `App\*` references remain in any of them.

- [ ] **Step 1: Audit `AbstractCsvImporter` + `CsvUserImporter` for `App\*` coupling**

Run: `grep -nE "use App\\\\|App\\\\" app/ImportExport/AbstractCsvImporter.php app/ImportExport/CsvUserImporter.php`
Expected: only framework/extension imports (`Glueful\*`) per the §7 audit (`PasswordHasher`, `UserRepository`, `AegisPermissionProvider`). If any `App\*` import appears, STOP and report it — it is an unaudited coupling that must be resolved (likely via a contract) before relocation.

- [ ] **Step 2: Move both files into the pack with the new namespace**

Move `app/ImportExport/AbstractCsvImporter.php` → `packages/lemma-importers/src/AbstractCsvImporter.php` and `app/ImportExport/CsvUserImporter.php` → `packages/lemma-importers/src/CsvUserImporter.php`. In both, change `namespace App\ImportExport;` → `namespace Glueful\Lemma\Importers;`. `CsvUserImporter`'s `extends AbstractCsvImporter` now resolves within the pack namespace. Leave the class bodies otherwise unchanged (verbatim relocation).

- [ ] **Step 3: Add the shared capability guard + apply it to `CsvUserImporter`**

Create `packages/lemma-importers/src/Concerns/RequiresImportersCapability.php` — the backend enabled-state gate (per Global Constraints), shared by all four adapters:
```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Importers\Concerns;

use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

trait RequiresImportersCapability
{
    private function assertImportersEnabled(CapabilityRegistry $capabilities): void
    {
        if (!$capabilities->isEnabled('lemma.importers')) {
            throw new \RuntimeException('The lemma.importers capability is disabled.');
        }
    }
}
```
In `CsvUserImporter`: inject `private readonly CapabilityRegistry $capabilities` (constructor), `use RequiresImportersCapability;`, and call `$this->assertImportersEnabled($this->capabilities);` as the **first line** of its plan entry point (`validatePlan()` / `prepare()` — whichever the import-export engine calls first; check the `ImporterInterface` method order). A disabled-capability job then fails closed before any rows are read.

- [ ] **Step 4: Register `CsvUserImporter` in the pack provider; de-register from the app**

In `packages/lemma-importers/src/LemmaImportersServiceProvider.php` `services()`, add:
```php
            CsvUserImporter::class => [
                'class'    => CsvUserImporter::class,
                'shared'   => true,
                'autowire' => true,
                'tags'     => ['import_export.importer'],
            ],
```
(with `use Glueful\Lemma\Importers\CsvUserImporter;`). In `app/Providers/LemmaServiceProvider.php`, **remove** the `CsvUserImporter` and `AbstractCsvImporter` service entries and their `use App\ImportExport\…` imports.

- [ ] **Step 5: Write/relocate the test + a backend-gate test**

If a user-importer test exists under `tests/`, update its imports to `Glueful\Lemma\Importers\CsvUserImporter` and keep its assertions. Otherwise add `tests/Integration/Importers/CsvUserImporterRelocationTest.php` asserting the relocated class is discoverable + tagged (resolve it from the container by class name and assert it `instanceof Glueful\Extensions\ImportExport\Contracts\ImporterInterface`).

Add a **pure unit test** of the capability guard (`tests/Unit/Importers/RequiresImportersCapabilityTest.php`) — it exercises the trait directly via an anonymous class, so it needs no adapter deps:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Importers;

use App\Capabilities\DefaultCapabilityRegistry;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Importers\Concerns\RequiresImportersCapability;
use PHPUnit\Framework\TestCase;

final class RequiresImportersCapabilityTest extends TestCase
{
    /** A minimal user of the trait that exposes the private guard for testing. */
    private function gate(): object
    {
        return new class {
            use RequiresImportersCapability;
            public function run(CapabilityRegistry $caps): void
            {
                $this->assertImportersEnabled($caps);
            }
        };
    }

    public function testThrowsWhenDisabled(): void
    {
        $reg = new DefaultCapabilityRegistry(['lemma.importers' => false]);
        $reg->register(new Capability('lemma.importers'));
        $this->expectException(\RuntimeException::class);
        $this->gate()->run($reg);
    }

    public function testPassesWhenEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry();
        $reg->register(new Capability('lemma.importers'));
        $this->gate()->run($reg);
        self::assertTrue(true); // no exception
    }
}
```

- [ ] **Step 6: Run tests + boundary + lint**

Run: `vendor/bin/phpunit tests/Integration/Importers tests/Unit/Importers && composer boundaries`
Expected: PASS; boundaries OK.
Run: `vendor/bin/phpcbf packages/lemma-importers app/Providers/LemmaServiceProvider.php && vendor/bin/phpcs packages/lemma-importers app/Providers/LemmaServiceProvider.php`
Expected: clean.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-importers app/Providers/LemmaServiceProvider.php tests/Integration/Importers tests/Unit/Importers
git rm app/ImportExport/AbstractCsvImporter.php app/ImportExport/CsvUserImporter.php
# When authorized:
git commit -m "Move AbstractCsvImporter + CsvUserImporter into glueful/lemma-importers (+ backend capability gate)"
```

---

### Task 5: Move + refactor the three content adapters into the pack

**Files:**
- Create: `packages/lemma-importers/src/CsvContentImporter.php`, `MarkdownContentImporter.php`, `WordpressContentImporter.php` (moved + refactored from `app/Content/ImportExport/`)
- Delete: the three originals under `app/Content/ImportExport/`
- Modify: `app/Providers/LemmaServiceProvider.php` (remove the three service entries + `use` imports)
- Modify: `packages/lemma-importers/src/LemmaImportersServiceProvider.php` (register the three, tagged)
- Test: `tests/Integration/Importers/ContentImporterWritesViaContractTest.php`

**Interfaces:**
- Consumes (NEW, contracts only): `Glueful\Lemma\Contracts\Authoring\ContentWriter` (`createDraft`/`publish`/`validate` — Task 1); `Glueful\Lemma\Contracts\Authoring\ValidationFailed` (the catch type — Task 1); `Glueful\Lemma\Contracts\Schema\ContentTypeReader` (`findUuidBySlug`/`schemaFor` — Task 2); `Glueful\Lemma\Contracts\Schema\{ContentSchemaReader, FieldDescriptor}` (`FieldDescriptor::format()` — Task 2 / Phase A); `Glueful\Lemma\Contracts\Capability\CapabilityRegistry` + `Glueful\Lemma\Importers\Concerns\RequiresImportersCapability` (the backend gate — Task 4); `Glueful\Extensions\ImportExport\Contracts\ImporterInterface` + Import VOs; `AbstractCsvImporter` (pack, for `CsvContentImporter`).
- Produces: the three content adapters in the pack, refactored so the write path is `ContentWriter` and schema access is `ContentTypeReader`/`ContentSchemaReader`. **Removed dependencies:** `EntryRepository`, `PublishService`, `FieldValidator`, `ContentTypeRepository`, `ContentTypeSchema`, `FieldDefinition`.

- [ ] **Step 1: Write the failing test** (a content adapter creates an entry via the contract)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Importers\CsvContentImporter;

final class ContentImporterWritesViaContractTest extends LemmaTestCase
{
    /** @return list<class-string> the four importer adapter classes */
    private function adapters(): array
    {
        return [
            \Glueful\Lemma\Importers\CsvContentImporter::class,
            \Glueful\Lemma\Importers\MarkdownContentImporter::class,
            \Glueful\Lemma\Importers\WordpressContentImporter::class,
            \Glueful\Lemma\Importers\CsvUserImporter::class,
        ];
    }

    /** @return list<string> traits used by $cls and all its parents */
    private function traitsOf(string $cls): array
    {
        $traits = [];
        for ($c = $cls; $c !== false; $c = get_parent_class($c)) {
            $traits = array_merge($traits, array_values(class_uses($c) ?: []));
        }
        return $traits;
    }

    public function testCsvContentImporterIsContractCoupledOnly(): void
    {
        // Structural guard: the refactored adapter must depend on the ContentWriter contract,
        // not on the engine repositories/services it used before.
        $ctor = (new \ReflectionClass(CsvContentImporter::class))->getConstructor();
        $paramTypes = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $ctor?->getParameters() ?? [],
        );
        $joined = implode(',', $paramTypes);
        self::assertStringContainsString('Glueful\\Lemma\\Contracts\\Authoring\\ContentWriter', $joined);
        self::assertStringNotContainsString('App\\Content\\Repositories\\EntryRepository', $joined);
        self::assertStringNotContainsString('App\\Content\\Services\\PublishService', $joined);
        self::assertStringNotContainsString('App\\Content\\Validation\\FieldValidator', $joined);
    }

    public function testEveryAdapterUsesTheCapabilityGuardTrait(): void
    {
        // The backend gate is only meaningful if EVERY adapter applies it — not just one sample.
        // Catches an adapter that forgets `use RequiresImportersCapability;`.
        foreach ($this->adapters() as $cls) {
            self::assertContains(
                \Glueful\Lemma\Importers\Concerns\RequiresImportersCapability::class,
                $this->traitsOf($cls),
                "{$cls} must use RequiresImportersCapability (backend capability gate)",
            );
        }
    }

    public function testNoAdapterReferencesAppNamespace(): void
    {
        foreach ($this->adapters() as $cls) {
            $src = (string) file_get_contents((new \ReflectionClass($cls))->getFileName());
            self::assertDoesNotMatchRegularExpression('/(^|[^\\\\\\w])App\\\\/m', $src, "{$cls} must not reference App\\");
        }
    }
}
```
> This pins the refactor (no engine coupling) without standing up the full import-job machinery. A fuller end-to-end import test can be added if the import-export harness is readily available; the structural test is the minimum that proves the contract refactor.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Importers/ContentImporterWritesViaContractTest.php`
Expected: FAIL — `Glueful\Lemma\Importers\CsvContentImporter` not found (not yet moved).

- [ ] **Step 3: Move the three adapters into the pack (namespace change)**

Move `app/Content/ImportExport/{CsvContentImporter,MarkdownContentImporter,WordpressContentImporter}.php` → `packages/lemma-importers/src/`. Change `namespace App\Content\ImportExport;` → `namespace Glueful\Lemma\Importers;`. Update `CsvContentImporter`'s `extends AbstractCsvImporter` to the pack base (same namespace now).

- [ ] **Step 4: Refactor the write path + schema access to contracts**

In each of the three adapters, apply this refactor (the §7 audit located the exact call sites — `CsvContentImporter.php:101-131`, `MarkdownContentImporter.php:139-144`, `WordpressContentImporter.php:143-149`):

1. **Constructor:** replace the injected `EntryRepository $entries`, `PublishService $publisher`, `FieldValidator $validator`, and any `ContentTypeRepository` with the contracts + the capability registry, and `use Glueful\Lemma\Importers\Concerns\RequiresImportersCapability;` on the class:
```php
    public function __construct(
        // ...existing framework deps the adapter needs (ApplicationContext, etc.)...
        private readonly \Glueful\Lemma\Contracts\Authoring\ContentWriter $writer,
        private readonly \Glueful\Lemma\Contracts\Schema\ContentTypeReader $types,
        private readonly \Glueful\Lemma\Contracts\Capability\CapabilityRegistry $capabilities,
    ) {
        // ...
    }
```
2. **Type/schema resolution** (in `prepare()`/`validatePlan()`): replace `ContentTypeRepository::findBySlug(...)` with `$this->types->findUuidBySlug($slug)` (→ `$typeUuid`) and read the schema via `$this->types->schemaFor($typeUuid)` (a `ContentSchemaReader`). Drive column→field mapping and type coercion from `$schema->fields()` / `$schema->field($name)` (each a `FieldDescriptor` exposing `name()`/`type()`). Keep the adapter's own coercion helpers (move any needed coercion that lived in `FieldValidator`, e.g. datetime normalization, into the adapter — it is pack-owned mapping logic now).
3. **Dry-run validation:** replace `$this->validator->validate($schema, $payload)` with `$this->writer->validate($typeUuid, $locale, $payload)`.
4. **Commit write:** replace the `createEntry()` + `saveDraft()` (+ `publish()`) sequence with:
```php
        $entryUuid = $this->writer->createDraft($typeUuid, $locale, $clean, $actorUuid);
        if ($publish) {
            $this->writer->publish($entryUuid, $locale, $actorUuid);
        }
```
Keep the per-row `try/catch` that turns a validation failure into a batch error report, but catch the **contract** exception `Glueful\Lemma\Contracts\Authoring\ValidationFailed` (Task 1) instead of `App\Content\Validation\ValidationException` — the pack must not reference `App\*`. `validate()`/`createDraft()` throw an object that is-a `ValidationFailed`, and `$e->errors()` is available. Remove all `use App\Content\…` imports.

5. **Backend capability guard:** add `use RequiresImportersCapability;` to the class and call `$this->assertImportersEnabled($this->capabilities);` as the **first line** of the adapter's plan entry point (`validatePlan()`/`prepare()`), so a disabled `lemma.importers` fails the job closed before any work (per Global Constraints). For `CsvContentImporter` (which extends `AbstractCsvImporter`) confirm the plan entry it overrides; for the standalone Markdown/WordPress adapters, their own plan method.

> **Verify at the keyboard:** confirm exactly which framework deps each adapter still needs (e.g. `ApplicationContext`, the import-export `ImporterInterface` methods) and that the coercion logic previously borrowed from `FieldValidator` (e.g. datetime normalization, the body-format raw-vs-HTML branch using `FieldDescriptor::format()`) is fully reproduced in the adapter. If an adapter needs a schema capability the contracts don't expose (beyond `FieldDescriptor::name()/type()/format()/isMultiple()/referenceType()/referenceSlugField()`), STOP and report NEEDS_CONTEXT — do not reach back into `App\*`.

- [ ] **Step 5: Register the three in the pack provider; de-register from the app**

In `LemmaImportersServiceProvider::services()` add the three (tagged `import_export.importer`, autowire). In `app/Providers/LemmaServiceProvider.php` remove their three service entries + `use App\Content\ImportExport\…` imports. (Leave `LemmaContentExporter`/`LemmaContentImporter` registrations — snapshot stays core.)

- [ ] **Step 6: Run tests + boundary + lint**

Run: `vendor/bin/phpunit tests/Integration/Importers && composer boundaries`
Expected: PASS; boundaries OK (no pack→`glueful/lemma` dep; no `App\*` in the moved files — `grep -rn "App\\\\" packages/lemma-importers/src` returns nothing).
Run: `vendor/bin/phpcbf packages/lemma-importers app/Providers/LemmaServiceProvider.php && vendor/bin/phpcs packages/lemma-importers app/Providers/LemmaServiceProvider.php`
Expected: clean.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-importers app/Providers/LemmaServiceProvider.php tests/Integration/Importers
git rm app/Content/ImportExport/CsvContentImporter.php app/Content/ImportExport/MarkdownContentImporter.php app/Content/ImportExport/WordpressContentImporter.php
# When authorized:
git commit -m "Move + refactor content format adapters into glueful/lemma-importers (write via ContentWriter)"
```

---

### Task 6: Gate the adapter admin surface by `lemma.importers`

**Files:**
- Modify: `admin/src/pages/settings/import-export/index.vue` (gate the format-import section)
- Modify: the users page's bulk-CSV-import surface (locate in Step 1)
- Test: `admin/src/__tests__/importersGating.spec.ts`

**Interfaces:**
- Consumes: `useCapabilitiesStore()` (Phase C; `isEnabled('lemma.importers')`). The snapshot export/import controls are **not** gated.
- Produces: the format-adapter import controls render only when `lemma.importers` is enabled; the bulk-CSV user import likewise. The snapshot export/import UI is untouched. (This is the UX **complement** to the backend capability gate from Tasks 4–5 — the adapters already fail closed when disabled even if a request bypasses the UI; this just stops showing controls that wouldn't work.)

- [ ] **Step 1: Locate the two adapter surfaces**

Run: `grep -rln "import" admin/src/pages/settings/import-export admin/src/pages/users` and read `admin/src/pages/settings/import-export/index.vue`. Identify (a) the **format-adapter import** controls (CSV/Markdown/WordPress upload + mapping) versus the **snapshot** export/import controls, and (b) the users page's **bulk CSV import** control. Note the exact section boundaries — only the adapter controls get gated.

- [ ] **Step 2: Write the failing test**

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'

vi.mock('@/api/authFetch', () => ({ authFetch: vi.fn().mockResolvedValue({ data: { capabilities: [] } }) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useCapabilitiesStore } from '@/stores/capabilities'
import ImportExportPage from '@/pages/settings/import-export/index.vue'

describe('format-import gating', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('hides the format-import section when lemma.importers is disabled', async () => {
    const caps = useCapabilitiesStore()
    caps.enabledIds = new Set() // disabled
    const wrapper = mount(ImportExportPage, { global: { stubs: { /* stub heavy children as needed */ } } })
    expect(wrapper.find('[data-test="format-import"]').exists()).toBe(false)
  })

  it('shows the format-import section when lemma.importers is enabled', async () => {
    const caps = useCapabilitiesStore()
    caps.enabledIds = new Set(['lemma.importers'])
    const wrapper = mount(ImportExportPage, { global: { stubs: { /* … */ } } })
    expect(wrapper.find('[data-test="format-import"]').exists()).toBe(true)
  })
})
```
> Mounting a full page may require stubbing Nuxt UI children and Colada queries. If a full mount is impractical, extract the format-import controls into a small child component (`FormatImportSection.vue`) and unit-test that the parent renders it only when `caps.isEnabled('lemma.importers')` — the extraction is itself a clean way to make the gate testable. Pick the lighter path; keep the assertion (hidden when disabled, shown when enabled).

- [ ] **Step 3: Gate the format-import section**

In `settings/import-export/index.vue` `<script setup>`: `const caps = useCapabilitiesStore(); caps.ensureLoaded()`. Wrap the format-adapter import controls in `v-if="caps.isEnabled('lemma.importers')"` and add `data-test="format-import"` to the section root. Leave the snapshot export/import controls outside the gate (always rendered). Apply the same gate to the users page's bulk-CSV-import control (its own `data-test` + `v-if`).
> Using the capabilities store for an in-page section gate is the sanctioned pattern (Phase C exposes `isEnabled` as the public capability check). This is NOT a hard-coded sidebar conditional — the nav entry (Settings → Import/Export) stays, since the snapshot half is always available.

- [ ] **Step 4: Run the test + full SPA suite + gates**

Run: `cd admin && npx vitest run src/__tests__/importersGating.spec.ts && npm run test`
Expected: PASS (existing suite green).
Run: `cd admin && npm run type-check` (direct, no pipe — must exit 0) `&& npx oxlint <changed files> && npx oxfmt --check <changed files>`
Expected: clean.

- [ ] **Step 5: Stage (commit only when authorized — see Global Constraints)**

```bash
git add admin/src/pages/settings/import-export admin/src/pages/users admin/src/__tests__/importersGating.spec.ts
# (include any extracted FormatImportSection.vue)
# When authorized:
git commit -m "Gate the format-import admin surface behind the lemma.importers capability"
```

---

### Task 7: Prove removability + final conformance

**Files:**
- Create: `tests/Integration/Importers/SnapshotSurvivesWithoutAdaptersTest.php`
- Create: `docs/composable-core/REMOVING_LEMMA_IMPORTERS.md` (the documented removal check)
- Test: the above + `composer boundaries` + the full suite.

**Interfaces:**
- Consumes: the core-owned snapshot engine (`App\Content\ImportExport\LemmaContentExporter`/`LemmaContentImporter`) which must remain resolvable + functional independent of the pack; `composer boundaries`.
- Produces: an automated guard that the snapshot path does not depend on the pack, and a documented `composer remove` verification.

- [ ] **Step 1: Write the snapshot-independence test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Content\ImportExport\LemmaContentExporter;
use App\Content\ImportExport\LemmaContentImporter;
use App\Tests\Support\LemmaTestCase;

final class SnapshotSurvivesWithoutAdaptersTest extends LemmaTestCase
{
    public function testSnapshotEngineResolvesIndependentlyOfThePack(): void
    {
        // The snapshot engine is core-owned; it must resolve from the container regardless of
        // the importers pack, and must NOT reference any Glueful\Lemma\Importers\* class.
        self::assertInstanceOf(LemmaContentExporter::class, $this->container()->get(LemmaContentExporter::class));
        self::assertInstanceOf(LemmaContentImporter::class, $this->container()->get(LemmaContentImporter::class));

        foreach ([LemmaContentExporter::class, LemmaContentImporter::class] as $cls) {
            $src = (string) file_get_contents((new \ReflectionClass($cls))->getFileName());
            self::assertStringNotContainsString('Glueful\\Lemma\\Importers', $src, "$cls must not depend on the pack");
        }
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Integration/Importers/SnapshotSurvivesWithoutAdaptersTest.php`
Expected: PASS (snapshot engine is core, pack-independent).

- [ ] **Step 3: Document the removal verification**

Create `docs/composable-core/REMOVING_LEMMA_IMPORTERS.md` describing the manual proof:
```markdown
# Removing glueful/lemma-importers

`composer remove glueful/lemma-importers` (and drop its path-repo entry) removes the
CSV/Markdown/WordPress + user import adapters. After removal:

- The headless CMS core boots; content delivery and the admin work.
- **Snapshot export/import still works** — `LemmaContentExporter` / `LemmaContentImporter`,
  the `/v1/admin/import-export/upload|download` endpoints, and the snapshot UI are core-owned.
- The `lemma.importers` capability is absent from `GET /v1/admin/capabilities`, so the
  format-import admin section and the users bulk-CSV import hide automatically (Phase C gating).
- `composer boundaries` stays green.

Disabling without removing: set `'lemma.importers' => false` in `config/lemma.php`'s
`capabilities` switchboard — the adapters' admin surface hides, code stays installed.
```

- [ ] **Step 4: Full verification**

Run: `composer test` (PHP suite green), `composer phpcs` (clean), `composer boundaries` (OK), and `cd admin && npm run test && npm run type-check` (green; type-check exit 0).
Expected: all green.

- [ ] **Step 5: Stage (commit only when authorized — see Global Constraints)**

```bash
git add tests/Integration/Importers/SnapshotSurvivesWithoutAdaptersTest.php docs/composable-core/REMOVING_LEMMA_IMPORTERS.md
# When authorized:
git commit -m "Prove glueful/lemma-importers removability; snapshot stays core"
```

---

## Phase D — Definition of Done

- `glueful/lemma-importers` is a real `glueful-extension` depending only on contracts/framework/import-export (+ commonmark/users/aegis) — **never `glueful/lemma`** (boundary guard green); it registers the `lemma.importers` capability.
- The four format adapters live in the pack, write content **only via `ContentWriter`**, resolve schema via `ContentTypeReader`, and catch validation failures via the contract `ValidationFailed` — no `App\*` in the pack (enforced by the hardened boundary guard's source scan).
- New contract surface in `lemma-contracts` (still `0.x`): `ContentWriter::validate()`, `ValidationFailed`, `FieldDescriptor::format()`, `ContentTypeReader`.
- **Backend gate:** a disabled `lemma.importers` makes the adapters fail closed at their run boundary (not just hide the UI).
- The snapshot engine + upload/download API + snapshot UI remain in core and work with the pack removed.
- The format-import admin surface + users bulk-CSV import are gated by `lemma.importers` (UI complement to the backend gate).
- `composer test` / `composer phpcs` / `composer boundaries` green; `npm run test` / `type-check` green.

**This completes the composable-core arc (A→D):** contracts, capability spine, admin registry, and a real removable reference pack proving the loop end-to-end.

**Deferred (future, not here):** additional packs (Render, Collections, Forms); the runtime-loaded admin model + backend admin-contribution descriptors; making the snapshot engine itself a pack (it is deliberately core); the 1.0 contracts freeze (do it once a third-party pack is documented — spec §8).

---

## Self-Review

**Spec coverage (Phase D = spec §7 + §9.D, per the confirmed audit verdict):**
- §7 "scaffolding + ONE reference pack, gated by a coupling audit" → audit done; pack = format adapters (Tasks 3–5) ✅
- §7 reference-pack qualities (optional / working / backend service / admin surface / uses core via contracts / removable) → Tasks 3–7 ✅
- §7 "clean extraction = depends on lemma-contracts (+framework+pack deps), not glueful/lemma, lands content via ContentWriter" → Task 5 refactor + boundary guard ✅
- §7 contract-coverage gap (snapshot not covered by ContentWriter) → resolved by KEEPING snapshot in core (no bundle contract) — the confirmed decision ✅
- §9.D "pull the chosen module into a package against lemma-contracts, wire via extra.glueful, prove the loop" → Tasks 3–7 ✅
- §8 versioning (contracts shaken out by the extraction, stay 0.x) → Tasks 1–2 additions ✅
- §10 boundary guard + removability → Tasks 4–7 ✅
- §5 capability Installed→Enabled→Reported (pack registers Capability; gated; reported) → Task 3 + Task 6 ✅
- §5 "Enabled" gates jobs/subscribers (BACKEND, not just UI) → the `RequiresImportersCapability` guard (Tasks 4–5) + its unit test ✅
- §6 admin gating by capability id (Phase C registry/store) → Task 6 (UI complement) ✅
- §10 "no `App\*` in packs" actually enforced → Task 3 hardens the boundary guard to scan pack source ✅

**Placeholder scan:** No TBD/TODO. The "verify at the keyboard" / "locate the section" / "grep for App\\ coupling" notes are not placeholders — each names the exact file(s), the exact thing to confirm (constructor deps, coercion logic, the format-vs-snapshot section boundary), and the fail-safe (STOP + report rather than reach into `App\*`). They exist because the adapter bodies are relocated-verbatim with a precise, audit-located write-path refactor, not retyped from scratch.

**Type consistency:**
- `ContentWriter::validate(string,string,array): array` (Task 1) is consumed with that signature in Task 5's dry-run refactor.
- `ContentTypeReader::findUuidBySlug(string): ?string` + `schemaFor(string): ?ContentSchemaReader` (Task 2) match Task 5's type/schema resolution.
- The pack namespace `Glueful\Lemma\Importers\` is uniform across Tasks 3–5 (provider, base, adapters).
- The capability id `lemma.importers` is identical across Task 3 (registration), Task 6 (admin gate), and Task 7 (docs).
- `createDraft`/`publish` signatures used in Task 5 match the Phase A contract.
- `ValidationFailed` (Task 1) is the catch type in Task 5; `FieldDescriptor::format()` (Task 2) is the body-format branch in Task 5; the `RequiresImportersCapability` trait + `CapabilityRegistry` injection are introduced in Task 4 and applied in Tasks 4–5 consistently.
- No symbol referenced that isn't created in a prior task (or already shipped in Phases A–C).
