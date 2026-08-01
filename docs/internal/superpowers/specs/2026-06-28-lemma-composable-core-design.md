# Lemma as a Composable Core — Architecture Design

> **Status:** Design (architecture-only). Governs *how Lemma becomes composable*; it does **not**
> design Render, Collections, or Forms — those are separate downstream sub-projects (own spec → plan)
> built *against* this architecture.
>
> **Date:** 2026-06-28

## 1. Purpose & framing

Today Lemma is a single `type: project` create-project app: one `LemmaServiceProvider` wiring DI,
routes, and migrations; one Vite + Vue admin SPA. This design turns Lemma into a **canonical content
core plus composable capability packs**, so a developer can run it as:

- a **headless CMS** (default install), or
- a **rendered site platform** (add a render pack), or
- an **app backend** (add a collections pack),

…all over the **same canonical content**, installing only what they use.

**Guiding rule (settled):**

> **Core owns content truth. Packs own pack-domain truth. Everyone reuses Lemma's schema and
> validation language where it fits.**

The product north-star is dogfooding: it should be a product the author uses across their own
projects even if no one else does, and the extension seams come from *real per-project toggles*, not
imagined market segments.

## 2. Scope

**In scope:** the core/extension boundary, the contract surface, the capability/install model, the
admin-composition mechanism, the versioning policy, the migration path from today's monolith, and a
single **reference pack** that proves the loop end-to-end.

**Out of scope (separate specs later):** Render, Collections, Forms, Search, Analytics, and any
runtime-loaded third-party admin UI ("plugin platform").

**Non-goals:**
- No generic "everything is a record" store. The content engine must not become responsible for
  public write-heavy data, BaaS querying, form-abuse controls, or per-domain retention.
- No browser plugin platform (runtime-loaded third-party Vue bundles) in V1.
- No making the CMS content engine itself removable — the content engine is *always present*.

## 3. Package topology

> **First-party development lives together; distribution remains package-shaped.**

| Package | Composer type | Responsibility |
|---|---|---|
| **`glueful/lemma`** | `project` | Canonical CMS engine + admin shell + default app. *Implements* the contracts. Always present. |
| **`glueful/lemma-contracts`** | `library` | Thin & stable: interfaces, DTO contracts, lifecycle events, admin-contribution descriptor types, small VOs. **No engine logic, storage, or I/O.** Everyone compiles against this. |
| **`glueful/lemma-{render,collections,forms,importers,…}`** | `glueful-extension` | Depend on `lemma-contracts` (plus `glueful/framework` and pack-specific deps) — **never on `glueful/lemma`**; discovered via `extra.glueful`; register through the DI container + admin registry; optionally bundled in the create-project template. |

**Repo strategy.** Monorepo for first-party development: the `glueful/lemma` repo holds the app plus
`packages/lemma-contracts` and first-party pack directories as **Composer path packages**. Composer
package names are correct from day one, so distribution is already package-shaped. **Split-publishing
is introduced only when release automation is ready** — it is *not* a hard V1 requirement and must not
become the project. Until then, first-party packages can be released manually or from their own repos
if needed. Third-party packs are naturally their own repos.

## 4. The contract surface (`lemma-contracts`)

Seven contract groups — exactly the seams a pack needs, no more. Contracts hold **interfaces + DTOs +
events only**. The engine in `glueful/lemma` implements them; a pack sees *only the interfaces* (it
does not depend on `glueful/lemma` at all), so it physically cannot reach engine internals.

1. **Delivery reader** — read **published** content (by type, slug/uuid, with field-selection +
   locale). Read-only projection; never exposes drafts. Consumed by Render, Search, Collections
   (reference targets).
2. **Content writer** — **high-level only**: "create/update draft," "publish." Packs ask core to
   author content; they do **not** get repository-like primitives. This is the sole sanctioned path
   for a pack to land content in core (e.g. Importers), guaranteeing no pack shadows content tables.
3. **Schema / validation / field registry** — `ContentTypeSchema`/`FieldDefinition`/`FieldValidator`
   **abstractions (interfaces) + stable VOs**, plus a **field-type registry** so packs can both reuse
   the modeling language and register custom field types. **Do not export concrete `ContentTypeSchema`
   internals** — concrete schema internals must remain free to evolve behind the interfaces. This is
   what makes Forms & Collections feel native while owning their own storage.
4. **Lifecycle events** — the `BaseContentEvent` hierarchy (`EntryCreated/Updated/Deleted`, publish
   events) promoted to contracts as the stable subscription surface. Search reindexes, Render purges
   cache, Analytics records — all off these.
5. **Capability declaration / discovery** — a `Capability` descriptor (id, requires, provides) + a
   `CapabilityRegistry` core reports from. Backs both `extra.glueful` discovery and admin reporting.
6. **Admin contribution descriptors** — PHP DTOs describing what a pack contributes to admin (nav
   entry, route, settings panel, field-widget). **Metadata only — no Vue runtime code.** The
   `/capabilities` endpoint serializes these so the SPA registry knows what to mount.
   > **Scope refinement (decided during Phase C planning):** these backend descriptors serve the
   > **future runtime-loaded model**, where the SPA doesn't have a pack's code and needs the server
   > to describe what to mount. In the **V1 static model (Phase C)** the Vue module carries its own
   > nav and is **matched by capability id** against the enabled set the endpoint already returns —
   > so the descriptors are **NOT built in Phase C** and are **not** part of the V1 `/capabilities`
   > payload. They land with the runtime model, alongside `registerAdminModule`'s
   > `routes`/`settingsPanels`/`fieldWidgets` runtime fields.
7. **Pack context / scoped service access** — a small `LemmaContext` / `ContentContext` giving packs
   scoped access to allowed core services: current + default locale, actor identity where available,
   URL/path rendering, settings lookup, media/blob reference helpers. The sanctioned alternative to
   packs reaching for global helpers or app internals.

**Candidate addition beyond the base seven (gated by the §7 audit):** a high-level
**`ContentBundleReader` / `ContentBundleWriter`** snapshot-restore pair — for raw export/import of the
full content graph (types, entries, drafts, versions, publications, routes, references, blob
manifests). It is intentionally *not* in the base seven: it is only added if the reference-pack audit
chooses to extract the `lemma.content` snapshot engine (§7). Published-only delivery and high-level
create-draft/publish do **not** cover snapshot/restore.

**Per-pack truth boundaries (the rule, applied):**

| Pack | Reads | Owns (system of record) | Does **not** own |
|---|---|---|---|
| Render | content (delivery reader) | little/none | content truth |
| Importers | — | import-job / staging tables | content (lands via Content writer) |
| Forms | content (optional) | form definitions + submissions | content truth |
| Collections | content (reference targets) | collection rows | content truth |
| Search | content + events | indexes / projections | content truth |
| Analytics | events | derived facts/events | content truth |

## 5. Capability model & discovery

Three distinct layers keep **`composer remove`** and **"disable this pack"** as different operations:

| Layer | Question it answers | Mechanism | "Off" means |
|---|---|---|---|
| **Installed** | Is the package physically present? | Composer-present, discovered via `extra.glueful.provider`. The provider registers `services()`, **migrations** (`loadMigrationsFrom`), and its `Capability` metadata into `CapabilityRegistry`. **Routes, jobs, subscribers, and admin contributions are *not* registered here — only when enabled.** | `composer remove` — code & deps gone. |
| **Enabled** | Should its runtime behavior run? | `config/lemma.php` capability switchboard (mirrors the framework's `capabilities.php`), default-on when installed. | Flag off — code present, capability dormant. |
| **Reported** | Should the admin expose it? | `GET /v1/admin/capabilities` returns the **enabled** capabilities (V1: `id`/`label`/`description`/`requires`; admin descriptors are a future-runtime addition — see §4.6). | Not in payload → SPA doesn't mount the module gated on that id. |

**Gating rule.** **Routes, jobs, subscribers, and admin contributions are gated by *enabled* state.
Migrations are registered when *installed*** (so disabling preserves tables for re-enable, uninstall
safety, and data preservation). **Destructive cleanup is never automatic.**

**Boot ordering.** `lemma-contracts` (no boot) → **core engine binds its contract implementations** →
**packs boot and bind against the contracts** (resolving `ContentWriter`, `ContentDeliveryReader`,
`LemmaContext`, etc. from the container). **Core itself is not a capability** — it is always-on; only
packs are capabilities.

## 6. Admin composition registry

**One registry API, two lifetimes** — only *where registrations come from* changes between V1 and the
future.

```ts
registerAdminModule({
  id: 'forms',
  requires: ['lemma.forms'],   // gated against GET /v1/admin/capabilities
  nav: [...], routes: [...], settingsPanels: [...], fieldWidgets: [...],
})
```

**V1 (static, server-authoritative):**
- Each first-party pack's admin module is **statically imported** from the core admin source into an
  `adminModules` index.
- At boot the shell calls `GET /v1/admin/capabilities`, then the registry **mounts only modules whose
  required capability the server reports as enabled**. A disabled/absent pack's nav is hidden and its
  routes are never added. **No hard-coded sidebar conditionals.**
- **PHP reports the enabled capability *ids*. Vue has first-party screens statically bundled, each
  declaring its `requires` id. The SPA registry matches its static modules' `requires` against the
  server-reported enabled ids and mounts only those.** In V1 the Vue module carries its own nav, so
  the server does **not** send admin descriptors — matching is purely by capability id (see §4.6;
  descriptors are a future-runtime concern). A backend-only third-party pack can ship today and simply
  contributes no screens until admin-UI support exists.
  > **Note (file-based routing reality, Phase C):** this SPA uses `vue-router/vite` file-based routes
  > (`src/pages/**`), so routes always exist — the registry gates **nav visibility** (which modules'
  > nav shows) and **route reachability** (`meta.requiresCapability` in the guard), not route
  > *registration*. The `routes`/`settingsPanels`/`fieldWidgets` fields above are the future-runtime
  > shape; Phase C builds `id` + `requires` + `nav`.

**Future (runtime, Option 3):** the *same* `registerAdminModule` call accepts registrations loaded at
runtime from pack bundles (served via Glueful's existing `serveFrontend()`, which mounts a built
bundle at a literal path), with no change to call sites. The registry API graduates into a thin JS contract package
(`@glueful/lemma-admin-kit`) **only when third-party admin UI is actually built** — not prematurely.
Because V1 already behaves as if modules are externally contributed, this is a stepping stone, not a
rewrite.

> **Why not a runtime plugin platform now:** runtime-loaded third-party Vue bundles imply a shared Vue
> runtime, router injection, versioned JS contracts, dependency + CSS isolation, security posture, and
> broken-extension UX. That is a real plugin platform and must not be built before Lemma itself is
> finished.

## 7. Reference pack & extraction audit gate

The architecture work ships **scaffolding + one reference pack**, selected by a gate.

1. **Audit Importers** (`app/ImportExport`) against the reference-pack qualities below — specifically
   its coupling to content-types/entries/assets/routes, its import/export storage, and the external
   `glueful/import-export` dependency (a useful but potentially noisy first extraction).
2. **If clean** → extract as `glueful/lemma-importers`.
3. **If too coupled** → **do not pick a throwaway demo.** Fall back to the **smallest *real* optional
   module** that still has *all* of: a backend route/service, an admin screen, pack-owned data or
   config, and a capability descriptor. The proof must stay meaningful.

**Contract-coverage gap (must be decided in the audit).** `app/Content/ImportExport/` is two things:
the **format adapters** (WXR / CSV / Markdown → entries) which map onto the high-level `ContentWriter`
(create-draft/publish), **and** the `lemma.content` **snapshot export/import engine**
(`LemmaContentExporter` / `LemmaContentImporter`), which reads and upserts **raw** content tables —
`content_types`, `entries`, `entry_drafts`, `entry_versions`, `entry_publications`, `entry_routes`,
`entry_references`, plus blob manifests. **Raw snapshot/restore is not covered by the published-only
Delivery reader (§4.1) or the high-level Content writer (§4.2).** So the audit must choose one:
  - **(a)** add a high-level **`ContentBundleReader` / `ContentBundleWriter`** snapshot-restore contract
    pair (an addition *beyond* the base seven, gated by this audit — see §4) and extract Importers
    against it; or
  - **(b)** Importers **fails the audit for V1**, and the fallback module above is used until the
    bundle contracts are designed.

This is a genuine contract-shaping decision, not a packaging detail — picking (a) means the reference
extraction also *designs and proves* the bundle seam; picking (b) keeps the base seven untouched and
defers snapshot/restore.

**Reference-pack qualities (selection criteria):**
1. Optional by product meaning.
2. Already working in Lemma.
3. Has backend routes/services.
4. Has at least one admin screen or settings surface.
5. Uses core content through contracts rather than copying content tables directly.
6. Can be removed without breaking the headless CMS core.

**The loop the reference pack must exercise:** `extra.glueful` discovery → capability declaration +
reporting → **writes content only via `ContentWriter`** (never its own content tables) → contributes
an admin screen via `registerAdminModule` → owns its own staging/config tables → survives
`composer remove` with the headless core intact.

**"Clean extraction" defined:** the pack depends on **`lemma-contracts`** (plus `glueful/framework`
and pack-specific deps such as `glueful/import-export`) and **not on `glueful/lemma`** — it reaches
nothing in the engine package, lands content through `ContentWriter` (or, if chosen, the bundle
contracts), and owns its non-content tables.

## 8. Versioning & stability policy

- **`lemma-contracts` is the stability anchor — strict semver.** Additive change = minor; any
  interface / DTO / event / **capability-id** / **`/capabilities` descriptor-schema** break = major.
  Packs declare `glueful/lemma-contracts: ^X`; core implements one contracts major at a time.
- **0.x freeze trigger (hard):** contracts stay **`0.x` while only first-party packs exist**. Move to
  **`1.0` *before* documenting third-party pack authoring or accepting external packs.** This prevents
  publishing a "public extension API" before the seams are proven by the reference extraction.

## 9. Migration path from today's monolith

Strictly incremental — the app boots and tests stay green at every step.

- **A. Contracts in-repo.** Add `packages/lemma-contracts` (path package). Define the seven groups as
  interfaces + VOs. Make existing engine classes implement them and bind them in the container.
  Promote existing seams that already fit — `ReferenceTargetResolver`, `ContentReindexerInterface`,
  the `BaseContentEvent` hierarchy — into contracts.
- **B. Capability spine.** Add `config/lemma.php` switchboard + `CapabilityRegistry` +
  `GET /v1/admin/capabilities`. Core is *not* a capability (always-on).
- **C. Admin registry.** Introduce `registerAdminModule`; refactor the sidebar/router so screens
  register through it (core screens included, as always-on modules) — removing hard-coded conditionals
  and dogfooding the registry.
- **D. Reference extraction.** Run the audit (§7), pull the chosen module into a path package against
  `lemma-contracts`, wire via `extra.glueful`, prove the loop.

## 10. Testing & success criteria

- **Boundary guard (mechanical):** a CI check that no `glueful/lemma-*` pack depends on
  `glueful/lemma`. (A pack may depend on `lemma-contracts`, `glueful/framework`, and pack-specific
  deps — just never on the engine package.) The boundary is enforced, not aspirational.
- **Contract conformance:** the engine's implementations pass an interface-conformance suite shipped
  with `lemma-contracts`.
- **Disable behavior (spelled out):** a **disabled** pack must **not** enqueue new jobs, register
  scheduled handlers, receive subscribers, or expose routes/nav. **Existing pending jobs fail
  closed / no-op when the capability is disabled.** Its **tables are retained**.
- **Remove behavior:** `composer remove` → pack gone, **headless core still boots, serves content, and
  the admin works** (minus that pack's screens).
- **Definition of done:** the reference pack installs, contributes admin UI, writes content via
  `ContentWriter`, and cleanly removes — with the boundary guard green.

## 11. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Contracts shaped around guesses, not real use | The reference extraction (§7) pulls on every seam before 1.0; 0.x freeze trigger (§8). |
| Admin composability underestimated (the hard part) | V1 stays static-bundle + server-authoritative registry; runtime plugin platform explicitly deferred (§6). |
| Version-matrix pain across packs | Monorepo first-party dev; single contracts major at a time; `^` constraints (§3, §8). |
| Boundary erosion (packs reaching into engine) | Packs depend on `lemma-contracts` only; CI boundary guard; `LemmaContext` as the sanctioned service-access seam (§4.7, §10). |
| Disable accidentally destroys data | Migrations gated by *installed*, not *enabled*; destructive cleanup never automatic (§5). |
| Split-publish tooling consuming the project | Path packages now; split-publishing only when release automation is ready (§3). |
