# lemma-navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `glueful/lemma-navigation` capability pack (menus as data: storage, resolution, admin API, SPA editor) plus the two `lemma-contracts` seams (`MenuReader`, `EntryTargetResolver`), per `docs/superpowers/specs/2026-07-02-lemma-navigation-design.md`.

**Architecture:** A removable pack owning `navigation_menus`/`navigation_items`, resolving menu trees at read time (per-locale label fallback, published-only filtering via `EntryTargetResolver`), an atomic lock_version-guarded whole-tree PUT, a rate-limited public endpoint, and a capability-gated SPA editor. Core gains one contract implementation (`EngineEntryTargetResolver`).

**Tech Stack:** PHP 8.3 / Glueful (existing), Postgres test harness, Vue 3 + Nuxt UI + Pinia Colada, PHPUnit 10, Vitest.

## Global Constraints

- The spec is the contract: `docs/superpowers/specs/2026-07-02-lemma-navigation-design.md`. Re-read it first. Parent: `docs/V2_DESIGN.md`.
- Pack namespace `Glueful\Lemma\Navigation\`; depends on `glueful/lemma-contracts` + `glueful/framework` ONLY. Never `App\*` or the literal sequence `App\` anywhere in `packages/lemma-navigation/src/` — including comments (`composer boundaries` regexes catch doc text; lemma-workflow hit this).
- NO `enabled` config key; capability `lemma.navigation` via the switchboard only.
- PHP: `use` imports, phpcs 120 cols clean, `declare(strict_types=1)`; timestamps `gmdate('Y-m-d H:i:s')`.
- Pack conventions: flat `migrations/` at root, `MigrationPriority::DEPENDENT`, idempotent guards, no cross-package FKs; permissions seeded by pack, administrator granted via `database/dependent-migrations/`; triple-gated routes.
- Test harness facts (learned in lemma-workflow): register the pack's migration path in `scripts/run-test-migrations.php` (it hand-lists paths); run `composer test:migrate` before the smoke test; add new tables to `LemmaTestCase::TABLES` truncate list; rebuild the extension cache after provider/services changes (`php glueful extensions:cache`).
- Commits: on `dev`, batched at the 3 marked commit points. No AI attribution trailers.
- SPA: query modules follow `admin/src/queries/seo.ts` (authFetch); specs assert `data-test` hooks; do NOT pipe vue-tsc through tail.

## File Map

| Area | Files |
|---|---|
| Contracts | `packages/lemma-contracts/src/Navigation/MenuReader.php`, `packages/lemma-contracts/src/Delivery/EntryTargetResolver.php` |
| Core impl | `app/Content/Delivery/EngineEntryTargetResolver.php`, `app/Providers/LemmaServiceProvider.php` (binding) |
| Pack | `packages/lemma-navigation/{composer.json,README.md,routes/{admin-routes,public-routes}.php,migrations/00{1,2,3}_*.php,src/*}` |
| Pack src | `LemmaNavigationServiceProvider.php`, `MenuRepository.php`, `MenuResolver.php`, `Events/MenuUpdated.php`, `Http/{MenuCreateDTO,MenuTreeDTO}.php`, `Http/Controllers/{NavigationAdminController,MenuController}.php` |
| App wiring | root `composer.json`, `config/extensions.php`, `scripts/run-test-migrations.php`, `database/dependent-migrations/010_GrantNavigationPermissionsToAdministrator.php`, `tests/Support/LemmaTestCase.php` (TABLES) |
| Backend tests | `tests/Integration/Navigation/{EntryTargetResolverTest,NavigationCapabilityTest,NavigationMigrationSmokeTest,MenuResolutionTest,NavigationApiTest,NavigationRemovabilityTest}.php` |
| SPA | `admin/src/queries/navigation.ts`, `admin/src/queries/keys.ts`, `admin/src/registry/navigationModule.ts`, `admin/src/layouts/default.vue`, `admin/src/pages/navigation/index.vue`, `admin/src/pages/navigation/components/MenuTreeEditor.vue`, `admin/src/__tests__/navigation*.spec.ts` |

---

### Task 1: Contracts + EngineEntryTargetResolver

**Files:**
- Create: `packages/lemma-contracts/src/Navigation/MenuReader.php`
- Create: `packages/lemma-contracts/src/Delivery/EntryTargetResolver.php`
- Create: `app/Content/Delivery/EngineEntryTargetResolver.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (bind `EntryTargetResolver` — next to the `DraftSummaryReader` binding)
- Test: `tests/Integration/Navigation/EntryTargetResolverTest.php`

**Interfaces:**
- Produces: `MenuReader::menu(string $slug, string $locale): ?array` (resolved tree or null); `EntryTargetResolver::resolve(string $entryUuid, string $locale): array{status: string, path: ?string}` with status ∈ published|unpublished|deleted|missing|routeless, path non-null iff published.

- [ ] **Step 1: Failing test** — `tests/Integration/Navigation/EntryTargetResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;

final class EntryTargetResolverTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    private function resolver(): EntryTargetResolver
    {
        return $this->container()->get(EntryTargetResolver::class);
    }

    public function testPublishedEntryResolvesToPath(): void
    {
        $entry = $this->seedBilingualPublishedEntry(); // blog/hello (en), blog/bonjour (fr)
        $r = $this->resolver()->resolve($entry, 'en');
        self::assertSame('published', $r['status']);
        self::assertStringContainsString('/blog/hello', (string) $r['path']);
    }

    public function testDraftOnlyEntryIsUnpublished(): void
    {
        // Seed a fresh draft-only entry in the seeded 'blog' type.
        $this->seedBilingualPublishedEntry();
        $entries = $this->container()->get(\App\Content\Repositories\EntryRepository::class);
        $types = $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');

        $r = $this->resolver()->resolve($draft, 'en');
        self::assertSame('unpublished', $r['status']);
        self::assertNull($r['path']);
    }

    public function testSoftDeletedEntryIsDeleted(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->container()->get(\App\Content\Repositories\EntryRepository::class)->softDelete($entry);
        self::assertSame('deleted', $this->resolver()->resolve($entry, 'en')['status']);
    }

    public function testUnknownEntryIsMissing(): void
    {
        self::assertSame('missing', $this->resolver()->resolve('nope00000000', 'en')['status']);
    }

    public function testPublishedWithoutRouteIsRouteless(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        // Remove the public route while the publication pin remains.
        $this->container()->get(\App\Content\Repositories\RouteRepository::class)
            ->remove($entry, 'en');

        $r = $this->resolver()->resolve($entry, 'en');
        self::assertSame('routeless', $r['status']);
        self::assertNull($r['path']);
    }
}
```

(If `ContentTypeRepository::findBySlug()` differs, mirror whatever lookup `SeedsPublishedContent` uses — the seeder file is authoritative for repo call shapes.)

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Integration/Navigation/EntryTargetResolverTest.php` → interface not found.

- [ ] **Step 3: The contracts**

`packages/lemma-contracts/src/Navigation/MenuReader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Navigation;

/**
 * Resolved, published-only navigation for render/frontends. Implemented by the
 * lemma-navigation pack; consumers treat absence (or null) as "no menu" — the render
 * pack's menu() helper yields [] so nothing hard-depends on navigation being installed.
 */
interface MenuReader
{
    /**
     * @return list<array{label:string, url:string, entry:?string, children:list<mixed>}>|null
     *   null when no such menu (or the capability is disabled).
     */
    public function menu(string $slug, string $locale): ?array;
}
```

`packages/lemma-contracts/src/Delivery/EntryTargetResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Published-semantics target check for a single entry+locale — the read navigation
 * needs per menu item and render's path() helper reuses. Statuses:
 *   published   — pinned publication AND a public route (addressable); path resolved
 *   routeless   — pinned publication but NO route: live content that cannot be linked
 *                 until a route is assigned (actionable editor state)
 *   unpublished — entry exists (draft) but has no publication in this locale
 *   deleted     — soft-deleted entry
 *   missing     — no such entry
 * path is null for EVERY non-published status — no consumer can produce a dead link.
 */
interface EntryTargetResolver
{
    /**
     * @return array{status: 'published'|'unpublished'|'deleted'|'missing'|'routeless',
     *   path: ?string}  path is non-null iff status is 'published'
     */
    public function resolve(string $entryUuid, string $locale): array;
}
```

- [ ] **Step 4: Core implementation** — `app/Content/Delivery/EngineEntryTargetResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Seo\PathRenderer;
use Glueful\Database\Connection;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;

/** Engine-backed EntryTargetResolver over entries/publications/routes/content_types. */
final class EngineEntryTargetResolver implements EntryTargetResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PathRenderer $paths,
    ) {
    }

    public function resolve(string $entryUuid, string $locale): array
    {
        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)->first();
        if ($entry === null) {
            return ['status' => 'missing', 'path' => null];
        }
        if (($entry['status'] ?? null) === 'deleted') {
            return ['status' => 'deleted', 'path' => null];
        }

        $publication = $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($publication === null) {
            return ['status' => 'unpublished', 'path' => null];
        }
        $route = $this->db->table('entry_routes')->select(['slug'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        // Published-but-routeless: live content that cannot be linked until a route is
        // assigned. Distinct status so the menu editor can say "assign a route" rather
        // than "publish this"; path stays null so no consumer renders a dead link.
        if ($route === null) {
            return ['status' => 'routeless', 'path' => null];
        }

        $type = $this->db->table('content_types')->select(['slug'])
            ->where('uuid', '=', (string) $entry['content_type_uuid'])->first();
        $path = $this->paths->render((string) ($type['slug'] ?? ''), $locale, (string) $route['slug']);
        return ['status' => 'published', 'path' => $path];
    }
}
```

(Check the actual column names of `entry_publications`/`entry_routes` in `database/migrations/005_*`/`006_*` before finalizing; adjust the where clauses to match. `PathRenderer` is container-built with the app's route template/config — resolve it, don't construct it.)

- [ ] **Step 5: Bind in LemmaServiceProvider** — next to the `DraftSummaryReader` binding:

```php
            \Glueful\Lemma\Contracts\Delivery\EntryTargetResolver::class => [
                'class'    => \App\Content\Delivery\EngineEntryTargetResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
```

(with `use` imports per file style). If `PathRenderer` is not autowirable (constructor takes config scalars), use a small factory resolving it from the container the way existing factories do (`makeContentDeliveryReader` neighborhood shows the pattern).

- [ ] **Step 6: Run** — the four tests PASS; `vendor/bin/phpcs -q packages/lemma-contracts/src/ app/Content/Delivery/EngineEntryTargetResolver.php tests/Integration/Navigation/` clean. (No commit yet — Commit 1 closes Task 2.)

---

### Task 2: Pack skeleton + capability + app wiring (COMMIT 1)

**Files:**
- Create: `packages/lemma-navigation/composer.json`, `packages/lemma-navigation/src/LemmaNavigationServiceProvider.php`
- Modify: root `composer.json` (path repo after lemma-seo? alphabetical: after lemma-importers → keep alphabetical: `lemma-navigation` between importers and search), `config/extensions.php` (append provider), `scripts/run-test-migrations.php` (add path)
- Test: `tests/Integration/Navigation/NavigationCapabilityTest.php`

**Interfaces:**
- Produces: capability `lemma.navigation`; provider `Glueful\Lemma\Navigation\LemmaNavigationServiceProvider`.

- [ ] **Step 1: Failing test** (mirror `WorkflowCapabilityTest` exactly, capability id `lemma.navigation`, minus the config assertion — this pack has no config):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class NavigationCapabilityTest extends LemmaTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('lemma.navigation'),
            'lemma.navigation must be registered and enabled by default',
        );
    }
}
```

- [ ] **Step 2: composer.json** — copy `packages/lemma-workflow/composer.json` verbatim with: name `glueful/lemma-navigation`, description `"Navigation menus for Lemma: menu trees as data with published-only resolution, as a removable capability pack."`, psr-4 `"Glueful\\Lemma\\Navigation\\": "src/"`, provider `Glueful\\Lemma\\Navigation\\LemmaNavigationServiceProvider`. Same require block.

- [ ] **Step 3: Provider skeleton** — `packages/lemma-navigation/src/LemmaNavigationServiceProvider.php` (identical shape to the workflow provider at the equivalent stage; no `register()` config merge — no pack config):

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Navigation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaNavigationServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.navigation',
            label: 'Navigation',
            description: 'Menu trees served headless and to themes.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-navigation',
        );
    }
}
```

Create the (empty) `packages/lemma-navigation/migrations/` and `routes/` dirs now.

- [ ] **Step 4: Wire** — root `composer.json`: path repo `{ "type": "path", "url": "packages/lemma-navigation" }` + require `"glueful/lemma-navigation": "*"` (both alphabetical). `config/extensions.php`: append `'Glueful\Lemma\Navigation\LemmaNavigationServiceProvider',`. `scripts/run-test-migrations.php`: add after the lemma-seo block:

```php
$manager->addMigrationPath(
    $root . '/packages/lemma-navigation/migrations',
    MigrationPriority::DEPENDENT,
    'lemma-navigation'
);
```

Run: `composer update glueful/lemma-navigation` then `php glueful extensions:cache` (expect 15 providers).

- [ ] **Step 5: Test passes** — capability test green.

- [ ] **Step 6: COMMIT 1** (includes the approved design docs still uncommitted):

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add docs/V2_DESIGN.md docs/NEXT.md docs/superpowers/specs/2026-07-02-lemma-navigation-design.md \
  docs/superpowers/plans/2026-07-02-lemma-navigation.md \
  packages/lemma-contracts/src/Navigation/ packages/lemma-contracts/src/Delivery/EntryTargetResolver.php \
  app/Content/Delivery/EngineEntryTargetResolver.php app/Providers/LemmaServiceProvider.php \
  packages/lemma-navigation/ composer.json composer.lock config/extensions.php \
  scripts/run-test-migrations.php tests/Integration/Navigation/
git commit -m "V2 rendered-delivery design + lemma-navigation contracts and skeleton

- docs/V2_DESIGN.md: rendered-delivery decision set (in-process SSR packs,
  lowest-priority catch-all into PublicRouteResolver, filesystem Twig
  themes, tag-invalidated render cache as its own subproject); NEXT.md
  updated to point at it.
- lemma-navigation spec + plan (V2 sub-project 1).
- lemma-contracts: MenuReader (soft-consumed by render later) and
  EntryTargetResolver (published|unpublished|deleted|missing|routeless +
  path; published means ADDRESSABLE: publication AND route, routeless =
  live but unlinkable until a route is assigned; path null for every
  non-published status).
- Core EngineEntryTargetResolver over entries/publications/routes.
- glueful/lemma-navigation pack skeleton: capability lemma.navigation,
  DEPENDENT migrations dir, wired via path repo + allow-list."
```

---

### Task 3: Migrations + grants

**Files:**
- Create: `packages/lemma-navigation/migrations/001_CreateNavigationMenusTable.php`, `002_CreateNavigationItemsTable.php`, `003_SeedNavigationPermissions.php`
- Create: `database/dependent-migrations/010_GrantNavigationPermissionsToAdministrator.php`
- Modify: `tests/Support/LemmaTestCase.php` (TABLES: prepend `'navigation_items', 'navigation_menus',`)
- Test: `tests/Integration/Navigation/NavigationMigrationSmokeTest.php`

**Interfaces:**
- Produces: tables per spec §3 (`navigation_menus` incl. `lock_version` int default 0; `navigation_items` with labels json + soft entry_uuid); permission `navigation.manage` granted to administrator.

- [ ] **Step 1: Failing smoke test** — mirror `WorkflowMigrationSmokeTest` with tables `['navigation_menus', 'navigation_items']` and the single slug `navigation.manage` in the administrator-grant assertion.

- [ ] **Step 2: Menus migration** — `001_CreateNavigationMenusTable.php` (workflow migration 001 is the shape template):

```php
        $schema->createTable('navigation_menus', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('slug', 64);
            $table->string('name', 120);
            // Optimistic concurrency for whole-tree PUTs (spec §5): stale version → 409.
            $table->integer('lock_version')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('slug', 'uniq_navigation_menu_slug');
            $table->unique('uuid');
        });
```

- [ ] **Step 3: Items migration** — `002_CreateNavigationItemsTable.php`:

```php
        $schema->createTable('navigation_items', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('menu_uuid', 12);
            $table->string('parent_uuid', 12)->nullable();
            $table->integer('position')->default(0);
            // entry (soft reference — no cross-package FK) | url
            $table->string('kind', 8);
            $table->string('entry_uuid', 12)->nullable();
            $table->string('url', 1024)->nullable();
            // locale → label; resolution falls back requested → default locale → any.
            $table->json('labels');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['menu_uuid', 'parent_uuid', 'position'], 'idx_navigation_items_tree');
        });
```

- [ ] **Step 4: Permission seed + grant** — `003_SeedNavigationPermissions.php`: copy `packages/lemma-workflow/migrations/003_SeedWorkflowPermissions.php` with `PERMISSIONS = ['navigation.manage' => 'Manage navigation menus']`, category `'navigation'`. `010_GrantNavigationPermissionsToAdministrator.php`: copy `009_GrantWorkflowPermissionsToAdministrator.php` with the same single-permission constant and description `'Grant navigation.manage to the administrator role.'`.

- [ ] **Step 5: Migrate + verify** — `composer test:migrate` (expect the 3 pack migrations + 010 pending → applied), smoke test PASS.

---

### Task 4: MenuRepository + MenuResolver + MenuUpdated event

**Files:**
- Create: `packages/lemma-navigation/src/MenuRepository.php`, `src/MenuResolver.php`, `src/Events/MenuUpdated.php`
- Modify: `packages/lemma-navigation/src/LemmaNavigationServiceProvider.php` (services + MenuReader binding)
- Test: `tests/Integration/Navigation/MenuResolutionTest.php`

**Interfaces (produced — Tasks 5/6 rely on these exact shapes):**
- `MenuRepository::createMenu(string $slug, string $name): array` (menu row) / `findMenu(string $slug): ?array` / `listMenus(): list<array{slug:string,name:string,item_count:int,lock_version:int}>` / `renameMenu(string $slug, string $name): bool` / `deleteMenu(string $slug): bool`
- `MenuRepository::itemsOf(string $menuUuid): list<array<string,mixed>>` (flat rows, tree order) / `replaceTree(string $menuUuid, int $lockVersion, array $flatItems): bool` — single transaction: `UPDATE navigation_menus SET lock_version = lock_version + 1, updated_at = ? WHERE uuid = ? AND lock_version = ?`; **rowCount 0 → return false (stale, caller 409s)**; then delete + bulk-insert items. `$flatItems`: pre-flattened rows with uuid/parent_uuid/position/kind/entry_uuid/url/labels(json string).
- `MenuResolver implements MenuReader`: `menu(slug, locale)` → null when menu unknown OR capability disabled (documented; tags/bindings are compile-time — the lemma-workflow gate precedent); resolved items: label = labels[locale] ?? labels[defaultLocale] ?? first; `url` kind passes through; `entry` kind resolves via `EntryTargetResolver` — `status !== 'published'` drops the item AND its subtree.
- `MenuUpdated extends BaseEvent`, constructor `(public readonly string $menuSlug)`.

- [ ] **Step 1: Failing tests** — `MenuResolutionTest` (truncates `navigation_*` in setUp like the workflow tests truncate theirs; seeds via `MenuRepository` + `SeedsPublishedContent` for a real published entry):

```php
public function testLabelFallbackChain(): void
{
    // labels {fr: 'À propos'} only → en request falls back default(en)→absent→any('À propos')
}

public function testUrlItemsAlwaysServeAndEntryItemsResolvePaths(): void
{
    // url item served verbatim; entry item's url contains /blog/hello
}

public function testNonPublishedSubtreeIsDropped(): void
{
    // parent entry item → draft-only entry, with a url child: BOTH absent from menu();
    // sibling url item still present
}

public function testUnknownMenuIsNull(): void
```

Write these as full tests: create a menu (`createMenu('main', 'Main')`), build `$flatItems` arrays inline (uuid via `Glueful\Helpers\Utils::generateNanoID()`, labels via `json_encode(['fr' => 'À propos'])`), call `replaceTree($menuUuid, 0, $items)`, then assert on `$this->container()->get(\Glueful\Lemma\Contracts\Navigation\MenuReader::class)->menu('main', 'en')`.

- [ ] **Step 2: Run → class not found.**

- [ ] **Step 3: MenuUpdated event** — copy the `ReviewSubmitted` shape: `final class MenuUpdated extends BaseEvent`, constructor `(public readonly string $menuSlug)` calling `parent::__construct()`.

- [ ] **Step 4: MenuRepository** — plain `Connection`-backed like `WorkflowStateRepository`. The two non-obvious methods:

```php
    /** @param list<array<string,mixed>> $flatItems pre-validated flat rows */
    public function replaceTree(string $menuUuid, int $lockVersion, array $flatItems): bool
    {
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            $guard = $pdo->prepare(
                'UPDATE navigation_menus SET lock_version = lock_version + 1, updated_at = ?'
                . ' WHERE uuid = ? AND lock_version = ?'
            );
            $guard->execute([gmdate('Y-m-d H:i:s'), $menuUuid, $lockVersion]);
            if ($guard->rowCount() === 0) {
                $pdo->rollBack();
                return false; // stale lock_version (or vanished menu) — caller 409s
            }
            $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?')->execute([$menuUuid]);
            foreach ($flatItems as $row) {
                $this->db->table('navigation_items')->insert($row + ['menu_uuid' => $menuUuid]);
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> ordered parent-first, then position */
    public function itemsOf(string $menuUuid): array
    {
        $stmt = $this->db->getPDO()->prepare(
            'SELECT uuid, parent_uuid, position, kind, entry_uuid, url, labels'
            . ' FROM navigation_items WHERE menu_uuid = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$menuUuid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
```

`createMenu` inserts with `Utils::generateNanoID()` uuid + lock_version 0 and returns the row; `listMenus` LEFT JOINs an item count; `deleteMenu` removes items then the menu in a transaction.

- [ ] **Step 5: MenuResolver** —

```php
final class MenuResolver implements MenuReader
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CapabilityRegistry $capabilities,
        private readonly MenuRepository $menus,
        private readonly EntryTargetResolver $targets,
    ) {
    }

    public function menu(string $slug, string $locale): ?array
    {
        // Bindings are compile-time, so the disabled check lives here (workflow-gate precedent):
        // disabled capability must look exactly like "pack absent" to consumers.
        if (!$this->capabilities->isEnabled('lemma.navigation')) {
            return null;
        }
        $menu = $this->menus->findMenu($slug);
        if ($menu === null) {
            return null;
        }
        $byParent = [];
        foreach ($this->menus->itemsOf((string) $menu['uuid']) as $row) {
            $byParent[(string) ($row['parent_uuid'] ?? '')][] = $row;
        }
        return $this->children($byParent, '', $locale);
    }

    /** @param array<string, list<array<string,mixed>>> $byParent */
    private function children(array $byParent, string $parent, string $locale): array
    {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $row) {
            $entry = null;
            if ((string) $row['kind'] === 'entry') {
                $entry = (string) $row['entry_uuid'];
                $target = $this->targets->resolve($entry, $locale);
                if ($target['status'] !== 'published') {
                    continue; // spec §4: drop the item AND its subtree
                }
                $url = (string) $target['path'];
            } else {
                $url = (string) $row['url'];
            }
            $out[] = [
                'label' => $this->label($row, $locale),
                'url' => $url,
                'entry' => $entry,
                'children' => $this->children($byParent, (string) $row['uuid'], $locale),
            ];
        }
        return $out;
    }

    private function label(array $row, string $locale): string
    {
        $labels = json_decode((string) $row['labels'], true);
        if (!is_array($labels) || $labels === []) {
            return '';
        }
        $default = (string) config($this->context, 'i18n.default_locale', 'en');
        return (string) ($labels[$locale] ?? $labels[$default] ?? reset($labels));
    }
}
```

- [ ] **Step 6: Register** — provider `services()`: `MenuRepository` (autowired shared), `MenuResolver` (factory resolving context/CapabilityRegistry/MenuRepository/EntryTargetResolver — same dual-lookup style as workflow's factories but all four are plain container gets), and the contract alias `MenuReader::class => ['class' => MenuResolver::class ...]`? Bind the interface to the same shared instance: `\Glueful\Lemma\Contracts\Navigation\MenuReader::class => ['factory' => [self::class, 'makeMenuResolver'], 'shared' => true]` and `MenuResolver::class` referencing the same factory. Rebuild extension cache.

- [ ] **Step 7: Tests pass; phpcs clean.**

---

### Task 5: HTTP API — DTOs, controllers, routes (COMMIT 2)

**Files:**
- Create: `packages/lemma-navigation/src/Http/MenuCreateDTO.php`, `src/Http/MenuTreeDTO.php`, `src/Http/Controllers/NavigationAdminController.php`, `src/Http/Controllers/MenuController.php`, `routes/admin-routes.php`, `routes/public-routes.php`
- Modify: provider (controllers + `loadRoutesFrom` both files inside the `isEnabled` block; dispatch `MenuUpdated`)
- Test: `tests/Integration/Navigation/NavigationApiTest.php`

**Interfaces:**
- Consumes: Task 4's repository/resolver signatures verbatim; `EntryTargetResolver` for write-time target checks; `Response::success/error`; actor pattern (`user` attribute) if needed for audit (not required v1).
- Produces routes per spec §5.

- [ ] **Step 1: Failing tests** — `NavigationApiTest` (container controllers driven directly, workflow-API style; truncate navigation tables in setUp). Cover, as separate test methods with full bodies:
  - create menu → 200 with row incl. `lock_version: 0`; duplicate slug → 409; bad slug (`Main Menu!`) → ValidationException.
  - `PUT items` happy path: tree of url + published-entry items with lock_version 0 → 200; admin `GET /menus/{slug}?locale=en` returns unfiltered tree with `target_status`/`target_url` per entry item + bumped `lock_version` 1.
  - locale-sensitive badges: with the bilingual seed entry, unpublish `fr` (or seed an en-only entry) → admin show with `?locale=en` reports `published`, with `?locale=fr` reports `unpublished` for the SAME item (echoed `locale` matches the request).
  - stale lock_version (repeat the same PUT with 0) → 409.
  - validation matrix in one method with try/fail/catch blocks: unknown kind, depth > 6, > 500 items (generate programmatically), `javascript:alert(1)` url, missing-entry uuid, deleted-entry uuid (soft-delete a seeded entry first) — each `ValidationException` or 422; **unpublished entry target is ACCEPTED** (assert 200).
  - public endpoint via container `MenuController::show`: published-only tree, unknown menu 404.
  - `testRoutesAreRegisteredWithPermissions`: admin routes carry `lemma_permission:navigation.manage`; public route carries `rate_limit`.

- [ ] **Step 2: Run → controllers missing.**

- [ ] **Step 3: DTOs.** `MenuCreateDTO::fromRequest(array $body): self` — manual Validator (workflow's `WorkflowNoteDTO` is the template): `slug` [Required, Sanitize(trim), Regex('/^[a-z0-9-]{1,64}$/')], `name` [Required, Sanitize(trim), Type('string'), Length(1, 120)]. `MenuTreeDTO::fromRequest(array $body, EntryTargetResolver $targets): self` — validates `lock_version` (Required, integer ≥ 0) and the recursive `items` structurally in PHP (the Validator handles flat fields; recursion is explicit code):

```php
    /** @return list<array<string,mixed>> flattened rows ready for MenuRepository::replaceTree */
    private static function walk(
        array $items,
        ?string $parent,
        int $depth,
        int &$count,
        EntryTargetResolver $targets,
        array &$errors,
        string $path,
    ): array {
        if ($depth > 6) {
            $errors[$path] = ['menu depth exceeds 6'];
            return [];
        }
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $p = "{$path}{$i}";
            if (++$count > 500) {
                $errors[$p] = ['menu exceeds 500 items'];
                return $rows;
            }
            $kind = $item['kind'] ?? null;
            $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
            foreach ($labels as $loc => $text) {
                if (!is_string($loc) || !is_string($text) || mb_strlen($text) > 200) {
                    $errors["{$p}.labels"] = ['labels must map locale to string ≤ 200'];
                }
            }
            $uuid = Utils::generateNanoID();
            if ($kind === 'entry') {
                $entry = is_string($item['entry_uuid'] ?? null) ? $item['entry_uuid'] : '';
                $status = $entry !== '' ? $targets->resolve($entry, 'en')['status'] : 'missing';
                // missing/deleted are authoring errors; unpublished is allowed (spec §5).
                if (in_array($status, ['missing', 'deleted'], true)) {
                    $errors["{$p}.entry_uuid"] = ["entry target is {$status}"];
                }
                $rows[] = self::row($uuid, $parent, $i, 'entry', $entry, null, $labels);
            } elseif ($kind === 'url') {
                $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
                $ok = preg_match('#^(https?://|/)#', $url) === 1 && mb_strlen($url) <= 1024;
                if (!$ok) {
                    $errors["{$p}.url"] = ['url must be http(s):// or site-relative /… (≤ 1024)'];
                }
                $rows[] = self::row($uuid, $parent, $i, 'url', null, $url, $labels);
            } else {
                $errors["{$p}.kind"] = ['kind must be entry or url'];
                continue;
            }
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $rows = array_merge(
                $rows,
                self::walk($children, $uuid, $depth + 1, $count, $targets, $errors, "{$p}.children."),
            );
        }
        return $rows;
    }
```

`fromRequest` collects `$errors` and throws `ValidationException($errors)` when non-empty; exposes `public readonly int $lockVersion` and `public readonly array $rows`. (Target-status locale for the write-time check: any locale works for missing/deleted detection — they are locale-independent; use the default locale via `config`.)

- [ ] **Step 4: Controllers.** `NavigationAdminController` (constructor: `MenuRepository`, `MenuResolver` no — admin tree is UNfiltered: build it from `itemsOf` + per-entry `EntryTargetResolver` statuses; `EntryTargetResolver`; `EventService` for `MenuUpdated`; `ApplicationContext` for default locale): `index` (list), `create` (201/409 on duplicate slug via existing-check), `show` (menu + nested unfiltered tree with `target_status`/`target_url` + `lock_version`), `rename`, `delete`, `replaceItems` (DTO → `replaceTree`; false → `Response::error('The menu changed since you loaded it. Reload and retry.', 409, ['lock_version' => $current])`; success → dispatch `MenuUpdated($slug)` → return `show` payload). **`show` is locale-aware:** it reads `?locale=` (default via `config('i18n.default_locale')`) and resolves each entry item's `target_status`/`target_url` via `EntryTargetResolver::resolve($entryUuid, $thatLocale)` — status is locale-sensitive (published in `en` can be routeless/unpublished in `fr`), so the badge locale always matches what the editor asked for. The response echoes `locale`. `MenuController::show(Request, string $slug)` (public): locale from `?locale=` default via config; `MenuReader::menu()` → null → 404; else `Response::success(['slug' => $slug, 'locale' => $locale, 'items' => $tree])`.

- [ ] **Step 5: Routes.** `routes/admin-routes.php` — group `['prefix' => '/v1/admin/navigation', 'middleware' => ['auth']]`, every route `->middleware('lemma_permission:navigation.manage')`: `GET /menus`, `POST /menus`, `GET /menus/{slug}`, `PUT /menus/{slug}`, `DELETE /menus/{slug}`, `PUT /menus/{slug}/items`. `routes/public-routes.php` — `GET /v1/menus/{slug}` `->middleware('rate_limit')`. Provider boot (inside `isEnabled`): `loadRoutesFrom` both + controllers registered in `services()` (admin controller via factory for the multi-dep constructor; public controller autowire-able if its deps are container classes).

- [ ] **Step 6: Tests pass; phpcs; full backend suite green** (`vendor/bin/phpunit`).

- [ ] **Step 7: COMMIT 2**

```bash
git add packages/lemma-navigation/ tests/Integration/Navigation/ tests/Support/LemmaTestCase.php \
  database/dependent-migrations/010_GrantNavigationPermissionsToAdministrator.php
git commit -m "lemma-navigation: storage, resolution, admin + public API

- navigation_menus (lock_version optimistic concurrency) + navigation_items
  (per-locale label maps, soft entry references); navigation.manage seeded,
  administrator granted via dependent migration 010.
- MenuResolver implements the MenuReader contract: label fallback chain
  (requested → default → any), published-only filtering via
  EntryTargetResolver with whole-subtree drops, null when the capability is
  disabled (compile-time-binding precedent).
- Whole-tree PUT validated recursively (kinds, URL schemes, depth 6, 500
  items, missing/deleted targets 422 — unpublished allowed) and guarded by
  lock_version (stale → 409). MenuUpdated event on every mutation.
- Public GET /v1/menus/{slug} rate-limited; admin routes triple-gated."
```

---

### Task 6: Removability + docs

**Files:**
- Test: `tests/Integration/Navigation/NavigationRemovabilityTest.php`
- Create: `packages/lemma-navigation/README.md`
- Modify: `CHANGELOG.md` ([Unreleased] Added), `docs/NEXT.md` (mark navigation implementation ✅ shipped, render core next)

- [ ] **Step 1: Removability test** — mirror `WorkflowRemovabilityTest`: disabled-capability app boot (`bootAppWithConfigOverride('lemma', ['capabilities' => ['lemma.navigation' => false]])`) → admin + public routes 404; container `MenuReader::menu('main', 'en')` on the DISABLED app returns null; the boundary sweep over `packages/lemma-navigation/src` (same regex + `assertGreaterThan(5, $checked)` guard).
- [ ] **Step 2: README** — model on `packages/lemma-workflow/README.md`: what it provides (tables, resolution semantics table from spec §4), the contract seams (MenuReader consumed optionally by render per V2 §5; EntryTargetResolver), API table, capability/switchboard, install/remove, out-of-scope list (spec §8).
- [ ] **Step 3: CHANGELOG + NEXT.md** — Added entry covering pack + contracts + SPA (write it to cover Task 7-8 too); NEXT.md: navigation line → ✅ shipped (2026-07-02), next = render core.
- [ ] **Step 4: Verify** — full suite + `composer boundaries` (expect **8 packages checked**) + phpcs. (Commit lands with Task 8's Commit 3.)

---

### Task 7: SPA query module + nav registration

**Files:**
- Create: `admin/src/queries/navigation.ts`, `admin/src/registry/navigationModule.ts`
- Modify: `admin/src/queries/keys.ts` (`navMenus: () => ['navigation','menus']`, `navMenu: (slug, locale) => ['navigation','menu',slug,locale]`), `admin/src/layouts/default.vue` (register module after workflow)
- Test: `admin/src/__tests__/navigationQueries.spec.ts`, `admin/src/__tests__/navigationModule.spec.ts`

**Interfaces (produced for Task 8):**
- Types: `NavMenuSummary {slug,name,item_count,lock_version}`, `NavTreeItem {uuid?,kind:'entry'|'url',entry_uuid?,url?,labels:Record<string,string>,target_status?,target_url?,children:NavTreeItem[]}`, `NavMenuDetail {slug,name,locale,lock_version,items:NavTreeItem[]}`.
- `fetchMenus()`, `fetchMenu(slug, locale)` (GET `.../menus/{slug}?locale=`), `createMenu(slug,name)`, `renameMenu(slug,name)`, `deleteMenu(slug)`, `saveTree(slug, lockVersion, items)` (PUT `/v1/admin/navigation/menus/{slug}/items` body `{lock_version, items}`), hooks `useNavMenus(enabled?)`, `useNavMenu(slug, locale, enabled?)` (key `navMenu(slug, locale)` — a locale switch is a new cache key → automatic refetch), `useNavigationMutations(slug?)` (invalidate both keys on settle).
- Module: `registerNavigationModule()` — id `navigation`, requires `['lemma.navigation']`, nav label `Navigation`, icon `i-lucide-menu`, to `/navigation`.

- [ ] **Steps:** module + query file follow `workflowModule.ts` / `queries/workflow.ts` verbatim patterns (authFetch, envelope unwrap, keys); specs mirror `workflowQueries.spec.ts` (URL shapes incl. the PUT body carrying `lock_version`) and `workflowModule.spec.ts` (gating). Run vitest on both; `npx vue-tsc --noEmit`.

---

### Task 8: SPA navigation page + tree editor (COMMIT 3)

**Files:**
- Create: `admin/src/pages/navigation/index.vue`, `admin/src/pages/navigation/components/MenuTreeEditor.vue`
- Test: `admin/src/__tests__/navigationPage.spec.ts`, `admin/src/__tests__/menuTreeEditor.spec.ts`

**Interfaces:**
- Consumes Task 7's hooks/types exactly.

- [ ] **Step 1: Page** — `/navigation` (definePage requiresAuth): menu list (create form: slug+name; select menu; rename/delete with confirm) + `<MenuTreeEditor :menu="detail" @save="..."/>`. On save: `saveTree(slug, detail.lock_version, items)`; **409 → notify "menu changed since load" + refetch** (the editor re-seeds). `data-test`: `nav-page`, `nav-menu-list`, `nav-menu-create`, `nav-menu-row`.
- [ ] **Step 2: MenuTreeEditor** — props `{ menu: NavMenuDetail; locales: string[] }`; local reactive copy of the tree; per-item: label input for the ACTIVE locale (locale switcher tabs — **the switcher drives BOTH the label being edited and the badge locale**: switching re-runs `useNavMenu(slug, locale)` so `target_status` always reflects the locale on screen; unsaved local edits are preserved by merging fetched `target_*` into the local tree by item uuid, not by replacing the tree), kind-specific field (entry picker: content-type select via `useContentTypes` + entry select via `useEntries(type)`; or URL input), `target_status` badge for entry items (`unpublished`/`routeless`/`deleted`/`missing` distinct colors — routeless copy: "needs a route"), add child / add sibling / remove, and **up/down/indent/outdent buttons** (array splices — no drag-drop dependency, per spec §6). Emit `save` with the serialized tree (strip `target_*`). `data-test` hooks: `tree-item`, `tree-item-label`, `tree-item-up|down|indent|outdent|remove`, `tree-add-root`, `tree-save`, `tree-item-status`.
- [ ] **Step 3: Specs** — page spec (mock queries: list renders, selecting loads editor, 409 path calls refetch + toast); editor spec (mock nothing — pure props/emits: reorder via up/down changes emitted order; indent nests under previous sibling; label edits land in the active locale key; unpublished badge renders from `target_status`). Assert only `data-test` hooks.
- [ ] **Step 4: Verify all** — `npx vitest run` (full SPA suite), `npx vue-tsc --noEmit`, backend full suite, `composer boundaries`.
- [ ] **Step 5: COMMIT 3**

```bash
git add packages/lemma-navigation/README.md CHANGELOG.md docs/NEXT.md admin/src/ \
  tests/Integration/Navigation/NavigationRemovabilityTest.php
git commit -m "lemma-navigation: removability + docs + admin SPA menu builder

- Removability: disabled capability 404s routes and nulls MenuReader;
  boundary sweep + composer boundaries at 8 packages.
- Admin SPA: Navigation page (menu list, create/rename/delete) + tree
  editor (per-locale labels with fallback editing, entry picker with
  target-status badges, url items, up/down/indent/outdent reordering,
  whole-tree save guarded by lock_version with 409 reload handling);
  capability-gated nav module.
- README, CHANGELOG, NEXT.md (navigation shipped; render core is next)."
```

---

## Self-Review Checklist

- Spec §2 contracts → Task 1. §3 storage/permissions → Task 3. §4 semantics → Task 4 (resolver) + Task 5 DTO (write-time rules). §5 API incl. lock_version 409 → Task 5. §6 SPA → Tasks 7–8. §7 tests → every task + Task 6. §8 out-of-scope respected (no visibility rules/drag-drop/regions).
- The `EntryTargetResolver` five-status enum (`routeless` = published without a route, path null) is USER-APPROVED and already reflected in the spec — do not collapse it back to four statuses during execution. The write-time DTO allows `unpublished` AND `routeless`; only `missing`/`deleted` are 422s.
- Type consistency: `replaceTree(menuUuid, lockVersion, flatItems): bool` used by Task 5's controller; DTO produces `rows` matching `replaceTree`'s row shape; SPA `saveTree` body `{lock_version, items}` matches the DTO's expectations.
