# glueful/lemma-analytics — Design Spec

**Status:** Approved design (pre-plan)
**Date:** 2026-06-30
**Scope:** Backend pack only. The admin-SPA dashboard is a separate follow-up spec.

## 1. Purpose & boundary

A self-contained **product-analytics fact store** for a Lemma instance. It answers two
families of question:

- **CMS / data activity** — entries created/published, rows created/updated, collection growth.
- **Usage & engagement** — logins, active users, login failures, API-key activity.

It does this by **consuming lifecycle events** (content, collections, auth) and **owning its own
facts** — its own tables, fed only by events, queried through its own API.

It is deliberately **distinct** from two things that already capture data:

| System | Captures | Why analytics is different |
|---|---|---|
| Framework `metrics` (`api_metrics`, `api_metrics_daily`) | Raw HTTP traffic / latency / rate limits | Ops-level, not product-level. Analytics never touches HTTP/request metrics. |
| `audit_logs` (audit extension) | Immutable per-action forensic trail (who did exactly what) | Forensics, not trends. Analytics stores **aggregates** and is privacy-minimized for long-term retention. |

Analytics and audit are **independent consumers of the same pure events** — analytics does not read
`audit_logs` and does not depend on the audit extension.

## 2. Architecture

Mirrors `lemma-collections` (self-contained capability pack) and the audit event-bridge pattern.

- **Pack `packages/lemma-analytics`** — depends only on `glueful/framework` + `glueful/lemma-contracts`.
  Owns: migrations, `AnalyticsRecorder`, the rollup logic, the query service + admin controllers,
  routes, config, a prune command, and a gated capability.
- The pack **subscribes directly** to framework events it can reference: `SessionCreatedEvent`,
  `SessionDestroyedEvent`, `AuthenticationFailedEvent`.
- An **App-side bridge listener** (in the Lemma app) maps events the pack must not depend on —
  `lemma-collections`'s `Collection*` / `CollectionRow*` events and the App's content `Entry*` events
  — into `AnalyticsRecorder`. This is the exact shape of `CollectionAuditListener`, registered in
  `LemmaServiceProvider::registerEventListeners()` alongside the audit listener. Removing a pack
  drops its bridge wiring cleanly (guarded by `class_exists`).

```
content/collections events ──▶ App bridge listener ─┐
                                                     ├─▶ AnalyticsRecorder ─▶ facts + rollups
framework auth events ──▶ pack listener ────────────┘
```

## 3. Data model

Hybrid: raw facts are canonical; daily rollups serve fast reads.

### `analytics_facts` — append-only source of truth (pruned at retention)
| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `occurred_at` | timestamp | event time (from the `BaseEvent` timestamp) |
| `event` | varchar | dotted, e.g. `content.entry.published`, `collections.row.created`, `auth.login` |
| `category` | varchar | `content` \| `collections` \| `auth` |
| `subject_type` | varchar null | e.g. `content_type`, `collection`, `user` |
| `subject_id` | varchar null | the content-type slug / collection name / user uuid |
| `actor_type` | varchar null | `admin` \| `api_key` \| `user` \| `system` |
| `actor_id` | varchar null | raw actor uuid — **only here**, and only until pruned |
| `metadata` | json null | small event-specific context (e.g. schema `change`/`detail`) |

Indexes: `(occurred_at)`, `(event, occurred_at)`, `(category, occurred_at)`.

### `analytics_daily` — event-count rollup (kept forever)
| column | type | notes |
|---|---|---|
| `day` | date | |
| `event` | varchar | |
| `subject` | varchar **not null** | breakdown value (collection name / content-type slug); the sentinel **`__total__`** = the all-up total for that event/day |
| `count` | bigint | UPSERT-incremented per fact |

Unique: `(day, event, subject)` — **all columns NOT NULL**, so `ON CONFLICT … DO UPDATE` reliably
increments. (A nullable `subject` would break this: SQL unique constraints do not treat `NULL` as
conflicting with `NULL` — Postgres keeps them distinct, MySQL/SQLite likewise — so total rows would
duplicate instead of incrementing.) A `__total__` row is always written (the per-event daily total); a
per-subject row is additionally written for **low-cardinality** subjects (collections, content types)
to support breakdowns. High-cardinality subjects (e.g. users on auth events) are **not** rolled by
subject — only `__total__` — so this table never grows per-user. The sentinel cannot collide:
collection/content-type slugs match `^[a-z][a-z0-9_]*$` and cannot begin with `_`. This is what
`…/series` and most of `…/summary` read.

### `analytics_active_actors` — distinct-actor presence (kept forever, privacy-minimized)
| column | type | notes |
|---|---|---|
| `day` | date | |
| `metric` | varchar | default `active_users`; room for `active_api_keys`, `content_contributors`, … |
| `actor_type` | varchar | `user` (humans; `admin` normalized in) \| `api_key` (future) |
| `actor_id_hash` | varchar | **salted HMAC** of `actor_id` alone (not the type) — never the raw id |

Unique: `(day, metric, actor_type, actor_id_hash)`. "Active users / day" =
`COUNT(*) WHERE metric = 'active_users' GROUP BY day`.

**`active_users` definition (v1):** distinct **human** users only. The `admin` and `user` actor types
are both normalized to `actor_type = 'user'` before writing, and the hash is of the `actor_id` uuid
alone — so the same person across admin and regular sessions counts **once**. `api_key` and `system`
actors are **excluded** from `active_users`. Hashing `actor_id` alone (with `actor_type` a separate
column) lets later metrics coexist unambiguously — `active_api_keys` (`actor_type = 'api_key'`),
`content_contributors`, etc.

**Privacy:** the forever-kept actives table holds no identity — only a per-instance one-way hash that
preserves uniqueness for counting. `actor_id_hash = hmac_sha256(key = ANALYTICS_HASH_KEY (or APP_KEY),
data = actor_id)`. The salt makes the table non-correlatable to known uuids even if leaked. Raw
`actor_id` exists only in `analytics_facts` and is removed at the 90-day prune.

## 4. Fact taxonomy (v1)

The bridge/listeners translate each event to one `AnalyticsFact`. Actor is taken from the event payload
where present (collection events carry `actorType/actorId`; auth events carry the user uuid; content
events carry the editing user); where an event carries no actor, the bridge falls back to the request
principal at dispatch (best-effort), consistent with the audit actor model. Exact field names are
confirmed during planning.

| Source | Event | `event` | category | subject_type / id | actor |
|---|---|---|---|---|---|
| Auth (fw) | `SessionCreatedEvent` | `auth.login` | auth | user / uuid | user / uuid |
| Auth (fw) | `SessionDestroyedEvent` | `auth.logout` | auth | user / uuid | user / uuid |
| Auth (fw) | `AuthenticationFailedEvent` | `auth.login_failed` | auth | — (count only, no identity) | — (no actor) |
| Collections | `CollectionCreated` | `collections.collection.created` | collections | collection / name | actor |
| Collections | `CollectionUpdated` | `collections.collection.updated` | collections | collection / name | actor |
| Collections | `CollectionDropped` | `collections.collection.dropped` | collections | collection / name | actor |
| Collections | `CollectionRowCreated` | `collections.row.created` | collections | collection / name | actor |
| Collections | `CollectionRowUpdated` | `collections.row.updated` | collections | collection / name | actor |
| Collections | `CollectionRowDeleted` | `collections.row.deleted` | collections | collection / name | actor |
| Content | `EntryCreated` | `content.entry.created` | content | content_type / slug | actor |
| Content | `EntryUpdated` | `content.entry.updated` | content | content_type / slug | actor |
| Content | `EntryDeleted` | `content.entry.deleted` | content | content_type / slug | actor |
| Content | `EntryPublished` | `content.entry.published` | content | content_type / slug | actor |
| Content | `EntryUnpublished` | `content.entry.unpublished` | content | content_type / slug | actor |

Easily added later via the same bridge, no schema change: `AssetAttached/Detached`, `Model*`, and more
active metrics (`active_api_keys`, `content_contributors`). v1 writes only the `active_users` metric
(distinct human users — see §3 for the precise definition).

### Auth ingestion: token/PII allow-list (hard rule)

Auth events expose token accessors — `SessionCreatedEvent::getTokens()` / `getAccessToken()` /
`getRefreshToken()`. The auth listeners use a strict **allow-list** and MUST NEVER call those accessors
or copy any token material into a fact or `metadata`. The only auth fields recorded are: the **event
name**, and the **user uuid** (login/logout only — needed for actor attribution + active-users; a uuid
is an identifier, not a credential, lives in `analytics_facts` until the 90-day prune, and is one-way
hashed in `analytics_active_actors`). `auth.login_failed` records a **count only** — no attempted
username (it is unverified input and likely PII). `metadata` is always empty for auth facts.

## 5. Ingestion

- One service, `AnalyticsRecorder::record(AnalyticsFact $fact): void`, is the single write chokepoint:
  1. insert the raw fact row,
  2. UPSERT-increment `analytics_daily` at `(day, event, '__total__')` (always — the per-event daily
     total), and additionally at `(day, event, subject_id)` when the subject is low-cardinality
     (`subject_type` ∈ {`collection`, `content_type`}) so per-collection / per-type breakdowns exist,
  3. if the fact has a **human** actor (`actor_type` ∈ {`user`, `admin`}), `INSERT … ON CONFLICT DO
     NOTHING` into `analytics_active_actors` for `(day, 'active_users', 'user', actor_id_hash)` —
     `admin` normalized to `user`, hash of the uuid alone, so one person counts once (see §3).
     `api_key` / `system` actors are not written to `active_users`.
- **Synchronous, after-commit, best-effort.** Recording is wrapped so it never throws into the
  request (failures are logged, swallowed) — the same guarantee audit gives. Zero infrastructure
  (no queue) — this is what keeps the pack self-contained. The recorder is the seam if async/queued
  ingestion is wanted later.

## 6. Read / query API (admin, gated `analytics.read`)

- `GET /v1/admin/analytics/series?metric=<event>&from=&to=&interval=day[&dimension=subject[&subject=<value>]]`
  → time-series of counts from `analytics_daily` (zero-filled buckets across the range). Default reads
  the `__total__` rows; `dimension=subject` returns per-subject rows (optionally filtered to one
  `subject`).
- `GET /v1/admin/analytics/summary?from=&to=`
  → KPI totals for the range: e.g. entries published, rows created, logins, **active users**
    (from `analytics_active_actors`), login failures.

A small `AnalyticsQuery` service encapsulates the rollup reads; controllers stay thin. Responses use
the framework's standard envelope. Reads hit rollups only; raw facts back ad-hoc/backfill needs.

## 7. Capability, config, retention

- Gated capability like collections: migrations register on install; listeners + routes are active
  when the capability is enabled.
- `config/analytics.php`: `enabled`, `retention_days` (default **90**), `hash_key` (falls back to
  `APP_KEY`).
- **Retention:** a prune command (`analytics:prune`) deletes `analytics_facts` rows older than
  `retention_days`. `analytics_daily` and `analytics_active_actors` are **never** pruned (they are
  already aggregated + privacy-minimized). Schedulable.

## 8. Testing

- **Pack:** `AnalyticsRecorder` writes a fact, increments the daily rollup, and inserts a distinct
  actor (idempotent on repeat within a day); `AnalyticsQuery` returns correct zero-filled series and
  summary totals; `actor_id_hash` is stable + salted; prune removes only aged raw facts and leaves
  rollups/actives intact.
- **App:** a wiring test (à la `CollectionAuditWiringTest`) proves the bridge maps collection/content
  events to the recorder, and the pack maps auth events.

## 9. Out of scope (this spec)

- Admin-SPA dashboard / charts — a separate follow-up spec (this ships the data layer + read API).
- HTTP/ops metrics — owned by the framework `metrics` capability.
- Async/queued ingestion — seam left at `AnalyticsRecorder`.
- Cross-instance / external analytics export — future.

## 10. Open seams (intentional, not v1 work)

- Additional active metrics (`active_api_keys`, `content_contributors`) — no migration, just extra
  `INSERT`s at ingest.
- Additional event sources (assets, models, webhooks) — add a bridge mapping.
- Hourly/weekly rollups — add a rollup table; raw facts make backfill possible within retention.
