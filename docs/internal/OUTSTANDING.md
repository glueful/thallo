# Outstanding Features — Thallo tracker

> A **living checklist** of what's left to build. Unlike [NEXT.md](NEXT.md) (a stable
> pointer page), this file is meant to be edited as work lands — tick items off, link the
> spec/plan/commit, and move them to **Recently shipped**.

**Last reconciled:** 2026-07-12 (against the working tree, not just `NEXT.md`).

## How to use this file

- Each item is a checkbox. When it ships:
  1. Change `- [ ]` → `- [x]`.
  2. Append `— shipped YYYY-MM-DD` and a link to the spec/plan (or commit).
  3. Cut it from its section and paste it under **Recently shipped** (newest first).
- **Size** is a rough estimate: `S` (a sliver, days), `M` (a feature, ~1–2 weeks), `L` (a track, multiple specs).
- **Home** links the doc where the work is already designed or tracked; if blank, no design exists yet — start with the brainstorm → spec → plan → implement loop.
- Keep this list reconciled with reality: if you discover something here already shipped, tick it and note the date.

Legend: **Size** S/M/L · **Home** = existing spec/doc, or _"(no design yet)"_.

---

## A. Large tracks (named in the vision)

- [ ] **Ecommerce content integration** — `L` — **Home:** [APPROACH.md](APPROACH.md). _No design yet._
- [ ] **Personalization / segmentation** — `L` — **Home:** [APPROACH.md](APPROACH.md). _No design yet._

## B. Per-feature follow-ups (deferred slivers on shipped features)

- [ ] **Schema migrations: `retype` ops** — `S` — only `delete` + `rename` shipped. **Home:** [destructive-schema-backfill spec](superpowers/specs/2026-06-16-destructive-schema-backfill-design.md).
- [ ] **Field localization: copy-on-change sync** — `S` — sync non-localized fields across locales on change. **Home:** [field-localization spec](superpowers/specs/2026-06-16-field-localization-design.md).
- [ ] **Version pruning: scheduled + export-before-prune interlock** — `S` — manual pruning shipped. **Home:** [version-pruning spec](superpowers/specs/2026-06-16-version-pruning-design.md).
- [ ] **Per-locale RBAC: per-content-type scoping** — `M` — extend the same Aegis mechanism to content-type scope. **Home:** [per-locale-rbac spec](superpowers/specs/2026-06-16-per-locale-rbac-design.md).
- [ ] **Scheduled publish: auto-retry, recurring, failure notifications** — `M` — **Home:** [scheduled-publish spec](superpowers/specs/2026-06-16-scheduled-publish-design.md).
- [ ] **SEO: redirect import/export** — `S` — pack ships sitemaps/meta/robots; import/export not built. **Home:** [seo-routing-module spec](superpowers/specs/2026-06-16-seo-routing-module-design.md).
- [ ] **SEO: `thallo:seo:check` + `redirects:prune` commands** — `S` — not built. **Home:** same spec as above.
- [ ] **Multi-workspace setup: admin-settable resolution hosts** — `M` — base domain + default hosts are `config/tenancy.php`/`.env`-only today, so activating full resolution requires a hand-edited `.env` + server restart the admin UI never surfaces (the Resolution → Activate button 422s on "At least one default tenant host must be configured"). Make them persisted admin settings so the flow is UI-driven — enable → set base domain/hosts → activate — with at most one "reload to finish" prompt instead of editing `.env` and guessing when to bounce the server. Surfaced by dogfooding on thallodev.dev 2026-07-11. **Home:** _(no design yet)_ — follow-up on shipped multi-tenancy.

- [ ] **Slider: caption overlay on bare image slides** — `S` — hero slides already carry
  full text-over-image (scrim, on-media ink, buttons); this is the LIGHTWEIGHT middle
  option — an image slide's `caption` styled as a small overlay (bottom-left over a
  subtle gradient) inside hero-style sliders, instead of the below-image caption.
  Deliberately kept out of the 2026-08 slider v1 (transitions/heights/duration arc) to
  watch whether authors reach for hero slides first. **Home:** _(no design yet)_ —
  follow-up on the modern-blocks carousel.

## C. Forms follow-ups (form block v1 deferrals)

**Home for all:** [form-block spec §14](superpowers/specs/2026-07-09-form-block-design.md).

- [ ] **Field-builder UI** — `M` — editor to add/remove/reorder arbitrary fields, editing the same normalized model the sealed descriptor already uses (additive, not a rewrite). _Biggest seam._
- [ ] **Forms registry (Approach B)** — `M` — first-class `forms` table + projector for per-form dashboards/analytics; submissions already carry `form_key`/`form_name` so no data is stranded.
- [ ] **CAPTCHA guard provider** — `S` — a new `FormSubmissionGuard` implementation behind the existing seam.
- [ ] **File-upload fields** — `M`.
- [ ] **Multi-step forms** — `M`.
- [ ] **Scheduled digests / CRM / webhook delivery** — `M`.

## D. Importer depth (adapters shipped; these extend them)

**Home for all:** [ADAPTER_NOTES.md](ADAPTER_NOTES.md).

- [ ] **WordPress: media / attachments** — `M`.
- [ ] **WordPress: authors** — `S`.
- [ ] **WordPress: categories / tags** — `S` — now unblocked (model as a terms content type + multi-valued reference).
- [ ] **WordPress: custom post types** — `M`.
- [ ] **WordPress: post-meta** — `S`.
- [ ] **WordPress: upsert-by-WP-id (re-import idempotency)** — `S` — v1 is create-only.
- [ ] **CSV / Markdown: upsert-by-key** — `S` — both create-only today.

## E. Admin SPA

- [ ] **Polish pass** — `S` — no net-new surfaces outstanding; incremental UX refinement only. **Home:** `docs/superpowers/plans/*admin-spa*`.

---

## Recently shipped

> Move ticked items here (newest first) with their ship date + spec link, so the sections
> above stay focused on what's left.

- [x] **Multi-tenancy** — shipped 2026-07-11–12 — the full arc, far beyond the original additive-retrofit line: SP1 foundation (`tenant_uuid` retrofit, widened uniques, `BelongsToTenant`, raw-query scoping) → SP2 resolution + tenant management → SP3 membership×RBAC → **Bucket 1** lifecycle gaps (workspace-manager role, two-phase deletion + host-cooldown, background domain re-verification) → **Bucket 2** (collections tenancy, per-tenant roles / matrix overrides, public self-serve signup) → the control-plane/enforcement **provider split** shipped as `glueful/tenancy` **2.0.0**. Index: [LIFECYCLE-GAPS-README](superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md); specs/plans under `superpowers/{specs,plans}/multi-tenancy/`. **Loose ends (housekeeping, not feature gaps):** public-signup (2B) is implemented + verified but **HELD/uncommitted** along with the admin-settings polish; one tenancy-on `BlobPublicUrlProviderTest` failure remains unreproduced (passes in every isolated config) pending the exact suite-invocation command.
- [x] **Form block** — shipped 2026-07-09 — generic `form` block (contact preset): sealed-descriptor model, stored + best-effort-emailed submissions, spam guard chain, admin Submissions area + CSV, delivery mode (store+email / email-only), selectable submit-button style. [spec](superpowers/specs/2026-07-09-form-block-design.md) · [plan](superpowers/plans/2026-07-09-form-block.md).
- [x] **Rendered delivery (V2)** — shipped 2026-07-02/03 — navigation pack, render core, render caching, listing/archive pages, preview-through-theme + preview sessions, term index pages, DB-edited templates, page/block builder. [V2_DESIGN.md](V2_DESIGN.md).
- [x] **Capability packs** — seo, collections, search, analytics, workflow, navigation, importers — built as removable packs on the composable-core seams.
- [x] **POST-V1 batch** — shipped 2026-06-17 — destructive-schema backfill, field localization, version pruning, per-locale RBAC, SEO/routing, scheduled publish. [POST_V1.md](POST_V1.md).
