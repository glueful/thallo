# SP2a — Full Tenant Resolution + Tenant Management (Design)

> Slice 1 of SP2 (see `SP2-README.md` for the umbrella objective, dependency graph, shared
> invariants, and contract ledger — invariants cited as "SP2 index §3.n"). SP1 (enablement to
> ON, bootstrap_default runtime) is complete and MUST remain undisturbed while SP2a code is
> deployed-but-inactive.

## §1 Objective

Take the runtime from bootstrap_default (every request implicitly the default tenant) to
**full_resolution**: every public request resolves its tenant strictly from a verified host,
every tenant-data admin request from an explicit `X-Tenant-Id` selection, blob routes join the
resolution surface, and tenants beyond the first can be created and managed — while an
**unseeded tenant never serves** (operational activation is SP2b's seed success).

## §2 Ownership (three layers)

- **Framework (generic seams only, no tenancy behavior, no default bindings):**
  `BlobRouteMiddlewareProvider` + `BlobRouteAction`, `BlobPublicUrlProvider`, and generic
  `auth:optional` behavior needed by signed-or-authenticated VIEW.
- **Extension `glueful/tenancy` (tenant identity + resolution):** `tenant_domains`,
  `DomainResolver`, host normalization, resolver profiles, administration bridges.
- **Contracts `glueful/extension-contracts` (neutral seams):** `TenantAdministration`,
  `TenantDomainAdministration`, `TenantResolutionProbe` (new); `TenantProvisioner` stays
  retrofit-only.
- **Thallo (product policy):** activation flow, profile wiring, management HTTP/CLI/SPA,
  blob policy + URL generation, cache segmentation.

Release order (SP2 index §3.10): framework → contracts → extension → Thallo pins.

## §3 Framework seams (held; release before pinning)

### §3.1 `BlobRouteMiddlewareProvider`

```php
enum BlobRouteAction: string { case UPLOAD; case VIEW; case INFO; case DELETE; case SIGN; }

interface BlobRouteMiddlewareProvider
{
    /** @return list<string> middleware aliases to insert for this blob action */
    public function middlewareFor(BlobRouteAction $action): array;
}
```

Blob route **registration** soft-resolves the provider (providers boot before framework routes
load, so registration-time consultation is safe — verified). For UPLOAD/INFO/DELETE/SIGN it
inserts contributed middleware **after strict authentication, before rate limiting**. VIEW uses
generic `auth:optional` first: valid credentials populate the authenticated user, absent
credentials pass through for signed-grant validation, and invalid credentials still 401.
Contributed VIEW middleware follows optional auth and precedes rate limiting;
`UploadController::show()` remains the authoritative visibility/signature gate. Its access check
normalizes every private alias (`private|true|'true'|1`) through the existing
`requiresAuthFor('retrieve')` helper. Unbound providers add no middleware; the framework never
inspects contributed middleware behavior.

**VIEW ordering guarantee:** contributed middleware for VIEW runs after optional authentication
but before controller-level signature validation (`UploadController::show()` `:289`), with no
earlier strict-auth gate. The
framework therefore documents — and Thallo's VIEW contribution must honor — that VIEW middleware
MUST NOT reject requests that may carry a valid signed grant before the controller can validate
it (see §7.3 for how Thallo satisfies this). INFO keeps its existing route-level auth and does not
share VIEW's middleware list.

### §3.2 `BlobPublicUrlProvider`

Signed URLs currently build from the REQUEST host (`UploadController.php:381`,
`$request->getSchemeAndHttpHost()`), which is the central admin host when an editor generates
one from the admin API — a host the public resolver would never map. New generic seam:

```php
interface BlobPublicUrlProvider
{
    /**
     * Base URL (scheme + host) to compose this blob's public/signed URLs on,
     * or null to keep the request host.
     *
     * @param array<string,mixed> $blob
     */
    public function publicBaseUrl(array $blob): ?string;
}
```

Soft-resolved in `signedUrl()` (and any public-URL composition): non-null → that base replaces
the request host. Unbound → current behavior. Thallo's implementation returns the owning
tenant's **canonical public origin** (§7.3); it does not assume every tenant has a
`tenant_domains` row.

## §4 Contracts additions (`glueful/extension-contracts` → 1.2.0)

`TenantProvisioner` keeps its narrow retrofit contract (`provisionDefault`, `hasAnyTenant`) —
verified it cannot express management (`TenantProvisioner.php:24`). New neutral contracts,
implemented by the extension, consumed by Thallo controllers/CLI:

```php
interface TenantAdministration
{
    /** Creates in state PROVISIONING (never active); returns tenant uuid. */
    public function create(ApplicationContext $c, string $slug, string $name, string $ownerUserUuid): string;
    public function suspend(ApplicationContext $c, string $tenantUuid): void;
    public function reactivate(ApplicationContext $c, string $tenantUuid): void;
    /** Seed-success boundary: PROVISIONING → ACTIVE. SP2b's seeder is the intended caller. */
    public function markActive(ApplicationContext $c, string $tenantUuid): void;
    /** @return list<array{uuid:string,slug:string,name:string,status:string}> */
    public function listTenants(ApplicationContext $c, ?string $status = null): array;
    /** @return array{uuid:string,slug:string,name:string,status:string}|null */
    public function getTenant(ApplicationContext $c, string $tenantUuid): ?array;
    /** Active memberships joined to ACTIVE tenants for one user.
     *  @return list<array{uuid:string,slug:string,name:string,status:string}>
     */
    public function listTenantsForUser(ApplicationContext $c, string $userUuid): array;
    /** @return list<array{uuid:string,user_uuid:string,role:string,status:string}> */
    public function listMembers(ApplicationContext $c, string $tenantUuid): array;
    public function addMember(ApplicationContext $c, string $tenantUuid, string $userUuid, string $role): void;
    public function removeMember(ApplicationContext $c, string $tenantUuid, string $userUuid): void;
    public function setMemberRole(ApplicationContext $c, string $tenantUuid, string $userUuid, string $role): void;
}

interface TenantDomainAdministration
{
    /** Normalizes + validates host; returns domain uuid + DNS TXT verification token. @return array{uuid:string,token:string} */
    public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array;
    /** Performs the DNS TXT check now; returns the new verification_status. */
    public function verifyDomain(ApplicationContext $c, string $domainUuid): string;
    public function disableDomain(ApplicationContext $c, string $domainUuid): void;
    public function enableDomain(ApplicationContext $c, string $domainUuid): void;
    public function removeDomain(ApplicationContext $c, string $domainUuid): void;
    /** @return list<array{uuid:string,host:string,verification_status:string,status:string}> */
    public function listDomains(ApplicationContext $c, string $tenantUuid): array;
    /** @return array{uuid:string,tenant_uuid:string,host:string,verification_status:string,status:string}|null */
    public function getDomain(ApplicationContext $c, string $domainUuid): ?array;
    /** Pre-verified operator-controlled host (activation auto-mapping only). */
    public function addPreverifiedDomain(ApplicationContext $c, string $tenantUuid, string $host): string;
}

interface TenantResolutionProbe
{
    /**
     * Resolve one normalized host through the named public profile without consulting the
     * deployment activation gate. Used only by the activation verifier.
     */
    public function probePublicHost(ApplicationContext $c, string $host): ?string;
}
```

The administration implementation owns the lifecycle invariants, not its callers:

- `markActive` accepts `provisioning` only; `reactivate` accepts `suspended` only; no ordinary
  method can jump from `provisioning` to `active` except `markActive`.
- Roles are validated against the extension's configured role allowlist.
- The final ACTIVE owner cannot be removed or demoted. The owner-count check and mutation run in
  one transaction while locking the tenant's active-owner membership rows, so two concurrent
  requests cannot each remove what they observed as a non-final owner.
- `listTenantsForUser` excludes provisioning/suspended tenants. A bypass-holding platform
  operator uses `listTenants(..., 'active')` instead and can therefore switch to every ACTIVE
  tenant without requiring synthetic memberships.

## §5 Extension `glueful/tenancy` → 1.2.0

### §5.1 `tenant_domains` schema

`id` PK · `uuid` (12, unique) · `tenant_uuid` (12, FK→tenants ON DELETE CASCADE, indexed) ·
`host` string, **normalized, globally UNIQUE** · `verification_status`
(`pending|verified`, default `pending`) · `status` (`active|disabled`, default `active`) ·
`verification_token` nullable · `verified_at` nullable · timestamps.

Two **independent** columns (pinned): verification is a fact about DNS control; status is an
operator choice. A domain resolves publicly **iff** `verification_status=verified` AND
`status=active` AND its tenant's `status='active'` (per the §8 lifecycle, `active` means
seeded + operational — `provisioning` and `suspended` both fail the conjunct). Suspension
never rewrites domain rows — the tenant-status conjunct makes them unavailable and
reactivation restores the exact prior configuration (pinned). Removal frees the host;
suspension does not (no-takeover pin).

### §5.2 Host normalization (single shared implementation)

Used by the resolver AND every write path: lowercase → strip trailing dot → strip port →
IDN→ASCII (punycode). Reject: IP literals, wildcard input, malformed hosts, hosts colliding
with the configured base domain's reserved labels (`www`, `api`, `admin`, configurable), and
the base domain itself unless explicitly allowed for the default tenant's apex mapping.

The public-origin configuration is one explicit shape shared by activation, subdomain
resolution, normalization, and blob URL generation:

```php
'tenancy.public_origin' => [
    'scheme' => 'https',             // `http` allowed only for explicit local/dev configuration
    'base_domain' => 'sites.test',   // tenant subdomains: {tenant-slug}.sites.test
    'default_hosts' => ['sites.test', 'www.sites.test'],
    'reserved_labels' => ['www', 'api', 'admin'],
],
```

`default_hosts` are the activation-required hosts. While full resolution is active,
`disableDomain`/`removeDomain` MUST reject an operation that would disable or remove the last
active mapping for any required host. SP2c must lower full resolution before those mappings can
be dismantled.

`SubdomainResolver` is migrated from the legacy `tenancy.subdomain.base_domain` key to this
single `public_origin.base_domain` source and uses `HostNormalizer` for both configured and
request hosts. Tenant creation validates `{slug}.{base_domain}` through the same rules, including
reserved-label rejection; a tenant slug can never claim `admin`, `api`, or another reserved host.

### §5.3 Host extraction

Host extraction uses Symfony `Request::getHost()` after the framework's existing
`RequestProvider` configures trusted proxies from `security.trusted_proxies`. The extension does
not introduce a second proxy allowlist. Forwarded host headers are therefore honored only for
the same framework-trusted proxies used by the rest of the request stack; otherwise `Host` wins.

### §5.4 `DomainResolver` + precedence

Exact normalized-host match in `tenant_domains` (verified+active+tenant-active) → tenant uuid.
Ordered **before** `SubdomainResolver` in every public profile: an exact custom-domain match
takes precedence over subdomain inference (pinned).

### §5.5 Resolver profiles

Config shape (extension):

```php
'tenancy.profiles' => [
    'public' => ['resolvers' => ['domain', 'subdomain'], 'require_membership' => false,
                 'require_authenticated' => false],
    'admin'  => ['resolvers' => ['header', 'jwt'], 'require_membership' => true,
                 'uuid_only' => true, 'conflict' => 'reject'],
],
```

Middleware alias takes the profile plus an optional `soft` flag: `tenant:public`,
`tenant:admin`, `tenant:public:soft`. Pipeline enforcement per profile: `public` requires a
resolvable ACTIVE tenant, **no membership**; `admin` collects **both** header and JWT-claim
candidates — if both present and disagreeing → **reject 409/403** (pinned P2: a header-only
profile cannot detect the conflict), else header is the SPA's authoritative selection;
membership/bypass mandatory; UUIDs only (slugs rejected). `hide_existence` semantics carry
over unchanged.

**`soft` variant:** resolves and attaches the tenant when the profile CAN resolve one, but on
failure attaches nothing and passes through instead of rejecting — for routes whose
authoritative gate lives deeper (blob VIEW's controller-level signature validation +
ownership-first policy, §7.3). Soft never substitutes a fallback tenant; it only declines to
block.

**Inert-before-activation (pinned):** every profile middleware consults the same
`FullTenantResolutionReadiness` instance used by `TenantRuntimeReadiness`; it passes through
without resolving unless `isReady()` is true. The raw `tenancy.resolution` flag is never an
independent middleware gate. SP1 bootstrap mode therefore continues working with SP2a fully
deployed, and a degraded required-host mapping cannot leave profiles active while runtime mode
has fallen to `none`.

### §5.6 Administration bridges

`ContractTenantAdministration`, `ContractTenantDomainAdministration`, and
`ContractTenantResolutionProbe` implement §4 over the concrete models/resolution pipeline (same
pattern as `ContractTenantProvisioner` — the only crossing point).
`create()` inserts with `status='provisioning'`; `markActive()` is the ONLY transition to
`active` besides the SP1 retrofit's default tenant (created active — it has real content).
Tenant + initial-owner creation is one transaction. Preverified-domain creation is idempotent by
normalized host. Required-host protection soft-resolves the neutral
`FullTenantResolutionReadiness` only inside disable/remove methods, never in the domain bridge
constructor; this avoids a readiness→domain-reader→readiness DI cycle. No extension-local
interface is implemented by Thallo. Thallo uses `getTenant`/`getDomain`/`listDomains` and never
queries extension-owned tables directly.

## §6 Thallo activation — `FullResolutionActivation`

Gated + resumable, persisted in `thallo_system_flags` (`tenancy.resolution_step`), mirroring
SP1 finalize's crash-safety:

1. **map_default_hosts:** auto-provision pre-verified domains (`addPreverifiedDomain`) for the
   default tenant covering the configured apex/base hosts (operator controls them → no DNS
   dance). Idempotent.
2. **verify_wiring:** profiles configured; CORS allows `X-Tenant-Id`; default tenant's hosts
   verified+active; route groups carry their profiles (probe the route table).
3. **rebuild_route_cache:** clear + rebuild (route metadata changed with profile middleware).
4. **fresh-boot verification boundary:** a NEW process (same needsFreshBoot discipline as SP1)
   calls `TenantResolutionProbe::probePublicHost()` with a default-tenant host and proves that
   the named public profile's real resolver/validation pipeline returns the default tenant. The
   probe bypasses ONLY the deployment activation gate; it does not bypass host normalization,
   domain/subdomain precedence, tenant existence, or active-status validation. This is a direct
   service probe, not a routed HTTP request (the real middleware is intentionally inert until
   activation); route-table inspection in step 2 independently proves placement. Only then —
5. **complete full atomically:** one DB transaction validates the expected activation step and
   writes BOTH `tenancy.resolution='full'` and activation step `FULL`. A crash cannot expose FULL
   status with an inactive runtime or vice versa; retry from the unchanged pre-completion step is
   safe.

Thallo binds one shared `FullTenantResolutionReadiness` → `isReady()` = flag is `full` + every
required default host still has an active mapping. Both profile middleware and the existing
`TenantRuntimeReadiness` composite consume that SAME predicate. `mode()` flips via the composite
(SP2 index §3.3/§3.4). SP2a tightens `BootstrapTenantCreationGuard`: creation is permitted ONLY
when mode is exactly `full_resolution`; both `bootstrap_default` and `none` throw. **One-way in
SP2a**; lowering is SP2c.
CLI: `thallo:tenancy:resolution:activate|status` (status-first dispatch like SP1's enable).

## §7 Surfaces

### §7.1 Routes + coverage test

Public: `tenant:public → tenant_bootstrap → caches…`. Admin tenant-data:
`auth → tenant:admin → tenant_bootstrap → permission`. `tenant_bootstrap` remains the
fail-closed backstop (resolved → passthrough; full mode + unresolved → 503) — SP1 code
untouched. `RouteCoverageTest` evolves (pinned): `tenant_bootstrap` no longer asserted at
index 0 but **immediately after the authoritative resolver** and before tenant-data/cache
middleware; exactly-one-marker rule stands (`tenant:*` profiles are resolver companions, not
markers). System routes (`my-tenants`, switch, extensions, health, identity) stay
`tenant_system`.

### §7.2 Management HTTP + CLI + SPA

- HTTP (`/v1/admin/tenancy/*`, `tenant_system` for pre-selection endpoints, `tenant:admin` +
  markers for tenant-scoped ones): `my-tenants` uses `listTenantsForUser()` for ordinary users
  and all ACTIVE tenants from `listTenants()` for bypass-holding platform operators; tenants
  create/suspend/reactivate/list (create → `assertCanCreateTenant()` then
  `TenantAdministration::create` → **provisioning**); domains add/verify/enable/disable/remove/
  list; memberships list/add/remove/set-role. Platform-operator endpoints require the bypass
  permission. Membership mutation responses preserve the final-owner invariant from §4.
- CLI: `thallo:tenancy:tenant:create|suspend|reactivate|list`,
  `domain:add|verify|enable|disable|remove|list`, `member:add|remove|set-role|list`.
- SPA: switcher fed by `my-tenants`; selected UUID in Pinia + localStorage, validated at
  startup; `X-Tenant-Id` injected ONLY in `authFetch`; 403 → clear selection, refresh
  my-tenants, open switcher. **No tenant-creation UI in SP2a** (pinned: UI creation ships
  after SP2b makes created tenants operational). Management screens: domains + members +
  suspend for platform operators; creation stays API/CLI.

### §7.3 Blob surface

- Thallo binds `BlobRouteMiddlewareProvider`: `VIEW → ['tenant:public:soft']`, all others →
  `['tenant:admin']`. The VIEW contribution is the **soft** variant of the public profile
  (pinned P1 #1): it resolves and attaches the tenant when the host maps, but does NOT reject
  an unmapped host — it attaches nothing and lets the controller's signature validation +
  `TenantBlobPolicy`'s ownership-first denial decide. Anonymous public serving on a tenant
  host resolves normally; a signed URL on any host reaches signature validation; an
  unowned/unauthorized blob is still denied by policy (SP2 index §3.5). Fail-closed authority
  for blobs is the POLICY (ownership-first), not the router.
- Thallo binds `BlobPublicUrlProvider`: signed/public URLs are generated on the owning tenant's
  **canonical public origin**, resolved independently of the current request host:
  1. default tenant → first active configured `default_hosts` mapping (configuration order),
  2. other tenant with a verified+active custom domain → earliest verified custom domain,
  3. otherwise → the managed `{tenant-slug}.{base_domain}` subdomain.
  The scheme comes from `tenancy.public_origin.scheme`. This deterministic v1 rule deliberately
  does not claim an operator-selectable "primary domain"; adding that control later can replace
  step 2 without changing the provider contract. Before full activation, absence of a complete
  public-origin configuration returns null and preserves framework behavior. While
  `FullTenantResolutionReadiness::isReady()` is true, inability to derive an origin THROWS — it
  never falls back to the central request host.
- `TenantBlobPolicy` is unchanged: after the framework's visibility/auth/signature gate, it
  already loads `media_assets` ownership and requires the resolved request tenant to equal the
  owner. That existing comparison is what rejects replaying a path+query signature on another
  tenant host; SP2a adds tests, not a signature-specific policy shortcut.

### §7.4 Caches

Media/blob URL cache joins the segmented surfaces (`tenant:{uuid}:…`, fail-closed —
SP2 index §3.6). Domain add/verify/enable/disable/remove purges host-sensitive caches
(render page/error, SEO sitemap, routing) for the affected tenant's segment.

## §8 Tenant lifecycle (pinned P1 #3)

```
provisioning ──(SP2b seed succeeds → markActive)──► active ◄──reactivate── suspended
     │                                                 │
     └── never resolves publicly,                      └──suspend──► suspended
         never selectable in my-tenants,
         admin access: platform operators only (to manage/inspect)
```

- `provisioning`: resolvable by NO public profile; excluded from `my-tenants`; domains can be
  added/verified but do not resolve; visible to platform operators.
- `active`: full operation.
- `suspended`: public 404 (tenant-status conjunct, domains untouched — pinned P1 #4); member
  admin access 403; platform operators retain management access; reactivation restores prior
  domain configuration exactly. **No hard deletion in SP2a** — a destructive deletion flow
  (host retention/takeover policy) is explicitly deferred.
- SP2a defines `markActive()` as the seed-success boundary; SP2b's seeder calls it. SP2a's
  acceptance uses a test-only seed stub to cross the boundary (§10).
- The SP1 default tenant is `active` from the retrofit (it owns the pre-existing content).

## §9 Failure modes

Unknown/unverified/disabled host → 404 (no default fallback; SP2 index §3.1). Provisioning or
suspended tenant on public → 404. Missing/invalid/non-member `X-Tenant-Id` on tenant-data
admin routes → fail closed (403/404 per hide_existence). Header/JWT disagreement → reject.
Membership revoked mid-session → 403 + SPA recovery. Activation crash at any step → resumable,
flag never set before the fresh-boot proof. Blob VIEW on unmapped host without valid signature
→ denied by policy. Domain removal frees the host; suspension does not. Required default-host
removal/disable while full resolution is active → conflict/refusal. Runtime mode `none` never
un-gates tenant creation.

## §10 Testing

- **Acceptance (the SP2a definition of done):** activate full resolution (real fresh-boot
  boundary) → default tenant serves on apex exactly as before → create tenant two (lands
  `provisioning`; public 404; absent from my-tenants) → test-seed → `markActive` → subdomain
  AND verified custom domain serve isolated content → admin switches via `X-Tenant-Id` and
  sees only the selected tenant's data → **signed blob URL generated from the central admin
  API carries the tenant host; anonymous GET succeeds; wrong host or tampered signature
  fails** (pinned) → suspension: public 404, domains rows untouched, reactivation restores.
- **Inert-mode regression:** full SP1 suites green with SP2a deployed-but-inactive.
- **Blob regressions:** with `uploads.access=private`, an anonymous valid signed VIEW reaches the
  controller and succeeds on the owner tenant's host; authenticated unsigned private VIEW still
  succeeds through `auth:optional`; invalid credentials fail; anonymous unsigned public blobs
  remain denied for every private alias (`private|true|'true'|1`); INFO remains strictly
  authenticated. A subdomain-only tenant receives a signed URL on `{slug}.{base_domain}` rather
  than the central admin host. Full-resolution origin failure throws instead of falling back.
- Normalization table-tests (IDN, port, trailing dot, IP literal, wildcard, base-domain
  collisions); precedence (exact domain beats subdomain); header/JWT conflict rejection;
  no-takeover-after-suspension; provisioning-tenant invisibility; RouteCoverageTest evolution;
  per-surface fail-closed cases from §9; PostgreSQL race with two owners/two different removals
  leaves exactly one owner (and same-sole-owner attempts both fail);
  `my-tenants` ordinary-membership vs platform-bypass behavior; creation refused in both
  `bootstrap_default` and `none`; required-host mutation refusal.

## §11 Out of scope (SP2a)

Seed/sync + provenance (SP2b — `markActive` is the only coupling point); disable/lowering the
resolution flag (SP2c); tenant deletion + host-retention policy; custom-domain TLS automation;
background re-verification jobs (manual/CLI re-check only); tenant-creation UI (post-SP2b).
