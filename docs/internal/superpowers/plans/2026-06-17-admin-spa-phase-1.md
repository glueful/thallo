# Admin SPA — Phase 1 (editorial loop) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development — implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Recommended fallback: superpowers:executing-plans.

**Goal:** Ship Lemma's first-party editor SPA so an editor can author and publish getlemma.dev's *existing* content types end-to-end — list (draft-inclusive) → create → edit a schema-driven, Markdown-bodied draft → preview → route → publish/schedule/rollback/redirects → upload-and-use an asset — without touching the API by hand. Phase 1 *consumes* schema; it never mutates it.

**Architecture:** Three backend additions to the existing Glueful/PHP app (`app/Content`) — a draft-inclusive admin entry-list endpoint (`GET /v1/admin/entries?type=`), an **unauthenticated** runtime-config endpoint (`GET /admin/config.json`), and an **unauthenticated, self-locking** first-run web setup (`POST /admin/setup`) that creates the first admin (via `glueful/users` + `glueful/aegis`) and a couple of site settings (`site_name`, `default_locale`) so the editorial loop has a logged-in admin to start from — plus mounting the compiled bundle at `/admin` via the framework's `serveFrontend()` seam (secure asset serving + `index.html` deep-link fallback + cache split; no hand-rolled controller), followed by a greenfield Vue 3 SPA under `admin/` whose compiled output ships as `public/admin/`. (DB credentials are NOT part of web setup — they stay in `.env`; the standalone `lemma` CLI cut of setup is out of scope, see Task 0d.) The SPA is a typed-from-OpenAPI client wrapped in domain composables, in-memory access token (Pinia, never `localStorage`) with refresh-on-401 via the httpOnly refresh cookie, a schema-driven field editor via a `type → component` registry, and a hard, **test-enforced** boundary: no Phase 1 screen/composable ever calls `PATCH /content-types/{slug}/schema` or `POST /content-types/{slug}/migrations`.

**Tech Stack:**
- **Backend:** PHP 8.3, PostgreSQL, Glueful framework (`Glueful\Http\Response` envelope, `QueryBuilder`, `config()` helper, fluent router, `ServiceProvider`, `RequireLemmaPermission` middleware), PHPUnit 10 via `App\Tests\Support\LemmaTestCase` (Postgres `lemma_test`). Tests: `composer test:phpunit -- --filter <Name>`; lint `composer phpcs`.
- **Frontend:** *(toolchain SUPERSEDED — see the SUPERSEDED note under "Frontend (Task groups 1–8)"; the maintainer scaffolds on **Vite 8 / Vue Router 5 file-based / Nuxt UI**.)* Vue 3 (`<script setup>` + TS), Vite, Vue Router, Pinia, **Nuxt UI**, `openapi-typescript` (type generation) + `openapi-fetch` (thin typed client), `markdown-it` (preview render), Vitest + `@vue/test-utils`, Playwright (one e2e). Build → `public/admin/`.

**Spec:** `docs/superpowers/specs/2026-06-17-admin-spa-phase-1-design.md`

> **Framework dependency (Task 0c).** Static `/admin` serving uses the framework's
> `Glueful\Extensions\ServiceProvider::serveFrontend()` seam (designed in
> `glueful/framework` → `docs/superpowers/specs/2026-06-17-framework-serve-frontend-design.md`).
> That seam **shipped in framework 1.59.0**, and Lemma is pinned `glueful/framework: ^1.59.0`, so
> Task 0c is **unblocked** — its **Step 0** `grep`-confirms the pinned framework actually exposes
> `serveFrontend()` before the rest of the task runs. Tasks 0a, 0b, and all frontend tasks (1–8)
> have no framework dependency.

---

## File map

### Backend (Task group 0)
- Modify: `app/Content/Repositories/EntryRepository.php` — add `listForType(string $typeUuid, string $defaultLocale, int $page, int $perPage, ?string $q): array` (draft-inclusive paginated list + display-title derivation).
- Create: `app/Content/Http/DTOs/Requests/EntryListQuery.php` — query-param request DTO (`type`/`q`/`page`/`perPage`) carrying the `#[FromQuery]` OpenAPI docs + `#[Rule]` validation.
- Create: `app/Content/Http/DTOs/Responses/Entries/EntryListItemData.php` — doc-only response DTO for one list row.
- Create: `app/Content/Http/DTOs/Responses/Entries/EntryListData.php` — doc-only response DTO for the list envelope.
- Modify: `app/Content/Http/Controllers/EntryController.php` — add `index(EntryListQuery $query): Response` (perm `lemma.entries.read`).
- Modify: `routes/lemma_admin.php` — add `GET /v1/admin/entries`.
- Create: `app/Content/Http/Controllers/AdminConfigController.php` — `config(): Response` returning the **unauthenticated** runtime config JSON (Task 0d adds `installed` to the payload, injecting `SetupService`).
- Create: `routes/lemma_admin_spa.php` — registers the unauthenticated `/admin/config.json` route **and** (Task 0d) the unauthenticated `POST /admin/setup` route (auto-discovered by `RouteManifest`). The `/admin` + `/admin/{rest}` SPA routes are registered by the framework `serveFrontend()` call, not in this file.
- Modify: `app/Providers/LemmaServiceProvider.php` — register `AdminConfigController`, `SetupService`, `SetupController` **and** call `$this->serveFrontend('/admin', <public/admin>, ['name' => 'Lemma Admin'])` in `boot()` to mount the compiled bundle.

### First-run web setup (Task 0d)
- Create: `database/migrations/013_CreateLemmaSettingsTable.php` — `lemma_settings` key/value store (`key` PK, `value`, `updated_at`) holding `site_name`, `default_locale`, and the `installed` marker.
- Create: `app/Setup/SetupService.php` — **single source of truth** for install: `isInstalled(): bool` + race-safe transactional `install(string $siteName, string $adminEmail, string $adminPassword, string $locale): void` (re-check → create first admin via `glueful/users` → grant admin role via `glueful/aegis` → write settings → set `installed` marker). The future `php glueful lemma:setup` CLI command calls this same service.
- Create: `app/Content/Http/DTOs/Requests/SetupData.php` — `RequestData` DTO (`site_name`, `admin_email`, `admin_password`, `locale`) with `#[Rule]` validation.
- Create: `app/Content/Http/Controllers/SetupController.php` — `setup(SetupData $input): JsonResponse`, **unauthenticated**, returns `409` once installed (self-locks permanently), else installs and returns success.
- Create test: `tests/Integration/Http/SetupApiTest.php`
- Modify: `config/lemma.php` — add the `admin` block (`api_base`, `site_preview_url`, `default_locale`).
- Create test: `tests/Integration/Http/EntryListApiTest.php`
- Create test: `tests/Integration/Http/AdminConfigApiTest.php`
- Create test: `tests/Integration/Http/AdminSpaServingTest.php` — thin **wiring** test (`findRoute`) that `serveFrontend()` mounted `/admin` after boot and that `/admin/config.json` is not shadowed by the SPA catch-all. Exhaustive serving behavior (traversal/cache/dot-rule/fallback) lives in the framework's own `ServeFrontendTest`.
- Create fixture: `tests/fixtures/admin/index.html` — minimal committed `<!doctype html>` bundle so `serveFrontend()` (which no-ops without an `index.html`) mounts `/admin` under test. Also: `phpunit.xml` gains a `<env name="LEMMA_ADMIN_BUNDLE_PATH" value="tests/fixtures/admin"/>` entry so `lemma.admin.bundle_path` resolves to this fixture before the process-global boot.

### Frontend (Task groups 1–8) — all under `admin/`

> **⚠️ SUPERSEDED — the frontend toolchain below is out of date and is NOT to be implemented as written.**
> The Admin SPA **project scaffold is owned and created by the maintainer**, on a current stack:
> **Vite 8**, **Vue Router 5 (file-based routing)**, and **Nuxt UI**. That makes the hand-rolled
> toolchain here obsolete: no hand-written `router.ts` or `views/` enumeration (file-based `pages/`
> replaces them), no hand-authored `vite.config.ts`/`tsconfig*`/`vitest.config`/`playwright.config`/
> `strip-admin-paths.mjs` (Vite 8 owns its config; `base: '/admin/'` replaces the path-strip hack),
> and UI primitives come from Nuxt UI rather than being built per-type.
>
> **Division of work:** the maintainer scaffolds the project; **then** these task groups are
> **re-planned against the real scaffold** and only the *app-specific* layer is implemented on top —
> the `runtimeConfig` loader (fetch `/admin/config.json` at boot), the typed-from-OpenAPI client +
> refresh-on-401, the Pinia in-memory session store, the domain composables, the **schema-driven
> field registry/editor built _on_ Nuxt UI**, the screens as file-based pages, and the
> schema-boundary test. **Contracts the backend assumes (preserve in the scaffold):** build
> `base: '/admin/'` → output `public/admin/` with an `index.html` deep-link fallback; boot reads
> `/admin/config.json` (`apiBase`/`installed`); the access token stays **in memory** (never
> `localStorage`). The **backend Task group 0 (0a–0d) is unaffected** by any of this.
>
> *Treat the file lists and code in Task groups 1–8 below as historical intent, not instructions.*

- Config: `admin/package.json`, `admin/vite.config.ts`, `admin/tsconfig.json`, `admin/tsconfig.node.json`, `admin/vitest.config.ts`, `admin/playwright.config.ts`, `admin/index.html`, `admin/env.d.ts`, `admin/.gitignore`.
- Bootstrap: `admin/src/main.ts`, `admin/src/App.vue`, `admin/src/router.ts`, `admin/src/runtimeConfig.ts`, `admin/src/assets/main.css` (Tailwind v4 + Nuxt UI CSS entry).
- Client: `admin/scripts/strip-admin-paths.mjs` (gen-time path-prefix trimmer), `admin/src/api/schema.d.ts` (generated), `admin/src/api/client.ts` (thin typed client + refresh-on-401).
- Stores: `admin/src/stores/session.ts` (Pinia, in-memory token).
- Composables: `admin/src/composables/useAuth.ts`, `useContentTypes.ts`, `useEntries.ts`, `useDraft.ts`, `useRoutes.ts`, `usePublish.ts`, `useSchedules.ts`, `useVersions.ts`, `useRedirects.ts`, `useMedia.ts`, `usePreview.ts`.
- Field editor: `admin/src/fields/registry.ts`, `admin/src/fields/types.ts`, and one component per type under `admin/src/fields/components/` (`StringField.vue`, `TextField.vue`, `NumberField.vue`, `BooleanField.vue`, `DatetimeField.vue`, `EnumField.vue`, `AssetField.vue`, `ReferenceField.vue`, `JsonField.vue`), plus `FieldEditor.vue`.
- Screens: `admin/src/views/SetupView.vue` (first-run setup, Task 1.5), `admin/src/views/LoginView.vue`, `EntryListView.vue`, `EntryEditView.vue`, `VersionsView.vue`; layout `admin/src/views/AppShell.vue`, `admin/src/components/ContentTypeNav.vue`.
- Tests: `admin/test/setup.ts`, `admin/test/mountWithUi.ts` (mounts components with native-element Nuxt UI stubs), `admin/test/fields/StringField.spec.ts` (+ one per field), `admin/test/views/SetupView.spec.ts` (Task 1.5), `admin/test/composables/*.spec.ts`, `admin/test/schemaBoundary.spec.ts` (**the boundary test**), `admin/e2e/editorial-loop.spec.ts`.
- Packaging: `.gitattributes` (export-ignore the SPA *source*), `.github/workflows/admin.yml` (CI build+test on PR/push), `.github/workflows/release.yml` (bakes the compiled `public/admin/` into the release tag, WordPress-style), `docs/ADMIN_SPA.md` (build/distribution note).

Conventions: PHP `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col. TS strict mode, `<script setup lang="ts">`, no `localStorage`/`sessionStorage` for tokens.

---

## Task group 0 — Backend seams

### Task 0a: Draft-inclusive admin entry list (`GET /v1/admin/entries?type=`)

**Files:**
- Modify: `app/Content/Repositories/EntryRepository.php`
- Create: `app/Content/Http/DTOs/Requests/EntryListQuery.php` (query-param DTO)
- Create: `app/Content/Http/DTOs/Responses/Entries/EntryListItemData.php`, `EntryListData.php`
- Modify: `app/Content/Http/Controllers/EntryController.php`, `routes/lemma_admin.php`
- Test: `tests/Integration/Http/EntryListApiTest.php`

The query joins `entries` (the identity spine, filtered to `status='active'` and the content type) to `entry_drafts`, `entry_publications`, `entry_routes`, and `entry_schedules`. It derives a per-entry **display title** by convention: the default-locale draft's `title` field if present and non-empty, else the entry's default-locale route slug, else the short uuid. Editorial **status** is `published` (a publication row exists for any locale), else `scheduled` (a pending publish schedule exists), else `draft`. Pagination mirrors the delivery offset convention (`?page`/`?perPage`, clamped to `lemma.delivery.max_per_page`).

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/Http/EntryListApiTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\CreateEntryData;
use App\Content\Http\DTOs\Requests\EntryListQuery;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Validation\FieldValidator;
use App\Content\Schema\Migration\SchemaProjector;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\LemmaTestCase;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class EntryListApiTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page', 'name' => 'Page',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function controller(LocaleManagerInterface $locales = new FakeLocaleManager()): EntryController
    {
        return new EntryController(
            $this->appContext(),
            new EntryRepository($this->connection(), $this->appContext(), new ContentTypeRepository($this->connection())),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new RouteRepository($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
            new ContentLocaleService($this->appContext(), $locales),
            $this->container()->get(SchemaProjector::class),
        );
    }

    /** @param class-string<RequestData> $dto @param array<string,mixed> $body */
    private function hydrate(string $dto, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dto, $body);
    }

    private function newEntryWithTitle(string $title): string
    {
        $create = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'page', 'locale' => 'en']),
            new Request(),
        );
        $uuid = json_decode((string) $create->getContent(), true)['data']['entry']['uuid'];
        $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => $title], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        return $uuid;
    }

    /** @param array<string,mixed> $query */
    private function listQuery(array $query): EntryListQuery
    {
        /** @var EntryListQuery $dto */
        $dto = $this->hydrate(EntryListQuery::class, $query);
        return $dto;
    }

    public function testListReturnsDraftInclusiveRowsWithDisplayTitleAndStatus(): void
    {
        $this->newEntryWithTitle('Home');
        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertCount(1, $data['entries']);
        $row = $data['entries'][0];
        self::assertSame('Home', $row['display_title'], 'display title derives from default-locale draft title');
        self::assertSame('draft', $row['status'], 'an unpublished entry is draft (the list is draft-inclusive)');
        self::assertSame(['en'], $row['locales']);
        self::assertArrayHasKey('uuid', $row);
        self::assertArrayHasKey('updated_at', $row);
        self::assertSame(1, $data['total']);
        self::assertSame(1, $data['current_page']);
    }

    public function testUnknownTypeReturns404(): void
    {
        $resp = $this->controller()->index($this->listQuery(['type' => 'nope']));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testMissingTypeIsRejectedByValidation(): void
    {
        // `type` is required on EntryListQuery, so hydration fails (the router turns this into a
        // 422 in the HTTP flow) BEFORE the controller runs — the 422 is the DTO's job, not index()'s.
        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->hydrate(EntryListQuery::class, []);
    }

    public function testDisplayTitleFallsBackToUuidWhenNoTitleOrRoute(): void
    {
        // Create an entry but never save a title field.
        $create = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'page', 'locale' => 'en']),
            new Request(),
        );
        $uuid = json_decode((string) $create->getContent(), true)['data']['entry']['uuid'];

        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));
        $row = json_decode((string) $resp->getContent(), true)['data']['entries'][0];
        self::assertSame($uuid, $row['display_title'], 'falls back to the entry uuid');
    }

    public function testQueryFilterMatchesDisplayTitle(): void
    {
        $this->newEntryWithTitle('Alpha');
        $this->newEntryWithTitle('Beta');

        $resp = $this->controller()->index($this->listQuery(['type' => 'page', 'q' => 'alph']));
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertCount(1, $data['entries']);
        self::assertSame('Alpha', $data['entries'][0]['display_title']);
    }

    public function testListResponseMatchesDtoShape(): void
    {
        $this->newEntryWithTitle('Home');
        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryListData::class);
        self::assertDataMatchesDtoShape(
            $data['entries'][0],
            \App\Content\Http\DTOs\Responses\Entries\EntryListItemData::class,
        );
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter EntryListApiTest`
Expected: FAIL — `Call to undefined method App\Content\Http\Controllers\EntryController::index()`.

- [ ] **Step 3: Implement `EntryRepository::listForType`.** Add this method to `app/Content/Repositories/EntryRepository.php` (after `localeSummary`, before `emptyScheduleSummary`):
```php
    /**
     * Draft-inclusive admin list for one content type. Returns a page of entries (any
     * editorial state — draft/scheduled/published), newest-updated first, each carrying a
     * derived display title, an editorial status, the set of locales present, and
     * updated_at. `$q` filters on the derived display title (case-insensitive substring).
     *
     * Unlike the delivery repository (which reads only the publication spine), this reads
     * the `entries` identity table so unpublished drafts are included — that is the whole
     * point of the admin list. The per-entry aggregates (draft titles, publications,
     * routes, schedules) are gathered in bounded follow-up queries keyed by the page's
     * entry uuids, so this is O(1) round-trips, not N+1.
     *
     * @return array{
     *   entries: list<array{uuid:string,display_title:string,status:string,locales:list<string>,updated_at:?string}>,
     *   total:int, current_page:int, per_page:int
     * }
     */
    public function listForType(string $typeUuid, string $defaultLocale, int $page, int $perPage, ?string $q): array
    {
        $base = $this->db->table('entries')
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('status', '=', 'active');

        $total = (int) $base->count();

        $entryRows = $this->db->table('entries')
            ->select(['uuid', 'updated_at'])
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('status', '=', 'active')
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        $uuids = array_map(static fn (array $r): string => (string) $r['uuid'], $entryRows);
        if ($uuids === []) {
            return ['entries' => [], 'total' => 0, 'current_page' => $page, 'per_page' => $perPage];
        }

        // Bounded aggregates keyed by entry uuid (no per-row queries).
        $draftsByEntry = [];   // entry => [locale => fields[]]
        foreach ($this->db->table('entry_drafts')->whereIn('entry_uuid', $uuids)->get() as $row) {
            $raw = $row['fields'] ?? [];
            $fields = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $draftsByEntry[(string) $row['entry_uuid']][(string) $row['locale']] = $fields;
        }

        $localesByEntry = [];  // entry => set of locales (from drafts ∪ publications)
        foreach ($draftsByEntry as $entry => $byLocale) {
            foreach (array_keys($byLocale) as $loc) {
                $localesByEntry[$entry][$loc] = true;
            }
        }

        $publishedEntries = [];
        foreach ($this->db->table('entry_publications')->whereIn('entry_uuid', $uuids)->get() as $row) {
            $entry = (string) $row['entry_uuid'];
            $publishedEntries[$entry] = true;
            $localesByEntry[$entry][(string) $row['locale']] = true;
        }

        $scheduledEntries = [];
        foreach (
            $this->db->table('entry_schedules')
                ->whereIn('entry_uuid', $uuids)
                ->where('status', '=', 'pending')
                ->where('action', '=', 'publish')
                ->get() as $row
        ) {
            $scheduledEntries[(string) $row['entry_uuid']] = true;
        }

        $routeSlugByEntry = []; // entry => default-locale slug
        foreach (
            $this->db->table('entry_routes')
                ->whereIn('entry_uuid', $uuids)
                ->where('locale', '=', $defaultLocale)
                ->get() as $row
        ) {
            $routeSlugByEntry[(string) $row['entry_uuid']] = (string) ($row['slug'] ?? '');
        }

        $items = [];
        foreach ($entryRows as $row) {
            $uuid = (string) $row['uuid'];
            $draftTitle = $draftsByEntry[$uuid][$defaultLocale]['title'] ?? null;
            $display = is_string($draftTitle) && trim($draftTitle) !== ''
                ? $draftTitle
                : ($routeSlugByEntry[$uuid] ?? '') ?: $uuid;

            $status = isset($publishedEntries[$uuid])
                ? 'published'
                : (isset($scheduledEntries[$uuid]) ? 'scheduled' : 'draft');

            $locales = array_keys($localesByEntry[$uuid] ?? []);
            sort($locales);

            if ($q !== null && $q !== '' && stripos($display, $q) === false) {
                continue;
            }

            $items[] = [
                'uuid' => $uuid,
                'display_title' => (string) $display,
                'status' => $status,
                'locales' => array_values($locales),
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }

        // Page the post-filter list (filtering is on the derived title, so it happens in PHP).
        $filteredTotal = $q !== null && $q !== '' ? count($items) : $total;
        $offset = ($page - 1) * $perPage;
        $items = array_slice($items, $offset, $perPage);

        return [
            'entries' => array_values($items),
            'total' => $filteredTotal,
            'current_page' => $page,
            'per_page' => $perPage,
        ];
    }
```

- [ ] **Step 4: Implement the response DTOs.**

`app/Content/Http/DTOs/Responses/Entries/EntryListItemData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of one admin entry-list row (see EntryController::index). Display title is
 * derived by convention; status is the coarse editorial state (draft|scheduled|published).
 */
final class EntryListItemData implements ResponseData
{
    /** @param list<string> $locales */
    public function __construct(
        public readonly string $uuid,
        public readonly string $display_title,
        public readonly string $status,
        public readonly array $locales,
        public readonly ?string $updated_at,
    ) {
    }
}
```

`app/Content/Http/DTOs/Responses/Entries/EntryListData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the admin entry-list envelope (see EntryController::index).
 */
final class EntryListData implements ResponseData
{
    /** @param list<EntryListItemData> $entries */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $current_page,
        public readonly int $per_page,
    ) {
    }
}
```

> Confirm the `ResponseData` contract FQCN before implementing: `grep -rn "interface ResponseData" vendor/glueful/framework/src`. The existing DTOs (e.g. `EntryResultData`) implement `Glueful\Http\Contracts\ResponseData`; match whatever they use.

- [ ] **Step 5: Implement the query DTO + `EntryController::index`.**

First create the request DTO `app/Content/Http/DTOs/Requests/EntryListQuery.php` — it carries the query-param OpenAPI docs via `#[FromQuery(description:)]` (so the controller needs no `#[QueryParam]`), matching `app/Content/Http/DTOs/Requests/Delivery/DeliveryListQuery.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Requests;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for the draft-inclusive admin entry list
 * ({@see \App\Content\Http\Controllers\EntryController::index()}). The router hydrates this from
 * the query string; the required-`type` rule fails hydration (→ 422) BEFORE the controller runs.
 * Int clamping for page/perPage stays in the controller.
 */
final class EntryListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Content type slug to list.')]
        #[Rule(['required', 'string'])]
        public readonly string $type,
        #[FromQuery(description: 'Case-insensitive substring filter on the derived display title.')]
        #[Rule('string')]
        public readonly ?string $q = null,
        // Query-string ints arrive as strings; `numeric` accepts "2" where `integer` (a gettype()
        // check) would reject it. The `?int` param then coerces it.
        #[FromQuery(description: 'Page number (default 1).')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page (clamped to lemma.delivery.max_per_page; default lemma.delivery.default_per_page).')]
        #[Rule('numeric')]
        public readonly ?int $perPage = null,
    ) {
    }
}
```
> Confirm the `FromQuery`/`Rule`/`RequestData` FQCNs and the `DTOs/Requests/` location against the existing `DeliveryListQuery` before implementing — match whatever the existing query DTOs use.

Then add to `app/Content/Http/Controllers/EntryController.php` the import (no `QueryParam` import — the docs live on the DTO):
```php
use App\Content\Http\DTOs\Requests\EntryListQuery;
use App\Content\Http\DTOs\Responses\Entries\EntryListData;
```
Then add the method (before `store()`):
```php
    /**
     * Draft-inclusive admin list of entries for a content type (`?type={slug}`). Unlike the
     * public delivery list, this includes drafts/scheduled/unpublished entries — it reads the
     * `entries` identity table, not the publication spine. Each row carries a derived display
     * title, the coarse editorial status, the locales present, and updated_at. Offset paged
     * (`?page`/`?perPage`, perPage clamped); optional `?q=` filters on the display title.
     */
    #[ApiOperation(
        summary: 'List entries of a content type (draft-inclusive)',
        description: 'Returns a page of entries for the content type named by `type` (slug), INCLUDING '
            . 'drafts/scheduled/unpublished entries (this is the admin authoring list, not the published '
            . 'delivery feed). Each row has a derived `display_title`, editorial `status` '
            . '(draft|scheduled|published), the `locales` present, and `updated_at`. Offset paged via '
            . '`page`/`perPage`; `q` filters on the display title. Requires the `lemma.entries.read` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, schema: EntryListData::class, description: 'A page of entries.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Missing/invalid `type` query parameter.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    // The query params (type/q/page/perPage) are documented + validated by EntryListQuery.
    public function index(EntryListQuery $query): Response
    {
        // `type` is guaranteed present + a string by the DTO's required rule (else 422 at hydration).
        $typeRow = $this->types->findBySlug($query->type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }

        $page = max(1, $query->page ?? 1);
        $max = (int) config($this->context, 'lemma.delivery.max_per_page', 100);
        $default = (int) config($this->context, 'lemma.delivery.default_per_page', 20);
        $perPage = $query->perPage ?? $default;
        $perPage = $perPage < 1 ? $default : min($perPage, $max);

        $result = $this->entries->listForType(
            (string) $typeRow['uuid'],
            $this->locales->default(),
            $page,
            $perPage,
            ($query->q !== null && $query->q !== '') ? $query->q : null,
        );

        return Response::success($result, 'Entries retrieved.');
    }
```

- [ ] **Step 6: Register the route.** In `routes/lemma_admin.php`, inside the `/v1/admin` group, immediately before the `$router->post('/entries', ...)` line:
```php
    $router->get('/entries', [EntryController::class, 'index'])
        ->middleware('lemma_permission:lemma.entries.read');
```

- [ ] **Step 7: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter EntryListApiTest`
Expected: PASS — draft-inclusive rows, display-title derivation + uuid fallback, unknown-type 404, missing-`type` rejected by `EntryListQuery` validation (422 at the HTTP layer), `q` filter, and the DTO-shape assertions.

- [ ] **Step 8: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Repositories/EntryRepository.php app/Content/Http/Controllers/EntryController.php \
  app/Content/Http/DTOs/Requests/EntryListQuery.php \
  app/Content/Http/DTOs/Responses/Entries/EntryListItemData.php \
  app/Content/Http/DTOs/Responses/Entries/EntryListData.php \
  routes/lemma_admin.php tests/Integration/Http/EntryListApiTest.php
git commit -m "Add draft-inclusive admin entry-list endpoint"
```

---

### Task 0b: Unauthenticated runtime config (`GET /admin/config.json`)

**Files:**
- Modify: `config/lemma.php`
- Create: `app/Content/Http/Controllers/AdminConfigController.php`
- Create: `routes/lemma_admin_spa.php` (config route added here; asset routes added in 0c)
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Http/AdminConfigApiTest.php`

The endpoint is deliberately **outside** the `/v1/admin` `auth` group — the SPA needs `apiBase` before login. It returns the install-specific values the compiled bundle must not bake in.

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/Http/AdminConfigApiTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\AdminConfigController;
use App\Tests\Support\LemmaTestCase;

final class AdminConfigApiTest extends LemmaTestCase
{
    public function testReturnsRuntimeConfigKeys(): void
    {
        $controller = new AdminConfigController($this->appContext());
        $resp = $controller->config();

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertArrayHasKey('apiBase', $body);
        self::assertArrayHasKey('sitePreviewUrl', $body);
        self::assertArrayHasKey('defaultLocale', $body);
        self::assertSame('/v1/admin', $body['apiBase']);
    }

    public function testConfigRouteIsRegisteredUnauthenticated(): void
    {
        // The SPA needs apiBase BEFORE it can log in, so this route must NOT be in the
        // /v1/admin auth group. Assert it is registered and carries no `auth` middleware.
        $route = $this->findRoute('GET', '/admin/config.json');
        self::assertNotNull($route, '/admin/config.json must be registered');
        $middleware = (array) ($route['middleware'] ?? []);
        self::assertNotContains('auth', $middleware, 'config.json must be unauthenticated');
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter AdminConfigApiTest`
Expected: FAIL — `Class "App\Content\Http\Controllers\AdminConfigController" not found`.

- [ ] **Step 3: Add the config block.** In `config/lemma.php`, append before the closing `];`:
```php
    // Admin SPA runtime config (served UNAUTHENTICATED at GET /admin/config.json so the
    // compiled bundle is not env-baked — one build works across installs). See
    // docs/superpowers/specs/2026-06-17-admin-spa-phase-1-design.md §"Runtime config".
    'admin' => [
        // The admin API base the SPA calls. Lemma's admin routes are hardcoded /v1/admin.
        'api_base' => env('LEMMA_ADMIN_API_BASE', '/v1/admin'),
        // The frontend preview URL template; the SPA appends/embeds the minted token.
        'site_preview_url' => env('LEMMA_SITE_PREVIEW_URL', ''),
        // Phase 1 is en-only in the UI; locale stays in the data model.
        'default_locale' => env('LEMMA_ADMIN_DEFAULT_LOCALE', (string) env('I18N_DEFAULT_LOCALE', 'en')),
    ],
```

- [ ] **Step 4: Implement the controller.** Create `app/Content/Http/Controllers/AdminConfigController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves the admin SPA's runtime config as raw JSON at the UNAUTHENTICATED
 * `GET /admin/config.json`. This must NOT sit behind the `/v1/admin` auth group: the SPA
 * fetches it at boot, before it has a token, to learn the API base. Values come from
 * `config('lemma.admin.*')` (env-overridable), so one compiled bundle works across installs.
 *
 * Returns a bare JSON object (NOT the framework `data`-envelope) because the SPA reads
 * `apiBase`/`sitePreviewUrl`/`defaultLocale` at the top level — a plain config document.
 * Returns a Symfony `JsonResponse` directly; the Glueful router accepts any Symfony
 * `Response` return (`Glueful\Http\Response` extends it), so no envelope/bridge is needed.
 */
final class AdminConfigController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function config(): JsonResponse
    {
        $payload = [
            'apiBase' => (string) config($this->context, 'lemma.admin.api_base', '/v1/admin'),
            'sitePreviewUrl' => (string) config($this->context, 'lemma.admin.site_preview_url', ''),
            'defaultLocale' => (string) config($this->context, 'lemma.admin.default_locale', 'en'),
        ];

        // No-store: install config can change without a rebuild; the SPA must read it fresh.
        $json = new JsonResponse($payload);
        $json->headers->set('Cache-Control', 'no-store');
        return $json;
    }
}
```

> The `config()` method returns a Symfony `JsonResponse` directly — there is no `Response::fromSymfony()` in the framework, and none is needed: the router normalizes any returned Symfony `Response`. The `AdminConfigApiTest` assertions (`getStatusCode()`/`getContent()`) hold unchanged.

- [ ] **Step 5: Create the SPA route file.** Create `routes/lemma_admin_spa.php`:
```php
<?php

declare(strict_types=1);

use App\Content\Http\Controllers\AdminConfigController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin runtime config — UNAUTHENTICATED by design. config.json is fetched by the SPA before
 * login. Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file
 * (it would double-register).
 *
 * The compiled bundle itself (/admin + /admin/{rest}) is NOT served here — it is mounted by
 * the framework's serveFrontend() seam in LemmaServiceProvider::boot() (Task 0c). config.json
 * is a STATIC route, so the router's O(1) static-first lookup always matches it before
 * serveFrontend's dynamic /admin/{rest} catch-all — it is never swallowed by the SPA fallback.
 *
 * This route is NOT under /v1/admin and carries no `auth` middleware; every API call the SPA
 * makes IS auth-gated under /v1/admin.
 */
$router->get('/admin/config.json', [AdminConfigController::class, 'config']);
```

- [ ] **Step 6: Register the controller.** In `app/Providers/LemmaServiceProvider.php`, add the import near the other controllers:
```php
use App\Content\Http\Controllers\AdminConfigController;
```
and add to the `services()` array (beside `EntryController`):
```php
            AdminConfigController::class => [
                'class' => AdminConfigController::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 7: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter AdminConfigApiTest`
Expected: PASS — the controller returns the three keys with `apiBase = /v1/admin`, and `/admin/config.json` is registered without `auth`.

- [ ] **Step 8: phpcs + commit.**
```bash
composer phpcs
git add config/lemma.php app/Content/Http/Controllers/AdminConfigController.php \
  routes/lemma_admin_spa.php app/Providers/LemmaServiceProvider.php \
  tests/Integration/Http/AdminConfigApiTest.php
git commit -m "Add unauthenticated GET /admin/config.json runtime config"
```

---

### Task 0c: Mount the admin bundle at `/admin` via the framework `serveFrontend()` seam

**Files:**
- Modify: `config/lemma.php` (add `admin.bundle_path`), `app/Providers/LemmaServiceProvider.php` (call `serveFrontend()` in `boot()`)
- Test: `tests/Integration/Http/AdminSpaServingTest.php`

> **Requires framework ≥ 1.59.0** (where `serveFrontend()` shipped) — see the *Framework dependency* note at the top of this plan. Lemma is already pinned `glueful/framework: ^1.59.0`, so this is **unblocked**; **Step 0** confirms the seam is present in the pinned framework before the rest of the task runs. (Tasks 0a/0b and the frontend can still proceed independently.)

The framework seam serves a built bundle at a literal path with secure asset serving (traversal guard, dotfile/`.php` denial, mime detection, ETag/304), an `index.html` deep-link fallback for client-side routing, and a cache split (immutable content-hashed assets, `no-cache` shell). That is exactly the admin SPA's need, so Phase 1 hand-rolls **no** controller — it calls the seam from the provider. The bundle path is read from `config('lemma.admin.bundle_path')` so ops can relocate it and tests can point it at a fixture.

- [ ] **Step 0: Confirm the seam is present in the pinned framework.**

Run: `grep -n "function serveFrontend" vendor/glueful/framework/src/Extensions/ServiceProvider.php`
Expected: one `protected function serveFrontend(` match. If absent, the framework release carrying this seam is not pinned yet — STOP (see the *Framework dependency* note).

- [ ] **Step 1: Add the bundle-path + enable config.** In `config/lemma.php`, inside the `'admin' => [ ... ]` block added in Task 0b, add:
```php
        // Whether the default first-party admin SPA is mounted at /admin. The bundled admin is a
        // REPLACEABLE client of the /v1/admin API — set this false to bring your own (point
        // bundle_path at your build, or disable and register a different mount in a provider).
        'enabled' => (bool) env('LEMMA_ADMIN_ENABLED', true),
        // Filesystem dir of the compiled SPA bundle the framework serveFrontend() seam mounts
        // at /admin. Defaults to public/admin (baked into the release tag by .github/workflows/
        // release.yml; gitignored in dev). Override for tests/relocation/a custom admin.
        'bundle_path' => env('LEMMA_ADMIN_BUNDLE_PATH', dirname(__DIR__) . '/public/admin'),
```
(`config/` sits one level under the project root, so `dirname(__DIR__)` is that root.)

- [ ] **Step 1b: Create the pre-boot bundle fixture + point the test env at it.** `serveFrontend()` no-ops (registers no `/admin` route) unless `bundle_path` holds an `index.html`, and the Lemma container boots process-globally — so the fixture must exist *and* `bundle_path` must point at it *before the first boot*. Make both concrete and committed:

  (a) Create `tests/fixtures/admin/index.html` (a minimal, real bundle shell — content is irrelevant to the wiring test, only its presence matters):
```html
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><title>Lemma Admin (test fixture)</title></head>
<body><div id="app"></div></body></html>
```

  (b) In `phpunit.xml`, under `<php>`, add the env var so `lemma.admin.bundle_path` resolves to the fixture at boot:
```xml
        <env name="LEMMA_ADMIN_BUNDLE_PATH" value="tests/fixtures/admin"/>
```
  This sets the env before PHPUnit loads the bootstrap, so the value is in place before the process-global container boots — no per-test override needed.

- [ ] **Step 2: Write the failing wiring test.** Create `tests/Integration/Http/AdminSpaServingTest.php`. This is a thin **wiring** test — it proves the provider mounts the bundle (the `/admin` route is registered after boot) and that the config route still resolves as itself. Exhaustive behavior (traversal, cache split, dot-rule, deep-link fallback) is owned by the framework's own `ServeFrontendTest`, not re-tested here.
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\LemmaTestCase;

final class AdminSpaServingTest extends LemmaTestCase
{
    public function testAdminBundleIsMountedAtAdmin(): void
    {
        // serveFrontend() registers the SPA root route only when bundle_path exists and holds
        // index.html. phpunit.xml points LEMMA_ADMIN_BUNDLE_PATH at tests/fixtures/admin (Step 1b),
        // which holds a committed index.html, so the mount is wired during the process-global boot.
        $route = $this->findRoute('GET', '/admin');
        self::assertNotNull($route, '/admin must be mounted by serveFrontend()');
    }

    public function testConfigJsonIsNotShadowedBySpaCatchAll(): void
    {
        // The static config.json route (Task 0b) must still resolve as itself, never as the SPA
        // fallback — the router's static-first lookup guarantees this.
        $route = $this->findRoute('GET', '/admin/config.json');
        self::assertNotNull($route, '/admin/config.json must remain its own static route');
    }
}
```

> **Bundle fixture / boot timing.** This test depends on the concrete fixture + env from **Step 1b**: the committed `tests/fixtures/admin/index.html` and the `<env name="LEMMA_ADMIN_BUNDLE_PATH" value="tests/fixtures/admin"/>` entry in `phpunit.xml`. Because PHPUnit applies `<php><env>` before loading the bootstrap, `lemma.admin.bundle_path` already points at the fixture when the process-global container boots — so `serveFrontend()` sees an `index.html` and registers `/admin`. If `/admin` is *not* found in a run, the env entry is missing from `phpunit.xml` (or the fixture file is absent), not a timing race — re-check Step 1b. (`lemma.admin.enabled` defaults `true`, so the mount is on under test with no extra env.)

- [ ] **Step 3: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter AdminSpaServingTest`
Expected: FAIL — `/admin` not found (the provider does not mount it yet).

- [ ] **Step 4: Mount the bundle in the provider.** In `app/Providers/LemmaServiceProvider.php`, inside `boot(ApplicationContext $context)`, add (serveFrontend registers routes directly on the router — it is **not** a `loadRoutesFrom()` call, so it does not double-register with `RouteManifest`):
```php
        // Mount the compiled admin SPA at /admin via the framework seam: secure asset serving
        // + index.html deep-link fallback + cache split. No-ops (with a warning) if the bundle
        // is unbuilt. The /admin/config.json static route (routes/lemma_admin_spa.php) keeps
        // precedence over the SPA catch-all via the router's static-first lookup.
        // Gated by lemma.admin.enabled so an operator can disable the default admin and bring
        // their own (the admin is a replaceable client of the /v1/admin API).
        if ((bool) config($context, 'lemma.admin.enabled', true)) {
            $this->serveFrontend(
                '/admin',
                (string) config($context, 'lemma.admin.bundle_path', dirname(__DIR__, 2) . '/public/admin'),
                ['name' => 'Lemma Admin'],
            );
        }
```

- [ ] **Step 5: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter AdminSpaServingTest`
Expected: PASS — `/admin` is mounted and `/admin/config.json` remains its own route.

- [ ] **Step 6: phpcs + commit.**
```bash
composer phpcs
git add config/lemma.php app/Providers/LemmaServiceProvider.php phpunit.xml \
  tests/fixtures/admin/index.html tests/Integration/Http/AdminSpaServingTest.php
git commit -m "Mount admin SPA at /admin via framework serveFrontend() seam"
```

---

### Task 0d: First-run setup (web) — create the first admin (`POST /admin/setup`)

**Files:**
- Create: `database/migrations/013_CreateLemmaSettingsTable.php`
- Create: `app/Setup/SetupService.php`
- Create: `app/Content/Http/DTOs/Requests/SetupData.php`
- Create: `app/Content/Http/Controllers/SetupController.php`
- Modify: `app/Content/Http/Controllers/AdminConfigController.php` (add `installed` to the payload)
- Modify: `routes/lemma_admin_spa.php` (add `POST /admin/setup`)
- Modify: `app/Providers/LemmaServiceProvider.php` (register `SetupService` + `SetupController`)
- Test: `tests/Integration/Http/SetupApiTest.php`

The editorial loop needs a logged-in admin, and a fresh install has none. This task adds a **web** first-run setup that creates the first admin (via `glueful/users`) + grants the admin role (via `glueful/aegis`) and writes a couple of site settings (`site_name`, `default_locale`). It rides on the surfaces Task 0b/0c already established: the unauthenticated `GET /admin/config.json` (which now also reports `installed`) and the unauthenticated `routes/lemma_admin_spa.php` route file.

> **Out of scope (CLI).** The standalone `lemma` CLI and the **CLI cut** of setup (`lemma setup` / `php glueful lemma:setup`, plus the infra layer: DB creds, `APP_KEY`/`JWT_KEY`, migrations) are **NOT** in this plan — they are designed in `docs/superpowers/specs/2026-06-19-lemma-cli-onboarding-design.md`. DB credentials are never written from an HTTP request (a 12-factor/security footgun); web setup only creates the first admin + site settings. The web setup deliberately shares one `SetupService` with that future console command — **build the service standalone here so the CLI can call the identical `install()` path** (do NOT build the CLI command in this task).

> **Security posture (explicit).** `POST /admin/setup` is **unauthenticated** (there is no admin yet to authenticate against) but **self-locks permanently**: once an admin exists / the `installed` marker is set, the endpoint returns `409` forever — it can never create a second "first" admin. `install()` is **race-safe**: it runs inside a DB transaction that re-checks `isInstalled()` and relies on a **unique constraint** (the `installed` settings key is a primary key; `glueful/users` enforces email uniqueness) as the backstop, so two concurrent setup requests cannot both win — the loser's insert violates the constraint and its transaction rolls back, leaving exactly one admin.

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/Http/SetupApiTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\AdminConfigController;
use App\Content\Http\Controllers\SetupController;
use App\Content\Http\DTOs\Requests\SetupData;
use App\Setup\SetupService;
use App\Tests\Support\LemmaTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;

final class SetupApiTest extends LemmaTestCase
{
    private function service(): SetupService
    {
        return $this->container()->get(SetupService::class);
    }

    private function controller(): SetupController
    {
        return new SetupController($this->appContext(), $this->service());
    }

    /** @param array<string,mixed> $body */
    private function setupData(array $body): SetupData
    {
        /** @var SetupData $dto */
        $dto = (new RequestDataHydrator())->hydrate(SetupData::class, $body);
        return $dto;
    }

    private function validBody(): array
    {
        return [
            'site_name' => 'getlemma.dev',
            'admin_email' => 'admin@getlemma.dev',
            'admin_password' => 'correct horse battery',
            'locale' => 'en',
        ];
    }

    public function testFirstSetupCreatesAdminAndFlipsInstalled(): void
    {
        self::assertFalse($this->service()->isInstalled(), 'a fresh install is not installed');

        $resp = $this->controller()->setup($this->setupData($this->validBody()));

        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue($this->service()->isInstalled(), 'install() flips the installed marker');
        // The admin now exists and can be looked up by email (created via glueful/users).
        self::assertNotNull(
            $this->container()->get(\Glueful\Auth\Contracts\UserProviderInterface::class)
                ->findByEmail('admin@getlemma.dev'),
            'the first admin was created',
        );
    }

    public function testSecondSetupIsPermanentlyLockedWith409(): void
    {
        $this->controller()->setup($this->setupData($this->validBody()));

        $second = $this->controller()->setup($this->setupData([
            'site_name' => 'evil.example',
            'admin_email' => 'attacker@evil.example',
            'admin_password' => 'another password',
            'locale' => 'en',
        ]));

        self::assertSame(409, $second->getStatusCode(), 'setup self-locks once installed');
        self::assertNull(
            $this->container()->get(\Glueful\Auth\Contracts\UserProviderInterface::class)
                ->findByEmail('attacker@evil.example'),
            'no second admin is ever created',
        );
    }

    public function testConfigJsonReportsInstalledBeforeAndAfter(): void
    {
        $config = new AdminConfigController($this->appContext(), $this->service());

        $before = json_decode((string) $config->config()->getContent(), true);
        self::assertFalse($before['installed'], 'config.json reports installed:false before setup');

        $this->controller()->setup($this->setupData($this->validBody()));

        $after = json_decode((string) $config->config()->getContent(), true);
        self::assertTrue($after['installed'], 'config.json reports installed:true after setup');
    }

    public function testGateIsBoundToTheInstalledInvariantNotASoftCheck(): void
    {
        // Guard test: the lock is the installed/no-admin INVARIANT, not a one-shot flag the
        // controller flips. Install via the service directly (bypassing the controller), then a
        // controller setup must STILL be refused — proving the gate reads isInstalled(), which is
        // backed by the persisted marker / first-admin uniqueness, not controller-local state.
        $this->service()->install('seeded.example', 'seed@example.test', 'seed password', 'en');

        $resp = $this->controller()->setup($this->setupData($this->validBody()));
        self::assertSame(409, $resp->getStatusCode());
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `composer test:phpunit -- --filter SetupApiTest`
Expected: FAIL — `Class "App\Setup\SetupService" not found`.

- [ ] **Step 3: Create the settings-store migration.** Create `database/migrations/013_CreateLemmaSettingsTable.php` (matches the existing migration style — `MigrationInterface`, idempotent `hasTable` guard, `down()` rollback; see `001_CreateContentTypesTable.php`):
```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Runtime-settable key/value settings for Lemma (NOT env/infra config). Holds values an admin
 * can change without a redeploy — `site_name`, `default_locale` — plus the `installed` marker
 * that first-run setup (SetupService) sets exactly once. `key` is the PRIMARY KEY, which is the
 * race-safety backstop: two concurrent installs cannot both insert `installed`.
 */
final class CreateLemmaSettingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('lemma_settings')) {
            return;
        }
        $schema->createTable('lemma_settings', function ($table) {
            $table->string('key', 191)->primary();   // e.g. site_name, default_locale, installed
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('lemma_settings');
    }

    public function getDescription(): string
    {
        return 'Create lemma_settings (runtime key/value store + first-run installed marker).';
    }
}
```

> Migration number `013` is the next free index (`012_CreateEntrySchedulesTable.php` is the highest; `008` is already skipped). If a `013_*` exists at execution, bump to the next free integer — the class name + table are what matter, not the exact number.

- [ ] **Step 4: Implement `SetupService` (the single source of truth for install).** Create `app/Setup/SetupService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Setup;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Glueful\Extensions\Aegis\Services\RoleService;

/**
 * Single source of truth for first-run install. Both the web setup (SetupController, this plan)
 * and the future `php glueful lemma:setup` CLI command (see
 * docs/superpowers/specs/2026-06-19-lemma-cli-onboarding-design.md) call install() — there is
 * exactly ONE install path, so the web and CLI cuts can never diverge.
 *
 * install() is RACE-SAFE: it runs in a DB transaction that (a) re-checks isInstalled(),
 * (b) creates the first admin via glueful/users, (c) grants the admin role via glueful/aegis,
 * (d) writes site_name/default_locale, (e) sets the `installed` marker — all atomic. The
 * lemma_settings PRIMARY KEY on `key` (plus glueful/users' unique email) is the backstop: if two
 * setups race past the re-check, the second insert of `installed` violates the PK and its
 * transaction rolls back, so exactly one admin is ever created.
 */
final class SetupService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly UserProviderInterface $users,
        private readonly RoleService $roles,
        private readonly RoleRepository $roleRepo,
    ) {
    }

    /** Installed iff the `installed` marker is set (equivalently: an admin user exists). */
    public function isInstalled(): bool
    {
        $row = $this->db->table('lemma_settings')
            ->where('key', '=', 'installed')
            ->first();

        return $row !== null && (string) ($row['value'] ?? '') !== '';
    }

    public function install(string $siteName, string $adminEmail, string $adminPassword, string $locale): void
    {
        $this->db->transaction(function () use ($siteName, $adminEmail, $adminPassword, $locale): void {
            // (a) Re-check inside the transaction — collapse the check-then-act window.
            if ($this->isInstalled()) {
                return;
            }

            // (b) Create the first admin via glueful/users. Email uniqueness is enforced by the
            //     users table, a second backstop against a concurrent first admin.
            $adminUuid = $this->users->create([
                'username' => $adminEmail,
                'email' => $adminEmail,
                'password' => $adminPassword,
                'status' => 'active',
            ]);

            // (c) Grant the Lemma admin role via glueful/aegis (role slug from config).
            $roleSlug = (string) config($this->context, 'lemma.roles.admin', 'lemma_admin');
            $role = $this->roleRepo->getRoleBySlug($roleSlug);
            if ($role !== null) {
                $this->roles->assignRoleToUser($adminUuid, $role->uuid);
            }

            // (d) Write runtime site settings.
            $this->put('site_name', $siteName);
            $this->put('default_locale', $locale);

            // (e) Set the installed marker LAST — its INSERT on the `key` PRIMARY KEY is the
            //     race backstop: a concurrent install that got here too violates the PK and
            //     rolls back, so only one transaction commits an admin.
            $this->put('installed', '1');
        });
    }

    private function put(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->db->table('lemma_settings')->where('key', '=', $key)->first();
        if ($existing !== null) {
            $this->db->table('lemma_settings')
                ->where('key', '=', $key)
                ->update(['value' => $value, 'updated_at' => $now]);
            return;
        }
        // INSERT (not upsert) for `installed` so a racing duplicate hits the PK constraint.
        $this->db->table('lemma_settings')->insert([
            'key' => $key,
            'value' => $value,
            'updated_at' => $now,
        ]);
    }
}
```

> Confirm the aegis/users seams before implementing: `grep -rn "function getRoleBySlug" vendor/glueful/aegis/src/Repositories/RoleRepository.php` (role lookup), `grep -rn "function assignRoleToUser" vendor/glueful/aegis/src/Services/RoleService.php`, `grep -rn "function create\b" vendor/glueful/users/src/Repositories/UserRepository.php` and the `UserProviderInterface` create/findByEmail surface. Match whatever the pinned versions expose (the `RoleService::assignRoleToUser($userUuid, $roleUuid)` and `UserRepository::create([...])` shapes are confirmed against the vendored code; adjust if a minor version moved them).

- [ ] **Step 5: Implement the `SetupData` DTO.** Create `app/Content/Http/DTOs/Requests/SetupData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Requests;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * First-run web setup input (POST /admin/setup). Unauthenticated but self-locking — see
 * SetupController. Validation is intentionally strict: a real email and a non-trivial admin
 * password, since this seeds the only privileged account.
 */
final class SetupData implements RequestData
{
    public function __construct(
        #[Rule(['required', 'string', 'max:200'])]
        public readonly string $site_name,
        #[Rule(['required', 'email'])]
        public readonly string $admin_email,
        #[Rule(['required', 'string', 'min:12'])]
        public readonly string $admin_password,
        #[Rule(['required', 'string', 'max:12'])]
        public readonly string $locale = 'en',
    ) {
    }
}
```

> Confirm the validation surface before implementing: `grep -rn "class Rule" vendor/glueful/framework/src/Validation/Attributes` and how the existing request DTOs (e.g. `CreateEntryData`/`SaveDraftData`) attach `#[Rule]` — match their exact attribute shape (rule-array vs per-rule attributes). The point is required + email + min-password-length; align the syntax to the codebase's other RequestData DTOs.

- [ ] **Step 6: Implement the `SetupController` (unauthenticated, self-locking).** Create `app/Content/Http/Controllers/SetupController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Http\DTOs\Requests\SetupData;
use App\Setup\SetupService;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * First-run web setup — UNAUTHENTICATED by design (there is no admin yet to authenticate), but
 * SELF-LOCKING: once SetupService::isInstalled() is true it returns 409 forever, so a second
 * "first" admin can never be created. The heavy lifting (and the race-safety) lives in
 * SetupService::install(), which the future `php glueful lemma:setup` CLI shares.
 *
 * Returns a Symfony JsonResponse directly; the Glueful router normalizes any returned Symfony
 * Response (there is no Response::fromSymfony bridge).
 */
final class SetupController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SetupService $setup,
    ) {
    }

    public function setup(SetupData $input): JsonResponse
    {
        // Permanent lock: refuse once installed. This is the gate; install() ALSO re-checks
        // inside its transaction, so even a TOCTOU race past this point cannot double-create.
        if ($this->setup->isInstalled()) {
            return new JsonResponse(
                ['message' => 'Setup has already been completed.'],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $this->setup->install(
            $input->site_name,
            $input->admin_email,
            $input->admin_password,
            $input->locale,
        );

        return new JsonResponse(['message' => 'Setup complete.', 'installed' => true]);
    }
}
```

- [ ] **Step 7: Wire `installed` into `GET /admin/config.json`.** Modify `app/Content/Http/Controllers/AdminConfigController.php` (Task 0b) to inject `SetupService` and report `installed`, so the SPA learns at boot whether to route to `/setup` or `/login`. Add the import:
```php
use App\Setup\SetupService;
```
Change the constructor:
```php
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SetupService $setup,
    ) {
    }
```
And add `installed` to the payload in `config()`:
```php
        $payload = [
            'apiBase' => (string) config($this->context, 'lemma.admin.api_base', '/v1/admin'),
            'sitePreviewUrl' => (string) config($this->context, 'lemma.admin.site_preview_url', ''),
            'defaultLocale' => (string) config($this->context, 'lemma.admin.default_locale', 'en'),
            // Whether first-run setup has run. The SPA boot guard routes to /setup when false.
            'installed' => $this->setup->isInstalled(),
        ];
```

> This changes `AdminConfigController`'s constructor — update the `new AdminConfigController(...)` call in `AdminConfigApiTest` (Task 0b) to pass the service too: `new AdminConfigController($this->appContext(), $this->container()->get(SetupService::class))`. (The `SetupApiTest` above already constructs it with both args.)

- [ ] **Step 8: Register the route.** In `routes/lemma_admin_spa.php`, add the import and the unauthenticated setup route alongside `config.json` (still OUTSIDE `/v1/admin`, no `auth` middleware — there is no admin to authenticate against at first run):
```php
use App\Content\Http\Controllers\SetupController;
```
```php
/*
 * First-run setup — UNAUTHENTICATED but self-locking: SetupController returns 409 once installed.
 * Outside /v1/admin (no admin exists yet to auth against). Like config.json, this is a static
 * route, so the router's static-first lookup matches it before serveFrontend's /admin/{rest}
 * catch-all — never swallowed by the SPA fallback.
 */
$router->post('/admin/setup', [SetupController::class, 'setup']);
```

- [ ] **Step 9: Register the services.** In `app/Providers/LemmaServiceProvider.php`, add the imports near the other controllers/services:
```php
use App\Content\Http\Controllers\SetupController;
use App\Setup\SetupService;
```
and add to the `services()` array (beside `AdminConfigController`):
```php
            SetupService::class => [
                'class' => SetupService::class,
                'shared' => true,
                'autowire' => true,
            ],
            SetupController::class => [
                'class' => SetupController::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 10: Run it; verify it passes.**

Run: `composer test:phpunit -- --filter SetupApiTest`
Expected: PASS — first setup creates the admin + flips `installed`; a second setup returns `409` and creates no second admin; `config.json` reports `installed:false` before and `true` after; and the gate is bound to the `installed`/no-admin invariant (install-via-service-then-controller-setup still 409s).

- [ ] **Step 11: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add database/migrations/013_CreateLemmaSettingsTable.php \
  app/Setup/SetupService.php \
  app/Content/Http/DTOs/Requests/SetupData.php \
  app/Content/Http/Controllers/SetupController.php \
  app/Content/Http/Controllers/AdminConfigController.php \
  routes/lemma_admin_spa.php app/Providers/LemmaServiceProvider.php \
  tests/Integration/Http/AdminConfigApiTest.php tests/Integration/Http/SetupApiTest.php
git commit -m "Add unauthenticated, self-locking first-run web setup (POST /admin/setup)"
```

---

## Task group 1 — Vue SPA scaffold (conventions established here)

> **⚠️ SUPERSEDED — do not implement this scaffold.** The Admin SPA project is scaffolded by the
> maintainer on a current stack (**Vite 8 / Vue Router 5 file-based routing / Nuxt UI**). Task
> groups 1–8 are **re-planned against that real scaffold** before any frontend code is written;
> only the app-specific layer (runtime config, typed client + refresh-on-401, Pinia session store,
> domain composables, the Nuxt-UI-based schema-driven field editor, file-based page screens, the
> schema-boundary test) is implemented on top. See the SUPERSEDED note under "Frontend (Task groups
> 1–8)" in the File map for the full division of work + the contracts to preserve. The text below is
> historical intent, not instructions.

There is no existing frontend. This task creates the entire `admin/` toolchain and proves the typed client + runtime-config loader + Vitest harness. Every later frontend task reuses these conventions.

**Files:** all the config files in the File map's "Config" + "Bootstrap" + "Client" rows, plus `admin/src/api/schema.d.ts` (generated), `admin/test/setup.ts`.

- [ ] **Step 1: Write the failing test (runtime config loader).** Create `admin/test/runtimeConfig.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { loadRuntimeConfig, getRuntimeConfig } from '../src/runtimeConfig'

describe('runtimeConfig', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('fetches /admin/config.json and exposes apiBase', async () => {
    vi.stubGlobal('fetch', vi.fn(async () =>
      new Response(JSON.stringify({ apiBase: '/v1/admin', sitePreviewUrl: 'https://getlemma.dev/preview', defaultLocale: 'en', installed: true }))
    ))
    const cfg = await loadRuntimeConfig()
    expect(cfg.apiBase).toBe('/v1/admin')
    expect(getRuntimeConfig().sitePreviewUrl).toBe('https://getlemma.dev/preview')
    expect(getRuntimeConfig().defaultLocale).toBe('en')
    expect(getRuntimeConfig().installed).toBe(true)
  })

  it('throws if config.json cannot be loaded (fail fast at boot)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('nope', { status: 500 })))
    await expect(loadRuntimeConfig()).rejects.toThrow(/runtime config/i)
  })
})
```

- [ ] **Step 2: Create the toolchain config so the test can run.**

`admin/package.json`:
```json
{
  "name": "lemma-admin",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc --noEmit && vite build",
    "preview": "vite preview",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:e2e": "playwright test",
    "gen:api": "node scripts/strip-admin-paths.mjs && openapi-typescript .openapi-admin.json -o src/api/schema.d.ts",
    "typecheck": "vue-tsc --noEmit"
  },
  "dependencies": {
    "@nuxt/ui": "^3.0.0",
    "markdown-it": "^14.1.0",
    "openapi-fetch": "^0.13.0",
    "pinia": "^2.2.0",
    "vue": "^3.5.0",
    "vue-router": "^4.4.0"
  },
  "devDependencies": {
    "@playwright/test": "^1.48.0",
    "@tailwindcss/vite": "^4.0.0",
    "@types/markdown-it": "^14.1.0",
    "@vitejs/plugin-vue": "^5.1.0",
    "@vue/test-utils": "^2.4.6",
    "happy-dom": "^15.0.0",
    "openapi-typescript": "^7.4.0",
    "tailwindcss": "^4.0.0",
    "typescript": "^5.6.0",
    "vite": "^5.4.0",
    "vitest": "^2.1.0",
    "vue-tsc": "^2.1.0"
  }
}
```

> Pin exact versions at install time (`npm install` will resolve the `^` ranges). The `@vitejs/plugin-vue` key above has a deliberate typo guard: the real package is `@vitejs/plugin-vue` — copy it exactly. Nuxt UI's standalone Vue/Vite build is `@nuxt/ui` v3+; see the *Nuxt UI Vue/Vite setup* note below for the exact plugin + CSS wiring (Tailwind v4 + Vite plugins).

> `gen:api` runs `scripts/strip-admin-paths.mjs` before `openapi-typescript` because the composables call **admin-relative** paths (`/content-types`, `/entries`, …) while `baseUrl` supplies the `/v1/admin` prefix; the strip step rewrites the spec's path keys to match (and drops non-admin paths), so the generated `paths` type contains the relative keys the client actually uses.

`admin/vite.config.ts`:
```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import ui from '@nuxt/ui/vite'
import { fileURLToPath, URL } from 'node:url'

// The compiled SPA is served by the backend at /admin (framework serveFrontend() seam), so
// assets must be referenced under that base — NOT '/'. Output goes to ../public/admin so the release
// artifact ships only public/admin/ (admin/ + node_modules are export-ignore'd).
// Nuxt UI v3 (Vue/Vite, non-Nuxt) wires two Vite plugins alongside vue(): Tailwind v4
// (@tailwindcss/vite) and Nuxt UI's own (@nuxt/ui/vite). See the CAVEAT after main.ts.
export default defineConfig({
  base: '/admin/',
  plugins: [vue(), tailwindcss(), ui()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: '../public/admin',
    emptyOutDir: true,
  },
})
```

`admin/vitest.config.ts`:
```ts
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  test: {
    environment: 'happy-dom',
    globals: true,
    setupFiles: ['./test/setup.ts'],
    include: ['test/**/*.spec.ts'],
  },
})
```

`admin/tsconfig.json`:
```json
{
  "compilerOptions": {
    "target": "ESNext",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "strict": true,
    "jsx": "preserve",
    "lib": ["ESNext", "DOM", "DOM.Iterable"],
    "skipLibCheck": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "esModuleInterop": true,
    "types": ["vitest/globals", "node"],
    "baseUrl": ".",
    "paths": { "@/*": ["src/*"] }
  },
  "include": ["src/**/*.ts", "src/**/*.d.ts", "src/**/*.vue", "test/**/*.ts", "env.d.ts"],
  "references": [{ "path": "./tsconfig.node.json" }]
}
```

`admin/tsconfig.node.json`:
```json
{
  "compilerOptions": {
    "composite": true,
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "types": ["node"]
  },
  "include": ["vite.config.ts", "vitest.config.ts", "playwright.config.ts"]
}
```

`admin/env.d.ts`:
```ts
/// <reference types="vite/client" />
declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<Record<string, never>, Record<string, never>, unknown>
  export default component
}
```

`admin/test/setup.ts`:
```ts
// Global test setup. Component specs mount via the mountWithUi helper (test/mountWithUi.ts),
// which registers native-element global stubs for the Nuxt UI components the field editors use
// (so queries like wrapper.get('input') resolve deterministically without rendering the real
// Nuxt UI tree). Here we only ensure a clean DOM between specs.
import { beforeEach } from 'vitest'

beforeEach(() => {
  document.body.innerHTML = ''
})
```

`admin/test/mountWithUi.ts` (mounts a component with Nuxt UI stubbed to native elements so DOM queries are deterministic):
```ts
import { mount, type ComponentMountingOptions } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import type { Component } from 'vue'

// v-model bridge: render a native element that re-emits its native input/change as
// `update:modelValue`, so the field components' @update:model-value handlers fire and
// wrapper.get('input'|'textarea'|'select') + setValue() work exactly as for a native control.
function nativeModelStub(tag: 'input' | 'textarea' | 'select') {
  return defineComponent({
    props: { modelValue: { type: null, default: '' }, type: { type: String, default: undefined } },
    emits: ['update:modelValue'],
    setup(props, { emit, slots }) {
      const ev = tag === 'select' ? 'onChange' : 'onInput'
      return () =>
        h(
          tag,
          {
            ...(tag === 'input' ? { type: props.type } : {}),
            value: props.modelValue as string,
            [ev]: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value),
          },
          tag === 'select' ? slots.default?.() : undefined,
        )
    },
  })
}

// USwitch is a boolean control — bridge a checkbox to update:modelValue.
const USwitchStub = defineComponent({
  props: { modelValue: { type: Boolean, default: false } },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h('input', {
        type: 'checkbox',
        checked: props.modelValue,
        onChange: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).checked),
      })
  },
})

// UFormField passes through: render the label (so error/label assertions via wrapper.text()
// pass) and the default slot (the actual control).
const UFormFieldStub = defineComponent({
  props: { label: { type: String, default: '' }, error: { type: String, default: '' }, required: { type: Boolean, default: false } },
  setup(props, { slots }) {
    return () =>
      h('div', { class: 'u-form-field' }, [
        props.label ? h('label', props.label) : null,
        slots.default?.(),
        props.error ? h('span', { class: 'u-form-field-error' }, props.error) : null,
      ])
  },
})

// UButton / UApp passthrough wrappers (render their default slot).
const passthrough = (tag: string) =>
  defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })

export const uiStubs: Record<string, Component> = {
  UInput: nativeModelStub('input'),
  UTextarea: nativeModelStub('textarea'),
  USelect: nativeModelStub('select'),
  USwitch: USwitchStub,
  UFormField: UFormFieldStub,
  UButton: passthrough('button'),
  UApp: passthrough('div'),
}

export function mountWithUi<C extends Component>(component: C, options: ComponentMountingOptions<C> = {}) {
  return mount(component, {
    ...options,
    global: {
      ...(options.global ?? {}),
      stubs: { ...uiStubs, ...(options.global?.stubs ?? {}) },
    },
  })
}
```

`admin/.gitignore`:
```
node_modules
dist
*.tsbuildinfo
playwright-report
test-results
.openapi-admin.json
```

- [ ] **Step 3: Implement `runtimeConfig.ts` so the test passes.** Create `admin/src/runtimeConfig.ts`:
```ts
// Runtime config (NOT build-baked). Fetched once at boot from the backend's unauthenticated
// GET /admin/config.json so a single compiled bundle works across installs. apiBase is needed
// before login, which is exactly why this endpoint is public.
export interface RuntimeConfig {
  apiBase: string
  sitePreviewUrl: string
  defaultLocale: string
  // Whether first-run setup has completed (Task 0d). The boot/router guard routes to /setup
  // when false, and treats /setup as inert once true. See SetupView.vue + router.ts (Task 1.5).
  installed: boolean
}

let current: RuntimeConfig | null = null

export async function loadRuntimeConfig(): Promise<RuntimeConfig> {
  // Same-origin, base-relative: the backend serves both /admin (this bundle) and /admin/config.json.
  const res = await fetch('/admin/config.json', { headers: { Accept: 'application/json' } })
  if (!res.ok) {
    throw new Error(`Failed to load runtime config (/admin/config.json returned ${res.status})`)
  }
  const cfg = (await res.json()) as RuntimeConfig
  current = cfg
  return cfg
}

export function getRuntimeConfig(): RuntimeConfig {
  if (current === null) {
    throw new Error('Runtime config not loaded — call loadRuntimeConfig() at boot first.')
  }
  return current
}
```

- [ ] **Step 4: Generate the OpenAPI types + the rest of the bootstrap.**

First regenerate the backend OpenAPI (Task 0 added the new endpoint), then generate TS types:
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer docs:openapi            # writes docs/openapi.json
cd admin && npm install && npm run gen:api   # strips /v1/admin → writes src/api/schema.d.ts
```

`admin/scripts/strip-admin-paths.mjs` (gen-time path trimmer — keeps only `/v1/admin/*`, strips the prefix so the generated `paths` type matches the admin-relative keys the composables call; `baseUrl` re-supplies `/v1/admin`):
```js
// Reads ../../docs/openapi.json, keeps ONLY paths under /v1/admin, removes that prefix from each
// path key (so '/v1/admin/content-types' -> '/content-types'), and writes the trimmed spec to
// .openapi-admin.json (gitignored). openapi-typescript then generates `paths` keyed by the
// admin-relative paths the composables use, with baseUrl '/v1/admin' supplying the prefix at
// request time. Scoping to /v1/admin/* also drops delivery/preview paths from the admin client.
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
// here = admin/scripts/ ; the spec lives at the Lemma repo root (../../docs/openapi.json).
const srcPath = resolve(here, '..', '..', 'docs', 'openapi.json')
const outPath = resolve(here, '..', '.openapi-admin.json')

const PREFIX = '/v1/admin'

const spec = JSON.parse(readFileSync(srcPath, 'utf8'))
const paths = spec.paths ?? {}
const trimmed = {}

for (const [key, value] of Object.entries(paths)) {
  if (key !== PREFIX && !key.startsWith(`${PREFIX}/`)) continue
  const rel = key.slice(PREFIX.length) || '/'
  trimmed[rel] = value
}

spec.paths = trimmed
writeFileSync(outPath, JSON.stringify(spec, null, 2))
console.log(`strip-admin-paths: kept ${Object.keys(trimmed).length} ${PREFIX}/* path(s) -> ${outPath}`)
```

`admin/src/api/client.ts` (thin typed client; refresh-on-401 added in Task 2, stubbed here):
```ts
import createClient from 'openapi-fetch'
import type { paths } from './schema'
import { getRuntimeConfig } from '@/runtimeConfig'

// One typed client per app. baseUrl comes from runtime config (the admin API base). The token
// getter is wired by the session store (Task 2) so the client never imports the store directly
// (avoids a Pinia<->client import cycle).
let tokenGetter: () => string | null = () => null
let onUnauthorized: () => Promise<boolean> = async () => false

export function configureClientAuth(getToken: () => string | null, refresh: () => Promise<boolean>): void {
  tokenGetter = getToken
  onUnauthorized = refresh
}

export function createApiClient() {
  const client = createClient<paths>({ baseUrl: getRuntimeConfig().apiBase })

  // Attach the in-memory bearer token to every request.
  client.use({
    onRequest({ request }) {
      const token = tokenGetter()
      if (token) request.headers.set('Authorization', `Bearer ${token}`)
      return request
    },
    // Refresh-on-401: on the first 401, try a refresh; if it succeeds, replay once.
    async onResponse({ request, response }) {
      if (response.status !== 401) return response
      const refreshed = await onUnauthorized()
      if (!refreshed) return response
      const retry = new Request(request)
      const token = tokenGetter()
      if (token) retry.headers.set('Authorization', `Bearer ${token}`)
      return fetch(retry)
    },
  })

  return client
}

export const api = createApiClient()
```

`admin/src/router.ts`:
```ts
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useSession } from '@/stores/session'
import { getRuntimeConfig } from '@/runtimeConfig'

const routes: RouteRecordRaw[] = [
  { path: '/setup', name: 'setup', component: () => import('@/views/SetupView.vue') },
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue') },
  {
    path: '/',
    component: () => import('@/views/AppShell.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: { name: 'entries', params: { type: '' } } },
      { path: 'entries/:type', name: 'entries', component: () => import('@/views/EntryListView.vue'), props: true },
      { path: 'entries/:type/:uuid', name: 'entry-edit', component: () => import('@/views/EntryEditView.vue'), props: true },
      { path: 'entries/:type/:uuid/versions', name: 'versions', component: () => import('@/views/VersionsView.vue'), props: true },
    ],
  },
]

// base '/admin/' so deep links match the backend mount and the SPA fallback.
export const router = createRouter({ history: createWebHistory('/admin/'), routes })

router.beforeEach((to) => {
  const session = useSession()
  const { installed } = getRuntimeConfig()

  // First-run guard (Task 0d/1.5): an uninstalled app has no admin, so force everything to
  // /setup until setup runs; once installed, /setup is inert (redirect to login).
  if (!installed && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (installed && to.name === 'setup') {
    return { name: 'login' }
  }

  if (to.meta.requiresAuth && !session.isAuthenticated) {
    return { name: 'login' }
  }
  if (to.name === 'login' && session.isAuthenticated) {
    return { name: 'entries', params: { type: '' } }
  }
  return true
})
```

> The router references `@/views/SetupView.vue` (lazy import) and reads `installed` from runtime config; the `SetupView.vue` component itself is created in **Task 1.5** (immediately below). The lazy `() => import()` is not type-checked until the route is hit, so the Task 1 scaffold build still passes; commit `SetupView.vue` with Task 1.5. The boot/router-guard wiring (the `/setup` route + the `!installed → /setup` guard above) lands here in the scaffold so the router is complete, and is exercised by Task 1.5's component test + Task 2's auth-guard tests.

`admin/src/App.vue`:
```vue
<script setup lang="ts">
// Root shell: Nuxt UI's app provider wraps router-view so toasts/overlays mount correctly.
</script>

<template>
  <UApp>
    <router-view />
  </UApp>
</template>
```

`admin/src/main.ts`:
```ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ui from '@nuxt/ui/vue-plugin'
import App from './App.vue'
import { router } from './router'
import { loadRuntimeConfig } from './runtimeConfig'
import './assets/main.css'

// Boot order matters: load runtime config FIRST (the API client reads apiBase from it), then
// mount. A failed config load is fatal — show it rather than booting a broken app.
async function boot() {
  await loadRuntimeConfig()
  const app = createApp(App)
  app.use(createPinia())
  app.use(router)
  app.use(ui)
  app.mount('#app')
}

boot().catch((err) => {
  document.body.innerHTML =
    `<pre style="padding:2rem;font-family:monospace">Admin failed to start: ${String(err)}</pre>`
})
```

`admin/src/assets/main.css` (the single CSS entry imported by `main.ts`; Tailwind v4 + Nuxt UI use CSS `@import`, not a JS-side `ui.css`):
```css
@import "tailwindcss";
@import "@nuxt/ui";
```

`admin/index.html`:
```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Lemma Admin</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
  </body>
</html>
```

> **Nuxt UI Vue/Vite setup — CAVEAT.** The wiring above (Vite plugins `@tailwindcss/vite` + `@nuxt/ui/vite`; plugin `import ui from '@nuxt/ui/vue-plugin'` + `app.use(ui)`; CSS entry `@import "tailwindcss"; @import "@nuxt/ui";` from `src/assets/main.css`) is the current Nuxt UI v3 Vue/Vite (non-Nuxt) shape with Tailwind v4. **Confirm the exact plugin export names and CSS import lines against the installed `@nuxt/ui` version at execution — Nuxt UI's Vue/Vite setup has churned across 3.x.** If `npm run build` reports a missing export, read `admin/node_modules/@nuxt/ui/package.json` `"exports"` and use the published names. This is the one place to reconcile against the installed package; everything else is framework-agnostic Vue.

- [ ] **Step 5: Run the scaffold test; verify it passes.**

Run: `npm --prefix admin run test -- runtimeConfig`
Expected: PASS — config fetched + exposed, and a 500 rejects with a "runtime config" error.

- [ ] **Step 6: Verify the build wires to public/admin.**
```bash
npm --prefix admin run build
ls public/admin/index.html public/admin/assets    # both must exist
```
Expected: `vite build` emits `public/admin/index.html` + hashed assets under `public/admin/assets/`. (This is what the framework `serveFrontend()` mount serves at `/admin` once Task 0c is committed.)

- [ ] **Step 7: Commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add admin/package.json admin/vite.config.ts admin/vitest.config.ts admin/tsconfig.json \
  admin/tsconfig.node.json admin/env.d.ts admin/.gitignore admin/index.html \
  admin/src/main.ts admin/src/App.vue admin/src/router.ts admin/src/runtimeConfig.ts \
  admin/src/assets/main.css admin/scripts/strip-admin-paths.mjs \
  admin/src/api/client.ts admin/src/api/schema.d.ts \
  admin/test/setup.ts admin/test/runtimeConfig.spec.ts
git commit -m "Scaffold admin SPA: Vite/Vue/Pinia/Nuxt UI + typed client + runtime config"
```

> Do NOT commit `admin/node_modules` or `public/admin` (build output) — `public/admin` is a generated artifact rebuilt by CI/packaging (Task 8). Add `public/admin/` to the repo `.gitignore` in Task 8.

---

### Task 1.5: Setup screen (`/setup`) — first-run web setup UI

This screen is the frontend half of the first-run web setup (backend: Task 0d). It is reached by the boot/router guard added to `router.ts` in Task 1 (`!installed → /setup`). The form POSTs to the **unauthenticated** `/admin/setup` endpoint via **raw `fetch`** — NOT the typed `api` client — because that endpoint is outside the `/v1/admin`-scoped client and needs no bearer token (exactly like `useAuth`'s `postAuth`). On success it redirects to `/login`.

**Files:** `admin/src/views/SetupView.vue`, `admin/test/views/SetupView.spec.ts`.

- [ ] **Step 1: Write the failing component test.** Create `admin/test/views/SetupView.spec.ts`:
```ts
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mountWithUi } from '../mountWithUi'
import SetupView from '@/views/SetupView.vue'

// /admin/setup is UNAUTHENTICATED and outside the /v1/admin client, so SetupView posts via raw
// fetch (like useAuth). Stub fetch + the router push and assert both.
const push = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

describe('SetupView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    push.mockReset()
  })
  afterEach(() => vi.unstubAllGlobals())

  it('posts the form to /admin/setup and redirects to login on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ message: 'Setup complete.', installed: true }), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mountWithUi(SetupView)
    await wrapper.get('input[type="text"]').setValue('getlemma.dev')
    await wrapper.get('input[type="email"]').setValue('admin@getlemma.dev')
    await wrapper.get('input[type="password"]').setValue('correct horse battery')
    await wrapper.get('form').trigger('submit.prevent')
    await Promise.resolve()
    await Promise.resolve()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/admin/setup')
    expect(init.method).toBe('POST')
    const body = JSON.parse(init.body as string)
    expect(body.site_name).toBe('getlemma.dev')
    expect(body.admin_email).toBe('admin@getlemma.dev')
    expect(body.admin_password).toBe('correct horse battery')
    expect(push).toHaveBeenCalledWith({ name: 'login' })
  })

  it('surfaces an error and does not redirect when setup fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ message: 'Setup has already been completed.' }), { status: 409 }),
    ))
    const wrapper = mountWithUi(SetupView)
    await wrapper.get('input[type="email"]').setValue('admin@getlemma.dev')
    await wrapper.get('input[type="password"]').setValue('correct horse battery')
    await wrapper.get('form').trigger('submit.prevent')
    await Promise.resolve()
    await Promise.resolve()

    expect(push).not.toHaveBeenCalled()
    expect(wrapper.text()).toMatch(/already been completed/i)
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- SetupView`
Expected: FAIL — cannot resolve `@/views/SetupView.vue`.

- [ ] **Step 3: Implement the setup screen.** Create `admin/src/views/SetupView.vue`:
```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { getRuntimeConfig } from '@/runtimeConfig'

// First-run setup posts to the UNAUTHENTICATED /admin/setup (outside the /v1/admin typed client),
// so we use raw fetch — no bearer token, no openapi-fetch. On success → /login. The backend
// self-locks (409 once installed), so the worst a re-submit can do is surface "already completed".
const router = useRouter()
const siteName = ref('')
const adminEmail = ref('')
const adminPassword = ref('')
const locale = ref(getRuntimeConfig().defaultLocale)
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = null
  try {
    const res = await fetch('/admin/setup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        site_name: siteName.value,
        admin_email: adminEmail.value,
        admin_password: adminPassword.value,
        locale: locale.value,
      }),
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) {
      error.value = (json?.message as string) ?? `Setup failed (${res.status})`
      return
    }
    router.push({ name: 'login' })
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Setup failed'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-6">
    <UCard class="w-full max-w-sm">
      <template #header><h1 class="text-lg font-semibold">Welcome to Lemma — first-run setup</h1></template>
      <form class="space-y-4" @submit.prevent="submit">
        <UFormField label="Site name"><UInput v-model="siteName" type="text" /></UFormField>
        <UFormField label="Admin email"><UInput v-model="adminEmail" type="email" autocomplete="username" /></UFormField>
        <UFormField label="Admin password"><UInput v-model="adminPassword" type="password" autocomplete="new-password" /></UFormField>
        <UFormField label="Default locale"><UInput v-model="locale" type="text" /></UFormField>
        <UAlert v-if="error" color="error" :title="error" />
        <UButton type="submit" :loading="loading" block>Create admin & finish</UButton>
      </form>
    </UCard>
  </div>
</template>
```

> The `mountWithUi` stubs render `UInput` as a native `<input>` carrying its `type` (see `nativeModelStub`), so `wrapper.get('input[type="email"]')` etc. resolve, and `UAlert`'s `title` is surfaced via the `UFormField`/passthrough stubs for the error assertion. If `UAlert` is not already stubbed, add a passthrough stub for it to `uiStubs` in `test/mountWithUi.ts` (it renders its `title` prop) — the same one-line passthrough pattern as `UButton`.

- [ ] **Step 4: Run; verify pass.**

Run: `npm --prefix admin run test -- SetupView`
Expected: PASS — the form posts `{site_name, admin_email, admin_password, locale}` to `/admin/setup` and redirects to `/login`; a 409 surfaces the message and does not redirect.

- [ ] **Step 5: Commit.**
```bash
git add admin/src/views/SetupView.vue admin/test/views/SetupView.spec.ts admin/test/mountWithUi.ts
git commit -m "Add first-run setup screen (/setup) posting to unauthenticated /admin/setup"
```

---

## Task group 2 — Auth shell (in-memory token, refresh-on-401, logout)

**Slice-1 task — pin the effective auth paths.** Before writing code, confirm the live auth paths from the route manifest (a global API prefix can shift them; Lemma's own routes are hardcoded `/v1/admin` and may not share the auth prefix):
```bash
php glueful route:list | grep -iE 'auth|/me'
composer docs:openapi && grep -oE '"/[^"]*(auth|login|refresh|logout|me)[^"]*"' docs/openapi.json | sort -u
```
Record the effective `login`, `refresh`, `logout`, `/me` paths. The framework registers `POST /auth/login`, `POST /auth/refresh-token`, `POST /auth/logout` (framework `routes/auth.php`) and `GET /me` (glueful/users). With `API_USE_PREFIX=true` + `API_VERSION_IN_PATH=true` (config/api.php defaults) these become `/api/v1/auth/login` etc., while Lemma admin stays `/v1/admin`. **Use the manifest's actual paths in the `authPaths` constant below.** Login returns `{ access_token, refresh_token, token_type, expires_in, user }`; the refresh token is returned in the body (the framework does not set an httpOnly cookie by default). Per the spec's auth posture, the SPA keeps the access token in memory and relies on the refresh endpoint; if the deployment is configured to set an httpOnly refresh cookie, the body refresh_token is simply unused.

**Files:** `admin/src/stores/session.ts`, `admin/src/composables/useAuth.ts`, `admin/src/views/LoginView.vue`, `admin/src/views/AppShell.vue`, `admin/test/composables/useAuth.spec.ts`, `admin/test/stores/session.spec.ts`.

> **First-run interplay with the router guard.** The `router.beforeEach` guard (defined in `router.ts`, Task 1) already consults `installed` from runtime config *ahead of* the auth checks added here: an **uninstalled** app routes everything to `/setup` (there is no admin to authenticate against yet, Task 0d/1.5), and once **installed** `/setup` is inert (redirects to `/login`). So the auth-gating below only matters once setup has run; do not re-implement the guard — the `requiresAuth`/`login` rules here sit *after* the `installed` rules in the same `beforeEach`.

- [ ] **Step 1: Write the failing tests.** Create `admin/test/stores/session.spec.ts`:
```ts
import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSession } from '@/stores/session'

describe('session store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('holds the access token in memory only (never localStorage)', () => {
    const s = useSession()
    expect(s.isAuthenticated).toBe(false)
    s.setTokens({ accessToken: 'a.b.c', refreshToken: 'r1' })
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('a.b.c')
    // The store must not persist the token anywhere observable.
    expect(localStorage.getItem('accessToken')).toBeNull()
    expect(Object.values(localStorage).join('')).not.toContain('a.b.c')
  })

  it('clears tokens on logout', () => {
    const s = useSession()
    s.setTokens({ accessToken: 'a.b.c', refreshToken: 'r1' })
    s.clear()
    expect(s.isAuthenticated).toBe(false)
    expect(s.accessToken).toBeNull()
  })
})
```
Create `admin/test/composables/useAuth.spec.ts`:
```ts
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// useAuth's login/refresh/logout go through postAuth(), which calls the GLOBAL fetch directly
// (auth is unauthenticated + httpOnly-cookie-based — not an openapi-fetch concern), so we stub
// fetch, not api.POST. configureClientAuth is a no-op here; stub the client module just so the
// import resolves without pulling in the real openapi-fetch client.
vi.mock('@/api/client', () => ({ api: {}, configureClientAuth: vi.fn() }))

// postAuth reads res.ok / res.status and `json.data ?? json` for access_token/refresh_token/user.
function authResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

import { useAuth } from '@/composables/useAuth'
import { useSession } from '@/stores/session'

describe('useAuth', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('login stores tokens and exposes no error on success', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      authResponse({ data: { access_token: 'a.b.c', refresh_token: 'r1', user: { uuid: 'u1' } } }),
    ))
    const { login, error } = useAuth()
    await login('editor@getlemma.dev', 'pw')
    expect(useSession().accessToken).toBe('a.b.c')
    expect(error.value).toBeNull()
  })

  it('login surfaces an error and stores no token on 401', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      authResponse({ message: 'Invalid credentials' }, 401),
    ))
    const { login, error } = useAuth()
    await login('editor@getlemma.dev', 'wrong')
    expect(useSession().isAuthenticated).toBe(false)
    expect(error.value).toMatch(/invalid/i)
  })

  it('refresh swaps the access token using the refresh token', async () => {
    const s = useSession()
    s.setTokens({ accessToken: 'old', refreshToken: 'r1' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      authResponse({ data: { access_token: 'new', refresh_token: 'r2', user: { uuid: 'u1' } } }),
    ))
    const { refresh } = useAuth()
    const ok = await refresh()
    expect(ok).toBe(true)
    expect(s.accessToken).toBe('new')
  })

  it('refresh returns false and clears the session when the refresh token is rejected', async () => {
    const s = useSession()
    s.setTokens({ accessToken: 'old', refreshToken: 'bad' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      authResponse({ message: 'expired' }, 401),
    ))
    const { refresh } = useAuth()
    const ok = await refresh()
    expect(ok).toBe(false)
    expect(s.isAuthenticated).toBe(false)
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- session useAuth`
Expected: FAIL — cannot resolve `@/stores/session` / `@/composables/useAuth`.

- [ ] **Step 3: Implement the session store.** Create `admin/src/stores/session.ts`:
```ts
import { defineStore } from 'pinia'

// Access token lives ONLY in this store's memory — never localStorage/sessionStorage (XSS
// exfiltration surface). The refresh token is held in memory too for the body-refresh flow;
// when the deployment uses an httpOnly refresh cookie instead, refreshToken stays null and the
// cookie is sent automatically by the browser on the refresh call.
export interface AuthUser { uuid: string }

export const useSession = defineStore('session', {
  state: () => ({
    accessToken: null as string | null,
    refreshToken: null as string | null,
    user: null as AuthUser | null,
  }),
  getters: {
    isAuthenticated: (s): boolean => s.accessToken !== null,
  },
  actions: {
    setTokens(p: { accessToken: string; refreshToken?: string | null; user?: AuthUser | null }): void {
      this.accessToken = p.accessToken
      this.refreshToken = p.refreshToken ?? this.refreshToken
      if (p.user) this.user = p.user
    },
    clear(): void {
      this.accessToken = null
      this.refreshToken = null
      this.user = null
    },
  },
})
```

- [ ] **Step 4: Implement `useAuth`.** Create `admin/src/composables/useAuth.ts`:
```ts
import { ref } from 'vue'
import { configureClientAuth } from '@/api/client'
import { useSession } from '@/stores/session'

// Effective auth paths — PINNED from the route manifest in this task's slice-1 step. Update
// these to the exact strings `php glueful route:list` reports for this install. They are
// relative to the SAME origin but NOT necessarily under apiBase (Lemma admin is /v1/admin while
// auth may be /api/v1/auth), so they are called via fetch against absolute origin paths.
const authPaths = {
  login: '/api/v1/auth/login',
  refresh: '/api/v1/auth/refresh-token',
  logout: '/api/v1/auth/logout',
} as const

interface LoginResult {
  access_token: string
  refresh_token: string
  user: { uuid: string }
}

async function postAuth(path: string, body: Record<string, unknown>, bearer?: string | null): Promise<LoginResult> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' }
  if (bearer) headers.Authorization = `Bearer ${bearer}`
  const res = await fetch(path, { method: 'POST', headers, body: JSON.stringify(body), credentials: 'include' })
  const json = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((json?.message as string) ?? `Authentication failed (${res.status})`)
  }
  // Framework login envelope nests the payload under `data`.
  return (json.data ?? json) as LoginResult
}

export function useAuth() {
  const session = useSession()
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function login(username: string, password: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const data = await postAuth(authPaths.login, { username, password })
      session.setTokens({ accessToken: data.access_token, refreshToken: data.refresh_token, user: data.user })
    } catch (e) {
      session.clear()
      error.value = e instanceof Error ? e.message : 'Login failed'
    } finally {
      loading.value = false
    }
  }

  async function refresh(): Promise<boolean> {
    try {
      // Body-refresh when we hold a refresh token; the httpOnly-cookie path sends no body token.
      const data = await postAuth(authPaths.refresh, session.refreshToken ? { refresh_token: session.refreshToken } : {})
      session.setTokens({ accessToken: data.access_token, refreshToken: data.refresh_token, user: data.user })
      return true
    } catch {
      session.clear()
      return false
    }
  }

  async function logout(): Promise<void> {
    try {
      await postAuth(authPaths.logout, {}, session.accessToken)
    } catch {
      // Best-effort server-side revoke; the client clears regardless.
    } finally {
      session.clear()
    }
  }

  // Wire the typed client's auth callbacks to this session (token getter + refresh-on-401).
  configureClientAuth(() => session.accessToken, refresh)

  return { login, refresh, logout, error, loading }
}
```

- [ ] **Step 5: Implement the login screen + app shell.**

`admin/src/views/LoginView.vue`:
```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

const router = useRouter()
const { login, error, loading } = useAuth()
const username = ref('')
const password = ref('')

async function submit() {
  await login(username.value, password.value)
  if (!error.value) router.push({ name: 'entries', params: { type: '' } })
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-6">
    <UCard class="w-full max-w-sm">
      <template #header><h1 class="text-lg font-semibold">Lemma Admin</h1></template>
      <form class="space-y-4" @submit.prevent="submit">
        <UFormField label="Email"><UInput v-model="username" type="email" autocomplete="username" /></UFormField>
        <UFormField label="Password"><UInput v-model="password" type="password" autocomplete="current-password" /></UFormField>
        <UAlert v-if="error" color="error" :title="error" />
        <UButton type="submit" :loading="loading" block>Sign in</UButton>
      </form>
    </UCard>
  </div>
</template>
```

`admin/src/views/AppShell.vue`:
```vue
<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import ContentTypeNav from '@/components/ContentTypeNav.vue'

const router = useRouter()
const { logout } = useAuth()

async function signOut() {
  await logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex min-h-screen">
    <aside class="w-64 border-r p-4 space-y-4">
      <div class="flex items-center justify-between">
        <span class="font-semibold">Lemma</span>
        <UButton size="xs" variant="ghost" @click="signOut">Sign out</UButton>
      </div>
      <ContentTypeNav />
    </aside>
    <main class="flex-1 p-6"><router-view /></main>
  </div>
</template>
```

- [ ] **Step 6: Run; verify pass.**

Run: `npm --prefix admin run test -- session useAuth`
Expected: PASS — token in memory only (no localStorage), logout clears, login success/401, refresh swap + reject-clears.

- [ ] **Step 7: Commit.**
```bash
git add admin/src/stores/session.ts admin/src/composables/useAuth.ts admin/src/views/LoginView.vue \
  admin/src/views/AppShell.vue admin/test/stores/session.spec.ts admin/test/composables/useAuth.spec.ts
git commit -m "Add auth shell: in-memory token, refresh-on-401, login/logout"
```

---

## Task group 3 — Content-type nav + entry list (read-only)

**Files:** `admin/src/composables/useContentTypes.ts`, `useEntries.ts`, `admin/src/components/ContentTypeNav.vue`, `admin/src/views/EntryListView.vue`, `admin/test/composables/useContentTypes.spec.ts`, `useEntries.spec.ts`.

- [ ] **Step 1: Write the failing tests.** Create `admin/test/composables/useContentTypes.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'

const get = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get }, configureClientAuth: vi.fn() }))
import { useContentTypes } from '@/composables/useContentTypes'

describe('useContentTypes', () => {
  beforeEach(() => get.mockReset())

  it('loads content types from GET /content-types', async () => {
    get.mockResolvedValueOnce({ data: { data: { content_types: [{ slug: 'page', name: 'Page' }] } }, error: undefined })
    const { load, types, error } = useContentTypes()
    await load()
    expect(get).toHaveBeenCalledWith('/content-types')
    expect(types.value).toEqual([{ slug: 'page', name: 'Page' }])
    expect(error.value).toBeNull()
  })

  it('surfaces an error and leaves types empty on failure', async () => {
    get.mockResolvedValueOnce({ data: undefined, error: { message: 'boom' } })
    const { load, types, error } = useContentTypes()
    await load()
    expect(types.value).toEqual([])
    expect(error.value).toMatch(/boom/)
  })
})
```
Create `admin/test/composables/useEntries.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'

const get = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get }, configureClientAuth: vi.fn() }))
import { useEntries } from '@/composables/useEntries'

describe('useEntries', () => {
  beforeEach(() => get.mockReset())

  it('lists entries for a type via the new GET /entries endpoint', async () => {
    get.mockResolvedValueOnce({
      data: { data: { entries: [{ uuid: 'e1', display_title: 'Home', status: 'draft', locales: ['en'], updated_at: null }], total: 1, current_page: 1, per_page: 20 } },
      error: undefined,
    })
    const { list, entries, total } = useEntries()
    await list('page')
    expect(get).toHaveBeenCalledWith('/entries', { params: { query: { type: 'page', page: 1, perPage: 20 } } })
    expect(entries.value[0].display_title).toBe('Home')
    expect(total.value).toBe(1)
  })

  it('passes the q filter when given', async () => {
    get.mockResolvedValueOnce({ data: { data: { entries: [], total: 0, current_page: 1, per_page: 20 } }, error: undefined })
    const { list } = useEntries()
    await list('page', { q: 'alph', page: 2, perPage: 10 })
    expect(get).toHaveBeenCalledWith('/entries', { params: { query: { type: 'page', page: 2, perPage: 10, q: 'alph' } } })
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- useContentTypes useEntries`
Expected: FAIL — composables not found.

- [ ] **Step 3: Implement the composables.**

`admin/src/composables/useContentTypes.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export interface ContentTypeSummary { slug: string; name: string }

export function useContentTypes() {
  const types = ref<ContentTypeSummary[]>([])
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    const { data, error: err } = await api.GET('/content-types')
    if (err || !data) {
      error.value = (err?.message as string) ?? 'Failed to load content types'
      types.value = []
    } else {
      types.value = ((data.data?.content_types ?? []) as ContentTypeSummary[]).map((t) => ({ slug: t.slug, name: t.name }))
    }
    loading.value = false
  }

  return { types, error, loading, load }
}
```

`admin/src/composables/useEntries.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export interface EntryListItem {
  uuid: string
  display_title: string
  status: 'draft' | 'scheduled' | 'published'
  locales: string[]
  updated_at: string | null
}

export function useEntries() {
  const entries = ref<EntryListItem[]>([])
  const total = ref(0)
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function list(type: string, opts: { q?: string; page?: number; perPage?: number } = {}): Promise<void> {
    loading.value = true
    error.value = null
    const query: Record<string, string | number> = { type, page: opts.page ?? 1, perPage: opts.perPage ?? 20 }
    if (opts.q) query.q = opts.q
    const { data, error: err } = await api.GET('/entries', { params: { query } })
    if (err || !data) {
      error.value = (err?.message as string) ?? 'Failed to load entries'
      entries.value = []
      total.value = 0
    } else {
      entries.value = (data.data?.entries ?? []) as EntryListItem[]
      total.value = (data.data?.total ?? 0) as number
    }
    loading.value = false
  }

  return { entries, total, error, loading, list }
}
```

- [ ] **Step 4: Implement the nav + list views.**

`admin/src/components/ContentTypeNav.vue`:
```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useContentTypes } from '@/composables/useContentTypes'

// Read-only content-type sidebar (GET /content-types). Phase 1 NEVER creates/edits types here.
const { types, error, load } = useContentTypes()
onMounted(load)
</script>

<template>
  <nav class="space-y-1">
    <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
    <RouterLink
      v-for="t in types"
      :key="t.slug"
      :to="{ name: 'entries', params: { type: t.slug } }"
      class="block px-2 py-1 rounded hover:bg-gray-100 text-sm"
    >{{ t.name }}</RouterLink>
  </nav>
</template>
```

`admin/src/views/EntryListView.vue`:
```vue
<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useEntries } from '@/composables/useEntries'
import { api } from '@/api/client'

const props = defineProps<{ type: string }>()
const router = useRouter()
const { entries, total, error, loading, list } = useEntries()
const q = ref('')

async function reload() {
  if (props.type) await list(props.type, { q: q.value || undefined })
}
onMounted(reload)
watch(() => props.type, reload)

async function createEntry() {
  const { data } = await api.POST('/entries', { body: { content_type: props.type } })
  const uuid = data?.data?.entry?.uuid
  if (uuid) router.push({ name: 'entry-edit', params: { type: props.type, uuid } })
}
</script>

<template>
  <section v-if="type" class="space-y-4">
    <header class="flex items-center justify-between">
      <h1 class="text-xl font-semibold capitalize">{{ type }}</h1>
      <UButton @click="createEntry">New {{ type }}</UButton>
    </header>
    <UInput v-model="q" placeholder="Search title…" @keyup.enter="reload" />
    <UAlert v-if="error" color="error" :title="error" />
    <p v-else-if="loading">Loading…</p>
    <ul v-else class="divide-y border rounded">
      <li v-for="e in entries" :key="e.uuid" class="flex items-center justify-between p-3">
        <RouterLink :to="{ name: 'entry-edit', params: { type, uuid: e.uuid } }" class="font-medium hover:underline">
          {{ e.display_title }}
        </RouterLink>
        <UBadge :color="e.status === 'published' ? 'success' : e.status === 'scheduled' ? 'warning' : 'neutral'">
          {{ e.status }}
        </UBadge>
      </li>
    </ul>
    <p class="text-xs text-gray-500">{{ total }} entr{{ total === 1 ? 'y' : 'ies' }}</p>
  </section>
  <p v-else class="text-gray-500">Select a content type.</p>
</template>
```

- [ ] **Step 5: Run; verify pass.**

Run: `npm --prefix admin run test -- useContentTypes useEntries`
Expected: PASS — both composables call the right endpoints and shape the data.

- [ ] **Step 6: Commit.**
```bash
git add admin/src/composables/useContentTypes.ts admin/src/composables/useEntries.ts \
  admin/src/components/ContentTypeNav.vue admin/src/views/EntryListView.vue \
  admin/test/composables/useContentTypes.spec.ts admin/test/composables/useEntries.spec.ts
git commit -m "Add read-only content-type nav and draft-inclusive entry list"
```

---

## Task group 4 — Create entry + schema-driven field editor

The field editor reads the content type's `schema` (via `GET /content-types/{slug}`) and renders one input per field by `type → component`. **This is the heart of the boundary:** it consumes schema, never mutates it.

**Files:** `admin/src/fields/types.ts`, `registry.ts`, `components/*.vue` (one per type), `FieldEditor.vue`, `admin/src/composables/useDraft.ts`, and the field/draft specs.

- [ ] **Step 1: Write the failing tests — the ESTABLISHED PATTERN (one field fully worked).** Create `admin/test/fields/StringField.spec.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { mountWithUi } from '../mountWithUi'
import StringField from '@/fields/components/StringField.vue'

describe('StringField', () => {
  it('renders the current value and round-trips edits via v-model', async () => {
    const wrapper = mountWithUi(StringField, {
      props: { field: { name: 'title', type: 'string', required: true }, modelValue: 'Hello' },
    })
    const input = wrapper.get('input')
    expect((input.element as HTMLInputElement).value).toBe('Hello')
    await input.setValue('World')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['World'])
  })

  it('marks the field required for validation surfacing', () => {
    const wrapper = mountWithUi(StringField, {
      props: { field: { name: 'title', type: 'string', required: true }, modelValue: '', error: 'Title is required' },
    })
    expect(wrapper.text()).toContain('Title is required')
  })
})
```
Create the registry test `admin/test/fields/registry.spec.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { resolveFieldComponent } from '@/fields/registry'
import StringField from '@/fields/components/StringField.vue'
import TextField from '@/fields/components/TextField.vue'
import JsonField from '@/fields/components/JsonField.vue'

describe('field registry', () => {
  it('maps each known field type to a component', () => {
    expect(resolveFieldComponent('string')).toBe(StringField)
    expect(resolveFieldComponent('text')).toBe(TextField)
    for (const t of ['number', 'boolean', 'datetime', 'enum', 'asset', 'reference', 'json']) {
      expect(resolveFieldComponent(t)).toBeTruthy()
    }
  })

  it('falls back to the raw JSON field for an unknown type (never crashes the form)', () => {
    expect(resolveFieldComponent('mystery')).toBe(JsonField)
  })
})
```
Create the Markdown field test `admin/test/fields/TextField.spec.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { mountWithUi } from '../mountWithUi'
import TextField from '@/fields/components/TextField.vue'

describe('TextField (Markdown)', () => {
  it('round-trips the value and renders a Markdown preview', async () => {
    const wrapper = mountWithUi(TextField, {
      props: { field: { name: 'body', type: 'text' }, modelValue: '# Hi' },
    })
    const ta = wrapper.get('textarea')
    await ta.setValue('# Hello')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['# Hello'])
    // Preview pane renders markdown-it output.
    expect(wrapper.find('[data-test="md-preview"]').html()).toContain('<h1>')
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- fields`
Expected: FAIL — field components/registry not found.

- [ ] **Step 3: Implement the field types + registry.**

`admin/src/fields/types.ts`:
```ts
// FieldDefinition mirrors the backend ContentTypeSchema field shape (app/Content/Schema/
// FieldDefinition.php → ContentTypeSchema::toArray): name, type, required?, enum? (enum values),
// localized?. The SPA only READS these to render inputs — it never writes schema (Phase 2).
export interface FieldDefinition {
  name: string
  type: string
  required?: boolean
  enum?: string[]
  localized?: boolean
  reference_type?: string // for 'reference' fields: the referenced content type slug, if declared
}

export interface FieldProps {
  field: FieldDefinition
  modelValue: unknown
  error?: string | null
}
```

`admin/src/fields/registry.ts`:
```ts
import type { Component } from 'vue'
import StringField from './components/StringField.vue'
import TextField from './components/TextField.vue'
import NumberField from './components/NumberField.vue'
import BooleanField from './components/BooleanField.vue'
import DatetimeField from './components/DatetimeField.vue'
import EnumField from './components/EnumField.vue'
import AssetField from './components/AssetField.vue'
import ReferenceField from './components/ReferenceField.vue'
import JsonField from './components/JsonField.vue'

// type → component map (spec §"Schema-driven field editor"). An unknown type degrades to the
// raw JSON editor so a forward-compatible schema never crashes the form.
const registry: Record<string, Component> = {
  string: StringField,
  text: TextField,
  number: NumberField,
  boolean: BooleanField,
  datetime: DatetimeField,
  enum: EnumField,
  asset: AssetField,
  reference: ReferenceField,
  json: JsonField,
}

export function resolveFieldComponent(type: string): Component {
  return registry[type] ?? JsonField
}
```

- [ ] **Step 4: Implement the field components.**

`admin/src/fields/components/StringField.vue` (the worked pattern):
```vue
<script setup lang="ts">
import type { FieldProps } from '@/fields/types'
const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <UInput
      :model-value="(modelValue as string) ?? ''"
      @update:model-value="emit('update:modelValue', String($event))"
    />
  </UFormField>
</template>
```

`admin/src/fields/components/TextField.vue` (Markdown textarea + preview):
```vue
<script setup lang="ts">
import { computed } from 'vue'
import MarkdownIt from 'markdown-it'
import type { FieldProps } from '@/fields/types'

const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()
const md = new MarkdownIt({ html: false, linkify: true, breaks: true })
const rendered = computed(() => md.render((props.modelValue as string) ?? ''))
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <div class="grid grid-cols-2 gap-3">
      <UTextarea
        :rows="12"
        :model-value="(modelValue as string) ?? ''"
        @update:model-value="emit('update:modelValue', String($event))"
      />
      <div data-test="md-preview" class="prose max-w-none border rounded p-3 overflow-auto" v-html="rendered" />
    </div>
  </UFormField>
</template>
```

`admin/src/fields/components/NumberField.vue`:
```vue
<script setup lang="ts">
import type { FieldProps } from '@/fields/types'
const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [number | null] }>()
function onInput(v: string) {
  emit('update:modelValue', v === '' ? null : Number(v))
}
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <UInput type="number" :model-value="(modelValue as number) ?? ''" @update:model-value="onInput(String($event))" />
  </UFormField>
</template>
```

`admin/src/fields/components/BooleanField.vue`:
```vue
<script setup lang="ts">
import type { FieldProps } from '@/fields/types'
const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [boolean] }>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <USwitch :model-value="Boolean(modelValue)" @update:model-value="emit('update:modelValue', Boolean($event))" />
  </UFormField>
</template>
```

`admin/src/fields/components/DatetimeField.vue`:
```vue
<script setup lang="ts">
import type { FieldProps } from '@/fields/types'
const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [string | null] }>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <UInput
      type="datetime-local"
      :model-value="(modelValue as string) ?? ''"
      @update:model-value="emit('update:modelValue', $event === '' ? null : String($event))"
    />
  </UFormField>
</template>
```

`admin/src/fields/components/EnumField.vue`:
```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { FieldProps } from '@/fields/types'
const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [string | null] }>()
const options = computed(() => (props.field.enum ?? []).map((v) => ({ label: v, value: v })))
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <USelect
      :items="options"
      :model-value="(modelValue as string) ?? null"
      @update:model-value="emit('update:modelValue', ($event as string) ?? null)"
    />
  </UFormField>
</template>
```

`admin/src/fields/components/JsonField.vue`:
```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import type { FieldProps } from '@/fields/types'

const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [unknown] }>()
const text = ref(JSON.stringify(props.modelValue ?? null, null, 2))
const parseError = ref<string | null>(null)

watch(text, (v) => {
  try {
    emit('update:modelValue', JSON.parse(v))
    parseError.value = null
  } catch {
    parseError.value = 'Invalid JSON'
  }
})
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="(error ?? parseError) ?? undefined">
    <UTextarea v-model="text" :rows="8" class="font-mono text-sm" />
  </UFormField>
</template>
```

`admin/src/fields/components/AssetField.vue` (stub now; upload wired in Task 7 — keep its contract stable):
```vue
<script setup lang="ts">
import type { FieldProps } from '@/fields/types'
import { useMedia } from '@/composables/useMedia'

const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [string | null] }>()
const { upload, uploading, error } = useMedia()

async function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const uuid = await upload(file)
  if (uuid) emit('update:modelValue', uuid)
}
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="(error ?? props.error) ?? undefined">
    <div class="space-y-2">
      <input type="file" accept="image/*" :disabled="uploading" @change="onFile" />
      <p v-if="modelValue" class="text-xs text-gray-500">blob: {{ modelValue }}</p>
    </div>
  </UFormField>
</template>
```

`admin/src/fields/components/ReferenceField.vue` (minimal entry picker via the entry-list endpoint):
```vue
<script setup lang="ts">
import { ref } from 'vue'
import type { FieldProps } from '@/fields/types'
import { useEntries } from '@/composables/useEntries'

const props = defineProps<FieldProps>()
const emit = defineEmits<{ 'update:modelValue': [string | null] }>()
const { entries, list } = useEntries()
const q = ref('')

async function search() {
  if (props.field.reference_type) await list(props.field.reference_type, { q: q.value || undefined })
}
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :error="error ?? undefined">
    <div class="space-y-2">
      <div class="flex gap-2">
        <UInput v-model="q" placeholder="Search entries…" @keyup.enter="search" />
        <UButton variant="soft" @click="search">Search</UButton>
      </div>
      <ul class="border rounded divide-y max-h-40 overflow-auto">
        <li
          v-for="e in entries"
          :key="e.uuid"
          class="p-2 text-sm cursor-pointer hover:bg-gray-50"
          :class="{ 'bg-blue-50': modelValue === e.uuid }"
          @click="emit('update:modelValue', e.uuid)"
        >{{ e.display_title }}</li>
      </ul>
      <p v-if="modelValue" class="text-xs text-gray-500">target: {{ modelValue }}</p>
    </div>
  </UFormField>
</template>
```

`admin/src/fields/FieldEditor.vue`:
```vue
<script setup lang="ts">
import { resolveFieldComponent } from '@/fields/registry'
import type { FieldDefinition } from '@/fields/types'

defineProps<{
  fields: FieldDefinition[]
  values: Record<string, unknown>
  errors: Record<string, string>
}>()
const emit = defineEmits<{ 'update:value': [name: string, value: unknown] }>()
</script>

<template>
  <div class="space-y-5">
    <component
      :is="resolveFieldComponent(field.type)"
      v-for="field in fields"
      :key="field.name"
      :field="field"
      :model-value="values[field.name]"
      :error="errors[field.name] ?? null"
      @update:model-value="emit('update:value', field.name, $event)"
    />
  </div>
</template>
```

- [ ] **Step 5: Implement `useDraft` (load schema + draft, save with optimistic lock + 409).**

Write the failing test first — `admin/test/composables/useDraft.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'

const get = vi.fn()
const put = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get, PUT: put }, configureClientAuth: vi.fn() }))
import { useDraft } from '@/composables/useDraft'

describe('useDraft', () => {
  beforeEach(() => { get.mockReset(); put.mockReset() })

  it('loads the schema and draft for an entry+locale', async () => {
    get.mockImplementation((path: string) => {
      if (path === '/content-types/{slug}') {
        return Promise.resolve({ data: { data: { content_type: { schema: [{ name: 'title', type: 'string', required: true }] } } } })
      }
      return Promise.resolve({ data: { data: { draft: { fields: { title: 'Hi' }, lock_version: 2 } } } })
    })
    const { load, fields, values, lockVersion } = useDraft()
    await load('page', 'e1', 'en')
    expect(fields.value).toEqual([{ name: 'title', type: 'string', required: true }])
    expect(values.value).toEqual({ title: 'Hi' })
    expect(lockVersion.value).toBe(2)
  })

  it('saves with the lock_version and advances it on success', async () => {
    put.mockResolvedValueOnce({ data: { data: { draft: { fields: { title: 'X' }, lock_version: 3 } } }, error: undefined, response: { status: 200 } })
    const { save, lockVersion, values, conflict } = useDraft()
    values.value = { title: 'X' }
    lockVersion.value = 2
    const ok = await save('e1', 'en')
    expect(ok).toBe(true)
    expect(put).toHaveBeenCalledWith('/entries/{uuid}/draft/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
      body: { fields: { title: 'X' }, lock_version: 2 },
    })
    expect(lockVersion.value).toBe(3)
    expect(conflict.value).toBe(false)
  })

  it('surfaces a 409 as a conflict the user can reload from', async () => {
    put.mockResolvedValueOnce({ data: undefined, error: { code: 'STALE_DRAFT', current: { lock_version: 5, fields: { title: 'Theirs' } } }, response: { status: 409 } })
    const { save, conflict } = useDraft()
    const ok = await save('e1', 'en')
    expect(ok).toBe(false)
    expect(conflict.value).toBe(true)
  })

  it('surfaces 422 field validation errors keyed by field name', async () => {
    put.mockResolvedValueOnce({ data: undefined, error: { errors: { title: 'must be a string' } }, response: { status: 422 } })
    const { save, fieldErrors } = useDraft()
    const ok = await save('e1', 'en')
    expect(ok).toBe(false)
    expect(fieldErrors.value.title).toMatch(/string/)
  })
})
```

Run: `npm --prefix admin run test -- useDraft`
Expected: FAIL — `useDraft` not found.

Implement `admin/src/composables/useDraft.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'
import type { FieldDefinition } from '@/fields/types'

// Owns the schema-driven draft: load the content type schema + the entry's draft, edit field
// values in memory, and save under optimistic concurrency (lock_version). A 409 is surfaced as
// `conflict` ("reload — someone else edited this"); a 422 maps to per-field `fieldErrors`.
export function useDraft() {
  const fields = ref<FieldDefinition[]>([])
  const values = ref<Record<string, unknown>>({})
  const lockVersion = ref(0)
  const fieldErrors = ref<Record<string, string>>({})
  const conflict = ref(false)
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function load(typeSlug: string, uuid: string, locale: string): Promise<void> {
    loading.value = true
    error.value = null
    conflict.value = false
    const typeRes = await api.GET('/content-types/{slug}', { params: { path: { slug: typeSlug } } })
    fields.value = (typeRes.data?.data?.content_type?.schema ?? []) as FieldDefinition[]
    const draftRes = await api.GET('/entries/{uuid}/draft/{locale}', { params: { path: { uuid, locale } } })
    const draft = draftRes.data?.data?.draft
    values.value = (draft?.fields ?? {}) as Record<string, unknown>
    lockVersion.value = (draft?.lock_version ?? 0) as number
    loading.value = false
  }

  function setValue(name: string, value: unknown): void {
    values.value = { ...values.value, [name]: value }
  }

  async function save(uuid: string, locale: string): Promise<boolean> {
    fieldErrors.value = {}
    conflict.value = false
    error.value = null
    const { data, error: err, response } = await api.PUT('/entries/{uuid}/draft/{locale}', {
      params: { path: { uuid, locale } },
      body: { fields: values.value, lock_version: lockVersion.value },
    })
    if (response?.status === 409) {
      conflict.value = true
      return false
    }
    if (response?.status === 422) {
      fieldErrors.value = (err?.errors ?? {}) as Record<string, string>
      return false
    }
    if (err || !data) {
      error.value = (err?.message as string) ?? 'Failed to save draft'
      return false
    }
    lockVersion.value = (data.data?.draft?.lock_version ?? lockVersion.value) as number
    return true
  }

  return { fields, values, lockVersion, fieldErrors, conflict, error, loading, load, setValue, save }
}
```

- [ ] **Step 6: Implement the edit view** wiring `useDraft` + `FieldEditor`. Create `admin/src/views/EntryEditView.vue`:
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useDraft } from '@/composables/useDraft'
import { getRuntimeConfig } from '@/runtimeConfig'
import FieldEditor from '@/fields/FieldEditor.vue'

const props = defineProps<{ type: string; uuid: string }>()
const locale = getRuntimeConfig().defaultLocale
const draft = useDraft()
const saved = ref(false)

onMounted(() => draft.load(props.type, props.uuid, locale))

async function onSave() {
  saved.value = await draft.save(props.uuid, locale)
}
</script>

<template>
  <section class="space-y-4 max-w-3xl">
    <header class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">Edit {{ type }}</h1>
      <UButton :loading="draft.loading.value" @click="onSave">Save draft</UButton>
    </header>
    <UAlert v-if="draft.conflict.value" color="warning"
      title="This draft was edited elsewhere — reload to see the latest before saving again." />
    <UAlert v-if="saved" color="success" title="Draft saved." />
    <FieldEditor
      :fields="draft.fields.value"
      :values="draft.values.value"
      :errors="draft.fieldErrors.value"
      @update:value="draft.setValue"
    />
  </section>
</template>
```

- [ ] **Step 7: Run; verify pass.**

Run: `npm --prefix admin run test -- fields useDraft`
Expected: PASS — registry mapping + fallback, StringField round-trip + required, Markdown preview, and useDraft load/save/409/422.

- [ ] **Step 8: Commit.**
```bash
git add admin/src/fields admin/src/composables/useDraft.ts admin/src/views/EntryEditView.vue \
  admin/test/mountWithUi.ts admin/test/fields admin/test/composables/useDraft.spec.ts
git commit -m "Add schema-driven field editor (type-component registry) and useDraft"
```

---

## Task group 5 — Route/slug editor + Preview + publish/unpublish

**Files:** `admin/src/composables/useRoutes.ts`, `usePublish.ts`, `usePreview.ts`, plus edit-view wiring; specs for each.

- [ ] **Step 1: Write the failing tests.** Create `admin/test/composables/usePublish.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
const post = vi.fn()
vi.mock('@/api/client', () => ({ api: { POST: post }, configureClientAuth: vi.fn() }))
import { usePublish } from '@/composables/usePublish'

describe('usePublish', () => {
  beforeEach(() => post.mockReset())

  it('publishes an entry locale', async () => {
    post.mockResolvedValueOnce({ data: { data: { version_uuid: 'v1' } }, error: undefined })
    const { publish, error } = usePublish()
    const ok = await publish('e1', 'en')
    expect(post).toHaveBeenCalledWith('/entries/{uuid}/publish/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } } })
    expect(ok).toBe(true)
    expect(error.value).toBeNull()
  })

  it('surfaces a publish validation error (422)', async () => {
    post.mockResolvedValueOnce({ data: undefined, error: { errors: { title: 'required' } } })
    const { publish, error } = usePublish()
    const ok = await publish('e1', 'en')
    expect(ok).toBe(false)
    expect(error.value).toBeTruthy()
  })

  it('unpublishes an entry locale', async () => {
    post.mockResolvedValueOnce({ data: { data: {} }, error: undefined })
    const { unpublish } = usePublish()
    expect(await unpublish('e1', 'en')).toBe(true)
    expect(post).toHaveBeenCalledWith('/entries/{uuid}/unpublish/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } } })
  })
})
```
Create `admin/test/composables/usePreview.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
const post = vi.fn()
vi.mock('@/api/client', () => ({ api: { POST: post }, configureClientAuth: vi.fn() }))
vi.mock('@/runtimeConfig', () => ({ getRuntimeConfig: () => ({ apiBase: '/v1/admin', sitePreviewUrl: 'https://getlemma.dev/preview?token={token}', defaultLocale: 'en' }) }))
import { usePreview } from '@/composables/usePreview'

describe('usePreview', () => {
  beforeEach(() => post.mockReset())

  it('mints a token and builds the configured site preview URL', async () => {
    post.mockResolvedValueOnce({ data: { data: { token: 'TOK', expires_in: 600 } }, error: undefined })
    const { buildPreviewUrl } = usePreview()
    const url = await buildPreviewUrl('e1', 'en')
    expect(post).toHaveBeenCalledWith('/entries/{uuid}/preview/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } }, body: {} })
    expect(url).toBe('https://getlemma.dev/preview?token=TOK')
  })

  it('appends token as a query param when the template has no {token} placeholder', async () => {
    post.mockResolvedValueOnce({ data: { data: { token: 'TOK' } }, error: undefined })
    vi.doMock('@/runtimeConfig', () => ({ getRuntimeConfig: () => ({ apiBase: '/v1/admin', sitePreviewUrl: 'https://getlemma.dev/preview', defaultLocale: 'en' }) }))
    const { buildPreviewUrl } = usePreview()
    const url = await buildPreviewUrl('e1', 'en')
    expect(url).toContain('TOK')
  })
})
```
Create `admin/test/composables/useRoutes.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
const get = vi.fn(); const put = vi.fn(); const del = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get, PUT: put, DELETE: del }, configureClientAuth: vi.fn() }))
import { useRoutes } from '@/composables/useRoutes'

describe('useRoutes', () => {
  beforeEach(() => { get.mockReset(); put.mockReset(); del.mockReset() })

  it('assigns a slug for an entry+locale', async () => {
    put.mockResolvedValueOnce({ data: { data: { routes: [{ locale: 'en', slug: 'home' }] } }, error: undefined })
    const { assign } = useRoutes()
    const ok = await assign('e1', 'en', 'home')
    expect(put).toHaveBeenCalledWith('/entries/{uuid}/routes/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } }, body: { slug: 'home' } })
    expect(ok).toBe(true)
  })

  it('reports a 409 slug clash', async () => {
    put.mockResolvedValueOnce({ data: undefined, error: { code: 'ROUTE_TAKEN' }, response: { status: 409 } })
    const { assign, error } = useRoutes()
    expect(await assign('e1', 'en', 'taken')).toBe(false)
    expect(error.value).toMatch(/in use/i)
  })

  it('removes a slug', async () => {
    del.mockResolvedValueOnce({ data: { data: {} }, error: undefined })
    const { remove } = useRoutes()
    expect(await remove('e1', 'en')).toBe(true)
    expect(del).toHaveBeenCalledWith('/entries/{uuid}/routes/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } } })
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- usePublish usePreview useRoutes`
Expected: FAIL — composables not found.

- [ ] **Step 3: Implement the composables.**

`admin/src/composables/usePublish.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export function usePublish() {
  const error = ref<string | null>(null)
  const busy = ref(false)

  async function publish(uuid: string, locale: string): Promise<boolean> {
    busy.value = true; error.value = null
    const { error: err } = await api.POST('/entries/{uuid}/publish/{locale}', { params: { path: { uuid, locale } } })
    busy.value = false
    if (err) {
      error.value = err.errors ? Object.values(err.errors).join('; ') : (err.message as string) ?? 'Publish failed'
      return false
    }
    return true
  }

  async function unpublish(uuid: string, locale: string): Promise<boolean> {
    busy.value = true; error.value = null
    const { error: err } = await api.POST('/entries/{uuid}/unpublish/{locale}', { params: { path: { uuid, locale } } })
    busy.value = false
    if (err) { error.value = (err.message as string) ?? 'Unpublish failed'; return false }
    return true
  }

  return { publish, unpublish, error, busy }
}
```

`admin/src/composables/usePreview.ts`:
```ts
import { api } from '@/api/client'
import { getRuntimeConfig } from '@/runtimeConfig'

// Preview opens the CONFIGURED frontend URL, not the raw JSON preview API (Lemma is headless).
// 1) mint a token via the admin API, 2) build the site preview URL from runtime config — either
// substituting a {token} placeholder or appending ?token=.
export function usePreview() {
  async function buildPreviewUrl(uuid: string, locale: string): Promise<string | null> {
    const { data, error } = await api.POST('/entries/{uuid}/preview/{locale}', {
      params: { path: { uuid, locale } },
      body: {},
    })
    const token = data?.data?.token
    if (error || !token) return null

    const template = getRuntimeConfig().sitePreviewUrl
    if (!template) return null
    if (template.includes('{token}')) return template.replace('{token}', encodeURIComponent(token))
    const sep = template.includes('?') ? '&' : '?'
    return `${template}${sep}token=${encodeURIComponent(token)}`
  }

  return { buildPreviewUrl }
}
```

`admin/src/composables/useRoutes.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export interface EntryRoute { locale: string; slug: string }

export function useRoutes() {
  const routes = ref<EntryRoute[]>([])
  const error = ref<string | null>(null)

  async function fetchFor(uuid: string): Promise<void> {
    const { data } = await api.GET('/entries/{uuid}/routes', { params: { path: { uuid } } })
    routes.value = (data?.data?.routes ?? []) as EntryRoute[]
  }

  async function assign(uuid: string, locale: string, slug: string): Promise<boolean> {
    error.value = null
    const { data, error: err, response } = await api.PUT('/entries/{uuid}/routes/{locale}', {
      params: { path: { uuid, locale } }, body: { slug },
    })
    if (response?.status === 409) { error.value = 'That slug is already in use.'; return false }
    if (err || !data) { error.value = (err?.message as string) ?? 'Failed to assign route'; return false }
    routes.value = (data.data?.routes ?? []) as EntryRoute[]
    return true
  }

  async function remove(uuid: string, locale: string): Promise<boolean> {
    error.value = null
    const { error: err } = await api.DELETE('/entries/{uuid}/routes/{locale}', { params: { path: { uuid, locale } } })
    if (err) { error.value = (err.message as string) ?? 'Failed to remove route'; return false }
    routes.value = routes.value.filter((r) => r.locale !== locale)
    return true
  }

  return { routes, error, fetchFor, assign, remove }
}
```

- [ ] **Step 4: Wire into the edit view.** Extend `admin/src/views/EntryEditView.vue` `<script setup>` and template with a slug input + Preview/Publish/Unpublish buttons (uses `useRoutes`, `usePreview`, `usePublish`). Add to the script:
```ts
import { useRoutes } from '@/composables/useRoutes'
import { usePreview } from '@/composables/usePreview'
import { usePublish } from '@/composables/usePublish'

const routes = useRoutes()
const preview = usePreview()
const publisher = usePublish()
const slug = ref('')

onMounted(async () => { await routes.fetchFor(props.uuid); slug.value = routes.routes.value.find((r) => r.locale === locale)?.slug ?? '' })

async function saveSlug() { await routes.assign(props.uuid, locale, slug.value) }
async function openPreview() {
  const url = await preview.buildPreviewUrl(props.uuid, locale)
  if (url) window.open(url, '_blank', 'noopener')
}
async function publish() { await publisher.publish(props.uuid, locale) }
async function unpublish() { await publisher.unpublish(props.uuid, locale) }
```
and to the template (after the FieldEditor):
```vue
    <UCard>
      <template #header><h2 class="text-sm font-medium">Route &amp; publish</h2></template>
      <div class="space-y-3">
        <UFormField label="Slug" :error="routes.error.value ?? undefined">
          <div class="flex gap-2"><UInput v-model="slug" placeholder="my-page" /><UButton variant="soft" @click="saveSlug">Save slug</UButton></div>
        </UFormField>
        <UAlert v-if="publisher.error.value" color="error" :title="publisher.error.value" />
        <div class="flex gap-2">
          <UButton variant="outline" @click="openPreview">Preview</UButton>
          <UButton color="success" :loading="publisher.busy.value" @click="publish">Publish</UButton>
          <UButton color="neutral" variant="soft" @click="unpublish">Unpublish</UButton>
        </div>
      </div>
    </UCard>
```

- [ ] **Step 5: Run; verify pass.**

Run: `npm --prefix admin run test -- usePublish usePreview useRoutes`
Expected: PASS — publish + 422, unpublish, mint-and-build-URL (both template forms), assign + 409 clash + remove.

- [ ] **Step 6: Commit.**
```bash
git add admin/src/composables/useRoutes.ts admin/src/composables/usePublish.ts admin/src/composables/usePreview.ts \
  admin/src/views/EntryEditView.vue admin/test/composables/usePublish.spec.ts \
  admin/test/composables/usePreview.spec.ts admin/test/composables/useRoutes.spec.ts
git commit -m "Add route/slug editor, configured-URL preview, publish/unpublish"
```

---

## Task group 6 — Versions + rollback, schedules, redirects

**Files:** `admin/src/composables/useVersions.ts`, `useSchedules.ts`, `useRedirects.ts`, `admin/src/views/VersionsView.vue`, plus specs.

- [ ] **Step 1: Write the failing tests.** Create `admin/test/composables/useVersions.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
const get = vi.fn(); const post = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get, POST: post }, configureClientAuth: vi.fn() }))
import { useVersions } from '@/composables/useVersions'

describe('useVersions', () => {
  beforeEach(() => { get.mockReset(); post.mockReset() })

  it('lists versions for an entry+locale', async () => {
    get.mockResolvedValueOnce({ data: { data: { versions: [{ uuid: 'v2', version: 2 }, { uuid: 'v1', version: 1 }] } }, error: undefined })
    const { list, versions } = useVersions()
    await list('e1', 'en')
    expect(get).toHaveBeenCalledWith('/entries/{uuid}/versions/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } } })
    expect(versions.value).toHaveLength(2)
  })

  it('rolls back to a version', async () => {
    post.mockResolvedValueOnce({ data: { data: { version_uuid: 'v1' } }, error: undefined })
    const { rollback } = useVersions()
    const ok = await rollback('e1', 'en', 'v1')
    expect(post).toHaveBeenCalledWith('/entries/{uuid}/rollback/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } }, body: { version_uuid: 'v1' } })
    expect(ok).toBe(true)
  })
})
```
Create `admin/test/composables/useSchedules.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
const get = vi.fn(); const post = vi.fn(); const del = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get, POST: post, DELETE: del }, configureClientAuth: vi.fn() }))
import { useSchedules } from '@/composables/useSchedules'

describe('useSchedules', () => {
  beforeEach(() => { get.mockReset(); post.mockReset(); del.mockReset() })

  it('schedules a publish at an absolute time', async () => {
    post.mockResolvedValueOnce({ data: { data: { schedule: { uuid: 's1' } } }, error: undefined })
    const { schedule } = useSchedules()
    const ok = await schedule('e1', 'en', 'publish', '2999-01-01T00:00:00Z')
    expect(post).toHaveBeenCalledWith('/entries/{uuid}/schedules/{locale}', { params: { path: { uuid: 'e1', locale: 'en' } }, body: { action: 'publish', run_at: '2999-01-01T00:00:00Z' } })
    expect(ok).toBe(true)
  })

  it('lists and cancels schedules', async () => {
    get.mockResolvedValueOnce({ data: { data: { schedules: [{ uuid: 's1', action: 'publish' }] } }, error: undefined })
    del.mockResolvedValueOnce({ data: { data: {} }, error: undefined })
    const { list, cancel, schedules } = useSchedules()
    await list('e1')
    expect(schedules.value).toHaveLength(1)
    expect(await cancel('e1', 's1')).toBe(true)
    expect(del).toHaveBeenCalledWith('/entries/{uuid}/schedules/{scheduleUuid}', { params: { path: { uuid: 'e1', scheduleUuid: 's1' } } })
  })
})
```
Create `admin/test/composables/useRedirects.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
const get = vi.fn(); const post = vi.fn(); const del = vi.fn()
vi.mock('@/api/client', () => ({ api: { GET: get, POST: post, DELETE: del }, configureClientAuth: vi.fn() }))
import { useRedirects } from '@/composables/useRedirects'

describe('useRedirects', () => {
  beforeEach(() => { get.mockReset(); post.mockReset(); del.mockReset() })

  it('creates a redirect for a content type', async () => {
    post.mockResolvedValueOnce({ data: { data: { redirect: { uuid: 'r1' } } }, error: undefined })
    const { create } = useRedirects()
    const ok = await create('page', { locale: 'en', source_slug: 'old', target: { url: '/new' }, status: 301 })
    expect(post).toHaveBeenCalledWith('/content-types/{slug}/redirects', { params: { path: { slug: 'page' } }, body: { locale: 'en', source_slug: 'old', target: { url: '/new' }, status: 301 } })
    expect(ok).toBe(true)
  })

  it('lists and deletes redirects', async () => {
    get.mockResolvedValueOnce({ data: { data: { redirects: [{ uuid: 'r1' }] } }, error: undefined })
    del.mockResolvedValueOnce({ data: { data: {} }, error: undefined })
    const { list, remove, redirects } = useRedirects()
    await list('page')
    expect(redirects.value).toHaveLength(1)
    expect(await remove('r1')).toBe(true)
    expect(del).toHaveBeenCalledWith('/redirects/{uuid}', { params: { path: { uuid: 'r1' } } })
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- useVersions useSchedules useRedirects`
Expected: FAIL — composables not found.

- [ ] **Step 3: Implement the composables.**

`admin/src/composables/useVersions.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export interface VersionRow { uuid: string; version: number; fields?: Record<string, unknown>; created_at?: string }

export function useVersions() {
  const versions = ref<VersionRow[]>([])
  const error = ref<string | null>(null)

  async function list(uuid: string, locale: string): Promise<void> {
    error.value = null
    const { data, error: err } = await api.GET('/entries/{uuid}/versions/{locale}', { params: { path: { uuid, locale } } })
    if (err) { error.value = (err.message as string) ?? 'Failed to load versions'; versions.value = []; return }
    versions.value = (data?.data?.versions ?? []) as VersionRow[]
  }

  async function rollback(uuid: string, locale: string, versionUuid: string): Promise<boolean> {
    error.value = null
    const { error: err } = await api.POST('/entries/{uuid}/rollback/{locale}', {
      params: { path: { uuid, locale } }, body: { version_uuid: versionUuid },
    })
    if (err) { error.value = err.errors ? Object.values(err.errors).join('; ') : (err.message as string) ?? 'Rollback failed'; return false }
    return true
  }

  return { versions, error, list, rollback }
}
```

`admin/src/composables/useSchedules.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export interface ScheduleRow { uuid: string; action: 'publish' | 'unpublish'; run_at?: string; status?: string }

export function useSchedules() {
  const schedules = ref<ScheduleRow[]>([])
  const error = ref<string | null>(null)

  async function list(uuid: string): Promise<void> {
    error.value = null
    const { data, error: err } = await api.GET('/entries/{uuid}/schedules', { params: { path: { uuid } } })
    if (err) { error.value = (err.message as string) ?? 'Failed to load schedules'; schedules.value = []; return }
    schedules.value = (data?.data?.schedules ?? []) as ScheduleRow[]
  }

  async function schedule(uuid: string, locale: string, action: 'publish' | 'unpublish', runAt: string): Promise<boolean> {
    error.value = null
    const { error: err } = await api.POST('/entries/{uuid}/schedules/{locale}', {
      params: { path: { uuid, locale } }, body: { action, run_at: runAt },
    })
    if (err) { error.value = (err.message as string) ?? 'Failed to schedule'; return false }
    return true
  }

  async function cancel(uuid: string, scheduleUuid: string): Promise<boolean> {
    error.value = null
    const { error: err } = await api.DELETE('/entries/{uuid}/schedules/{scheduleUuid}', { params: { path: { uuid, scheduleUuid } } })
    if (err) { error.value = (err.message as string) ?? 'Failed to cancel'; return false }
    return true
  }

  return { schedules, error, list, schedule, cancel }
}
```

`admin/src/composables/useRedirects.ts`:
```ts
import { ref } from 'vue'
import { api } from '@/api/client'

export interface RedirectInput {
  locale: string
  source_slug: string
  target: { url?: string; entry_uuid?: string; content_type?: string; locale?: string }
  status: number
}
export interface RedirectRow { uuid: string; source_slug?: string; status?: number }

export function useRedirects() {
  const redirects = ref<RedirectRow[]>([])
  const error = ref<string | null>(null)

  async function list(typeSlug: string): Promise<void> {
    error.value = null
    const { data, error: err } = await api.GET('/content-types/{slug}/redirects', { params: { path: { slug: typeSlug } } })
    if (err) { error.value = (err.message as string) ?? 'Failed to load redirects'; redirects.value = []; return }
    redirects.value = (data?.data?.redirects ?? []) as RedirectRow[]
  }

  async function create(typeSlug: string, input: RedirectInput): Promise<boolean> {
    error.value = null
    const { error: err } = await api.POST('/content-types/{slug}/redirects', { params: { path: { slug: typeSlug } }, body: input })
    if (err) { error.value = (err.message as string) ?? 'Failed to create redirect'; return false }
    return true
  }

  async function remove(uuid: string): Promise<boolean> {
    error.value = null
    const { error: err } = await api.DELETE('/redirects/{uuid}', { params: { path: { uuid } } })
    if (err) { error.value = (err.message as string) ?? 'Failed to delete redirect'; return false }
    return true
  }

  return { redirects, error, list, create, remove }
}
```

- [ ] **Step 4: Implement the versions view.** Create `admin/src/views/VersionsView.vue`:
```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useVersions } from '@/composables/useVersions'
import { getRuntimeConfig } from '@/runtimeConfig'

const props = defineProps<{ type: string; uuid: string }>()
const locale = getRuntimeConfig().defaultLocale
const { versions, error, list, rollback } = useVersions()

onMounted(() => list(props.uuid, locale))
async function onRollback(versionUuid: string) {
  if (await rollback(props.uuid, locale, versionUuid)) await list(props.uuid, locale)
}
</script>

<template>
  <section class="space-y-4 max-w-2xl">
    <h1 class="text-xl font-semibold">Version history</h1>
    <UAlert v-if="error" color="error" :title="error" />
    <ul class="divide-y border rounded">
      <li v-for="v in versions" :key="v.uuid" class="flex items-center justify-between p-3">
        <span>v{{ v.version }} <span class="text-xs text-gray-500">{{ v.created_at }}</span></span>
        <UButton size="xs" variant="soft" @click="onRollback(v.uuid)">Roll back</UButton>
      </li>
    </ul>
  </section>
</template>
```

- [ ] **Step 5: Run; verify pass.**

Run: `npm --prefix admin run test -- useVersions useSchedules useRedirects`
Expected: PASS — versions list + rollback, schedule create/list/cancel, redirect create/list/delete.

- [ ] **Step 6: Commit.**
```bash
git add admin/src/composables/useVersions.ts admin/src/composables/useSchedules.ts admin/src/composables/useRedirects.ts \
  admin/src/views/VersionsView.vue admin/test/composables/useVersions.spec.ts \
  admin/test/composables/useSchedules.spec.ts admin/test/composables/useRedirects.spec.ts
git commit -m "Add versions/rollback, schedules, and redirects composables + versions view"
```

---

## Task group 7 — Upload-and-use asset field (no library)

The `asset` field uploads to the **core blob upload route** and stores the returned blob UUID. There is **no library/list** (the core blob routes expose no GET collection). Confirm the effective upload path from the manifest first (same prefix caveat as auth): the framework registers `POST /blobs` (framework `routes/blobs.php`), which with the API prefix becomes `/api/v1/blobs`. The upload response returns `{ uuid, url, mime_type, ... }` (envelope under `data`).

```bash
php glueful route:list | grep -i blob   # confirm the effective POST /blobs path
```

**Files:** `admin/src/composables/useMedia.ts`, `admin/test/composables/useMedia.spec.ts` (AssetField.vue already wired in Task 4).

- [ ] **Step 1: Write the failing test.** Create `admin/test/composables/useMedia.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
vi.mock('@/runtimeConfig', () => ({ getRuntimeConfig: () => ({ apiBase: '/v1/admin', sitePreviewUrl: '', defaultLocale: 'en' }) }))
vi.mock('@/stores/session', () => ({ useSession: () => ({ accessToken: 'a.b.c' }) }))
import { useMedia } from '@/composables/useMedia'

describe('useMedia', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('uploads a file and returns the blob uuid', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: { uuid: 'blob-123', url: '/x', mime_type: 'image/png' } }), { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)
    const { upload } = useMedia()
    const file = new File(['x'], 'a.png', { type: 'image/png' })
    const uuid = await upload(file)
    expect(uuid).toBe('blob-123')
    // multipart POST with the `file` field, bearer attached.
    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toContain('/blobs')
    expect((init as RequestInit).method).toBe('POST')
    expect((init as any).body).toBeInstanceOf(FormData)
    expect(((init as any).headers as Record<string, string>).Authorization).toBe('Bearer a.b.c')
  })

  it('surfaces an upload error and returns null', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('no', { status: 413 })))
    const { upload, error } = useMedia()
    const uuid = await upload(new File(['x'], 'a.png', { type: 'image/png' }))
    expect(uuid).toBeNull()
    expect(error.value).toBeTruthy()
  })
})
```

- [ ] **Step 2: Run; verify fail.**

Run: `npm --prefix admin run test -- useMedia`
Expected: FAIL — `useMedia` not found.

- [ ] **Step 3: Implement `useMedia`.** Create `admin/src/composables/useMedia.ts`:
```ts
import { ref } from 'vue'
import { useSession } from '@/stores/session'

// Upload-and-use (spec §Media): POST the file to the core blob route, store the returned blob
// UUID. NO library/picker — the core blob routes expose no GET collection, so a "pick existing"
// flow has no backend in Phase 1. The blob route is NOT under the admin apiBase (it's the core
// /blobs route, prefixed like auth), so we call it via fetch with the bearer token attached.
const BLOB_UPLOAD_PATH = '/api/v1/blobs' // PINNED from `php glueful route:list | grep blob`

export function useMedia() {
  const uploading = ref(false)
  const error = ref<string | null>(null)

  async function upload(file: File): Promise<string | null> {
    uploading.value = true
    error.value = null
    try {
      const form = new FormData()
      form.append('file', file)
      const token = useSession().accessToken
      const res = await fetch(BLOB_UPLOAD_PATH, {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: form,
      })
      if (!res.ok) {
        error.value = `Upload failed (${res.status})`
        return null
      }
      const json = await res.json()
      const uuid = json?.data?.uuid ?? json?.uuid ?? null
      if (!uuid) { error.value = 'Upload returned no blob UUID'; return null }
      return uuid as string
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Upload failed'
      return null
    } finally {
      uploading.value = false
    }
  }

  return { upload, uploading, error }
}
```

- [ ] **Step 4: Run; verify pass.**

Run: `npm --prefix admin run test -- useMedia`
Expected: PASS — multipart `file` upload with bearer, returns the blob uuid; error path returns null.

- [ ] **Step 5: Commit.**
```bash
git add admin/src/composables/useMedia.ts admin/test/composables/useMedia.spec.ts
git commit -m "Add upload-and-use asset field via core blob route (no library)"
```

---

## Task group 8 — Schema-boundary test, packaging, CI, docs

### Task 8a: THE SCHEMA-BOUNDARY TEST (named, runnable)

This is the spec's hard boundary, enforced — not a prose note. It asserts that **no Phase 1 source** issues a schema-mutating request: no call site references `PATCH /content-types/{slug}/schema` or `POST /content-types/{slug}/migrations`. It works two ways for defense in depth: (1) a **static source audit** scanning every `admin/src` file for the forbidden endpoint strings / methods, and (2) a **runtime mock-client assertion** that exercising the editor never invokes `api.PATCH` on the schema endpoint.

**Files:** `admin/test/schemaBoundary.spec.ts`.

- [ ] **Step 1: Write the test (it must pass once the code is correct — but FAIL if anyone adds a schema mutation).** Create `admin/test/schemaBoundary.spec.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath, URL } from 'node:url'

const SRC = fileURLToPath(new URL('../src', import.meta.url))

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : [full]
  })
}

describe('Phase 1 schema boundary (HARD: consume schema, never mutate)', () => {
  const files = walk(SRC).filter((f) => /\.(ts|vue)$/.test(f))

  it('no source references the schema-mutating endpoints', () => {
    // Phase 2 (schema builder) owns PATCH /content-types/{slug}/schema and
    // POST /content-types/{slug}/migrations. No Phase 1 screen/composable may call them.
    const forbidden = [
      '/content-types/{slug}/schema',
      '/content-types/{slug}/migrations',
    ]
    const offenders: string[] = []
    for (const file of files) {
      const src = readFileSync(file, 'utf8')
      for (const needle of forbidden) {
        if (src.includes(needle)) offenders.push(`${file} references ${needle}`)
      }
    }
    expect(offenders, offenders.join('\n')).toEqual([])
  })

  it('no source issues a PATCH to a content-type schema path via the client', () => {
    // Guard the method+path shape too (e.g. api.PATCH('/content-types/...')), not just the
    // literal template strings, so an interpolated path can't slip the first check.
    const offenders: string[] = []
    for (const file of files) {
      const src = readFileSync(file, 'utf8')
      // any api.PATCH(... content-types ...) or api.POST(... migrations ...)
      if (/\.PATCH\(\s*['"`][^'"`]*content-types/.test(src)) offenders.push(`${file}: PATCH to content-types`)
      if (/\.POST\(\s*['"`][^'"`]*content-types\/[^'"`]*migrations/.test(src)) offenders.push(`${file}: POST migrations`)
    }
    expect(offenders, offenders.join('\n')).toEqual([])
  })
})
```

- [ ] **Step 2: Run it; verify it PASSES (the codebase is already clean).**

Run: `npm --prefix admin run test -- schemaBoundary`
Expected: PASS — no Phase 1 source touches the schema-mutation endpoints. (Sanity-check the guard: temporarily add `api.PATCH('/content-types/{slug}/schema', {})` to a scratch file, re-run, see it FAIL, then remove it. Document this in the commit body, do not commit the scratch file.)

- [ ] **Step 3: Commit.**
```bash
git add admin/test/schemaBoundary.spec.ts
git commit -m "Enforce Phase 1 schema-consume-only boundary with a source/route audit test"
```

### Task 8b: One Playwright e2e (the editorial loop in miniature)

**Files:** `admin/playwright.config.ts`, `admin/e2e/editorial-loop.spec.ts`.

- [ ] **Step 1: Add the Playwright config.** Create `admin/playwright.config.ts`:
```ts
import { defineConfig } from '@playwright/test'

// Runs against a locally-served backend + built SPA. The webServer boots `php glueful serve`
// from the repo root (so /admin and /v1/admin are both reachable); BASE_URL points at it. The
// backend must have a seeded editor user + a `page` content type (see e2e spec setup notes).
export default defineConfig({
  testDir: './e2e',
  use: { baseURL: process.env.BASE_URL ?? 'http://127.0.0.1:8000', headless: true },
  webServer: {
    command: 'php glueful serve --port=8000',
    cwd: '..',
    url: 'http://127.0.0.1:8000/admin/config.json',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
})
```

- [ ] **Step 2: Write the e2e.** Create `admin/e2e/editorial-loop.spec.ts`:
```ts
import { test, expect } from '@playwright/test'

// log in → create a `page` → fill title + Markdown body → assign a slug → publish → see it in
// the delivery API. Mirrors the getlemma.dev loop. Requires a seeded editor + `page` type with a
// `title` (string) and `body` (text) field, and delivery readable for `page`.
const EMAIL = process.env.E2E_EMAIL ?? 'editor@getlemma.dev'
const PASSWORD = process.env.E2E_PASSWORD ?? 'password'

test('editor authors and publishes a page', async ({ page, request }) => {
  await page.goto('/admin/login')
  await page.getByLabel('Email').fill(EMAIL)
  await page.getByLabel('Password').fill(PASSWORD)
  await page.getByRole('button', { name: 'Sign in' }).click()

  await page.getByRole('link', { name: 'Page' }).click()
  await page.getByRole('button', { name: /New page/i }).click()

  await page.getByLabel('title').fill('E2E Home')
  await page.getByLabel('body').locator('textarea').fill('# Welcome')
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByText('Draft saved.')).toBeVisible()

  await page.getByPlaceholder('my-page').fill('e2e-home')
  await page.getByRole('button', { name: 'Save slug' }).click()
  await page.getByRole('button', { name: 'Publish' }).click()

  // The published entry is now visible through the delivery API.
  const res = await request.get('/v1/content/page/e2e-home')
  expect(res.ok()).toBeTruthy()
})
```

> Confirm the delivery read path (`/v1/content/{type}/{slug}`) against `routes/lemma_delivery.php` / `php glueful route:list | grep content` and adjust the request URL + any required `X-API-Key` header for a private type. If `page` is not `public_delivery`, add the key header. This e2e is gated behind a seeded backend; it is NOT part of `npm run test` (unit) — it runs via `npm run test:e2e`.

- [ ] **Step 3: Commit.**
```bash
git add admin/playwright.config.ts admin/e2e/editorial-loop.spec.ts
git commit -m "Add Playwright e2e: author and publish a page end-to-end"
```

### Task 8c: Packaging (export-ignore), repo gitignore, CI, docs

**Files:** `.gitattributes`, `.gitignore`, `.github/workflows/admin.yml`, `docs/ADMIN_SPA.md`.

- [ ] **Step 1: export-ignore the source, gitignore the dev build.** The distribution model is WordPress-style: the published package (the Packagist **dist** of a release tag, consumed via `composer create-project glueful/lemma`) ships the **compiled** `public/admin/` — never the Vue source or toolchain — so a fresh install runs with no Node. Two pieces:

  Create/append `.gitattributes` at repo root to strip the SPA **source** from the dist tarball. Note `public/admin/` is **deliberately NOT** listed — it MUST ship:
```
# The SPA source + toolchain never ship in the Packagist dist — only the compiled
# public/admin/ does (baked into the release tag by .github/workflows/release.yml).
# A create-project / install therefore needs no Node.
/admin            export-ignore
/.github          export-ignore
/docs/superpowers export-ignore
```
  Append to the repo-root `.gitignore` — the build is a generated artifact in dev and is committed **only onto the release tag** (by `release.yml`, Step 2b), never onto `main`:
```
# Compiled admin SPA — generated. Gitignored on main; baked into the release tag by release.yml.
/public/admin/
```

> Verify `public/admin/` is not tracked on `main`: `git ls-files public/admin | head`. If Task 1 Step 6's build committed anything under it, untrack with `git rm -r --cached public/admin` before committing this gitignore. The build re-enters the tree only at release time, on the tag — `main` stays clean.

- [ ] **Step 2: Add the CI build step.** Create `.github/workflows/admin.yml`:
```yaml
name: Admin SPA
on:
  pull_request:
    paths: ['admin/**', 'docs/openapi.json']
  push:
    branches: [main, dev]
    paths: ['admin/**', 'docs/openapi.json']

jobs:
  build-and-test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: admin/package-lock.json
      - run: npm ci
        working-directory: admin
      - name: Typecheck
        run: npm run typecheck
        working-directory: admin
      - name: Unit + component + boundary tests
        run: npm run test
        working-directory: admin
      - name: Build to public/admin
        run: npm run build
        working-directory: admin
      - name: Verify the bundle was produced
        run: test -f public/admin/index.html
```

> `npm ci` needs `admin/package-lock.json`. Generate it locally (`cd admin && npm install`) and commit it in this step so CI is reproducible. This workflow only **validates** the build on PRs/pushes — it does **not** commit `public/admin/` (that happens only at release, Step 2b).

- [ ] **Step 2b: Add the release workflow that bakes the build into the tag.** Packagist archives a release **tag** and runs no build step, so the compiled `public/admin/` must already be in the tag's tree. `release.yml` builds the bundle and bakes it into the tagged commit **without polluting `main`** — it pushes only the tag. Create `.github/workflows/release.yml`:
```yaml
name: Release
on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Release tag to cut (e.g. v1.2.0)'
        required: true

jobs:
  bake-and-tag:
    runs-on: ubuntu-latest
    permissions:
      contents: write              # needed to push the tag
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: admin/package-lock.json
      # The admin build is Node-only: gen:api reads the COMMITTED docs/openapi.json (regenerate
      # + commit it in dev before tagging if the admin API contract changed). No PHP/DB needed.
      - run: npm ci && npm run gen:api && npm run build
        working-directory: admin
      - run: test -f public/admin/index.html
      # Force-add the (dev-gitignored) bundle, commit it locally, tag that commit, and push ONLY
      # the tag. The branch is never pushed, so main never receives the build commit.
      - name: Tag a release commit carrying the compiled bundle
        run: |
          git config user.name  'lemma-release[bot]'
          git config user.email 'release@getlemma.dev'
          git add -f public/admin
          git commit -m "build: bake admin bundle into ${{ inputs.version }}"
          git tag -a "${{ inputs.version }}" -m "Lemma ${{ inputs.version }}"
          git push origin "${{ inputs.version }}"
```

> Result: the `vX.Y.Z` tag's tree contains the compiled `public/admin/`, while `.gitattributes` strips `admin/` (source) from the dist. So `composer create-project glueful/lemma` (the Packagist dist of that tag) yields a ready-to-run CMS whose admin is **already built** — no Node, exactly like a WordPress release ZIP. `main` stays free of build artifacts; only the tag carries them. **Cut Lemma releases through this workflow, not by hand-tagging.**

- [ ] **Step 3: Add the docs note.** Create `docs/ADMIN_SPA.md`:
```markdown
# Admin SPA (Phase 1)

The first-party editor lives in `admin/` (Vue 3 + Vite + Pinia + Nuxt UI). Distribution is
WordPress-style: a release ships ONLY the **compiled** `public/admin/` — the `admin/` source,
`node_modules/`, and the toolchain are `export-ignore`'d, and `.github/workflows/release.yml`
bakes the build into the release **tag** — so `composer create-project glueful/lemma` runs with
no Node. `public/admin/` is gitignored on `main` and only ever committed onto a release tag.

## Develop
```bash
cd admin
npm install
npm run gen:api      # regenerate src/api/schema.d.ts from ../docs/openapi.json
npm run dev          # Vite dev server
npm run test         # Vitest unit/component + the schema-boundary audit
npm run test:e2e     # Playwright (needs a seeded backend on :8000)
```

## Build (what packaging/CI runs)
```bash
composer docs:openapi          # refresh docs/openapi.json after any backend route change
cd admin && npm run gen:api    # regenerate types (a contract drift becomes a TS error)
npm run build                  # emits ../public/admin/  (served by framework serveFrontend() at /admin)
```

## Runtime config
The compiled bundle is NOT env-baked. It fetches `GET /admin/config.json` (unauthenticated) at
boot for `apiBase`, `sitePreviewUrl`, `defaultLocale` — set via `LEMMA_ADMIN_API_BASE`,
`LEMMA_SITE_PREVIEW_URL`, `LEMMA_ADMIN_DEFAULT_LOCALE`.

## Swapping or disabling the admin
The bundled admin is a **replaceable client** of the `/v1/admin` API — nothing in the backend
depends on it. To run a different admin UI:
- **Point at your own build:** set `LEMMA_ADMIN_BUNDLE_PATH` to your compiled bundle dir
  (must contain `index.html`); `serveFrontend('/admin', …)` mounts it instead.
- **Disable the default mount entirely:** set `LEMMA_ADMIN_ENABLED=false`. `/admin` is then
  unmounted; register your own mount (another `serveFrontend()` call, a different route, or a
  separate frontend host) against the same `/v1/admin` API.

This keeps the door open to a future independently-versioned admin package (`glueful/lemma-admin`)
without changing the backend contract.

## Boundary
Phase 1 CONSUMES content-type schema (to render the field editor) and NEVER mutates it. The
test `admin/test/schemaBoundary.spec.ts` fails CI if any source calls
`PATCH /content-types/{slug}/schema` or `POST /content-types/{slug}/migrations` (that is the
Phase 2 schema builder).
```

- [ ] **Step 4: Run the full frontend suite + build once more.**
```bash
npm --prefix admin run test
npm --prefix admin run build && test -f public/admin/index.html && echo OK
```
Expected: all Vitest specs green (every composable, every field, the schema-boundary audit), bundle built.

- [ ] **Step 5: Commit.**
```bash
git add .gitattributes .gitignore .github/workflows/admin.yml .github/workflows/release.yml docs/ADMIN_SPA.md admin/package-lock.json
git commit -m "Package admin SPA: export-ignore source, CI build + release bake, docs"
```

---

## Self-review notes

- **All four backend tasks present & tested.** (0a) `GET /v1/admin/entries?type=` — `EntryRepository::listForType` + `EntryController::index`, perm `lemma.entries.read` via `routes/lemma_admin.php`, display-title derivation (draft title → route slug → uuid), offset pagination mirroring the delivery convention, draft-inclusive (reads `entries`, not the publication spine), `EntryListApiTest`. (0b) `GET /admin/config.json` — **unauthenticated** (registered in `routes/lemma_admin_spa.php`, outside the `/v1/admin` auth group; `AdminConfigApiTest::testConfigRouteIsRegisteredUnauthenticated` asserts no `auth` middleware), values from `config('lemma.admin.*')`. (0c) `/admin` + `/admin/{rest}` with `index.html` deep-link fallback — mounted via the framework `serveFrontend()` seam in `LemmaServiceProvider::boot()` (no hand-rolled controller; behavior owned/tested by the framework's `ServeFrontendTest`), `AdminSpaServingTest` proves the mount is wired and `/admin/config.json` is not shadowed. Requires framework ≥ 1.59.0 (where `serveFrontend()` shipped; Lemma pinned `^1.59.0`) — Step 0 confirms the seam. (0d) `POST /admin/setup` — **unauthenticated but self-locking** first-run web setup: `SetupService::install()` is the single source of truth (shared with the future `php glueful lemma:setup` CLI, see `docs/superpowers/specs/2026-06-19-lemma-cli-onboarding-design.md`), **race-safe** via a DB transaction that re-checks `isInstalled()` with the `lemma_settings.key` PK (+ `glueful/users` email uniqueness) as the constraint backstop; creates the first admin (`glueful/users`) + grants the admin role (`glueful/aegis`) + writes `site_name`/`default_locale`; `SetupController` returns `409` permanently once installed; `config.json` now reports `installed`; `SetupApiTest` covers create→409→`installed` reporting + the invariant-bound gate. DB creds are NOT in web setup (stay in `.env`; the CLI cut is out of scope, deferred to that spec).
- **Schema-boundary test is named & runnable** — `admin/test/schemaBoundary.spec.ts` does a source/route audit over every `admin/src/**/*.{ts,vue}`, failing if any file references `/content-types/{slug}/schema` or `/content-types/{slug}/migrations` (literal strings) OR issues `api.PATCH('/content-types…')` / `api.POST('…/migrations')` (method+path regex). It is its own runnable test (`npm --prefix admin run test -- schemaBoundary`), not a prose note. Confirmed Phase 1 never wires these: `useContentTypes` only `GET /content-types`; `useDraft` only `GET /content-types/{slug}` (read) + `GET/PUT /entries/{uuid}/draft/{locale}`.
- **Every scope item mapped.** 1 Auth shell → Task 2. 2 Content-type nav → Task 3. 3 Draft-inclusive list → Tasks 0a + 3. 4 Create entry → Task 3 (`EntryListView.createEntry` POST `/entries`) + 4. 5 Schema-driven editor → Task 4 (registry + `FieldEditor` + `useDraft`). 6 Markdown textarea + preview → Task 4 `TextField.vue` (markdown-it preview pane, tested). 7 Asset upload-and-use (no library) → Task 7 `useMedia` + `AssetField`. 8 Route/slug editor → Task 5 `useRoutes`. 9 Preview via configured URL → Task 5 `usePreview` (mint token → open `sitePreviewUrl`). 10 Publish/unpublish/schedule → Task 5 (`usePublish`) + 6 (`useSchedules`). 11 Versions + rollback → Task 6 `useVersions` + `VersionsView`. 12 Redirects → Task 6 `useRedirects`. First-run web setup (first admin + site settings; gives the editorial loop a logged-in admin) → Task 0d (`SetupService`/`SetupController`/`SetupData`/`lemma_settings` migration, `POST /admin/setup`, `installed` in `config.json`) + the setup screen Task 1.5 (`SetupView.vue` + the `installed` boot/router guard in `runtimeConfig.ts`/`router.ts`); the standalone `lemma` CLI cut is explicitly out of scope (see `docs/superpowers/specs/2026-06-19-lemma-cli-onboarding-design.md`). Runtime config via `GET /admin/config.json` → 0b + Task 1 `runtimeConfig.ts`. Typed-from-OpenAPI client + domain composables → Task 1 (`openapi-typescript` → `schema.d.ts`, `openapi-fetch` client) + every composable. Static serving + index fallback → 0c. Auth paths pinned via route manifest → Task 2 slice-1 step (+ blob path in Task 7, delivery path in 8b). Packaging (export-ignore source; `release.yml` bakes the compiled `public/admin/` into the release tag, WordPress-style; default admin swappable/disable-able via `lemma.admin.enabled` + `bundle_path`) → Task 8c.
- **Type/name consistency across tasks (verified against real code).** Backend: `EntryController.__construct($context, EntryRepository, ContentTypeRepository, FieldValidator, RouteRepository, ReferenceProjectionRepository, ContentLocaleService, ?SchemaProjector)` — the test's `controller()` matches the real signature. `ContentTypeRepository::findBySlug` returns a hydrated row with `uuid`/`schema`/`schema_version`. `EntryRepository::findDraft` returns `fields`/`lock_version`. `ContentLocaleService::default()` is the default-locale source (used in `index`). `Response::validation`/`::notFound`/`::success` exist (used throughout the existing controller). Tables/columns confirmed via migrations: `entries(uuid, content_type_uuid, status, updated_at, id)`, `entry_drafts(entry_uuid, locale, fields, updated_at)`, `entry_publications(entry_uuid, locale)`, `entry_routes(entry_uuid, locale, slug)`, `entry_schedules(entry_uuid, status, action)`. Frontend: every composable returns refs consumed by its screen; the field `enum` key matches the backend serialization (`ContentTypeSchema::toArray` emits `'enum' => enumValues`); field `type` values are exactly the backend `FieldDefinition::TYPES` set (`string,text,number,boolean,datetime,enum,reference,asset,json`). The `api.GET/POST/PUT/DELETE('/path', { params: { path, query }, body })` call shape is `openapi-fetch`'s, used identically in composables and asserted identically in specs.
- **Unverified seams flagged with a confirm step (not assumed).** the framework `serveFrontend()` seam (0c Step 0 `grep` gate; shipped in framework 1.59.0), the `ResponseData` contract FQCN (0a Step 4), Nuxt UI's exact Vite-plugin/CSS import names (Task 1 CAVEAT), effective auth paths + blob path + delivery path (manifest-pinned in Tasks 2, 7, 8b). Each has an explicit `grep`/`route:list` step before the code relies on it. (`AdminConfigController` returns a Symfony `JsonResponse` directly — no `Response::fromSymfony` bridge; the router normalizes it.)
- **Placeholder scan:** no `TBD`, no "similar to Task N", no "add error handling". Every code step is complete PHP/Vue/TS. The only deliberately-stubbed-then-completed component is `AssetField.vue` (introduced in Task 4 with its real upload contract, its `useMedia` dependency implemented in Task 7) — its interface never changes, so no rework.
- **Test commands match `composer.json`.** Backend `composer test:phpunit -- --filter <Name>` (= `vendor/bin/phpunit`); backend tests extend `App\Tests\Support\LemmaTestCase` (Postgres `lemma_test`, truncates `entries`/`entry_drafts`/`entry_publications`/`entry_routes`/`entry_schedules` between tests — all already in `TABLES`). Frontend `npm --prefix admin run test` (Vitest) + `npm --prefix admin run test:e2e` (Playwright), defined in `admin/package.json`. OpenAPI via `composer docs:openapi` (= `php glueful generate:openapi -f --clean`, writes `docs/openapi.json`).
- **Deferred items honored.** Blob-list/library picker is explicitly NOT built (Task 7 does upload-and-use only). Multi-locale UI is en-only (`defaultLocale` from runtime config); the data model keeps locale. No schema/model builder, no WYSIWYG, no importers — all out of Phase 1.
