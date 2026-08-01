# Thallo

**The canonical source for your content.** Model it once with flexible schemas, manage it with
real editorial workflows, and deliver it anywhere — through headless APIs today, rendered
pages later.

Thallo is a hybrid CMS built as a [Glueful](https://github.com/glueful/framework) application
(it starts from `glueful/api-skeleton` and dogfoods the framework's onboarding path). It owns
the **content domain** — content models, entries, versioning, publishing, delivery — and
integrates with Glueful core + extensions for the platform layer (storage, queue, scheduler,
webhooks, OpenAPI, auth).

> **Status:** the **V1 headless backend is complete**, plus a post‑V1 batch (scheduled publish,
> destructive‑schema backfill, version pruning, SEO/routing, field‑localization, per‑locale
> RBAC) has shipped. The first‑party **admin UI** and **rendered page delivery** are the next
> phases — see [docs/internal/NEXT.md](docs/internal/NEXT.md). This is pre‑release software.

---

## What it does

- **Content modeling** — content types with JSONB field schemas (`string`/`text`/`number`/
  `boolean`/`datetime`/`enum`/`reference`/`asset`/`json`), filterable fields with Postgres
  expression indexes, and field‑append‑only evolution (with a destructive‑change backfill path).
- **Drafts → versions → publication** — one mutable draft per `(entry, locale)` with optimistic
  locking; immutable version snapshots written *at publish*; a single published pin per
  entry+locale. Rollback re‑pins any prior version.
- **Headless delivery API** — `GET /v1/content/{type}/{slugOrUuid}` with GraphQL‑style field
  selection (`?fields=…&expand=…`), filtering, pagination, per‑type `Cache-Control`, ETags, and
  per‑type API‑key scopes (`read:content`, `read:content:{type}`) with public opt‑in.
- **Preview** — short‑lived HMAC‑signed tokens give a frontend draft (or a specific historical
  version) access without exposing unpublished content publicly.
- **Localization** — locale‑aware drafts/versions/routes over `glueful/i18n` (fallback chains,
  locale‑variant status), plus flag‑aware copy‑on‑create for non‑localized fields.
- **Publishing pipeline** — a frozen PSR‑14 content‑event taxonomy driving cache invalidation,
  webhooks, and search reindex; **scheduled** publish/unpublish at a future time.
- **SEO / routing** — auto‑captured + manual redirects (301/302/308, chain‑free), plus
  canonical / hreflang metadata on delivery.
- **Permissions** — coarse Thallo RBAC over `glueful/aegis`, with optional **per‑locale** scoping
  via Aegis resource filters (see [docs/internal/PER_LOCALE_RBAC.md](docs/internal/PER_LOCALE_RBAC.md)).
- **Portability** — content‑model + entry + asset‑manifest export/import adapters over
  `glueful/import-export` (see [docs/internal/ADAPTER_NOTES.md](docs/internal/ADAPTER_NOTES.md)); configurable
  version pruning with the export bundle as the safety net.

## Requirements

- **PHP 8.3+**
- **PostgreSQL** — required, not optional. Thallo relies on JSONB, expression indexes,
  `FOR UPDATE SKIP LOCKED`, partial‑unique indexes, and `CHECK` constraints throughout.
- Composer

## Quick start

```bash
composer install

# Configure the environment (PostgreSQL connection + secrets)
cp .env.example .env
#  set DB_DRIVER=pgsql and the DB_PGSQL_* connection vars
composer key:generate               # APP_KEY (encryption) + JWT_KEY

# Apply the schema (core auth/queue/scheduler + Thallo content engine + extensions)
php glueful migrate:run

# Run it
php glueful serve
```

Key endpoints once running:

| Surface | Route | Notes |
|---------|-------|-------|
| Delivery (public, read‑only) | `GET /v1/content/{type}/{slugOrUuid}` | API‑key scoped; anonymous only for `public_delivery` types |
| Admin / editor API | `…/v1/admin/*` | content types, entries, drafts, versions, publish, routes, schedules, redirects, migrations |
| Preview | `GET /v1/preview/{token}` | unauthenticated by design — the signed token is the capability |
| API docs | `/docs` | when `API_DOCS_ENABLED=true` |

## Project layout

```
app/Content/            # the content engine (Thallo's domain)
  Schema/               # field definitions, content-type schema, migration op model
  Repositories/         # entries, drafts, versions, publications, routes, references, schedules
  Services/             # PublishService (publish/unpublish/rollback), MigrationService, …
  Http/Controllers/     # admin + delivery controllers
  Delivery/             # published read path, field projection, ETags
  Scheduling/ Backfill/ Seo/ Retention/ Localization/ Indexing/   # feature modules
  Console/ Jobs/        # CLI commands + queue jobs
config/                 # thallo.php (+ schedule.php, queue.php, …)
database/migrations/    # content-engine schema (001 → 012)
routes/                 # admin.php, content.php, preview.php
tests/                  # Unit / Integration / Feature (PostgreSQL via AppTestCase)
docs/                   # design + product docs (see below)
```

## Documentation

| Doc | What it is |
|-----|-----------|
| [docs/internal/APPROACH.md](docs/internal/APPROACH.md) | Product vision, positioning, and ecosystem boundaries |
| [docs/internal/V1_DESIGN.md](docs/internal/V1_DESIGN.md) | V1's expensive‑to‑reverse architecture decisions |
| [docs/internal/POST_V1.md](docs/internal/POST_V1.md) | The (now‑closed) post‑V1 backlog — all six features shipped |
| [docs/internal/NEXT.md](docs/internal/NEXT.md) | Forward‑work index: what's next and where it's tracked |
| [docs/internal/PER_LOCALE_RBAC.md](docs/internal/PER_LOCALE_RBAC.md) | Operator recipe for locale‑scoped permissions |
| [docs/internal/ADAPTER_NOTES.md](docs/internal/ADAPTER_NOTES.md) | Import/export adapter notes |
| `docs/internal/superpowers/specs/` · `plans/` | Per‑feature design specs and implementation plans |

## Testing

```bash
composer test            # full suite (PostgreSQL)
composer test:unit
composer test:integration
composer ci              # phpcs + tests
```

Tests run against a PostgreSQL test database (`*_test`); `composer test` resets + migrates it
before running. See `CLAUDE.md` for the full developer workflow and conventions.

## Built on Glueful

Thallo deliberately does **not** rebuild platform infrastructure. Storage/uploads, webhooks,
scheduler, OpenAPI/docs, queue, and basic audit logging come from Glueful core and its
extensions (`glueful/users`, `glueful/aegis`, `glueful/i18n`, `glueful/import-export`,
`glueful/media`, `glueful/cdn`, …). Thallo focuses on the content domain and integrates with the
rest.
