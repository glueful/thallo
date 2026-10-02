# One place to turn each feature on — design

**Status:** draft for review · **Date:** 2026-10-02 · **Release:** one beta, cut when asked

## 1. Why

Turning Commerce on today takes two switches on two tabs of the Extensions page. You enable the
`glueful/commerce` engine on **Installed** or **Browse**, then the **Commerce** capability on
**Capabilities**. Each step leaves work for a later request. A production install (scentnoir, beta.76)
ended with Commerce half on: the engine enabled, the capability following it, and no blocks until
`thallo:provision` ran. Beta.77 fixed the symptoms: blocks now seed when the block library loads,
and the install roles now get their permissions. The two-switch model is still there.

This design gives each feature one authoritative place to manage it, and one user action to turn it
on or off. The same work removes the Browse tab, which offers raw switches on framework packages
and doesn't fit Thallo.

## 2. What exists today (checked in code)

- **The Extensions page** (`admin/src/pages/extensions/index.vue`, nav entry in `admin/src/registry/coreModule.ts`)
  has three tabs: **Installed** (the default), **Browse** and **Capabilities**.
- **Browse** (`BrowseExtensions.vue`) searches Packagist live for `type=glueful-extension`
  (`ExtensionAdminController::registry`). That returns 23 packages today, all first-party
  `glueful/*`, nine of them already required by Thallo. Each card offers **Install** (`composer require`
  through `ExtensionInstaller`) or **Enable / Disable**, without the state checks the Installed tab uses.
- **The web installer** is on unless `APP_ENV=production`, and `EXTENSIONS_INSTALL_ENABLED` overrides
  that either way (`config/extensions.php`, `install.enabled`). So it's off in production by default,
  not impossible.
- **Generic enable / disable** (`ExtensionAdminController::toggle` → framework `ExtensionSchemaExecutor`,
  and the framework's `extensions:enable` / `extensions:disable` commands) already checks declared
  dependencies and refuses **protected providers** (`ProtectedProviders::refusalFor`, reading
  `extensions.protected`). The only protected provider declared is tenancy's, and only in the
  operator's `config/extensions.php`. What's missing is Thallo's own runtime requirements: nothing
  stops disabling Aegis, Users or i18n from a card.
- **Capabilities** (`CapabilityAdminController`, `CapabilityStateStore`, `DefaultCapabilityRegistry`):
  - **Stored state:** `capability.<id>.enabled` in the system channel; an untouched switch has no row.
  - **Engine-backed capabilities:** an untouched one follows its engine. Commerce
    (`glueful/commerce`), Subscriptions (`glueful/subscriptions`), Workspaces (`glueful/tenancy`),
    Accounts (`glueful/users`) and Content importers (`glueful/import-export`) declare an `owningPackage`.
  - **Within a request:** the registry remembers each capability's state, so a flip only shows
    from the next request (`CapabilityAdminController.php:139`).
- **Pack blocks** seed through `ContributedBlockTypeReconciler`: a once-per-switch-on flag, single-store
  only (it returns early with workspaces on, line 35). Since beta.77 the block library also calls it.
- **Install-role grants** (`InstallRoleGrants`) keep an "offered once" ledger, so a revoked grant stays
  revoked.
- **Workspaces** has its own enablement flow (Settings › Workspaces: adoption and enforcement checks) and
  writes its provider's enabled line through the low-level writer, around the protection.

## 3. Decisions

### 3.1 The page: Features first

- **Rename the page and nav entry from "Extensions" to "Features",** with the heading **Features**.
  The default view is the list of capabilities, as cards with a switch. `/extensions` redirects to
  `/features`, so old links and bookmarks keep working.
- **A second view, "Installed packages",** keeps the technical detail (see 3.7).
- **Each capability card has one switch:**
  - **Commerce and Subscriptions** use the activation flow (3.2).
  - **Workspaces** links to its existing setup flow in Settings › Workspaces. It never uses the
    generic activation sequence.
  - **Every other capability** keeps today's simple switch (no engine to prepare).
- **Access:** the page stays operator-only (`system.access`). Non-operators see today's access empty
  state.

### 3.2 Activation flow (Commerce, Subscriptions)

The user makes **one action**. The server may need more than one request to finish it, because the
engine's own provider only boots on a fresh application boot. The admin drives this automatically
behind one "Turning on Commerce…" state. The user never visits another page or refreshes to finish.

**Stored state.** A new capability state, `preparing`, joins on and off. While a capability is
preparing it is **effectively off**: it doesn't follow its engine, even after the engine is enabled.
This closes the half-on window. The state is stored as an explicit operation record (3.3), not
inferred.

**Steps,** each safe to run again:

1. **Mark the capability `preparing`** and record the operation.
2. **Enable the engine** through the owning flow's own path: migrate first, then write the enabled
   list. This is the path Workspaces uses; it isn't refused as protected, because Thallo owns the flow.
3. **Fresh boot:** the admin calls the operation's continue endpoint, so the next steps run with the
   engine's provider booted.
4. **Seed the capability's blocks,** for every workspace when workspaces are on (3.4).
5. **Grant new permissions** through the install-role ledger (3.5).
6. **Mark the capability on** and close the operation.

**What the user sees:**
1. **Confirmation:**
   > **Turn on Commerce?**
   > This prepares your store and adds products, orders, shop blocks and templates. Your existing
   > content is kept.
   >
   > [Turn on] [Cancel]
2. **Progress:** one "Turning on Commerce…" state on the card.
3. **Success:** a summary built from the operation's result, never hard-coded. For example: "Commerce
   is on. Added 18 blocks and the shop templates." It links to Products and Settings › Block types.
   Capability discovery, the navigation and the current user's permissions refresh, so the Commerce
   section appears at once.

### 3.3 Failure, retry and concurrency

- **Failure is reported accurately, step by step.** For example: "Commerce's tables are ready, but its
  blocks couldn't be added: <reason>." **Retry** resumes from the failed step. Nothing claims to have
  rolled back: migrations and the enabled list may have changed. The capability stays `preparing`,
  so it remains off until a retry succeeds.
- **The operation record:** one row per capability activation, holding the steps done, the step that
  failed, the error, and timestamps. It lives in the system channel or its own small table; the plan
  chooses which.
- **Concurrency:** a second **Turn on** while one is running joins the running operation and doesn't
  start another. Double-clicks and retries never duplicate work: the executor locks, the seeder only
  adds, the ledger offers once.
- **Read-only hosts:** enabling an engine writes `config/extensions.php` and recompiles the extension
  cache. Where the host can't be written (the existing `hostToggleRefusal`), the card says so before
  the user starts, gives the exact CLI steps, and offers no button that can't work.
- **Cache-stale outcome:** if the executor finishes but leaves the extension cache stale
  (`STATUS_CACHE_STALE`), the result says what that means and how to finish (the command to run). It
  isn't reported as a clean success.

### 3.4 Workspaces

With workspaces on, activation seeds the capability's blocks into **every existing workspace**, the
same set `thallo:blocks:seed --all` reaches. It tracks readiness **per workspace** in the operation
record, so a failure names the workspaces that aren't ready and Retry covers only those. A workspace
created later gets the capability's blocks from the workspace starter, as today. The single-store
reconciler stays as it is.

### 3.5 Permissions

New permissions go through the existing "offered once" ledger (`InstallRoleGrants`), never "grant every
missing permission". A permission an operator deliberately removed stays removed. (Migration 016 in
beta.77 was a one-time repair for an install bug, not a model for routine activation.) After
activation, the current user's permissions refresh.

### 3.6 Turning off

Turning a capability off **disables the capability only**:
- the engine stays enabled and its data stays installed;
- its blocks are hidden, never deleted (today's behaviour);
- it can be turned on again.

Confirmation:
> **Turn off Commerce?**
> Commerce's pages, blocks and menu are hidden. Products, orders and your content are kept, and you
> can turn it on again.

Turning off never disables a shared engine. After completion, capability discovery, the navigation
and the current user's permissions refresh.

### 3.7 Installed packages, and one management policy enforced on the server

The **Installed packages** view lists each installed extension with:
- **its version, schema health, dependencies, and a link to its documentation** (kept from today);
- **one management line:**
  - **Required by Thallo:** no Disable.
  - **Managed by Commerce / Subscriptions / Workspaces:** links to that card or flow; no raw switch.
  - **Independent:** a supported extension with the actions it's allowed (enable / disable).

**One declared policy, in core.** A single map in core declares each package's management class
(required, managed-by `<capability>`, independent), with a reason. It's used three ways:
- **The admin UI** renders the management line from it.
- **Thallo's mutation endpoints** (`ExtensionAdminController`) refuse protected actions from it, so
  hiding a switch is never the only guard.
- **The framework's generic surfaces** (`extensions:enable` / `extensions:disable`, the executor and
  its controllers) see it as `extensions.protected` entries, registered through
  `ApplicationContext::mergeConfigDefaults()` in core's provider. They sit beneath the operator's
  file, so an existing site gets them without editing `config/extensions.php`, and an operator's own
  entries still win. The plan must verify that the merged defaults reach `ProtectedProviders::refusalFor()`
  in the CLI boot. If they don't, that's a framework change, and the spec's guarantee for the CLI
  waits on it.

**Audit, don't assume.** Being installed by Composer doesn't make a package required to stay enabled.
Before declaring "Required by Thallo", the plan audits each package's actual runtime requirement.
The candidates are Aegis, Users, i18n, Media, Audit, email notifications, and import/export (behind
the Content importers capability, so possibly "managed" instead).

### 3.8 Remove Browse

Removed together, so nothing is left behind by accident:
- the Browse tab and `BrowseExtensions.vue`;
- the catalog query (`useExtensionCatalog`), its types and any controls only it used;
- `GET /v1/admin/extensions/registry` and its live Packagist query;
- their tests;
- their OpenAPI operations and any docs that mention them.

**The web installer** (`POST /v1/admin/extensions/install`, `useExtensionInstall`) has no other caller
once Browse goes, so it's removed with it, explicitly. The framework's `ExtensionInstaller` and its
config stay; they're the framework's. A curated catalog that wants an installer later brings its own
supported caller.

### 3.9 Deferred: a curated catalog

Not in this release. A catalog is an ongoing compatibility and documentation commitment. When it
comes, it's organised around outcomes (object storage, search, social sign-in), with supported
versions, prerequisites and setup instructions verified per integration. A Composer command alone
doesn't make something usable, and ".env only" must be proven per entry.

### 3.10 Kept: the safety nets

- **Block library seeding (beta.77)** stays. It covers the CLI path (`extensions:enable`) and any
  capability turned on outside the admin.
- **`thallo:provision`** stays the repair for everything.

## 4. Testing

- **Activation operation:**
  - the steps run in order;
  - a capability that's preparing is effectively off, even with its engine enabled;
  - a failure at each step leaves an accurate record, and Retry resumes from that step;
  - a second Turn on joins the running operation;
  - read-only host → refused up front, with the CLI steps;
  - cache-stale → reported as such.
- **Workspaces:** blocks reach every existing workspace; a failure in one workspace names it, and
  Retry covers it only.
- **Permissions:** activation grants through the ledger; a revoked grant stays revoked.
- **Turn off:** the capability is off, the engine stays enabled, the blocks are hidden but not
  deleted, and turning on again works.
- **Management policy:**
  - the admin endpoints refuse disabling a required package, and refuse the raw switch on a managed one;
  - the framework CLI refuses them too, through the merged `extensions.protected` (an integration
    test over a real boot);
  - an operator's own `extensions.protected` entry still wins.
- **Browse removal:** the route, the client query and the installer endpoint are gone; `/extensions`
  redirects to `/features`.
- **Admin:** vitest for the card states (idle, confirm, preparing, failed with Retry, on, read-only);
  e2e for one full Commerce activation against fixtures.
- **End to end:** on a fresh skeleton install in production mode, turning Commerce on from the
  Features page leaves the capability on and its blocks present, with no provision. This repeats the
  beta.77 reproduction, now through the new flow.

## 5. Docs and changelog

- **Capabilities concept page:** the Features page, the activation flow, turning off.
- **Getting started / install:** where to turn features on.
- **Troubleshooting:** what each failure means, Retry, and read-only hosts.
- **Remove** the Browse and web installer mentions.
- **Changelog:** under `[Unreleased]`, riding with each change.

## 6. Open questions for the plan

1. **Where the operation record lives:** the system channel, or its own table (a migration).
2. **Whether `mergeConfigDefaults` reaches `extensions.protected` in the framework's CLI boot,** or
   whether that needs a framework change first.
3. **The runtime-requirement audit's results:** which packages are "Required by Thallo".
4. **Content importers and Accounts** also declare engines (import/export, Users). Do they join the
   activation flow, or stay simple switches over required engines?
