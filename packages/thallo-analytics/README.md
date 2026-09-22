# glueful/thallo-analytics

A self-contained **product-analytics fact store** for [Thallo](https://thallo.dev). It consumes
content, collection, and auth **lifecycle events**, owns its own privacy-minimized facts (raw facts
+ daily rollups + distinct-actor presence), and exposes a gated admin read API — packaged as a
**capability pack** that depends only on the framework and `glueful/thallo-contracts`.

It answers "what's happening in the content/data" and "who's using it," and is deliberately distinct
from two things that already capture data: the framework's `metrics` capability (raw HTTP traffic —
ops, not product) and `audit_logs` (immutable per-action forensics). Analytics stores **aggregated
trends**; audit and analytics are independent consumers of the same pure events.

## What it provides

- **Three tables** (hybrid model — raw facts are canonical, rollups serve fast reads):
  | Table | Role |
  |---|---|
  | `analytics_facts` | Append-only raw event rows (source of truth); pruned after the retention window. Raw `actor_id` lives **only** here. |
  | `analytics_daily` | `(day, event, subject) → count`, UPSERT-incremented per fact; a `__total__` sentinel row is the per-event daily total, low-cardinality subjects (collections, content types) also get a breakdown row. Kept forever. |
  | `analytics_active_actors` | `(day, metric, actor_type, actor_id_hash)` — a **salted HMAC** of the actor id, never the raw value. "Active users / day" = distinct rows. Kept forever, privacy-minimized. |
- **`AnalyticsRecorder`** — the single, synchronous, best-effort write chokepoint (never throws into
  the request). Writes the fact, atomically increments the daily rollups (`ON CONFLICT`), and records
  a distinct active user (humans only — `admin` normalized to `user`, api-keys/system excluded).
- **Ingestion** — the pack subscribes framework **auth** events (`login`/`logout`/`login_failed`)
  under a strict token/PII allow-list (never reads token accessors; failed logins are count-only). A
  **bridge listener** in core (`Thallo\Core\Analytics\AnalyticsBridgeListener`) maps the events the
  pack can't depend on — `thallo-collections` `Collection*`/`CollectionRow*` and content `Entry*`
  events — into the recorder (the audit-listener pattern).
- **Read API** — `GET /v1/admin/analytics/series` (zero-filled daily time-series for a metric,
  optionally by subject), `GET /v1/admin/analytics/summary` (KPI totals + distinct active users
  over a range), and `GET /v1/admin/analytics/breakdown` (top subjects for one event over a range),
  behind `auth` + `content_permission:analytics.read`.
- **Retention** — `php glueful analytics:prune` deletes raw `analytics_facts` past
  `analytics.retention_days` (default 90); the rollups and the distinct-actor table are never pruned.

## The capability

The provider registers a single capability in `boot()`:

```php
new Capability('thallo.analytics', label: 'Analytics', description: '…');
```

- **Enabled by default.** An operator turns it off or on in the admin under **Extensions ›
  Capabilities**. The switch is stored system-wide and overrides the deploy-time
  `thallo.capabilities` config map.
- **Gated.** When disabled, the read API routes are never registered (`404`) and the pack's auth
  listeners do not subscribe. The core bridge for content and collection events is wired by
  `CoreServiceProvider`, which boots before this pack registers its capability, so it reads only the
  deploy-time `thallo.capabilities` config map: an admin switch-off alone does not stop it.
  Migrations run on install (not enable), so disabling preserves the tables.
- **Permission.** The pack declares `analytics.read`; the host app grants it to `administrator` in its
  own dependent migration.

## Privacy

The forever-kept `analytics_active_actors` table holds no identity — only a per-instance one-way
HMAC (`actor_id_hash = hmac_sha256(actor_id, ANALYTICS_HASH_KEY | APP_KEY)`), which preserves
uniqueness for counting without being reversible to a user. Raw `actor_id` exists only in
`analytics_facts` and is removed at the retention prune. Auth facts never carry token material, and
failed logins record no attempted username.

## Boundary

Depends on `glueful/thallo-contracts` and `glueful/framework` — and **never** on `glueful/thallo` (the
application), the audit extension, or `glueful/thallo-collections`. The collection/content event bridge
lives in core (`core/src/Analytics/`) precisely so the pack stays dependency-pure; the repo's
`composer boundaries` check enforces this (no `Thallo\Core\` references in `src/` or `routes/`).

## Install

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider, so there is nothing to install or enable per pack.
Its tables are created by `php glueful migrate:run` with the rest of the schema.

Optionally set `ANALYTICS_HASH_KEY` (falls back to `APP_KEY`) and `ANALYTICS_RETENTION_DAYS`.

Turning the capability off (Extensions › Capabilities) removes the read API and the auth
listeners. The analytics tables stay on disk.

## Admin

The admin's **Analytics** page (`admin/src/pages/analytics`) charts this data through the read API.
HTTP/ops metrics stay with the framework `metrics` capability.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-analytics/`.
