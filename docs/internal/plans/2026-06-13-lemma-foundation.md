# Lemma Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up Lemma's content engine foundation — scaffold from `glueful/api-skeleton`, the §1 PostgreSQL data model, dynamic field validation, and the admin API for content-type CRUD plus the draft → publish → version → publication lifecycle (§2 semantics).

**Architecture:** Lemma is a Glueful *application* built on `glueful/api-skeleton` (namespace `App\`, content domain under `app/Content/`). Content-type field *values* live in a single `JSONB` column on version/draft rows (no table-per-type, no EAV); identity, state, routing, and references stay relational. Drafts are a single mutable working copy per (entry, locale) under optimistic concurrency; an immutable `entry_versions` snapshot is written only at publish; an `entry_publications` pin selects the live version. The delivery API (later plan) reads only through `entry_publications JOIN entry_versions`, so drafts physically cannot leak.

**Tech Stack:** PHP 8.3, Glueful framework ^1.56.0, PostgreSQL 15+ (JSONB), `glueful/users` (identity), `glueful/aegis` (RBAC), PHPUnit 10. Scaffolded via `composer create-project glueful/api-skeleton`.

**Scope boundary (foundation only — these are LATER plans, do not build here):** delivery/read API, filterable-field expression indexes, the publish *pipeline* side effects (domain events, webhooks, cache tags, CDN/search enqueues), preview tokens, the admin SPA, and import/export adapters. The `entry_references` *table* is created here (it is part of the schema spine), but its write-time projection and delivery-time resolution are deferred to the delivery plan — nothing reads it in foundation.

**Source of truth:** [`../V1_DESIGN.md`](../V1_DESIGN.md) §1–§3, §7, §11 and [`../APPROACH.md`](../APPROACH.md). Where this plan says "per §N", that section is authoritative on intent.

---

## Conventions locked for this plan (read once)

These resolve framework specifics so later tasks are unambiguous:

- **Identifiers:** 12-char NanoIDs via `Glueful\Helpers\Utils::generateNanoID(12)`, stored as `string('uuid', 12)` columns. This matches every Glueful extension and keeps `created_by`/`updated_by` type-compatible with the `glueful/users` store. (Postgres has a native `uuid()` type, but FK-compatibility with the nanoid ecosystem wins.)
- **JSONB:** `$table->json('fields')` renders as `JSONB` on PostgreSQL (verified: `PostgreSQLSqlGenerator` maps `'json' => 'JSONB'`). Use `->json()` for every field-value/schema column.
- **Composite "primary key (entry_uuid, locale)"** from the design is implemented as a surrogate `bigInteger('id')->primary()->autoIncrement()` plus a `unique([...])` constraint — the framework idiom (the schema builder's primary path is the surrogate id; uniqueness is enforced by the named unique index). Semantics are identical.
- **Migrations** live in `database/migrations/` as plain (unnamespaced) classes implementing `Glueful\Database\Migrations\MigrationInterface`; the runner `include`s the file and instantiates by class name. Filenames are numeric-prefixed (`001_…`). Auto-discovered from `config/database.php` `migrations.path`. **Every `createTable` migration's `up()` opens with the codebase idiom guard `if ($schema->hasTable('<table>')) { return; }`** (re-run safety; matches framework-core/users/aegis migrations). **Data-seed migrations (e.g. `008`) are the exception** — the target tables already exist, so a `hasTable` guard would wrongly skip seeding; they achieve idempotency via row-level batch-check-then-insert instead (per aegis `003_SeedDefaultRoles`).
- **Repositories** inject `Glueful\Database\Connection` and use the query builder: `$this->db->table('t')->where('k', '=', $v)->first()|get()|insert($row)|update($data)`.
- **Responses** use `Glueful\Http\Response` statics: `success()`, `created()`, `validation()`, `notFound()`, `error()`, `paginated()`.
- **Transactions:** `db($context)->transaction(fn() => …)` (per CLAUDE.md; `DB::transaction` does not exist).
- **Permission gating** follows the `RequireConversaPermission`/`RequireFlagsPermission` pattern (a `RouteMiddleware` that resolves `Glueful\Permissions\PermissionManager`, fails closed).
- **Postgres is required.** Integration tests run against a real PostgreSQL service; JSONB and expression behavior are not exercised by SQLite. Pure-logic unit tests (e.g. `FieldValidator`) may run on any backend since they touch no DB.
- **Running tests:** integration tests need the `lemma_test` schema present. `composer test` migrates it first (`test:migrate`, set up in Task 3) then runs PHPUnit. The per-task `Run:` lines that call `vendor/bin/phpunit tests/Integration/...` directly assume `composer test:migrate` has already been run once in the session; for a single test use `composer test:migrate` once then `composer test:phpunit -- --filter <Name>` (never `composer test -- --filter` — Composer forwards the arg to every script in the `test` array). Unit-test `Run:` lines (`tests/Unit/...`) need no DB.

---

## File structure

```
config/
  lemma.php                                   # default_locale, media_disk, role names
app/
  Providers/
    LemmaServiceProvider.php                  # registers repos/services + lemma_permission alias + routes/migrations
  Content/
    Schema/
      FieldDefinition.php                     # one field's parsed definition (name,type,required,localized,filterable,filter_type)
      ContentTypeSchema.php                   # parsed schema (list<FieldDefinition>) + lookups
      SchemaParseException.php
    Validation/
      FieldValidator.php                      # validates a fields payload against a ContentTypeSchema
      ValidationException.php                 # carries field => message map
    Repositories/
      ContentTypeRepository.php
      EntryRepository.php                     # entries + drafts (identity + working copy)
      VersionRepository.php                   # entry_versions + entry_publications
      RouteRepository.php                     # entry_routes
    Services/
      PublishService.php                      # the publish/unpublish/rollback transaction (§2 crux)
    Support/
      OptimisticLockException.php             # -> 409
    Http/
      Controllers/
        ContentTypeController.php             # /v1/admin/content-types
        EntryController.php                   # /v1/admin/entries (+ drafts)
        PublicationController.php             # publish / unpublish / rollback
      RequireLemmaPermission.php              # RouteMiddleware, alias 'lemma_permission'
routes/
  lemma_admin.php                             # /v1/admin route group (loaded by LemmaServiceProvider)
database/migrations/
  001_CreateContentTypesTable.php
  002_CreateEntriesTable.php
  003_CreateEntryDraftsTable.php
  004_CreateEntryVersionsTable.php
  005_CreateEntryPublicationsTable.php
  006_CreateEntryRoutesTable.php
  007_CreateEntryReferencesTable.php
  008_SeedLemmaRolesAndPermissions.php        # aegis roles/permissions (idempotent)
tests/
  Support/LemmaTestCase.php                   # boots framework against Postgres test DB, clears tables
  Unit/Content/FieldValidatorTest.php
  Integration/Migrations/SchemaTest.php
  Integration/Content/ContentTypeRepositoryTest.php
  Integration/Content/EntryRepositoryTest.php
  Integration/Content/PublishServiceTest.php
  Integration/Content/RouteRepositoryTest.php
  Integration/Http/ContentTypeApiTest.php
  Integration/Http/EntryApiTest.php
  Integration/Http/PublicationApiTest.php
  Integration/FoundationFlowTest.php
```

---

### Task 1: Scaffold the Lemma app and configure PostgreSQL

> **STATUS (2026-06-13): essentially complete.** Scaffolded into the repo; `composer.json` pins framework `^1.56.0` + `glueful/{users,email-notification,media,aegis}`; all four enabled in `config/extensions.php`; `.env` set to Postgres via `DB_PGSQL_*` (db `lemma`, user `lemma_app`, `DB_PGSQL_SCHEMA=public`); `APP_KEY`/`JWT_KEY` generated by the scaffold's `install`; `config/lemma.php` created. **Remaining:** (a) commit the scaffold baseline; (b) **`php glueful migrate:run` has NOT been run yet** — it is the next action and creates the framework-core + users/aegis tables (Lemma's own `001…008` migrations don't exist until Tasks 2/10).

**Files:**
- Create (scaffold): the whole `glueful/api-skeleton` tree into the `lemma/` repo
- Modify: `.env`, `config/extensions.php`, `composer.json`
- Create: `config/lemma.php`

- [ ] **Step 1: Preflight — commit the current docs baseline so the tree is clean**

The intended starting point is **the api-skeleton contents + the current `docs/`** (V1_DESIGN/APPROACH/ADAPTER_NOTES + this plan). The repo is currently *dirty*: the three design docs are tracked at the repo **root** but the working tree has moved them into `docs/` (`git status --short` shows ` D ADAPTER_NOTES.md`, ` D APPROACH.md`, ` D V1_DESIGN.md`, `?? docs/`). Commit that reorganization first so the scaffold lands on a clean tree and isn't entangled with the docs move:

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add -A
git commit -m "Move design docs + foundation plan under docs/"
git status --short   # MUST be empty before continuing
```

Expected: working tree clean; `docs/V1_DESIGN.md`, `docs/APPROACH.md`, `docs/ADAPTER_NOTES.md`, `docs/plans/2026-06-13-lemma-foundation.md` are the tracked locations.

- [ ] **Step 2: Scaffold the skeleton into the repo (preserving `docs/` and `.git/`)**

> **What `create-project` does automatically (do not repeat it later):** the skeleton's `post-create-project-cmd` runs, inside `lemma-scaffold`: (1) copies `.env.example` → `.env`; (2) creates `storage/database/glueful.sqlite`; (3) runs `glueful install --force`, which **generates `APP_KEY`, `JWT_KEY`, `TOKEN_SALT` into `.env`** and runs migrations — but `install` is **SQLite-only by design** (it refuses other engines). So after this step the scaffold already has a `.env` with real keys; Lemma just needs to repoint that `.env` at Postgres (Step 5) and migrate Postgres manually (Step 7). The plan therefore does **not** copy `.env` or run `generate:key`/`install` again.

```bash
cd /Users/michaeltawiahsowah/Sites/glueful
composer create-project glueful/api-skeleton lemma-scaffold   # runs post-create: .env + keys + sqlite install
# carry app files AND the generated .env into the repo; never touch .git or docs
rsync -a --exclude='.git' --exclude='docs' lemma-scaffold/ lemma/
rm -rf lemma-scaffold
cd lemma
rm -f storage/database/glueful.sqlite   # drop the scaffold's SQLite db; Lemma uses Postgres
```

Expected: `lemma/` has `app/`, `bootstrap/`, `config/`, `public/`, `routes/`, `glueful`, `composer.json`, and a `.env` carrying generated `APP_KEY`/`JWT_KEY` (SQLite-configured for now), alongside the committed `docs/`.

- [ ] **Step 3: Pin the released framework + extensions, install**

Confirm `composer.json` `require` pins (the skeleton release already targets these): `glueful/framework: ^1.56.0`, `glueful/users: ^1.0.1`, `glueful/email-notification: ^1.10.0`, `glueful/media: ^1.1.0`. Add aegis:

```bash
composer require glueful/aegis:^1.7.1
composer install
```

Expected: `vendor/` populated, no resolver errors.

- [ ] **Step 4: Enable users + aegis in the extension allowlist**

Edit `config/extensions.php` so `enabled` contains both providers (users is already there from the skeleton; add aegis):

```php
return [
    'enabled' => [
        'Glueful\\Extensions\\Users\\UsersServiceProvider',
        'Glueful\\Extensions\\EmailNotification\\EmailNotificationServiceProvider',
        'Glueful\\Extensions\\Media\\MediaServiceProvider',
        'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
    ],
];
```

- [ ] **Step 5: Repoint the existing `.env` at PostgreSQL**

The `.env` already exists (carried from the scaffold, with generated keys). **Edit** these keys in place — do not re-copy `.env.example`. Create the DB first (`createdb lemma`). **The framework reads driver-prefixed `DB_PGSQL_*` vars for Postgres** (`config/database.php` `pgsql` block); the generic `DB_HOST`/`DB_DATABASE`/… are the *mysql* block and are inert here:

```env
APP_ENV=development
DB_DRIVER=pgsql
DB_PGSQL_HOST=127.0.0.1
DB_PGSQL_PORT=5432
DB_PGSQL_DATABASE=lemma
DB_PGSQL_USERNAME=lemma_app
DB_PGSQL_PASSWORD=<your-password>
DB_PGSQL_SCHEMA=public
DB_PGSQL_SSL_MODE=prefer
```

`APP_KEY`/`JWT_KEY`/`TOKEN_SALT` are already populated by the scaffold's `install`; leave them. `DB_PGSQL_SCHEMA=public` is the framework default and is correct for v1 — Lemma uses **row-level** tenancy (a `tenant_uuid` column via `glueful/tenancy`), never schema-per-tenant, so you never move off `public`.

- [ ] **Step 6: Create `config/lemma.php`**

```php
<?php

return [
    // Default content locale. When glueful/i18n is installed the localization
    // phase binds this to i18n.default_locale; in v1 it is a plain code.
    'default_locale' => env('LEMMA_DEFAULT_LOCALE', 'en'),

    // Glueful storage disk that backs media blob references (see V1_DESIGN §8).
    'media_disk' => env('LEMMA_MEDIA_DISK', 'local'),

    // Seeded role names (see V1_DESIGN §7).
    'roles' => [
        'admin' => 'lemma_admin',
        'editor' => 'lemma_editor',
        'viewer' => 'lemma_viewer',
    ],
];
```

- [ ] **Step 7: Migrate Postgres + verify boot (managed server — no zombie process)**

Keys already exist (Step 2's `install`); just migrate the now-Postgres `.env` and health-check. `install` is SQLite-only, so this is the first Postgres migration:

```bash
php glueful migrate:run
# Start the dev server, capture its PID, health-check, then stop it.
php glueful serve > /tmp/lemma-serve.log 2>&1 &
SERVE_PID=$!
sleep 2
curl -fsS http://127.0.0.1:8080/health || { kill "$SERVE_PID"; cat /tmp/lemma-serve.log; exit 1; }
kill "$SERVE_PID"
```

Expected: `migrate:run` creates the framework/users/aegis tables on Postgres with no error; `/health` returns a healthy JSON body, then the server is stopped (no orphaned process). (Agent runners: if your tool offers a managed background-process handle, use it to start `serve` and stop it after the health check instead of the PID dance.)

- [ ] **Step 8: Commit the scaffold baseline**

The docs were already committed in Step 1, so this commit contains only the scaffold + config:

```bash
git add -A
git commit -m "Scaffold Lemma from api-skeleton; configure PostgreSQL + aegis"
```

---

### Task 2: The §1 content schema migrations (7 tables)

**Files:**
- Create: `database/migrations/001_CreateContentTypesTable.php` … `007_CreateEntryReferencesTable.php`

> **Ordering note:** the PHPUnit `SchemaTest` that asserts these tables/columns lives in **Task 3** (it needs the `LemmaTestCase` harness). This task writes the migrations and verifies them via the CLI runner, so it is self-contained and the plan reads cleanly top-to-bottom.

- [ ] **Step 1: Write the migrations**

`database/migrations/001_CreateContentTypesTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateContentTypesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('content_types', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('slug', 160);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('schema');                 // field definitions (JSONB)
            $table->integer('schema_version')->default(1);
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->unique('uuid');
            $table->unique('slug');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('content_types');
    }

    public function getDescription(): string
    {
        return 'Create content_types (content models + JSONB field schema).';
    }
}
```

`database/migrations/002_CreateEntriesTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntriesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('entries', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('content_type_uuid', 12);
            $table->enum('status', ['active', 'archived', 'deleted'], 'active');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->unique('uuid');
            $table->index('content_type_uuid');
            $table->index('status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entries');
    }

    public function getDescription(): string
    {
        return 'Create entries (locale-neutral identity spine).';
    }
}
```

`database/migrations/003_CreateEntryDraftsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryDraftsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('entry_drafts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->json('fields');
            $table->integer('schema_version');
            $table->integer('lock_version')->default(0);
            $table->string('updated_by', 12)->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            // "one draft per (entry, locale)" — surrogate id + unique pair
            $table->unique(['entry_uuid', 'locale'], 'uniq_draft_entry_locale');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_drafts');
    }

    public function getDescription(): string
    {
        return 'Create entry_drafts (single mutable working copy per entry+locale).';
    }
}
```

`database/migrations/004_CreateEntryVersionsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryVersionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('entry_versions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->integer('version');
            $table->json('fields');
            $table->integer('schema_version');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->unique('uuid');
            $table->unique(['entry_uuid', 'locale', 'version'], 'uniq_version_entry_locale_version');
            $table->index(['entry_uuid', 'locale']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_versions');
    }

    public function getDescription(): string
    {
        return 'Create entry_versions (immutable append-only snapshots written at publish).';
    }
}
```

`database/migrations/005_CreateEntryPublicationsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryPublicationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('entry_publications', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->string('version_uuid', 12);       // -> entry_versions.uuid
            $table->string('published_by', 12)->nullable();
            $table->timestamp('published_at')->default('CURRENT_TIMESTAMP');
            $table->unique(['entry_uuid', 'locale'], 'uniq_publication_entry_locale');
            $table->index('version_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_publications');
    }

    public function getDescription(): string
    {
        return 'Create entry_publications (the published-version pin, one per entry+locale).';
    }
}
```

`database/migrations/006_CreateEntryRoutesTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryRoutesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('entry_routes', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('content_type_uuid', 12);  // denormalized so route lookups never join entries
            $table->string('locale', 16);
            $table->string('slug', 200);
            $table->unique(['content_type_uuid', 'locale', 'slug'], 'uniq_route_type_locale_slug');
            $table->index('entry_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_routes');
    }

    public function getDescription(): string
    {
        return 'Create entry_routes (per content-type + locale slug uniqueness).';
    }
}
```

`database/migrations/007_CreateEntryReferencesTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryReferencesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('entry_references', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('source_entry_uuid', 12);
            $table->string('source_field', 160);
            $table->string('target_entry_uuid', 12);
            $table->unique(
                ['source_entry_uuid', 'source_field', 'target_entry_uuid'],
                'uniq_reference_source_field_target'
            );
            $table->index('target_entry_uuid');  // reverse lookups ("what links here")
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_references');
    }

    public function getDescription(): string
    {
        return 'Create entry_references (normalized reference index; projection deferred to delivery plan).';
    }
}
```

- [ ] **Step 2: Run the migrations and verify via the CLI**

```bash
php glueful migrate:run
php glueful migrate:status        # all 7 lemma migrations listed as applied
# Confirm JSONB landed (psql), not plain json:
psql lemma -tAc "SELECT data_type FROM information_schema.columns WHERE table_name='entry_versions' AND column_name='fields';"
```

Expected: `migrate:run` applies `001…007` with no error; the `psql` check prints `jsonb`. (The PHPUnit assertions for table existence + the JSONB column are added in Task 3 with the harness.)

- [ ] **Step 3: Commit**

```bash
git add database/migrations
git commit -m "Add Lemma content schema migrations (content_types..entry_references)"
```

---

### Task 3: Test harness (`LemmaTestCase`) + the PHPUnit schema test

**Files:**
- Create: `tests/Support/LemmaTestCase.php`, `tests/Integration/Migrations/SchemaTest.php`
- Modify: `phpunit.xml` (Postgres test env), `composer.json` (`test` script migrates the test DB first)

**Why the schema is migrated outside the harness:** the framework `MigrationManager` is awkward to construct/resolve inside a `TestCase`, and its public runner is `migrate()` (there is no `runPending()`). So the test database is migrated by the `composer test` script *before* PHPUnit starts; the harness only boots the framework and clears tables between tests. The `QueryBuilder` has no `truncate()` — cleanup uses `->where('id', '>', 0)->delete()` (every Lemma table has an `id`).

**`migrate:run` creates far more than Lemma's 7 tables.** It applies the framework **core** migrations (`/framework/migrations`: `auth_sessions`, `auth_refresh_tokens`, `api_keys`, `blobs`, `locks`, the `api_metrics*` / `notification*` / `queue*` tables, `scheduled_jobs`, `job_executions` — some gated by `config/capabilities.php`) **and** the enabled extensions' migrations (`users`/`aegis`: `users`, `profiles`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_permissions`), in addition to Lemma's `001…008`. Two consequences for the harness:
> - `setUp()` clears **only the 7 Lemma content tables** — never the core/users/aegis tables. The lemma roles/permissions seeded by migration `008` and any RBAC fixtures must persist across tests (re-seeding every test would be wasteful and the seed is idempotent anyway).
> - Tests that insert rows into shared tables the harness doesn't clear (e.g. a `users` row for `actingAsAdmin` in Task 14) must use **unique identifiers** or clean up after themselves, since those rows survive between tests.

- [ ] **Step 1: Configure the Postgres test DB + a migrate-then-test script**

Create `lemma_test` and grant the dev DB user access (`createdb -O lemma_app lemma_test`, or `createdb lemma_test` then `GRANT ALL … TO lemma_app`). **Override only the database name** in `phpunit.xml` — host/user/**password** come from `.env` (the real password must NOT live in the committed `phpunit.xml`). The var is `DB_PGSQL_DATABASE`, not `DB_DATABASE` (the framework's pgsql block reads `DB_PGSQL_*`):

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_PGSQL_DATABASE" value="lemma_test"/>
</php>
```

> **Why this matters (data safety):** the harness `setUp()` deletes all rows from the 7 Lemma tables on every test. If the test DB name does not override correctly, those deletes hit the **dev `lemma`** database. Using the wrong var name (`DB_DATABASE`) would be silently ignored by the pgsql block — so it **must** be `DB_PGSQL_DATABASE`. After wiring this, sanity-check once: `composer test:migrate` then `psql -d lemma_test -c '\dt'` should show the Lemma tables in `lemma_test`, and `psql -d lemma -c 'SELECT count(*) FROM content_types'` should be untouched.

In `composer.json` `scripts`, make `test` migrate the test DB first (inline `DB_PGSQL_DATABASE` overrides `.env`; dotenv is non-overriding so the inline value wins). Keep PHPUnit in its **own** script so a `--filter` passed to `composer test:phpunit` is never appended to the migration command:

```json
"scripts": {
    "test:migrate": "DB_PGSQL_DATABASE=lemma_test APP_ENV=testing php glueful migrate:run --force",
    "test:phpunit": "vendor/bin/phpunit",
    "test": ["@test:migrate", "@test:phpunit"]
}
```

> Run the full suite with `composer test`. To run a subset, run `composer test:migrate` once, then `composer test:phpunit -- --filter <Name>` — never `composer test -- --filter <Name>` (Composer would forward `--filter` to *every* script in the `test` array, including `migrate:run`).

- [ ] **Step 2: Write `LemmaTestCase` (boot + truncate only — no migrate inside the harness)**

`tests/Support/LemmaTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

abstract class LemmaTestCase extends TestCase
{
    protected static ?ApplicationContext $app = null;

    // Truncate order is child -> parent (no FKs in v1, but keep it deterministic).
    private const TABLES = [
        'entry_references', 'entry_routes', 'entry_publications',
        'entry_versions', 'entry_drafts', 'entries', 'content_types',
    ];

    public static function setUpBeforeClass(): void
    {
        if (self::$app === null) {
            $root = dirname(__DIR__, 2);
            // Schema is created by `composer test:migrate` before PHPUnit runs.
            // boot() returns Glueful\Application; store its ApplicationContext
            // (Application::getContext()) so the ?ApplicationContext property + appContext() are correct.
            self::$app = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        }
    }

    protected function setUp(): void
    {
        // QueryBuilder has no truncate(); delete-all via a tautological predicate
        // (every Lemma table has an integer `id`). Deletes commit immediately.
        foreach (self::TABLES as $t) {
            $this->connection()->table($t)->where('id', '>', 0)->delete();
        }
    }

    protected function appContext(): ApplicationContext
    {
        return self::$app;
    }

    protected function connection(): Connection
    {
        return self::$app->getContainer()->get(Connection::class);
    }
}
```

- [ ] **Step 3: Write the schema test (now the harness exists)**

`tests/Integration/Migrations/SchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\LemmaTestCase;

final class SchemaTest extends LemmaTestCase
{
    /** @dataProvider tables */
    public function testTableExists(string $table): void
    {
        self::assertTrue(
            $this->connection()->getSchemaBuilder()->hasTable($table),
            "expected table {$table} to exist after migration"
        );
    }

    /** @return array<int, array{0:string}> */
    public static function tables(): array
    {
        return array_map(
            static fn(string $t): array => [$t],
            [
                'content_types', 'entries', 'entry_drafts', 'entry_versions',
                'entry_publications', 'entry_routes', 'entry_references',
            ]
        );
    }

    public function testFieldsColumnIsJsonb(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'entry_versions')
            ->where('column_name', '=', 'fields')
            ->first();
        self::assertSame('jsonb', $col['data_type']);
    }
}
```

- [ ] **Step 4: Run the schema test through the harness**

Run: `composer test:migrate && composer test:phpunit -- --filter SchemaTest`
Expected: PASS — migrations applied by `test:migrate`, all 7 tables present, `entry_versions.fields` is `jsonb`. (`hasTable()` and `getSchemaBuilder()` are confirmed `Connection` methods.)

- [ ] **Step 5: Commit**

```bash
git add tests/Support/LemmaTestCase.php tests/Integration/Migrations/SchemaTest.php phpunit.xml composer.json
git commit -m "Add PostgreSQL-backed Lemma test harness + schema test"
```

---

### Task 4: `ContentTypeSchema` + `FieldDefinition` value objects

**Files:**
- Create: `app/Content/Schema/FieldDefinition.php`, `app/Content/Schema/ContentTypeSchema.php`, `app/Content/Schema/SchemaParseException.php`
- Test: `tests/Unit/Content/ContentTypeSchemaTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Content/ContentTypeSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\SchemaParseException;
use PHPUnit\Framework\TestCase;

final class ContentTypeSchemaTest extends TestCase
{
    public function testParsesFields(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);

        self::assertSame(['title', 'price'], array_map(fn($f) => $f->name, $schema->fields()));
        self::assertTrue($schema->field('title')->required);
        self::assertTrue($schema->field('price')->filterable);
        self::assertSame('number', $schema->field('price')->filterType);
        self::assertNull($schema->field('missing'));
    }

    public function testFilterableFieldMustDeclareFilterType(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'price', 'type' => 'number', 'filterable' => true],
        ]);
    }

    public function testRejectsDuplicateFieldNames(): void
    {
        $this->expectException(SchemaParseException::class);
        ContentTypeSchema::fromArray([
            ['name' => 'a', 'type' => 'string'],
            ['name' => 'a', 'type' => 'number'],
        ]);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Content/ContentTypeSchemaTest.php`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement the value objects**

`app/Content/Schema/SchemaParseException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Schema;

final class SchemaParseException extends \InvalidArgumentException
{
}
```

`app/Content/Schema/FieldDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Schema;

final class FieldDefinition
{
    public const TYPES = ['string', 'text', 'number', 'boolean', 'datetime', 'enum', 'reference', 'asset', 'json'];
    public const FILTER_TYPES = ['string', 'number', 'boolean', 'datetime', 'enum'];

    /** @param list<string> $enumValues */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $localized = false,
        public readonly bool $filterable = false,
        public readonly ?string $filterType = null,
        public readonly array $enumValues = [],
    ) {
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $name = isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : '';
        if ($name === '' || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new SchemaParseException("field name must match [a-z][a-z0-9_]*: '{$name}'");
        }
        $type = $raw['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new SchemaParseException("field '{$name}' has invalid type");
        }
        $filterable = (bool) ($raw['filterable'] ?? false);
        $filterType = $raw['filter_type'] ?? null;
        if ($filterable) {
            if (!is_string($filterType) || !in_array($filterType, self::FILTER_TYPES, true)) {
                throw new SchemaParseException("filterable field '{$name}' must declare a valid filter_type");
            }
        } else {
            $filterType = null;
        }
        $enum = [];
        if ($type === 'enum') {
            $enum = array_values(array_filter(
                array_map('strval', (array) ($raw['enum'] ?? [])),
                static fn(string $v): bool => $v !== ''
            ));
            if ($enum === []) {
                throw new SchemaParseException("enum field '{$name}' requires non-empty enum values");
            }
        }

        return new self(
            name: $name,
            type: $type,
            required: (bool) ($raw['required'] ?? false),
            localized: (bool) ($raw['localized'] ?? false),
            filterable: $filterable,
            filterType: $filterType,
            enumValues: $enum,
        );
    }
}
```

`app/Content/Schema/ContentTypeSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Schema;

final class ContentTypeSchema
{
    /** @param array<string,FieldDefinition> $byName */
    private function __construct(private readonly array $byName)
    {
    }

    /** @param list<array<string,mixed>> $raw */
    public static function fromArray(array $raw): self
    {
        $byName = [];
        foreach ($raw as $fieldRaw) {
            if (!is_array($fieldRaw)) {
                throw new SchemaParseException('each field definition must be an object');
            }
            $field = FieldDefinition::fromArray($fieldRaw);
            if (isset($byName[$field->name])) {
                throw new SchemaParseException("duplicate field name '{$field->name}'");
            }
            $byName[$field->name] = $field;
        }
        return new self($byName);
    }

    /** @return list<FieldDefinition> */
    public function fields(): array
    {
        return array_values($this->byName);
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->byName[$name] ?? null;
    }

    /** @return list<array<string,mixed>> normalized form for persistence */
    public function toArray(): array
    {
        return array_map(static fn(FieldDefinition $f): array => array_filter([
            'name' => $f->name,
            'type' => $f->type,
            'required' => $f->required,
            'localized' => $f->localized,
            'filterable' => $f->filterable,
            'filter_type' => $f->filterType,
            'enum' => $f->enumValues,
        ], static fn($v): bool => $v !== false && $v !== null && $v !== []), $this->fields());
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Content/ContentTypeSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Content/Schema tests/Unit/Content/ContentTypeSchemaTest.php
git commit -m "Add ContentTypeSchema/FieldDefinition value objects"
```

---

### Task 5: `FieldValidator` (validate a fields payload against a schema)

**Files:**
- Create: `app/Content/Validation/FieldValidator.php`, `app/Content/Validation/ValidationException.php`
- Test: `tests/Unit/Content/FieldValidatorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Content/FieldValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class FieldValidatorTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'price', 'type' => 'number'],
            ['name' => 'status', 'type' => 'enum', 'enum' => ['draft', 'live']],
            ['name' => 'active', 'type' => 'boolean'],
        ]);
    }

    public function testAcceptsValidPayloadAndDropsUnknownKeys(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'Hi', 'price' => 9.5, 'status' => 'live', 'active' => true,
            'sneaky' => 'x', // unknown -> dropped, not an error
        ]);
        self::assertSame(['title' => 'Hi', 'price' => 9.5, 'status' => 'live', 'active' => true], $clean);
    }

    public function testRejectsMissingRequired(): void
    {
        try {
            (new FieldValidator())->validate($this->schema(), ['price' => 1]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('title', $e->errors());
        }
    }

    public function testRejectsWrongType(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['title' => 'ok', 'price' => 'not-a-number']);
    }

    public function testRejectsEnumOutsideSet(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['title' => 'ok', 'status' => 'archived']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Content/FieldValidatorTest.php`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement**

`app/Content/Validation/ValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Validation;

final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('field validation failed');
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
```

`app/Content/Validation/FieldValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Validation;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;

final class FieldValidator
{
    /**
     * Validate a fields payload against a content type schema.
     * Returns the cleaned payload (known fields only, in schema order).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(ContentTypeSchema $schema, array $payload): array
    {
        $errors = [];
        $clean = [];

        foreach ($schema->fields() as $field) {
            $present = array_key_exists($field->name, $payload);
            $value = $present ? $payload[$field->name] : null;

            if (!$present || $value === null) {
                if ($field->required) {
                    $errors[$field->name] = 'is required';
                }
                continue;
            }

            $error = $this->checkType($field, $value);
            if ($error !== null) {
                $errors[$field->name] = $error;
                continue;
            }
            $clean[$field->name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    private function checkType(FieldDefinition $field, mixed $value): ?string
    {
        return match ($field->type) {
            'string', 'text' => is_string($value) ? null : 'must be a string',
            'number' => (is_int($value) || is_float($value)) ? null : 'must be a number',
            'boolean' => is_bool($value) ? null : 'must be a boolean',
            'datetime' => (is_string($value) && strtotime($value) !== false) ? null : 'must be an ISO datetime',
            'enum' => in_array($value, $field->enumValues, true) ? null
                : 'must be one of: ' . implode(', ', $field->enumValues),
            'reference', 'asset' => (is_string($value) && $value !== '') ? null : 'must be a uuid',
            'json' => (is_array($value)) ? null : 'must be an object/array',
            default => 'unknown field type',
        };
    }
}
```

> Reference/asset *existence* (that the target entry/blob exists) is intentionally **not** checked here — references resolve at delivery (V1_DESIGN §4) and broken references degrade to omitted. Foundation validates shape only.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Content/FieldValidatorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Content/Validation tests/Unit/Content/FieldValidatorTest.php
git commit -m "Add schema-driven FieldValidator"
```

---

### Task 6: `ContentTypeRepository`

**Files:**
- Create: `app/Content/Repositories/ContentTypeRepository.php`
- Test: `tests/Integration/Content/ContentTypeRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\LemmaTestCase;

final class ContentTypeRepositoryTest extends LemmaTestCase
{
    private function repo(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    public function testCreateThenFindBySlug(): void
    {
        $uuid = $this->repo()->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            'created_by' => 'user00000001',
        ]);
        $row = $this->repo()->findBySlug('post');
        self::assertSame($uuid, $row['uuid']);
        self::assertSame(1, $row['schema_version']);
        self::assertSame('title', $row['schema'][0]['name']);
    }

    public function testUpdateSchemaBumpsSchemaVersion(): void
    {
        $uuid = $this->repo()->create(['slug' => 'post', 'name' => 'Post', 'schema' => []]);
        $this->repo()->updateSchema($uuid, [['name' => 'body', 'type' => 'text']]);
        self::assertSame(2, $this->repo()->findByUuid($uuid)['schema_version']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Content/ContentTypeRepositoryTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ContentTypeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): string
    {
        $uuid = Utils::generateNanoID(12);
        $schema = ContentTypeSchema::fromArray((array) ($data['schema'] ?? []));
        $this->db->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => (string) $data['slug'],
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'schema' => json_encode($schema->toArray(), JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_by' => isset($data['created_by']) ? (string) $data['created_by'] : null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        return $uuid;
    }

    /** @param list<array<string,mixed>> $schema */
    public function updateSchema(string $uuid, array $schema): void
    {
        $parsed = ContentTypeSchema::fromArray($schema);
        $current = $this->findByUuid($uuid);
        $this->db->table('content_types')->where('uuid', '=', $uuid)->update([
            'schema' => json_encode($parsed->toArray(), JSON_THROW_ON_ERROR),
            'schema_version' => (int) $current['schema_version'] + 1,
            'updated_at' => $this->now(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->hydrate($this->db->table('content_types')->where('uuid', '=', $uuid)->first());
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->hydrate($this->db->table('content_types')->where('slug', '=', $slug)->first());
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(
            fn(array $r): array => (array) $this->hydrate($r),
            $this->db->table('content_types')->orderBy('slug', 'ASC')->get()
        );
    }

    public function schemaFor(string $uuid): ContentTypeSchema
    {
        $row = $this->findByUuid($uuid);
        if ($row === null) {
            throw new \RuntimeException("content type {$uuid} not found");
        }
        return ContentTypeSchema::fromArray($row['schema']);
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['schema'] = is_string($row['schema'] ?? null)
            ? (json_decode((string) $row['schema'], true) ?? [])
            : (array) ($row['schema'] ?? []);
        $row['schema_version'] = (int) $row['schema_version'];
        return $row;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Content/ContentTypeRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Content/Repositories/ContentTypeRepository.php tests/Integration/Content/ContentTypeRepositoryTest.php
git commit -m "Add ContentTypeRepository"
```

---

### Task 7: `EntryRepository` (identity + optimistic-locked draft)

**Files:**
- Create: `app/Content/Repositories/EntryRepository.php`, `app/Content/Support/OptimisticLockException.php`
- Test: `tests/Integration/Content/EntryRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Tests\Support\LemmaTestCase;

final class EntryRepositoryTest extends LemmaTestCase
{
    private function repo(): EntryRepository
    {
        return new EntryRepository($this->connection());
    }

    public function testCreateEntryStartsAnEmptyDraft(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $draft = $this->repo()->findDraft($entry, 'en');
        self::assertSame(0, $draft['lock_version']);
        self::assertSame([], $draft['fields']);
    }

    public function testSaveDraftIncrementsLockVersion(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'A'], 1, 0, 'user00000001');
        self::assertSame(1, $this->repo()->findDraft($entry, 'en')['lock_version']);
    }

    public function testStaleSaveThrows409(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'A'], 1, 0, 'user00000001'); // now lock_version=1
        $this->expectException(OptimisticLockException::class);
        $this->repo()->saveDraft($entry, 'en', ['title' => 'B'], 1, 0, 'user00000001'); // stale 0
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Content/EntryRepositoryTest.php`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement**

`app/Content/Support/OptimisticLockException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Support;

final class OptimisticLockException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('draft was modified by another writer');
    }
}
```

`app/Content/Repositories/EntryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Support\OptimisticLockException;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class EntryRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Create entry identity + an empty draft for the locale. Returns entry uuid. */
    public function createEntry(string $contentTypeUuid, string $locale, int $schemaVersion, ?string $actor): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => $contentTypeUuid,
            'status' => 'active',
            'created_by' => $actor,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        $this->db->table('entry_drafts')->insert([
            'entry_uuid' => $uuid,
            'locale' => $locale,
            'fields' => json_encode([], JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'lock_version' => 0,
            'updated_by' => $actor,
            'updated_at' => $this->now(),
        ]);
        return $uuid;
    }

    /**
     * Save the draft working copy under optimistic concurrency. The caller passes the
     * lock_version it last read; if the row has moved on, throw (controller -> 409).
     *
     * @param array<string,mixed> $fields already-validated, cleaned payload
     */
    public function saveDraft(
        string $entryUuid,
        string $locale,
        array $fields,
        int $schemaVersion,
        int $expectedLockVersion,
        ?string $actor,
    ): void {
        $affected = $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->where('lock_version', '=', $expectedLockVersion)
            ->update([
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'schema_version' => $schemaVersion,
                'lock_version' => $expectedLockVersion + 1,
                'updated_by' => $actor,
                'updated_at' => $this->now(),
            ]);
        if ($affected < 1) {
            throw new OptimisticLockException();
        }
    }

    /** @return array<string,mixed>|null */
    public function findEntry(string $uuid): ?array
    {
        return $this->db->table('entries')->where('uuid', '=', $uuid)->first() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findDraft(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($row === null) {
            return null;
        }
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['lock_version'] = (int) $row['lock_version'];
        $row['schema_version'] = (int) $row['schema_version'];
        return $row;
    }

    public function softDelete(string $uuid): void
    {
        $this->db->table('entries')->where('uuid', '=', $uuid)
            ->update(['status' => 'deleted', 'updated_at' => $this->now()]);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Content/EntryRepositoryTest.php`
Expected: PASS — including the 409 path (stale `lock_version` matches 0 rows → throw).

- [ ] **Step 5: Commit**

```bash
git add app/Content/Repositories/EntryRepository.php app/Content/Support tests/Integration/Content/EntryRepositoryTest.php
git commit -m "Add EntryRepository with optimistic-locked drafts"
```

---

### Task 8: `VersionRepository` + `PublishService` (the §2 publish transaction)

**Files:**
- Create: `app/Content/Repositories/VersionRepository.php`, `app/Content/Services/PublishService.php`
- Test: `tests/Integration/Content/PublishServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;

final class PublishServiceTest extends LemmaTestCase
{
    private string $type;
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new EntryRepository($this->connection());
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
    }

    private function service(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            new EntryRepository($this->connection()),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new \App\Content\Validation\FieldValidator(),
        );
    }

    public function testPublishWritesVersion1AndPins(): void
    {
        $versionUuid = $this->service()->publish($this->entry, 'en', 'user00000001');
        $pub = (new VersionRepository($this->connection()))->findPublication($this->entry, 'en');
        self::assertSame($versionUuid, $pub['version_uuid']);
        $v = (new VersionRepository($this->connection()))->findVersionByUuid($versionUuid);
        self::assertSame(1, $v['version']);
        self::assertSame('V1', $v['fields']['title']);
    }

    public function testSecondPublishAppendsVersion2(): void
    {
        $this->service()->publish($this->entry, 'en', 'user00000001');
        (new EntryRepository($this->connection()))
            ->saveDraft($this->entry, 'en', ['title' => 'V2'], 1, 1, 'user00000001');
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $repo = new VersionRepository($this->connection());
        self::assertSame(2, $repo->findVersionByUuid($repo->findPublication($this->entry, 'en')['version_uuid'])['version']);
        self::assertCount(2, $repo->versionsFor($this->entry, 'en'));
    }

    public function testPublishRejectsInvalidDraft(): void
    {
        (new EntryRepository($this->connection()))
            ->saveDraft($this->entry, 'en', [], 1, 0, 'user00000001'); // wait: lock now 1 after setUp save
        // re-save to clear required title via empty payload at correct lock
        $this->expectException(ValidationException::class);
        $this->service()->publish($this->entry, 'en', 'user00000001');
    }

    public function testUnpublishRemovesPin(): void
    {
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->service()->unpublish($this->entry, 'en');
        self::assertNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }

    public function testRollbackRepinsOlderVersion(): void
    {
        $v1 = $this->service()->publish($this->entry, 'en', 'user00000001');
        (new EntryRepository($this->connection()))
            ->saveDraft($this->entry, 'en', ['title' => 'V2'], 1, 1, 'user00000001');
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->service()->rollback($this->entry, 'en', $v1, 'user00000001');
        self::assertSame($v1, (new VersionRepository($this->connection()))
            ->findPublication($this->entry, 'en')['version_uuid']);
    }
}
```

> Note: `testPublishRejectsInvalidDraft` as written has a lock-version sequencing subtlety (setUp already saved once → lock is 1). When implementing, fix the test to save the empty payload at the current lock version (read it via `findDraft`), or split into its own entry. Keep the *assertion* (invalid draft → `ValidationException`, no version written).

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Content/PublishServiceTest.php`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement `VersionRepository`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class VersionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function nextVersionNumber(string $entryUuid, string $locale): int
    {
        $max = $this->db->table('entry_versions')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
            ->max('version');
        return (int) ($max ?? 0) + 1;
    }

    /** @param array<string,mixed> $fields  Returns the new version uuid. */
    public function appendVersion(
        string $entryUuid,
        string $locale,
        int $version,
        array $fields,
        int $schemaVersion,
        ?string $actor,
    ): string {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entry_versions')->insert([
            'uuid' => $uuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version' => $version,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'created_by' => $actor,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    public function pin(string $entryUuid, string $locale, string $versionUuid, ?string $actor): void
    {
        $existing = $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        $data = ['version_uuid' => $versionUuid, 'published_by' => $actor, 'published_at' => date('Y-m-d H:i:s')];
        if ($existing === null) {
            $this->db->table('entry_publications')->insert(array_merge(
                ['entry_uuid' => $entryUuid, 'locale' => $locale],
                $data
            ));
        } else {
            $this->db->table('entry_publications')
                ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->update($data);
        }
    }

    public function unpin(string $entryUuid, string $locale): void
    {
        $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->delete();
    }

    /** @return array<string,mixed>|null */
    public function findPublication(string $entryUuid, string $locale): ?array
    {
        return $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findVersionByUuid(string $uuid): ?array
    {
        $row = $this->db->table('entry_versions')->where('uuid', '=', $uuid)->first();
        if ($row === null) {
            return null;
        }
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['version'] = (int) $row['version'];
        return $row;
    }

    /** @return list<array<string,mixed>> newest first */
    public function versionsFor(string $entryUuid, string $locale): array
    {
        return $this->db->table('entry_versions')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
            ->orderBy('version', 'DESC')->get();
    }
}
```

- [ ] **Step 4: Implement `PublishService`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Services;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;

final class PublishService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly VersionRepository $versions,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
    ) {
    }

    /**
     * Validate the current draft, snapshot it as the next immutable version, and pin it —
     * all in one transaction (V1_DESIGN §2/§5). Returns the new version uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor): string
    {
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null) {
            throw new \RuntimeException("entry {$entryUuid} not found");
        }
        $draft = $this->entries->findDraft($entryUuid, $locale);
        if ($draft === null) {
            throw new \RuntimeException("no draft for {$entryUuid}/{$locale}");
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        // Throws ValidationException before any write if the draft is invalid.
        $clean = $this->validator->validate($schema, $draft['fields']);

        return db($this->context)->transaction(function () use ($entryUuid, $locale, $clean, $draft, $actor): string {
            $version = $this->versions->nextVersionNumber($entryUuid, $locale);
            $versionUuid = $this->versions->appendVersion(
                $entryUuid,
                $locale,
                $version,
                $clean,
                (int) $draft['schema_version'],
                $actor,
            );
            $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
            return $versionUuid;
        });
        // Pipeline side effects (events, webhooks, cache, CDN/search) attach in the delivery plan
        // via db()->afterCommit(); foundation deliberately writes no side effects.
    }

    public function unpublish(string $entryUuid, string $locale): void
    {
        db($this->context)->transaction(function () use ($entryUuid, $locale): void {
            $this->versions->unpin($entryUuid, $locale);
        });
    }

    /** Re-pin an existing (older) version without writing a new one. */
    public function rollback(string $entryUuid, string $locale, string $versionUuid, ?string $actor): void
    {
        $version = $this->versions->findVersionByUuid($versionUuid);
        if ($version === null || (string) $version['entry_uuid'] !== $entryUuid || (string) $version['locale'] !== $locale) {
            throw new \RuntimeException('version does not belong to this entry/locale');
        }
        db($this->context)->transaction(function () use ($entryUuid, $locale, $versionUuid, $actor): void {
            $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
        });
    }
}
```

- [ ] **Step 5: Run to verify it passes** (fix the noted sequencing in `testPublishRejectsInvalidDraft` first)

Run: `vendor/bin/phpunit tests/Integration/Content/PublishServiceTest.php`
Expected: PASS — version monotonicity, pin upsert, validation-before-write, unpublish, rollback.

- [ ] **Step 6: Commit**

```bash
git add app/Content/Repositories/VersionRepository.php app/Content/Services/PublishService.php tests/Integration/Content/PublishServiceTest.php
git commit -m "Add VersionRepository + PublishService (transactional publish/unpublish/rollback)"
```

---

### Task 9: `RouteRepository` (slug uniqueness per type+locale)

**Files:**
- Create: `app/Content/Repositories/RouteRepository.php`
- Test: `tests/Integration/Content/RouteRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\RouteRepository;
use App\Tests\Support\LemmaTestCase;

final class RouteRepositoryTest extends LemmaTestCase
{
    private function repo(): RouteRepository
    {
        return new RouteRepository($this->connection());
    }

    public function testAssignThenLookup(): void
    {
        $this->repo()->assign('entry0000001', 'type0000001', 'en', 'hello');
        $row = $this->repo()->findBySlug('type0000001', 'en', 'hello');
        self::assertSame('entry0000001', $row['entry_uuid']);
    }

    public function testDuplicateSlugInSameTypeLocaleRejected(): void
    {
        $this->repo()->assign('entry0000001', 'type0000001', 'en', 'hello');
        self::assertFalse($this->repo()->isSlugAvailable('type0000001', 'en', 'hello'));
        self::assertTrue($this->repo()->isSlugAvailable('type0000001', 'fr', 'hello'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Content/RouteRepositoryTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use Glueful\Database\Connection;

final class RouteRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Upsert the route for an entry+locale (one slug per entry+locale). */
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
            $this->db->table('entry_routes')
                ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
                ->update(['slug' => $slug, 'content_type_uuid' => $contentTypeUuid]);
        }
    }

    public function isSlugAvailable(string $contentTypeUuid, string $locale, string $slug): bool
    {
        return $this->db->table('entry_routes')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('slug', '=', $slug)
            ->first() === null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $contentTypeUuid, string $locale, string $slug): ?array
    {
        return $this->db->table('entry_routes')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('slug', '=', $slug)
            ->first() ?: null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Content/RouteRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Content/Repositories/RouteRepository.php tests/Integration/Content/RouteRepositoryTest.php
git commit -m "Add RouteRepository"
```

---

### Task 10: Permissions — seed roles/permissions + `lemma_permission` middleware

**Files:**
- Create: `database/migrations/008_SeedLemmaRolesAndPermissions.php`, `app/Content/Http/RequireLemmaPermission.php`
- Test: `tests/Unit/Http/RequireLemmaPermissionTest.php`

- [ ] **Step 1: Write the failing middleware test (fail-closed posture)**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Content\Http\RequireLemmaPermission;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequireLemmaPermissionTest extends TestCase
{
    public function testNoAuthUserIsForbidden(): void
    {
        $mw = new RequireLemmaPermission($this->contextWithoutPermissionManager());
        $resp = $mw->handle(new Request(), fn() => new Response(), 'lemma.entries.write');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testEmptyPermissionParamIsForbidden(): void
    {
        $mw = new RequireLemmaPermission($this->contextWithoutPermissionManager());
        $resp = $mw->handle(new Request(), fn() => new Response(), '');
        self::assertSame(403, $resp->getStatusCode());
    }

    private function contextWithoutPermissionManager(): \Glueful\Bootstrap\ApplicationContext
    {
        // Minimal double: a context whose container has no PermissionManager -> fail closed.
        // Use the test harness's app context in integration; here a stub container suffices.
        return \App\Tests\Support\StubContext::withoutPermissionManager();
    }
}
```

> If a `StubContext` double is awkward, promote this to an integration test extending `LemmaTestCase` and assert the three fail-closed branches (no `auth.user`, empty param, unresolved `PermissionManager`). The behavioral contract is what matters; mirror `RequireFlagsPermission` exactly.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Http/RequireLemmaPermissionTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement the middleware (mirror `RequireFlagsPermission`)**

```php
<?php

declare(strict_types=1);

namespace App\Content\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

final class RequireLemmaPermission implements RouteMiddleware
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $permission = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';
        if ($permission === '') {
            return $this->forbidden();
        }
        $user = $request->attributes->get('auth.user');
        if (!$user instanceof UserIdentity) {
            return $this->forbidden();
        }
        $manager = $this->permissionManager();
        if (!$manager instanceof PermissionManager) {
            return $this->forbidden();
        }
        $context = [
            'roles' => $user->roles(),
            'scopes' => $user->scopes(),
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];
        if (!$manager->can($user->id(), $permission, 'lemma', $context)) {
            return $this->forbidden();
        }
        return $next($request);
    }

    private function permissionManager(): ?PermissionManager
    {
        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id) && ($m = $container->get($id)) instanceof PermissionManager) {
                    return $m;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    private function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }
}
```

- [ ] **Step 4: Implement the seed migration (idempotent direct inserts into aegis tables)**

Modeled exactly on aegis's own `003_SeedDefaultRoles` (verified): a migration may instantiate `new Connection()` in `up()` and write data; idempotency is batch-check-by-slug then `insertBatch` only the missing rows. The aegis table columns used below are taken from that seeder — `roles(uuid,name,slug,description,level,is_system,status)`, `permissions(uuid,name,slug,category,description,is_system)`, `role_permissions(uuid,role_uuid,permission_uuid)`. Permission slugs and role slugs are the frozen v1 contract (V1_DESIGN §7); do not encode content-type into permission names — per-type restriction later uses Aegis resource arguments on the same names.

```php
<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

final class SeedLemmaRolesAndPermissions implements MigrationInterface
{
    /** permission slug => human label */
    private const PERMISSIONS = [
        'lemma.models.manage' => 'Manage content models',
        'lemma.entries.write' => 'Create and edit entries',
        'lemma.entries.publish' => 'Publish and unpublish entries',
        'lemma.entries.read' => 'Read entries (admin)',
    ];

    /** role slug => [name, level, granted permission slugs] */
    private const ROLES = [
        'lemma_admin' => ['Lemma Admin', 80, [
            'lemma.models.manage', 'lemma.entries.write', 'lemma.entries.publish', 'lemma.entries.read',
        ]],
        'lemma_editor' => ['Lemma Editor', 50, [
            'lemma.entries.write', 'lemma.entries.publish', 'lemma.entries.read',
        ]],
        'lemma_viewer' => ['Lemma Viewer', 20, ['lemma.entries.read']],
    ];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        $roleUuids = $this->ensureRows(
            'roles',
            array_map(static fn(string $slug, array $r): array => [
                'slug' => $slug, 'name' => $r[0], 'description' => $r[0],
                'level' => $r[1], 'is_system' => true, 'status' => 'active',
            ], array_keys(self::ROLES), self::ROLES)
        );

        $permUuids = $this->ensureRows(
            'permissions',
            array_map(static fn(string $slug, string $label): array => [
                'slug' => $slug, 'name' => $label, 'category' => 'lemma',
                'description' => $label, 'is_system' => true,
            ], array_keys(self::PERMISSIONS), self::PERMISSIONS)
        );

        // role_permissions assignments (idempotent on the (role_uuid, permission_uuid) pair).
        $existing = [];
        foreach ($this->db->table('role_permissions')->select(['role_uuid', 'permission_uuid'])->get() as $row) {
            $existing[$row['role_uuid'] . '|' . $row['permission_uuid']] = true;
        }
        $newAssignments = [];
        foreach (self::ROLES as $roleSlug => [, , $grants]) {
            foreach ($grants as $permSlug) {
                $pair = $roleUuids[$roleSlug] . '|' . $permUuids[$permSlug];
                if (!isset($existing[$pair])) {
                    $newAssignments[] = [
                        'uuid' => Utils::generateNanoID(),
                        'role_uuid' => $roleUuids[$roleSlug],
                        'permission_uuid' => $permUuids[$permSlug],
                    ];
                }
            }
        }
        if ($newAssignments !== []) {
            $this->db->table('role_permissions')->insertBatch($newAssignments);
        }
    }

    /**
     * Insert rows that don't already exist (matched by slug); return slug => uuid for all.
     *
     * @param list<array<string,mixed>> $rows each carries a 'slug'
     * @return array<string,string>
     */
    private function ensureRows(string $table, array $rows): array
    {
        $slugs = array_column($rows, 'slug');
        $bySlug = [];
        foreach ($this->db->table($table)->select(['uuid', 'slug'])->whereIn('slug', $slugs)->get() as $r) {
            $bySlug[$r['slug']] = $r['uuid'];
        }
        $insert = [];
        foreach ($rows as $row) {
            if (!isset($bySlug[$row['slug']])) {
                $row['uuid'] = Utils::generateNanoID();
                $bySlug[$row['slug']] = $row['uuid'];
                $insert[] = $row;
            }
        }
        if ($insert !== []) {
            $this->db->table($table)->insertBatch($insert);
        }
        return $bySlug;
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permUuids = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid'
        );
        if ($permUuids !== []) {
            $this->db->table('role_permissions')->whereIn('permission_uuid', $permUuids)->delete();
        }
        $this->db->table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();
        $this->db->table('roles')->whereIn('slug', array_keys(self::ROLES))->delete();
    }

    public function getDescription(): string
    {
        return 'Seed Lemma roles (admin/editor/viewer) and namespaced permissions into aegis.';
    }
}
```

> Idempotent by construction: re-running matches existing rows by slug/pair and inserts nothing. Column set matches aegis `003_SeedDefaultRoles`; if a future aegis schema adds a required column, mirror its seeder. The role slugs match `config/lemma.php`'s `roles` map. (If you prefer the higher-level path, `AegisPermissionProvider::syncCatalog($permissions, $roles)` exists and is idempotent — but it needs the provider resolved from the container, which a migration does not have; the direct inserts above keep this self-contained.)

- [ ] **Step 5: Run middleware test + seed migration**

Run: `php glueful migrate:run && vendor/bin/phpunit tests/Unit/Http/RequireLemmaPermissionTest.php`
Expected: PASS; `lemma_*` roles + `lemma.*` permissions present in aegis tables; re-running `migrate:run`-equivalent seed is a no-op.

- [ ] **Step 6: Commit**

```bash
git add app/Content/Http/RequireLemmaPermission.php database/migrations/008_SeedLemmaRolesAndPermissions.php tests/Unit/Http/RequireLemmaPermissionTest.php
git commit -m "Add Lemma permission middleware + role/permission seed"
```

---

### Task 11: Admin API — content-type CRUD

**Files:**
- Create: `app/Content/Http/Controllers/ContentTypeController.php`, `routes/lemma_admin.php`
- Test: `tests/Integration/Http/ContentTypeApiTest.php`

- [ ] **Step 1: Write the failing API test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeApiTest extends LemmaTestCase
{
    private function controller(): ContentTypeController
    {
        return new ContentTypeController(new ContentTypeRepository($this->connection()));
    }

    private function json(array $body): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    public function testStoreCreatesType(): void
    {
        $resp = $this->controller()->store($this->json([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]));
        self::assertSame(201, $resp->getStatusCode());
        self::assertNotNull((new ContentTypeRepository($this->connection()))->findBySlug('post'));
    }

    public function testStoreRejectsBadSchema(): void
    {
        $resp = $this->controller()->store($this->json([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'price', 'type' => 'number', 'filterable' => true]], // missing filter_type
        ]));
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testShowNotFound(): void
    {
        self::assertSame(404, $this->controller()->show(new Request(), 'nope')->getStatusCode());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/ContentTypeApiTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\SchemaParseException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeController
{
    public function __construct(private readonly ContentTypeRepository $types)
    {
    }

    public function index(Request $request): Response
    {
        return Response::success(['content_types' => $this->types->all()], 'Content types retrieved.');
    }

    public function store(Request $request): Response
    {
        $in = $this->body($request);
        $slug = (string) ($in['slug'] ?? '');
        $name = (string) ($in['name'] ?? '');
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,159}\z/', $slug) !== 1 || trim($name) === '') {
            return Response::validation(['slug' => 'lowercase slug required', 'name' => 'name required']);
        }
        if ($this->types->findBySlug($slug) !== null) {
            return Response::validation(['slug' => "content type '{$slug}' already exists"]);
        }
        try {
            $uuid = $this->types->create([
                'slug' => $slug,
                'name' => trim($name),
                'description' => $in['description'] ?? null,
                'schema' => (array) ($in['schema'] ?? []),
                'created_by' => $this->actor($request),
            ]);
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::created(['content_type' => $this->types->findByUuid($uuid)], 'Content type created.');
    }

    public function show(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        return $row === null
            ? Response::notFound('Content type not found.')
            : Response::success(['content_type' => $row], 'Content type retrieved.');
    }

    public function updateSchema(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Content type not found.');
        }
        try {
            $this->types->updateSchema($row['uuid'], (array) ($this->body($request)['schema'] ?? []));
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::success(['content_type' => $this->types->findByUuid($row['uuid'])], 'Schema updated.');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
```

- [ ] **Step 4: Add the route file**

`routes/lemma_admin.php`:

```php
<?php

declare(strict_types=1);

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\PublicationController;
use Glueful\Routing\Router;

/** @var Router $router */

$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    /**
     * @route GET /v1/admin/content-types
     * @summary List content types
     * @tag Lemma Admin
     */
    $router->get('/content-types', [ContentTypeController::class, 'index'])
        ->middleware('lemma_permission:lemma.entries.read');

    /**
     * @route POST /v1/admin/content-types
     * @summary Create content type
     * @tag Lemma Admin
     * @requestBody slug:string name:string schema:array {required=slug,name}
     */
    $router->post('/content-types', [ContentTypeController::class, 'store'])
        ->middleware('lemma_permission:lemma.models.manage');

    $router->get('/content-types/{slug}', [ContentTypeController::class, 'show'])
        ->middleware('lemma_permission:lemma.entries.read');
    $router->patch('/content-types/{slug}/schema', [ContentTypeController::class, 'updateSchema'])
        ->middleware('lemma_permission:lemma.models.manage');

    // Entry + publication routes added in Tasks 12–13.
});
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Http/ContentTypeApiTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Content/Http/Controllers/ContentTypeController.php routes/lemma_admin.php tests/Integration/Http/ContentTypeApiTest.php
git commit -m "Add content-type admin API"
```

---

### Task 12: Admin API — entries + drafts

**Files:**
- Create: `app/Content/Http/Controllers/EntryController.php`
- Modify: `routes/lemma_admin.php` (add entry routes)
- Test: `tests/Integration/Http/EntryApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\EntryController;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EntryApiTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function controller(): EntryController
    {
        return new EntryController(
            new EntryRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        );
    }

    private function json(array $b): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($b));
    }

    public function testCreateEntryReturnsEntryWithEmptyDraft(): void
    {
        $resp = $this->controller()->store($this->json(['content_type' => 'post', 'locale' => 'en']));
        self::assertSame(201, $resp->getStatusCode());
    }

    public function testSaveDraftRejectsStaleLockWith409(): void
    {
        $created = json_decode($this->controller()->store($this->json(['content_type' => 'post', 'locale' => 'en']))->getContent(), true);
        $uuid = $created['data']['entry']['uuid'];
        $this->controller()->saveDraft($this->json(['fields' => ['title' => 'A'], 'lock_version' => 0]), $uuid, 'en');
        $resp = $this->controller()->saveDraft($this->json(['fields' => ['title' => 'B'], 'lock_version' => 0]), $uuid, 'en');
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSaveDraftValidatesFields(): void
    {
        $created = json_decode($this->controller()->store($this->json(['content_type' => 'post', 'locale' => 'en']))->getContent(), true);
        $uuid = $created['data']['entry']['uuid'];
        $resp = $this->controller()->saveDraft($this->json(['fields' => ['title' => 123], 'lock_version' => 0]), $uuid, 'en');
        self::assertSame(422, $resp->getStatusCode());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/EntryApiTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class EntryController
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
    ) {
    }

    public function store(Request $request): Response
    {
        $in = $this->body($request);
        $type = $this->types->findBySlug((string) ($in['content_type'] ?? ''));
        if ($type === null) {
            return Response::validation(['content_type' => 'unknown content type']);
        }
        $locale = (string) ($in['locale'] ?? config($this->ctx($request), 'lemma.default_locale', 'en'));
        $uuid = $this->entries->createEntry($type['uuid'], $locale, (int) $type['schema_version'], $this->actor($request));
        return Response::created([
            'entry' => $this->entries->findEntry($uuid),
            'draft' => $this->entries->findDraft($uuid, $locale),
        ], 'Entry created.');
    }

    public function show(Request $request, string $uuid): Response
    {
        $entry = $this->entries->findEntry($uuid);
        return $entry === null
            ? Response::notFound('Entry not found.')
            : Response::success(['entry' => $entry], 'Entry retrieved.');
    }

    public function getDraft(Request $request, string $uuid, string $locale): Response
    {
        $draft = $this->entries->findDraft($uuid, $locale);
        return $draft === null
            ? Response::notFound('Draft not found.')
            : Response::success(['draft' => $draft], 'Draft retrieved.');
    }

    public function saveDraft(Request $request, string $uuid, string $locale): Response
    {
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        $in = $this->body($request);
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        try {
            $clean = $this->validator->validate($schema, (array) ($in['fields'] ?? []));
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        }
        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        try {
            $this->entries->saveDraft(
                $uuid,
                $locale,
                $clean,
                (int) $type['schema_version'],
                (int) ($in['lock_version'] ?? -1),
                $this->actor($request),
            );
        } catch (OptimisticLockException) {
            return Response::error('Draft was modified by another writer.', Response::HTTP_CONFLICT, [
                'code' => 'STALE_DRAFT',
                'current' => $this->entries->findDraft($uuid, $locale),
            ]);
        }
        return Response::success(['draft' => $this->entries->findDraft($uuid, $locale)], 'Draft saved.');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }

    private function ctx(Request $request): \Glueful\Bootstrap\ApplicationContext
    {
        /** @var \Glueful\Bootstrap\ApplicationContext $c */
        $c = $request->attributes->get('app.context');
        return $c;
    }
}
```

> `config($context, ...)` needs an `ApplicationContext`. If the framework does not place it on `app.context` request attributes, inject `ApplicationContext` into the controller constructor instead (the skeleton's `BaseController` already holds `protected ApplicationContext $context`; extend it or inject directly). Confirm the available accessor when wiring Task 14 and use it consistently.

- [ ] **Step 4: Add entry routes** to `routes/lemma_admin.php` inside the group:

```php
    $router->post('/entries', [EntryController::class, 'store'])
        ->middleware('lemma_permission:lemma.entries.write');
    $router->get('/entries/{uuid}', [EntryController::class, 'show'])
        ->middleware('lemma_permission:lemma.entries.read');
    $router->get('/entries/{uuid}/draft/{locale}', [EntryController::class, 'getDraft'])
        ->middleware('lemma_permission:lemma.entries.read');
    $router->put('/entries/{uuid}/draft/{locale}', [EntryController::class, 'saveDraft'])
        ->middleware('lemma_permission:lemma.entries.write');
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Http/EntryApiTest.php`
Expected: PASS — 201 create, 409 stale draft, 422 invalid fields.

- [ ] **Step 6: Commit**

```bash
git add app/Content/Http/Controllers/EntryController.php routes/lemma_admin.php tests/Integration/Http/EntryApiTest.php
git commit -m "Add entry + draft admin API"
```

---

### Task 13: Admin API — publish / unpublish / rollback

**Files:**
- Create: `app/Content/Http/Controllers/PublicationController.php`
- Modify: `routes/lemma_admin.php`
- Test: `tests/Integration/Http/PublicationApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\PublicationController;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicationApiTest extends LemmaTestCase
{
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new EntryRepository($this->connection());
        $this->entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'Hello'], 1, 0, 'user00000001');
    }

    private function controller(): PublicationController
    {
        return new PublicationController(new PublishService(
            $this->appContext(),
            new EntryRepository($this->connection()),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        ));
    }

    public function testPublishReturns200AndPins(): void
    {
        $resp = $this->controller()->publish(new Request(), $this->entry, 'en');
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }

    public function testUnpublishRemovesPin(): void
    {
        $this->controller()->publish(new Request(), $this->entry, 'en');
        $this->controller()->unpublish(new Request(), $this->entry, 'en');
        self::assertNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/PublicationApiTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Services\PublishService;
use App\Content\Validation\ValidationException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class PublicationController
{
    public function __construct(private readonly PublishService $publisher)
    {
    }

    public function publish(Request $request, string $uuid, string $locale): Response
    {
        try {
            $versionUuid = $this->publisher->publish($uuid, $locale, $this->actor($request));
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }
        return Response::success(['version_uuid' => $versionUuid], 'Entry published.');
    }

    public function unpublish(Request $request, string $uuid, string $locale): Response
    {
        $this->publisher->unpublish($uuid, $locale);
        return Response::success([], 'Entry unpublished.');
    }

    public function rollback(Request $request, string $uuid, string $locale): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $versionUuid = is_array($body) ? (string) ($body['version_uuid'] ?? '') : '';
        if ($versionUuid === '') {
            return Response::validation(['version_uuid' => 'required']);
        }
        try {
            $this->publisher->rollback($uuid, $locale, $versionUuid, $this->actor($request));
        } catch (\RuntimeException $e) {
            return Response::validation(['version_uuid' => $e->getMessage()]);
        }
        return Response::success(['version_uuid' => $versionUuid], 'Rolled back to version.');
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
```

- [ ] **Step 4: Add publication routes** to `routes/lemma_admin.php` inside the group:

```php
    $router->post('/entries/{uuid}/publish/{locale}', [PublicationController::class, 'publish'])
        ->middleware('lemma_permission:lemma.entries.publish');
    $router->post('/entries/{uuid}/unpublish/{locale}', [PublicationController::class, 'unpublish'])
        ->middleware('lemma_permission:lemma.entries.publish');
    $router->post('/entries/{uuid}/rollback/{locale}', [PublicationController::class, 'rollback'])
        ->middleware('lemma_permission:lemma.entries.publish');
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Http/PublicationApiTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Content/Http/Controllers/PublicationController.php routes/lemma_admin.php tests/Integration/Http/PublicationApiTest.php
git commit -m "Add publish/unpublish/rollback admin API"
```

---

### Task 14: Wire everything through `LemmaServiceProvider` + end-to-end flow test

**Files:**
- Create: `app/Providers/LemmaServiceProvider.php`
- Modify: `config/serviceproviders.php` (register the provider)
- Test: `tests/Integration/FoundationFlowTest.php`

- [ ] **Step 1: Write the failing end-to-end test (HTTP-level, through the kernel)**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the admin API through the booted application kernel with an authenticated
 * admin user, exercising the full create-type -> create-entry -> save-draft ->
 * publish -> read-version path. Authentication is established the same way the
 * users/aegis integration tests do (seed an admin user + lemma_admin role, mint a
 * bearer token, set Authorization). See LemmaTestCase::actingAsAdmin().
 */
final class FoundationFlowTest extends LemmaTestCase
{
    public function testCreateTypeEntryPublishReadBack(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/v1/admin/content-types', [
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ])->assertStatus(201);

        $entry = $this->postJson('/v1/admin/entries', ['content_type' => 'post', 'locale' => 'en'])
            ->assertStatus(201)->json('data.entry.uuid');

        $this->putJson("/v1/admin/entries/{$entry}/draft/en", ['fields' => ['title' => 'Hello'], 'lock_version' => 0])
            ->assertStatus(200);

        $version = $this->postJson("/v1/admin/entries/{$entry}/publish/en", [])
            ->assertStatus(200)->json('data.version_uuid');

        self::assertNotEmpty($version);
    }
}
```

> The `actingAsAdmin()`, `postJson()`, `putJson()`, and `assertStatus()/json()` helpers belong on `LemmaTestCase` and wrap the framework kernel (`$app->handle($request)`) plus a response assertion shim. Implement them in this task against the real kernel — do not invent helpers the harness lacks. If a full kernel round-trip is heavy, the controller-level tests in Tasks 11–13 already cover behavior; this test's job is to prove routing + middleware + auth wiring is correct, so keep it kernel-level.

- [ ] **Step 2: Implement `LemmaServiceProvider`**

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\RequireLemmaPermission;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;

final class LemmaServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            ContentTypeRepository::class => ['class' => ContentTypeRepository::class, 'shared' => true, 'autowire' => true],
            EntryRepository::class => ['class' => EntryRepository::class, 'shared' => true, 'autowire' => true],
            VersionRepository::class => ['class' => VersionRepository::class, 'shared' => true, 'autowire' => true],
            RouteRepository::class => ['class' => RouteRepository::class, 'shared' => true, 'autowire' => true],
            FieldValidator::class => ['class' => FieldValidator::class, 'shared' => true, 'autowire' => true],
            PublishService::class => ['class' => PublishService::class, 'shared' => true, 'autowire' => true],
            ContentTypeController::class => ['class' => ContentTypeController::class, 'shared' => true, 'autowire' => true],
            EntryController::class => ['class' => EntryController::class, 'shared' => true, 'autowire' => true],
            PublicationController::class => ['class' => PublicationController::class, 'shared' => true, 'autowire' => true],
            RequireLemmaPermission::class => [
                'class' => RequireLemmaPermission::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['lemma_permission'],
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('lemma', require dirname(__DIR__, 2) . '/config/lemma.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $this->loadRoutesFrom(dirname(__DIR__, 2) . '/routes/lemma_admin.php');
    }
}
```

> Verify `ServiceProvider` base + `services()`/`register()`/`boot()`/`loadRoutesFrom()`/`mergeConfig()` against an app provider in the skeleton (the skeleton's `AppServiceProvider` uses `services()`). If the skeleton's app providers extend a different base or use `defs()` (framework 1.55+), match that. The `autowire` repositories resolve `Connection`; `PublishService` resolves `ApplicationContext` + repos + validator — all container-known.

- [ ] **Step 3: Register the provider** in `config/serviceproviders.php`:

```php
return [
    'enabled' => [
        'App\\Providers\\AppServiceProvider',
        'App\\Providers\\LemmaServiceProvider',
    ],
];
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS — unit + integration green against PostgreSQL, including the end-to-end flow.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/LemmaServiceProvider.php config/serviceproviders.php tests/Integration/FoundationFlowTest.php tests/Support/LemmaTestCase.php
git commit -m "Wire Lemma foundation through LemmaServiceProvider; end-to-end flow test"
```

---

## Self-review

**Spec coverage (V1_DESIGN §1–§3, §7, §11 steps 2–3):**
- §1 JSONB field storage + 7 tables → Tasks 2, 4, 6 (json()→JSONB verified). ✓
- §1 schema_version bump on model change → Task 6 `updateSchema`. ✓
- §1 filter_type required on filterable fields → Task 4 (parsing); the *expression indexes* are explicitly deferred to the delivery plan (foundation has no read API). ✓ (gap is intentional and labeled)
- §2 draft (optimistic lock, 409) / version (append-at-publish, monotonic) / publication (pin upsert) / unpublish / rollback → Tasks 7, 8, 12, 13. ✓
- §2 "version history" naming, no status column on read path → enforced structurally (no status filter anywhere; delivery plan reads only publications⋈versions). ✓
- §3 locale column on drafts/versions/publications/routes; single default locale from config → Tasks 2, 12 (`lemma.default_locale`). ✓
- §7 three coarse roles + namespaced permissions, no type-encoded names → Task 10. ✓
- §11 steps 2–3 build order → Tasks 1 (scaffold/Postgres), 2–9 (data layer), 10–14 (admin API). ✓
- Out of scope and labeled: delivery API, expression indexes, pipeline side effects, preview, SPA, export, reference projection. ✓

**Placeholder scan:** No placeholders remain. The Task 10 seed is now a complete idempotent implementation against the verified aegis table columns (the earlier throwing stub is gone). Two soft "verify-when-wiring" notes remain and are *not* placeholders — they are real integration points with the contract stated and a working default given: Task 12's `ApplicationContext` accessor in `EntryController` (default: inject `ApplicationContext` via the constructor, which `LemmaServiceProvider` autowires) and Task 14's app `ServiceProvider` base/`defs()` shape (default: match the skeleton's `AppServiceProvider`). The previously-wrong harness APIs (`MigrationManager::runPending`, `QueryBuilder::truncate`) are corrected to `migrate()` (run via `composer test:migrate`) and `->where('id','>',0)->delete()`. No "TBD/handle edge cases/similar to Task N" patterns.

**Type consistency:** Method names are consistent across tasks — `createEntry`, `saveDraft`, `findDraft`, `findEntry` (EntryRepository); `nextVersionNumber`, `appendVersion`, `pin`/`unpin`, `findPublication`, `findVersionByUuid`, `versionsFor` (VersionRepository); `publish`/`unpublish`/`rollback` (PublishService); `validate` (FieldValidator). Controllers consume exactly these. `created_by`/`updated_by`/`published_by` are nullable `string(12)` everywhere, matching the nanoid actor ids.

**Known sequencing fix to apply during execution:** `PublishServiceTest::testPublishRejectsInvalidDraft` (Task 8) is written with a lock-version that `setUp` has already advanced; the note in that task says to read the current `lock_version` before the empty-payload save (or use a dedicated entry). The assertion — invalid draft raises `ValidationException` and writes no version — is the contract to preserve.

---

## Open items deferred to later plans (not foundation)

- Delivery/read API (`/v1/content/...`), field selection/expansion, filterable-field expression indexes (`CREATE INDEX CONCURRENTLY`), cursor pagination, ETag/cache tags — V1_DESIGN §1, §6.
- Publish *pipeline* side effects via `afterCommit`: domain events, webhook taxonomy, cache-tag invalidation, CDN/search enqueues, `lemma:resync` — V1_DESIGN §5.
- `entry_references` write-time projection + delivery-time resolution — V1_DESIGN §4.
- Preview tokens — V1_DESIGN §6.
- Admin SPA, export/import bundle adapters — V1_DESIGN §9, APPROACH admin section.
