# glueful/lemma-analytics

A self-contained **product-analytics fact store** for [Lemma](https://getlemma.dev). It consumes
content, collection, and auth **lifecycle events**, owns its own privacy-minimized facts (raw facts
+ daily rollups + distinct-actor presence), and exposes a gated admin read API — packaged as a
**removable capability pack** that depends only on the framework and `glueful/lemma-contracts`.

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
  under a strict token/PII allow-list (never reads token accessors; failed logins are count-only). An
  App-side **bridge listener** maps the events the pack can't depend on — `lemma-collections`
  `Collection*`/`CollectionRow*` and content `Entry*` events — into the recorder (the audit-listener
  pattern).
- **Read API** — `GET /v1/admin/analytics/series` (zero-filled daily time-series for a metric,
  optionally by subject), `GET /v1/admin/analytics/summary` (KPI totals + distinct active users
  over a range), and `GET /v1/admin/analytics/breakdown` (top subjects for one event over a range),
  behind `auth` + `lemma_permission:analytics.read`.
- **Retention** — `./lemma analytics:prune` deletes raw `analytics_facts` past
  `analytics.retention_days` (default 90); the rollups and the distinct-actor table are never pruned.

## The capability

The provider registers a single capability in `boot()`:

```php
new Capability('lemma.analytics', label: 'Analytics', description: '…');
```

- **Enabled by default.** Disable it by setting `'lemma.analytics' => false` in `config/lemma.php`'s
  `capabilities` switchboard.
- **Gated end-to-end.** When disabled, the read API routes are never registered (`404`) **and all
  ingestion stops** — the auth listeners and the App bridge only subscribe when the capability is
  enabled, so no facts are recorded. Migrations run on install (not enable), so disabling preserves
  the tables.
- **Permission.** The pack declares `analytics.read`; the host app grants it to `administrator` in its
  own dependent migration.

## Privacy

The forever-kept `analytics_active_actors` table holds no identity — only a per-instance one-way
HMAC (`actor_id_hash = hmac_sha256(actor_id, ANALYTICS_HASH_KEY | APP_KEY)`), which preserves
uniqueness for counting without being reversible to a user. Raw `actor_id` exists only in
`analytics_facts` and is removed at the retention prune. Auth facts never carry token material, and
failed logins record no attempted username.

## Boundary

Depends on `glueful/lemma-contracts` and `glueful/framework` — and **never** on `glueful/lemma` (the
application), the audit extension, or `glueful/lemma-collections`. The collection/content event bridge
lives App-side (`app/Analytics/`) precisely so the pack stays dependency-pure; the repo's
`composer boundaries` check enforces this (no `App\` references in `src/`).

## Install

The pack is **bundled by default** in the Lemma create-project template. To add it to an existing app
(it lives as a path package in this monorepo):

1. `composer require glueful/lemma-analytics`
2. `./lemma extensions:enable lemma-analytics` (writes the provider into the
   `config/extensions.php` allow-list and recompiles the extension cache)
3. `./lemma migrate:run` to create the tables.

Optionally set `ANALYTICS_HASH_KEY` (falls back to `APP_KEY`) and `ANALYTICS_RETENTION_DAYS`.

## Remove

`./lemma extensions:disable lemma-analytics`, then `composer remove glueful/lemma-analytics`. The CMS
core boots unchanged. The `lemma.analytics` capability disappears from `GET /v1/admin/capabilities`,
so the read API is gone and ingestion stops. The analytics tables remain on disk (drop them manually
if you want the data gone).

## Out of scope

The admin-SPA analytics **dashboard** (charts over this data) is a separate concern — this pack ships
the backend fact store + read API. HTTP/ops metrics stay with the framework `metrics` capability.
