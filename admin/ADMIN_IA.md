# Thallo Admin — Information Architecture

> Reference for the admin SPA navigation. Captures the top-level sections, their
> sub-items, the backend each maps to, and the persona it serves. Phase 1 ships the
> editorial loop (Home + Content); everything else starts as a stub page and fills in
> over later phases.

## Top-level nav

```
Home · Content · Media · Extensions · Users & Access · Developers · Settings · Utilities
```

```
┌────────────────────────────────────────────┐
│  Thallo                                      │
├────────────────────────────────────────────┤
│  Home                                       │  ← default landing (dashboard)
│                                             │
│  CONTENT                                    │  dynamic content types
│    • Pages, Posts, Authors …                │     (GET /v1/admin/content-types)
│  Media                                      │     asset library
│                                             │
│  Extensions                                 │  browse / enable / configure (install → later)
│                                             │
│  Users & Access                             │
│    Users                                    │
│    Roles & Permissions                      │
│    Audit Log                                │
│                                             │
│  Developers                                 │
│    API Reference                            │
│    Documentation                            │
│    API Keys                                 │
│    Webhooks                                 │
│                                             │
│  Settings                                   │
│    Content Types · General · Languages      │
│    Redirects · Email · Import / Export      │
│                                             │
│  Utilities                                  │
│    Scheduled Tasks · Health · Cache         │
│  ─────────────────────────────────         │
│  ◦ you@site            ▾  (profile/logout)  │
└────────────────────────────────────────────┘
```

## Sections

### Home
- **Persona:** all
- **Phase 1:** yes
- Default landing route (`/`) after login: recent/draft entries, quick "create"
  actions, content counts.
- **Welcome / first-run** is *not* a separate page — it's Home's **empty/onboarding
  state** for a fresh install. Because install seeds a **Pages** type (see Content), the
  first-run state is **"create your first page"** (concrete, one click) rather than
  "define a content type" (cold-start) — a working editorial loop on day one. It
  disappears once real content exists. Driven off the same emptiness the dashboard
  already reads, not a dedicated route.

### Content
- **Persona:** editorial
- **Phase 1:** yes (the editorial loop)
- One nav item per **content type**, fetched live from `GET /v1/admin/content-types`
  (Pages, Posts, Authors, …).
- Click a type → `/entries/<type>` → click a row → `/entries/<type>/{uuid}`.
- **Seeded type:** a fresh install ships with one generic **Pages** type
  (`slug: page`, `name: Pages`, schema `title` [string, required] + `body` [text]),
  seeded by `App\Setup\SetupService::install()`. It is an **ordinary content-type row**
  — fully editable, renameable, deletable like any user-defined type, *not* a
  hardcoded/system type — so the "define your own types" model stays intact. Pages alone
  (not Posts) — pages are universal; a blog is opinionated. `public_delivery` is left at
  the secure default (`false`); enable it per type in the type editor.
- **Adding/editing a type** happens in **Settings → Content Types** (the type builder),
  with an inline **"+ New type"** affordance at the bottom of this Content list pointing
  at the same screen. The builder UI is a later phase; Phase 1 only seeds Pages and edits
  its entries.

### Media
- **Persona:** editorial
- Asset library (blobs / uploads). Stub for now.

### Extensions
- **Persona:** site owner (product feature — like WP Plugins / Shopify Apps / Statamic Addons)
- Top-level, **not** an ops/dev concern.
- Ships as **browse + enable/disable + configure** (works on already-installed packages today).
- **Install from UI → later.** Thallo extensions are Composer packages, so one-click
  install isn't WordPress's drop-a-file — it needs a real mechanism (a managed/hosted
  control plane that runs `composer require` + redeploys, or a curated registry + apply
  step). A possible in-UI console to run commands is the highest-privilege surface in the
  app (RCE by design): own owner/developer role, hard auth, audit trail. Design deliberately.

### Users & Access
- **Persona:** admin
- **Users** — `glueful/users`
- **Roles & Permissions** — `aegis` (RBAC)
- **Audit Log** — activity / security record (filed here, not Utilities — it's an
  access record, not a maintenance tool)

### Developers
- **Persona:** integrator (Stripe-style "Developers" section)
- **API Reference** — internal **Scalar** viewer over **Thallo's own `openapi.json`**
  (bundled into the admin build or served from a Thallo-owned route — does **not** depend
  on the framework's `/docs` route or `documentation.enabled`). Gate the menu item on
  whether the spec is reachable.
- **Documentation** — Thallo's own guides (separate from the API reference).
- **API Keys** — delivery keys (create / rotate / revoke); `api_keys` + `ApiKeyService`.
- **Webhooks** — outbound event subscriptions (core `Api\Webhooks`).

### Settings
- **Persona:** admin / site owner
- **Content Types** — the type builder (create/edit content-type schemas). Schema =
  configuration, so it lives here (Directus "Data Model under Settings" pattern); also
  reachable via "+ New type" in the Content nav. Builder UI is a later phase.
- **General** — site name, default locale, base URL (`settings`)
- **Languages** — `glueful/i18n`
- **Redirects** — SEO redirects (endpoints exist)
- **Email** — notifications / sender (`glueful/email-notification`)
- **Import / Export** — content jobs (`glueful/import-export`)

### Utilities
- **Persona:** ops
- **Scheduled Tasks** — queue + publish schedules (`thallo:schedules`, queue)
- **Health** — diagnostics (framework health endpoints)
- **Cache** — status / clear (`cache:status` / `cache:clear`)

### Account menu (corner, not in nav)
- Current user's profile, password, logout (`glueful/users` account).

## Naming rationale

- **Developers** (not "System") — Stripe-standard; the cluster (API keys + webhooks +
  docs + reference) is exactly Stripe's "Developers" section. CMS alternative
  "Integrations" (Ghost) reads oddly over "Documentation", so "Developers" is the
  cleaner umbrella for an API-first/headless CMS.
- **Utilities** (not "System") — Statamic-standard for cache/health/maintenance; "System"
  sounds like infrastructure plumbing. ("Tools", à la WordPress, is the mainstream
  alternative.)
- **Extensions** is a top-level product feature, **not** dev-only — Thallo is a product,
  not a dev-only tool.

## Production / docs note

The framework defaults `documentation.enabled` **off in production** (secure default for a
generic framework; overridable via `API_DOCS_ENABLED`). Thallo's in-SPA **API Reference**
renders its **own** spec, so it's independent of that flag — no framework change needed.
Thallo decides for itself whether to expose its API reference (it should — the API is a
product surface).

## Phase 1 scope

Only **Home** + **Content** (and the editorial loop under it) are Phase 1. All other
sections start as stub/empty pages and fill in over later phases.
