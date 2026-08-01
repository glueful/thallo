# Scheduled Publish / Unpublish — Design

**Goal:** Let editors schedule an entry+locale to publish or unpublish at a future
time, as **deferred execution of the existing publish/unpublish actions** — no second
publish model, no changes to the draft/version/publication lifecycle or the delivery
read path.

**Status:** ✅ Shipped (2026-06-17) — implemented and reviewed.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §1. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) §2 ("Scheduled publish/unpublish is deferred").

---

## Definition of behavior (the contract)

> Scheduled publish is deferred execution of the normal publish action. At the
> scheduled time, Lemma validates and publishes the current draft. If validation
> fails, the entry remains unchanged and the scheduled job records the failure.

Unpublish is the symmetric deferred call to the existing unpublish action.

## Scope decisions (settled during brainstorming)

1. **Deferred publish, not a frozen snapshot.** At `run_at`, run the normal
   `PublishService::publish()` — it validates + snapshots the **current** draft, pins,
   emits `entry.published`, invalidates cache, drives search/webhooks. Editors keep
   working until go-live; whatever is in the draft at the scheduled moment ships. The
   accepted tradeoff: an invalid draft at fire time fails the scheduled action and
   nothing goes live (better than hidden unpublished versions + a second mental model).
2. **Dedicated `entry_schedules` table**, not `publish_at`/`unpublish_at` columns —
   supports publish AND unpublish (incl. a publish-at-T1 / unpublish-at-T2 window),
   cancel/reschedule, and an audit trail. Leaves `entry_drafts`/`entry_publications`
   untouched (§2's "no special publication states").
3. **Dedicated `schedules` sub-resource API**, not an overloaded `publish?run_at=`.
4. **The §5 event taxonomy is unchanged.** Firing emits the existing
   `entry.published`/`entry.unpublished`; scheduling/canceling emits no content event.
5. **`run_at` is stored and returned as UTC ISO-8601.** The API accepts an absolute
   ISO-8601 timestamp *with timezone* and normalizes to UTC; naive/local times are
   rejected.

## Architecture

Four units, each independently testable:

- **`entry_schedules` table + `ScheduleRepository`** — persistence and the due-row claim
  (the claim query is repository-owned raw PDO with bound parameters; see below).
- **`ScheduleRunner`** — the unit of firing logic and the direct test target. It claims
  due rows, fires each through `PublishService` **outside any enclosing transaction**, and
  records the outcome via a separate write (see firing mechanism). Both entry points below
  delegate to it, so the behavior is defined once.
- **Two thin runner wrappers:**
  - **`lemma:schedules:run` console command** — the operator/manual entry point
    (deterministic, admin-invokable).
  - **`RunDueSchedulesJob` (a `Glueful\Queue\Job` / `JobInterface`)** — the cron entry
    point. The framework scheduler resolves `config/schedule.php` `handler_class` through
    `JobHandlerResolver`, which **requires `JobInterface`** — a console command is rejected
    — so the every-minute cron target is this job, not the command.
- **`ScheduleController` + routes** — the admin API to create/list/cancel schedules.

`PublishService::publish(string $entryUuid, string $locale, ?string $actor): string`
and `unpublish(string $entryUuid, string $locale): void` are the existing entry points
reused verbatim.

## Data model — `entry_schedules`

New migration `database/migrations/010_CreateEntrySchedulesTable.php` (confirm the next
number during planning):

```
entry_schedules
  id              bigint pk autoincrement
  uuid            char(12)        -- public id (nanoid); used by the cancel endpoint
  entry_uuid      char(12)        -- target (publish granularity = entry + locale)
  locale          varchar
  action          varchar         -- CHECK (action IN ('publish','unpublish'))
  run_at          timestamptz     -- when to fire (stored UTC)
  status          varchar         -- CHECK (status IN ('pending','processing','done','failed','canceled'))
  attempts        int default 0   -- incremented per fire attempt; diagnostics + future manual retry
  failure_reason  text null       -- set when status='failed'
  created_by      char(12) null
  created_at      timestamptz
  updated_at      timestamptz null
  canceled_at     timestamptz null
  canceled_by     char(12) null
  INDEX (status, run_at)                                       -- the poller scan
  UNIQUE (entry_uuid, locale, action) WHERE status = 'pending' -- one pending publish + one pending unpublish per (entry, locale)
```

Notes:
- `action`/`status` use DB `CHECK` constraints (the Glueful schema builder's enum/check
  support) plus app-level enums (`App\Content\Enums\ScheduleAction`, `ScheduleStatus`).
- `timestamptz` is the intent; implement via whatever the Glueful schema builder maps to
  a PostgreSQL timestamp-with-time-zone column.
- The partial unique allows at most **one pending publish and one pending unpublish per
  (entry, locale)**; terminal rows (`done`/`failed`/`canceled`) accumulate as history.
- `processing` is a **transient claim marker**, not a terminal state: the runner flips a
  row `pending → processing` when it claims it (so the claim is durable without holding a
  row lock across the fire), then writes the terminal status afterward. A row stuck in
  `processing` means the worker crashed mid-fire; `reclaimStale` returns it to `pending`.

## Firing mechanism — `ScheduleRunner`

The unit of firing logic is `ScheduleRunner::run(int $limit)`. Both wrappers
(`lemma:schedules:run` for operators, `RunDueSchedulesJob` for cron) simply call it, so
the behavior is defined and tested in one place — the runner is the direct test target.
`config/schedule.php` registers `RunDueSchedulesJob` to run every minute
(`'schedule' => '* * * * *'`). The on/off switch is `config('lemma.scheduler.enabled')`
(env `LEMMA_SCHEDULER_ENABLED`, default off in the test env), checked **inside**
`run()`. Note the framework scheduler's per-job `'enabled'` config key is **inert** (the
disabled-job skip is commented out in `JobScheduler::loadCoreJobsFromConfig()`), so the
internal guard — not a schedule-entry flag — is the real off-switch.

Per run (one "tick"):
0. **Reclaim stale claims** — `reclaimStale(300)` resets any row left `processing` for
   more than ~5 minutes (a worker that crashed mid-fire) back to `pending` so it is
   retried on this tick.
1. **Claim** a bounded batch of due rows via `ScheduleRepository::claimDuePending($limit)`
   in a **short transaction**: `SELECT … status='pending' AND run_at <= now()` ordered by
   `run_at` `FOR UPDATE SKIP LOCKED`, then `UPDATE … SET status='processing' … RETURNING *`,
   then commit. Flipping to `processing` **is** the claim — the row lock is released at
   commit, so the lock is not held across the (potentially slow) fire, yet overlapping
   runs / multiple workers never double-claim (`SKIP LOCKED` + the status change). **This
   claim query is owned by `ScheduleRepository` and uses raw PDO with bound parameters** —
   the Glueful query builder doesn't expose `FOR UPDATE SKIP LOCKED` safely, and the
   feature's concurrency guarantee depends on it. **Postgres-only**, consistent with the
   V1 Postgres requirement.
2. **Execute** each claimed row in its own try/catch, **outside any enclosing
   transaction**:
   - `publish` → `PublishService::publish(entry_uuid, locale, created_by)`.
   - `unpublish` → `PublishService::unpublish(entry_uuid, locale)`.
3. **Record outcome** via a separate `markOutcome` write (not nested in the action):
   - success → `status='done'`;
   - exception → `status='failed'`, `failure_reason` = the exception message, entry
     unchanged;
   - target entry soft-deleted → `status='canceled'` (target intentionally gone, not an
     operational failure);
   - `attempts` incremented in all cases.

**Behaviors:**
- **No auto-retry in V1.** A `failed` row stays failed (a stale "publish at 9am" must
  not surprise-publish at noon). `attempts` is recorded for diagnostics and a future
  manual-retry endpoint; the editor fixes the draft and re-schedules.
- **Missed runs catch up** — polling `run_at <= now()` fires a schedule whose time
  passed while the worker was down, on the next tick. No separate catch-up logic.
- **Idempotency** — only `pending` rows are claimed; `done`/`failed`/`canceled` are
  terminal and never re-fired. `processing` rows are not re-claimed except via
  `reclaimStale`.
- **`unpublish` of an already-unpublished entry → `done`** (desired end-state reached;
  no false error).

**Why this shape (savepoint-independent):** because the claim commits the `processing`
flip *before* the action runs, the action fires *outside* any enclosing transaction, and
the terminal status is a *separate* write, an action failure can never roll back the
status write — the design does **not** depend on the framework's `db()->transaction()`
being savepoint-isolated. `PublishService::publish` still runs its own `db()->transaction()`
with `afterCommit` effects internally; that transaction failing rolls back only its own
work, and the runner then writes `failed`. The plan verifies this with an
**outcome-survives-failure test** (publish exception → `failed` recorded; success →
`afterCommit` events fire once).

## API surface

A `schedules` sub-resource under the existing `/v1/admin` group (consistent with
`/draft`, `/routes`, `/locales`, `/versions`). All under `auth` + `lemma_permission`.

- **`POST /v1/admin/entries/{uuid}/schedules/{locale}`** — Perm `lemma.entries.publish`.
  Body `{ "action": "publish"|"unpublish", "run_at": "<ISO-8601 with offset>" }`.
  - Validation: the entry must **exist and not be soft-deleted** (`status='deleted'`) —
    otherwise **404**, before any row is written (no orphan schedule against a missing
    entry); `action` ∈ {publish, unpublish}; `run_at` must be an **absolute ISO-8601
    timestamp with timezone** and in the **future** (reject past + naive/no-offset →
    422); `locale` validated via `ContentLocaleService`. `run_at` is **normalized to UTC**
    before storage.
  - **Create replaces the existing pending action** of that type (against the partial
    unique): the existing *pending* row is updated/replaced (re-POST = reschedule).
    **Terminal rows (`done`/`failed`/`canceled`) are never touched** — history is
    preserved.
  - Response: the schedule row — `uuid`, `entry_uuid`, `locale`, `action`, `run_at`
    (UTC ISO-8601), `status`, `created_by`, `created_at` — plus a `replaced` boolean
    indicating whether a pending row already existed.
- **`GET /v1/admin/entries/{uuid}/schedules`** — Perm `lemma.entries.read`. Lists this
  entry's schedules across locales: pending rows + recent terminal rows (history), with
  `failure_reason` where present.
- **`DELETE /v1/admin/entries/{uuid}/schedules/{scheduleUuid}`** — Perm
  `lemma.entries.publish`. Cancels a **pending** row → `status='canceled'`,
  `canceled_at`/`canceled_by` set. The cancel is **entry-scoped**: it matches on
  `entry_uuid = {uuid} AND uuid = {scheduleUuid}`, so a known schedule UUID cannot be
  canceled under the wrong entry URL. A terminal row, an unknown UUID, or a UUID that
  belongs to a different entry → **409** (not a silent success).

Request DTO: `App\Content\Http\DTOs\ScheduleData` (`action`, `run_at`) — a hydrated
`RequestData` with `#[Rule]`s; the with-timezone + future + UTC-normalization logic
lives in the controller/repository (it's beyond a built-in rule).

## Events

No change to the §5 frozen taxonomy. Firing runs `PublishService`, emitting the existing
`entry.published`/`entry.unpublished` (downstream cannot distinguish scheduled from
manual — correct). Scheduling and canceling are pending-intent changes, **not** content
state changes, and emit no content event.

## Status visibility

- **`GET …/schedules`** — full detail + history (incl. `failure_reason`).
- **`GET /v1/admin/entries/{uuid}/locales`** (existing summary) gains a per-locale
  `scheduled` block: the next pending publish/unpublish + `run_at`, and the last failure
  (status + `failure_reason`) if any — so scheduling state sits alongside
  draft/publication/route state and isn't hidden behind a separate call.

## Testing (Postgres, `LemmaTestCase`)

- **Schema:** table + `CHECK` guards + partial-unique behavior (a 2nd pending publish is
  rejected/replaced; pending publish + pending unpublish coexist).
- **API:** POST creates pending, returns the row with `replaced=false`; 2nd POST same
  action → `replaced=true`, moves `run_at`, terminal rows untouched; POST rejects past
  `run_at`, naive (no-offset) `run_at`, bad `action`, disabled locale (422), and a
  missing or soft-deleted entry (404, no row written); GET lists pending + terminal
  history; DELETE cancels a pending row (`canceled` + `canceled_at/by`); DELETE on a
  terminal row → 409; DELETE is **entry-scoped** (a schedule of entry A DELETEd under
  entry B's URL → 409, row stays pending); permission gating (publish for POST/DELETE,
  read for GET).
- **`ScheduleRunner`** (invoked directly — the deterministic test unit):
  claims `run_at <= now()` not future; `publish` → version + pin +
  `entry.published` (recording listener); `unpublish` → publication removed +
  `entry.unpublished`; invalid draft → `failed` + `failure_reason`, entry unchanged;
  soft-deleted entry → `canceled`; unpublish-already-unpublished → `done`; missed run
  fires on tick; terminal rows never re-fired; `attempts` incremented.
- **Outcome-survives-failure test:** a publish exception during the fire still records
  `failed` (the `markOutcome` write is independent of the action — not nested in it); on
  success, `afterCommit` events fire exactly once.
- **Concurrency-claim test:** with a held `FOR UPDATE SKIP LOCKED` transaction on a due
  row, a second claim does not see/claim that row — the same pending row cannot be
  claimed/fired twice.
- **Stale-reclaim test:** a row left `processing` past the threshold is returned to
  `pending` by `reclaimStale` and fired on the next tick.
- **Timezone normalization test:** POST with a valid offset timestamp (e.g.
  `2026-07-01T09:00:00+02:00`); assert the stored + returned `run_at` is the consistent
  UTC ISO-8601 equivalent (`2026-07-01T07:00:00Z`).
- **Locales summary:** `GET …/locales` includes the per-locale `scheduled` block.

## Out of scope / follow-ups

- **Auto-retry** of failed schedules and a **manual-retry endpoint** (`attempts` is
  carried for this).
- **Failure notifications** (email/webhook when a scheduled publish fails) — a
  notification concern, surfaced only via the read APIs in V1.
- **Recurring schedules** (cron-like repeating publishes) — V1 is one-shot per row.

## Success criteria

- An entry+locale can be scheduled to publish/unpublish at a future UTC time; the tick
  fires it through the existing `PublishService` path with identical events/cache/webhook
  behavior.
- Invalid-draft-at-fire-time leaves the entry unchanged and records `failed` +
  `failure_reason`, visible in the admin.
- Cancel, reschedule (replace pending), and the publish/unpublish window all work; the
  §5 event taxonomy and the delivery read path are unchanged.
- Full suite green on Postgres CI; the concurrency, outcome-survives-failure, stale-reclaim,
  and timezone tests pass.
