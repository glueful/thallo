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
  - **Every other capability** keeps today's simple switch, unless the audit (§3.7) assigns it an
    engine to prepare. Accounts (`glueful/users`) and Content importers (`glueful/import-export`)
    do have engines; the audit decides whether those engines are required by Thallo (so the switch
    stays simple) or prepared through this flow.
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

**Steps,** each safe to run again, each owned by the current operation (3.3):

1. **Mark the capability `preparing`** and record the operation.
2. **Enable the engine** through the owning flow's own path: migrate first, then write the enabled
   list. This is the path Workspaces uses; it isn't refused as protected, because Thallo owns the
   flow. Skipped when the engine is already enabled and ready.
3. **Verify on a fresh boot. This is a completion gate.** The admin calls the operation's continue
   endpoint. That request must prove, before any later step runs, that:
   - the engine's provider is actually loaded in this boot (`ExtensionManager`);
   - its schema is Ready (the availability resolver's verdict).

   Another HTTP request alone isn't proof, particularly after a failed cache recompile. If the check
   fails, the step fails with the reason and its repair (for example, rebuilding the extension
   cache), and the capability stays `preparing`, so it stays off. A cache-stale outcome from step 2
   stays off until this check passes, and recovery resumes the same operation.
4. **Seed the capability's blocks** from its **explicit contributions**
   (`BlockTypeKind::contributionsFor()`), for every workspace when workspaces are on (3.4). Seeding
   happens while the capability is still effectively off, so the ordinary `definitions()`, which
   filters a gated contribution out while its capability is off, must not be used here.
5. **Grant new permissions** through the install-role ledger (3.5).
6. **Final readiness check, then mark the capability on**, under the operation's exclusive ownership
   (3.3). The check covers workspaces created during preparation (3.4). Then the operation closes.

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

### 3.3 Concurrency, failure and retry

One concurrency contract covers the **whole activation**, not only the engine work: the executor's
lock protects migrations and the enabled list, not operation creation, continuation, seeding or the
final switch.

**Operations:**
- **Creation is atomic.** At most one open operation per capability. A second **Turn on**, from
  another tab or another operator, finds and joins the open operation instead of creating one. The
  creation is a single compare-and-set on the operation store.
- **Every operation has an id and a generation.** Every continuation names the operation it belongs
  to. A continuation for an operation that is no longer current does nothing and reports that a newer
  decision exists. An old request can never turn a feature back on after a newer decision.
- **Steps are owned exclusively.** A step runs only while its runner holds the operation's ownership,
  a lease that expires, so a crashed runner doesn't block forever. Overlapping continuations: the
  second waits or returns "in progress"; it never runs a step twice in parallel. A step's completion
  is recorded before the response is sent, so a lost response is harmless: the next continuation sees
  the step done and moves on.

**Interruptions:**
- **Browser closed or request timed out after its work:** the operation stays recorded. Reopening
  Features discovers it and offers **Continue** (and **Cancel** while it isn't on). Continuing resumes
  from the first unfinished step.
- **Turned off during preparation:** turning off supersedes the operation. Its generation ends and the
  capability is stored off. Any delayed continuation of the old operation is refused at its next
  ownership check, and the final step re-checks the generation before switching on. Steps already
  done stay done (tables migrated, blocks hidden while off). A later **Turn on** starts a new operation
  that skips them.
- **The existing capability-update endpoint** (`CapabilityAdminController::update`) must not bypass
  preparation. For a capability that uses the activation flow, a request to turn it on starts or joins
  the operation; it never writes `on` directly. Turning off goes through the supersession above.

**Failure:**
- **Reported accurately, step by step.** For example: "Commerce's tables are ready, but its blocks
  couldn't be added: <reason>." **Retry** resumes from the failed step. Nothing claims to have rolled
  back: migrations and the enabled list may have changed. The capability stays `preparing`, so it
  remains off until a retry succeeds.
- **The operation record** holds the steps done, the step that failed, the error, the generation, the
  lease and timestamps, plus per-workspace readiness (3.4). Where it lives is a plan decision.

**Filesystem writes:**
- **Writability is checked only when a pending step writes the filesystem.** That means step 2:
  `config/extensions.php` and the extension cache. If the host can't be written (the existing
  `hostToggleRefusal`), the card says so before the user starts and gives the deploy-time steps (3.10).
  It offers no button that can't work.
- **Turning on an already-prepared feature** (engine enabled and ready, for example after an earlier
  turn-off) needs no filesystem write. It works on a read-only host.
- **Cache-stale outcome:** see the step 3 gate. It is never reported as a clean success.

### 3.4 Workspaces

With workspaces on, activation seeds the capability's explicit contributions (3.2, step 4) into
**every existing workspace**, the same set `thallo:blocks:seed --all` reaches. It tracks readiness
**per workspace** in the operation record, so a failure names the workspaces that aren't ready and
Retry covers only those.

**No workspace falls between the two paths.** A workspace created after activation lists its targets,
but before the capability is on, would otherwise miss both: activation didn't list it, and its starter
sees the capability as off and leaves the blocks out. So workspace creation and activation's final
step coordinate:
- **While a capability is preparing,** creating a workspace also seeds that capability's explicit
  contributions into the new workspace, and records it in the operation's readiness.
- **The final readiness check (3.2, step 6) runs under the operation's exclusive ownership.** It lists
  the workspaces again and seeds any not yet ready, then switches the capability on in the same
  critical section. Workspace creation takes the same coordination point, briefly, so it lands either
  before the check (and is seeded) or after the switch (and its starter sees the capability on).
- **After completion,** a new workspace gets the capability's blocks from its starter, as today.

The single-store reconciler stays as it is.

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

### 3.10 The CLI path, deployments, and the safety nets

**A feature-owned CLI that uses the same operation contract.** Under the new policy, `extensions:enable`
refuses managed engines, and its refusal names this command instead:
- `php glueful thallo:features:enable <capability>`: starts or joins the operation and runs its steps.
  The fresh-boot gate (3.2, step 3) runs in a second, fresh process that the command starts itself.
- `php glueful thallo:features:resume [<capability>]`: continues an open operation from its first
  unfinished step.
- `php glueful thallo:features:status`: lists capabilities, their states and any open operation.

**Immutable deployments: preparation and completion are separate.** A genuinely read-only filesystem
can't be written by the CLI either, so:
- **Preparation runs at deploy time,** where the filesystem is still writable: `thallo:features:enable
  <capability> --prepare` migrates, writes the enabled list and builds the extension cache, then stops
  with the capability `preparing`.
- **Completion runs at runtime,** from the Features page or `thallo:features:resume`. It needs only
  the database: the fresh-boot gate, seeding, grants and the switch. It works on the read-only host
  because no pending step writes the filesystem.

**`thallo:provision`** resumes any open operation: its database steps always, and its filesystem
steps when the host is writable. It reports the result. It is not a general activation
command, and it doesn't turn on a capability nobody asked for.

**Required providers that are already disabled.** `extensions.protected` refuses both enable and
disable, so "Required by Thallo: no Disable" alone leaves no way back for a required provider that's
already disabled. The Installed packages view shows it as **Required by Thallo, disabled**, with the
repair command. `thallo:provision` re-enables required providers that are disabled (filesystem
permitting) and says so in its output.

**Kept safety nets:**
- **Block library seeding (beta.77)** stays, for single-store sites, covering any capability that
  ends up on outside the operation.
- **`thallo:provision`** stays the general repair, as described above.

## 4. Testing

- **Activation operation:**
  - the steps run in order;
  - a capability that's preparing is effectively off, even with its engine enabled;
  - a failure at each step leaves an accurate record, and Retry resumes from that step;
  - a second Turn on joins the running operation;
  - read-only host → refused up front, with the CLI steps;
  - cache-stale → reported as such.
- **Concurrency:** two simultaneous starts make one operation; overlapping continuations never run a
  step twice; a lost response (work done, response dropped) is resumed without repeating the step; a
  delayed continuation after turning off is refused and leaves the capability off; the capability
  update endpoint can't write `on` past a preparing operation.
- **Fresh-boot gate:** a continue request whose boot doesn't load the provider, or whose schema
  isn't Ready, fails the step and leaves the capability off; a cache-stale outcome stays off until
  the gate passes; recovery resumes the same operation.
- **Workspaces:** blocks reach every existing workspace; a failure in one workspace names it, and
  Retry covers it only. A workspace created while seeding is paused gets the blocks. A workspace
  created immediately before completion is caught by the final check; one created immediately after
  gets them from its starter.
- **Seeding while off:** preparation seeds the explicit contributions even though `definitions()`
  filters them out while the capability is off.
- **CLI:** `thallo:features:enable`, `resume` and `status` follow the same contract;
  `extensions:enable` on a managed engine refuses and names `thallo:features:enable`; `--prepare` at
  deploy time and completion at runtime work on a host made read-only between them; turning an
  already-prepared feature back on works on a read-only host.
- **Required providers:** a disabled required provider shows the repair, and provision re-enables it.
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
   whether that needs a framework change first. If it needs one, CLI enforcement is a **release
   gate**: this release doesn't ship until the framework change is published and required.
3. **The runtime-requirement audit's results:** which packages are "Required by Thallo".
4. **Content importers and Accounts** also declare engines (import/export, Users). The audit decides
   whether they join the activation flow or stay simple switches over required engines.
5. **The ownership mechanism:** a database advisory lock, a row lock on the operation record, or a
   lease column. The contract in 3.3 is fixed; the mechanism is the plan's.
