# lemma-analytics Admin SPA (Phase 2) — Design

**Status:** Approved (direction + refinements), pending spec review.
**Depends on:** the Phase 1 backend pack — `docs/superpowers/specs/2026-06-30-lemma-analytics-design.md`.
**Scope:** the admin-SPA analytics dashboard that visualizes the `lemma-analytics` read API, plus two
small read-API additions to the pack that the dashboard requires. Everything is gated on the
`lemma.analytics` capability and disappears (frontend nav + backend 404) when the pack is disabled or
removed.

---

## 1. Goal

Give the admin a first-class **Analytics** view over the facts the Phase 1 pack already records —
activity KPIs, trends over time, auth health, distinct active users, and a per-subject breakdown of
the most active collections and content types — without adding any new fact-collection surface. This
phase is read-only visualization over the existing rollups, with the minimum backend additions needed
to serve two of those views cleanly (no frontend N+1 fan-out).

## 2. What already exists (Phase 1)

The pack ships two read endpoints under `/v1/admin/analytics`, both gated `auth` +
`lemma_permission:analytics.read`:

- `GET /series?metric=<event>&from=&to=[&dimension=subject&subject=<x>]` → `{ metric, from, to,
  series: [{day, count}] }`, zero-filled to a contiguous daily axis. Reads `analytics_daily`.
- `GET /summary?from=&to=` → `{ from, to, totals: { <event>: count, … }, active_users: <int> }`. Totals
  read `analytics_daily` (`subject = '__total__'`); `active_users` is `COUNT(DISTINCT actor_id_hash)`
  over `analytics_active_actors` for the range.

**Event catalog** (the `event` / metric names the recorder writes):

| Event | Subject | Source |
|---|---|---|
| `auth.login`, `auth.logout`, `auth.login_failed` | — | pack auth listener |
| `collections.collection.created` / `updated` / `dropped` | collection name | App bridge |
| `collections.row.created` / `updated` / `deleted` | collection name | App bridge |
| `content.entry.created` / `updated` / `deleted` / `published` / `unpublished` | content-type slug | App bridge |

The recorder writes a `__total__` daily row for every event, **and** a per-subject daily breakdown row
for low-cardinality subjects (collection names, content-type slugs) — so a subject-level breakdown of
those events is already present in `analytics_daily`.

## 3. Backend additions (this phase, in `packages/lemma-analytics`)

Two additions to the pack's read layer. Both keep the existing triple gate (capability boot-gate →
`auth` → `lemma_permission:analytics.read`) and the existing response envelope.

### 3.1 `active_users` as a routed `/series` metric (extension, not a new endpoint)

`GET /series?metric=active_users&from=&to=` returns the **same** `{ metric, from, to, series:
[{day, count}] }` shape, but `count` is the daily distinct active-user count. The frontend then has
**one** chart API shape for every line chart.

`AnalyticsQuery::series()` branches on the metric:

- `metric === 'active_users'` → query `analytics_active_actors`:
  `SELECT day, COUNT(DISTINCT actor_id_hash) AS count FROM analytics_active_actors
   WHERE metric = 'active_users' AND day >= ? AND day <= ? GROUP BY day`, then zero-fill the daily
  axis exactly like the `analytics_daily` path.
- any other metric → unchanged (`analytics_daily`, `where event`, `where subject`).

The `dimension=subject` / `subject=` params are ignored for `active_users` (there is no subject axis on
the actor table); the controller need not special-case them.

### 3.2 `GET /breakdown?event=&from=&to=&limit=` (new endpoint)

Returns the top subjects for **one** event over a range — `{ event, from, to, breakdown:
[{subject, count}] }`, ordered by count desc, limited (default `limit=10`, clamped to **max 50**).
One event at a time by design — the dashboard never mixes events into a single ranking.

`AnalyticsQuery::breakdown(string $event, string $from, string $to, int $limit = 10): array`:

```sql
SELECT subject, SUM(count) AS count
FROM analytics_daily
WHERE event = ? AND subject <> '__total__' AND day >= ? AND day <= ?
GROUP BY subject
ORDER BY count DESC
LIMIT ?
```

Controller `breakdown(Request)`: require `event`, `from`, `to` (422 if missing, mirroring `series`);
parse `limit` (default 10, clamp). Route registered next to the other two in
`routes/admin-routes.php` with the same `lemma_permission:analytics.read` middleware.

### 3.3 Backend tests (pack)

- `AnalyticsQuery::series('active_users', …)` returns daily distinct counts, zero-filled, and does
  **not** read `analytics_daily`.
- `AnalyticsQuery::breakdown()` excludes `__total__`, orders desc, and honors the limit/clamp.
- Controller: `breakdown` is behind the `analytics.read` gate and 422s on missing params — matching
  the existing `series`/`summary` controller tests and the pack's `RemovabilityTest` (route 404s when
  the capability is disabled).

## 4. Frontend architecture

Follows the established admin-SPA module pattern exactly; the whole surface is capability-gated.

- **Page:** `admin/src/pages/analytics/index.vue` —
  `definePage({ meta: { requiresAuth: true, requiresCapability: 'lemma.analytics' } })`.
- **Nav module:** `admin/src/registry/analyticsModule.ts` exporting `registerAnalyticsModule()` →
  `registerAdminModule({ id: 'analytics', requires: ['lemma.analytics'], nav: { main: [{ label:
  'Analytics', icon: 'i-lucide-chart-line', to: '/analytics' }] } })`. Registered in
  `admin/src/layouts/default.vue` alongside `registerCoreModule()` / `registerCollectionsModule()`,
  before first nav render. When `lemma.analytics` is off the group is absent (mirrors collections).
- **Query layer:** `admin/src/queries/analytics.ts` — Pinia Colada (`useQuery`) over `authFetch`,
  against `runtimeConfig.apiBase + '/analytics'`. Typed interfaces for series / summary / breakdown
  responses. Range presets drive `from`/`to` (see §6).
- **Charts:** add `@unovis/vue` + `@unovis/ts` to `admin/package.json`. unovis is wrapped behind two
  local components so the dependency stays isolated and swappable:
  - `admin/src/pages/analytics/components/AnalyticsLineChart.vue` — one or more daily series (axes,
    tooltip, legend, semantic-color palette).
  - `admin/src/pages/analytics/components/AnalyticsBarChart.vue` — horizontal bars for the breakdown.
  Both take already-shaped `{day|subject, count}` data and Nuxt UI semantic colors; no unovis types
  leak into pages or queries.

## 5. Page layout & metric mapping

```
┌ Analytics ───────────────────────────── [ 7d | 30d | 90d ] ┐
│ ┌ Active users ┐┌ Logins ┐┌ Entries ┐┌ Rows ┐              │  KPI cards (/summary + active_users)
│ │     128      ││  1.2k  ││   340   ││ 890  │              │
│ └──────────────┘└────────┘└─────────┘└──────┘              │
│ ┌ Activity trend ────────────────────────────────────────┐ │  line (/series ×3, overlaid)
│ │  logins ▪ entries ▪ rows  (daily)                      │ │
│ └────────────────────────────────────────────────────────┘ │
│ ┌ Active users / day ─────┐ ┌ Auth health ────────────────┐ │  line (/series×1) | line (/series×2)
│ │  ╱╲___╱╲                │ │  login ▪ login_failed       │ │
│ └─────────────────────────┘ └─────────────────────────────┘ │
│ ┌ Most active  [ Collections | Content types ] ──────────┐  │  bar (/breakdown, segmented)
│ │  posts   ████████████  120                             │  │
│ │  authors ██████        62                              │  │
│ └────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

| Block | Source call(s) | Metric(s) |
|---|---|---|
| KPI: Active users | `/summary` | `active_users` |
| KPI: Logins | `/summary` | `auth.login` total |
| KPI: Entries created | `/summary` | `content.entry.created` total |
| KPI: Rows created | `/summary` | `collections.row.created` total |
| Activity trend | `/series` ×3 (overlaid) | `auth.login`, `content.entry.created`, `collections.row.created` |
| Active users / day | `/series` ×1 | `active_users` |
| Auth health | `/series` ×2 (overlaid) | `auth.login`, `auth.login_failed` |
| Breakdown (segmented) | `/breakdown` ×1 per selected segment | Collections → `collections.row.created`; Content types → `content.entry.created` |

The breakdown segmented control switches the single `/breakdown` query's `event` param between the two
values; only the selected segment's query runs. Segment labels are fixed ("Collections", "Content
types"); no mixed/combined ranking.

**Home KPI strip:** the same four compact cards (no chart), on `admin/src/pages/index.vue`, reusing the
analytics summary query at a fixed default range (30d), shown only when `caps.isEnabled('lemma.analytics')`,
each linking through to `/analytics`. Kept quiet — a single compact row, no trend, no range control.

## 6. Time range

- Presets **7 / 30 / 90 days**, default **30**; a single reactive range state on the page is the one
  source of truth feeding every query's `from`/`to`.
- `to` = today (inclusive), `from` = `to − (N−1)` days, formatted `YYYY-MM-DD` to match the API's date
  comparison. Changing the preset re-runs all page queries (query keys include the range).
- The home KPI strip uses a fixed 30d range (no control of its own).

## 7. States & errors

- **Loading:** Nuxt UI `USkeleton` for KPI cards and a placeholder block per chart.
- **Empty:** zero-filled series render a flat baseline; KPI cards show `0`; the breakdown shows an
  inline empty state ("No activity in this range yet") when it returns no rows.
- **Error:** each query surfaces failures through the existing `useNotify` pattern; a failed chart
  shows an inline message with retry, and one failing block never blanks the page. The page itself
  only requires auth + capability; a data error is non-fatal.

## 8. Frontend tests (vitest)

- **Gating:** `analyticsModule` adds the Analytics nav only when `lemma.analytics` is enabled and it is
  absent when disabled (mirrors `collectionsModule` / `coreModule` specs).
- **Query layer:** range presets build the correct `from`/`to` and endpoint URLs (`/series`,
  `/summary`, `/breakdown`, and the `active_users` metric variant); response typing/unwrapping via
  `authFetch` (`json.data ?? json`).
- **Page:** with stubbed query composables and stubbed unovis wrapper components, the page renders the
  four KPI values and one chart block per metric group, and the breakdown segmented control switches
  the active `event`. unovis wrappers are stubbed the way other portal/Nuxt-UI components are in the
  existing suite (assert on `data-test` hooks, not chart internals).
- **Home strip:** renders the four cards only when the capability is enabled.

## 9. Out of scope

- No new fact-collection surface, no new events, no changes to the recorder or the Phase 1 tables.
- No CSV/export, no custom (arbitrary) date-range picker beyond the three presets, no per-user drill-down
  (the actor table is intentionally hashed/anonymous — §Privacy of the Phase 1 spec).
- No realtime/streaming; queries are request/refetch on range change.
- Retention/pruning is unchanged (Phase 1 `analytics:prune`).

## 10. Deliverables summary

**Pack (`packages/lemma-analytics`):**
- `AnalyticsQuery::series()` routes `active_users` to `analytics_active_actors`; new
  `AnalyticsQuery::breakdown()`.
- `AnalyticsController::breakdown()` + `/v1/admin/analytics/breakdown` route.
- Backend tests (§3.3).

**Admin SPA (`admin/`):**
- `@unovis/vue` + `@unovis/ts` deps.
- `queries/analytics.ts`; `registry/analyticsModule.ts` (+ registration in `layouts/default.vue`).
- `pages/analytics/index.vue` + `components/AnalyticsLineChart.vue` + `components/AnalyticsBarChart.vue`.
- Home KPI strip on `pages/index.vue`.
- Frontend tests (§8).
