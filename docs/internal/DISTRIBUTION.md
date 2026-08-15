# Thallo Distribution Story

> Status: **CHARTER — governs the distribution cycle.** Amended 2026-08-15 with the
> payment-links / cleanup-train learnings and an external review's corrections (admin-SPA
> release bake identified as missing machinery; obligations matrix; two-posture CI; artifact
> gates; immutable release sequence). This repo is currently both the dogfood
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
| Thallo\Subscriptions | Active by default — the `thallo.subscriptions` capability is enabled unless explicitly set to `false` in `config/thallo.php` (absent key ⇒ enabled, same `DefaultCapabilityRegistry` default every capability gets). Its engine (`glueful/subscriptions`) ships enabled in `config/extensions.php` unlike Commerce's tier-2 posture — the "bundled engine enabled by default" consistency rule (design spec §1). Workspace self-serve checkout (`/v1/admin/billing/*`) is gated by `billing.manage`, a per-workspace, role-delegable authority — deliberately disjoint from the platform's `tenancy.manage`/`tenancy.access_any` (a platform-only operator is never granted it, and vice versa) — so enabling the capability never hands checkout/cancel control to platform operators who happen to hold tenancy authority |
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
3. **Payments credentials are runtime-editable, encrypted, write-only** (`Settings → Payments`);
   `.env` keys remain the ops-managed fallback. No distribution work needed here. **Ownership
   update (platform-payments-settings, 2026-08-08):** this surface moved OUT of Commerce and is
   now APP-OWNED, platform-scoped over the unscoped system channel — it was never really a
   Commerce-only concern (the SAME single gateway account also settles every workspace SaaS
   subscription), and per-pack ownership let disabling `thallo.commerce` silently revert live
   webhook verification to `.env`. It is therefore INSTALLATION-global infrastructure, not a
   tier-2 "Commerce" setting: trimming `glueful/commerce` from `config/extensions.php` per the
   checklist below must never be read as trimming payments configuration too — `glueful/payvia`
   and its Settings → Payments page stay relevant to a payments-only (no storefront) install, e.g.
   one selling only workspace subscriptions. See
   [platform-payments-settings spec](superpowers/specs/2026-08-05-platform-payments-settings-design.md).
4. **Gateway-less and keyless installs sell nothing but break nothing** — manual collection
   is the floor the whole payments stack degrades to. This is what makes trimming commerce
   and payvia from defaults safe.
5. **Tier 2 ships installed-but-disabled** (2026-08-15). Not hope: `InertnessTest` and the
   `interface_exists` guards pin the inert posture, and the extensions-browser enable flow is
   tested. The skeleton split stays a later option, not v1.
6. **No setup wizard for Developer Preview** (2026-08-15). README + the documented CLI
   sequence; the audience is developers. Revisit at Beta.
7. **All 13 `packages/thallo-*` modules stay path-local** (2026-08-15). Publish one only when
   another application consumes it or it needs independent versioning.
8. **Versioning + immutability** (2026-08-15): the app tags `v1.0.0-beta.N` (semver
   pre-release; Packagist and `create-project` handle it). Tags are IMMUTABLE — corrections
   become `beta.N+1`, never a mutated tag. Promotion arc: Developer Preview → Beta after the
   Thallo website runs on a published tag AND external developers complete installs from the
   public docs alone; → `1.0.0` when Beta has run clean.
9. **The admin SPA distribution shape is constrained by Packagist** (2026-08-15): dists come
   from `git archive` of the tag — there is no post-archive hook — so the RELEASE COMMIT
   ITSELF must contain the built `public/admin` (force-added past the gitignore by the release
   script) while `/admin` source stays export-ignored. As of this amendment that machinery
   DOES NOT EXIST: `public/admin` is gitignored with zero tracked files, so a
   `create-project --prefer-dist` today ships NO admin at all (source export-ignored, build
   absent from the tag tree) even though the `.gitattributes` comment promises otherwise.
   Hard launch blocker — see the checklist's release-bake gate.

## 4. Distribution-time checklist

Execute top-to-bottom when we decide to ship. Each item is small; the point is not to forget any.

- [ ] **Trim `config/extensions.php`** to tier 1 (drop the Commerce, Payvia, Meilisearch
      lines — the Thallo modules are no longer in this file at all). Keep the tenancy comment
      block and the `extensions.protected` map verbatim.
- [ ] **Decide the capability switchboard defaults** (`thallo.capabilities`): all-on for
      tier-1 surfaces; `thallo.search` stays off (already the committed default); document
      that tier-2 capabilities appear on install.
- [ ] **CI: two postures.** (a) The dogfood everything-on suite stays (re-enable tier 2
      before the suite, or a committed CI-only extensions config) — the payments/commerce
      suites must never go silently unexercised. (b) A DISTRIBUTION-DEFAULTS lane runs against
      the fresh-install `extensions.php`, so installed-but-disabled inertness is exercised as
      users actually receive it — today nothing tests that posture. Suite realities for the
      executor: ~10-minute wall time, shard lists in `ci.yml`, env pins in `phpunit.xml`.
- [ ] **`.env.example` pass**: fresh-install values (APP_KEY placeholder + generation
      instructions, DB, mail, `EXTENSIONS_INSTALL_ENABLED` stance for prod), no dogfood values.
- [ ] **First-run experience**: document (or script) create-project → `.env` → `migrate:run`
      → admin-user creation → login. Decide whether a setup wizard is v1 or later.
- [ ] **Seed content**: default theme, starter block types (contributor already ships),
      decide on a sample entry/homepage.
- [ ] **Admin SPA release bake (HARD GATE — machinery missing, see decision 9):** script the
      release step (`pnpm build` → `git add -f public/admin` → release commit), then verify
      `git archive <tag> | tar -t` contains `public/admin/index.html` and does NOT contain
      `admin/`; a `create-project` from the tag must serve the admin. The SPA item stays open
      until this workflow exists and has been exercised on a real tag.
- [ ] **Strip dogfood-only files from the distributed artifact**: `docs/superpowers/`,
      `.superpowers/`, local scripts that assume sibling repos (`../extensions` dev-links).
- [ ] **README + upgrade story**: create-project instructions, how updates arrive
      (composer update vs template re-pull), extension install guide. Upgrade instructions
      MUST include clearing compiled containers on every `composer update` — the
      stale-compiled-container failure class was hit twice during development (seam-fallback
      dead code; payment-service guards, which now fail loud by design).
- [ ] **Versioning**: the app template gets its own versioned releases; pin extension
      constraints to published versions (standing rule: publish dependencies first).
- [ ] **Security defaults audit**: production env posture (HTTPS, docs off, installer off),
      confirm no secrets in committed config.
- [ ] **`BASE_URL` is REQUIRED**: the canonical-origin authority reads it (never the Host
      header). Unset, the SEO head omits canonical/OG URLs entirely (safe posture — the
      un-overridden `http://localhost` default is treated as unconfigured), but every
      absolute-URL surface (media URLs, storefront CSRF origin, sitemap) wants it set.
      First-run docs and `.env.example` must say so loudly. Payment-link precision (both
      directions): ordinary HTTP local development is fine for the CMS, but payment-link
      MINTING requires a canonical HTTPS origin with no non-default port (a reviewed custody
      decision) — local HTTPS satisfies it; plain-HTTP dev sees links as typed-unavailable,
      which is expected, not a bug.

- [ ] **Operational-obligations matrix** — one user-facing production page, organized BY
      CAPABILITY/TIER with each row marked required/recommended. Obligations attach at the
      tier that creates them (a tier-1 blog install needs none of the payment rows). Rows to
      cover: core scheduler + queue workers; scheduled publishing; signup cleanup; domain
      reverification; commerce — `commerce:orders:expire` (cancels stale drafts AND purges
      canceled numberless artifacts past `commerce.orders.draft_purge_days`); payvia —
      `payvia:intents:sweep-stale` (`--tenant` loop or ForEachTenant on tenanted installs);
      rich email channel (payment-request mail); `logging.sensitive_paths` (committed defaults
      cover `/checkout/pay/*`; base-URL-mounted apps register prefixed templates);
      reverse-proxy/CDN log redaction (recipes exist in the pack README);
      `zend.exception_ignore_args=On`; Paystack integration `payment_session_timeout=0`.
- [ ] **Known-limitations page** (public, linked from README): single platform merchant
      account — every workspace settles through the platform gateway, no per-workspace
      credentials; Paystack constraints (no session renewal without provider death proof;
      repricing is a hard typed stop with the documented operator recovery); payment-link
      open/click analytics deliberately excluded (zero-third-party landing rule).
- [ ] **Changelog curation — not a blind collapse**: condense the pre-release chronology into
      the initial release entry (written as a product description), PRESERVE durable security
      decisions, migration requirements and upgrade instructions, move the full development
      history to `HISTORY.md`.
- [ ] **SECURITY.md, support channels, upgrade policy, semver expectations** — none exist yet.
- [ ] **Public-Git curation of `docs/internal/`** — independent of `export-ignore` (which
      already excludes it from dists): Packagist publication makes the GIT repo public, so
      decide per file what stays in public Git, what graduates to user-facing docs, and what
      relocates.
- [ ] **Composer self-containment**: no `repositories` entries or scripts escaping the repo
      (the `packages/*` relative path-repos ship with the app and are fine; any sibling
      `../` dev links must be gone). Proven by the artifact gate below, not by inspection.
- [ ] **Artifact clean-machine gate**: `composer create-project --prefer-dist` from the
      PUBLISHED tag in a directory with no sibling repositories; `composer install --no-dev`;
      confirm no escaping path repositories or symlinks; the admin loads; the documented
      first-run sequence completes using only the public docs.
- [ ] **Website-from-tag gate**: deploy the Thallo website + docs from that exact published
      tag — never from the dev checkout. Announce only after BOTH gates pass; anything found
      becomes `beta.N+1`, never a mutated tag.

## 5. Open questions — RESOLVED 2026-08-15 (recorded as decisions 5–7 above)

- Commerce ships **installed-but-disabled** (decision 5); the skeleton split is a later
  option only.
- **No setup wizard** for Developer Preview (decision 6).
- The 13 thallo modules **stay path-local** (decision 7); publication is reconsidered only
  when another application consumes one or one needs independent versioning.
