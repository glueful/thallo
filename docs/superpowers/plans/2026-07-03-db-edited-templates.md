# DB-Edited Templates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin-editable theme templates stored in the database, layered over the filesystem theme, statically policy-scanned (no runtime sandbox), with append-only version history and a template-manager admin screen.

**Architecture:** A pack-owned `RenderTemplateLoader` (DB first, filesystem second — NOT Twig's `ChainLoader`, whose persistent `$hasSourceCache` breaks DB-only templates) feeds the existing render Twig environment; freshness = per-render reset + `db:{theme}:{path}:{version_uuid}:policy:{N}` compile-cache keys (the policy version invalidates compiled templates when the allowlist tightens), so no compiled-cache purging exists. `TemplateLinter` walks the parsed AST against `TemplatePolicy` at save (422 with line numbers) and again in the loader before compile. Everything lives in `packages/lemma-render` (navigation-pack precedent: migrations, admin controller, permission seed, purge listener).

**Tech Stack:** PHP 8.4 / Glueful framework, Twig 3.27, SQLite test suite, Vue 3 + Nuxt UI admin SPA (openapi-fetch, CodeMirror 6).

**Spec:** `docs/superpowers/specs/2026-07-03-db-edited-templates-design.md` — read it first; every pin below traces to it.

## Global Constraints

- **Commit gate:** work is STAGED at two groupings (after Task 5 and after Task 7) and committed ONLY on explicit user authorization. No Claude/Anthropic attribution anywhere.
- **phpcs:** verify with `vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"` — never through a pipe.
- **Pack boundaries:** `packages/lemma-render` may depend on `Glueful\*` (framework) and `Glueful\Lemma\Contracts\*` only — never `App\*`. Run `composer boundaries` after backend tasks.
- **No new contracts** — DB templates are render-pack internal.
- **No runtime sandbox**: `SandboxExtension` is never registered. Enforcement is the static AST scan only.
- **Render context invariant:** arrays/scalars only, never objects (soundness of static-only enforcement).
- **Enforcement allowlists (spec §4, verbatim):**
  - Tags: `if`, `for`, `set`, `block`, `extends`, `include`, `verbatim`.
  - Filters: `abs`, `batch`, `capitalize`, `column`, `date`, `date_modify`, `default`, `escape`, `e`, `first`, `format`, `join`, `json_encode`, `keys`, `last`, `length`, `lower`, `merge`, `nl2br`, `number_format`, `replace`, `reverse`, `round`, `slice`, `sort`, `split`, `striptags`, `title`, `trim`, `upper`, `url_encode`. **No `raw`, no `filter`/`map`/`reduce`.**
  - Functions: `menu`, `path`, `asset`, `facets`, `include`, `parent`, `block`, `cycle`, `date`, `min`, `max`, `range`.
  - Tests: `defined`, `empty`, `even`, `iterable`, `null`, `odd`, `same as`, `divisible by`, `sequence`, `mapping`.
  - `include`/`extends` targets: **constant strings only**. Method-call syntax: denied. Unknown AST node classes: denied (default-deny).
- **Config kill-switch:** `lemma_render.db_templates` (env `RENDER_DB_TEMPLATES`, default `true`).
- **Permission:** `templates.manage` (matches `navigation.manage` style). Routes triple-gated: capability → `auth` → `lemma_permission:templates.manage`.
- Working directory for all commands: `/Users/michaeltawiahsowah/Sites/glueful/lemma` (backend) and `…/lemma/admin` (SPA).

## File Map

| File | Responsibility |
|---|---|
| `packages/lemma-render/migrations/001_CreateRenderTemplatesTable.php` | `lemma_render_templates` |
| `packages/lemma-render/migrations/002_CreateRenderTemplateVersionsTable.php` | `lemma_render_template_versions` |
| `packages/lemma-render/migrations/003_SeedTemplatesPermission.php` | `templates.manage` |
| `packages/lemma-render/src/Templates/TemplateRepository.php` | rows: map/find/save/deactivate/versions/restore-support |
| `packages/lemma-render/src/Templates/TemplatePolicy.php` | allowlists (data only) |
| `packages/lemma-render/src/Templates/TemplateLinter.php` | AST scan — THE enforcement engine |
| `packages/lemma-render/src/Templates/DatabaseTemplateLoader.php` | Twig loader over the repo (+ compile-time lint) |
| `packages/lemma-render/src/Templates/RenderTemplateLoader.php` | DB-first/FS-second composite, `resetForRender()` |
| `packages/lemma-render/src/Templates/TemplateUpdated.php` | in-pack event (save/delete/restore) |
| `packages/lemma-render/src/Templates/TemplateCatalog.php` | merged fs+db listing, fs source reads |
| `packages/lemma-render/src/Http/Controllers/TemplatesAdminController.php` | admin API |
| `packages/lemma-render/src/Listeners/PurgeRenderCacheOnTemplateUpdate.php` | active-theme purge |
| `packages/lemma-render/routes/admin-routes.php` | route grammar (version routes FIRST) |
| `packages/lemma-render/src/TwigFactory.php` (modify) | optional DB loader → composite |
| `packages/lemma-render/src/Http/Controllers/RenderController.php` (modify) | `resetForRender()` per render; themed sessions get DB loader |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` (modify) | services/factories/boot wiring |
| `packages/lemma-render/config/lemma-render.php` (modify) | `db_templates` |
| `scripts/run-test-migrations.php` (modify) | register pack migrations |
| `tests/Support/LemmaTestCase.php` (modify) | TABLES += the two new tables |
| `admin/src/registry/templatesModule.ts`, `admin/src/pages/templates/*`, `admin/src/api/rawPath.ts` | SPA |

---

### Task 1: Migrations + TemplateRepository

**Files:**
- Create: `packages/lemma-render/migrations/001_CreateRenderTemplatesTable.php`, `002_CreateRenderTemplateVersionsTable.php`, `003_SeedTemplatesPermission.php`
- Create: `packages/lemma-render/src/Templates/TemplateRepository.php`
- Modify: `scripts/run-test-migrations.php` (add pack path), `tests/Support/LemmaTestCase.php` (TABLES), `packages/lemma-render/src/LemmaRenderServiceProvider.php` (register repo service + `loadMigrationsFrom`)
- Test: `tests/Integration/Render/TemplateRepositoryTest.php`

**Interfaces:**
- Consumes: `Glueful\Database\Connection` (framework), `Glueful\Helpers\Utils::generateNanoID()`.
- Produces (later tasks rely on these exact signatures):
  - `overrideMap(string $theme): array<string,string>` — path → current_version_uuid, **active rows only**
  - `findCurrentSource(string $theme, string $path): ?array{source:string,version_uuid:string}` — null when missing/inactive
  - `find(string $theme, string $path): ?array` — raw row (any active state)
  - `listActive(string $theme): array<string,string>` — path → updated_at
  - `save(string $theme, string $path, string $source, ?string $createdBy): array{template_uuid:string,version_uuid:string}` — transaction; creates or **reactivates**
  - `deactivate(string $theme, string $path): bool` — false when no row / already inactive
  - `versions(string $theme, string $path): list<array{uuid:string,created_by:?string,created_at:string,current:bool}>` — newest first, works on inactive rows too
  - `findVersion(string $theme, string $path, string $versionUuid): ?array{uuid:string,source:string,created_by:?string,created_at:string}`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Render/TemplateRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Templates\TemplateRepository;

final class TemplateRepositoryTest extends LemmaTestCase
{
    private function repo(): TemplateRepository
    {
        return new TemplateRepository($this->connection());
    }

    public function testSaveCreatesRowAndVersionAndOverrideMapSeesIt(): void
    {
        $r = $this->repo();
        $ids = $r->save('default', 'entry.twig', 'v1 source', 'user00000001');
        self::assertSame(12, strlen($ids['template_uuid']));

        self::assertSame(['entry.twig' => $ids['version_uuid']], $r->overrideMap('default'));
        self::assertSame([], $r->overrideMap('other-theme')); // per-theme keying

        $current = $r->findCurrentSource('default', 'entry.twig');
        self::assertSame('v1 source', $current['source']);
        self::assertSame($ids['version_uuid'], $current['version_uuid']);
    }

    public function testHistoryIsAppendOnlyAndDeleteDeactivatesPreservingIt(): void
    {
        $r = $this->repo();
        $v1 = $r->save('default', 'entry.twig', 'one', null);
        $v2 = $r->save('default', 'entry.twig', 'two', 'user00000001');
        self::assertSame($v1['template_uuid'], $v2['template_uuid']); // same row
        self::assertNotSame($v1['version_uuid'], $v2['version_uuid']);

        $versions = $r->versions('default', 'entry.twig');
        self::assertCount(2, $versions);
        self::assertTrue($versions[0]['current']);   // newest first
        self::assertFalse($versions[1]['current']);

        self::assertTrue($r->deactivate('default', 'entry.twig'));
        self::assertFalse($r->deactivate('default', 'entry.twig')); // already inactive
        self::assertSame([], $r->overrideMap('default'));            // loader-invisible
        self::assertNull($r->findCurrentSource('default', 'entry.twig'));
        self::assertCount(2, $r->versions('default', 'entry.twig')); // history preserved

        // Re-create REACTIVATES the old row; history continues.
        $v3 = $r->save('default', 'entry.twig', 'three', null);
        self::assertSame($v1['template_uuid'], $v3['template_uuid']);
        self::assertCount(3, $r->versions('default', 'entry.twig'));
        self::assertSame('three', $r->findCurrentSource('default', 'entry.twig')['source']);
    }

    public function testFindVersionScopedToThemeAndPath(): void
    {
        $r = $this->repo();
        $ids = $r->save('default', 'entry.twig', 'body', 'user00000001');
        $found = $r->findVersion('default', 'entry.twig', $ids['version_uuid']);
        self::assertSame('body', $found['source']);
        self::assertSame('user00000001', $found['created_by']);
        self::assertNull($r->findVersion('other', 'entry.twig', $ids['version_uuid']));
        self::assertNull($r->findVersion('default', 'other.twig', $ids['version_uuid']));

        self::assertSame(['entry.twig'], array_keys($r->listActive('default')));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Render/TemplateRepositoryTest.php
```
Expected: FAIL — class `TemplateRepository` not found (and, once created, missing tables until Step 4's harness wiring).

- [ ] **Step 3: Write the migrations**

`packages/lemma-render/migrations/001_CreateRenderTemplatesTable.php` (navigation-pack shape — global namespace, `MigrationInterface`):

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateRenderTemplatesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('lemma_render_templates')) {
            return;
        }
        $schema->createTable('lemma_render_templates', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('theme', 64);
            $table->string('path', 190);
            // Nullable between row creation and the first version insert (one transaction).
            $table->string('current_version_uuid', 12)->nullable();
            // DELETE = deactivate (spec §2): the loader ignores inactive rows; history stays.
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['theme', 'path'], 'uniq_render_template_theme_path');
            $table->unique('uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('lemma_render_templates');
    }

    public function getDescription(): string
    {
        return 'Create lemma_render_templates (DB template override identity per theme+path).';
    }
}
```

`packages/lemma-render/migrations/002_CreateRenderTemplateVersionsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateRenderTemplateVersionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('lemma_render_template_versions')) {
            return;
        }
        $schema->createTable('lemma_render_template_versions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('template_uuid', 12);
            $table->text('source');
            // Bare user uuid — no cross-package FK (spec §2).
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique('uuid');
            $table->index('template_uuid', 'idx_render_template_versions_template');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('lemma_render_template_versions');
    }

    public function getDescription(): string
    {
        return 'Create lemma_render_template_versions (append-only, immutable template sources).';
    }
}
```

`packages/lemma-render/migrations/003_SeedTemplatesPermission.php` (copy of navigation's seed, one slug):

```php
<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

final class SeedTemplatesPermission implements MigrationInterface
{
    private const PERMISSIONS = [
        'templates.manage' => 'Manage theme template overrides',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        $db = new Connection();
        $existing = [];
        foreach (
            $db->table('permissions')->select(['slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $existing[$row['slug']] = true;
        }
        $insert = [];
        foreach (self::PERMISSIONS as $slug => $label) {
            if (isset($existing[$slug])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(),
                'slug' => $slug,
                'name' => $label,
                'category' => 'render',
                'description' => $label,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $db->table('permissions')->insertBatch($insert);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // NO-OP: removing the pack must not strip permission rows roles may reference.
    }

    public function getDescription(): string
    {
        return 'Declare the templates.manage permission.';
    }
}
```

- [ ] **Step 4: Wire the test harness**

`scripts/run-test-migrations.php` — after the `lemma-navigation` block, add:

```php
$manager->addMigrationPath(
    $root . '/packages/lemma-render/migrations',
    MigrationPriority::DEPENDENT,
    'lemma-render'
);
```

`tests/Support/LemmaTestCase.php` — TABLES (child → parent), prepend the two new tables:

```php
    private const TABLES = [
        'lemma_render_template_versions', 'lemma_render_templates',
        'navigation_items', 'navigation_menus',
```

`packages/lemma-render/src/LemmaRenderServiceProvider.php` — `boot()` starts with (before the capability gate; pack conventions):

```php
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
```

and `services()` gains:

```php
            TemplateRepository::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateRepository'],
            ],
```

with (imports: `use Glueful\Lemma\Render\Templates\TemplateRepository;` and `use Glueful\Database\Connection;` — **use-imports, not inline FQCNs**):

```php
    public static function makeTemplateRepository(ContainerInterface $container): TemplateRepository
    {
        return new TemplateRepository($container->get(Connection::class));
    }
```

Run the migrations against the test DB: `php scripts/run-test-migrations.php` (with the suite's `APP_ENV=testing` env — check how `composer test` invokes it and use the same invocation; if the suite auto-migrates on boot, just run the suite).

- [ ] **Step 5: Write TemplateRepository**

`packages/lemma-render/src/Templates/TemplateRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * DB template overrides (spec §2): one row per (theme, path); versions are APPEND-ONLY
 * and immutable — save() always inserts a version and repoints current_version_uuid;
 * deactivate() hides a row from the loader WITHOUT touching history; re-saving a
 * deactivated path reactivates the same row so history continues.
 */
final class TemplateRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,string> path => current_version_uuid (ACTIVE rows only) */
    public function overrideMap(string $theme): array
    {
        $map = [];
        foreach (
            $this->db->table('lemma_render_templates')
                ->select(['path', 'current_version_uuid'])
                ->where('theme', '=', $theme)
                ->where('active', '=', 1)
                ->get() as $row
        ) {
            $row = (array) $row;
            if (is_string($row['current_version_uuid'] ?? null) && $row['current_version_uuid'] !== '') {
                $map[(string) $row['path']] = (string) $row['current_version_uuid'];
            }
        }
        return $map;
    }

    /** @return array<string,mixed>|null the raw row, any active state */
    public function find(string $theme, string $path): ?array
    {
        $row = $this->db->table('lemma_render_templates')
            ->where('theme', '=', $theme)
            ->where('path', '=', $path)
            ->first();
        return $row === null ? null : (array) $row;
    }

    /** @return array{source:string,version_uuid:string}|null null = missing or inactive */
    public function findCurrentSource(string $theme, string $path): ?array
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null || (int) $tpl['active'] !== 1 || !is_string($tpl['current_version_uuid'])) {
            return null;
        }
        $version = $this->db->table('lemma_render_template_versions')
            ->where('uuid', '=', (string) $tpl['current_version_uuid'])
            ->first();
        if ($version === null) {
            return null;
        }
        return [
            'source' => (string) ((array) $version)['source'],
            'version_uuid' => (string) $tpl['current_version_uuid'],
        ];
    }

    /** @return array<string,string> path => updated_at (ACTIVE rows only) */
    public function listActive(string $theme): array
    {
        $out = [];
        foreach (
            $this->db->table('lemma_render_templates')
                ->select(['path', 'updated_at'])
                ->where('theme', '=', $theme)
                ->where('active', '=', 1)
                ->get() as $row
        ) {
            $row = (array) $row;
            $out[(string) $row['path']] = (string) ($row['updated_at'] ?? '');
        }
        return $out;
    }

    /**
     * Create-or-reactivate + append a version + repoint, in ONE transaction (spec §5).
     *
     * @return array{template_uuid:string,version_uuid:string}
     */
    public function save(string $theme, string $path, string $source, ?string $createdBy): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            $tpl = $this->find($theme, $path);
            if ($tpl === null) {
                $templateUuid = Utils::generateNanoID();
                $this->db->table('lemma_render_templates')->insert([
                    'uuid' => $templateUuid,
                    'theme' => $theme,
                    'path' => $path,
                    'current_version_uuid' => null,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $templateUuid = (string) $tpl['uuid'];
            }
            $versionUuid = Utils::generateNanoID();
            $this->db->table('lemma_render_template_versions')->insert([
                'uuid' => $versionUuid,
                'template_uuid' => $templateUuid,
                'source' => $source,
                'created_by' => $createdBy,
                'created_at' => $now,
            ]);
            $this->db->table('lemma_render_templates')
                ->where('uuid', '=', $templateUuid)
                ->update(['current_version_uuid' => $versionUuid, 'active' => 1, 'updated_at' => $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['template_uuid' => $templateUuid, 'version_uuid' => $versionUuid];
    }

    /** Deactivate (spec §2: DELETE preserves history). False = no row or already inactive. */
    public function deactivate(string $theme, string $path): bool
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null || (int) $tpl['active'] !== 1) {
            return false;
        }
        $this->db->table('lemma_render_templates')
            ->where('uuid', '=', (string) $tpl['uuid'])
            ->update(['active' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Newest first; readable on INACTIVE rows too (history survives delete; restore
     * reactivates).
     *
     * @return list<array{uuid:string,created_by:?string,created_at:string,current:bool}>
     */
    public function versions(string $theme, string $path): array
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null) {
            return [];
        }
        $current = is_string($tpl['current_version_uuid']) ? $tpl['current_version_uuid'] : '';
        $out = [];
        foreach (
            $this->db->table('lemma_render_template_versions')
                ->select(['uuid', 'created_by', 'created_at'])
                ->where('template_uuid', '=', (string) $tpl['uuid'])
                ->orderBy('id', 'DESC')
                ->get() as $row
        ) {
            $row = (array) $row;
            $out[] = [
                'uuid' => (string) $row['uuid'],
                'created_by' => is_string($row['created_by'] ?? null) ? $row['created_by'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'current' => ((int) $tpl['active']) === 1 && (string) $row['uuid'] === $current,
            ];
        }
        return $out;
    }

    /** @return array{uuid:string,source:string,created_by:?string,created_at:string}|null */
    public function findVersion(string $theme, string $path, string $versionUuid): ?array
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null) {
            return null;
        }
        $row = $this->db->table('lemma_render_template_versions')
            ->where('uuid', '=', $versionUuid)
            ->where('template_uuid', '=', (string) $tpl['uuid'])
            ->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        return [
            'uuid' => (string) $row['uuid'],
            'source' => (string) $row['source'],
            'created_by' => is_string($row['created_by'] ?? null) ? $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
```

NOTE: if the query builder's `orderBy('id', 'DESC')` signature differs, mirror whatever `MenuRepository`/other pack repos use — check before inventing.

- [ ] **Step 6: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Integration/Render/TemplateRepositoryTest.php
```
Expected: PASS (3 tests). If tables are missing, the suite migration step didn't run — re-check Step 4.

- [ ] **Step 7: Quality gates**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
```
Expected: `PHPCS_EXIT=0`, boundaries OK. **No staging yet.**

---

### Task 2: TemplatePolicy + TemplateLinter

**Files:**
- Create: `packages/lemma-render/src/Templates/TemplatePolicy.php`, `packages/lemma-render/src/Templates/TemplateLinter.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (register `TemplateLinter`)
- Test: `tests/Integration/Render/TemplateLinterTest.php`

**Interfaces:**
- Consumes: `RenderContextExtension` (container-shared — gives the scratch parser `menu`/`path`/`asset`/`facets`).
- Produces: `TemplateLinter::lint(string $source, string $name = 'template.twig'): list<array{line:int,message:string}>` — empty list = clean. `TemplatePolicy` constants `TAGS`, `FILTERS`, `FUNCTIONS`, `TESTS` and `TemplatePolicy::isAllowedNodeClass(string $class): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Render/TemplateLinterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Templates\TemplateLinter;

final class TemplateLinterTest extends LemmaTestCase
{
    private function linter(): TemplateLinter
    {
        return $this->container()->get(TemplateLinter::class);
    }

    /** The representative valid template: every allowlisted construct family at once. */
    public function testRepresentativeValidTemplateLintsClean(): void
    {
        $source = <<<'TWIG'
        {% extends "layout.twig" %}
        {% block content %}
          {% set items = entry.fields.tags|default([]) %}
          {% if items is not empty and items|length > 1 %}
            <ul>
            {% for item in items|sort %}
              <li class="{{ cycle(['odd','even'], loop.index0) }}">
                {{ item.name|upper }} — {{ item.count|number_format }}
                <a href="{{ path(item.uuid) ?? '#' }}">{{ item.title|default('Untitled') }}</a>
              </li>
            {% endfor %}
            </ul>
          {% endif %}
          {% include "partials/card.twig" %}
          {{ include("partials/footer.twig") }}
          <img src="{{ asset('img/logo.svg') }}" alt="{{ menu('main')|length }}">
          {% verbatim %}{{ not twig }}{% endverbatim %}
          {{ min(1, 2) + max(3, 4) }} {{ range(1, 3)|join(',') }}
          {{ "now"|date("Y") }} {{ entry.title ?? 'x' }}
          {{ loop is defined ? 'y' : 'n' }}
        {% endblock %}
        TWIG;
        self::assertSame([], $this->linter()->lint($source));
    }

    public function testDeniedConstructsEachViolateWithLineNumbers(): void
    {
        // [source, expected message fragment]
        $cases = [
            ["{% macro x() %}{% endmacro %}", 'macro'],
            ["{{ entry.title|raw }}", 'raw'],
            ["{{ constant('PHP_VERSION') }}", 'constant'],
            ["{{ attribute(entry, 'title') }}", 'attribute'],
            ["{{ source('layout.twig') }}", 'source'],
            ["{{ entry.getTitle() }}", 'Method calls'],
            ["{% apply upper %}x{% endapply %}", 'apply'],
            ["{{ [1,2]|map(v => v) }}", 'map'],
            ["{% include var_name %}", 'constant string'],
            ["{% extends var_name %}", 'constant string'],
            // Default-deny proof: SpreadUnary lives in the "safe-looking"
            // Expression\Unary\ namespace but is NOT in the exact-class allowlist —
            // an unreviewed node is denied regardless of its namespace.
            ["{{ [0, ...[1, 2]]|join(',') }}", 'not allowed'],
        ];
        foreach ($cases as [$source, $fragment]) {
            $violations = $this->linter()->lint($source);
            self::assertNotSame([], $violations, "expected violation for: {$source}");
            self::assertSame(1, $violations[0]['line'], "line for: {$source}");
            self::assertStringContainsStringIgnoringCase(
                $fragment,
                $violations[0]['message'],
                "message for: {$source}",
            );
        }
    }

    public function testSyntaxErrorReportsItsLine(): void
    {
        $violations = $this->linter()->lint("ok\n{% if x %}\nno endif");
        self::assertCount(1, $violations);
        self::assertGreaterThanOrEqual(2, $violations[0]['line']);
    }

    public function testAllViolationsReportedAtOnce(): void
    {
        $violations = $this->linter()->lint("{{ a|raw }}\n{{ constant('X') }}");
        self::assertCount(2, $violations);
        self::assertSame(1, $violations[0]['line']);
        self::assertSame(2, $violations[1]['line']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/TemplateLinterTest.php
```
Expected: FAIL — `TemplateLinter` not found.

- [ ] **Step 3: Write TemplatePolicy**

`packages/lemma-render/src/Templates/TemplatePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

/**
 * The v1 allowlists for DB-authored templates (spec §4) — DATA ONLY. TemplateLinter's
 * AST walk is the enforcement engine; nothing here (and nothing in Twig's sandbox)
 * runs at render time. NOT app-configurable in v1.
 *
 * Node classes: default-deny — EXACT classes only, each reviewed one-by-one (pure
 * data/control flow, no code execution, no I/O, no object reach). There is no
 * namespace-level allow: a new/unreviewed Twig node class is denied even when it
 * lives in a familiar-looking namespace (SpreadUnary, MatchesBinary — preg_match on
 * template-supplied patterns — and HasEvery/HasSomeBinary — arrow-function carriers —
 * are all deliberately absent). Class-string entries that don't exist in the
 * installed Twig are harmless (they never match); completeness is driven by the
 * representative-template test.
 */
final class TemplatePolicy
{
    /**
     * Compiled-cache invalidator: part of every DB template's compile-cache key
     * (db:{theme}:{path}:{version_uuid}:policy:{CACHE_VERSION}). The compile-time lint
     * runs only when a template compiles — without this, a template compiled under an
     * older, looser policy would keep executing after a tightening. BUMP THIS ON EVERY
     * allowlist or enforcement change (tags/filters/functions/tests/node classes/
     * linter rules); the next render then recompiles — and re-lints — everything.
     */
    public const CACHE_VERSION = 1;

    public const TAGS = ['if', 'for', 'set', 'block', 'extends', 'include', 'verbatim'];

    public const FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default',
        'escape', 'e', 'first', 'format', 'join', 'json_encode', 'keys', 'last',
        'length', 'lower', 'merge', 'nl2br', 'number_format', 'replace', 'reverse',
        'round', 'slice', 'sort', 'split', 'striptags', 'title', 'trim', 'upper',
        'url_encode',
    ];

    public const FUNCTIONS = [
        'menu', 'path', 'asset', 'facets',
        'include', 'parent', 'block', 'cycle', 'date', 'min', 'max', 'range',
    ];

    public const TESTS = [
        'defined', 'empty', 'even', 'iterable', 'null', 'odd',
        'same as', 'divisible by', 'sequence', 'mapping',
    ];

    /**
     * EXACT node classes known safe — enumerated from the installed Twig 3.27
     * (vendor/twig/twig/src/Node), reviewed individually. NO namespace-level allow.
     *
     * Deliberately absent (each is a decision, not an oversight):
     *   - Expression\Unary\SpreadUnary            — `...` spread; not needed in v1
     *   - Expression\Binary\MatchesBinary         — preg_match on template patterns (ReDoS)
     *   - Expression\Binary\HasEveryBinary/HasSomeBinary — arrow-function carriers
     *   - Expression\Binary\SetBinary + *DestructuringSetBinary — set internals beyond plain assignment
     *   - Expression\ArrowFunctionExpression      — how map/filter/reduce stay out
     *   - Expression\MacroReferenceExpression, MethodCallExpression, InlinePrint,
     *     VariadicExpression, ListExpression, Test\ConstantTest, Filter\RawFilter
     *
     * @var list<class-string|string>
     */
    public const NODE_CLASSES = [
        \Twig\Node\ModuleNode::class,
        \Twig\Node\BodyNode::class,
        \Twig\Node\Node::class,
        \Twig\Node\Nodes::class,
        \Twig\Node\TextNode::class,
        \Twig\Node\PrintNode::class,
        \Twig\Node\SetNode::class,
        \Twig\Node\IfNode::class,
        \Twig\Node\ForNode::class,
        \Twig\Node\ForLoopNode::class,
        \Twig\Node\BlockNode::class,
        \Twig\Node\BlockReferenceNode::class,
        \Twig\Node\IncludeNode::class,
        \Twig\Node\EmptyNode::class,
        \Twig\Node\CaptureNode::class,
        // Expressions (top level)
        \Twig\Node\Expression\ConstantExpression::class,
        \Twig\Node\Expression\ArrayExpression::class,
        \Twig\Node\Expression\GetAttrExpression::class,
        \Twig\Node\Expression\FilterExpression::class,
        \Twig\Node\Expression\FunctionExpression::class,
        \Twig\Node\Expression\TestExpression::class,
        \Twig\Node\Expression\ConditionalExpression::class,
        \Twig\Node\Expression\NullCoalesceExpression::class,
        \Twig\Node\Expression\ParentExpression::class,
        \Twig\Node\Expression\BlockReferenceExpression::class,
        \Twig\Node\Expression\EmptyExpression::class,
        // Variables
        \Twig\Node\Expression\Variable\ContextVariable::class,
        \Twig\Node\Expression\Variable\AssignContextVariable::class,
        \Twig\Node\Expression\Variable\LocalVariable::class,
        \Twig\Node\Expression\Variable\TemplateVariable::class,
        \Twig\Node\Expression\Variable\AssignTemplateVariable::class,
        // Unary (NO SpreadUnary)
        \Twig\Node\Expression\Unary\NegUnary::class,
        \Twig\Node\Expression\Unary\NotUnary::class,
        \Twig\Node\Expression\Unary\PosUnary::class,
        \Twig\Node\Expression\Unary\StringCastUnary::class,
        // Binary (NO Matches/HasEvery/HasSome/Set*/destructuring)
        \Twig\Node\Expression\Binary\AddBinary::class,
        \Twig\Node\Expression\Binary\AndBinary::class,
        \Twig\Node\Expression\Binary\BitwiseAndBinary::class,
        \Twig\Node\Expression\Binary\BitwiseOrBinary::class,
        \Twig\Node\Expression\Binary\BitwiseXorBinary::class,
        \Twig\Node\Expression\Binary\ConcatBinary::class,
        \Twig\Node\Expression\Binary\DivBinary::class,
        \Twig\Node\Expression\Binary\ElvisBinary::class,
        \Twig\Node\Expression\Binary\EndsWithBinary::class,
        \Twig\Node\Expression\Binary\EqualBinary::class,
        \Twig\Node\Expression\Binary\FloorDivBinary::class,
        \Twig\Node\Expression\Binary\GreaterBinary::class,
        \Twig\Node\Expression\Binary\GreaterEqualBinary::class,
        \Twig\Node\Expression\Binary\InBinary::class,
        \Twig\Node\Expression\Binary\LessBinary::class,
        \Twig\Node\Expression\Binary\LessEqualBinary::class,
        \Twig\Node\Expression\Binary\ModBinary::class,
        \Twig\Node\Expression\Binary\MulBinary::class,
        \Twig\Node\Expression\Binary\NotEqualBinary::class,
        \Twig\Node\Expression\Binary\NotInBinary::class,
        \Twig\Node\Expression\Binary\NotSameAsBinary::class,
        \Twig\Node\Expression\Binary\NullCoalesceBinary::class,
        \Twig\Node\Expression\Binary\OrBinary::class,
        \Twig\Node\Expression\Binary\PowerBinary::class,
        \Twig\Node\Expression\Binary\RangeBinary::class,
        \Twig\Node\Expression\Binary\SameAsBinary::class,
        \Twig\Node\Expression\Binary\SpaceshipBinary::class,
        \Twig\Node\Expression\Binary\StartsWithBinary::class,
        \Twig\Node\Expression\Binary\SubBinary::class,
        \Twig\Node\Expression\Binary\XorBinary::class,
        // Tests (NO ConstantTest)
        \Twig\Node\Expression\Test\DefinedTest::class,
        \Twig\Node\Expression\Test\DivisiblebyTest::class,
        \Twig\Node\Expression\Test\EvenTest::class,
        \Twig\Node\Expression\Test\NullTest::class,
        \Twig\Node\Expression\Test\OddTest::class,
        \Twig\Node\Expression\Test\SameasTest::class,
        // Filters (NO RawFilter)
        \Twig\Node\Expression\Filter\DefaultFilter::class,
        // Ternary
        \Twig\Node\Expression\Ternary\ConditionalTernary::class,
    ];

    public static function isAllowedNodeClass(string $class): bool
    {
        return in_array($class, self::NODE_CLASSES, true);
    }
}
```

**Completing the node allowlist is TDD-driven, not guesswork:** run the representative-template test; each failure names the offending node class in its message. For every named class, review it against the safety criteria — *pure data/control flow, no code execution, no I/O, no object reach* — and only then add it to `NODE_CLASSES` as an **exact class**. There is no namespace-level allow, ever — that a class lives in a safe-looking namespace is not a review. `ArrowFunctionExpression`, `SpreadUnary`, `MatchesBinary`, and `HasEvery/HasSomeBinary` must NEVER be added (arrow-function and regex escape hatches). The list above was enumerated from the installed `vendor/twig/twig/src/Node` — expect at most a couple of compiler-internal additions from the loop (e.g. if plain `{% set %}` routes through a class not listed).

- [ ] **Step 4: Write TemplateLinter**

`packages/lemma-render/src/Templates/TemplateLinter.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

use Glueful\Lemma\Render\RenderContextExtension;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Source;
use Twig\Template;

/**
 * THE enforcement engine for DB-authored templates (spec §4): parses the source in a
 * scratch environment (same extension set, so the render functions parse) and walks
 * the AST against TemplatePolicy. No runtime sandbox exists — what this scan allows
 * is what renders. Soundness rests on the render context being arrays/scalars only.
 *
 * Runs at SAVE (→ 422 with line numbers) and again in DatabaseTemplateLoader before
 * the source ever reaches the compiler (rows written around the API stay enforced).
 */
final class TemplateLinter
{
    public function __construct(private readonly RenderContextExtension $extension)
    {
    }

    /** @return list<array{line:int,message:string}> empty = clean */
    public function lint(string $source, string $name = 'template.twig'): array
    {
        $env = new Environment(new ArrayLoader([]), ['autoescape' => 'html']);
        $env->addExtension($this->extension);
        try {
            $module = $env->parse($env->tokenize(new Source($source, $name)));
        } catch (SyntaxError $e) {
            return [['line' => max(1, $e->getTemplateLine()), 'message' => $e->getRawMessage()]];
        }

        $violations = [];
        // extends target (module-level): constant string only (spec §4).
        if ($module->hasNode('parent') && !$module->getNode('parent') instanceof ConstantExpression) {
            $violations[] = [
                'line' => $module->getNode('parent')->getTemplateLine(),
                'message' => 'extends target must be a constant string.',
            ];
        }
        $this->walk($module, $violations);
        usort($violations, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        return $violations;
    }

    /** @param list<array{line:int,message:string}> $violations */
    private function walk(Node $node, array &$violations): void
    {
        $this->check($node, $violations);
        foreach ($node as $child) {
            if ($child instanceof Node) {
                $this->walk($child, $violations);
            }
        }
    }

    /** @param list<array{line:int,message:string}> $violations */
    private function check(Node $node, array &$violations): void
    {
        $line = max(1, $node->getTemplateLine());
        $deny = static function (string $message) use (&$violations, $line): void {
            $violations[] = ['line' => $line, 'message' => $message];
        };

        // Unknown node classes: default-deny (spec §4) — checked FIRST so a novel
        // construct is reported even when it carries no tag/name.
        if (!TemplatePolicy::isAllowedNodeClass($node::class)) {
            $deny(sprintf('Template construct "%s" is not allowed.', $node::class));
            return; // one violation per unknown node; children are still walked by walk()
        }

        $tag = $node->getNodeTag();
        if ($tag !== null && $tag !== '' && !in_array($tag, TemplatePolicy::TAGS, true)) {
            $deny(sprintf('Tag "%s" is not allowed.', $tag));
        }

        if ($node instanceof FilterExpression) {
            $filterName = (string) $node->getAttribute('name');
            if (!in_array($filterName, TemplatePolicy::FILTERS, true)) {
                $deny(sprintf('Filter "%s" is not allowed.', $filterName));
            }
        }

        if ($node instanceof FunctionExpression) {
            $functionName = (string) $node->getAttribute('name');
            if (!in_array($functionName, TemplatePolicy::FUNCTIONS, true)) {
                $deny(sprintf('Function "%s" is not allowed.', $functionName));
            }
            // include() function target: constant string only (spec §4).
            if ($functionName === 'include') {
                $args = $node->getNode('arguments');
                $first = null;
                foreach ($args as $arg) {
                    $first = $arg;
                    break;
                }
                if (!$first instanceof ConstantExpression) {
                    $deny('include target must be a constant string.');
                }
            }
        }

        // Specialized nodes that replaced a function call (parent, block, attribute…)
        // stash the original name — police it like a normal function (Twig 3.27).
        if ($node->hasAttribute('sandboxed_function_name')) {
            $stashed = (string) $node->getAttribute('sandboxed_function_name');
            if (!in_array($stashed, TemplatePolicy::FUNCTIONS, true)) {
                $deny(sprintf('Function "%s" is not allowed.', $stashed));
            }
        }

        if ($node instanceof TestExpression) {
            $testName = (string) $node->getAttribute('name');
            if (!in_array($testName, TemplatePolicy::TESTS, true)) {
                $deny(sprintf('Test "%s" is not allowed.', $testName));
            }
        }

        if (
            $node instanceof GetAttrExpression
            && $node->getAttribute('type') === Template::METHOD_CALL
        ) {
            $deny('Method calls are not allowed.');
        }

        if ($node instanceof IncludeNode && !$node->getNode('expr') instanceof ConstantExpression) {
            $deny('include target must be a constant string.');
        }
    }
}
```

Twig 3.27 API adjustments the implementer may hit (verify against `vendor/twig/twig/src`, do not guess): `TestExpression`'s name attribute; whether `getNodeTag()` returns null or '' — handle both (the code above does); the exact class replacing `attribute()` (its `sandboxed_function_name` stash covers it either way). If `{{ x|map(...) }}` parses to a violation via `ArrowFunctionExpression` unknown-node instead of the `map` filter name, the test's `'map'` fragment fails — swap that case's expected fragment to `'not allowed'` and assert the line only.

- [ ] **Step 5: Register the linter service**

`LemmaRenderServiceProvider::services()` gains:

```php
            TemplateLinter::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateLinter'],
            ],
```

with (import `use Glueful\Lemma\Render\Templates\TemplateLinter;`):

```php
    public static function makeTemplateLinter(ContainerInterface $container): TemplateLinter
    {
        return new TemplateLinter($container->get(RenderContextExtension::class));
    }
```

- [ ] **Step 6: Run to verify they pass**

```bash
vendor/bin/phpunit tests/Integration/Render/TemplateLinterTest.php
```
Expected: PASS. Iterate the node allowlist per Step 3's criteria until the representative template is clean AND every denial case still fails closed.

- [ ] **Step 7: Quality gates**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
```
Expected: clean. **No staging yet.**

---

### Task 3: Loaders + TwigFactory + config/provider wiring

**Files:**
- Create: `packages/lemma-render/src/Templates/DatabaseTemplateLoader.php`, `packages/lemma-render/src/Templates/RenderTemplateLoader.php`
- Modify: `packages/lemma-render/src/TwigFactory.php`, `packages/lemma-render/config/lemma-render.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php` (`makeTwigFactory`)
- Test: `tests/Integration/Render/DbTemplateLoaderTest.php`

**Interfaces:**
- Consumes: `TemplateRepository` (Task 1), `TemplateLinter` (Task 2).
- Produces:
  - `DatabaseTemplateLoader::__construct(TemplateRepository $repo, TemplateLinter $linter, string $theme)`; `reset(): void`; implements `Twig\Loader\LoaderInterface`.
  - `RenderTemplateLoader::__construct(DatabaseTemplateLoader $db, FilesystemLoader $fs)`; `resetForRender(): void`; implements `LoaderInterface`.
  - `TwigFactory::__construct(ThemeLocator, RenderContextExtension, string $cacheDir, ?DatabaseTemplateLoader $dbTemplates = null)`.
  - Config key `lemma_render.db_templates` (default true).

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Render/DbTemplateLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;
use Glueful\Lemma\Render\Templates\RenderTemplateLoader;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Twig\Environment;
use Twig\Error\LoaderError;

final class DbTemplateLoaderTest extends LemmaTestCase
{
    private function repo(): TemplateRepository
    {
        return new TemplateRepository($this->connection());
    }

    /** A fresh environment over the composite loader for the given theme. */
    private function env(string $theme = 'default'): Environment
    {
        $base = $this->appContext()->getBasePath();
        $factory = new TwigFactory(
            new ThemeLocator($theme, $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
            new DatabaseTemplateLoader(
                $this->repo(),
                $this->container()->get(TemplateLinter::class),
                $theme,
            ),
        );
        return $factory->environment();
    }

    public function testDbOverrideShadowsFilesystemAndDeactivateFallsBack(): void
    {
        $env = $this->env();
        $fsRendered = $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]);

        $this->repo()->save('default', 'entry.twig', 'DBOVERRIDE:{{ entry.fields.title }}', null);
        $loader = $env->getLoader();
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        self::assertSame(
            'DBOVERRIDE:T',
            $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]),
        );

        $this->repo()->deactivate('default', 'entry.twig');
        $loader->resetForRender();
        self::assertSame(
            $fsRendered,
            $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]),
        );
    }

    /** THE ChainLoader regression (spec §3): a miss must not poison later existence. */
    public function testDbOnlyTemplateResolvesAfterAnEarlierMissInTheSameProcess(): void
    {
        $env = $this->env();
        $loader = $env->getLoader();
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);

        self::assertFalse($loader->exists('entry/interview.twig')); // the poisoning miss
        $this->repo()->save('default', 'entry/interview.twig', 'INTERVIEW:{{ entry.x }}', null);
        $loader->resetForRender();
        self::assertTrue($loader->exists('entry/interview.twig'));
        self::assertSame('INTERVIEW:1', $env->render('entry/interview.twig', ['entry' => ['x' => 1]]));
    }

    public function testEverySaveIsANewCompiledCacheEntryAndOldVersionsStayImmutable(): void
    {
        $this->repo()->save('default', 'k.twig', 'one', null);
        $env = $this->env();
        $loader = $env->getLoader();
        $keyOne = $loader->getCacheKey('k.twig');

        $this->repo()->save('default', 'k.twig', 'two', null);
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        self::assertNotSame($keyOne, $loader->getCacheKey('k.twig')); // version in the key
        self::assertSame('two', $env->render('k.twig'));
        self::assertTrue($loader->isFresh('k.twig', 0));

        // Policy version is part of the key (spec §3/§4): a tightening bumps
        // TemplatePolicy::CACHE_VERSION and orphans every compiled DB template.
        self::assertStringContainsString(
            ':policy:' . \Glueful\Lemma\Render\Templates\TemplatePolicy::CACHE_VERSION,
            $loader->getCacheKey('k.twig'),
        );
    }

    /** Compile-time enforcement: a row written AROUND the API never executes. */
    public function testMaliciousRowInsertedViaSqlFailsAtCompileNotAtSave(): void
    {
        // Straight SQL — bypasses the save-time lint entirely.
        $this->repo()->save('default', 'evil.twig', 'placeholder', null);
        $map = $this->repo()->overrideMap('default');
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $map['evil.twig'])
            ->update(['source' => "{{ constant('PHP_VERSION') }}"]);

        $env = $this->env();
        $this->expectException(LoaderError::class);
        $env->render('evil.twig');
    }

    public function testNoDbLoaderMeansPureFilesystemBehavior(): void
    {
        $base = $this->appContext()->getBasePath();
        $factory = new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        );
        $env = $factory->environment();
        self::assertNotInstanceOf(RenderTemplateLoader::class, $env->getLoader());

        // An active DB override is INVISIBLE without the DB loader (kill-switch seam).
        $this->repo()->save('default', 'entry.twig', 'DBOVERRIDE:x', null);
        self::assertStringNotContainsString(
            'DBOVERRIDE',
            $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]),
        );
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/DbTemplateLoaderTest.php
```
Expected: FAIL — loader classes not found / `TwigFactory` has no 4th param.

- [ ] **Step 3: Write the loaders**

`packages/lemma-render/src/Templates/DatabaseTemplateLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Twig loader over the override rows for ONE theme (spec §3). The override map is
 * memoized for a single render — reset() clears it (called via
 * RenderTemplateLoader::resetForRender() before every render); freshness is
 * reload-per-render + version-keyed compile-cache keys, NEVER events.
 *
 * getSourceContext() re-runs the policy scan before the source reaches the compiler:
 * rows written around the admin API (SQL, migrations) are still enforced. The compile
 * cache is keyed to the version uuid, so the scan runs once per saved version.
 */
final class DatabaseTemplateLoader implements LoaderInterface
{
    /** @var array<string,string>|null path => current_version_uuid; null = reload */
    private ?array $map = null;

    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly TemplateLinter $linter,
        private readonly string $theme,
    ) {
    }

    public function reset(): void
    {
        $this->map = null;
    }

    /** @return array<string,string> */
    private function map(): array
    {
        return $this->map ??= $this->repo->overrideMap($this->theme);
    }

    public function exists(string $name): bool
    {
        return isset($this->map()[$name]);
    }

    public function getCacheKey(string $name): string
    {
        $version = $this->map()[$name]
            ?? throw new LoaderError(sprintf('Template "%s" has no active DB override.', $name));
        // The policy version is part of the key (spec §3/§4): the compile-time lint
        // only runs on compile, so a policy TIGHTENING must orphan every previously
        // compiled DB template — otherwise old compilations keep executing unchecked.
        return 'db:' . $this->theme . ':' . $name . ':' . $version
            . ':policy:' . TemplatePolicy::CACHE_VERSION;
    }

    public function isFresh(string $name, int $time): bool
    {
        return true; // versions are immutable; a save (or policy bump) is a NEW cache key
    }

    public function getSourceContext(string $name): Source
    {
        $row = $this->repo->findCurrentSource($this->theme, $name);
        if ($row === null) {
            throw new LoaderError(sprintf('Template "%s" has no active DB override.', $name));
        }
        $violations = $this->linter->lint($row['source'], $name);
        if ($violations !== []) {
            throw new LoaderError(sprintf(
                'DB template "%s" (theme "%s") violates the template policy: %s',
                $name,
                $this->theme,
                implode('; ', array_map(
                    static fn (array $v): string => sprintf('line %d: %s', $v['line'], $v['message']),
                    $violations,
                )),
            ));
        }
        return new Source($row['source'], $name);
    }
}
```

`packages/lemma-render/src/Templates/RenderTemplateLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * DB-first, filesystem-second composite (spec §3). Deliberately NOT Twig's ChainLoader:
 * ChainLoader memoizes exists() in a persistent $hasSourceCache with no invalidation
 * path — a miss before the first save would pin a DB-only template to "not found" for
 * the process lifetime. This composite keeps NO exists-cache of its own;
 * resetForRender() clears the one piece of state that can go stale (the DB map).
 */
final class RenderTemplateLoader implements LoaderInterface
{
    public function __construct(
        private readonly DatabaseTemplateLoader $db,
        private readonly FilesystemLoader $fs,
    ) {
    }

    /** Called by the render controller before EVERY render (resetTags() family). */
    public function resetForRender(): void
    {
        $this->db->reset();
    }

    public function exists(string $name): bool
    {
        return $this->db->exists($name) || $this->fs->exists($name);
    }

    public function getSourceContext(string $name): Source
    {
        return $this->db->exists($name) ? $this->db->getSourceContext($name) : $this->fs->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        return $this->db->exists($name) ? $this->db->getCacheKey($name) : $this->fs->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->db->exists($name) ? $this->db->isFresh($name, $time) : $this->fs->isFresh($name, $time);
    }
}
```

- [ ] **Step 4: Extend TwigFactory**

`packages/lemma-render/src/TwigFactory.php` — full new body (imports gain `use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;` and `use Glueful\Lemma\Render\Templates\RenderTemplateLoader;`):

```php
final class TwigFactory
{
    public function __construct(
        private readonly ThemeLocator $themes,
        private readonly RenderContextExtension $extension,
        private readonly string $cacheDir,
        // DB-edited templates (spec §3): when present, overrides load DB-first through
        // the pack composite. Null = pure filesystem, byte-identical to pre-feature.
        private readonly ?DatabaseTemplateLoader $dbTemplates = null,
    ) {
    }

    public function environment(): Environment
    {
        $paths = $this->themes->activePaths();
        $fs = new FilesystemLoader($paths['templates']);
        $twig = new Environment(
            $this->dbTemplates === null ? $fs : new RenderTemplateLoader($this->dbTemplates, $fs),
            [
                'autoescape' => 'html',
                'cache' => rtrim($this->cacheDir, '/') . '/' . $paths['name'],
                'auto_reload' => true,
                'strict_variables' => false,
            ],
        );
        $twig->addExtension($this->extension);
        return $twig;
    }
}
```

- [ ] **Step 5: Config + provider factory**

`packages/lemma-render/config/lemma-render.php` — append:

```php
    // DB-edited templates (spec 2026-07-03 §7): admin-authored overrides layered over
    // the filesystem theme. false = ops kill-switch — pure filesystem loading
    // (pre-feature behavior) and the template admin routes are not registered.
    'db_templates' => env('RENDER_DB_TEMPLATES', true),
```

`LemmaRenderServiceProvider::makeTwigFactory` becomes (imports for the two loader classes already added? add `use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;`):

```php
    public static function makeTwigFactory(ContainerInterface $container): TwigFactory
    {
        $context = $container->get(ApplicationContext::class);
        $db = null;
        if ((bool) config($context, 'lemma_render.db_templates', true)) {
            $db = new DatabaseTemplateLoader(
                $container->get(TemplateRepository::class),
                $container->get(TemplateLinter::class),
                // The RESOLVED active theme (activePaths()['name']) — matches the page
                // cache's theme keying; a fallen-back locator must not apply another
                // theme's overrides.
                $container->get(ThemeLocator::class)->activePaths()['name'],
            );
        }
        return new TwigFactory(
            $container->get(ThemeLocator::class),
            $container->get(RenderContextExtension::class),
            $context->getBasePath() . '/storage/cache/twig',
            $db,
        );
    }
```

- [ ] **Step 6: Run to verify they pass**

```bash
vendor/bin/phpunit tests/Integration/Render/DbTemplateLoaderTest.php
vendor/bin/phpunit tests/Integration/Render/
```
Expected: PASS — including every pre-existing render test (no-override behavior byte-identical).

- [ ] **Step 7: Quality gates**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
```
Expected: clean. **No staging yet.**

---

### Task 4: Render pipeline integration — reset, themed sessions, event + purge

**Files:**
- Create: `packages/lemma-render/src/Templates/TemplateUpdated.php`, `packages/lemma-render/src/Listeners/PurgeRenderCacheOnTemplateUpdate.php`
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php`
- Test: `tests/Integration/Render/DbTemplatesPipelineTest.php`

**Interfaces:**
- Consumes: `RenderTemplateLoader::resetForRender()`, `DatabaseTemplateLoader` (Task 3), `TemplateRepository`/`TemplateLinter` (Tasks 1–2), the existing `themedEnv()`/`render()` in `RenderController`.
- Produces: `TemplateUpdated(public readonly string $theme, public readonly string $path)` event; `PurgeRenderCacheOnTemplateUpdate::onTemplateUpdated(object $event): void`; `RenderController` ctor extended with `?TemplateRepository $templates = null, ?TemplateLinter $templateLinter = null` (after `$sessionVerifier`).

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Render/DbTemplatesPipelineTest.php` (harness idioms from `PreviewSessionTest`: `SeedsPublishedContent` gives `seedBilingualPublishedEntry()` → published entry routed at `/blog/hello`; `handle()` dispatches through the kernel; page-cache state cleared in tearDown):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Preview\PreviewMinter;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\Templates\TemplateUpdated;
use Symfony\Component\HttpFoundation\Request;

final class DbTemplatesPipelineTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function repo(): TemplateRepository
    {
        return new TemplateRepository($this->connection());
    }

    private function saveAndAnnounce(string $theme, string $path, string $source): void
    {
        $this->repo()->save($theme, $path, $source, null);
        $this->container()->get(EventService::class)->dispatch(new TemplateUpdated($theme, $path));
    }

    public function testSaveIsLiveOnTheVeryNextRequestWithoutRestart(): void
    {
        $this->seedBilingualPublishedEntry();
        $before = (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent();
        self::assertStringNotContainsString('DBLIVE', $before);

        $this->saveAndAnnounce('default', 'entry.twig', 'DBLIVE:{{ entry.fields.title }}');
        $after = (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent();
        self::assertStringContainsString('DBLIVE:', $after);
    }

    public function testActiveThemeSavePurgesCachedPagesAndInactiveThemeSaveDoesNot(): void
    {
        // Observable cached-vs-fresh sentinel: the CACHED body says DBLIVE1; a NEWER
        // pending override (saved WITHOUT its event) would render DBLIVE2 on any
        // re-render — so "still DBLIVE1" proves the cache was NOT purged, and
        // "DBLIVE2" proves it WAS. A body assertion alone can't distinguish
        // cached-served from re-rendered-identical.
        $this->seedBilingualPublishedEntry();
        $this->saveAndAnnounce('default', 'entry.twig', 'DBLIVE1:{{ entry.fields.title }}');
        self::assertStringContainsString(
            'DBLIVE1:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(), // primes cache
        );

        // Newer override, NO event: visible only if something re-renders the page.
        $this->repo()->save('default', 'entry.twig', 'DBLIVE2:{{ entry.fields.title }}', null);
        self::assertStringContainsString(
            'DBLIVE1:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(), // cache intact
        );

        // INACTIVE theme mutation: must NOT purge — the DBLIVE1 body is still served
        // even though a re-render would say DBLIVE2.
        $this->saveAndAnnounce('othertheme', 'entry.twig', 'OTHER:{{ entry.fields.title }}');
        self::assertStringContainsString(
            'DBLIVE1:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(),
        );

        // ACTIVE theme mutation purges: the very next request re-renders → DBLIVE2.
        $this->saveAndAnnounce('default', 'entry.twig', 'DBLIVE2:{{ entry.fields.title }}');
        self::assertStringContainsString(
            'DBLIVE2:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(),
        );
    }

    public function testFixed404BodyRefreshesAfterA404TemplateOverride(): void
    {
        $this->seedBilingualPublishedEntry();
        // Prime the SHARED fixed 404 body (RenderErrorCache).
        $this->handle(Request::create('/no-such-page', 'GET'));

        $this->saveAndAnnounce('default', '404.twig', 'CUSTOM404BODY');
        $res = $this->handle(Request::create('/no-such-page', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('CUSTOM404BODY', (string) $res->getContent());
    }

    public function testBrokenErrorTemplateOverrideDegradesToPlainText500(): void
    {
        $this->seedBilingualPublishedEntry();
        // error.twig override that FAILS the compile-time policy (inserted via SQL
        // around the lint) → LoaderError at render → error.twig retry ALSO fails →
        // the recursion guard's plain-text 500.
        $this->repo()->save('default', 'entry.twig', 'ok {{ entry.fields.title }}', null);
        $this->repo()->save('default', 'error.twig', 'placeholder', null);
        $map = $this->repo()->overrideMap('default');
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $map['entry.twig'])
            ->update(['source' => "{{ constant('X') }}"]);
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $map['error.twig'])
            ->update(['source' => "{{ constant('X') }}"]);
        $this->container()->get(EventService::class)
            ->dispatch(new TemplateUpdated('default', 'entry.twig'));

        $res = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(500, $res->getStatusCode());
        self::assertSame('Internal Server Error', (string) $res->getContent());
    }

    public function testThemedPreviewSessionRendersThatThemesOverrides(): void
    {
        $this->seedBilingualPublishedEntry();
        $this->makeAltTheme();
        try {
            // Override FOR THE ALT THEME only.
            $this->repo()->save('altprev', 'entry.twig', 'ALTDB:{{ entry.fields.title }}', null);
            $entry = $this->seedDraftEntry('Session draft');
            $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

            $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
            self::assertSame(200, $res->getStatusCode());
            self::assertStringContainsString('ALTDB:Session draft', (string) $res->getContent());

            // The boot environment stays unpoisoned AND default-theme pages don't see
            // the alt theme's override.
            $plain = (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent();
            self::assertStringNotContainsString('ALTDB:', $plain);
        } finally {
            $this->removeAltTheme();
        }
    }

    // --- fixtures (altprev theme + draft seeding: same shapes as PreviewSessionTest) ---

    private function makeAltTheme(): void
    {
        $base = $this->appContext()->getBasePath() . '/themes/altprev';
        mkdir($base . '/templates', 0777, true);
        file_put_contents($base . '/theme.json', (string) json_encode(['name' => 'altprev']));
        // No entry.twig on disk: the DB override + pack-default fallback do the work.
        file_put_contents($base . '/templates/layout.twig', "{% block content %}{% endblock %}");
    }

    private function removeAltTheme(): void
    {
        $alt = $this->appContext()->getBasePath() . '/themes/altprev';
        if (!is_dir($alt)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($alt, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f
        ) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($alt);
    }

    private function seedDraftEntry(string $title): string
    {
        $types = new \App\Content\Repositories\ContentTypeRepository($this->connection());
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new \App\Content\Repositories\EntryRepository(
            $this->connection(),
            $this->appContext(),
            $types,
        );
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        return $uuid;
    }
}
```

NOTE: the themed-session assertion depends on how the alt theme's DB `entry.twig` override renders without a disk `entry.twig` — the DB override IS the template, extending nothing, so `ALTDB:…` is the whole body. If `PreviewSessionTest`'s `makeAltTheme` differs usefully (it writes `templates/entry.twig`), keep THIS test's shape: the point is the DB override applying under the session theme.

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/DbTemplatesPipelineTest.php
```
Expected: FAIL — `TemplateUpdated` missing; saves invisible to renders (no reset wiring); no purge listener.

- [ ] **Step 3: Event + listener**

`packages/lemma-render/src/Templates/TemplateUpdated.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A DB template override changed what renders (save, deactivate, restore — spec §5).
 * In-pack event: dispatched by the templates admin controller, consumed by the render
 * purge listener. It may clear same-process loader state as a convenience, but it is
 * NOT the freshness mechanism (that's reset-per-render + version-keyed cache keys).
 */
final class TemplateUpdated extends BaseEvent
{
    public function __construct(
        public readonly string $theme,
        public readonly string $path,
    ) {
        parent::__construct();
    }
}
```

`packages/lemma-render/src/Listeners/PurgeRenderCacheOnTemplateUpdate.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Listeners;

use Glueful\Cache\CacheStore;
use Glueful\Lemma\Render\Templates\TemplateUpdated;
use Glueful\Lemma\Render\ThemeLocator;
use Psr\Container\ContainerInterface;

/**
 * TemplateUpdated → invalidateTags(['lemma:render:page']) — ONLY when the edited theme
 * is the ACTIVE theme (spec §5): inactive themes never populate the shared caches
 * (preview sessions are uncached). The one tag covers the page cache AND the fixed
 * 404/410 bodies (RenderErrorCache tags them identically). Broad purge over cleverness
 * — a template affects an unknowable page set. Compiled Twig cache: untouched
 * (version-keyed). Services resolved per-invocation (menu-listener precedent).
 */
final class PurgeRenderCacheOnTemplateUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onTemplateUpdated(object $event): void
    {
        if (!$event instanceof TemplateUpdated) {
            return;
        }
        $active = $this->container->get(ThemeLocator::class)->activePaths()['name'];
        if ($event->theme !== $active) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['lemma:render:page']);
    }
}
```

- [ ] **Step 4: RenderController wiring**

Ctor appends two soft params (after `$sessionVerifier`; imports: `use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;`, `use Glueful\Lemma\Render\Templates\RenderTemplateLoader;`, `use Glueful\Lemma\Render\Templates\TemplateLinter;`, `use Glueful\Lemma\Render\Templates\TemplateRepository;`):

```php
        private readonly ?TemplateRepository $templates = null,
        private readonly ?TemplateLinter $templateLinter = null,
```

`render()` — the reset block grows one line (FIRST line of the block, before `resetTags()`; `$env` is hoisted so the try uses it too):

```php
        $env = $twig ?? $this->twig();
        $loader = $env->getLoader();
        if ($loader instanceof RenderTemplateLoader) {
            $loader->resetForRender(); // reload the override map once per render (spec §3)
        }
        $this->extension->resetTags();
        $this->extension->setAssetBase($assetBase);
        $this->extension->setLocale($locale);
```

…and in the try: `$html = $env->render($template, $context);` (replacing the inline `($twig ?? $this->twig())`).

`themedEnv()` — the factory construction gains the DB loader for the SESSION theme, keyed to the LOCATOR-RESOLVED name (a vanished theme that fell back to `default` must not carry the vanished theme's overrides):

```php
        $base = $this->context->getBasePath();
        try {
            $locator = new ThemeLocator($session->theme, $base . '/themes');
            $db = $this->templates !== null && $this->templateLinter !== null
                ? new DatabaseTemplateLoader(
                    $this->templates,
                    $this->templateLinter,
                    $locator->activePaths()['name'],
                )
                : null;
            $factory = new TwigFactory(
                $locator,
                $this->extension,
                $base . '/storage/cache/twig',
                $db,
            );
            return [$factory->environment(), '/_preview-assets/' . $session->token];
        } catch (\Throwable $e) {
```

Provider — `makeRenderController` appends (resolve `$context` at the top of the factory if not already):

```php
            (bool) config($context, 'lemma_render.db_templates', true)
                ? $container->get(TemplateRepository::class)
                : null,
            (bool) config($context, 'lemma_render.db_templates', true)
                ? $container->get(TemplateLinter::class)
                : null,
```

Provider — `boot()`, inside the `isEnabled('lemma.render')` gate, next to the MenuUpdated listener (import `use Glueful\Lemma\Render\Templates\TemplateUpdated;`; the `$events` variable already exists there):

```php
            if ((bool) config($context, 'lemma_render.db_templates', true)) {
                $events->addListener(
                    TemplateUpdated::class,
                    [app($context, PurgeRenderCacheOnTemplateUpdate::class), 'onTemplateUpdated'],
                );
            }
```

…and `services()` gains `PurgeRenderCacheOnTemplateUpdate` (mirror `PurgeRenderCacheOnMenuUpdate`'s registration: shared, factory passing the container).

- [ ] **Step 5: Run to verify they pass**

```bash
vendor/bin/phpunit tests/Integration/Render/DbTemplatesPipelineTest.php
vendor/bin/phpunit tests/Integration/Render/
```
Expected: PASS, including every pre-existing render/preview test.

- [ ] **Step 6: Quality gates**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
```
Expected: clean. **No staging yet.**

---

### Task 5: Admin API — catalog, controller, routes, grammar

**Files:**
- Create: `packages/lemma-render/src/Templates/TemplateCatalog.php`, `packages/lemma-render/src/Http/Controllers/TemplatesAdminController.php`, `packages/lemma-render/routes/admin-routes.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php`
- Test: `tests/Integration/Render/TemplatesAdminApiTest.php`

**Interfaces:**
- Consumes: `TemplateRepository`, `TemplateLinter`, `TemplateUpdated`, `PreviewThemeValidator` (existing contract binding → `RenderThemeValidator`), `ThemeLocator`, `EventService`.
- Produces: `TemplateCatalog::list(string $theme): list<array{path:string,origin:string,overridden:bool,updated_at:?string}>`; `TemplateCatalog::readFile(string $theme, string $path): ?array{source:string,origin:string}`; controller actions `index/show/save/delete/versions/showVersion/restore`.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Render/TemplatesAdminApiTest.php` (direct controller invocation + route-table/grammar assertions — the `NavigationApiTest` idiom):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Http\Controllers\TemplatesAdminController;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

final class TemplatesAdminApiTest extends LemmaTestCase
{
    private function api(): TemplatesAdminController
    {
        return $this->container()->get(TemplatesAdminController::class);
    }

    private function putReq(string $source, array $query = []): Request
    {
        $req = Request::create(
            '/x',
            'PUT',
            $query,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['source' => $source]),
        );
        $req->attributes->set('user', ['uuid' => 'user00000001']);
        return $req;
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response|\Symfony\Component\HttpFoundation\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    public function testListMergesFilesystemAndDbWithOrigins(): void
    {
        $list = $this->json($this->api()->index(Request::create('/x', 'GET')))['data']['templates'];
        $byPath = array_column($list, null, 'path');
        self::assertSame('default', $byPath['entry.twig']['origin']); // pack default file
        self::assertFalse($byPath['entry.twig']['overridden']);

        $this->api()->save($this->putReq('DB:{{ entry.fields.title }}'), 'entry.twig');
        $this->api()->save($this->putReq('NEW'), 'entry/interview.twig'); // DB-only path

        $list = $this->json($this->api()->index(Request::create('/x', 'GET')))['data']['templates'];
        $byPath = array_column($list, null, 'path');
        self::assertSame('db', $byPath['entry.twig']['origin']);
        self::assertTrue($byPath['entry.twig']['overridden']);
        self::assertSame('db', $byPath['entry/interview.twig']['origin']); // created, not 404'd
    }

    public function testShowReadsDbThenFilesystemAnd404sWhenNeitherExists(): void
    {
        $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'entry.twig'));
        self::assertSame('default', $show['data']['origin']); // fs source, read-only start
        self::assertNotSame('', $show['data']['source']);

        $this->api()->save($this->putReq('DBSRC'), 'entry.twig');
        $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'entry.twig'));
        self::assertSame('db', $show['data']['origin']);
        self::assertSame('DBSRC', $show['data']['source']);

        self::assertSame(
            404,
            $this->api()->show(Request::create('/x', 'GET'), 'entry/nope.twig')->getStatusCode(),
        );
    }

    public function testSaveValidatesPathThemeAndPolicy(): void
    {
        // Policy violation → 422 with line-numbered errors.
        $res = $this->api()->save($this->putReq("ok\n{{ x|raw }}"), 'entry.twig');
        self::assertSame(422, $res->getStatusCode());
        $errors = $this->json($res)['errors'];
        self::assertSame(2, $errors[0]['line']);

        // Path traversal / non-.twig / URL-significant characters / empty segments →
        // 422 (the segment grammar keeps raw slash-preserving admin URLs deterministic).
        foreach (
            [
                '../evil.twig',
                'notes.txt',
                'entry/foo?bar.twig',
                'entry/foo#bar.twig',
                'entry/foo bar.twig',
                'entry//foo.twig',
                '/entry.twig',
                'entry/./foo.twig',
            ] as $badPath
        ) {
            self::assertSame(
                422,
                $this->api()->save($this->putReq('x'), $badPath)->getStatusCode(),
                "expected 422 for path: {$badPath}",
            );
        }
        // Unknown theme → 404.
        self::assertSame(
            404,
            $this->api()->save($this->putReq('x', ['theme' => 'ghost']), 'entry.twig')->getStatusCode(),
        );
        // Missing/non-string source → 422.
        $bad = Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(422, $this->api()->save($bad, 'entry.twig')->getStatusCode());
    }

    public function testDeleteVersionsRestoreLifecycle(): void
    {
        self::assertSame(404, $this->api()->delete(Request::create('/x', 'DELETE'), 'entry.twig')->getStatusCode());

        $this->api()->save($this->putReq('one'), 'entry.twig');
        $this->api()->save($this->putReq('two'), 'entry.twig');
        self::assertSame(200, $this->api()->delete(Request::create('/x', 'DELETE'), 'entry.twig')->getStatusCode());

        // History survives the delete and restore REACTIVATES.
        $versions = $this->json($this->api()->versions(Request::create('/x', 'GET'), 'entry.twig'))['data']['versions'];
        self::assertCount(2, $versions);
        $old = $versions[1]['uuid'];
        $one = $this->json($this->api()->showVersion(Request::create('/x', 'GET'), 'entry.twig', $old));
        self::assertSame('one', $one['data']['source']);

        $res = $this->api()->restore($this->putReq(''), 'entry.twig', $old);
        self::assertSame(200, $res->getStatusCode());
        $repo = new TemplateRepository($this->connection());
        self::assertSame('one', $repo->findCurrentSource('default', 'entry.twig')['source']);
        self::assertCount(3, $repo->versions('default', 'entry.twig')); // restore = append

        self::assertSame(
            404,
            $this->api()->versions(Request::create('/x', 'GET'), 'never-saved.twig')->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->api()->restore($this->putReq(''), 'entry.twig', 'nope00000000')->getStatusCode(),
        );
    }

    public function testRestoreRelintsAgainstTheCurrentPolicy(): void
    {
        $this->api()->save($this->putReq('one'), 'entry.twig');
        $this->api()->save($this->putReq('two'), 'entry.twig');
        $repo = new TemplateRepository($this->connection());
        $versions = $repo->versions('default', 'entry.twig');
        $old = $versions[1]['uuid'];

        // Mutate the OLD version around the API (stands in for a version predating a
        // policy tightening): restore must 422 and change nothing.
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $old)
            ->update(['source' => "{{ constant('X') }}"]);

        $res = $this->api()->restore($this->putReq(''), 'entry.twig', $old);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame(1, $this->json($res)['errors'][0]['line']);
        self::assertSame('two', $repo->findCurrentSource('default', 'entry.twig')['source']); // unchanged
        self::assertCount(2, $repo->versions('default', 'entry.twig'));                        // no append
    }

    public function testRouteGrammarAndPermissions(): void
    {
        // Triple gate: every route carries the permission middleware.
        foreach (
            [
                ['GET', '/v1/admin/render/templates'],
                ['GET', '/v1/admin/render/templates/{path}'],
                ['PUT', '/v1/admin/render/templates/{path}'],
                ['DELETE', '/v1/admin/render/templates/{path}'],
                ['GET', '/v1/admin/render/templates/{path}/versions'],
                ['GET', '/v1/admin/render/templates/{path}/versions/{uuid}'],
                ['POST', '/v1/admin/render/templates/{path}/versions/{uuid}/restore'],
            ] as [$method, $path]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "missing route {$method} {$path}");
            self::assertContains(
                'lemma_permission:templates.manage',
                (array) ($route['middleware'] ?? []),
                "permission missing on {$method} {$path}",
            );
        }

        // THE characterization (spec §6): …/entry/blog.twig/versions matches the
        // HISTORY route, not the generic show with path="entry/blog.twig/versions".
        $router = $this->container()->get(Router::class);
        $match = $router->match(Request::create('/v1/admin/render/templates/entry/blog.twig/versions', 'GET'));
        self::assertNotNull($match);
        $handler = $match['route']->getHandler();
        self::assertSame('versions', $handler[1] ?? null);
        self::assertSame('entry/blog.twig', (string) ($match['params']['path'] ?? ''));
    }
}
```

NOTE: adjust the two `Router::match()` result accessors (`$match['route']->getHandler()`, `$match['params']`) to the actual shape `Router::match(Request): ?array` returns — inspect `vendor/glueful/framework/src/Routing/Router.php:292` and fix the assertions, keeping their MEANING (versions action matched; `path` param excludes `/versions`).

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/TemplatesAdminApiTest.php
```
Expected: FAIL — controller/catalog missing.

- [ ] **Step 3: TemplateCatalog**

`packages/lemma-render/src/Templates/TemplateCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Templates;

/**
 * The merged template listing (spec §6): pack-default files + app-theme files + active
 * DB rows, with per-path origin (db > theme > default — loader precedence). Also reads
 * a FILESYSTEM source (theme file first, pack default second) as the editor's
 * copy-from-disk starting point.
 */
final class TemplateCatalog
{
    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly string $appThemesDir,
        private readonly string $packThemesDir,
    ) {
    }

    /** @return list<array{path:string,origin:string,overridden:bool,updated_at:?string}> */
    public function list(string $theme): array
    {
        $files = [];
        foreach ($this->walk($this->packThemesDir . '/default/templates') as $p) {
            $files[$p] = 'default';
        }
        if ($theme !== 'default') {
            foreach ($this->walk(rtrim($this->appThemesDir, '/') . '/' . $theme . '/templates') as $p) {
                $files[$p] = 'theme';
            }
        }
        $rows = $this->repo->listActive($theme);

        $paths = array_unique([...array_keys($files), ...array_keys($rows)]);
        sort($paths);
        $out = [];
        foreach ($paths as $p) {
            $out[] = [
                'path' => $p,
                'origin' => isset($rows[$p]) ? 'db' : $files[$p],
                'overridden' => isset($rows[$p]),
                'updated_at' => isset($rows[$p]) && $rows[$p] !== '' ? $rows[$p] : null,
            ];
        }
        return $out;
    }

    /** @return array{source:string,origin:string}|null filesystem read (db handled by caller) */
    public function readFile(string $theme, string $path): ?array
    {
        if ($theme !== 'default') {
            $themeFile = rtrim($this->appThemesDir, '/') . '/' . $theme . '/templates/' . $path;
            if (is_file($themeFile)) {
                return ['source' => (string) file_get_contents($themeFile), 'origin' => 'theme'];
            }
        }
        $default = $this->packThemesDir . '/default/templates/' . $path;
        if (is_file($default)) {
            return ['source' => (string) file_get_contents($default), 'origin' => 'default'];
        }
        return null;
    }

    /** @return list<string> theme-relative *.twig paths under $dir */
    private function walk(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $out[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
            }
        }
        sort($out);
        return $out;
    }
}
```

- [ ] **Step 4: TemplatesAdminController**

`packages/lemma-render/src/Http/Controllers/TemplatesAdminController.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Http\Controllers;

use Glueful\Events\EventService;
use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator;
use Glueful\Lemma\Render\Templates\TemplateCatalog;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\Templates\TemplateUpdated;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * DB-edited templates admin API (spec §5–§6). Triple-gated at the route layer
 * (capability → auth → lemma_permission:templates.manage). Save = live: syntactic path
 * check → theme check (RenderThemeValidator via the PreviewThemeValidator binding) →
 * policy lint (422 with ALL line-numbered violations) → transactional save →
 * TemplateUpdated (also on delete/restore — every mutation that changes what renders).
 */
final class TemplatesAdminController
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly TemplateLinter $linter,
        private readonly TemplateCatalog $catalog,
        private readonly PreviewThemeValidator $themeValidator,
        private readonly ThemeLocator $activeTheme,
        private readonly EventService $events,
    ) {
    }

    #[ApiOperation(summary: 'List resolvable templates (filesystem + DB) for a theme', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Merged listing with per-path origin (db|theme|default).')]
    public function index(Request $request): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Unknown theme.', 404);
        }
        return Response::success(['theme' => $theme, 'templates' => $this->catalog->list($theme)]);
    }

    #[ApiOperation(summary: 'Current template source (DB override or filesystem)', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Source + origin; filesystem sources are the copy-from-disk start.')]
    #[ApiResponse(404, description: 'Unknown theme, invalid path, or nothing at this path.')]
    public function show(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        $db = $this->templates->findCurrentSource($theme, $path);
        if ($db !== null) {
            return Response::success([
                'path' => $path,
                'theme' => $theme,
                'origin' => 'db',
                'source' => $db['source'],
                'version_uuid' => $db['version_uuid'],
            ]);
        }
        $file = $this->catalog->readFile($theme, $path);
        if ($file === null) {
            return Response::error('Not Found', 404);
        }
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'origin' => $file['origin'],
            'source' => $file['source'],
            'version_uuid' => null,
        ]);
    }

    #[ApiOperation(
        summary: 'Save a template override (create or update; DB-only paths allowed)',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Saved and live; response carries the new version uuid.')]
    #[ApiResponse(404, description: 'Unknown theme.')]
    #[ApiResponse(422, description: 'Invalid path/source, or policy violations ({line, message} list).')]
    public function save(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Unknown theme.', 404);
        }
        if ($this->invalidPath($path)) {
            return Response::error('Invalid template path (relative, no "..", must end in .twig).', 422);
        }
        $body = (array) json_decode((string) $request->getContent(), true);
        $source = $body['source'] ?? null;
        if (!is_string($source)) {
            return Response::error('source must be a string.', 422);
        }
        $violations = $this->linter->lint($source, $path);
        if ($violations !== []) {
            return new Response([
                'success' => false,
                'message' => 'Template policy violations.',
                'errors' => $violations,
            ], 422);
        }
        $ids = $this->templates->save($theme, $path, $source, $this->userUuid($request));
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'version_uuid' => $ids['version_uuid'],
        ], 'Template saved.');
    }

    #[ApiOperation(
        summary: 'Delete the override (deactivate — history preserved), fall back to filesystem',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Override deactivated.')]
    #[ApiResponse(404, description: 'No active override at this path.')]
    public function delete(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        if (!$this->templates->deactivate($theme, $path)) {
            return Response::error('No active override at this path.', 404);
        }
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success(['path' => $path, 'theme' => $theme], 'Override removed.');
    }

    #[ApiOperation(summary: 'Version history (newest first; survives delete)', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Versions: {uuid, created_by, created_at, current}.')]
    #[ApiResponse(404, description: 'This path has never been saved.')]
    public function versions(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        if ($this->templates->find($theme, $path) === null) {
            return Response::error('This path has no saved versions.', 404);
        }
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'versions' => $this->templates->versions($theme, $path),
        ]);
    }

    #[ApiOperation(summary: 'One version\'s source', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'The immutable stored source.')]
    #[ApiResponse(404, description: 'Unknown version for this path.')]
    public function showVersion(Request $request, string $path, string $uuid): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        $version = $this->templates->findVersion($theme, $path, $uuid);
        if ($version === null) {
            return Response::error('Unknown version for this path.', 404);
        }
        return Response::success(['path' => $path, 'theme' => $theme] + $version);
    }

    #[ApiOperation(
        summary: 'Restore a version (append-as-new-current; reactivates a deleted override)',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Restored; response carries the NEW version uuid.')]
    #[ApiResponse(404, description: 'Unknown version for this path.')]
    #[ApiResponse(422, description: 'The stored version violates the CURRENT policy ({line, message} list).')]
    public function restore(Request $request, string $path, string $uuid): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || $this->invalidPath($path)) {
            return Response::error('Not Found', 404);
        }
        $version = $this->templates->findVersion($theme, $path, $uuid);
        if ($version === null) {
            return Response::error('Unknown version for this path.', 404);
        }
        // Re-lint against TODAY'S policy (spec §4): old versions can predate a policy
        // tightening or have been mutated around the API — restore must not put a
        // template live that a fresh save would reject. Same 422 payload as save().
        $violations = $this->linter->lint($version['source'], $path);
        if ($violations !== []) {
            return new Response([
                'success' => false,
                'message' => 'Template policy violations.',
                'errors' => $violations,
            ], 422);
        }
        $ids = $this->templates->save($theme, $path, $version['source'], $this->userUuid($request));
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'version_uuid' => $ids['version_uuid'],
        ], 'Version restored.');
    }

    /** @return string|null resolved theme; null = invalid (caller 404s) */
    private function theme(Request $request): ?string
    {
        $theme = (string) $request->query->get('theme', '');
        if ($theme === '') {
            return $this->activeTheme->activePaths()['name'];
        }
        return $this->themeValidator->isValidTheme($theme) ? $theme : null;
    }

    /**
     * Syntactic only (spec §5): slash-separated segments, each [A-Za-z0-9._-]+ and
     * not "."/"..", ending .twig. Conservative and URL-SAFE by construction — the
     * admin client injects {path} into URLs RAW (slash-preserving), so ?, #, spaces,
     * and every other URL-significant character must be unrepresentable, not merely
     * discouraged. The charset also excludes \, :, and scheme syntax. Empty segments
     * cover leading slashes and "//". DB-only paths are FINE.
     */
    private function invalidPath(string $path): bool
    {
        if ($path === '' || !str_ends_with($path, '.twig')) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1
            ) {
                return true;
            }
        }
        return false;
    }

    private function userUuid(Request $request): ?string
    {
        $user = (array) $request->attributes->get('user', []);
        return isset($user['uuid']) && is_string($user['uuid']) ? $user['uuid'] : null;
    }
}
```

- [ ] **Step 5: Routes + provider registration**

`packages/lemma-render/routes/admin-routes.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Lemma\Render\Http\Controllers\TemplatesAdminController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * DB-edited templates admin API. Triple-gated like the other packs:
 *   1. capability + kill-switch — this file loads only when lemma.render is enabled
 *      AND lemma_render.db_templates is true (else 404).
 *   2. auth — group middleware.
 *   3. lemma_permission — templates.manage on every route.
 *
 * Route grammar (spec §6): {path} spans slashes, so VERSION routes register FIRST and
 * every {path} is constrained to end in .twig — the parser stays deterministic
 * (…/entry/blog.twig/versions can never be swallowed as a generic show).
 */
$router->group(
    ['prefix' => '/v1/admin/render', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->get('/templates/{path}/versions/{uuid}', [TemplatesAdminController::class, 'showVersion'])
            ->where('path', '.+\.twig')
            ->where('uuid', '[A-Za-z0-9_-]{12}')
            ->middleware('lemma_permission:templates.manage');
        $router->post('/templates/{path}/versions/{uuid}/restore', [TemplatesAdminController::class, 'restore'])
            ->where('path', '.+\.twig')
            ->where('uuid', '[A-Za-z0-9_-]{12}')
            ->middleware('lemma_permission:templates.manage');
        $router->get('/templates/{path}/versions', [TemplatesAdminController::class, 'versions'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');

        $router->get('/templates', [TemplatesAdminController::class, 'index'])
            ->middleware('lemma_permission:templates.manage');
        $router->get('/templates/{path}', [TemplatesAdminController::class, 'show'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');
        $router->put('/templates/{path}', [TemplatesAdminController::class, 'save'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');
        $router->delete('/templates/{path}', [TemplatesAdminController::class, 'delete'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');
    },
);
```

(If `Router::where()` does not chain for a second param, use whatever multi-constraint form the framework supports — check `vendor/glueful/framework/src/Routing/Router.php` — keeping BOTH constraints.)

Provider `services()` gains `TemplateCatalog` + `TemplatesAdminController`:

```php
    public static function makeTemplateCatalog(ContainerInterface $container): TemplateCatalog
    {
        $context = $container->get(ApplicationContext::class);
        return new TemplateCatalog(
            $container->get(TemplateRepository::class),
            $context->getBasePath() . '/themes',
            dirname(__DIR__) . '/themes',
        );
    }

    public static function makeTemplatesAdminController(ContainerInterface $container): TemplatesAdminController
    {
        return new TemplatesAdminController(
            $container->get(TemplateRepository::class),
            $container->get(TemplateLinter::class),
            $container->get(TemplateCatalog::class),
            $container->get(PreviewThemeValidator::class),
            $container->get(ThemeLocator::class),
            $container->get(EventService::class),
        );
    }
```

(`dirname(__DIR__)` from `src/LemmaRenderServiceProvider.php` = the pack root, so `…/themes` is the pack themes dir — verify it resolves to `packages/lemma-render/themes`.)

Provider `boot()` — inside the capability gate, in the same `db_templates` conditional as the listener:

```php
                $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
```

- [ ] **Step 6: Run to verify they pass**

```bash
vendor/bin/phpunit tests/Integration/Render/TemplatesAdminApiTest.php
vendor/bin/phpunit tests/Integration/Render/
```
Expected: PASS.

- [ ] **Step 7: Full backend verification + STAGE** *(grouping 1 — backend; commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add packages/lemma-render scripts/run-test-migrations.php \
        tests/Support/LemmaTestCase.php tests/Integration/Render
```

Expected: green (pre-existing single skip only). STOP — when authorized:

```bash
git commit -m "feat(render): DB-edited templates — storage, static policy enforcement, loader, admin API

lemma_render_templates/_versions (append-only history; DELETE deactivates,
restore appends); TemplateLinter AST scan as the sole enforcement (no runtime
sandbox) at save AND at compile via DatabaseTemplateLoader; pack-owned
RenderTemplateLoader (DB-first, no persistent exists-cache — ChainLoader's
\$hasSourceCache breaks DB-only templates) with per-render reset; version-keyed
compile-cache keys (no compiled-cache purging); active-theme-only page+error
cache purge on TemplateUpdated; themed preview sessions load that theme's
overrides; RENDER_DB_TEMPLATES kill-switch; triple-gated admin API with
deterministic {path} route grammar."
```

---

### Task 6: Admin SPA — Templates screen

**Files:**
- Modify: `admin/package.json` (deps), `admin/src/layouts/default.vue` (register module)
- Create: `admin/src/registry/templatesModule.ts`, `admin/src/api/rawPath.ts`, `admin/src/pages/templates/index.vue`, `admin/src/pages/templates/components/TemplateEditor.vue`, `admin/src/pages/templates/components/HistoryPanel.vue`
- Test: `admin/src/pages/templates/__tests__/templates.spec.ts` (follow the existing vitest layout — if page tests live elsewhere, e.g. `admin/tests/`, put it there)

**Interfaces:**
- Consumes: the Task 5 endpoints via the generated `client` (`/render/templates…` after the `/v1/admin` strip); `registerAdminModule` (`site` group, requires `['lemma.render']`).
- Produces: route `/templates` (file-based routing), nav item "Templates".

- [ ] **Step 1: Deps + module registration**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin
pnpm add codemirror @codemirror/language @codemirror/legacy-modes @codemirror/view @codemirror/state
```

`admin/src/registry/templatesModule.ts`:

```ts
import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

const site: NavigationMenuItem[] = [
  { label: 'Templates', icon: 'i-lucide-file-code-2', to: '/templates' },
]

/** Site → Templates (DB-edited theme overrides). Visible only with lemma.render on. */
export function registerTemplatesModule(): void {
  registerAdminModule({ id: 'templates', requires: ['lemma.render'], nav: { site } })
}
```

`admin/src/layouts/default.vue`: import and call `registerTemplatesModule()` exactly where `registerNavigationModule()` is called (same setup block, before the nav computed is read).

- [ ] **Step 2: Raw path serializer**

`{path}` spans slashes; openapi-fetch's default path serializer runs `encodeURIComponent` on params, which would send `entry%2Fblog.twig`. `admin/src/api/rawPath.ts`:

```ts
/**
 * Path serializer for slash-spanning {path} params (template endpoints): substitutes
 * {path} RAW and percent-encodes every other param. Raw substitution is safe ONLY
 * because the server pins template paths to slash-separated [A-Za-z0-9._-]+ segments
 * (spec §5) — no ?, #, spaces, or other URL-significant characters can exist in a
 * saved path. Pass per-request:
 * client.GET('/render/templates/{path}', { pathSerializer: rawPath, … }).
 */
export function rawPath(pathname: string, pathParams: Record<string, unknown>): string {
  let out = pathname
  for (const [key, value] of Object.entries(pathParams)) {
    out = out.replace(`{${key}}`, key === 'path' ? String(value) : encodeURIComponent(String(value)))
  }
  return out
}
```

Verify the per-request option name against `node_modules/openapi-fetch/dist/index.d.ts` (`grep -n pathSerializer`) — the dist resolves `requestPathSerializer || globalPathSerializer || defaultPathSerializer`, so a per-request `pathSerializer` fetch option exists; match its exact signature.

- [ ] **Step 3: The page**

`admin/src/pages/templates/index.vue` — list + editor orchestration. Follow `pages/navigation/index.vue` conventions (script setup, typed `client`, `useNotify`, `ApiError`, `data-test` hooks). Complete component:

```vue
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { client } from '@/api/client'
import { rawPath } from '@/api/rawPath'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import TemplateEditor from './components/TemplateEditor.vue'
import HistoryPanel from './components/HistoryPanel.vue'

interface TemplateRow {
  path: string
  origin: 'db' | 'theme' | 'default'
  overridden: boolean
  updated_at: string | null
}
interface Violation { line: number; message: string }

const notify = useNotify()
const theme = ref<string>('')
const templates = ref<TemplateRow[]>([])
const selectedPath = ref<string | null>(null)
const source = ref('')
const origin = ref<string>('')
const editing = ref(false)
const violations = ref<Violation[]>([])
const saving = ref(false)
const historyOpen = ref(false)

const groups = computed(() => {
  const byFamily = new Map<string, TemplateRow[]>()
  for (const t of templates.value) {
    const family = t.path.includes('/') ? t.path.split('/')[0] : 'root'
    if (!byFamily.has(family)) byFamily.set(family, [])
    byFamily.get(family)!.push(t)
  }
  return [...byFamily.entries()].sort(([a], [b]) => a.localeCompare(b))
})

async function loadList() {
  const { data, error } = await client.GET('/render/templates', {
    params: { query: theme.value ? { theme: theme.value } : {} },
  })
  if (error) return notify.error('Failed to load templates')
  theme.value = (data as any).data.theme
  templates.value = (data as any).data.templates
}

async function open(path: string) {
  const { data, error } = await client.GET('/render/templates/{path}', {
    params: { path: { path }, query: theme.value ? { theme: theme.value } : {} },
    pathSerializer: rawPath,
  } as any)
  if (error) return notify.error('Failed to load template')
  selectedPath.value = path
  source.value = (data as any).data.source
  origin.value = (data as any).data.origin
  editing.value = (data as any).data.origin === 'db'
  violations.value = []
}

async function save() {
  if (!selectedPath.value) return
  saving.value = true
  violations.value = []
  const { data, error, response } = await client.PUT('/render/templates/{path}', {
    params: { path: { path: selectedPath.value }, query: theme.value ? { theme: theme.value } : {} },
    body: { source: source.value },
    pathSerializer: rawPath,
  } as any)
  saving.value = false
  if (error) {
    if (response?.status === 422 && Array.isArray((error as any).errors)) {
      violations.value = (error as any).errors
      return
    }
    return notify.error(error instanceof ApiError ? error.message : 'Save failed')
  }
  notify.success('Template saved — live now')
  editing.value = true
  origin.value = 'db'
  await loadList()
}

async function removeOverride() {
  if (!selectedPath.value) return
  const { error } = await client.DELETE('/render/templates/{path}', {
    params: { path: { path: selectedPath.value }, query: theme.value ? { theme: theme.value } : {} },
    pathSerializer: rawPath,
  } as any)
  if (error) return notify.error('Delete failed')
  notify.success('Override removed — filesystem template is live')
  await loadList()
  await open(selectedPath.value)
}

onMounted(loadList)
</script>

<template>
  <div class="flex gap-6 p-6" data-test="templates-page">
    <aside class="w-80 shrink-0 space-y-4">
      <div v-for="[family, rows] in groups" :key="family">
        <h3 class="text-sm font-semibold text-muted mb-1">{{ family }}</h3>
        <ul>
          <li v-for="t in rows" :key="t.path">
            <button
              class="w-full text-left px-2 py-1 rounded hover:bg-elevated text-sm"
              :class="{ 'bg-elevated': t.path === selectedPath }"
              :data-test="`template-item-${t.path}`"
              @click="open(t.path)"
            >
              <span class="truncate">{{ t.path }}</span>
              <UBadge size="xs" :color="t.origin === 'db' ? 'primary' : 'neutral'" class="ml-1">
                {{ t.origin }}
              </UBadge>
            </button>
          </li>
        </ul>
      </div>
    </aside>

    <main v-if="selectedPath" class="flex-1 min-w-0 space-y-3" data-test="template-detail">
      <div class="flex items-center gap-2">
        <h2 class="font-mono text-sm flex-1 truncate">{{ selectedPath }}</h2>
        <UButton
          v-if="origin === 'db'"
          data-test="history-button"
          variant="ghost"
          icon="i-lucide-history"
          label="History"
          @click="historyOpen = true"
        />
        <UButton
          v-if="origin === 'db'"
          data-test="delete-override"
          color="error"
          variant="ghost"
          icon="i-lucide-trash-2"
          label="Delete override"
          @click="removeOverride"
        />
        <UButton
          data-test="save-template"
          :loading="saving"
          icon="i-lucide-save"
          label="Save"
          @click="save"
        />
      </div>

      <p v-if="origin !== 'db'" class="text-xs text-muted" data-test="fs-origin-note">
        Filesystem template ({{ origin }}) — saving creates a database override that shadows it.
      </p>

      <TemplateEditor v-model="source" :violations="violations" />

      <ul v-if="violations.length" class="text-sm text-error space-y-1" data-test="violations">
        <li v-for="v in violations" :key="`${v.line}-${v.message}`" data-test="violation">
          Line {{ v.line }}: {{ v.message }}
        </li>
      </ul>

      <HistoryPanel
        v-if="selectedPath"
        v-model:open="historyOpen"
        :theme="theme"
        :path="selectedPath"
        @restored="loadList(); open(selectedPath!)"
      />
    </main>
    <main v-else class="flex-1 grid place-items-center text-muted text-sm">
      Select a template to view or override it.
    </main>
  </div>
</template>
```

- [ ] **Step 4: Editor + history components**

`admin/src/pages/templates/components/TemplateEditor.vue` (CodeMirror 6, Twig via the legacy jinja2 stream mode):

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { EditorView, keymap, lineNumbers } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { StreamLanguage } from '@codemirror/language'
import { jinja2 } from '@codemirror/legacy-modes/mode/jinja2'

const model = defineModel<string>({ required: true })
defineProps<{ violations: { line: number; message: string }[] }>()

const host = ref<HTMLElement | null>(null)
let view: EditorView | null = null

onMounted(() => {
  view = new EditorView({
    parent: host.value!,
    state: EditorState.create({
      doc: model.value,
      extensions: [
        lineNumbers(),
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        StreamLanguage.define(jinja2),
        EditorView.updateListener.of((u) => {
          if (u.docChanged) model.value = u.state.doc.toString()
        }),
      ],
    }),
  })
})

watch(model, (next) => {
  if (view && next !== view.state.doc.toString()) {
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: next } })
  }
})

onBeforeUnmount(() => view?.destroy())
</script>

<template>
  <div
    ref="host"
    class="border border-default rounded-md min-h-[24rem] text-sm font-mono"
    data-test="template-editor"
  />
</template>
```

(If `@codemirror/commands` isn't pulled by the meta-package, `pnpm add @codemirror/commands`.)

`admin/src/pages/templates/components/HistoryPanel.vue`:

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import { client } from '@/api/client'
import { rawPath } from '@/api/rawPath'
import { useNotify } from '@/composables/useNotify'

interface Version { uuid: string; created_by: string | null; created_at: string; current: boolean }

const open = defineModel<boolean>('open', { required: true })
const props = defineProps<{ theme: string; path: string }>()
const emit = defineEmits<{ restored: [] }>()

const notify = useNotify()
const versions = ref<Version[]>([])
const preview = ref<{ uuid: string; source: string } | null>(null)

watch(open, async (isOpen) => {
  if (!isOpen) return
  preview.value = null
  const { data, error } = await client.GET('/render/templates/{path}/versions', {
    params: { path: { path: props.path }, query: { theme: props.theme } },
    pathSerializer: rawPath,
  } as any)
  if (error) return notify.error('Failed to load history')
  versions.value = (data as any).data.versions
})

async function view(uuid: string) {
  const { data, error } = await client.GET('/render/templates/{path}/versions/{uuid}', {
    params: { path: { path: props.path, uuid }, query: { theme: props.theme } },
    pathSerializer: rawPath,
  } as any)
  if (error) return notify.error('Failed to load version')
  preview.value = { uuid, source: (data as any).data.source }
}

async function restore(uuid: string) {
  const { error } = await client.POST('/render/templates/{path}/versions/{uuid}/restore', {
    params: { path: { path: props.path, uuid }, query: { theme: props.theme } },
    pathSerializer: rawPath,
  } as any)
  if (error) return notify.error('Restore failed')
  notify.success('Version restored — live now')
  open.value = false
  emit('restored')
}
</script>

<template>
  <USlideover v-model:open="open" title="Version history">
    <template #body>
      <ul class="space-y-2" data-test="history-list">
        <li
          v-for="v in versions"
          :key="v.uuid"
          class="flex items-center gap-2 text-sm"
          :data-test="`version-${v.uuid}`"
        >
          <span class="flex-1 truncate">
            {{ v.created_at }} <span v-if="v.created_by" class="text-muted">by {{ v.created_by }}</span>
            <UBadge v-if="v.current" size="xs" color="primary" class="ml-1">current</UBadge>
          </span>
          <UButton size="xs" variant="ghost" label="View" @click="view(v.uuid)" />
          <UButton
            v-if="!v.current"
            size="xs"
            variant="ghost"
            color="warning"
            label="Restore"
            :data-test="`restore-${v.uuid}`"
            @click="restore(v.uuid)"
          />
        </li>
      </ul>
      <pre
        v-if="preview"
        class="mt-4 p-3 bg-elevated rounded text-xs overflow-x-auto"
        data-test="version-preview"
      >{{ preview.source }}</pre>
    </template>
  </USlideover>
</template>
```

- [ ] **Step 5: Component test**

Follow the repo's existing vitest conventions (Nuxt UI components are unstubbable — assert `data-test` hooks, never dropdown DOM). Stub `TemplateEditor` (CodeMirror needs DOM APIs jsdom lacks). Test: mock `client.GET`/`client.PUT`; mount the page; assert the list renders items (`template-item-entry.twig`), opening one shows `template-detail`, and a mocked 422 with `errors: [{line: 2, message: 'Filter "raw" is not allowed.'}]` renders a `[data-test="violation"]` containing `Line 2`. Write it in the same style as an existing page spec (copy the nearest `*.spec.ts` harness verbatim, then adapt).

- [ ] **Step 6: Verify**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin
pnpm gen:api        # after Task 7's OpenAPI regen this reruns; run now against current docs/openapi.json if it already carries the new endpoints, else defer failures to Task 7
pnpm type-check
pnpm test
```
Expected: type-check exit 0 (never through a pipe), tests pass. If `gen:api` lacks the new paths (OpenAPI not yet regenerated), regenerate the spec first: `cd .. && php glueful generate:openapi -f --clean && cd admin && pnpm gen:api`.

**No staging yet.**

---

### Task 7: Docs + OpenAPI + full verification + STAGE

**Files:**
- Modify: `packages/lemma-render/README.md`, `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md`; regenerate `docs/openapi.json` + `admin/src/api/*.d.ts`

- [ ] **Step 1: README** — new section after "Preview in the theme":

```markdown
## DB-edited templates

Admins with `templates.manage` can override any template the active theme resolves —
or create new hierarchy templates (`entry/interview.twig`) that don't exist on disk —
from the admin Templates screen. Overrides are stored per theme with **append-only
version history** (restore any version; deleting an override falls back to the
filesystem and keeps history). Saves go live immediately: every save is
**statically policy-checked** (allowlisted tags/filters/functions/tests, constant
include/extends targets, no method calls, no `raw`) with line-numbered errors, and
checked again at compile time, so rows written around the API never execute. There is
no runtime sandbox — enforcement is the AST scan plus the arrays-only render context.
`RENDER_DB_TEMPLATES=false` is the ops kill-switch (pure filesystem rendering, admin
routes off). Active-theme saves purge the page cache; per-preview themed sessions see
that theme's overrides, so you can author against an inactive theme and preview it.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]` → `### Added`** (prepend):

```markdown
- **DB-edited templates**: theme templates editable from the admin (new Templates
  screen with CodeMirror editor, per-template version history, restore, delete-with-
  fallback). Storage is per-theme + append-only (`lemma_render_templates` /
  `_versions`); a pack-owned DB-first loader (deliberately not Twig's `ChainLoader`,
  whose persistent exists-cache breaks DB-only templates) with per-render reset and
  version-keyed compile-cache keys (no compiled-cache purging). Enforcement is a
  static AST policy scan (`TemplateLinter`) at save (422 with line numbers) and at
  compile — no runtime sandbox; `raw`, macros, arrow-function filters, dynamic
  include/extends targets, and method calls are denied. Active-theme mutations purge
  the render page + error caches; themed preview sessions render that theme's
  overrides. Kill-switch: `RENDER_DB_TEMPLATES`. New permission: `templates.manage`.
```

- [ ] **Step 3: Trackers** — `docs/V2_DESIGN.md` §6: change the line `- DB-edited templates / Twig-sandbox admin overrides` to:

```markdown
- ✅ DB-edited templates — **shipped 2026-07-03**
  (`docs/superpowers/specs/2026-07-03-db-edited-templates-design.md`)
```

`docs/NEXT.md`: append to the render follow-ups block, same style as the earlier ✅ notes:

```markdown
   ✅ **DB-edited templates** also shipped (2026-07-03): admin-editable overrides with
   history + static policy scan. Spec:
   `docs/superpowers/specs/2026-07-03-db-edited-templates-design.md`.
```

- [ ] **Step 4: OpenAPI + admin regen + assertions**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
php glueful generate:openapi -f --clean
python3 -c "
import json; spec = json.load(open('docs/openapi.json'))
paths = spec['paths']
base = '/v1/admin/render/templates'
assert base in paths, 'template index route missing'
assert base + '/{path}' in paths and 'put' in paths[base + '/{path}'], 'template save route missing'
assert base + '/{path}/versions/{uuid}/restore' in paths, 'restore route missing'
assert not any(p.startswith('/_preview') for p in paths), 'preview routes leaked'
print('openapi OK,', len(paths), 'paths')
"
cd admin && pnpm gen:api && pnpm type-check && pnpm test && cd ..
```

- [ ] **Step 5: Full verification + STAGE** *(grouping 2 — SPA + docs; commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add packages/lemma-render/README.md CHANGELOG.md docs/V2_DESIGN.md docs/NEXT.md \
        docs/openapi.json admin/src admin/package.json admin/pnpm-lock.yaml
```

Expected: green (pre-existing single skip only). STOP — when authorized:

```bash
git commit -m "feat(admin): Templates screen + docs for DB-edited templates

CodeMirror editor with line-numbered policy violations, per-template history
with view/restore, delete-override-with-fallback; Site nav module gated on
lemma.render; raw path serializer for slash-spanning {path} params; OpenAPI +
generated client refresh; README/CHANGELOG/tracker updates."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 pack ownership/triple gate → Tasks 1+5; §2 tables/lifecycle (deactivate-preserves-history, reactivate-continues-history, restore-appends) → Tasks 1+5; §3 composite loader (ChainLoader exclusion + regression test), per-render reset, version cache keys, themed-session DB loader keyed to the LOCATOR-RESOLVED name, byte-identical no-DB path → Tasks 3–4; §4 linter-as-engine, both scan points, allowlists verbatim, constant-target pin, default-deny nodes → Task 2 (+ loader scan in Task 3, SQL-bypass tests in Tasks 3–4); §5 save pipeline order, event on all three mutations, active-theme-only purge incl. RenderErrorCache → Tasks 4–5; §6 route grammar pins + characterization + error semantics (DB-only PUT allowed, GET-nothing 404) → Task 5; §7 kill-switch → Tasks 3 (loader seam) + 4 (controller/provider config reads) + 5 (routes conditional; boot-level off-state is not separately bootable in the single-boot harness — the seam tests stand in, same limitation the capability tests live with); §8 SPA → Task 6; §9 consolidated errors → Tasks 4–5 tests; §10 test list → all mapped.
- **Type consistency:** repository method names/returns match every consumer (loader map/`findCurrentSource`, controller `find`/`versions`/`findVersion`/`save`/`deactivate`, catalog `listActive`); `resetForRender()` is the single reset entry point (controller + tests); `TemplateUpdated(theme, path)` matches dispatcher and listener; `lint()` violation shape `{line, message}` matches the 422 payload and the SPA's `Violation` type.
- **Judgement calls, stated:** Twig node-class allowlist completion is a controlled TDD loop with review criteria (class names verified against the installed Twig, not guessed); `Router::match()` result shape and `->where()` chaining are verify-don't-guess steps with the assertions' meaning pinned; openapi-fetch per-request `pathSerializer` verified against the installed dist; the SPA `as any` casts on `pathSerializer` calls are acceptable if the generated types don't surface the option — prefer typed if they do.
