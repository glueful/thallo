# Platform Payments Settings — Design

Move the installation-level Payvia gateway credentials out of Commerce into an app-owned,
platform-only Settings → Payments surface, backed by the unscoped system channel — so ambient
tenant context can never choose who receives money, and neither `thallo.commerce` nor
`thallo.subscriptions` capability state can alter payment credential resolution.

## §0 Why (verified state of the world)

Two consumers now charge through one Payvia configuration chain with contradictory scoping:

- `SettingsStorePayviaOverride` (thallo-commerce) declares: *"gateway credentials are
  INSTALLATION-level, not workspace-level… when tenancy enforcement lands, these keys must be
  pinned to the global scope explicitly (spec §3.6 records this as enforcement-time work)."*
- Its storage (`CommerceSettingsBridge` → `SettingsStore` → the `settings` table) is
  tenant-owned, and the workspace self-serve billing routes run inside
  `runAsTenant(workspace)` — so a workspace's stored keys can shadow the platform's for a
  PLATFORM subscription charge (wrong merchant), and webhook secrets could diverge from the
  account that signs the events.
- The override binds in `CommerceIntegrationServiceProvider` and gates on the commerce store's
  availability: disabling `thallo.commerce` silently reverts subscriptions billing AND webhook
  verification to env credentials. A seam consumed by two packs cannot be owned by one.

This program is the recorded enforcement-time obligation, executed — triggered by the shipped
workspace self-serve checkout.

## §1 Rulings (maintainer-pinned)

1. **App-owned platform payment store.** `PayviaSettingsOverride` is bound OUTSIDE Commerce and
   backed directly by the `SystemChannel` (`thallo_system_flags` — unscoped, pre-tenant,
   platform-only). Payvia keys are dynamic, so the exact-match `SystemKeys::KEYS` mechanism
   gains prefix support.
2. **Conservative migration.** Existing rows may have been stamped onto the DEFAULT TENANT
   during the tenancy retrofit, not left in a global sentinel scope. The migration must:
   adopt the default-workspace value only when no platform value exists; preserve platform
   values already present; verify before deleting legacy rows; refuse or diagnose conflicting
   values across workspaces rather than choosing one silently; be idempotent; retain at-rest
   encryption unchanged.
3. **Product limitation stated prominently.** Until Payvia supports explicit merchant
   connections, EVERY Thallo storefront and SaaS subscription settles through the single
   platform gateway account. The new UI says this clearly. It is not workspace payment
   isolation.
4. **No capability dependency.** Credential resolution is independent of `thallo.commerce` and
   `thallo.subscriptions` — proven by tests.
5. **The later merchant-connection model** (`platform` | `workspace:{workspace_uuid}` scopes in
   Payvia, workspace-owned gateway connections, per-connection webhook routing that identifies
   the connection BEFORE selecting and verifying its secret) is a separate upstream program and
   must not be undone by this work. Recorded in OUTSTANDING; nothing here presumes its shape
   beyond not blocking it.

## §2 Components (all app-level, per the maintainer's placement)

**`App\Settings\PlatformPaymentSettingsStore`** — the platform store over `SystemChannel`:
`get(string $key): ?string`, `putMany(array<string,string> $pairs): void`,
`forget(string $key): void`. Secrets (`secret_key`, `webhook_secret` subkeys) stored ENCRYPTED
exactly as today (framework `EncryptionService`, AAD = the full settings key string — unchanged
AAD means legacy ciphertext migrates VERBATIM, no re-encryption). Non-secret keys plain.
Null-never-throw on read (undecryptable/tampered ⇒ null, env fallback stays authoritative).

**`App\Settings\PlatformPayviaSettingsOverride implements PayviaSettingsOverride`** — the
replacement seam implementation: same structural whitelist as today (`payvia.default_gateway`
plus `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for ids present in the
`payvia.gateways` CONFIG map; ops knobs never editable), same null-never-throw contract, ZERO
capability gates, reads `PlatformPaymentSettingsStore` only — the `SystemChannel` is unscoped,
so tenant context cannot alter resolution BY CONSTRUCTION, not by containment.

**`SystemKeys` prefix support** — `isSystem()` matches the exact `KEYS` list OR a new
`PREFIXES` list containing `payvia.`; `SettingsStore` routing is otherwise unchanged. Side
effect (intended): any legacy tenant-stamped `payvia.*` row in `settings` becomes unreachable
through `SettingsStore` — which is why the migration runs in the same program.

**Binding** — the app binds `PayviaSettingsOverride::class` ⇒ `PlatformPayviaSettingsOverride`;
the Commerce binding and `SettingsStorePayviaOverride` are REMOVED in the same change (no
first-wins ambiguity may survive; a test proves resolution is identical with commerce enabled,
disabled, and absent). The binding mechanism must be effective on cached production boots
(the established rule: extension-provider `register()` never runs there; app-level wiring must
use a path proven to run in both boot modes).

**Migration** — console command `thallo:payments:migrate-platform-credentials` (platform
authority, non-destructive by default):
- Sources considered, in order: (a) existing platform values in the system channel (always
  preserved — never overwritten); (b) `payvia.*` rows in the `settings` table belonging to the
  DEFAULT workspace (single-store's effective global scope, possibly default-stamped by the
  tenancy retrofit) — adopted only where (a) has no value; (c) `payvia.*` rows under any OTHER
  workspace — never adopted: reported as conflicts requiring the operator's explicit
  resolution (`--adopt-from=<tenant_uuid>` per key group, or manual cleanup).
- Ciphertext copied verbatim (AAD unchanged); post-copy VERIFICATION decrypts every migrated
  secret through the new store and compares against the legacy read path BEFORE any legacy row
  is deleted; `--prune-legacy` is a separate, explicit second step that re-verifies first.
- Idempotent: re-running with nothing to do reports cleanly and changes nothing.

**Neutral controller** — `GET|PUT /v1/admin/settings/payments`, platform chain
`['auth', 'tenant_system', 'content_permission:tenancy.manage']`, names
`thallo.settings.payments.*`, registered from app-level routes (no pack capability gate).
GET returns per key `{value|masked, default, overridden}` — secret values are WRITE-ONLY
(masked on read: presence + last-4 only). PUT accepts the whitelisted keys, validates gateway
ids against the config map, encrypts secrets, 422s unknown/invalid keys. The
gateway-capability probe surface from the self-serve switch (`SelfServeGatewayCapability`)
stays where it is; this page links to it, not vice versa.

**SPA** — `Settings → Payments` page (platform nav, `manage_platform`-gated like
`/settings/workspaces`): default gateway select, per-gateway enable + credential fields
(write-only secrets with presence indicators), and the §1.3 limitation notice rendered
PROMINENTLY (alert, not fine print): "All revenue on this installation — storefront orders and
workspace SaaS subscriptions — settles through this single platform gateway account. Workspace
payment isolation requires merchant connections, which are not yet available."

**Commerce retirement** — the `/v1/admin/commerce/payments` endpoints and the Commerce
Payments SPA page/tab are REMOVED; commerce nav/settings link to the new page for operators
holding platform authority (workspace-only admins simply no longer see payment settings —
correct, since the credentials were never theirs). `SettingsStorePayviaOverride` and its
binding are deleted from the commerce pack; the commerce store-settings spec §3.6 obligation
is annotated as satisfied by this program. Commerce InertnessTest/spec pins updated.

## §3 Tests (binding)

- **Capability independence:** Payvia credential resolution (a seeded platform secret) is
  byte-identical with `thallo.commerce` on, off, and the commerce provider absent; likewise
  for `thallo.subscriptions`.
- **Tenant-context independence:** resolving gateway config inside `runAsTenant(workspace)` —
  including a workspace whose `settings` table carries hostile legacy `payvia.*` rows —
  returns the platform values for keys AND webhook secrets; the self-serve checkout path and
  the webhook verification path both covered.
- **Migration matrix:** platform-value-preserved; default-workspace-adopted-when-absent;
  cross-workspace conflict refused with diagnostics; verify-before-prune (a corrupted copy
  aborts pruning); idempotent re-run; ciphertext round-trips without re-encryption.
- **Prefix routing:** `payvia.*` reads/writes route to the system channel; legacy tenant rows
  unreachable through `SettingsStore` after the switch; non-payvia keys unaffected.
- **Controller:** platform-authority matrix (workspace `billing.manage`-only actor ⇒ 403);
  secret masking on GET; write-only round-trip; 422 matrix.
- **SPA:** page states, masked secrets, the limitation notice present, nav gating.
- **Regression:** commerce storefront checkout and subscriptions self-serve checkout both
  originate against the platform credentials under ambient workspace context (end-to-end with
  recording gateway doubles).

## §4 Out of scope

Workspace-owned gateway connections and Payvia's explicit `platform | workspace:{uuid}`
merchant scopes; per-connection webhook routing (single tenantless secret per gateway cannot
serve N workspace accounts — the connection must be identified before signature verification);
paid-membership revenue surfaces. All recorded as one OUTSTANDING follow-up entry
("Workspace merchant connections") so this program's structures are not undone by it.
