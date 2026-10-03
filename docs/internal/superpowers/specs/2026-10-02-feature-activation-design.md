# One place to turn each feature on — design

**Status:** approved a3c90f8d; **amended by §7** (draft for review) · **Date:** 2026-10-02 ·
**Release:** one beta, cut when asked

> **§7 supersedes** the page's name (§3.1: "Features"), the hard-coded management policy (§3.7), the
> CLI names (§3.10: `thallo:features:*`), the pre-seeded activation rows (§3.3/§3.4), and the rule
> that an untouched capability follows its engine, for activation capabilities (§3.2). Where §7 and
> an earlier section disagree, §7 wins.

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
6. **Finalize: the final readiness check, then the switch, as one finalization** under the
   operation's exclusive ownership (3.3). The check covers workspaces created during preparation
   (3.4). Then the operation closes.

**The finalization contract.** Routes are registered according to capability state, so a route table
compiled while the capability was off keeps serving the old routes
(`CapabilityAdminController.php:139` clears it for the same reason). **The capability becomes effective
only when no request can use the previous route table.** Two consequences:
- **No gap.** There's no moment, and no crash point, where the capability is on but requests are
  served from the old table. "Switch on, then invalidate" can't satisfy this, because a failure or a
  dead process between the two leaves the capability on with stale routes. Neither can "invalidate,
  then switch on" alone: a request that booted before the switch can rebuild the old table after the
  deletion.
- **Stale tables are unusable, not merely deleted.** A route table built under an earlier capability
  state, including one rebuilt by an in-flight request that booted before the switch, must be
  rejected by any request that sees the new state. For example, the table is keyed by, or validated
  against, a capability-state version that the switch advances in the same write that makes the
  capability effective. The plan chooses the mechanism; the contract is fixed.

Success means the next request reaches the newly enabled routes. Turning off follows the same
contract (3.6).

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
- **Steps are owned exclusively, and ownership is fenced.** A step runs only while its runner holds
  the operation's lease: an owner token and an expiry, so a crashed runner doesn't block forever. An
  expired lease doesn't stop its PHP process, which can resume after another runner has taken over.
  So ownership is checked again **at every write**, not only before starting:
  - recording a step done, advancing the operation and the final activation are each one conditional
    write that succeeds only for the current operation, generation **and** owner token. A runner
    whose lease was taken over finds its writes refused and stops;
  - **every step's side effects are safe when an old and a new runner overlap:**
    - engine enabling runs under the executor's lock;
    - block seeding creates a row only if its slug is missing, enforced by the table's uniqueness, so
      a second runner adds nothing;
    - grants are **serialized with the ledger** (3.5), not merely unique: a stale runner can't
      re-grant a permission an operator revoked after a newer runner granted it;
    - finalization follows its own contract (3.2), so an old runner can't make stale routes usable.

  Overlapping continuations: the second waits or returns "in progress"; it never runs a step twice in
  parallel. A step's completion is recorded before the response is sent, so a lost response is
  harmless: the next continuation sees the step done and moves on.

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

**The deployment contract.** Application files, `config/` and `bootstrap/cache/` may be immutable.
`storage/` must be writable, as it already has to be (`thallo:doctor` fails an install whose `storage/`
isn't). So:
- **Preparation writes application files:** step 2 writes `config/extensions.php` (the enabled list)
  and `bootstrap/cache/extensions.php` (the extension cache).
- **Completion writes only the database and `storage/`:** the route cache lives in `storage/cache`.
  The rest of this section calls those "application-file writes" and "runtime writes".

**Filesystem writes:**
- **Writability of application files is checked only when a pending step writes them.** That means
  step 2: `config/extensions.php` and the extension cache. If the host can't be written (the existing
  `hostToggleRefusal`), the card says so before the user starts and gives the deploy-time steps (3.10).
  It offers no button that can't work.
- **Turning on an already-prepared feature** (engine enabled and ready, for example after an earlier
  turn-off) needs no application-file write, only runtime writes. It works on a host whose
  application files are read-only.
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
  before the check (and is seeded) or after the switch.
- **Workspace creation reads fresh state inside the coordination point.** Its request may have booted
  before the switch, and the capability registry keeps each capability's state for the whole request
  (`DefaultCapabilityRegistry.php:85`). Acquiring the coordination point doesn't refresh that. So,
  inside the coordination point, creation reads the capability's state and any open operation
  authoritatively, from the state store and the operation record, not from the request's registry.
  It then seeds that capability's explicit contributions from that decision, not from the request's
  cached `definitions()`.
- **After completion,** a new workspace gets the capability's blocks from its starter, as today.

The single-store reconciler stays as it is.

### 3.5 Permissions

New permissions go through the existing "offered once" ledger (`InstallRoleGrants`), never "grant every
missing permission". A permission an operator deliberately removed stays removed.

**The ledger check, the grant writes and the ledger update form one serialized transaction.** Today
`InstallRoleGrants` grants from grant and ledger state it read earlier (`InstallRoleGrants.php:195`).
A unique constraint isn't enough to protect a revocation. Take this sequence: runner A reads the
ledger and pauses; runner B takes over, grants the permission and records it as offered; an operator
revokes it; A resumes. The revoked row is gone, so the constraint lets A insert the grant again. So:
- **Inside one transaction,** serialized on the ledger (for example a lock on its row), the runner
  reads the ledger authoritatively, grants only what it hasn't offered, and writes the ledger back.
- **Operation ownership is checked inside that same transaction** (3.3). A runner whose lease was
  taken over grants nothing.
- **This also serializes concurrent activations.** Commerce and Subscriptions share the one ledger, so
  two activations, or an activation and a `thallo:provision`, can't overwrite each other's ledger
  update.

The change applies to `InstallRoleGrants` itself, so provision and setup get the same guarantee. (Migration 016 in
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

Turning off never disables a shared engine. It follows the finalization contract (3.2): the capability
is off only when no request can use a route table built while it was on, so the next request can no
longer reach the capability's routes. After completion,
capability discovery, the navigation and the current user's permissions refresh.

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
  runtime writes (the database and `storage/`): the fresh-boot gate, seeding, grants, the switch and
  route-cache invalidation. It works on a host whose application files are read-only.

**`thallo:provision`** resumes any open operation: its runtime steps always, and its application-file
steps when those files are writable. It reports the result. It is not a general activation
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
  step twice; **fencing:** runner A pauses mid-step, its lease expires, runner B takes over and
  finishes, then A resumes: A's writes are refused, it neither advances the operation nor activates
  the feature, and the overlapping step's side effects aren't duplicated; a lost response (work done, response dropped) is resumed without repeating the step; a
  delayed continuation after turning off is refused and leaves the capability off; the capability
  update endpoint can't write `on` past a preparing operation.
- **Fresh-boot gate:** a continue request whose boot doesn't load the provider, or whose schema
  isn't Ready, fails the step and leaves the capability off; a cache-stale outcome stays off until
  the gate passes; recovery resumes the same operation.
- **Workspaces:** blocks reach every existing workspace; a failure in one workspace names it, and
  Retry covers it only. A workspace created while seeding is paused gets the blocks. A workspace
  created immediately before completion is caught by the final check; one created immediately after
  gets them from its starter. A workspace request that boots while Commerce is preparing, waits until
  activation finishes, then creates the workspace still gets Commerce's blocks: it reads fresh state
  inside the coordination point.
- **Route cache:** with a compiled route table already in place, activation's success means the next
  request reaches the capability's routes, and turning off means the next request can't. A failure
  (or killed process) between the readiness check and the end of finalization leaves the capability
  off and the old routes in force. A request that booted before the switch and rebuilds its route
  table afterwards can't make that stale table usable: the next request rejects it.
- **Grants:** takeover, revoke, resume: runner A reads the ledger and pauses; B takes over, grants the
  permission and records it; an operator revokes it; A resumes. The permission stays revoked.
  Concurrent Commerce and Subscriptions activations, and an activation racing a provision, both keep
  every ledger entry. Completion
  works with application files read-only and `storage/` writable.
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


## 7. Amendment: Extensions, declared management, and Payments

### 7.1 Why

The page exists so that Thallo's own features **and third-party packages** have one home. The first
cut named it Features and hard-coded who manages what (`FeatureManagementPolicy`: two activation
engines, two required packages, one external flow, their provider classes and their copy). Every new
integration, first- or third-party, would need a core edit. This amendment moves those declarations
to the packages that own them, keeps the guarantees the hard-coding happened to provide, and adds
Payments, which is a Thallo feature backed by an engine (`glueful/payvia`) like Commerce.

### 7.2 The page and the CLI

- **Extensions**, at `/extensions`, with two views: **Capabilities** (default) and **Installed**.
  `/features` never shipped in a release, so it gets no redirect.
- The CLI is `thallo:capabilities:enable|resume|status` (with `--prepare`), beside the existing
  `thallo:capabilities` list-and-flip command, which keeps refusing to turn an activation capability on.
- The unfiltered Browse tab stays removed. A Thallo-aware catalog is designed separately (§3.9).

### 7.3 Management is declared, as a typed mode

Every capability has one **management mode**, declared by the package that contributes it:

| Mode | Meaning | What the Capabilities view shows |
|---|---|---|
| `simple` | A plain switch over state | A switch (today's behaviour) |
| `activation` | Turning on prepares an engine through the activation flow (§3.2–§3.6) | One action, progress, Retry, Continue/Cancel |
| `external_flow` | Another flow owns it | A link to its **destination** (an admin path and a label) |

- **The default is `simple`.** Nothing in core names a capability to give it a mode.
- **`activation`** requires the capability's `owningPackage`: that package is the **engine**. The
  engine's provider is resolved from the package manifest (`extra.glueful.provider`), never written
  in Thallo. Optional copy (turn-on and turn-off confirmations, links shown once it is on) is
  declared with it; without it the page uses a generic sentence. Summaries stay built from the
  activation's result.
- **`external_flow`** names a destination. Workspaces declares `external_flow` → Settings ›
  Workspaces from the tenancy pack, replacing its special case in core.
- **Managed packages are derived:** a package is managed when it is the engine of an `activation`
  capability or the owning package of an `external_flow` capability. Its Installed row says
  "Managed by <capability label>" with a link, and every generic switch refuses it (§3.7's
  enforcement, through `extensions.protected`, unchanged).
- **Ambiguous declarations are rejected, not resolved by precedence:**
  - one package owned by more than one non-`simple` capability;
  - one capability id declared with different owners or modes by two sources;
  - an `activation` engine that is also required by Thallo (§7.6), or that isn't installed.

  Nothing silently picks a winner, and the verdict doesn't depend on registration order: the
  declarations are validated together, once all sources are read.

- **A misconfigured capability is blocked everywhere**, not merely flagged:
  - its runtime verdict is unavailable ("misconfigured: <reason>"), so it is **ineffective even if
    its state is stored on**, and every gate that consumes effective state sees it off;
  - start, continue, the CLI, `thallo:capabilities --enable/--disable`, and the switchboard's
    `PUT /capabilities/{id}` all refuse it with the reason;
  - the packages named by the conflicting declarations are **protected as misconfigured**, never
    reclassified as independent: the admin toggle, the API and `extensions:enable`/`disable` refuse
    them with the same reason;
  - the Capabilities view shows it as misconfigured, and `thallo:doctor` reports it.

  Blocking doesn't unload an engine that is already enabled, and work that deliberately runs outside
  the capability (settling payments already started, webhooks, records) carries on.

### 7.3a Activation capabilities are off until their activation finalizes

- **An `activation` capability is effective only when its state is stored on and it is available.**
  It never follows its engine: with no stored state it is off, even when its engine is already
  enabled and ready. This closes the window in which a newly discovered capability over an enabled
  engine would be effective before its blocks, grants and workspace readiness exist.
- **Only two writes store it on:** the activation's finalization (§3.2 step 6), and the one-time
  upgrade adoption below. Every other path stores it off (start, cancel, turning off).
- **`simple` capabilities keep today's default:** with no stored state, they follow their engine's
  availability.

**Upgrade adoption.** A capability that was effective under the old rule (following its engine)
must not stay off because the rule changed. One upgrade step, run by provision (the documented
upgrade step), adopts the activation capabilities that existed before this release (Commerce and
Subscriptions) and Payments (§7.7). It runs once per install:

- **eligibility is captured before provision changes the schema**: on the first provision on
  upgraded code, after connecting to the database it is about to install against and before its
  migrations, the step records which capabilities are eligible under the rules below; after the
  migrations it adopts exactly those. The record is durable (written before any migration runs, a
  fresh install's empty list included) and atomic (the first committed capture is authoritative; a
  later or overlapping provision reuses it and never replaces it, and completion is recorded with a
  compare-and-set). An interrupted provision keeps the captured list, and the retry applies it
  instead of capturing again; a retry after a fresh install's tables were created adopts nothing.
  A schema that becomes ready during that provision doesn't make a capability eligible. (A schema
  the operator made ready themselves, with `migrate:run` before that provision, counts as ready.);
- **unknown is not empty**: if provision can't connect to, or inspect, an existing database, it stops
  before migrating and records nothing;
- **between the code update and that provision**, an activation capability with no stored state
  reads off; the upgrade notes say to run provision after updating, as every release already does;

- it **initializes only an absent state**, atomically, through the state-version contract (the write
  and the version advance in one transaction, under the activation row lock);
- it **never overwrites** a stored state (on or off) or an activation in progress;
- **Commerce and Subscriptions are adopted only when they were effective under the old rules**:
  requested (no stored state, so the `thallo.capabilities` configuration map decides, and an
  explicit `false` there means not requested) **and** available (engine enabled and schema-ready).
  An engine that is ready doesn't by itself adopt a capability the configuration switched off: that
  capability stays off. **Payments** has its own rule (§7.7);
- an adopted capability is stored on and its activation row recorded as succeeded ("adopted"), so
  the Capabilities view shows it on with no summary of additions. A capability whose engine is
  enabled but not schema-ready is **not** adopted: it starts off, and turning it on runs a normal
  activation, which migrates the engine;
- after the upgrade, configuration never makes an `activation` capability effective: a
  `thallo.capabilities` entry of `true` doesn't bypass its activation (an entry of `false` still
  reads as off while no state is stored);
- because it initializes only absent state, running provision or the row sync again changes nothing:
  an operator who turned the capability off after the upgrade keeps it off.

A third-party capability discovered after the upgrade is never adopted: it starts off.

### 7.4 Declarations are discoverable before the engine is enabled

A disabled extension's provider never loads, so a declaration made only in that provider can't put
the capability on the page. Two sources are supported, and both are read without enabling the engine:

1. **An always-loaded integration pack** declares the capability, with its mode, from its provider
   (`DeclaresCapabilities`), as Thallo's packs do (Commerce, Subscriptions, Payments from core,
   tenancy); Thallo fills `CapabilityRegistry` from the declarations before boot and seals it, so a
   `register()` call after that is refused.
2. **Package metadata:** an installed package declares its capabilities in `composer.json`
   (`extra.thallo.capabilities`: id, label, description, mode, copy, destination). Thallo reads it
   from the package manifest whether or not the package is enabled. The declaring package is the
   owning package. This is the third-party path.

What the engine contributes once it runs (blocks, permissions, routes gated by the capability) is
needed only from the blocks step onward, after the fresh-boot gate has proven the engine is loaded
(§3.2 steps 3–5). The engine's own code is never needed to show the capability or start it.

### 7.5 Activation rows exist before a capability is actionable

The workspace guarantee (§3.4) relies on every activation row existing before workspace creation
takes its share locks. Rows can't be a fixed list written by a migration any more.

- **A capability may enter `preparing` only once its activation row exists and is committed.**
- **Turning off a capability with no row** holds the same workspace-seed lock (shared) from its check
  that the row is absent through its off write, so no row is initialized and no runner started in
  between; with a row, it supersedes under the row lock as before.
- **Rows are created by one idempotent sync** for every valid `activation` capability, in its own
  committed transaction. Two paths run it:
  - **provision**, for every declared capability;
  - **the turn-on action itself:** start (the admin's switch, `thallo:capabilities:enable`, and
    provision's resume) first runs the sync for that capability and commits it, then starts the
    activation in a **second** transaction. So the switch is offered for every valid declaration,
    and the first turn-on initializes its own row before anything is prepared.
- Reads (the Capabilities view, `thallo:capabilities:status`) never write rows; a capability with no
  row yet reads as off with no activation.
- **Creating a row waits for workspace seeds in flight.** Every workspace seed takes a shared
  workspace-seed lock before its activation share locks; row creation takes that lock exclusively.
  So a seed that began before the row existed finishes first, and every seed that begins after it
  sees the row. The one lock order becomes: workspace-seed lock → activation rows → block inserts →
  grants lock → extension-state lock.
- Rows for capabilities that are no longer declared stay, inert, as history.

### 7.6 Required packages have a mandatory minimum

- Thallo core declares the packages it can't run without (today `glueful/aegis` and `glueful/users`)
  in its own code or package metadata, beside the code that depends on them. They change only with
  that code.
- An operator may **add** required packages in configuration. The effective set is the union: no
  configuration can remove a package core requires.
- A required package can't be an `activation` engine or a managed package (§7.3), and provision puts
  a missing one back (§3.10).

### 7.7 Payments

**Payments** (`thallo.payments`) is a capability registered by core, owned by `glueful/payvia`, with
mode `activation`. Turning it on prepares Payvia like any engine; Settings › Payments then configures
gateways. Commerce and Subscriptions don't require it: both work with manual collection.

**Payments off is a contract, not a notice.** Turning a capability off leaves its engine loaded, so
every place that starts an online payment must ask one question: *may a new online payment start?*
(a contract in `thallo-contracts`), answered yes only while Payments is effective. While it is off:

- **Stopped:** every new online payment initiation — Commerce payment links (sending and initiating),
  online checkout, Subscriptions self-serve checkout and plan checkout URLs. Each reports manual
  collection, as it does today with no gateway configured.
- **Continues:** settlement of payments already started, their webhooks, reconciliation, refunds of
  captured payments, and access to payment records and to the saved gateway settings (kept, not
  deleted).
- **Recurring subscriptions:** turning Payments off doesn't cancel provider-side subscriptions.
  Renewals that the provider charges keep settling through their webhooks; Thallo starts no new
  provider subscription. The turn-off confirmation says so in plain words.
- **Existing installs keep working** through the upgrade adoption (§7.3a): where Payvia is enabled
  and schema-ready when eligibility is captured (before the first provision's migrations on
  upgraded code) and Payments has no stored state, Payments is stored **on**,
  so its payments behave exactly as before. Where Payvia is enabled but not schema-ready, Payments
  starts off, as online payments didn't work there before either. Every other install starts with
  Payments off. A later provision never turns it back on after an operator turned it off.
- Settings › Payments with Payments off says so and links to turning it on, instead of "install a
  gateway extension".

### 7.8 What stays as it was

- Meilisearch stays an independent extension: Search picks it up when configured and works without it.
- Commerce and Subscriptions keep the activation flow, now declared by their packs.
- Everything in §3.2–§3.6 (the flow, fencing, crash points, workspaces, grants, turning off) applies
  unchanged to any `activation` capability, first- or third-party.

### 7.9 Testing (additions to §4)

- **The third-party proof** uses a fixture package installed like a real third-party extension,
  declaring an `activation` capability in its metadata, with its engine **disabled at the start**. It
  proves, end to end: the capability appears and is actionable while the engine is disabled; its row
  is synced; activation enables the engine, seeds the blocks the engine contributes (single store and
  every workspace), grants the permissions it declares, and turns it on; turning it off supersedes
  the activation and hides it, leaving the engine enabled and its data kept. Not merely that its
  declaration is accepted.
- **Off until finalized:** a newly discovered `activation` declaration over an engine that is
  already enabled and ready reads off, with no blocks or grants, until its activation finalizes; a
  `simple` capability with no stored state still follows its engine.
- **Adoption:** Commerce effective before the upgrade (no stored state, engine ready) is on after it;
  no stored state with `thallo.capabilities` set to `false` and the engine ready → off after it;
  an existing stored off is untouched; an activation in progress is untouched; upgrade → turn
  Payments off → run provision and the row sync again → still off; Payvia enabled but not
  schema-ready → Payments off and not adopted, including when that provision's own migrations make
  it ready, and on a retry after an interrupted provision; a fresh install that crashes after its
  migrations adopts nothing on the retry; an unreachable database stops provision before it
  migrates; two overlapping provisions keep the first committed capture.
- **Turning off with no row:** a first turn-on that begins while a turn-off of a capability with no
  activation row is in flight waits for it, so no runner predates the off decision (two processes).
- **Declarations:** a real boot declares exactly the first-party capability list, all before any
  provider boots.
- **Declarations:** each ambiguity in §7.3 is rejected and reported; `external_flow` renders its
  destination; a provider is resolved from the manifest, not from Thallo.
- **Misconfiguration blocks everything:** conflicting declarations against a capability already
  stored on make it ineffective, in both registration orders; start, continue, the CLI, the
  switchboard flip and `PUT /capabilities/{id}` refuse it; `extensions:enable`/`disable` and the
  admin toggle refuse the packages involved; an already-enabled engine stays loaded.
- **Rows:** a workspace seed in flight while a new row is created finishes first, and a seed started
  after the row exists seeds a capability that is preparing (two processes, attempts counted); the
  first turn-on of a capability with no row commits the row, then starts; reads write no row.
- **Required minimum:** configuration can add a required package and can't remove Aegis or Users.
- **Payments off:** each initiation point refuses with manual collection while off; a webhook for a
  payment started before the switch still settles; a refund still works; a renewal webhook still
  settles; an install with Payvia enabled comes out of the upgrade with Payments on.

### 7.10 Docs and changelog

The page is **Extensions › Capabilities / Installed** everywhere, the CLI is `thallo:capabilities:*`,
and the unreleased changelog bullets that said "Features" are corrected in place. Payments gets a
section in the capabilities concept page and in the payments guide, including what off stops and what
it keeps. The developer docs describe both declaration sources and the three modes.
