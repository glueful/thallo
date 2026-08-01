# Navigation Redesign + Drag Reordering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the admin navigation page onto `UDashboardPanel` with a clear selectable menu list, and add drag-to-reorder for both the tree items (incl. nested children) and the sidebar menus (full-stack).

**Architecture:** Four sequential tasks — (1) backend menu ordering in `thallo-navigation` (fold `position` into the create-table migration, add a validated dense-rewrite reorder endpoint); (2) the admin query layer for reorder; (3) tree-item drag in the recursive `MenuTreeEditor`; (4) the page-shell redesign in `index.vue`, which also hosts the sidebar menu-reorder UI. Each task ends green and independently reviewable.

**Tech Stack:** PHP 8.3 (Glueful framework, `thallo-navigation` pack), phpunit; Vue 3 + Nuxt UI + `@pinia/colada` + `vue-draggable-plus`, vitest.

## Global Constraints

- Work on `dev` directly. No feature branches.
- No AI/Anthropic attribution anywhere (commits, PR text). No `Co-Authored-By`.
- **Hold ALL commits until explicit go-ahead** — implement the "Commit" steps' content but do NOT run them until told. (Standing instruction for this repo.)
- Never stage/commit `CLAUDE.md`.
- **Pre-launch migration fold:** the new `position` column goes into the *original* `001_CreateNavigationMenusTable.php`, NOT a new ALTER migration. Because that migration early-returns when the table exists, both the test DB and the dev DB must be rebuilt to pick it up: test DB via `composer test:reset-db && composer test:migrate`; dev DB via the throwaway sync script in Task 1 Step 12.
- **Preserve every existing `data-test` hook** (relocated, never removed): `nav-page`, `nav-menu-list`, `nav-menu-row`, `nav-menu-create`, `nav-locale-tab`, `nav-menu-delete`, `tree-add-root`, `tree-add-page`, `add-page-picker`, `add-page-type`, `tree-save`, `tree-item-up`, `tree-item-down`, `tree-item-indent`, `tree-item-outdent`, `tree-item-remove`, `tree-item-label`, `tree-item-url`, `tree-item-description`, `tree-item-icon`.
- House patterns: Nuxt UI create dialogs use `UModal` (`#body`/`#footer`) + `UFormField` + inline-disabled submit (see `redirects/index.vue`), not `UForm`/zod. Draggable uses `vue-draggable-plus`'s `VueDraggable` with a `handle` (see `CollectionEditSlideover.vue` flat, `BlockList.vue` nested shared-group).
- Verify commands: backend `composer test:phpunit -- --filter=Navigation` then `composer ci`; admin `pnpm --dir admin type-check && pnpm --dir admin test && pnpm --dir admin lint` (do NOT pipe `tsc`/`vue-tsc` through `tail` — it masks the exit code).

---

## File Structure

**Task 1 — backend (`packages/thallo-navigation/`)**
- `migrations/001_CreateNavigationMenusTable.php` — add `position` column.
- `src/MenuRepository.php` — `createMenu` sets `position`; `listMenus` orders by it; new `reorderMenus`.
- `src/Http/MenuReorderDTO.php` (new) — shape validation of the slug list.
- `src/Http/Controllers/NavigationAdminController.php` — new `reorder` action.
- `routes/admin-routes.php` — `POST /menus/reorder` registered before `/menus/{slug}`.
- `tests/Integration/Navigation/NavigationApiTest.php` (app repo) — reorder + nesting-round-trip tests.

**Task 2 — admin query layer (`admin/`)**
- `src/queries/navigation.ts` — `reorderMenus` fn + `reorder` mutation.
- `src/__tests__/navigationQueries.spec.ts` — reorder query test.

**Task 3 — tree drag (`admin/`)**
- `src/pages/navigation/components/MenuTreeEditor.vue` — drag handle, shared-group `VueDraggable`, always-droppable children, cycle guard.
- `src/__tests__/menuTreeEditor.spec.ts` — handle/container/guard tests.

**Task 4 — page shell + sidebar reorder (`admin/`)**
- `src/pages/navigation/index.vue` — `UDashboardPanel`, sidebar rows, create modal, reconciliation watch, sidebar drag + overflow reorder.
- `src/__tests__/navigationPage.spec.ts` — reconciliation, capability-gate, aria-current, reorder-selection tests.

---

### Task 1: Backend menu ordering + reorder endpoint

**Files:**
- Modify: `packages/thallo-navigation/migrations/001_CreateNavigationMenusTable.php`
- Modify: `packages/thallo-navigation/src/MenuRepository.php`
- Create: `packages/thallo-navigation/src/Http/MenuReorderDTO.php`
- Modify: `packages/thallo-navigation/src/Http/Controllers/NavigationAdminController.php`
- Modify: `packages/thallo-navigation/routes/admin-routes.php`
- Test: `tests/Integration/Navigation/NavigationApiTest.php`

**Interfaces:**
- Consumes: existing `MenuRepository` (`Connection $db`), `NavigationAdminController` (ctor `ApplicationContext, MenuRepository, EntryTargetResolver, EventService`), `Thallo\Contracts\Navigation\MenuUpdated`, `Glueful\Http\Response`.
- Produces:
  - `MenuRepository::reorderMenus(array $slugs): void` — full ordered list → dense `0..n-1` in one transaction.
  - `MenuReorderDTO::fromRequest(array $body): self` with `public readonly array $slugs`.
  - `NavigationAdminController::reorder(Request $request): Response` — `POST` body `{ "slugs": string[] }` → `{ "menus": [...] }`; 422 on dupe/unknown/missing/malformed.
  - Route `POST /v1/admin/navigation/menus/reorder`.

- [ ] **Step 1: Rebuild the test DB is deferred — first write the failing test.** Append these methods to `tests/Integration/Navigation/NavigationApiTest.php` (reuses the file's `admin()`, `req()`, `data()`, `findRoute()` helpers):

```php
    public function testCreateAppendsPositionAndListOrdersByInsertion(): void
    {
        // Created out of alphabetical order — the list must follow position (insertion),
        // not slug, so the alpha tiebreak never masks a broken position.
        $this->admin()->create($this->req(['slug' => 'zeta', 'name' => 'Zeta']));
        $this->admin()->create($this->req(['slug' => 'alpha', 'name' => 'Alpha']));
        $this->admin()->create($this->req(['slug' => 'mid', 'name' => 'Mid']));

        $list = $this->data($this->admin()->index(Request::create('/x', 'GET')))['menus'];
        self::assertSame(['zeta', 'alpha', 'mid'], array_column($list, 'slug'));
    }

    public function testReorderRewritesDensePositions(): void
    {
        foreach (['a', 'b', 'c'] as $s) {
            $this->admin()->create($this->req(['slug' => $s, 'name' => strtoupper($s)]));
        }
        $res = $this->admin()->reorder($this->req(['slugs' => ['c', 'a', 'b']]));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['c', 'a', 'b'], array_column($this->data($res)['menus'], 'slug'));

        // Persisted, not just echoed.
        $list = $this->data($this->admin()->index(Request::create('/x', 'GET')))['menus'];
        self::assertSame(['c', 'a', 'b'], array_column($list, 'slug'));
    }

    public function testReorderRejectsBadPayloadsWithNoPartialWrite(): void
    {
        foreach (['a', 'b', 'c'] as $s) {
            $this->admin()->create($this->req(['slug' => $s, 'name' => strtoupper($s)]));
        }
        // Establish a known order first.
        $this->admin()->reorder($this->req(['slugs' => ['c', 'a', 'b']]));

        $bad = [
            'duplicate' => ['c', 'a', 'a'],
            'missing'   => ['c', 'a'],           // b omitted
            'unknown'   => ['c', 'a', 'b', 'z'], // z is not a menu
        ];
        foreach ($bad as $label => $slugs) {
            $res = $this->admin()->reorder($this->req(['slugs' => $slugs]));
            self::assertSame(422, $res->getStatusCode(), "{$label} must 422");
            // No partial write: the order is still c, a, b.
            $list = $this->data($this->admin()->index(Request::create('/x', 'GET')))['menus'];
            self::assertSame(['c', 'a', 'b'], array_column($list, 'slug'), "{$label} must not write");
        }
    }

    public function testReorderRouteResolvesNotRenameViaSlug(): void
    {
        $route = $this->findRoute('POST', '/v1/admin/navigation/menus/reorder');
        self::assertNotNull($route);
        self::assertContains('content_permission:navigation.manage', (array) ($route['middleware'] ?? []));
    }

    public function testTreeReNestingRoundTripsThroughTheSavePath(): void
    {
        // The P1 nesting guarantee: whatever a tree drag produces, the menu-save path
        // (PUT /menus/{slug}/items) preserves it. Move a child from parent A to parent B
        // and assert the reload reflects the new nesting.
        $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));
        $childUnderA = [
            'lock_version' => 0,
            'items' => [
                ['kind' => 'url', 'url' => '/a', 'labels' => ['en' => 'A'], 'children' => [
                    ['kind' => 'url', 'url' => '/x', 'labels' => ['en' => 'X'], 'children' => []],
                ]],
                ['kind' => 'url', 'url' => '/b', 'labels' => ['en' => 'B'], 'children' => []],
            ],
        ];
        $this->admin()->replaceItems($this->req($childUnderA), 'main');
        $show = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main'));
        self::assertSame('X', $show['items'][0]['children'][0]['labels']['en']); // X under A
        self::assertCount(0, $show['items'][1]['children']);                     // B empty

        $lock = $show['lock_version'];
        $childUnderB = [
            'lock_version' => $lock,
            'items' => [
                ['kind' => 'url', 'url' => '/a', 'labels' => ['en' => 'A'], 'children' => []],
                ['kind' => 'url', 'url' => '/b', 'labels' => ['en' => 'B'], 'children' => [
                    ['kind' => 'url', 'url' => '/x', 'labels' => ['en' => 'X'], 'children' => []],
                ]],
            ],
        ];
        $this->admin()->replaceItems($this->req($childUnderB), 'main');
        $show = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main'));
        self::assertCount(0, $show['items'][0]['children']);                     // A empty
        self::assertSame('X', $show['items'][1]['children'][0]['labels']['en']); // X now under B
    }
```

- [ ] **Step 2: Rebuild the test DB, then run the tests to verify they fail for the right reason.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && composer test:reset-db && composer test:migrate`
Then: `composer test:phpunit -- --filter='testReorderRewritesDensePositions|testCreateAppendsPositionAndListOrdersByInsertion|testReorderRejectsBadPayloadsWithNoPartialWrite|testReorderRouteResolvesNotRenameViaSlug'`
Expected: FAIL — `Call to undefined method …::reorder()` (and the route/order assertions fail). The nesting round-trip test (`testTreeReNestingRoundTripsThroughTheSavePath`) should already PASS (the save path exists) — that's fine; it's a characterization guard for Task 3.

- [ ] **Step 3: Fold `position` into the menus migration.** In `packages/thallo-navigation/migrations/001_CreateNavigationMenusTable.php`, add the column after `lock_version`:

```php
            // Optimistic concurrency for whole-tree PUTs (spec §5): stale version → 409.
            $table->integer('lock_version')->default(0);
            // Admin-list order (navigation redesign §9): dense 0..n-1, rewritten by
            // POST /menus/reorder. Independent named menus, so this affects only the
            // admin list — no delivery/render impact.
            $table->integer('position')->default(0);
```

- [ ] **Step 4: Set `position` on create (append to end).** In `MenuRepository::createMenu`, compute the next position and include it in the insert:

```php
    public function createMenu(string $slug, string $name): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $max = $this->db->getPDO()
            ->query('SELECT COALESCE(MAX(position), -1) AS m FROM navigation_menus')
            ->fetch(\PDO::FETCH_ASSOC);
        $row = [
            'uuid' => Utils::generateNanoID(),
            'slug' => $slug,
            'name' => $name,
            'lock_version' => 0,
            'position' => ((int) ($max['m'] ?? -1)) + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->table('navigation_menus')->insert($row);
        return $row;
    }
```

- [ ] **Step 5: Order the list by position.** In `MenuRepository::listMenus`, add `m.position` to `GROUP BY` (Postgres requires ORDER-BY columns to be grouped) and order by it:

```php
        $stmt = $this->db->getPDO()->query(
            'SELECT m.slug, m.name, m.lock_version, COUNT(i.id) AS item_count'
            . ' FROM navigation_menus m LEFT JOIN navigation_items i ON i.menu_uuid = m.uuid'
            . ' GROUP BY m.id, m.slug, m.name, m.lock_version, m.position'
            . ' ORDER BY m.position ASC, m.slug ASC'
        );
```

- [ ] **Step 6: Add `reorderMenus` (dense rewrite in one transaction).** Add this method to `MenuRepository` (after `deleteMenu`):

```php
    /**
     * Rewrite the admin-list order. The caller passes the FULL ordered slug set
     * (validated in the controller); this writes dense 0..n-1 in one transaction.
     *
     * @param list<string> $slugs
     */
    public function reorderMenus(array $slugs): void
    {
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE navigation_menus SET position = ?, updated_at = ? WHERE slug = ?'
            );
            $now = gmdate('Y-m-d H:i:s');
            foreach (array_values($slugs) as $i => $slug) {
                $stmt->execute([$i, $now, $slug]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
```

- [ ] **Step 7: Create the reorder DTO** at `packages/thallo-navigation/src/Http/MenuReorderDTO.php`. It validates *shape only* (non-empty list of non-empty strings); set-equality/duplicates are the controller's job so all business rejections return a uniform 422 `Response`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Navigation\Http;

use Glueful\Validation\ValidationException;

/** Validates the reorder payload SHAPE: a non-empty list of non-empty slug strings. */
final class MenuReorderDTO
{
    /** @param list<string> $slugs */
    public function __construct(public readonly array $slugs)
    {
    }

    /**
     * @param array<string,mixed> $body
     * @throws ValidationException on a malformed payload (not an array, empty, non-string).
     */
    public static function fromRequest(array $body): self
    {
        $raw = $body['slugs'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new ValidationException(['slugs' => ['slugs must be a non-empty array.']]);
        }
        $slugs = [];
        foreach (array_values($raw) as $i => $s) {
            if (!is_string($s) || trim($s) === '') {
                throw new ValidationException(["slugs.$i" => ['Each slug must be a non-empty string.']]);
            }
            $slugs[] = trim($s);
        }
        return new self($slugs);
    }
}
```

- [ ] **Step 8: Add the `reorder` controller action.** In `NavigationAdminController`, add the `use` import and the method. Import (with the other `use Thallo\Navigation\Http\...` lines):

```php
use Thallo\Navigation\Http\MenuReorderDTO;
```

Method (after `create`, before `show`):

```php
    #[ApiOperation(summary: 'Reorder navigation menus (full ordered set)', tags: ['Thallo Navigation'])]
    #[ApiResponse(200, description: 'The reordered menu summaries.')]
    #[ApiResponse(422, description: 'Payload is not the exact set of existing menus (dupe/unknown/missing).')]
    public function reorder(Request $request): Response
    {
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $dto = MenuReorderDTO::fromRequest($body); // 422 on malformed shape

        // Uniform 422 (no write) for every business rejection — the client must send
        // the COMPLETE order so the result is always dense 0..n-1.
        if (count($dto->slugs) !== count(array_unique($dto->slugs))) {
            return Response::error('Duplicate slugs are not allowed.', 422);
        }
        $existing = array_map(
            static fn(array $m): string => (string) $m['slug'],
            $this->menus->listMenus(),
        );
        $a = $dto->slugs;
        $b = $existing;
        sort($a);
        sort($b);
        if ($a !== $b) {
            return Response::error('The slug list must be exactly the set of existing menus.', 422);
        }

        $this->menus->reorderMenus($dto->slugs);
        foreach ($dto->slugs as $slug) {
            $this->events->dispatch(new MenuUpdated($slug));
        }
        return Response::success(['menus' => $this->menus->listMenus()]);
    }
```

- [ ] **Step 9: Register the route BEFORE `/menus/{slug}`.** In `packages/thallo-navigation/routes/admin-routes.php`, add the reorder route right after `POST /menus` (so no `{slug}` param route can shadow it):

```php
        $router->post('/menus', [NavigationAdminController::class, 'create'])
            ->middleware('content_permission:navigation.manage');
        $router->post('/menus/reorder', [NavigationAdminController::class, 'reorder'])
            ->middleware('content_permission:navigation.manage');
        $router->get('/menus/{slug}', [NavigationAdminController::class, 'show'])
            ->middleware('content_permission:navigation.manage');
```

- [ ] **Step 10: Run the reorder tests — they pass now.**

Run: `composer test:phpunit -- --filter='Navigation'`
Expected: PASS (all `NavigationApiTest` methods, incl. the new five).

- [ ] **Step 11: Run the pack's full backend gate.**

Run: `composer ci`
Expected: PASS (phpcs + full phpunit). If phpcs flags line length in the new code, wrap to ≤120 cols and re-run.

- [ ] **Step 12: Sync the dev DB — LOCAL ENVIRONMENT REPAIR ONLY, never committed.** Correctness is already covered by the folded migration + the reset test DB (Step 2); this step only patches the developer's already-migrated live dev DB, which the early-returning migration won't ALTER. The script lives in the scratchpad and is NOT part of any commit (Step 13's `git add` deliberately omits it). Write a throwaway script and run it:

Create `/private/tmp/claude-501/-Users-michaeltawiahsowah-Sites-glueful-framework/b4c63269-5a93-462b-9da2-86b70e38b9e2/scratchpad/sync_menu_position.php`:

```php
<?php

require getcwd() . '/vendor/autoload.php';

$app = \Glueful\Framework::create(getcwd())->boot();
$db = $app->getContainer()->get(\Glueful\Database\Connection::class);
$schema = $db->getSchemaBuilder();

if (!$schema->hasColumn('navigation_menus', 'position')) {
    $schema->table('navigation_menus', function ($t) {
        $t->integer('position')->default(0);
    });
    echo "added navigation_menus.position\n";
} else {
    echo "position already present\n";
}

// Backfill dense positions by current slug order so existing menus are deterministic.
$rows = $db->table('navigation_menus')->select(['uuid', 'slug'])->orderBy('slug', 'ASC')->get();
$i = 0;
foreach ($rows as $r) {
    $db->table('navigation_menus')->where('uuid', '=', $r['uuid'])->update(['position' => $i++]);
}
echo 'backfilled ' . count($rows) . " menus\n";
```

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/thallo && php /private/tmp/claude-501/-Users-michaeltawiahsowah-Sites-glueful-framework/b4c63269-5a93-462b-9da2-86b70e38b9e2/scratchpad/sync_menu_position.php`
Expected: `added navigation_menus.position` + `backfilled N menus`. (If `hasColumn`/`table()->integer` isn't the exact SchemaBuilder API, check `SchemaBuilderInterface` — the migration in Step 3 uses `$table->integer(...)`, so mirror that; the goal is one nullable-safe `position INT DEFAULT 0` column + a dense backfill.)

- [ ] **Step 13: Commit (HOLD).**

```bash
git add packages/thallo-navigation/migrations/001_CreateNavigationMenusTable.php \
  packages/thallo-navigation/src/MenuRepository.php \
  packages/thallo-navigation/src/Http/MenuReorderDTO.php \
  packages/thallo-navigation/src/Http/Controllers/NavigationAdminController.php \
  packages/thallo-navigation/routes/admin-routes.php \
  tests/Integration/Navigation/NavigationApiTest.php
git commit -m "Navigation: menu position column + POST /menus/reorder (dense rewrite)"
```

---

### Task 2: Admin reorder query + mutation

**Files:**
- Modify: `admin/src/queries/navigation.ts`
- Test: `admin/src/__tests__/navigationQueries.spec.ts`

**Interfaces:**
- Consumes: Task 1's `POST /v1/admin/navigation/menus/reorder` returning `{ menus: NavMenuSummary[] }`; existing `authFetch`, `base()`, `NavMenuSummary`, `useMutation`, `useQueryCache`.
- Produces:
  - `reorderMenus(slugs: string[]): Promise<NavMenuSummary[]>`
  - `useNavigationMutations().reorder` — a `useMutation` whose `mutation` takes `slugs: string[]`.

- [ ] **Step 1: Write the failing query test.** Append to `admin/src/__tests__/navigationQueries.spec.ts` (import `reorderMenus` at the top alongside the existing imports):

```ts
  it('reorderMenus POSTs the full slug list to /menus/reorder and unwraps data.menus', async () => {
    authFetch.mockResolvedValue({ data: { menus: [{ slug: 'c', name: 'C', item_count: 0, lock_version: 0 }] } })
    const out = await reorderMenus(['c', 'a', 'b'])
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string; body: string }]
    expect(url).toBe('/v1/admin/navigation/menus/reorder')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({ slugs: ['c', 'a', 'b'] })
    expect(out[0]!.slug).toBe('c')
  })
```

Update the import line to include it:

```ts
import { fetchMenus, fetchMenu, saveTree, createMenu, reorderMenus } from '@/queries/navigation'
```

- [ ] **Step 2: Run to verify failure.**

Run: `pnpm --dir admin test -- navigationQueries`
Expected: FAIL — `reorderMenus is not a function` (or import undefined).

- [ ] **Step 3: Add the `reorderMenus` function** to `admin/src/queries/navigation.ts` (after `saveTree`):

```ts
/** Reorder the admin menu list: send the COMPLETE ordered slug set (dense-rewrite contract). */
export async function reorderMenus(slugs: string[]): Promise<NavMenuSummary[]> {
  const json = await authFetch(`${base()}/menus/reorder`, {
    method: 'POST',
    body: JSON.stringify({ slugs }),
  })
  const d = (json.data ?? json) as { menus?: NavMenuSummary[] }
  return d.menus ?? []
}
```

- [ ] **Step 4: Add the `reorder` mutation** to `useNavigationMutations` (after `save`, inside the returned object):

```ts
    reorder: useMutation({
      mutation: (slugs: string[]) => reorderMenus(slugs),
      onSettled: invalidate,
    }),
```

- [ ] **Step 5: Run the query test — passes.**

Run: `pnpm --dir admin test -- navigationQueries`
Expected: PASS.

- [ ] **Step 6: Typecheck.**

Run: `pnpm --dir admin type-check`
Expected: PASS (no errors). Do not pipe through `tail`.

- [ ] **Step 7: Commit (HOLD).**

```bash
git add admin/src/queries/navigation.ts admin/src/__tests__/navigationQueries.spec.ts
git commit -m "Navigation admin: reorderMenus query + reorder mutation"
```

---

### Task 3: Tree-item drag (incl. nested children) in MenuTreeEditor

**Files:**
- Modify: `admin/src/pages/navigation/components/MenuTreeEditor.vue`
- Test: `admin/src/__tests__/menuTreeEditor.spec.ts`

**Interfaces:**
- Consumes: `vue-draggable-plus`'s `VueDraggable`; the component's existing `props.items`, `changed()`, `move/indent/outdent/remove`.
- Produces: no cross-task interface. Adds a per-row `data-test="tree-item-drag"` handle; makes each level's list a shared-group (`'nav-tree'`) sortable; always renders a droppable children container; rejects drops into an item's own subtree.

**Design notes (why this shape):** `CollectionEditSlideover.vue` binds `VueDraggable v-model` to a **local** reactive array (not a prop). Here each level receives `items` as a **prop**, so we expose a `computed` get/set that mutates the prop array **in place** (matching the existing `props.items.splice(...)` calls — Vue permits mutating a prop array's contents, only reassignment warns). A shared `:group` lets items cross levels (reorder + re-nest); a `:move` guard rejects dropping a node into its own subtree (which would detach a cycle). Buttons stay as the keyboard path.

- [ ] **Step 1: Write the failing tests.** Append to `admin/src/__tests__/menuTreeEditor.spec.ts`:

```ts
  it('renders a drag handle on every row', () => {
    const wrapper = mountEditor([url('a'), url('b')])
    expect(wrapper.findAll('[data-test="tree-item-drag"]')).toHaveLength(2)
  })

  it('always renders a droppable children container, even for a childless item (nesting-by-drag target)', () => {
    const wrapper = mountEditor([url('a')])
    // Two sortable lists: the root, PLUS the (empty) child level under item 'a' — proving a
    // childless item still offers a drop target. If the child container were guarded by
    // `children.length > 0`, this would be 1.
    expect(wrapper.findAll('[data-test="tree-children"]').length).toBeGreaterThanOrEqual(2)
  })

  it('onMove rejects dropping a node into its own subtree', () => {
    const wrapper = mountEditor([url('a')])
    const vm = wrapper.vm as unknown as {
      onMove: (e: { dragged: HTMLElement; to: HTMLElement }) => boolean
    }
    const outer = document.createElement('div')
    const inner = document.createElement('div')
    outer.appendChild(inner)
    expect(vm.onMove({ dragged: outer, to: inner })).toBe(false) // into own subtree → reject
    expect(vm.onMove({ dragged: inner, to: outer })).toBe(true) // outward → allow
  })

  it('committing a reordered list mutates items in place and emits changed', () => {
    // The drag-commit path is the `list` computed setter (what vue-draggable-plus writes to).
    // Assigning a reordered array must splice the SAME items array in place and bubble changed.
    const items = [url('a'), url('b')]
    const wrapper = mount(MenuTreeEditor, { props: { items, locale: 'en' } })
    ;(wrapper.vm as unknown as { list: NavTreeItem[] }).list = [items[1]!, items[0]!]
    expect(items.map((i) => i.url)).toEqual(['/b', '/a'])
    expect(wrapper.emitted('changed')).toBeTruthy()
  })
```

- [ ] **Step 2: Run to verify failure.**

Run: `pnpm --dir admin test -- menuTreeEditor`
Expected: FAIL — no `tree-item-drag`, no `tree-children`, `onMove` undefined.

- [ ] **Step 3: Add the import, the draggable model, and the guard** to the `<script setup>` of `MenuTreeEditor.vue`. Add near the top imports:

```ts
import { VueDraggable } from 'vue-draggable-plus'
```

Add after the `move`/`remove` functions (needs `computed`, already imported later in the file — move the existing `import { computed, ref } from 'vue'` to the top of the script if it isn't already, so it's available here):

```ts
// Two-way view of THIS level's array for vue-draggable-plus. The setter mutates the
// prop array IN PLACE (like the existing splice calls) — never reassigns the prop —
// so drags commit straight into the page's working tree and `changed()` bubbles.
const list = computed<NavTreeItem[]>({
  get: () => props.items,
  set: (next) => {
    props.items.splice(0, props.items.length, ...next)
    changed()
  },
})

// Reject dropping an item into its own subtree (would detach a cycle). Sortable's move
// event carries the dragged element and the destination list element.
function onMove(e: { dragged: HTMLElement; to: HTMLElement }): boolean {
  return !e.dragged.contains(e.to)
}

// Exposed for tests: `onMove` (guard) and `list` (the drag-commit setter).
defineExpose({ onMove, list })
```

(If the file already has `import { computed, ref } from 'vue'` lower down for the icon picker, delete that lower line after moving it to the top — one import only.)

- [ ] **Step 4: Wrap the list in `VueDraggable` and add the handle + always-on children container** in the `<template>`. Replace the current `<ul class="space-y-2"> … </ul>` root with:

```html
  <VueDraggable
    v-model="list"
    :group="{ name: 'nav-tree' }"
    handle="[data-test='tree-item-drag']"
    :move="onMove"
    :animation="150"
    tag="ul"
    class="space-y-2"
    :class="{ 'min-h-8 rounded border border-dashed border-default': items.length === 0 }"
    data-test="tree-children"
  >
    <li v-for="(item, i) in items" :key="item.uuid ?? `new-${i}`" data-test="tree-item">
      <div class="border-default flex flex-wrap items-center gap-2 rounded border p-2">
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          icon="i-lucide-grip-vertical"
          class="cursor-grab"
          aria-label="Drag to reorder"
          data-test="tree-item-drag"
          @click.prevent
        />
        <!-- … all existing row controls (label input, url/badge, description, icon,
             spacer, up/down/indent/outdent/remove buttons) UNCHANGED … -->
      </div>

      <!-- Children ALWAYS render as a droppable level (empty → a thin dashed strip),
           so an item can receive children by drag. -->
      <div class="mt-2 ml-6 border-l border-default pl-3">
        <MenuTreeEditor
          :items="item.children"
          :locale="locale"
          :can-outdent="true"
          @changed="changed()"
          @outdent="(childIndex: number) => outdentChild(i, childIndex)"
        />
      </div>
    </li>
  </VueDraggable>
```

Key changes vs. the current template: (1) root `<ul>` → `<VueDraggable tag="ul">` with the group/handle/move; (2) a grip `UButton` (`data-test="tree-item-drag"`) is the FIRST control in each row; (3) the children `<div v-if="item.children.length > 0">` guard is REMOVED so the nested `MenuTreeEditor` always renders (its own root carries `data-test="tree-children"` and the empty-state dashed strip). Keep the `IconPickerModal` block below unchanged.

- [ ] **Step 5: Run the tree tests — pass.**

Run: `pnpm --dir admin test -- menuTreeEditor`
Expected: PASS (new four: handle, children-container, onMove guard, list-commit + the file's existing label/description/move/indent tests still green — the buttons are untouched).

- [ ] **Step 6: Typecheck + lint.**

Run: `pnpm --dir admin type-check && pnpm --dir admin lint`
Expected: PASS. (`@click.prevent` on the handle keeps it from activating; drag is driven by the `handle` selector.)

- [ ] **Step 7: Manual smoke (drag isn't jsdom-testable).** In the running admin, open a menu with nested items: drag a row by its grip to reorder within a level, and drag a row onto another item's dashed child strip to nest it; Save, reload, confirm the new nesting persists (this is the behavior the Task 1 `testTreeReNestingRoundTrips…` guards on the server side).

- [ ] **Step 8: Commit (HOLD).**

```bash
git add admin/src/pages/navigation/components/MenuTreeEditor.vue \
  admin/src/__tests__/menuTreeEditor.spec.ts
git commit -m "Navigation tree: drag-to-reorder incl. nesting, keep button fallback"
```

---

### Task 4: Page shell redesign + sidebar menu reorder

**Files:**
- Modify: `admin/src/pages/navigation/index.vue`
- Test: `admin/src/__tests__/navigationPage.spec.ts`

**Interfaces:**
- Consumes: Task 2's `mutations.reorder` (mutation over `slugs: string[]`); existing `useNavMenus`, `useNavMenu`, `useNavigationMutations`, `useCapabilitiesStore`, `MenuTreeEditor`, `ReferencePicker`, `VueDraggable`.
- Produces: the redesigned page. New/relocated `data-test`: `nav-page` (on the panel), `nav-menu-list` (aside), `nav-menu-row` (select button), `nav-menu-drag` (grip), `nav-menu-menu` (overflow), `nav-menu-create` (modal form). All tree/editor hooks unchanged.

**Design notes:** mirror `regions/index.vue` (`UDashboardPanel` → `#header` `UDashboardNavbar` + `#body` master-detail). Selection is a **slug** ref; a reconciliation `watch(menus)` changes it ONLY when the current slug is absent from the list (first load / post-delete / post-reload), so a reorder never disturbs a valid selection. The "New menu" button and modal render only when the capability is enabled.

- [ ] **Step 1: Set up the test stubs + factory and convert the STABLE existing tests (no new tests yet).** Establish that the behaviors we must preserve pass on the CURRENT page before touching it, so a later failure means "logic changed," not "UI moved."

In `admin/src/__tests__/navigationPage.spec.ts`:

(a) Make the capability mock togglable and add a shared reorder mock (both hoisted so the `vi.mock` factories can close over them):

```ts
const capsEnabled = vi.hoisted(() => ({ value: true }))
const reorderMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))

vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: () => capsEnabled.value }),
}))
```

Add `reorder: { mutateAsync: reorderMock }` to the `useNavigationMutations` mock's returned object, and add `reorderMock.mockClear()` + `capsEnabled.value = true` to `beforeEach` alongside the existing resets.

(b) Add a `mountPage()` factory with the stubs the redesigned page needs (modeled on `seoPanelGating.spec.ts`, but rendering BOTH header and body slots since "New menu" is in the header). These stubs are INERT on the current page (it doesn't mount those components yet), so they don't change current behavior:

```ts
const mountPage = () =>
  mount(NavigationPage, {
    global: {
      stubs: {
        UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
        UDashboardNavbar: { template: '<div><slot /><slot name="right" /></div>' },
        // Render modal content inline (real UModal teleports out of the wrapper).
        UModal: { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' },
        // Expose dropdown items as buttons so onSelect is reachable (real menu teleports).
        UDropdownMenu: {
          props: ['items'],
          template:
            '<div><button v-for="it in items.flat()" :key="it.label" ' +
            ':data-test="\'ddi-\' + it.label" :disabled="it.disabled" ' +
            '@click="it.onSelect && it.onSelect()">{{ it.label }}</button></div>',
        },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
```

(c) **Convert ONLY the four stable existing tests** (`selecting a menu…`, `Add page reveals…`, `picking a page…`, `409 on save…`) to call `mountPage()` instead of `mount(NavigationPage)` — bodies otherwise unchanged; their `nav-menu-row`/`tree-*`/`add-page-*` hooks survive the redesign. Do NOT touch the first test (`lists menus and shows the empty state…`, a legitimate auto-select behavior change) and do NOT add the new-behavior tests — both happen in Step 5, AFTER the refactor, so a failure there can only mean "behavior regressed," never "UI moved."

- [ ] **Step 2: Run the baseline — converted tests PASS on the CURRENT page.**

Run: `pnpm --dir admin test -- navigationPage`
Expected: PASS. The four converted tests (and the still-unchanged first test) pass on the un-refactored page because the new stubs are inert. This is the regression baseline the refactor must not break.

- [ ] **Step 3: Rewrite the `<script setup>` selection logic** in `admin/src/pages/navigation/index.vue`. Replace the current `const selected = ref('')` + the locale watch region with the reconciliation watch, add the create-modal state and the reorder wiring. Add imports at the top:

```ts
import { VueDraggable } from 'vue-draggable-plus'
```

Extend the type import to include `NavMenuSummary` (the file currently imports only `type { NavTreeItem }`):

```ts
import type { NavMenuSummary, NavTreeItem } from '@/queries/navigation'
```

Replace `const selected = ref('')` with the reconciliation watch, the modal flag, and the drag mirror (all depend only on `menus`, which is already defined above via `useNavMenus`):

```ts
const selected = ref('')

// Reconcile selection ONLY when it's invalid: first load (empty), post-delete, or a
// refetch that dropped the slug. A still-valid selection — including one whose row just
// moved in a reorder — is left untouched (selection follows slug, never index).
watch(
  menus,
  (rows) => {
    const list = rows ?? []
    if (!list.some((m) => m.slug === selected.value)) {
      selected.value = list.length > 0 ? list[0]!.slug : ''
    }
  },
  { immediate: true },
)

// Create-menu modal (replaces the sidebar form).
const createOpen = ref(false)

// Drag mirror for the sidebar list — kept in sync with the fetched list; the reorder
// mutation commits it. VueDraggable binds this so reordering is smooth without touching
// `selected`.
const menuOrder = ref<NavMenuSummary[]>([])
watch(
  menus,
  (rows) => {
    menuOrder.value = [...(rows ?? [])]
  },
  { immediate: true },
)
```

After the existing `const mutations = useNavigationMutations()` line, add the reorder committers:

```ts
async function commitOrder(): Promise<void> {
  try {
    await mutations.reorder.mutateAsync(menuOrder.value.map((m) => m.slug))
  } catch (e) {
    notifyError(e, 'Couldn’t reorder menus')
  }
}

// Keyboard fallback (overflow "Move up/down"): swap with the neighbour, then commit.
async function moveMenu(index: number, delta: number): Promise<void> {
  const target = index + delta
  if (target < 0 || target >= menuOrder.value.length) return
  const next = [...menuOrder.value]
  const [row] = next.splice(index, 1)
  next.splice(target, 0, row!)
  menuOrder.value = next
  await commitOrder()
}
```

Update `createMenu()` to close the modal on success: append `createOpen.value = false` after the existing `success('Menu created')` line.

Everything else in the `<script setup>` stays: `newSlug`/`newName`/`createMenu`/`deleteMenu`/`save`/`onEntryPicked`/`mergeBadges`/`working`/`dirty` and the add-page state (`addPageOpen`/`addPageType`/`pickedEntry`/`pageTypeOptions`) all feed the redesigned template unchanged.

- [ ] **Step 4: Rewrite the `<template>`** of `index.vue` to the panel + master-detail. Replace the whole `<template>…</template>` with:

```html
<template>
  <UDashboardPanel id="navigation" data-test="nav-page">
    <template #header>
      <UDashboardNavbar title="Navigation">
        <template #right>
          <UButton
            v-if="enabled"
            icon="i-lucide-plus"
            data-test="nav-menu-new"
            @click="() => { createOpen = true }"
          >
            New menu
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Capability off: one clear empty state, no list, no create. -->
      <div v-if="!enabled" class="flex h-full flex-col items-center justify-center gap-2 text-muted">
        <UIcon name="i-lucide-lock" class="size-8" />
        <p class="text-sm">Navigation isn’t enabled.</p>
      </div>

      <div v-else class="flex h-full min-h-0 flex-col gap-6 lg:flex-row">
        <aside class="w-full shrink-0 lg:w-80" data-test="nav-menu-list">
          <VueDraggable
            v-model="menuOrder"
            handle="[data-test='nav-menu-drag']"
            :animation="150"
            class="space-y-1"
            @end="commitOrder"
          >
            <div
              v-for="(menu, i) in menuOrder"
              :key="menu.slug"
              class="group flex items-center gap-1 rounded pr-1"
              :class="selected === menu.slug ? 'bg-elevated border-l-2 border-primary' : 'hover:bg-elevated border-l-2 border-transparent'"
            >
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                icon="i-lucide-grip-vertical"
                class="cursor-grab opacity-0 group-hover:opacity-100"
                aria-label="Drag to reorder"
                data-test="nav-menu-drag"
                @click.prevent
              />
              <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-3 px-2 py-2 text-left"
                data-test="nav-menu-row"
                :aria-current="selected === menu.slug ? 'true' : undefined"
                @click="selected = menu.slug"
              >
                <UIcon name="i-lucide-menu" class="text-muted size-4 shrink-0" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-medium">{{ menu.name }}</span>
                  <span class="text-muted block truncate text-xs">{{ menu.slug }}</span>
                </span>
                <UBadge color="neutral" variant="subtle" size="sm">{{ menu.item_count }}</UBadge>
              </button>
              <UDropdownMenu
                :items="[[
                  { label: 'Move up', icon: 'i-lucide-arrow-up', disabled: i === 0, onSelect: () => moveMenu(i, -1) },
                  { label: 'Move down', icon: 'i-lucide-arrow-down', disabled: i === menuOrder.length - 1, onSelect: () => moveMenu(i, 1) },
                ]]"
                data-test="nav-menu-menu"
              >
                <UButton size="xs" variant="ghost" color="neutral" icon="i-lucide-ellipsis-vertical" aria-label="Menu actions" />
              </UDropdownMenu>
            </div>
          </VueDraggable>

          <p v-if="menuOrder.length === 0" class="text-muted px-3 py-2 text-sm">No menus yet.</p>
        </aside>

        <div class="min-w-0 flex-1">
          <div v-if="detail">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <h2 class="font-medium">{{ detail.name }}</h2>
                <UButton
                  size="xs"
                  color="error"
                  variant="ghost"
                  data-test="nav-menu-delete"
                  @click="deleteMenu(detail.slug)"
                >
                  Delete
                </UButton>
              </div>
              <div class="flex items-center gap-1" role="group" aria-label="Locale">
                <UButton
                  v-for="code in locales"
                  :key="code"
                  size="xs"
                  :variant="locale === code ? 'solid' : 'ghost'"
                  data-test="nav-locale-tab"
                  @click="() => { locale = code }"
                >
                  {{ code }}
                </UButton>
              </div>
            </div>

            <MenuTreeEditor :items="working" :locale="locale || 'en'" @changed="dirty = true" />

            <div class="mt-4 flex items-center gap-3">
              <UButton
                size="sm"
                variant="outline"
                data-test="tree-add-root"
                @click="() => { working.push({ kind: 'url', url: '/', labels: {}, descriptions: {}, children: [] }); dirty = true }"
              >
                Add link
              </UButton>
              <UButton
                size="sm"
                variant="outline"
                icon="i-lucide-file-text"
                data-test="tree-add-page"
                @click="() => { addPageOpen = !addPageOpen }"
              >
                Add page
              </UButton>
              <span class="grow" />
              <UButton size="sm" :disabled="!dirty" data-test="tree-save" @click="save">Save</UButton>
            </div>

            <div
              v-if="addPageOpen"
              class="border-default mt-3 grid gap-3 rounded border p-3 sm:grid-cols-2"
              data-test="add-page-picker"
            >
              <UFormField label="Content type">
                <USelect
                  v-model="addPageType"
                  :items="pageTypeOptions"
                  placeholder="Pick a type…"
                  class="w-full"
                  data-test="add-page-type"
                />
              </UFormField>
              <UFormField label="Page" hint="The menu label follows the page title until you override it.">
                <ReferencePicker
                  v-if="addPageType"
                  v-model="pickedEntry"
                  :target="addPageType"
                  @picked="onEntryPicked"
                />
                <p v-else class="text-muted pt-1.5 text-sm">Pick a type first.</p>
              </UFormField>
            </div>
          </div>

          <!-- No menu selected: zero-menu empty state with a CTA, else a light hint. -->
          <div v-else class="flex h-full flex-col items-center justify-center gap-3 text-muted">
            <UIcon name="i-lucide-list-tree" class="size-8" />
            <p class="text-sm">{{ menuOrder.length === 0 ? 'No menus yet.' : 'Select a menu.' }}</p>
            <UButton v-if="menuOrder.length === 0" icon="i-lucide-plus" @click="() => { createOpen = true }">
              New menu
            </UButton>
          </div>
        </div>
      </div>
    </template>

    <!-- Create-menu modal: Enter in either input submits (real form), footer buttons remain. -->
    <UModal v-model:open="createOpen" title="New menu">
      <template #body>
        <form id="nav-create-form" data-test="nav-menu-create" class="space-y-3" @submit.prevent="createMenu">
          <UFormField label="Slug">
            <UInput v-model="newSlug" placeholder="slug (e.g. main)" class="w-full" />
          </UFormField>
          <UFormField label="Name">
            <UInput v-model="newName" placeholder="Name" class="w-full" />
          </UFormField>
          <button type="submit" class="sr-only" aria-hidden="true" tabindex="-1">Create</button>
        </form>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton color="neutral" variant="ghost" @click="() => { createOpen = false }">Cancel</UButton>
          <UButton
            :disabled="newSlug.trim() === '' || newName.trim() === ''"
            @click="createMenu"
          >
            Create
          </UButton>
        </div>
      </template>
    </UModal>
  </UDashboardPanel>
</template>
```

Notes: the grip (`nav-menu-drag`), the select button (`nav-menu-row`, carries `aria-current`), and the overflow (`nav-menu-menu`) are **siblings** in a `<div>` wrapper — no nested interactive elements. The sidebar list is bound to `menuOrder` (the drag mirror), not `menus`, so drag reordering is smooth; selection stays keyed to `selected` (a slug). The modal's footer `Create` calls `createMenu` directly (the visible action), and the hidden `type="submit"` gives Enter a target inside the teleported modal.

- [ ] **Step 5: Re-run the baseline (no regression), then add the new-behavior tests.**

First, repurpose the first existing test — auto-select makes "menus exist but none selected" a non-state and the empty copy changed, so this is a deliberate behavior change, not a regression:

```ts
  it('shows the zero-menu empty state with a create CTA', async () => {
    menusData.value = []
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.findAll('[data-test="nav-menu-row"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('No menus yet.')
    expect(wrapper.find('[data-test="nav-menu-new"]').exists()).toBe(true)
  })
```

Now re-run the file against the REFACTORED page (still only the converted + repurposed tests exist):

Run: `pnpm --dir admin test -- navigationPage`
Expected: PASS — the four converted tests + the repurposed zero-menu test. **If any of the four converted tests fail, the refactor changed logic** — fix `index.vue`, not the test. (This is the split the ordering buys: those four passed on the OLD page in Step 2 with unchanged hooks, so a failure here can only be "logic changed," never "UI moved.")

Finally add the new-behavior tests (logic that exists only after the refactor), all via `mountPage()`:

```ts
  it('auto-selects the first menu when none is selected', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    detailData.value = { slug: 'main', name: 'Main', locale: 'en', lock_version: 0, items: [] }
    const wrapper = mountPage()
    await flushPromises()
    // The first row is selected (aria-current) without any click.
    const rows = wrapper.findAll('[data-test="nav-menu-row"]')
    expect(rows[0]!.attributes('aria-current')).toBe('true')
  })

  it('reconciles a stale selection to the first menu when the selected slug disappears', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 2, lock_version: 0 }]
    const wrapper = mountPage()
    await flushPromises()
    // 'main' selected. Now it's deleted elsewhere and the list refetches without it.
    menusData.value = [{ slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 }]
    await flushPromises()
    const rows = wrapper.findAll('[data-test="nav-menu-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.attributes('aria-current')).toBe('true') // reconciled to 'footer'
  })

  it('hides "New menu" when the navigation capability is disabled', async () => {
    capsEnabled.value = false
    menusData.value = []
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="nav-menu-new"]').exists()).toBe(false)
    capsEnabled.value = true // reset for other tests
  })

  it('preserves the selected menu by slug across a reorder', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    const wrapper = mountPage()
    await flushPromises()
    // Select 'footer'.
    await wrapper.findAll('[data-test="nav-menu-row"]')[1]!.trigger('click')
    await flushPromises()
    // Reorder puts footer first; the list refetches in the new order.
    menusData.value = [
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
    ]
    await flushPromises()
    const rows = wrapper.findAll('[data-test="nav-menu-row"]')
    // Footer is now row 0 AND still the selected one (selection follows slug, not index).
    expect(rows[0]!.attributes('aria-current')).toBe('true')
    expect(rows[0]!.text()).toContain('Footer')
  })

  it('the overflow "Move down" reorders and commits the full slug list', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    const wrapper = mountPage()
    await flushPromises()
    // Row 0 ("main") → Move down. The stubbed dropdown renders items as buttons.
    await wrapper.findAll('[data-test="ddi-Move down"]')[0]!.trigger('click')
    await flushPromises()
    expect(reorderMock).toHaveBeenCalledWith(['footer', 'main'])
  })
```

Run: `pnpm --dir admin test -- navigationPage`
Expected: PASS — the five new tests + the repurposed zero-menu test + the four converted tests (ten total).

- [ ] **Step 6: Full admin gate.**

Run: `pnpm --dir admin type-check && pnpm --dir admin test && pnpm --dir admin lint`
Expected: PASS. If `UDropdownMenu`'s `items` typing complains, match the shape used elsewhere in the admin (search an existing `UDropdownMenu :items=` usage, e.g. `CollectionEditSlideover.vue` / a table row-actions menu, and mirror it).

- [ ] **Step 7: Manual smoke.** In the running admin: the page now has the standard panel navbar; the sidebar shows each menu as name + slug + count with a clear selected highlight; auto-selects the first menu; "New menu" opens the modal and Enter submits; drag a menu by its grip to reorder (selection stays on the same menu); the overflow "Move up/down" works; disabling the capability shows the lock empty state with no "New menu".

- [ ] **Step 8: Commit (HOLD).**

```bash
git add admin/src/pages/navigation/index.vue admin/src/__tests__/navigationPage.spec.ts
git commit -m "Navigation page: UDashboardPanel shell, selectable rows, create modal, sidebar reorder"
```

---

## Verification (whole feature)

- Backend: `composer ci` (phpcs + phpunit) green; `NavigationApiTest` covers create-append, dense reorder, dupe/missing/unknown → 422 with no partial write, route resolution, and the tree re-nesting round-trip.
- Admin: `pnpm --dir admin type-check && pnpm --dir admin test && pnpm --dir admin lint` green; nav specs cover the reorder query, tree drag handle/container/guard, and page reconciliation/capability-gate/aria-current/reorder-selection.
- Dev DB synced with the `position` column (Task 1 Step 12).
- Every pre-existing `data-test` hook still present (relocated).
- All commits HELD until explicit go-ahead.
