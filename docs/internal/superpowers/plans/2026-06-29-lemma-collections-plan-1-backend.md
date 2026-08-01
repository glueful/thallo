# lemma-collections Plan 1 — Backend Foundation + Public API

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `glueful/lemma-collections` backend — define a collection through an API/tests, materialize a real table, write/read/query/relate rows through `/v1/collections/{name}`, and disable/remove the pack without touching content.

**Architecture:** A removable capability pack whose *collection definition* (JSON metadata) drives runtime DDL (Glueful `SchemaBuilder`) to materialize and evolve a real per-collection table. It reuses a new small shared field-type registry seam in `lemma-contracts`; it is otherwise self-contained (own storage, validation, query engine, public API). Public requests resolve to an actor (`api_key | user | admin`) stamped into row audit columns; the public API is default-deny and gated by per-collection API-key scopes.

**Tech Stack:** PHP 8.3, Glueful framework (`services()` DI, `SchemaBuilderInterface` runtime DDL, Router + middleware, `api_keys` + scopes, `CapabilityRegistry`, `EventService`), PHPUnit, MySQL/PostgreSQL/SQLite.

**Source design:** `docs/superpowers/specs/2026-06-29-lemma-collections-design.md` (read it; this plan implements §§1–12, minus the admin UI which is Plan 2).

**Scope & sequencing:** This is **Plan 1 of 2**. Plan 2 — *Admin UI + Polish* (schema builder, guarded-drop UX, index-management UI, data browser, relation/asset field controls, API-key scope management UI, docs/README, final end-to-end conformance) — is authored **after Plan 1 is built and reviewed**, deliberately: the admin plan is far more accurate when it targets the real API surface Plan 1 produces, and it matches the "prove the engine + public API first, then make it usable" dogfooding order (the composable-core phases ran the same write → build → write-next way). Do not write Plan 2 against this plan's *intended* API — write it against the *shipped* one.

## Global Constraints

- **Pack boundary:** `glueful/lemma-collections` depends on `glueful/lemma-contracts` and `glueful/framework` — **never `glueful/lemma`**. Asset validation/expansion uses the framework's `Glueful\Repository\BlobRepository` (blobs are framework-level, so **no `glueful/media` dependency**); api-key auth + the public-API middleware aliases (`optional_api_key`, …) are provided by core at runtime and referenced **by alias, never by class**. No `App\*` references in `packages/lemma-collections/src/`. Enforced by `composer boundaries`.
- **Capability:** registers a `lemma.collections` `Capability`; provider added to `config/extensions.php`; default-on. **Never auto-drops data tables** on disable/remove. Because routes/subscribers are wired at boot under the enabled gate, **toggling `lemma.collections` on/off takes effect only after the route cache/manifest is rebuilt or the app reboots** (standard for extension toggles — e.g. `php glueful cache:clear` / route-manifest rebuild, never a per-request capability check).
- **Public API default-deny**, keyed by row `uuid` (never internal `id`); per-collection scopes `collections.{name}.{read|write|delete}`.
- **No silent data loss:** every destructive op is explicit-confirm + written to `collection_schema_changes`.
- **Registry keys are namespaced:** `content.*`, `collections.*`.
- **Physical tables** are named `collection_<uuid-or-hash>`, decoupled from the collection `name`.
- **PSR-12**, `<?php` + blank + `declare(strict_types=1)`; run `phpcbf`/`phpcs` clean before each commit. Per-task commits on `dev`.

## File Structure

**`packages/lemma-contracts/src/Schema/`** (new contracts)
- `FieldTypeDefinition.php` — interface: a registrable field type (key, label, valueShape, validationRules, adminWidget, capabilities).
- `FieldTypeRegistry.php` — interface: `register/get/has/all`.

**Core (`app/`)**
- `app/Content/Schema/FieldTypes/DefaultFieldTypeRegistry.php` — the engine registry impl (bound to the contract).
- `app/Content/Schema/FieldTypes/EditorialFieldTypes.php` — registers `content.*` types.
- `app/Providers/LemmaServiceProvider.php` — bind registry + register editorial types in `boot()`.

**`packages/lemma-collections/`** (new pack)
- `composer.json`, `src/LemmaCollectionsServiceProvider.php`
- `migrations/001_CreateCollectionDefinitionsTable.php`, `002_CreateCollectionSchemaChangesTable.php` (flat `migrations/` at the pack root — the Glueful extension convention: cf. `glueful/aegis`, `glueful/users`, `glueful/import-export`; **not** a nested `database/`)
- `src/Schema/CollectionDefinition.php` (VO), `src/Schema/CollectionField.php` (VO), `src/Schema/ColumnMapper.php`, `src/Schema/CollectionFieldTypes.php` (registers `collections.*`)
- `src/Schema/DdlPlanner.php`, `src/Schema/SchemaMaterializer.php`, `src/Schema/SchemaChange.php` (op VO)
- `src/CollectionManager.php`, `src/Repositories/CollectionDefinitionRepository.php`
- `src/Data/RowValidator.php`, `src/Data/RowRepository.php`, `src/Data/Actor.php`, `src/Query/QueryCompiler.php`
- `src/Relations/RelationResolver.php`
- `src/Events/CollectionRowCreated.php`, `CollectionRowUpdated.php`, `CollectionRowDeleted.php`
- `src/Http/CollectionScopeMiddleware.php`, `src/Http/ActorResolver.php`, `src/Http/Controllers/CollectionDataController.php`, `routes.php`
- `src/Support/PublicId.php` (prefixed nanoid), `src/Exceptions/*`

**Tests** live in the app suite under `tests/Integration/Collections/` and `tests/Unit/Collections/` (the pack has no standalone suite; the app boots the container that registers it — same as `lemma-importers`).

---

### Task 1: Field-type registry contracts + core registry + editorial registration

**Files:**
- Create: `packages/lemma-contracts/src/Schema/FieldTypeDefinition.php`
- Create: `packages/lemma-contracts/src/Schema/FieldTypeRegistry.php`
- Create: `app/Content/Schema/FieldTypes/DefaultFieldTypeRegistry.php`
- Create: `app/Content/Schema/FieldTypes/EditorialFieldTypes.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (bind registry in `contentEngineServices()`; register editorial types in `boot()`)
- Test: `tests/Unit/Collections/FieldTypeRegistryTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Contracts\Schema\FieldTypeDefinition` (`key():string`, `label():string`, `valueShape():string`, `validationRules():array`, `adminWidget():string`, `capabilities():array` — keys `filterable|sortable|indexable|multi|localized` → bool). `Glueful\Lemma\Contracts\Schema\FieldTypeRegistry` (`register(FieldTypeDefinition):void`, `get(string $key):FieldTypeDefinition`, `has(string $key):bool`, `all():array`). `App\Content\Schema\FieldTypes\DefaultFieldTypeRegistry implements FieldTypeRegistry` (throws on duplicate key; throws `OutOfBoundsException` on `get` miss).

- [ ] **Step 1: Write failing test** — `tests/Unit/Collections/FieldTypeRegistryTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use App\Content\Schema\FieldTypes\DefaultFieldTypeRegistry;
use Glueful\Lemma\Contracts\Schema\FieldTypeDefinition;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    private function def(string $key): FieldTypeDefinition
    {
        return new class ($key) implements FieldTypeDefinition {
            public function __construct(private string $k) {}
            public function key(): string { return $this->k; }
            public function label(): string { return 'L'; }
            public function valueShape(): string { return 'scalar'; }
            public function validationRules(): array { return []; }
            public function adminWidget(): string { return 'text'; }
            public function capabilities(): array { return ['filterable' => true]; }
        };
    }

    public function testRegisterGetHasAll(): void
    {
        $r = new DefaultFieldTypeRegistry();
        $r->register($this->def('collections.text'));
        self::assertTrue($r->has('collections.text'));
        self::assertSame('collections.text', $r->get('collections.text')->key());
        self::assertArrayHasKey('collections.text', $r->all());
    }

    public function testDuplicateKeyThrows(): void
    {
        $r = new DefaultFieldTypeRegistry();
        $r->register($this->def('content.text'));
        $this->expectException(\InvalidArgumentException::class);
        $r->register($this->def('content.text'));
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new DefaultFieldTypeRegistry())->get('nope');
    }
}
```

- [ ] **Step 2: Run, verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Collections/FieldTypeRegistryTest.php`
Expected: FAIL — `Glueful\Lemma\Contracts\Schema\FieldTypeDefinition` / `DefaultFieldTypeRegistry` not found.

- [ ] **Step 3: Add the contracts** — `FieldTypeDefinition.php` and `FieldTypeRegistry.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Schema;

/**
 * A registrable field type. The registry standardizes discovery/validation/widget/capability;
 * domain-specific metadata (column_type, localized, …) lives in each domain's own field payload.
 */
interface FieldTypeDefinition
{
    /** Namespaced key, e.g. "content.text" or "collections.decimal". */
    public function key(): string;
    public function label(): string;
    /** "scalar" | "array" | "json" — the broad shape of the stored value. */
    public function valueShape(): string;
    /** @return array<string,mixed> generic validation hints (required-capable, max length, …). */
    public function validationRules(): array;
    public function adminWidget(): string;
    /** @return array{filterable?:bool,sortable?:bool,indexable?:bool,multi?:bool,localized?:bool} */
    public function capabilities(): array;
}
```

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Schema;

interface FieldTypeRegistry
{
    public function register(FieldTypeDefinition $type): void;
    public function get(string $key): FieldTypeDefinition;
    public function has(string $key): bool;
    /** @return array<string,FieldTypeDefinition> keyed by ->key(). */
    public function all(): array;
}
```

- [ ] **Step 4: Implement `DefaultFieldTypeRegistry`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Schema\FieldTypes;

use Glueful\Lemma\Contracts\Schema\FieldTypeDefinition;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;

final class DefaultFieldTypeRegistry implements FieldTypeRegistry
{
    /** @var array<string,FieldTypeDefinition> */
    private array $types = [];

    public function register(FieldTypeDefinition $type): void
    {
        if (isset($this->types[$type->key()])) {
            throw new \InvalidArgumentException("Field type '{$type->key()}' is already registered.");
        }
        $this->types[$type->key()] = $type;
    }

    public function get(string $key): FieldTypeDefinition
    {
        return $this->types[$key] ?? throw new \OutOfBoundsException("Unknown field type '{$key}'.");
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function all(): array
    {
        return $this->types;
    }
}
```

- [ ] **Step 5: Run, verify pass.** `vendor/bin/phpunit tests/Unit/Collections/FieldTypeRegistryTest.php` → PASS.

- [ ] **Step 6: Register editorial types + bind the registry.** Create `EditorialFieldTypes.php` with a static `register(FieldTypeRegistry $r): void` that registers the existing content field types as `content.{type}` definitions (one small anonymous-or-named `FieldTypeDefinition` per content type: `string`, `text`, `number`, `boolean`, `reference`, `media`, `datetime` — read the existing content field-type list from `app/Content/Schema/` and mirror its `filterable/sortable/localized` flags). Bind in `LemmaServiceProvider::contentEngineServices()`:

```php
FieldTypeRegistry::class => [
    'class'    => DefaultFieldTypeRegistry::class,
    'shared'   => true,
    'autowire' => true,
],
```

and in `boot()` after the listener wiring:

```php
EditorialFieldTypes::register(app($context, FieldTypeRegistry::class));
```

(Add the `use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;`, `use App\Content\Schema\FieldTypes\DefaultFieldTypeRegistry;`, `use App\Content\Schema\FieldTypes\EditorialFieldTypes;` imports.)

- [ ] **Step 7: Test editorial registration** — append to the test (or a sibling integration test under `tests/Integration/Collections/`): resolve `FieldTypeRegistry` from the container, assert `has('content.text')` and that every `all()` key starts with `content.`.

- [ ] **Step 8: phpcbf + phpcs + run** the new tests; `composer boundaries` stays OK (contracts package only gained interfaces).

- [ ] **Step 9: Commit** — `git add packages/lemma-contracts/src/Schema app/Content/Schema/FieldTypes app/Providers/LemmaServiceProvider.php tests/Unit/Collections/FieldTypeRegistryTest.php && git commit -m "Add FieldTypeRegistry contract + core registry; register editorial field types"`

---

### Task 2: Scaffold `glueful/lemma-collections` pack + capability

**Files:**
- Create: `packages/lemma-collections/composer.json`
- Create: `packages/lemma-collections/src/LemmaCollectionsServiceProvider.php`
- Create: `packages/lemma-collections/src/Schema/CollectionFieldTypes.php` (registers `collections.*` — see Task 4 for the full set; in this task register a single `collections.text` to prove the wiring)
- Modify: root `composer.json` (path repo + require, mirroring how `lemma-importers` is wired), `config/extensions.php` (add the provider to the enabled allow-list), delete `bootstrap/cache/extensions.php` if present
- Test: `tests/Integration/Collections/CapabilityRegistrationTest.php`

**Interfaces:**
- Produces: `Glueful\Lemma\Collections\LemmaCollectionsServiceProvider` (extends `Glueful\Extensions\ServiceProvider`); declares the `lemma.collections` `Capability` in `boot()`; namespace root `Glueful\Lemma\Collections\`.

- [ ] **Step 1: Write failing test** — assert the capability is enabled by default and the provider booted.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class CapabilityRegistrationTest extends LemmaTestCase
{
    public function testCollectionsCapabilityIsRegisteredAndEnabled(): void
    {
        $caps = $this->container()->get(CapabilityRegistry::class);
        self::assertTrue($caps->isEnabled('lemma.collections'));
    }
}
```

- [ ] **Step 2: Run, verify fail** — `vendor/bin/phpunit tests/Integration/Collections/CapabilityRegistrationTest.php` → FAIL (capability unknown).

- [ ] **Step 3: Author `composer.json`** (mirror `packages/lemma-importers/composer.json`): `name: glueful/lemma-collections`, `type: glueful-extension`, `extra.glueful.provider: Glueful\\Lemma\\Collections\\LemmaCollectionsServiceProvider`, PSR-4 `Glueful\\Lemma\\Collections\\` → `src/`, requires `php ^8.3`, `glueful/lemma-contracts:*`, `glueful/framework:^1.64.0`. **No `glueful/lemma`.**

- [ ] **Step 4: Author the provider.**

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Collections\Schema\CollectionFieldTypes;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;

final class LemmaCollectionsServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            // filled in by later tasks (manager, repositories, controllers, middleware)
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: migrations are loaded in boot() (framework extension convention —
        // cf. aegis/users/import-export); DI bindings are declared via services().
    }

    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            'lemma.collections',
            label: 'Data collections',
            description: 'Developer-defined data collections with a public CRUD/query API.',
        ));

        CollectionFieldTypes::register(app($context, FieldTypeRegistry::class));

        // Migrations register on INSTALL (not enable — outside the gate), so disabling preserves tables.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'lemma-collections');

        // Routes are gated by ENABLED state (spec §5): register the public API only when the
        // capability is on. Disabling lemma.collections leaves migrations/tables intact but removes
        // the public surface entirely — requests 404 rather than reaching a disabled handler.
        if (app($context, CapabilityRegistry::class)->isEnabled('lemma.collections')) {
            $this->loadRoutesFrom(__DIR__ . '/Http/routes.php'); // file added in Task 11
        }
    }
}
```

(For this task, create `Http/routes.php` as an empty `<?php` returning nothing, and `CollectionFieldTypes::register()` registering only `collections.text` for now.)

- [ ] **Step 5: Wire installation** — add the path repo + `"glueful/lemma-collections": "*"` to root `composer.json` (mirror `lemma-importers`), run `composer update glueful/lemma-collections --no-interaction`, add `Glueful\Lemma\Collections\LemmaCollectionsServiceProvider::class` to `config/extensions.php` enabled list, delete `bootstrap/cache/extensions.php`.

- [ ] **Step 6: Run, verify pass.** Test green. `composer boundaries` → OK (3 packages).

- [ ] **Step 7: phpcbf/phpcs + Commit** — `git add packages/lemma-collections composer.json composer.lock config/extensions.php tests/Integration/Collections/CapabilityRegistrationTest.php && git commit -m "Scaffold glueful/lemma-collections pack + lemma.collections capability"`

---

### Task 3: Metadata migrations (`collection_definitions`, `collection_schema_changes`)

**Files:**
- Create: `packages/lemma-collections/migrations/001_CreateCollectionDefinitionsTable.php`
- Create: `packages/lemma-collections/migrations/002_CreateCollectionSchemaChangesTable.php`
- Test: `tests/Integration/Collections/MetadataMigrationsTest.php`

**Interfaces:**
- Produces tables `collection_definitions` (cols per spec §4.1) and `collection_schema_changes` (per spec §5).

- [ ] **Step 1: Failing test** — after `composer test:migrate`, both tables exist with the expected columns.

```php
public function testMetadataTablesExist(): void
{
    $schema = $this->container()->get(\Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class);
    self::assertTrue($schema->hasTable('collection_definitions'));
    self::assertTrue($schema->hasTable('collection_schema_changes'));
}
```

- [ ] **Step 2: Run** `composer test:migrate && vendor/bin/phpunit tests/Integration/Collections/MetadataMigrationsTest.php` → FAIL.

- [ ] **Step 3: Write `001_CreateCollectionDefinitionsTable.php`** (follow the existing `001_CreateContentTypesTable.php` shape):

```php
public function up(SchemaBuilderInterface $schema): void
{
    if ($schema->hasTable('collection_definitions')) {
        return;
    }
    $schema->createTable('collection_definitions', function ($table) {
        $table->bigInteger('id')->primary()->autoIncrement();
        $table->string('uuid', 24);
        $table->string('name', 64);
        $table->string('label', 160);
        $table->string('table_name', 80);
        $table->string('storage_mode', 16)->default('table'); // reserved; v1 accepts only 'table'
        $table->text('fields');            // JSON
        $table->integer('schema_version')->default(1);
        $table->string('status', 16)->default('active');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->unique('uuid');
        $table->unique('name');
        $table->unique('table_name');
    });
}
public function down(SchemaBuilderInterface $schema): void { $schema->dropTableIfExists('collection_definitions'); }
```

- [ ] **Step 4: Write `002_CreateCollectionSchemaChangesTable.php`** — cols: `id` (PK), `uuid` (unique), `collection_uuid` (index), `change_type` string(32), `payload` text (JSON), `actor_type` string(16), `actor_id` string(64) nullable, `destructive` boolean default false, `status` string(16) default `'pending'` (`pending|applied|failed`), `created_at` timestamp, `applied_at` timestamp nullable. (The `status`/`applied_at` pair backs the **MySQL recovery invariant** in Task 6: the record is written `pending` *before* the DDL and marked `applied`/`failed` *after* — so a `pending`/`failed` row with no `applied` is the signal that a schema change must be re-applied or reconciled.)

- [ ] **Step 5: Run** migrate + test → PASS.

- [ ] **Step 6: phpcbf/phpcs + Commit** — `git commit -m "Add collection_definitions + collection_schema_changes migrations"`

---

### Task 4: Collection field VOs + column mapper + register `collections.*` types

**Files:**
- Create: `packages/lemma-collections/src/Schema/CollectionField.php`, `src/Schema/CollectionDefinition.php`, `src/Schema/ColumnMapper.php`, `src/Schema/CollectionFieldTypes.php` (full set)
- Test: `tests/Unit/Collections/ColumnMapperTest.php`

**Interfaces:**
- Produces: `CollectionField` (readonly VO: `name:string`, `type:string` (e.g. `collections.decimal`), `settings:array` — `length/precision/scale/nullable/unique/index/bigint/values/target/multi`), with `fromArray(array):self` / `toArray():array`. `CollectionDefinition` (readonly VO: `uuid,name,label,tableName,storageMode,fields:list<CollectionField>,schemaVersion,status` — `storageMode` is a `string` that is always `'table'` in v1; `fromRow(array):self` (defaults a missing `storage_mode` to `'table'`); `field(string $name):?CollectionField`). `ColumnMapper::column(CollectionField): ColumnSpec` returning the physical column directive `{name, type, params, nullable, unique}` consumed by the materializer; `ColumnMapper::supportedTypes(): list<string>`.

- [ ] **Step 1: Failing test** — `ColumnMapper` maps each v1 type to the right column directive.

```php
public function testMapsDecimalAndTextAndMultiRelation(): void
{
    $m = new ColumnMapper();
    $price = $m->column(CollectionField::fromArray(['name' => 'price', 'type' => 'collections.decimal', 'settings' => ['precision' => 12, 'scale' => 2]]));
    self::assertSame(['decimal', [12, 2]], [$price->type, $price->params]);

    $title = $m->column(CollectionField::fromArray(['name' => 'title', 'type' => 'collections.text', 'settings' => ['length' => 120, 'nullable' => false]]));
    self::assertSame(['string', [120]], [$title->type, $title->params]);
    self::assertFalse($title->nullable);

    $tags = $m->column(CollectionField::fromArray(['name' => 'tags', 'type' => 'collections.relation', 'settings' => ['multi' => true, 'target' => 'collection:tags']]));
    self::assertSame('text', $tags->type); // JSON array column
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement the VOs + `ColumnMapper`.** The mapper switch (spec §4.3): `collections.text`→`string([length=255])`; `longtext`→`text`; `integer`→`bigInteger` if `bigint` else `integer`; `decimal`→`decimal([precision,scale])`; `boolean`→`boolean`; `date`→`date`; `datetime`→`timestamp`; `json`→`text` (JSON; or `jsonb` where supported — keep `text` for portability v1); `email`→`string([255])`; `url`→`string([2048])`; `enum`→`string([255])`; `relation`/`asset` single→`string([36])`, multi→`text` (JSON array). Carry `nullable` (default true), `unique` (default false) onto the `ColumnSpec`.

- [ ] **Step 4: Run** → PASS.

- [ ] **Step 5: Implement `CollectionFieldTypes::register()`** — register one `FieldTypeDefinition` per `collections.*` type with capabilities: scalar types `filterable+sortable+indexable=true` — **except `longtext`, which is `indexable=true` but `filterable=false,sortable=false`** (it maps to a `TEXT` column, which can't be plainly indexed or sorted; advertising filter/sort on it would invite queries the DB can't serve efficiently); `json/relation/asset` `filterable=false,sortable=false`; `relation/asset` may be `multi`. Replace the Task-2 stub.

- [ ] **Step 6: Test registration** — integration test: registry resolved from container `has('collections.decimal')` and all `collections.*` keys present; no key collides with `content.*`.

- [ ] **Step 7: phpcbf/phpcs + Commit** — `git commit -m "Add collection field VOs, column mapper, and collections.* field types"`

---

### Task 5: DDL planner (definition diff → operations; blocked-op detection)

**Files:**
- Create: `packages/lemma-collections/src/Schema/SchemaChange.php` (op VO), `src/Schema/DdlPlanner.php`, `src/Exceptions/BlockedSchemaChangeException.php`
- Test: `tests/Unit/Collections/DdlPlannerTest.php`

**Interfaces:**
- Produces: `SchemaChange` (readonly: `op` one of `create_table|add_field|drop_field|add_index|drop_index|drop_table`, `field:?CollectionField`, `destructive:bool`). `DdlPlanner::planCreate(CollectionDefinition): list<SchemaChange>` and `DdlPlanner::planAlter(CollectionDefinition $current, CollectionDefinition $next): list<SchemaChange>`. **Rename is not a concept the planner detects** — the field list is diffed *by name*: a name only in `$current` is a destructive `drop_field`, a name only in `$next` is an `add_field`, and v1 exposes no rename/retype API operation (so "renaming" via the JSON is simply a drop+add — the old column's data is lost through the destructive drop flow, Task 7). For a field name present in **both** definitions the planner compares the field's **storage signature** — `type`, `nullable`, `length`, `precision`, `scale`, `bigint`, relation/asset `target`, and single↔multiple — **excluding `index`/`unique`**. If that signature changed it throws `BlockedSchemaChangeException` (v1 has no in-place column alter — remodel via drop+add). It then **separately** diffs the `index`/`unique` flags into `add_index`/`drop_index` ops; a `unique` add is flagged `destructive: true` for the materializer's data pre-flight (Task 6). Nothing is ever silently dropped.

- [ ] **Step 1: Failing tests** — add-field, drop-field(destructive), add/remove-index; **any in-place storage-signature change on an existing field throws `BlockedSchemaChangeException`** (separate cases: type/retype, `nullable`, `length`, single↔multiple, relation `target`); an **index-only change on an existing field still emits `add_index`/`drop_index`** (not a block); a `unique` add is `destructive: true`; drop+add of *differing* names is allowed.

```php
public function testAddFieldAndDropFieldArePlanned(): void
{
    $p = new DdlPlanner();
    $a = def(['title' => text()]);
    $b = def(['title' => text(), 'views' => integer()]);
    $ops = $p->planAlter($a, $b);
    self::assertSame(['add_field'], array_map(fn($o) => $o->op, $ops));

    $ops2 = $p->planAlter($b, $a);
    self::assertSame('drop_field', $ops2[0]->op);
    self::assertTrue($ops2[0]->destructive);
}

public function testRetypeIsBlocked(): void
{
    $p = new DdlPlanner();
    $this->expectException(BlockedSchemaChangeException::class);
    $p->planAlter(def(['n' => integer()]), def(['n' => text()]));
}
```

- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement (diff field lists by name; new name → `add_field`; missing name → `drop_field` destructive; same name + different `type` → throw retype; index settings diff → `add_index`/`drop_index`; planCreate emits one `create_table`). **Step 4: Run** → PASS.

- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add collection DDL planner with blocked-op detection"`

---

### Task 6: Schema materializer + audit (execute ops via SchemaBuilder)

**Files:**
- Create: `packages/lemma-collections/src/Schema/SchemaMaterializer.php`, `src/Repositories/CollectionDefinitionRepository.php`, `src/Support/PublicId.php`, `src/Exceptions/PreflightFailedException.php`
- Test: `tests/Integration/Collections/SchemaMaterializerTest.php`

**Interfaces:**
- Produces: `PublicId::generate(string $prefix = ''): string` (prefix + nanoid; e.g. `prod_ab12cd34`). `CollectionDefinitionRepository` (`insert(CollectionDefinition):void`, `update(CollectionDefinition):void`, `delete(string $uuid):void`, `findByName(string):?CollectionDefinition`, `findByUuid`, `all()`). `SchemaMaterializer::apply(CollectionDefinition $def, list<SchemaChange> $ops, string $actorType, ?string $actorId): void`. For **each** op, in order: (1) run any pre-flight check (unique-index-add → reject with `PreflightFailedException` if duplicates exist, *before* writing anything); (2) write the `collection_schema_changes` row with `status='pending'` **before** the DDL; (3) execute the op via `SchemaBuilderInterface`; (4) mark the row `status='applied', applied_at=now` on success, or `status='failed'` on exception (then rethrow). The op list is wrapped in a transaction **only** where the driver has transactional DDL (Postgres/SQLite). On **MySQL** (DDL auto-commits — no rollback) this pending→applied/failed trail *is* the recovery record: a `pending`/`failed` row with no matching `applied` flags a schema change that must be re-applied or reconciled. (This is why the audit write must bracket the DDL, not follow it.)

- [ ] **Step 1: Failing test** — `apply()` of a `create_table` plan materializes `collection_<hash>` with system columns + mapped field columns.

```php
public function testCreateTableMaterializesRealTableWithSystemColumns(): void
{
    [$mat, $schema] = [$this->container()->get(SchemaMaterializer::class), $this->schema()];
    $def = new CollectionDefinition('clx_1', 'products', 'Products', 'collection_clx1', 'table', [
        CollectionField::fromArray(['name' => 'title', 'type' => 'collections.text', 'settings' => ['length' => 120]]),
    ], 1, 'active');
    $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', 'u1');

    self::assertTrue($schema->hasTable('collection_clx1'));
    foreach (['id', 'uuid', 'created_at', 'updated_at', 'created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id', 'title'] as $col) {
        self::assertTrue($schema->hasColumn('collection_clx1', $col), $col);
    }
    self::assertSame(1, $this->connection()->table('collection_schema_changes')->where('collection_uuid', 'clx_1')->count());
}
```

- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement `apply()`: open transaction iff `in_array($driver, ['pgsql','sqlite'])`; for `create_table` call `$schema->createTable($def->tableName, fn($t) => …)` adding system columns then each `ColumnMapper::column()`; for `add_field` `$schema->table()->...` (use `AlterTableBuilder` — match the pattern in an existing alter migration); for `drop_field`/`drop_table` the destructive ops; write the audit row each time; commit/rollback. For `add_index` with `unique`, first `SELECT field, COUNT(*) … GROUP BY field HAVING COUNT(*)>1` — if rows, throw `PreflightFailedException`. **Step 4: Run** → PASS.

- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add schema materializer (runtime DDL) + audit + preflight"`

---

### Task 7: `CollectionManager` — create / add-field / add-index / guarded drop

**Files:**
- Create: `packages/lemma-collections/src/CollectionManager.php`, `src/Exceptions/DestructiveConfirmationRequiredException.php`
- Modify: provider `services()` (register `CollectionManager`, `CollectionDefinitionRepository`, `SchemaMaterializer`, `DdlPlanner`, `ColumnMapper`, autowired)
- Test: `tests/Integration/Collections/CollectionManagerTest.php`

**Interfaces:**
- Produces: `CollectionManager::create(array $payload, string $actorType, ?string $actorId): CollectionDefinition` (validates name/fields; **rejects `storage_mode` other than `'table'`** with a validation error `['storage_mode' => 'Only table storage is supported in v1.']` — a missing `storage_mode` defaults to `'table'`; generates `uuid` + `table_name = 'collection_' . substr(hash, …)`, persists definition with `storage_mode='table'`, materializes table). `addField(string $name, array $field, …)`, `addIndex(...)`, `removeIndex(...)`. `dropField(string $name, string $field, array $opts, …)` and `dropCollection(string $name, array $opts, …)` — require `$opts['confirm'] === $field/$name` **unless the data table is empty** (light path); otherwise throw `DestructiveConfirmationRequiredException`. Bumps `schema_version` on each accepted change. **Never** called on disable/remove.

- [ ] **Step 1: Failing tests** — create materializes; **`create` with `storage_mode: 'document'` is rejected (only `'table'` allowed in v1); a missing `storage_mode` defaults to `'table'` and succeeds**; add-field alters; `dropField` without confirm on a populated table throws; `dropField` on an empty table succeeds without confirm; `dropCollection` with the right `confirm` drops table + definition row.
- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement (reserved-name guard against system columns + SQL keywords; name format `^[a-z][a-z0-9_]*$`). **Step 4: Run** → PASS.
- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add CollectionManager with guarded drop + empty-table light path"`

---

### Task 8: Row validation + `RowRepository` CRUD

**Files:**
- Create: `packages/lemma-collections/src/Data/Actor.php`, `src/Data/RowValidator.php`, `src/Data/RowRepository.php`, `src/Exceptions/RowValidationException.php`, `src/Exceptions/RowNotFoundException.php`
- Modify: provider `services()` (register the three, autowired; `RowValidator` autowires the framework `Glueful\Repository\BlobRepository` for `asset` existence checks)
- Test: `tests/Integration/Collections/RowCrudTest.php`

**Interfaces:**
- Produces: `Actor` (readonly: `type:string` (`api_key|user|admin`), `id:?string`). `RowValidator::validate(CollectionDefinition $def, array $input, bool $partial): array` — returns the coerced column map; throws `RowValidationException` (`->errors():array<string,string>`) on required/nullable/unique/type/enum/email/url violations, missing relation targets, and **missing `asset` blob UUIDs** (existence checked via `Glueful\Repository\BlobRepository::findByUuid()` — the single UUID, or every element of a multi-asset array, must resolve to a blob). `RowRepository::create(CollectionDefinition, array $input, Actor): array` (generates row `uuid` via `PublicId`, stamps `created_by_*`/`updated_by_*` + timestamps, inserts, returns the row keyed by `uuid`), `update(def,uuid,input,Actor):array` (partial; stamps `updated_by_*`), `delete(def,uuid):void`, `find(def,uuid):array`.

- [ ] **Step 1: Failing tests** — create stamps actor + uuid + timestamps and round-trips; required-field-missing throws with a per-field error; partial update only touches given fields; unknown uuid `find`/`delete` throws `RowNotFoundException`; enum rejects out-of-set; email rejects malformed; **a `single` and a `multiple` `asset` field reject a blob UUID absent from `blobs`** (seed one real blob via `BlobRepository`, assert a fabricated UUID is rejected and the real one accepted).
- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement validation per type + coercion (CSV-style: `"42"`→int, `"true"`→bool, decimal as string-safe), relation existence check via a count on the target collection's table. **Step 4: Run** → PASS.
- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add row validation + RowRepository CRUD with actor stamping"`

---

### Task 9: Query compiler (filter / sort / offset / fields)

**Files:**
- Create: `packages/lemma-collections/src/Query/QueryCompiler.php`, `src/Query/ListResult.php`, `src/Exceptions/InvalidQueryException.php`
- Test: `tests/Integration/Collections/QueryCompilerTest.php`

**Interfaces:**
- Produces: `QueryCompiler::list(CollectionDefinition $def, array $params): ListResult` — `params` keys `filter` (`[field => [op => value]]`, ops `eq,ne,lt,lte,gt,gte,like,in,null`), `sort` (`field,-other`), `fields` (csv), `page`, `perPage` (capped via config `lemma.collections.max_per_page`, default 100). Returns `ListResult{ data: list<array>, page, perPage, total }`. Rejects unknown fields and non-filterable/sortable fields (per the registry capabilities) with `InvalidQueryException`. Filters/sorts run over **real columns** (no JSONB).

- [ ] **Step 1: Failing tests** — eq/in/like/gt filter; `-field` desc sort; offset paging returns the right slice + total; filtering an unknown field throws; sorting a non-sortable field throws.
- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement against `Connection::table($def->tableName)` query builder; map ops to where clauses; whitelist field names against the definition; apply `fields` as a select projection (always include `uuid`). **Step 4: Run** → PASS.
- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add collection query compiler (filter/sort/offset/fields)"`

---

### Task 10: Relations (validate-on-write, expand, restrict-delete) + change events

**Files:**
- Create: `packages/lemma-collections/src/Relations/RelationResolver.php`, `src/Events/CollectionRowCreated.php`, `CollectionRowUpdated.php`, `CollectionRowDeleted.php`, `src/Exceptions/RowReferencedException.php`
- Modify: `RowRepository` (call `RelationResolver` on write/delete; emit events), provider `services()`
- Test: `tests/Integration/Collections/RelationsTest.php`

**Interfaces:**
- Produces: `RelationResolver::assertTargetsExist(CollectionField, array|string $value): void` (throws `RowValidationException` if a referenced `uuid` is absent from the target collection); `RelationResolver::expand(CollectionDefinition, list<array> $rows, list<string> $expand): list<array>` (one level, bounded; replaces each relation field with the resolved target row(s)); `RelationResolver::assertNotReferenced(CollectionDefinition $target, string $uuid): void` (scans every collection's relation fields whose `target` is this collection; throws `RowReferencedException` if any row references `$uuid`). Events: `CollectionRowCreated/Updated/Deleted extends Glueful\Events\Contracts\BaseEvent` carrying `collectionName`, `rowUuid`, and (created/updated) the row.

- [ ] **Step 1: Failing tests** — write with a non-existent relation target throws; `?expand` resolves single + multi relations one level; deleting a referenced row throws `RowReferencedException`; create/update/delete each dispatch the matching event (assert via a recording listener on `EventService`).
- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement; wire into `RowRepository::create/update/delete`. **Step 4: Run** → PASS.
- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add soft relations (validate/expand/restrict-delete) + row change events"`

---

### Task 11: Public data API — actor/scope middleware + controller + routes + bulk create

**Files:**
- Create: `packages/lemma-collections/src/Http/ActorResolver.php`, `src/Http/CollectionScopeMiddleware.php`, `src/Http/Controllers/CollectionDataController.php`, `src/Http/routes.php`
- Modify: provider `services()` (controller + middleware with alias `collection_scope`), `config/lemma.php` (add `collections` config block: `max_per_page`, `max_bulk`), and **core** `app/Content/Http/OptionalApiKeyAuthMiddleware.php` (also `attributes->set('api_key_uuid', $key->uuid)` — verify the key object's own-id property — so an `api_key` actor is auditable by key, not just by owning user)
- Test: `tests/Integration/Collections/PublicApiTest.php`

**Interfaces:**
- Consumes: `CollectionManager`, `RowRepository`, `QueryCompiler`, `RelationResolver`, `CollectionDefinitionRepository`.
- Produces: routes `GET /v1/collections/{name}`, `GET /v1/collections/{name}/{uuid}`, `POST /v1/collections/{name}`, `POST /v1/collections/{name}/bulk`, `PATCH /v1/collections/{name}/{uuid}`, `DELETE /v1/collections/{name}/{uuid}` — registered **only when the capability is enabled** (the Task-2 boot gate), each behind the `optional_api_key` alias + `CollectionScopeMiddleware` requiring `collections.{name}.{read|write|delete}` (default-deny: no key/scope → 403 `ForbiddenException`). `ActorResolver::resolve(Request): Actor` reads the request attributes `OptionalApiKeyAuthMiddleware` set: `auth_method==='api_key'` → `Actor('api_key', $attrs->get('api_key_uuid'))`; an admin session → `Actor('admin', $userUuid)`; a non-admin user session → `Actor('user', $userUuid)`. Bulk: validate every row, reject the whole request with per-row errors if any invalid (`422`), else insert in one transaction (where supported) capped at `lemma.collections.max_bulk`.

- [ ] **Step 1: Failing tests** — read without a scoped key → 403; with `collections.products.read` → 200 list; create with `…write` round-trips and returns `uuid`; **a row created via an API key has `created_by_type='api_key'` and `created_by_id` = the key's `uuid`** (asserts the extended middleware attribute flows through `ActorResolver`); bulk create all-valid inserts N in one tx; bulk with one invalid row → 422 + per-row errors + zero inserted; delete-referenced → 409; `{uuid}` paths never accept the numeric id.
- [ ] **Step 2: Run** → FAIL. **Step 3:** Implement (mirror the delivery API's api-key/scope middleware pattern — `OptionalApiKeyAuthMiddleware` + `RequireContentScope`; reuse the `api_keys` scope check). Controller maps validation/relation exceptions to `422`/`409`. **Step 4: Run** → PASS.
- [ ] **Step 5: phpcbf/phpcs + Commit** — `git commit -m "Add public collections data API (scoped, default-deny) + bulk create"`

---

### Task 12: Backend removability + boundary + conformance proof

**Files:**
- Create: `tests/Integration/Collections/RemovabilityTest.php`, `tests/Integration/Collections/NoAppReferencesTest.php`
- Test: the two above + `composer boundaries`

**Interfaces:** none (proof only).

- [ ] **Step 1: Write `NoAppReferencesTest`** — every file under `packages/lemma-collections/src` matches none of `/(^|[^\w])App\\/m` (mirror `lemma-importers`' boundary test, with the regex aligned to the guard script).
- [ ] **Step 2: Write `RemovabilityTest`** — boot the app with `lemma.collections` disabled (config `lemma.capabilities` → `['lemma.collections' => false]`, the way `CapabilityGatingTest` boots the default-no-search install). The Task-2 boot gate means the **public routes are never registered**, so `GET /v1/collections/{name}` returns **404** (not a 403 from a live-but-disabled handler); meanwhile `collection_definitions` and every `collection_*` data table still exist (disable never drops), and the content-engine services still resolve. (Mirror `SnapshotSurvivesWithoutAdaptersTest` + `CapabilityGatingTest`.)
- [ ] **Step 3: Run** the full suite + `composer boundaries` + `composer phpcs` → all green.
- [ ] **Step 4: Commit** — `git commit -m "Prove lemma-collections backend removability + boundary"`

---

## Plan 1 Self-Review

- **Spec coverage:** §2 surfaces/auth/actor → Tasks 11 (public) + (admin is Plan 2); §3 registry → Task 1; §4 data model → Tasks 3,4,6,8; §5 lifecycle/DDL/audit → Tasks 5,6,7; §6 API → Tasks 9,11; §7 relations → Task 10; §8 events → Task 10; §10 boundary/deps → Tasks 2,12; §11 out-of-scope respected (no rename/retype/bulk-patch/realtime/row-rules); §12 testing → woven per task + Task 12. Admin (§9) is intentionally Plan 2.
- **Placeholders:** none — every code step shows real signatures/tests; the provider `services()` stub in Task 2 is explicitly filled by later tasks (noted), not a placeholder.
- **Type consistency:** `CollectionDefinition`/`CollectionField`/`ColumnMapper`/`SchemaChange`/`Actor`/`ListResult` signatures are introduced in their Produces blocks and reused verbatim downstream; `FieldTypeRegistry`/`FieldTypeDefinition` keys are namespaced consistently (`content.*`, `collections.*`).

**Definition of done:** create a collection via API/tests → a real table materializes; write/read/query/relate rows through `/v1/collections/{name}` with scoped keys; disable/remove the pack with content untouched and tables preserved.
