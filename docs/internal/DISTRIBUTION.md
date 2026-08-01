# Thallo Distribution Story

> Status: **DESIGN — held until distribution time.** This repo is currently both the dogfood
> development app and the (future) distribution template. This document records the decisions
> that separate those two roles, so none of them get made by accident, and the checklist to
> execute when we are ready to distribute. Nothing in here changes current behavior.

## 1. Context

The dogfood install deliberately runs *everything* — its `config/extensions.php` activates every
extension so the suite and the team exercise every feature (this is why payvia is enabled in the
committed list: CI tests the app as configured, and the payments tests pin gateway-mode
behavior). A fresh Thallo for an end user should not inherit that posture wholesale: a blog-only
site shouldn't carry a shop, and a shop shouldn't need `.env` archaeology to take payments.

The architecture already supports the split — capability gating hides absent features in the
admin, the in-admin extensions browser installs/enables packs at runtime, unconfigured payvia
degrades checkout to manual collection, and disabled packs' providers are inert by design
(`services()` returns `[]` when their host extension is absent). Distribution is therefore
mostly a *configuration* decision, not an engineering project.

## 2. Modules, extensions, and lifecycle flows

Since the modules-not-extensions conversion (spec `docs/superpowers/specs/`
`2026-07-25-modules-not-extensions-design.md`, executed 2026-07-26), the three activation
axes are structurally distinct — distribution reasons about each separately:

**Modules — Thallo's own organs, always loaded.** The `packages/thallo-*` providers are
library-typed composer packages registered in `config/serviceproviders.php`. They are NOT
extensions: they never appear in the extensions catalog or `config/extensions.php`, and there
is no enable/disable lifecycle for them. Behaviour that must be optional is gated INSIDE the
module — by capability (`thallo.search`, off by default) or by host-extension presence
(Thallo\Commerce's `interface_exists` guard makes it inert without glueful/commerce). Ordering
is declarative (`DeclaresLoadOrder`, framework 1.72.0): modules share the post-extension
priority tier, with thallo-commerce explicitly `loadAfter` the commerce extension.

| Module | Why always loaded |
|---|---|
| Thallo\Render, Collections, Navigation, Seo, Workflow, Analytics | The CMS itself |
| Thallo\Importers | Admin operations surface |
| Thallo\Commerce | Inert without glueful/commerce — activation is the extension's |
| Thallo\Search | Inert until the `thallo.search` capability is switched on |
| Thallo\Tenancy | App-side tenancy integration, inert until enforcement |
| Tenancy **control plane** (`TenancyControlPlaneProvider`) + `App\Providers\ThalloServiceProvider` | Same list, registered first — must pre-exist so workspaces can be enabled later |

**Tier 1 — Core extensions, shipped and enabled.** The `config/extensions.php` allow-list in
a fresh install:

| Extension | Why core |
|---|---|
| Glueful Users, Aegis (RBAC), EmailNotification | Auth, permissions, forgot-password email |
| Glueful I18n, Media | Locales and media are table-stakes CMS features |
| Glueful Audit, ImportExport | Admin operations surface (revisit if weight matters) |

**Tier 2 — Capability extensions, added via the in-admin extensions browser.** Stateless to
enable, safe to remove, capability-gated in the UI. NOT active in a fresh install.

| Extension | Notes |
|---|---|
| glueful/commerce | Enabling it is what activates the always-loaded Thallo\Commerce module |
| glueful/payvia | Installs from the Payments tab's manual-collection pointer or the browser; keyless = manual collection, so enabling early is harmless |
| glueful/meilisearch | Requires an external Meilisearch server — never on by default; pairs with the `thallo.search` capability switch |

**Tier 3 — Stateful transitions, owned by dedicated admin flows.** Never
extensions-browser-managed, never statically listed.

| Feature | Flow |
|---|---|
| Tenancy **enforcement** (`Glueful\Extensions\Tenancy\TenancyServiceProvider`) | The runtime enablement lifecycle (begin → widen → confirm → finalize / disable), `SystemFlags` is the source of truth. Enforced mechanically since framework 1.72.0: the provider is listed in `extensions.protected`, so every generic activation surface (CLI, framework controller, Thallo's extensions admin) refuses it with a pointer to Settings › Workspaces |

## 3. Decisions already made (recorded, not revisitable by accident)

1. **One repo for now.** Dogfood app and template are the same repo; distribution v1 trims
   *activation*, not code. Tier-2 packages stay in `composer.json` `require` — code present,
   providers inert. A dedicated `thallo-skeleton` (api-skeleton precedent) is a later option
   if install weight or dogfood-only files become a problem; not v1.
2. **The extensions browser is the merchant's install path** for tier 2 — the detached
   composer-install service + auto-enable exists and Lemma's browse UI drives it. With code
   already required, "install" reduces to enable + container recompile + `migrate:run`.
3. **Payments credentials are runtime-editable, encrypted, write-only** (Payments tab);
   `.env` keys remain the ops-managed fallback. No distribution work needed here.
4. **Gateway-less and keyless installs sell nothing but break nothing** — manual collection
   is the floor the whole payments stack degrades to. This is what makes trimming commerce
   and payvia from defaults safe.

## 4. Distribution-time checklist

Execute top-to-bottom when we decide to ship. Each item is small; the point is not to forget any.

- [ ] **Trim `config/extensions.php`** to tier 1 (drop the Commerce, Payvia, Meilisearch
      lines — the Thallo modules are no longer in this file at all). Keep the tenancy comment
      block and the `extensions.protected` map verbatim.
- [ ] **Decide the capability switchboard defaults** (`thallo.capabilities`): all-on for
      tier-1 surfaces; `thallo.search` stays off (already the committed default); document
      that tier-2 capabilities appear on install.
- [ ] **CI split**: the dogfood CI must keep testing the everything-on posture. Either CI
      re-enables tier 2 before the suite (`php glueful extensions:enable ...` steps) or a
      committed CI-only extensions config — decide then; the payments/commerce suites must not
      go silently unexercised.
- [ ] **`.env.example` pass**: fresh-install values (APP_KEY placeholder + generation
      instructions, DB, mail, `EXTENSIONS_INSTALL_ENABLED` stance for prod), no dogfood values.
- [ ] **First-run experience**: document (or script) create-project → `.env` → `migrate:run`
      → admin-user creation → login. Decide whether a setup wizard is v1 or later.
- [ ] **Seed content**: default theme, starter block types (contributor already ships),
      decide on a sample entry/homepage.
- [ ] **Admin SPA artifact**: ship built `admin/dist` or require a pnpm build step — decide
      and document.
- [ ] **Strip dogfood-only files from the distributed artifact**: `docs/superpowers/`,
      `.superpowers/`, local scripts that assume sibling repos (`../extensions` dev-links).
- [ ] **README + upgrade story**: create-project instructions, how updates arrive
      (composer update vs template re-pull), extension install guide.
- [ ] **Versioning**: the app template gets its own versioned releases; pin extension
      constraints to published versions (standing rule: publish dependencies first).
- [ ] **Security defaults audit**: production env posture (HTTPS, docs off, installer off),
      confirm no secrets in committed config.
- [ ] **`BASE_URL` is REQUIRED**: the canonical-origin authority reads it (never the Host
      header). Unset, the SEO head omits canonical/OG URLs entirely (safe posture — the
      un-overridden `http://localhost` default is treated as unconfigured), but every
      absolute-URL surface (media URLs, storefront CSRF origin, sitemap) wants it set.
      First-run docs and `.env.example` must say so loudly.

## 5. Open questions (decide at distribution time)

- Does commerce ship **installed-but-disabled** (current v1 plan) or fully absent (needs the
  skeleton split)?
- Is a first-run setup wizard worth building before v1, or is a README + three CLI commands
  enough for the initial audience?
- Do we publish the thallo modules (`packages/*`, library-typed) to Packagist at
  distribution, or do they stay path-local to the app forever? (Path-local is fine for v1;
  publishing only matters if other apps should consume them. The modules-not-extensions spec
  deliberately left this open — flipping them to path-local-only or published libraries needs
  no further architecture change either way.)
