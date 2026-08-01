# Thallo Approach Notes

Thallo is a standalone content platform built on Glueful. It should feel like its own product, not just a framework extension, while still taking full advantage of Glueful's core and extension ecosystem.

## Product Direction

Thallo is a hybrid CMS:

- headless when teams want structured content delivered through APIs;
- rendered when teams want the CMS to serve websites and pages directly;
- structured enough for serious content modeling;
- practical enough for websites, ecommerce content, marketing pages, docs, and app content.

The core idea is:

> The canonical source for your content. Model it once with flexible schemas, manage it with real editorial workflows, and deliver it anywhere, rendered directly or through headless APIs.

The word "canonical" matters. Thallo should be the source of truth for content entries, fields, relationships, media, workflow state, and delivery behavior.

## Name And Brand

The product name is Thallo.

Reasons it fits:

- short and easy to pronounce;
- connected to language and meaning;
- a thallo is the canonical form of a word, which maps well to a CMS as the canonical source of content;
- it avoids forcing Glueful into the product name, even though Glueful powers the implementation.

Domain:

- `thallo.dev`

## Technical Foundation

Thallo should be built as a Glueful application/product, not as a separate framework — and it starts from `glueful/api-skeleton`, the same quick-start path the framework website documents for new users. This is deliberate dogfooding: building a real product through the advertised onboarding surfaces the actual developer experience (scaffolding, capabilities switchboard, extension enablement, migrations). DX friction discovered along the way gets filed against the skeleton/framework and fixed there, not silently worked around in Thallo.

Glueful core already covers most of the infrastructure layer Thallo needs:

- routing and controllers;
- validation and DTOs;
- ORM/query builder access;
- migrations;
- auth/security primitives;
- extension loading;
- queue primitives;
- scheduler primitives;
- upload/blob storage;
- webhook subscriptions, delivery tracking, signing, retries, delivery jobs, and webhook docs support;
- OpenAPI generation, docs routes, Scalar/Swagger/Redoc UI, extension doc merging, and SDK client generation;
- basic audit/activity logging through `activity_logs`, database log handling, auth/security event logging, and audit context helpers.

Thallo should provide:

- content model builder;
- entries with version history (published revisions);
- publishing workflow;
- preview and rendering behavior;
- headless delivery APIs;
- admin/editor UI;
- CMS-specific asset organization;
- content relationships, localization, and CMS-specific permission behavior.

Most important takeaway: Glueful already covers the platform infrastructure well. Thallo should focus on the content domain, then integrate with Glueful core and existing extensions instead of rebuilding storage, webhooks, scheduler, OpenAPI, or basic audit logging.

## Database

PostgreSQL should be the primary database target.

Reasons:

- strong relational modeling for content types, entries, revisions, users, workflows, and permissions;
- JSONB support for flexible schema payloads where useful;
- good indexing for search/filter workloads;
- mature transaction semantics for publishing and revision flows;
- good fit for future multi-tenant and ecommerce workloads.

The schema should avoid becoming a pure JSON document store. Use relational tables for durable system concepts, and use JSONB selectively for flexible content field values or schema metadata.

## Storage And Media

Thallo should consume Glueful's core upload/blob system instead of building its own storage layer.

Glueful core already has:

- `StorageManager`;
- Flysystem disks;
- local and memory disk support;
- configured S3/Azure/GCS drivers when the relevant adapters are installed;
- blob metadata table;
- blob routes;
- signed URLs;
- upload handling.

Recommended direction:

- store media references as blob UUIDs;
- use a Thallo media disk setting, likely `thallo.media_disk`, that maps to a Glueful storage disk;
- rely on `glueful/media` for image transforms, thumbnails, and metadata;
- rely on `glueful/cdn` for CDN purge/invalidation;
- rely on future storage provider factories for S3, R2, GCS, Azure, SFTP, or other disks.

Storage provider packs are convenience and packaging improvements, not a reason for Thallo to own storage. They should not duplicate uploads, blob schema, blob routes, or media processing.

## Already Available As Extensions

Useful existing extensions:

- `glueful/users` - identity/auth and user store.
- `glueful/entrada` - built: OAuth/OIDC social sign-in (Google, Facebook, GitHub, Apple with JWKS-verified ID tokens; web redirect + native mobile flows, `state` CSRF protection, verified-email-gated account linking, auto-registration into the users store). Thallo fit: optional "sign in with ..." on the admin login — purely additive, since entrada flows terminate in the same `glueful/users` JWT session the admin SPA already uses; the token-storage posture below is unchanged. Enterprise IdP SSO (Okta/Entra via generic OIDC) is not covered by its current provider list — a future entrada capability to track if Thallo targets enterprise editorial teams.
- `glueful/aegis` - permissions/security product surface.
- `glueful/tenancy` - built: shared-database, row-level multi-tenancy (`tenant_uuid` columns auto-scoped via a `BelongsToTenant` ORM trait + raw-SQL interceptor backstop, tenant propagation into jobs/CLI/scheduler; requires framework ^1.53.0 seams). For future multi-tenant CMS and agency/customer installs — Thallo v1 deliberately does **not** carry tenant columns; see V1_DESIGN.md §10 for the bounded retrofit path and why this differs from the locale decision.
- `glueful/media` - rich media processing.
- `glueful/cdn` - CDN and edge cache purge.
- Search extensions - bind Thallo's `ContentReindexerInterface` to reindex published content
  (for example a future `glueful/meilisearch` adapter).
- `glueful/queue-ops` - queue supervision and worker operations.
- `glueful/email-notification` - email delivery.
- `glueful/notiva` - notifications.
- `glueful/conversa` - SMS & WhatsApp notification channels (Twilio, Meta WhatsApp Cloud drivers, message log, delivery tracking) — relevant if Thallo ever sends editorial/workflow notifications over SMS/WhatsApp, not a conversations/messaging product.
- `glueful/payvia`, `glueful/commerce`, `glueful/subscriptions` - ecommerce, paid membership, plans, gated content, and commerce-related workflows.
- `glueful/archive` - archive/lifecycle use cases.
- `glueful/runiva` - operational/background execution use cases.

Thallo should integrate with these where they solve a real CMS need, but not require all of them for a basic install.

## Do Not Rebuild As Extensions

These are already covered well enough in framework core and should not be treated as separate required extensions for Thallo:

- storage/uploads, except provider-pack convenience packages;
- webhooks;
- scheduler;
- OpenAPI/docs;
- basic audit/activity logging.

## Good For The Glueful Ecosystem

These would benefit many Glueful apps, including Thallo, but they should be filtered by boundary and demand. First-party extensions should be primitives that integrate with core seams, not full product surfaces that compete with dedicated tools.

Near-term first-party primitives — **all four are already built** (the open question per package is release/pinning status, not existence):

- Storage provider packs: built — `glueful/storage-s3` (incl. R2, MinIO, Spaces, Wasabi as S3-compatible targets), `glueful/storage-gcs`, `glueful/storage-azure`, each registering a driver factory on the core storage seam. SFTP later.
- Feature flags: built — `glueful/flags` ships flag definitions, targeting rules, deterministic percentage rollouts, management API/CLI, audit rows, and lifecycle events behind a checker contract. Explicitly a rollout switchboard, not access control or billing.
- Localization/i18n: built — `glueful/i18n` ships a locale registry with single-parent fallback chains, a request locale resolver, persisted translation catalogs, pluralization, missing-key tracking, and a management API/CLI. Thallo content localization consumes its contracts (see V1_DESIGN.md §3) rather than rebuilding any of it.
- Import/export engine: built — `glueful/import-export` ships job/batch/error/report persistence, queue-backed deterministic batches, dry-run/commit modes, engine-owned retry, streaming CSV/JSON/NDJSON/ZIP-bundle readers and writers, lifecycle events, and HTTP/CLI job management. Thallo owns only the adapters (see V1_DESIGN.md §9 and ADAPTER_NOTES.md).

Demand-gated primitives:

- Backup: disaster-recovery workflows such as database dumps, blob/storage backup, encrypted archives, and restore. Keep this distinct from `glueful/archive`, which is lifecycle/cold-storage behavior.
- Advanced audit: immutable audit trails, diffs, retention, tamper checks, admin UI, and export. Core logging is enough for basic audit; build this only when a compliance-grade consumer exists.

Split or defer:

- Analytics/events should be split. A first-party event stream/capture extension is reasonable: emit, store, forward to sinks, and export. Funnels, dashboards, and product analytics UI should be deferred or left to integrations such as PostHog-style tools.

Boundary notes:

- `backup` and `archive` are different: backup is disaster recovery; archive is lifecycle/cold storage.
- `advanced audit` and core `activity_logs` are different: audit is compliance-grade history; activity logs are operational history.
- `import-export` and Thallo importers are different: import/export is the engine; Thallo owns CMS-specific adapters like WordPress, Markdown/MDX, and CSV-to-entry mapping.
- `flags` and entitlements are different: flags decide rollout/audience exposure; entitlements decide whether a tenant/account is allowed to use a commercial capability.

## Thallo-Specific Domain

These should be Thallo core or Thallo-owned modules/extensions:

- content models and fields;
- entries and version history (published revisions);
- draft/publish workflow;
- review/approval workflow;
- preview system;
- block/page builder;
- taxonomies and collections;
- public content delivery API;
- admin/editor API;
- content routing/rendering;
- SEO metadata, redirects, sitemap;
- publishing pipeline integrating core webhooks, scheduler, CDN, search, and queues;
- content migrations/importers for WordPress, Markdown/MDX, and CSV;
- localization for content: backend/API support is in v1 via `glueful/i18n` locale validation,
  per-locale drafts/publishing/routes, locale-variant status, copy-to-locale drafts, and
  single-entry delivery fallback; the first-party admin UI still needs the visual locale workflow;
- forms;
- navigation/menu builder;
- ecommerce content integration;
- personalization/segmentation later.

## Admin Interface

Thallo should ship with a first-party admin/editor UI so the product is usable out of the box. The default admin should cover content modeling, entries, version history, publishing, preview, media references, permissions, and the common editorial workflows expected from a CMS.

The admin UI should still be treated as a replaceable client of Thallo's admin/editor API. The core product contract is the content domain plus stable APIs; the bundled UI is the default experience, not a hard dependency. Teams that need a custom editorial surface should be able to disable or bypass the default admin and build against the same API.

Recommended admin stack:

- Vue 3 with Vite;
- Nuxt UI's Vue/Vite integration, not Nuxt as the application framework;
- Vue Router for admin navigation;
- Pinia or composables for admin state;
- a typed API client generated from Thallo's OpenAPI/admin API contract where practical.

The admin source should live as a frontend app inside the Thallo development repository, for example under `admin/`, but it should not be part of the normal runtime/download artifact that users install to run Thallo. Production/runtime packages should contain only the compiled admin assets, published to a public asset location such as `public/admin` or another framework-managed admin asset directory.

Packaging rule (concrete):

- the **source repo** contains `admin/` (SPA source), built by contributors and CI;
- the **release artifact** contains `public/admin/` (compiled assets) and nothing else of the frontend: `admin/`, `node_modules/`, and frontend build tooling (`package.json`, lockfiles, Vite config) are excluded from the distributed package — enforced via `.gitattributes` `export-ignore` (Composer dist installs) and the release build script, not by convention;
- CI builds the admin and commits/attaches the compiled `public/admin/` output as part of cutting a release, so a runtime install never needs Node;
- Glueful/Thallo serves `/admin` and `/admin/*` from the built asset output;
- API calls go through Thallo's admin/editor API;
- custom admin clients can disable or replace the bundled asset mount without changing the backend content engine.

## Core Storage Seam (Shipped)

The storage registry work this section originally proposed has landed in Glueful core:

- `FileUploader` records the actual effective upload disk in `blobs.storage_type`, so explicit per-request storage drivers are not overwritten by `uploads.disk`;
- `StorageDriverFactoryInterface`, `StorageDriverRegistryInterface`, `NativeSignedUrlProviderInterface`, and `StorageHealthCheckInterface` exist under `src/Storage/Contracts/` and are consumed by core (e.g. `UploadController`).

Storage provider packs (`glueful/storage-s3`, `storage-gcs`, `storage-azure`) build on that seam. They are useful for Glueful and Thallo, but Thallo itself should continue to consume the core blob/storage abstraction.

## Delivery, Auth, And Positioning Notes

- **Delivery API auth:** Glueful core's `api_keys` system (scopes, IP allowlists, expiration, rotation grace, `gf_live_*`/`gf_test_*` environment prefixes) is the delivery-token mechanism. Thallo should not build its own token store; it defines content scopes on top (`read:content`, `read:content:{type}`) and supports anonymous reads only for content types that explicitly opt into `public_delivery`.
- **GraphQL:** deliberate position — Thallo ships REST with Glueful's GraphQL-style field selection (`?fields=entry(title,author(name))`) and expansion instead of a GraphQL endpoint. This answers the sparse-fieldset/nested-fetch need without a second query engine. Revisit only on real demand.
- **Content portability:** "canonical source" implies content can leave. A full export (content models + entries + asset manifest) ships v1-adjacent as a CLI command and produces a re-importable bundle. This is a trust requirement and doubles as the content-level backup story.
- **Admin auth:** the bundled admin SPA authenticates against the same auth as the admin API (JWT bearer via `glueful/users`). Token storage posture, stated explicitly: the **access token lives in memory only** (never `localStorage`/`sessionStorage` — an XSS must not be able to exfiltrate a reusable credential), short-lived; the **refresh token lives in an `httpOnly` `SameSite=Strict` cookie path-scoped to the refresh endpoint**. That cookie reintroduces exactly one narrow CSRF surface (the refresh call itself), accepted because refresh-token rotation with reuse detection bounds it and the alternative (refresh token readable by JS) is strictly worse. All other admin API calls are pure bearer — no cookie, no CSRF surface. The typed API client carries the bearer token and handles the refresh dance. Social sign-in via `glueful/entrada` (when enabled) is just another way to establish the same session — the provider buttons on the login screen are driven by which entrada providers are configured, and the token posture is identical from that point on.
- **License/distribution:** open question — open-source core vs source-available vs commercial tiers is undecided. The entitlements boundary note above assumes commercial capabilities will exist. This decision shapes the repo split, the runtime-package rule, and the upgrade story; it should be made before the first public release, not before development starts.

## Initial Product Shape

The first usable Thallo version is **headless-first**: prove the content model, editorial workflow, and delivery API before building rendered delivery. Rendered pages require a template/theme layer that Glueful core deliberately does not have (it is an API framework), so "rendered" is its own phase with its own design — v1 keeps only the preview system, which serves drafts to a frontend rather than rendering them itself.

V1 focus:

1. content types and fields;
2. entries with draft/published state;
3. version history (immutable versions written at publish — see V1_DESIGN.md §2);
4. media attachment through Glueful blobs;
5. headless read APIs (with field selection, filtering, pagination, per-type
   Cache-Control, per-type API-key scopes, and public opt-in);
6. preview system (draft access for frontends via short-lived tokens);
7. PostgreSQL migrations;
8. user roles and permissions (coarse roles in v1);
9. admin/editor UI;
10. OpenAPI docs;
11. content import/export adapters over `glueful/import-export`;
12. backend content localization over `glueful/i18n`.

Rendered pages, the block/page builder, approval workflows, localization UI, forms, navigation, and native multi-tenant content partitioning are post-v1. (SEO modules and scheduled publish were also deferred past V1 and have since shipped — see [POST_V1.md](POST_V1.md).) The backend already carries the locale dimension and i18n-backed locale workflow; the deferred localization item is the editor-facing UI polish, not the content API/storage model.

It should integrate with core webhooks, scheduler, OpenAPI, storage, queues, CDN, and search from the start where useful. Avoid overbuilding early: the product should prove the content model, editorial workflow, and delivery model first.

Technical decisions for v1 (field storage, published read path, locale dimension, caching/invalidation contract, event taxonomy) are made in [V1_DESIGN.md](V1_DESIGN.md).
