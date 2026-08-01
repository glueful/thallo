# lemma-analytics Admin SPA (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the capability-gated admin analytics dashboard (a `/analytics` page + a quiet home KPI strip) over the `lemma-analytics` read API, adding only the two small read-API extensions the dashboard needs.

**Architecture:** Two backend read-layer additions in `packages/lemma-analytics` (route `active_users` through `analytics_active_actors` inside the existing `/series`; add a `/breakdown` endpoint). Then an admin-SPA feature following the established module pattern: a Pinia-Colada query layer over `authFetch`, a capability-gated nav module, an `/analytics` page assembling KPI cards + charts, and a home KPI strip. Charts use `@unovis/vue`, isolated behind two local wrapper components.

**Tech Stack:** PHP 8.3 (Glueful framework, PostgreSQL), Vue 3.5 + Nuxt UI 4 + Pinia Colada + `@unovis/vue`, vitest + vue-tsc, PHPUnit 10.

## Global Constraints

- **Postgres only** — the app DB is PostgreSQL; no SQLite/MySQL branches needed.
- **No new migrations, no new events, no recorder changes** — this phase is read-only visualization over the existing `analytics_daily` / `analytics_active_actors` rollups.
- **`active_users` is a `/series` metric variant, NOT a new endpoint** — same `{ metric, from, to, series: [{day,count}] }` shape.
- **`/breakdown` is one event at a time** — `limit` defaults to `10`, clamped to **max 50** (min 1).
- **Everything gates on `lemma.analytics`** — frontend nav via `requires: ['lemma.analytics']`; backend routes already 404 when the capability is disabled (the route file only loads when enabled).
- **Response envelope:** controllers return `Response::success($payload)` → JSON `{ "data": <payload> }`. `authFetch` unwraps `json.data ?? json`.
- **Charts:** only `@unovis/vue` + `@unovis/ts`; never let unovis types leak into pages or the query layer — go through the two wrapper components.
- **Commits:** no Claude/Anthropic attribution anywhere (no `Co-Authored-By`, no "Generated with Claude Code"). Work on `dev`.
- **Package manager:** admin SPA uses **pnpm**. PHP tests run against the `lemma_test` Postgres DB.

**Test run commands (reference):**
- Backend (from repo root `/Users/michaeltawiahsowah/Sites/glueful/lemma`):
  `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter <TestName>`
  (the `lemma_test` schema is already migrated; this phase adds no migrations.)
- Frontend (from `admin/`): `pnpm test <path/to/spec>` (vitest run), `pnpm type-check` (vue-tsc).

## Commit Protocol

**Do NOT commit until the human explicitly authorizes it.** Each task's final "Commit" step is a *staged, ready-to-run* commit — write the code, get the task green, then STOP and wait for the go-ahead before running `git add`/`git commit`. The commit commands are provided so the exact scope and message are unambiguous once authorized; they are not a licence to commit automatically after each task.

When authorized, the intended cadence is **one commit per task** on `dev` (matching the Phase 1 backend). Commit messages carry **no** Claude/Anthropic attribution — no `Co-Authored-By`, no "Generated with Claude Code". If the human asks to hold all commits to the end, batch them instead — the per-task boundaries above still define the commit scopes.

---

## File Structure

**Backend — `packages/lemma-analytics/`:**
- Modify `src/Query/AnalyticsQuery.php` — `series()` branches on `active_users`; add `breakdown()`. Extract the shared count-by-day + zero-fill so it stays DRY.
- Modify `src/Http/Controllers/AnalyticsController.php` — add `breakdown(Request)`.
- Modify `routes/admin-routes.php` — register `GET /analytics/breakdown`.
- Tests (repo root): `tests/Integration/Analytics/AnalyticsQueryTest.php` (extend), `tests/Integration/Analytics/AnalyticsApiTest.php` (extend), `tests/Integration/Analytics/AnalyticsRoutesGatedTest.php` (extend — add the `/breakdown` 401 route-gate test).

**Frontend — `admin/`:**
- Modify `package.json` — add `@unovis/vue`, `@unovis/ts`.
- Create `src/pages/analytics/components/AnalyticsLineChart.vue`, `.../AnalyticsBarChart.vue` — unovis wrappers.
- Modify `src/queries/keys.ts` — add analytics cache keys.
- Create `src/queries/analytics.ts` — range helper + fetchers + Colada composables.
- Create `src/registry/analyticsModule.ts` — gated nav module.
- Modify `src/layouts/default.vue` — register the analytics module.
- Create `src/pages/analytics/index.vue` — the dashboard page.
- Modify `src/pages/index.vue` — quiet home KPI strip.
- Tests: `src/__tests__/analyticsQueries.spec.ts`, `analyticsEnabledGate.spec.ts`, `analyticsModule.spec.ts`, `analyticsCharts.spec.ts`, `analyticsPage.spec.ts`, `homeAnalyticsStrip.spec.ts`.

---

## Task 1: Backend — `active_users` as a `/series` metric variant

**Files:**
- Modify: `packages/lemma-analytics/src/Query/AnalyticsQuery.php`
- Test: `tests/Integration/Analytics/AnalyticsQueryTest.php`

**Interfaces:**
- Consumes: existing `Glueful\Database\Connection` (already injected).
- Produces: `AnalyticsQuery::series('active_users', $from, $to)` returns `list<array{day:string,count:int}>` where `count` is the daily distinct active-user count, zero-filled — same shape as any other metric.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Analytics/AnalyticsQueryTest.php` (the class already has `record()`, which records with `actorType: 'user'`, populating `analytics_active_actors`):

```php
    public function testActiveUsersSeriesIsDailyDistinctAndZeroFilled(): void
    {
        // u-1 and u-2 active on 2025-06-10; u-1 again on 2025-06-12. Day 06-11 has nobody.
        $this->record('collections.row.created', 1749556800.0, 'u-1'); // 2025-06-10
        $this->record('collections.row.created', 1749556800.0, 'u-2'); // 2025-06-10
        $this->record('collections.row.created', 1749729600.0, 'u-1'); // 2025-06-12

        $q = $this->container()->get(AnalyticsQuery::class);
        $series = $q->series('active_users', '2025-06-10', '2025-06-12');

        self::assertSame(
            [
                ['day' => '2025-06-10', 'count' => 2], // u-1, u-2 distinct
                ['day' => '2025-06-11', 'count' => 0], // zero-filled
                ['day' => '2025-06-12', 'count' => 1], // u-1
            ],
            $series,
        );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter testActiveUsersSeriesIsDailyDistinctAndZeroFilled`
Expected: FAIL — the current `series()` reads `analytics_daily` for event `active_users` (which has no rows), so every day is `0`; the assertion for `2` and `1` fails.

- [ ] **Step 3: Implement — branch `series()` on the metric, keep zero-fill DRY**

Replace the `series()` method in `packages/lemma-analytics/src/Query/AnalyticsQuery.php` with a version that dispatches to a per-day count map, then shares the zero-fill loop:

```php
    /**
     * Daily count series for one metric, zero-filled across [from, to].
     *
     * `active_users` is special: it is not an event in analytics_daily but a distinct-actor count
     * over analytics_active_actors, so callers get one uniform series shape for every chart.
     *
     * @return list<array{day: string, count: int}>
     */
    public function series(string $event, string $from, string $to, ?string $subject = null): array
    {
        $byDay = $event === 'active_users'
            ? $this->activeUsersByDay($from, $to)
            : $this->countsByDay($event, $subject, $from, $to);

        $out = [];
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $out[] = ['day' => $day, 'count' => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    /**
     * event/subject daily counts from analytics_daily.
     *
     * @return array<string, int> day (Y-m-d) => count
     */
    private function countsByDay(string $event, ?string $subject, string $from, string $to): array
    {
        $rows = $this->connection->table('analytics_daily')
            ->select(['day', 'count'])
            ->where('event', $event)
            ->where('subject', $subject ?? '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr((string) $row['day'], 0, 10)] = (int) $row['count'];
        }
        return $byDay;
    }

    /**
     * Daily distinct active users from analytics_active_actors (raw SQL: the builder's count() is
     * COUNT(*), and we need COUNT(DISTINCT actor_id_hash) per day).
     *
     * @return array<string, int> day (Y-m-d) => distinct count
     */
    private function activeUsersByDay(string $from, string $to): array
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT day, COUNT(DISTINCT actor_id_hash) AS count FROM analytics_active_actors'
            . " WHERE metric = 'active_users' AND day >= ? AND day <= ? GROUP BY day"
        );
        $stmt->execute([$from, $to]);

        $byDay = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byDay[substr((string) $row['day'], 0, 10)] = (int) $row['count'];
        }
        return $byDay;
    }
```

The `summary()` method below it is unchanged.

- [ ] **Step 4: Run the test to verify it passes**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter testActiveUsersSeriesIsDailyDistinctAndZeroFilled`
Expected: PASS.

- [ ] **Step 5: Run the existing query tests to confirm no regression**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter AnalyticsQueryTest`
Expected: PASS (all three tests — the two originals still pass because `countsByDay` preserves the old behavior).

- [ ] **Step 6: Commit (when authorized — see Commit Protocol above)**

```bash
git add packages/lemma-analytics/src/Query/AnalyticsQuery.php tests/Integration/Analytics/AnalyticsQueryTest.php
git commit -m "analytics: route active_users through analytics_active_actors as a /series metric"
```

---

## Task 2: Backend — `/breakdown` endpoint (top subjects for one event)

**Files:**
- Modify: `packages/lemma-analytics/src/Query/AnalyticsQuery.php`
- Modify: `packages/lemma-analytics/src/Http/Controllers/AnalyticsController.php`
- Modify: `packages/lemma-analytics/routes/admin-routes.php`
- Test: `tests/Integration/Analytics/AnalyticsQueryTest.php`, `tests/Integration/Analytics/AnalyticsApiTest.php`

**Interfaces:**
- Consumes: `AnalyticsQuery` (Task 1), the existing `AnalyticsController(AnalyticsQuery $query)` constructor, the existing route group in `admin-routes.php`.
- Produces:
  - `AnalyticsQuery::breakdown(string $event, string $from, string $to, int $limit = 10): array` → `list<array{subject:string,count:int}>`, ordered by count desc, `__total__` excluded, `limit` clamped to `[1,50]`.
  - `AnalyticsController::breakdown(Request $request): Response` → `Response::success(['event'=>…,'from'=>…,'to'=>…,'breakdown'=>[…]])`; 422 when `event`/`from`/`to` missing.
  - Route `GET /v1/admin/analytics/breakdown` gated `auth` + `lemma_permission:analytics.read`.

- [ ] **Step 1: Write the failing query test**

Add to `tests/Integration/Analytics/AnalyticsQueryTest.php`. `record()` always uses `subjectId: 'posts'`; add a helper that varies the subject so the breakdown has more than one bucket. Put this private helper in the class:

```php
    private function recordSubject(string $event, float $ts, string $actorId, string $subject): void
    {
        $this->container()->get(AnalyticsRecorder::class)->record(new AnalyticsFact(
            event: $event,
            category: 'collections',
            subjectType: 'collection',
            subjectId: $subject,
            actorType: 'user',
            actorId: $actorId,
            occurredAt: $ts,
        ));
    }
```

Then the test:

```php
    public function testBreakdownRanksSubjectsDescendingAndExcludesTotalSentinel(): void
    {
        // 'posts' gets 2 row-creates, 'authors' gets 1, on 2025-06-10.
        $this->recordSubject('collections.row.created', 1749556800.0, 'u-1', 'posts');
        $this->recordSubject('collections.row.created', 1749556800.0, 'u-2', 'posts');
        $this->recordSubject('collections.row.created', 1749556800.0, 'u-1', 'authors');

        $q = $this->container()->get(AnalyticsQuery::class);
        $breakdown = $q->breakdown('collections.row.created', '2025-06-10', '2025-06-10');

        self::assertSame(
            [
                ['subject' => 'posts', 'count' => 2],
                ['subject' => 'authors', 'count' => 1],
            ],
            $breakdown,
        );
    }

    public function testBreakdownClampsLimitToFiftyAndMinimumOne(): void
    {
        // Seed 60 distinct subjects so an unclamped limit would return >50.
        for ($i = 0; $i < 60; $i++) {
            $this->recordSubject('collections.row.created', 1749556800.0, "u-$i", "subj-$i");
        }
        $q = $this->container()->get(AnalyticsQuery::class);

        // limit above the ceiling is clamped to 50.
        $clamped = $q->breakdown('collections.row.created', '2025-06-10', '2025-06-10', 9999);
        self::assertCount(50, $clamped, 'limit must clamp to a max of 50');

        // limit below 1 is clamped up to 1 (never 0/negative rows).
        $floored = $q->breakdown('collections.row.created', '2025-06-10', '2025-06-10', 0);
        self::assertCount(1, $floored, 'limit must clamp to a min of 1 when data exists');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter testBreakdownRanksSubjectsDescendingAndExcludesTotalSentinel`
Expected: FAIL with "Call to undefined method …AnalyticsQuery::breakdown()".

- [ ] **Step 3: Implement `breakdown()` on `AnalyticsQuery`**

Add this method to `AnalyticsQuery` (after `summary()`). Raw PDO mirrors the existing distinct-count query; `$limit` is clamped to an int and interpolated safely:

```php
    /**
     * Top subjects for one event over [from, to], ordered by total count desc. The '__total__'
     * sentinel row is excluded so only real subjects (collection names, content-type slugs) rank.
     *
     * @return list<array{subject: string, count: int}>
     */
    public function breakdown(string $event, string $from, string $to, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT subject, SUM(count) AS count FROM analytics_daily'
            . " WHERE event = ? AND subject <> '__total__' AND day >= ? AND day <= ?"
            . ' GROUP BY subject ORDER BY count DESC, subject ASC LIMIT ' . $limit
        );
        $stmt->execute([$event, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['subject' => (string) $row['subject'], 'count' => (int) $row['count']];
        }
        return $out;
    }
```

- [ ] **Step 4: Run the query tests to verify they pass**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter AnalyticsQueryTest`
Expected: PASS (all breakdown + earlier tests).

- [ ] **Step 5: Write the failing controller/API test**

Add to `tests/Integration/Analytics/AnalyticsApiTest.php`:

```php
    public function testBreakdownEndpointReturnsRankedSubjects(): void
    {
        $rec = $this->container()->get(AnalyticsRecorder::class);
        foreach ([['posts', 'u-1'], ['posts', 'u-2'], ['authors', 'u-1']] as [$subject, $actor]) {
            $rec->record(new AnalyticsFact(
                event: 'collections.row.created',
                category: 'collections',
                subjectType: 'collection',
                subjectId: $subject,
                actorType: 'user',
                actorId: $actor,
                occurredAt: 1749556800.0, // 2025-06-10
            ));
        }

        $controller = $this->container()->get(AnalyticsController::class);
        $req = Request::create('/v1/admin/analytics/breakdown', 'GET', [
            'event' => 'collections.row.created', 'from' => '2025-06-10', 'to' => '2025-06-10',
        ]);
        $res = $controller->breakdown($req);

        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertSame('collections.row.created', $body['data']['event']);
        self::assertSame(
            [['subject' => 'posts', 'count' => 2], ['subject' => 'authors', 'count' => 1]],
            $body['data']['breakdown'],
        );
    }

    public function testBreakdownEndpointRequiresEventFromTo(): void
    {
        $controller = $this->container()->get(AnalyticsController::class);
        $req = Request::create('/v1/admin/analytics/breakdown', 'GET', ['from' => '2025-06-10']);
        $res = $controller->breakdown($req);
        self::assertSame(422, $res->getStatusCode());
    }
```

- [ ] **Step 6: Run to verify it fails**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter testBreakdownEndpointReturnsRankedSubjects`
Expected: FAIL — "Call to undefined method …AnalyticsController::breakdown()".

- [ ] **Step 7: Implement the controller action**

Add to `packages/lemma-analytics/src/Http/Controllers/AnalyticsController.php` (after `summary()`):

```php
    #[ApiOperation(summary: 'Analytics breakdown: top subjects for one event', tags: ['Analytics'])]
    public function breakdown(Request $request): Response
    {
        $event = (string) $request->query->get('event', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($event === '' || $from === '' || $to === '') {
            return Response::error('event, from and to are required.', 422);
        }
        $limit = (int) $request->query->get('limit', 10);

        return Response::success([
            'event' => $event,
            'from' => $from,
            'to' => $to,
            'breakdown' => $this->query->breakdown($event, $from, $to, $limit),
        ]);
    }
```

- [ ] **Step 8: Register the route**

In `packages/lemma-analytics/routes/admin-routes.php`, add inside the existing group callback, after the `summary` route:

```php
        $router->get('/analytics/breakdown', [AnalyticsController::class, 'breakdown'])
            ->middleware('lemma_permission:analytics.read');
```

- [ ] **Step 9: Run the API tests to verify they pass**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter AnalyticsApiTest`
Expected: PASS.

- [ ] **Step 10: Add the route-registration + auth-gate test (through the real router)**

The controller tests above call `breakdown()` directly, so they do NOT prove the route is registered or that the `auth` + `lemma_permission:analytics.read` middleware are attached. Add a dispatch test to the existing `tests/Integration/Analytics/AnalyticsRoutesGatedTest.php` (which already tests `/summary` this exact way — `$this->handle(...)` runs the full router/middleware pipeline; anonymous requests must be rejected with 401, proving the route exists AND is gated):

```php
    public function testBreakdownRouteIsRegisteredAndRequiresAuth(): void
    {
        $response = $this->handle(Request::create('/v1/admin/analytics/breakdown', 'GET', [
            'event' => 'collections.row.created', 'from' => '2025-06-10', 'to' => '2025-06-10',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]));

        self::assertSame(
            401,
            $response->getStatusCode(),
            'Enabled-boot GET /v1/admin/analytics/breakdown must be 401 (route exists, auth rejects '
            . 'anonymous), got: ' . $response->getStatusCode() . ' body: ' . $response->getContent()
        );
    }
```

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit --filter AnalyticsRoutesGatedTest`
Expected: PASS (both the existing `/summary` test and the new `/breakdown` test — 401 unauthenticated). A missing route would 404 and a missing gate would 200/422; either fails this assertion.

- [ ] **Step 11: Run the full analytics suite + phpcs**

Run: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Analytics`
Expected: PASS (all analytics tests green).
Run: `vendor/bin/phpcs packages/lemma-analytics/src`
Expected: no errors.

- [ ] **Step 12: Commit (when authorized — see Commit Protocol above)**

```bash
git add packages/lemma-analytics/src/Query/AnalyticsQuery.php \
        packages/lemma-analytics/src/Http/Controllers/AnalyticsController.php \
        packages/lemma-analytics/routes/admin-routes.php \
        tests/Integration/Analytics/AnalyticsQueryTest.php \
        tests/Integration/Analytics/AnalyticsApiTest.php \
        tests/Integration/Analytics/AnalyticsRoutesGatedTest.php
git commit -m "analytics: add /breakdown read endpoint (top subjects per event)"
```

---

## Task 3: Frontend — unovis dependency + chart wrapper components

**Files:**
- Modify: `admin/package.json`
- Create: `admin/src/pages/analytics/components/AnalyticsLineChart.vue`
- Create: `admin/src/pages/analytics/components/AnalyticsBarChart.vue`
- Test: `admin/src/__tests__/analyticsCharts.spec.ts`

**Interfaces:**
- Produces:
  - `AnalyticsLineChart` — props `{ series: LineSeries[]; height?: number }` where
    `interface LineSeries { key: string; label: string; color: string; points: { day: string; count: number }[] }`.
    Renders a root `<div data-test="analytics-line-chart">`.
  - `AnalyticsBarChart` — props `{ items: { subject: string; count: number }[]; color?: string; height?: number }`.
    Renders a root `<div data-test="analytics-bar-chart">`; empty state `<div data-test="analytics-bar-empty">` when `items` is empty.

- [ ] **Step 1: Add the dependencies**

Run from `admin/`:

```bash
pnpm add @unovis/vue @unovis/ts
```

Expected: `package.json` gains both under `dependencies`; `pnpm-lock.yaml` updates.

- [ ] **Step 2: Create the line chart wrapper**

Create `admin/src/pages/analytics/components/AnalyticsLineChart.vue`:

```vue
<script lang="ts">
// A plain <script> block (NOT <script setup>, which forbids `export`) so the page can import this
// type: `import AnalyticsLineChart, { type LineSeries } from '.../AnalyticsLineChart.vue'`.
export interface LineSeries {
  key: string
  label: string
  color: string
  points: { day: string; count: number }[]
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { VisXYContainer, VisLine, VisAxis, VisTooltip } from '@unovis/vue'

const props = defineProps<{ series: LineSeries[]; height?: number }>()

// Merge every series into one record per day: { day, <seriesKey>: count, ... }. unovis plots each
// VisLine against a shared x index; y reads that series' key off the row.
interface Row {
  day: string
  [key: string]: number | string
}

const rows = computed<Row[]>(() => {
  const byDay = new Map<string, Row>()
  for (const s of props.series) {
    for (const p of s.points) {
      const row = byDay.get(p.day) ?? { day: p.day }
      row[s.key] = p.count
      byDay.set(p.day, row)
    }
  }
  return [...byDay.values()].sort((a, b) => String(a.day).localeCompare(String(b.day)))
})

const x = (_row: Row, i: number) => i
const xTickFormat = (i: number) => rows.value[i]?.day?.slice(5) ?? '' // MM-DD
</script>

<template>
  <div data-test="analytics-line-chart" :style="{ height: `${height ?? 240}px` }">
    <VisXYContainer :data="rows" :height="height ?? 240">
      <VisLine
        v-for="s in series"
        :key="s.key"
        :x="x"
        :y="(row: Row) => Number(row[s.key] ?? 0)"
        :color="s.color"
      />
      <VisAxis type="x" :tick-format="xTickFormat" :grid-line="false" />
      <VisAxis type="y" :grid-line="true" />
      <VisTooltip />
    </VisXYContainer>
  </div>
</template>
```

> **unovis prop check:** `@unovis/vue` accepts kebab-case props in templates (`:tick-format`, `:grid-line`). If `pnpm type-check` flags a prop name, verify against the installed `@unovis/vue` version's typings and adjust — the wrapper's public API (the `data-test` root + the `LineSeries` prop) must not change.

- [ ] **Step 3: Create the bar chart wrapper**

Create `admin/src/pages/analytics/components/AnalyticsBarChart.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { VisXYContainer, VisStackedBar, VisAxis } from '@unovis/vue'

interface BarItem {
  subject: string
  count: number
}
const props = defineProps<{ items: BarItem[]; color?: string; height?: number }>()

const isEmpty = computed(() => props.items.length === 0)
const x = (_d: BarItem, i: number) => i
const y = (d: BarItem) => d.count
const xTickFormat = (i: number) => props.items[i]?.subject ?? ''
</script>

<template>
  <div v-if="isEmpty" data-test="analytics-bar-empty" class="p-6 text-center text-sm text-muted">
    No activity in this range yet
  </div>
  <div v-else data-test="analytics-bar-chart" :style="{ height: `${height ?? 240}px` }">
    <VisXYContainer :data="items" :height="height ?? 240">
      <VisStackedBar :x="x" :y="y" :color="color ?? 'var(--ui-primary)'" />
      <VisAxis type="x" :tick-format="xTickFormat" :grid-line="false" />
      <VisAxis type="y" :grid-line="true" />
    </VisXYContainer>
  </div>
</template>
```

- [ ] **Step 4: Write the smoke test**

Create `admin/src/__tests__/analyticsCharts.spec.ts`. unovis children are stubbed so the test asserts only the wrapper's own contract (root element + empty state), not third-party SVG rendering:

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AnalyticsLineChart from '@/pages/analytics/components/AnalyticsLineChart.vue'
import AnalyticsBarChart from '@/pages/analytics/components/AnalyticsBarChart.vue'

// Stub unovis primitives — jsdom can't lay out real SVG charts, and they're not what we're testing.
const stubs = {
  VisXYContainer: { template: '<div><slot /></div>' },
  VisLine: true,
  VisStackedBar: true,
  VisAxis: true,
  VisTooltip: true,
}

describe('analytics chart wrappers', () => {
  it('line chart renders its container given series', () => {
    const wrapper = mount(AnalyticsLineChart, {
      props: {
        series: [{ key: 'logins', label: 'Logins', color: '#000', points: [{ day: '2025-06-10', count: 3 }] }],
      },
      global: { stubs },
    })
    expect(wrapper.find('[data-test="analytics-line-chart"]').exists()).toBe(true)
  })

  it('bar chart renders bars when items exist', () => {
    const wrapper = mount(AnalyticsBarChart, {
      props: { items: [{ subject: 'posts', count: 5 }] },
      global: { stubs },
    })
    expect(wrapper.find('[data-test="analytics-bar-chart"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="analytics-bar-empty"]').exists()).toBe(false)
  })

  it('bar chart shows the empty state when there are no items', () => {
    const wrapper = mount(AnalyticsBarChart, { props: { items: [] }, global: { stubs } })
    expect(wrapper.find('[data-test="analytics-bar-empty"]').exists()).toBe(true)
  })
})
```

- [ ] **Step 5: Run the smoke test**

Run (from `admin/`): `pnpm test src/__tests__/analyticsCharts.spec.ts`
Expected: PASS (3 tests).

- [ ] **Step 6: Typecheck**

Run (from `admin/`): `pnpm type-check`
Expected: no errors (adjust unovis prop names per the note in Step 2 if flagged).

- [ ] **Step 7: Commit (when authorized — see Commit Protocol above)**

```bash
git add admin/package.json admin/pnpm-lock.yaml \
        admin/src/pages/analytics/components/AnalyticsLineChart.vue \
        admin/src/pages/analytics/components/AnalyticsBarChart.vue \
        admin/src/__tests__/analyticsCharts.spec.ts
git commit -m "analytics(admin): add @unovis chart wrappers (line + bar)"
```

---

## Task 4: Frontend — analytics query layer

**Files:**
- Modify: `admin/src/queries/keys.ts`
- Create: `admin/src/queries/analytics.ts`
- Test: `admin/src/__tests__/analyticsQueries.spec.ts`

**Interfaces:**
- Consumes: `authFetch` (`@/api/authFetch`), `runtimeConfig.apiBase` (`@/runtime/config`), `qk` (`@/queries/keys`).
- Produces:
  - Types `RangePreset = 7 | 30 | 90`, `SeriesPoint`, `SummaryResponse`, `BreakdownItem`, `DateRange = { from: string; to: string }`.
  - `rangeFor(days: RangePreset, today?: Date): DateRange` — `to` = today, `from` = `to − (days−1)`, both `YYYY-MM-DD` (UTC).
  - `fetchSeries(metric, from, to): Promise<SeriesPoint[]>`, `fetchSummary(from, to): Promise<SummaryResponse>`, `fetchBreakdown(event, from, to, limit?): Promise<BreakdownItem[]>`.
  - Composables `useAnalyticsSummary(range, enabled?)`, `useAnalyticsSeries(metric, range)`, `useAnalyticsBreakdown(event, range)` — all accept `MaybeRefOrGetter` so preset changes refetch. `useAnalyticsSummary` takes an optional `enabled: MaybeRefOrGetter<boolean>` (default true); when it resolves false the query never fires (used by the Home strip to stay silent when `lemma.analytics` is off).

- [ ] **Step 1: Add cache keys**

In `admin/src/queries/keys.ts`, add to the `qk` object (before the closing brace):

```ts
  analyticsSummary: (from: string, to: string) => ['analytics', 'summary', from, to] as const,
  analyticsSeries: (metric: string, from: string, to: string) =>
    ['analytics', 'series', metric, from, to] as const,
  analyticsBreakdown: (event: string, from: string, to: string) =>
    ['analytics', 'breakdown', event, from, to] as const,
```

- [ ] **Step 2: Write the failing test**

Create `admin/src/__tests__/analyticsQueries.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { rangeFor, fetchSeries, fetchSummary, fetchBreakdown } from '@/queries/analytics'

describe('analytics query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('rangeFor computes an inclusive N-day window ending today', () => {
    const r = rangeFor(7, new Date('2025-06-30T12:00:00Z'))
    expect(r).toEqual({ from: '2025-06-24', to: '2025-06-30' }) // 7 days inclusive
  })

  it('fetchSeries hits /analytics/series with metric+from+to and unwraps data.series', async () => {
    authFetch.mockResolvedValue({ data: { series: [{ day: '2025-06-30', count: 4 }] } })
    const series = await fetchSeries('auth.login', '2025-06-24', '2025-06-30')
    expect(series).toEqual([{ day: '2025-06-30', count: 4 }])
    const url = authFetch.mock.calls[0][0] as string
    expect(url).toContain('/v1/admin/analytics/series?')
    expect(url).toContain('metric=auth.login')
    expect(url).toContain('from=2025-06-24')
    expect(url).toContain('to=2025-06-30')
  })

  it('fetchSummary hits /analytics/summary and returns the summary payload', async () => {
    authFetch.mockResolvedValue({ data: { from: 'a', to: 'b', totals: { 'auth.login': 9 }, active_users: 3 } })
    const s = await fetchSummary('a', 'b')
    expect(s.active_users).toBe(3)
    expect(s.totals['auth.login']).toBe(9)
    expect(authFetch.mock.calls[0][0]).toContain('/v1/admin/analytics/summary?')
  })

  it('fetchBreakdown hits /analytics/breakdown with event+limit and unwraps data.breakdown', async () => {
    authFetch.mockResolvedValue({ data: { breakdown: [{ subject: 'posts', count: 5 }] } })
    const b = await fetchBreakdown('collections.row.created', 'a', 'b', 10)
    expect(b).toEqual([{ subject: 'posts', count: 5 }])
    const url = authFetch.mock.calls[0][0] as string
    expect(url).toContain('/v1/admin/analytics/breakdown?')
    expect(url).toContain('event=collections.row.created')
    expect(url).toContain('limit=10')
  })
})
```

- [ ] **Step 3: Run to verify it fails**

Run (from `admin/`): `pnpm test src/__tests__/analyticsQueries.spec.ts`
Expected: FAIL — cannot resolve `@/queries/analytics`.

- [ ] **Step 4: Implement the query module**

Create `admin/src/queries/analytics.ts`:

```ts
import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

export type RangePreset = 7 | 30 | 90
export interface DateRange {
  from: string
  to: string
}

export interface SeriesPoint {
  day: string
  count: number
}
export interface SummaryResponse {
  from: string
  to: string
  totals: Record<string, number>
  active_users: number
}
export interface BreakdownItem {
  subject: string
  count: number
}

const base = () => `${runtimeConfig.apiBase}/analytics`

/** Inclusive N-day window ending today: from = to − (days − 1). Both YYYY-MM-DD (UTC). */
export function rangeFor(days: RangePreset, today: Date = new Date()): DateRange {
  const to = today
  const from = new Date(to)
  from.setUTCDate(from.getUTCDate() - (days - 1))
  const fmt = (d: Date) => d.toISOString().slice(0, 10)
  return { from: fmt(from), to: fmt(to) }
}

export async function fetchSeries(metric: string, from: string, to: string): Promise<SeriesPoint[]> {
  const qs = new URLSearchParams({ metric, from, to })
  const json = await authFetch(`${base()}/series?${qs.toString()}`)
  const data = (json.data ?? json) as { series?: SeriesPoint[] }
  return Array.isArray(data.series) ? data.series : []
}

export async function fetchSummary(from: string, to: string): Promise<SummaryResponse> {
  const qs = new URLSearchParams({ from, to })
  const json = await authFetch(`${base()}/summary?${qs.toString()}`)
  return (json.data ?? json) as SummaryResponse
}

export async function fetchBreakdown(
  event: string,
  from: string,
  to: string,
  limit = 10,
): Promise<BreakdownItem[]> {
  const qs = new URLSearchParams({ event, from, to, limit: String(limit) })
  const json = await authFetch(`${base()}/breakdown?${qs.toString()}`)
  const data = (json.data ?? json) as { breakdown?: BreakdownItem[] }
  return Array.isArray(data.breakdown) ? data.breakdown : []
}

export function useAnalyticsSummary(
  range: MaybeRefOrGetter<DateRange>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.analyticsSummary(toValue(range).from, toValue(range).to),
    query: () => {
      const r = toValue(range)
      return fetchSummary(r.from, r.to)
    },
    // When `enabled` resolves false the query never runs — the Home strip passes the
    // `lemma.analytics` capability flag so a disabled pack never hits the (404'd) backend route.
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useAnalyticsSeries(metric: string, range: MaybeRefOrGetter<DateRange>) {
  return useQuery({
    key: () => qk.analyticsSeries(metric, toValue(range).from, toValue(range).to),
    query: () => {
      const r = toValue(range)
      return fetchSeries(metric, r.from, r.to)
    },
  })
}

export function useAnalyticsBreakdown(
  event: MaybeRefOrGetter<string>,
  range: MaybeRefOrGetter<DateRange>,
) {
  return useQuery({
    key: () => qk.analyticsBreakdown(toValue(event), toValue(range).from, toValue(range).to),
    query: () => {
      const r = toValue(range)
      return fetchBreakdown(toValue(event), r.from, r.to)
    },
  })
}
```

- [ ] **Step 5: Run to verify it passes**

Run (from `admin/`): `pnpm test src/__tests__/analyticsQueries.spec.ts`
Expected: PASS (4 tests).

- [ ] **Step 6: Write the failing `enabled`-gate test (proves a disabled pack never fetches)**

The pure-function tests above don't exercise the `useAnalyticsSummary` composable. Add a second spec that mounts the real composable through Pinia Colada and asserts the `enabled` gate suppresses the network call. Create `admin/src/__tests__/analyticsEnabledGate.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useAnalyticsSummary } from '@/queries/analytics'

function mountWith(enabled: boolean) {
  const Comp = defineComponent({
    setup() {
      useAnalyticsSummary(() => ({ from: '2025-06-01', to: '2025-06-30' }), () => enabled)
      return () => h('div')
    },
  })
  // Pinia must be installed before PiniaColada.
  return mount(Comp, { global: { plugins: [createPinia(), PiniaColada] } })
}

describe('useAnalyticsSummary enabled gate', () => {
  beforeEach(() => {
    authFetch.mockReset().mockResolvedValue({ data: { from: 'a', to: 'b', totals: {}, active_users: 0 } })
  })

  it('does NOT hit the backend when disabled', async () => {
    mountWith(false)
    await flushPromises()
    expect(authFetch).not.toHaveBeenCalled()
  })

  it('hits /analytics/summary when enabled', async () => {
    mountWith(true)
    await flushPromises()
    expect(authFetch).toHaveBeenCalledTimes(1)
    expect(authFetch.mock.calls[0][0]).toContain('/analytics/summary')
  })
})
```

Run (from `admin/`): `pnpm test src/__tests__/analyticsEnabledGate.spec.ts`
Expected: PASS (2 tests). If the "disabled" case fails (authFetch WAS called), the `enabled` option in `useAnalyticsSummary` is missing or wrong.

- [ ] **Step 7: Typecheck**

Run (from `admin/`): `pnpm type-check`
Expected: no errors.

- [ ] **Step 8: Commit (when authorized — see Commit Protocol above)**

```bash
git add admin/src/queries/keys.ts admin/src/queries/analytics.ts \
        admin/src/__tests__/analyticsQueries.spec.ts \
        admin/src/__tests__/analyticsEnabledGate.spec.ts
git commit -m "analytics(admin): add range helper + read-API query layer (enabled-gated summary)"
```

---

## Task 5: Frontend — gated nav module

**Files:**
- Create: `admin/src/registry/analyticsModule.ts`
- Modify: `admin/src/layouts/default.vue`
- Test: `admin/src/__tests__/analyticsModule.spec.ts`

**Interfaces:**
- Consumes: `registerAdminModule` (`@/registry/adminModules`).
- Produces: `registerAnalyticsModule(): void` — registers module id `'analytics'`, `requires: ['lemma.analytics']`, one main nav item `{ label: 'Analytics', icon: 'i-lucide-chart-line', to: '/analytics' }`.

- [ ] **Step 1: Write the failing gating test**

Create `admin/src/__tests__/analyticsModule.spec.ts` (mirrors `collectionsGating.spec.ts`):

```ts
import { describe, it, expect, beforeEach } from 'vitest'
import { visibleNav, resetAdminModules } from '@/registry/adminModules'
import { registerAnalyticsModule } from '@/registry/analyticsModule'

describe('analytics admin module gating (lemma.analytics capability)', () => {
  beforeEach(() => resetAdminModules())

  it('omits the Analytics nav when lemma.analytics is disabled', () => {
    registerAnalyticsModule()
    const [main] = visibleNav(() => false)
    expect(main).toEqual([])
  })

  it('includes the Analytics nav linking to /analytics when enabled', () => {
    registerAnalyticsModule()
    const [main] = visibleNav((id) => id === 'lemma.analytics')
    expect(main.map((i) => i.label)).toEqual(['Analytics'])
    expect(main[0].to).toBe('/analytics')
  })
})
```

- [ ] **Step 2: Run to verify it fails**

Run (from `admin/`): `pnpm test src/__tests__/analyticsModule.spec.ts`
Expected: FAIL — cannot resolve `@/registry/analyticsModule`.

- [ ] **Step 3: Implement the module**

Create `admin/src/registry/analyticsModule.ts` (mirrors `collectionsModule.ts`):

```ts
import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Analytics admin nav — gated on the `lemma.analytics` capability. The whole "Analytics" entry
// disappears from the sidebar when the pack is disabled or removed (the backend 404s those routes
// too — see the pack's RemovabilityTest).
const main: NavigationMenuItem[] = [
  {
    label: 'Analytics',
    icon: 'i-lucide-chart-line',
    to: '/analytics',
  },
]

export function registerAnalyticsModule(): void {
  registerAdminModule({ id: 'analytics', requires: ['lemma.analytics'], nav: { main } })
}
```

- [ ] **Step 4: Register it in the layout**

In `admin/src/layouts/default.vue`, add the import next to the other module imports and the call next to the others (after `registerCollectionsModule()`):

```ts
import { registerAnalyticsModule } from '@/registry/analyticsModule'
```
```ts
registerAnalyticsModule()
```

- [ ] **Step 5: Run to verify it passes**

Run (from `admin/`): `pnpm test src/__tests__/analyticsModule.spec.ts`
Expected: PASS (2 tests).

- [ ] **Step 6: Typecheck**

Run (from `admin/`): `pnpm type-check`
Expected: no errors.

- [ ] **Step 7: Commit (when authorized — see Commit Protocol above)**

```bash
git add admin/src/registry/analyticsModule.ts admin/src/layouts/default.vue \
        admin/src/__tests__/analyticsModule.spec.ts
git commit -m "analytics(admin): add gated Analytics nav module"
```

---

## Task 6: Frontend — the `/analytics` page

**Files:**
- Create: `admin/src/pages/analytics/index.vue`
- Test: `admin/src/__tests__/analyticsPage.spec.ts`

**Interfaces:**
- Consumes: `useAnalyticsSummary`, `useAnalyticsSeries`, `useAnalyticsBreakdown`, `rangeFor`, types from `@/queries/analytics` (Task 4); `AnalyticsLineChart`, `AnalyticsBarChart` (Task 3).
- Produces: a route `/analytics` gated `requiresAuth` + `requiresCapability: lemma.analytics`. No exported symbols consumed by later tasks.

**Notes:**
- Use a `<route lang="yaml">` block for meta (matches every collections page; avoids the known `definePage` vue-tsc quirk).
- KPI event keys: `auth.login`, `content.entry.created`, `collections.row.created`; active users from `summary.active_users`.
- Breakdown segmented control switches one `event` ref between `collections.row.created` (Collections) and `content.entry.created` (Content types). Only the selected segment's query runs (the `event` is a ref driving `useAnalyticsBreakdown`).

- [ ] **Step 1: Write the failing page test**

Create `admin/src/__tests__/analyticsPage.spec.ts`. Query composables and the chart wrappers are stubbed so the test asserts the page's own wiring (KPI values, one chart per block, segment toggle):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'

// vi.mock factories are hoisted above imports, so a captured handle must come through vi.hoisted.
// We stash the event ref the page passes to useAnalyticsBreakdown, then read it AFTER the click
// (the page passes a computed<string>; toValue reads its current value post-reactivity-flush).
const h = vi.hoisted(() => ({ breakdownEventRef: null as unknown }))

vi.mock('@/queries/analytics', () => ({
  rangeFor: () => ({ from: '2025-06-01', to: '2025-06-30' }),
  useAnalyticsSummary: () => ({
    data: ref({
      from: '2025-06-01',
      to: '2025-06-30',
      totals: { 'auth.login': 1200, 'content.entry.created': 340, 'collections.row.created': 890 },
      active_users: 128,
    }),
    status: ref('success'),
    error: ref(null),
  }),
  useAnalyticsSeries: () => ({
    data: ref([{ day: '2025-06-01', count: 5 }]),
    status: ref('success'),
    error: ref(null),
  }),
  useAnalyticsBreakdown: (event: unknown) => {
    h.breakdownEventRef = event
    return { data: ref([{ subject: 'posts', count: 12 }]), status: ref('success'), error: ref(null) }
  },
}))

vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import AnalyticsPage from '@/pages/analytics/index.vue'

const stubs = {
  AnalyticsLineChart: { props: ['series'], template: '<div data-test="line-chart" />' },
  AnalyticsBarChart: { props: ['items'], template: '<div data-test="bar-chart" />' },
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}

describe('analytics page', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('renders the four KPI values from the summary', () => {
    const wrapper = mount(AnalyticsPage, { global: { stubs } })
    const text = wrapper.text()
    expect(text).toContain('128') // active users
    expect(text).toContain('1200') // logins (or a formatted variant — see impl note)
    expect(text).toContain('340') // entries created
    expect(text).toContain('890') // rows created
  })

  it('renders a line chart per trend block and a breakdown bar chart', () => {
    const wrapper = mount(AnalyticsPage, { global: { stubs } })
    // Activity trend + active users/day + auth health = 3 line charts.
    expect(wrapper.findAll('[data-test="line-chart"]').length).toBe(3)
    expect(wrapper.find('[data-test="bar-chart"]').exists()).toBe(true)
  })

  it('defaults the breakdown to Collections and switches to Content types', async () => {
    const wrapper = mount(AnalyticsPage, { global: { stubs } })
    expect(toValue(h.breakdownEventRef)).toBe('collections.row.created')
    await wrapper.find('[data-test="seg-types"]').trigger('click')
    expect(toValue(h.breakdownEventRef)).toBe('content.entry.created')
  })
})
```

- [ ] **Step 2: Run to verify it fails**

Run (from `admin/`): `pnpm test src/__tests__/analyticsPage.spec.ts`
Expected: FAIL — cannot resolve `@/pages/analytics/index.vue`.

- [ ] **Step 3: Implement the page**

Create `admin/src/pages/analytics/index.vue`:

```vue
<script setup lang="ts">
import { computed, ref, type Ref } from 'vue'
import {
  rangeFor,
  useAnalyticsSummary,
  useAnalyticsSeries,
  useAnalyticsBreakdown,
  type RangePreset,
} from '@/queries/analytics'
import AnalyticsLineChart, {
  type LineSeries,
} from '@/pages/analytics/components/AnalyticsLineChart.vue'
import AnalyticsBarChart from '@/pages/analytics/components/AnalyticsBarChart.vue'

const preset = ref<RangePreset>(30)
const range = computed(() => rangeFor(preset.value))
const PRESETS: RangePreset[] = [7, 30, 90]

const { data: summary } = useAnalyticsSummary(range)
const kpi = (event: string) => summary.value?.totals?.[event] ?? 0
const activeUsers = computed(() => summary.value?.active_users ?? 0)

const logins = useAnalyticsSeries('auth.login', range)
const loginsFailed = useAnalyticsSeries('auth.login_failed', range)
const entries = useAnalyticsSeries('content.entry.created', range)
const rows = useAnalyticsSeries('collections.row.created', range)
const activeUsersSeries = useAnalyticsSeries('active_users', range)

const pts = (q: { data: Ref<{ day: string; count: number }[] | undefined> }) => q.data.value ?? []

const activityTrend = computed<LineSeries[]>(() => [
  { key: 'logins', label: 'Logins', color: 'var(--ui-primary)', points: pts(logins) },
  { key: 'entries', label: 'Entries', color: 'var(--ui-success)', points: pts(entries) },
  { key: 'rows', label: 'Rows', color: 'var(--ui-warning)', points: pts(rows) },
])
const activeUsersTrend = computed<LineSeries[]>(() => [
  { key: 'active', label: 'Active users', color: 'var(--ui-primary)', points: pts(activeUsersSeries) },
])
const authHealth = computed<LineSeries[]>(() => [
  { key: 'ok', label: 'Login', color: 'var(--ui-success)', points: pts(logins) },
  { key: 'failed', label: 'Failed', color: 'var(--ui-error)', points: pts(loginsFailed) },
])

// Breakdown: one event at a time via the segmented control.
type BreakdownSegment = 'collections' | 'types'
const segment = ref<BreakdownSegment>('collections')
const breakdownEvent = computed(() =>
  segment.value === 'collections' ? 'collections.row.created' : 'content.entry.created',
)
const { data: breakdown } = useAnalyticsBreakdown(breakdownEvent, range)
const breakdownItems = computed(() => breakdown.value ?? [])

function fmt(n: number): string {
  return new Intl.NumberFormat().format(n)
}

// Void-returning click handlers. An inline `@click="preset = p"` compiles to a handler that RETURNS
// the assigned value, which trips Nuxt UI's `onClick: (e) => void` type (TS2322). These wrappers
// keep the handler `void`.
function setPreset(p: RangePreset): void {
  preset.value = p
}
function setSegment(seg: BreakdownSegment): void {
  segment.value = seg
}
</script>

<template>
  <UDashboardPanel id="analytics">
    <template #body>
      <div class="flex flex-col gap-4 p-4">
        <!-- Header: title + range presets -->
        <div class="flex items-center justify-between">
          <h1 class="text-lg font-semibold text-highlighted">Analytics</h1>
          <div class="flex gap-1" role="group" aria-label="Time range">
            <UButton
              v-for="p in PRESETS"
              :key="p"
              :data-test="`range-${p}`"
              size="xs"
              :variant="preset === p ? 'solid' : 'ghost'"
              :color="preset === p ? 'primary' : 'neutral'"
              @click="setPreset(p)"
            >
              {{ p }}d
            </UButton>
          </div>
        </div>

        <!-- KPI cards -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div data-test="kpi-active" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Active users</div>
            <div class="text-2xl font-semibold text-highlighted">{{ fmt(activeUsers) }}</div>
          </div>
          <div data-test="kpi-logins" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Logins</div>
            <div class="text-2xl font-semibold text-highlighted">{{ fmt(kpi('auth.login')) }}</div>
          </div>
          <div data-test="kpi-entries" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Entries created</div>
            <div class="text-2xl font-semibold text-highlighted">
              {{ fmt(kpi('content.entry.created')) }}
            </div>
          </div>
          <div data-test="kpi-rows" class="rounded-lg border border-default p-4">
            <div class="text-xs text-muted">Rows created</div>
            <div class="text-2xl font-semibold text-highlighted">
              {{ fmt(kpi('collections.row.created')) }}
            </div>
          </div>
        </div>

        <!-- Activity trend -->
        <section class="rounded-lg border border-default p-4">
          <h2 class="mb-2 text-sm font-medium text-highlighted">Activity trend</h2>
          <AnalyticsLineChart :series="activityTrend" />
        </section>

        <!-- Active users / day + Auth health -->
        <div class="grid gap-4 lg:grid-cols-2">
          <section class="rounded-lg border border-default p-4">
            <h2 class="mb-2 text-sm font-medium text-highlighted">Active users / day</h2>
            <AnalyticsLineChart :series="activeUsersTrend" />
          </section>
          <section class="rounded-lg border border-default p-4">
            <h2 class="mb-2 text-sm font-medium text-highlighted">Auth health</h2>
            <AnalyticsLineChart :series="authHealth" />
          </section>
        </div>

        <!-- Breakdown -->
        <section class="rounded-lg border border-default p-4">
          <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-medium text-highlighted">Most active</h2>
            <div class="flex gap-1" role="group" aria-label="Breakdown dimension">
              <UButton
                data-test="seg-collections"
                size="xs"
                :variant="segment === 'collections' ? 'solid' : 'ghost'"
                :color="segment === 'collections' ? 'primary' : 'neutral'"
                @click="setSegment('collections')"
              >
                Collections
              </UButton>
              <UButton
                data-test="seg-types"
                size="xs"
                :variant="segment === 'types' ? 'solid' : 'ghost'"
                :color="segment === 'types' ? 'primary' : 'neutral'"
                @click="setSegment('types')"
              >
                Content types
              </UButton>
            </div>
          </div>
          <AnalyticsBarChart :items="breakdownItems" />
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: lemma.analytics
</route>
```

> **Test note:** the KPI test asserts the raw number substrings (`1200`, `340`, `890`, `128`). `Intl.NumberFormat` in the jsdom/node test env with no locale renders these without grouping separators for 3-digit values, and `1200` → `1,200`. If the `1200` assertion fails because of the comma, the implementer should assert `1,200` (match the actual `fmt` output) rather than change `fmt`. Verify the exact rendered string and align the test to the implementation.

- [ ] **Step 4: Run to verify it passes**

Run (from `admin/`): `pnpm test src/__tests__/analyticsPage.spec.ts`
Expected: PASS (3 tests). If the logins KPI assertion trips on number formatting, align the expected string to `fmt`'s real output (see the test note) and re-run.

- [ ] **Step 5: Typecheck**

Run (from `admin/`): `pnpm type-check`
Expected: no errors.

- [ ] **Step 6: Commit (when authorized — see Commit Protocol above)**

```bash
git add admin/src/pages/analytics/index.vue admin/src/__tests__/analyticsPage.spec.ts
git commit -m "analytics(admin): add /analytics dashboard page"
```

---

## Task 7: Frontend — quiet home KPI strip

**Files:**
- Modify: `admin/src/pages/index.vue`
- Test: `admin/src/__tests__/homeAnalyticsStrip.spec.ts`

**Interfaces:**
- Consumes: `useAnalyticsSummary`, `rangeFor` (Task 4); `useCapabilitiesStore` (`@/stores/capabilities`).
- Produces: a compact 4-card strip on the home page, rendered only when `caps.isEnabled('lemma.analytics')`, each card linking to `/analytics`. No chart, no range control (fixed 30d).

- [ ] **Step 1: Write the failing test**

Create `admin/src/__tests__/homeAnalyticsStrip.spec.ts`. The home page already depends on several queries; mock them minimally plus the capabilities store:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'

const enabled = ref(true)
// Capture the `enabled` arg the page passes to useAnalyticsSummary (vi.hoisted — the mock factory
// is hoisted above imports). This proves the page WIRES the capability into the query's gate; the
// gate itself (no fetch when false) is proven by analyticsEnabledGate.spec.
const h = vi.hoisted(() => ({ summaryEnabled: null as unknown }))

vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: (id: string) => (id === 'lemma.analytics' ? enabled.value : true) }),
}))
vi.mock('@/queries/home', () => ({
  useHomeOverview: () => ({ data: ref({ types: [], recent: [], total_entries: 0 }), status: ref('success') }),
}))
vi.mock('@/queries/entries', () => ({ useCreateEntry: () => ({ mutateAsync: vi.fn() }) }))
vi.mock('@/queries/analytics', () => ({
  rangeFor: () => ({ from: '2025-06-01', to: '2025-06-30' }),
  useAnalyticsSummary: (_range: unknown, enabledArg?: unknown) => {
    h.summaryEnabled = enabledArg
    return {
      data: ref({ from: 'a', to: 'b', totals: { 'auth.login': 1200 }, active_users: 128 }),
      status: ref('success'),
    }
  },
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ user: { name: 'Test' } }) }))

import HomePage from '@/pages/index.vue'

const stubs = { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }

describe('home analytics KPI strip', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    enabled.value = true
    h.summaryEnabled = null
  })

  it('shows the analytics strip and enables the query when lemma.analytics is enabled', () => {
    const wrapper = mount(HomePage, { global: { stubs } })
    expect(wrapper.find('[data-test="home-analytics-strip"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('128')
    expect(toValue(h.summaryEnabled)).toBe(true)
  })

  it('hides the strip AND disables the analytics query when lemma.analytics is disabled', () => {
    enabled.value = false
    const wrapper = mount(HomePage, { global: { stubs } })
    expect(wrapper.find('[data-test="home-analytics-strip"]').exists()).toBe(false)
    // The page passes the capability flag as the summary's `enabled` gate, so a disabled pack
    // never triggers the analytics fetch (the (404'd) backend route is never called).
    expect(toValue(h.summaryEnabled)).toBe(false)
  })
})
```

- [ ] **Step 2: Run to verify it fails**

Run (from `admin/`): `pnpm test src/__tests__/homeAnalyticsStrip.spec.ts`
Expected: FAIL — no `[data-test="home-analytics-strip"]` element yet.

- [ ] **Step 3: Implement the strip**

In `admin/src/pages/index.vue`:

Add to the `<script setup>` imports and setup (near the other query composables):

```ts
import { useCapabilitiesStore } from '@/stores/capabilities'
import { rangeFor, useAnalyticsSummary } from '@/queries/analytics'
```
```ts
const caps = useCapabilitiesStore()
const analyticsOn = computed(() => caps.isEnabled('lemma.analytics'))
const analyticsRange = computed(() => rangeFor(30))
// Pass analyticsOn as the `enabled` gate: when the pack is disabled the summary query never fires,
// so Home never hits the (404'd) /analytics/summary route.
const { data: analyticsSummary } = useAnalyticsSummary(analyticsRange, analyticsOn)
const homeKpi = (event: string) => analyticsSummary.value?.totals?.[event] ?? 0
const homeActiveUsers = computed(() => analyticsSummary.value?.active_users ?? 0)
```

> `index.vue` already imports `{ computed, ref } from 'vue'` — no change to that import is needed. The capabilities store is NOT currently imported here; add the two imports above. If a later refactor has already added `useCapabilitiesStore`, reuse the existing instance instead of adding a second.

Add the strip near the top of the page `<template>` (inside the main content container, above the existing overview blocks):

```vue
        <div
          v-if="analyticsOn"
          data-test="home-analytics-strip"
          class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
          <RouterLink
            to="/analytics"
            class="rounded-lg border border-default p-3 hover:bg-elevated"
          >
            <div class="text-xs text-muted">Active users</div>
            <div class="text-xl font-semibold text-highlighted">{{ homeActiveUsers }}</div>
          </RouterLink>
          <RouterLink to="/analytics" class="rounded-lg border border-default p-3 hover:bg-elevated">
            <div class="text-xs text-muted">Logins</div>
            <div class="text-xl font-semibold text-highlighted">{{ homeKpi('auth.login') }}</div>
          </RouterLink>
          <RouterLink to="/analytics" class="rounded-lg border border-default p-3 hover:bg-elevated">
            <div class="text-xs text-muted">Entries created</div>
            <div class="text-xl font-semibold text-highlighted">
              {{ homeKpi('content.entry.created') }}
            </div>
          </RouterLink>
          <RouterLink to="/analytics" class="rounded-lg border border-default p-3 hover:bg-elevated">
            <div class="text-xs text-muted">Rows created</div>
            <div class="text-xl font-semibold text-highlighted">
              {{ homeKpi('collections.row.created') }}
            </div>
          </RouterLink>
        </div>
```

> Placement: put it inside whatever the page's main scroll/body container is, above the existing "first-run / recent" content, so it reads as a quiet top strip. Match the surrounding indentation.

- [ ] **Step 4: Run to verify it passes**

Run (from `admin/`): `pnpm test src/__tests__/homeAnalyticsStrip.spec.ts`
Expected: PASS (2 tests).

- [ ] **Step 5: Typecheck**

Run (from `admin/`): `pnpm type-check`
Expected: no errors.

- [ ] **Step 6: Run the full admin test suite**

Run (from `admin/`): `pnpm test`
Expected: PASS (all specs, including the pre-existing ones — confirm the home-page changes didn't break `index.vue`'s existing tests, if any).

- [ ] **Step 7: Commit (when authorized — see Commit Protocol above)**

```bash
git add admin/src/pages/index.vue admin/src/__tests__/homeAnalyticsStrip.spec.ts
git commit -m "analytics(admin): add quiet home KPI strip (gated on lemma.analytics)"
```

---

## Final verification (after all tasks)

- [ ] Backend: `DB_PGSQL_DATABASE=lemma_test APP_ENV=testing vendor/bin/phpunit tests/Integration/Analytics` — all green.
- [ ] Backend style: `vendor/bin/phpcs packages/lemma-analytics/src` — clean.
- [ ] Frontend: from `admin/`, `pnpm test` — all green; `pnpm type-check` — clean; `pnpm lint` — clean.
- [ ] Manual smoke (dev server): with `lemma.analytics` enabled, the Analytics nav appears, `/analytics` renders KPI cards + 3 line charts + the breakdown bar, the range presets refetch, and the segmented control switches Collections/Content types. Disable the capability → the nav entry and the home strip disappear and `/analytics` is guarded.

## Spec coverage check

- §3.1 active_users series → Task 1.
- §3.2 /breakdown + clamp max 50 → Task 2.
- §3.3 backend tests → Tasks 1 & 2.
- §4 architecture (page, module, queries, charts) → Tasks 3–6.
- §5 layout & metric mapping + segmented breakdown → Task 6.
- §5 home KPI strip → Task 7.
- §6 time range presets 7/30/90 default 30 → Tasks 4 & 6.
- §7 states/errors (empty state, non-fatal) → Task 3 (empty bar state), Task 6 (zero-filled/`?? 0`).
- §8 frontend tests → Tasks 3–7.
