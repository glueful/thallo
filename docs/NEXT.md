# What's Next — Lemma forward-work index

> A **pointer page**, not a design doc or a backlog. It records that the POST‑V1 backlog is
> closed and links each remaining thread to where it is *already* documented, so nothing has
> to be rediscovered. No work is sketched or decided here — follow the links.

**POST‑V1 status:** ✅ **closed** — all six deferred features shipped (2026‑06‑17). See
[POST_V1.md](POST_V1.md). The product lineage is
[APPROACH.md](APPROACH.md) (vision) → [V1_DESIGN.md](V1_DESIGN.md) (V1 architecture
decisions); the next phases derive from the same APPROACH.

**Shipped since (2026‑06):**
- **Admin SPA** — the Vue 3 + Vite + Nuxt UI editor UI is built out: content list + entry
  editor (rich text, references, versions, redirects), media, users (+ bulk CSV import), roles/
  permissions, webhooks, API keys, audit log, extensions, settings, utilities, auth/setup flows.
  Remaining SPA work is polish, not net-new surfaces. Tracked in `docs/superpowers/plans/*admin-spa*`.
- **Content importers** — `csv.content`, `markdown.content`, `wordpress.content` (WXR, v1: posts/
  pages → entries) and `csv.users`, over the `glueful/import-export` engine with a mapping wizard.
  Depth follow-ups remain (see below). Tracked in [ADAPTER_NOTES.md](ADAPTER_NOTES.md).
- **Localization UI** — editor locale workflow complete: per-locale publish/draft/scheduled status
  in the switcher, locale-aware versions page, copy-into-existing-locale (overwrite), translation-
  coverage in the entry list, cross-locale route management, bulk create/publish, and a disable-
  locale guard (`GET /v1/admin/locales/{locale}/usage`). Plan: `docs/superpowers/plans/2026-06-27-finish-localization-ui.md`.
- **Multi‑valued + filterable references** — reference fields can hold an array of targets
  (`multiple`/`maxItems`), resolve targets by uuid **or** a configurable slug field
  (`referenceSlugField`), and be filtered at the delivery layer via JSONB array containment over
  the published spine (GIN `jsonb_path_ops` expression index, auto-planned per content type).
  Admin: `MultiReferencePicker`. This is the **taxonomy enabler** — categories/tags now model as
  content-type-as-terms + a reference filter. Spec:
  `docs/superpowers/specs/2026-06-27-multivalued-filterable-references-design.md`; plan:
  `docs/superpowers/plans/2026-06-27-multivalued-filterable-references.md`.

---

## Composable‑core: pack vs. core (extraction boundary — settled)

Lemma is a **composable core** — a canonical content engine + `glueful/lemma-contracts` (thin
interfaces/DTOs) + **removable capability packs** that depend only on contracts/framework, never on
`glueful/lemma`. Spec: [composable‑core design](superpowers/specs/2026-06-28-lemma-composable-core-design.md).

- **Shipped extraction:** `glueful/lemma-importers` (the four format adapters: `csv.content`,
  `markdown.content`, `wordpress.content`, `csv.users`) — the proven reference pack. It registers the
  `lemma.importers` capability, writes content only through the `ContentWriter` contract, is backend +
  UI gated, and `composer boundaries` enforces the boundary.
- **Stays core — do NOT re‑litigate as `lemma-seo`:** `app/Content/Seo/` (the routing/addressability
  layer — `RouteResolver`, `PathRenderer`, `CanonicalProjector`, `RedirectRepository`) is **not** an
  extraction candidate. It's woven into three core seams at once: `PathRenderer` backs the
  `LemmaContext::renderPath()` contract that *other packs consume*; route resolution + canonical
  projection are constructor deps of the core `DeliveryController` and are stamped into every delivery
  response (`$item['seo']`); and route assignment is part of the entry authoring lifecycle
  (`EntryController` → `RouteRepository`). Extracting it would invert the dependency (core → pack),
  which the architecture forbids. Public addressability is a core delivery feature of a headless CMS.
- **The remaining packs are NEW BUILDS, not extractions.** A future `lemma-seo` pack is the *additive*
  SEO toolkit (sitemaps, SEO meta‑fields, redirect import/export, `lemma:seo:check`) built on the
  delivery‑reader contract — distinct from the core routing above. Same for **Render / Forms /
  Collections / Search / Analytics**: the contract seams already exist (`ContentDeliveryReader`,
  lifecycle events, `ContentReindexer`, `ContentWriter`), but core holds **no extractable code** for
  them — they're forward feature‑builds against the contracts, picked up via the tracks below.

---

## Large tracks (named in the vision, not yet started)

Each already has a home — there is **no** new doc to write to *track* these:

| Track | Where it lives today | Shape |
|-------|----------------------|-------|
| **Multi‑tenancy** — tenant‑owned content via `glueful/tenancy` | [V1_DESIGN.md](V1_DESIGN.md) §10 "Multi‑tenancy: deliberately not in the v1 schema" — the bounded, additive retrofit path + the guardrails that keep it cheap | Backend retrofit (add `tenant_uuid`, widen unique constraints, `BelongsToTenant` trait) — no row‑identity change |

### Importer depth follow-ups (adapters shipped; these extend them)

Over the built adapters ([ADAPTER_NOTES.md](ADAPTER_NOTES.md)):
- **WordPress depth** — media/attachments, authors, **categories/tags** (now unblocked — model
  them as a terms content type + multi-valued reference fields, shipped above), custom post types,
  post meta, and upsert-by-WP-id (re-import idempotency). v1 is posts/pages only.
- **CSV / Markdown upsert-by-key** — both are create-only today; ADAPTER_NOTES recommends stable
  key-column upserts.

## Per‑feature follow‑ups (each lives in its shipped spec's "Out of scope / follow‑ups")

The six shipped features each deferred a smaller follow‑up; they are tracked *in place*:

| Feature | Deferred follow‑up | Spec |
|---------|--------------------|------|
| Destructive‑schema backfill | **retype** ops (only delete + rename shipped) | [spec](superpowers/specs/2026-06-16-destructive-schema-backfill-design.md) |
| Field‑localization | **copy‑on‑change** sync of non‑localized fields | [spec](superpowers/specs/2026-06-16-field-localization-design.md) |
| Version pruning | **scheduled pruning** + an export‑before‑prune interlock | [spec](superpowers/specs/2026-06-16-version-pruning-design.md) |
| Per‑locale RBAC | **per‑content‑type** scoping (same Aegis mechanism) | [spec](superpowers/specs/2026-06-16-per-locale-rbac-design.md) |
| SEO / routing | **sitemaps**, SEO **meta‑fields** (title/description/OG), redirect **import/export**, `lemma:seo:check`/`redirects:prune` | [spec](superpowers/specs/2026-06-16-seo-routing-module-design.md) |
| Scheduled publish | **auto‑retry** of failed schedules, **recurring** schedules, failure notifications | [spec](superpowers/specs/2026-06-16-scheduled-publish-design.md) |

## Larger product surface still in the vision (no design yet)

These are named in [APPROACH.md](APPROACH.md) §"Lemma‑Specific Domain" / §"Initial Product
Shape" as post‑V1 and have **no** design doc yet:

- **Rendered delivery** — templates/themes/page rendering (the "rendered" half of the hybrid
  CMS). APPROACH calls this *"its own phase with its own design."* ✅ **Design written
  (2026‑07‑02): [V2_DESIGN.md](V2_DESIGN.md)** — all rendered-delivery decisions, the pinned
  render-core scope, and the sub-project sequence live there; sub‑projects 1–3
  (navigation, render core, render caching) shipped 2026‑07‑02 — see "Recommended
  sequencing" below.
- **Block / page builder** — architectural (how blocks compose + persist).
- **Approval / review workflow** — ✅ **shipped** (2026‑07‑02) as the `glueful/lemma-workflow`
  capability pack: single-stage state machine (draft → in_review → approved/changes_requested)
  over draft/publish, `PublishGate` core seam, `workflow.review`/`workflow.bypass` permissions,
  review-queue + editor panel in the admin SPA. Spec:
  `docs/superpowers/specs/2026-07-02-approval-workflow-design.md`.
- **Localization UI** — ✅ **shipped** (2026‑06‑27). The visual locale workflow is complete; see
  the "Shipped since" list above. Remaining localization work is the *field-localization
  copy-on-change* follow-up in the per-feature table below, not editor UX.
- **Taxonomies / collections** — the underlying **reference primitive shipped** (multi-valued +
  filterable references, above), and ✅ the **delivery surface shipped** (2026‑07‑02):
  `GET /v1/content/{type}/facets` + `/{type}/archive/{field}/{term}` over the new
  `published_entry_references` projection (listener‑maintained, `lemma:resync` re‑drivable).
  Spec: `docs/superpowers/specs/2026-07-02-term-archives-facets-design.md`. This unblocks the
  rendered listing/archive follow‑up track in V2_DESIGN.md.
- **Forms** — feature module. **Navigation / menu builder** — ✅ **shipped** (2026‑07‑02) as
  `glueful/lemma-navigation` (V2 sub‑project 1): menu trees with per‑locale labels,
  published‑only resolution via the new `MenuReader` + `EntryTargetResolver` contracts,
  lock_version‑guarded tree writes, admin SPA tree editor.
- **Ecommerce content integration**, **personalization / segmentation** — later, per APPROACH.

---

## Recommended sequencing (opinion, not a commitment)

1. **Rendered delivery** — ✅ design done ([V2_DESIGN.md](V2_DESIGN.md)), ✅ sub‑project 1
   (`lemma-navigation`) and ✅ sub‑project 2 (`lemma-render` core) **shipped** (2026‑07‑02):
   Lemma serves real HTML pages from published content through filesystem Twig themes
   (catch‑all → `PublicRouteResolver`, pack‑embedded default theme + app override).
   ✅ sub‑project 3 (render caching) **shipped** (2026‑07‑02): full‑page cache keyed
   `render:{theme}:{normalizedPath}`, surrogate‑tag invalidation through the existing
   lifecycle/`MenuUpdated` seams, ETag/304, `php glueful render:cache:clear`. Spec:
   `docs/superpowers/specs/2026-07-02-lemma-render-caching-design.md`.
2. **Taxonomies → term‑archives + facets** — ✅ **shipped** (2026‑07‑02): facet counts and
   term‑archive endpoints over the `published_entry_references` projection (spec:
   `docs/superpowers/specs/2026-07-02-term-archives-facets-design.md`).
   ✅ The rendered listing/archive pages track it unblocked also **shipped**
   (2026‑07‑02): `/{type}` listings + `/{type}/{field}/{term}` archives through the
   render catch‑all, opt‑in via `RENDER_LISTING_TYPES`. Spec:
   `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`.
   ✅ **Preview‑through‑theme** also **shipped** (2026‑07‑02): `/_preview/{token}`
   renders drafts through the active theme (uncached, no-store/noindex), with a
   server‑decided `theme_url` on mint + an admin "Preview in theme" action, plus
   `facets()` in Twig. Spec:
   `docs/superpowers/specs/2026-07-02-preview-through-theme-design.md`.
   Preview SESSIONS (full-site nav, listing preview, per-preview themes) shipped
   2026-07-02 (`docs/superpowers/specs/2026-07-02-preview-sessions-design.md`).
   ✅ **Term index pages** also shipped (2026‑07‑02): `/{type}/terms/{field}` with
   counts and archive links. Spec:
   `docs/superpowers/specs/2026-07-02-term-index-pages-design.md`.
3. Everything else (importer depth — incl. now‑unblocked WordPress categories/tags, tenancy, the
   per‑feature follow‑ups) is pull‑based: pick one,
   run the proven loop — brainstorm → spec → plan → implement — starting from the linked home
   above.
