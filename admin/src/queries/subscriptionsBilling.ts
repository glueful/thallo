import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// Admin SPA module for thallo-subscriptions (Task 11, Phase B) -- wraps the platform Plans admin
// API (Task 8, `PlansController`) and the workspace billing admin API + meta (Task 9,
// `WorkspaceBillingController`/`MetaController`), all under `/v1/admin/subscriptions`. Neither
// pack has run through OpenAPI codegen yet (see `api/schema.d.ts`'s absence of any
// `/subscriptions` path), so every fetcher here goes through `authFetch` -- mirrors
// `queries/extensions.ts`/`queries/tenants.ts`'s identical un-typed-client convention -- rather
// than the typed `client`.

const base = () => `${runtimeConfig.apiBase}/subscriptions`

// ── Meta ─────────────────────────────────────────────────────────────────────
// `GET /meta` (MetaController::show()) -- 200 ALWAYS, in every engine state and tenancy mode. The
// one status probe both the Plans and Billing pages fetch FIRST (binding "meta-first fetch"
// behavior rule): a disabled/unmigrated engine still degrades gracefully rather than surfacing an
// error, and `default_tenant_uuid: null` is a REPRESENTABLE state (no default workspace
// established yet in single-store mode), never an error condition.

export type SubscriptionsEngineState = 'engine_disabled' | 'schema_not_ready' | 'ready'

// Task 15 (Phase C, workspace self-serve checkout plan, spec §5.1): the platform-only kill switch
// for self-serve subscription checkout, plus the SAME gateway-capability verdict the write
// endpoint enforces before allowing an enable -- exposed here purely for the Billing page to
// explain an unavailable switch (no capable Payvia gateway configured) without ever attempting,
// and being refused, the write itself.
export type SelfServeGatewayCapableReason = 'payvia_unavailable' | 'gateway_not_capable' | null

export interface SubscriptionsMeta {
  engine: SubscriptionsEngineState
  tenancy_enabled: boolean
  default_tenant_uuid: string | null
  self_serve_checkout_enabled: boolean
  /** The configured default Payvia gateway's name (e.g. `paystack`), null only when payvia
   * itself isn't available -- present even when NOT capable, so the UI can name it concretely. */
  self_serve_gateway: string | null
  self_serve_gateway_capable: boolean
  self_serve_gateway_capable_reason: SelfServeGatewayCapableReason
}

function normalizeEngineState(value: unknown): SubscriptionsEngineState {
  return value === 'schema_not_ready' || value === 'ready' ? value : 'engine_disabled'
}

function normalizeSelfServeGatewayCapableReason(value: unknown): SelfServeGatewayCapableReason {
  return value === 'payvia_unavailable' || value === 'gateway_not_capable' ? value : null
}

export async function fetchSubscriptionsMeta(): Promise<SubscriptionsMeta> {
  const json = await authFetch(`${base()}/meta`)
  const raw = (json.data ?? json) as Record<string, unknown>
  return {
    engine: normalizeEngineState(raw.engine),
    tenancy_enabled: raw.tenancy_enabled === true,
    default_tenant_uuid: typeof raw.default_tenant_uuid === 'string' ? raw.default_tenant_uuid : null,
    self_serve_checkout_enabled: raw.self_serve_checkout_enabled === true,
    self_serve_gateway: typeof raw.self_serve_gateway === 'string' ? raw.self_serve_gateway : null,
    self_serve_gateway_capable: raw.self_serve_gateway_capable === true,
    self_serve_gateway_capable_reason: normalizeSelfServeGatewayCapableReason(raw.self_serve_gateway_capable_reason),
  }
}

export const qkSubscriptionsMeta = () => ['subscriptions', 'meta'] as const

export function useSubscriptionsMeta() {
  return useQuery({ key: qkSubscriptionsMeta(), query: fetchSubscriptionsMeta })
}

// `PUT /self-serve` ({@see \Thallo\Subscriptions\Http\SelfServeSettingsController}) -- body
// strictly `{enabled: bool}`. Refused 409 `no_capable_gateway` when enabling without a capable
// default gateway configured; disabling always succeeds. Returns only the new switch state (not
// full meta), so the mutation invalidates the meta query rather than trying to reconstruct it.

export async function setSelfServeCheckoutEnabled(enabled: boolean): Promise<boolean> {
  const json = await authFetch(`${base()}/self-serve`, {
    method: 'PUT',
    body: JSON.stringify({ enabled }),
  })
  const raw = (json.data ?? json) as Record<string, unknown>
  return raw.self_serve_checkout_enabled === true
}

export function useSelfServeCheckoutMutation() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (enabled: boolean) => setSelfServeCheckoutEnabled(enabled),
    onSettled: () => cache.invalidateQueries({ key: qkSubscriptionsMeta() }),
  })
}

// ── Plans (Task 8) ───────────────────────────────────────────────────────────
// `GET/POST /plans`, `PATCH /plans/{key}`, `POST /plans/{key}/archive`,
// `POST /plans/import-config` -- thin delegates to `PlanManagementService`'s platform-scope
// methods. Every plan row mirrors `subscription_plans` field-for-field (PlanPayloadValidator's
// exact validated field set); `plan_key` is immutable once created (a `PATCH` payload may omit it
// but never change it -- the engine 422s verbatim on an attempted change).

export type PlanStatus = 'draft' | 'active' | 'archived'
export type EntitlementValue = boolean | number | null
export type PlanEntitlements = Record<string, EntitlementValue>

/** Gateway key (e.g. `stripe`, `paystack`) -> provider-side identifier (Stripe price id,
 * Paystack plan code) -- `subscription_plans.provider_identifiers`, Task 19 (spec §4.2/§5.3).
 * `PlanPurchasability` is the sole checkout-purchasability authority read from this map;
 * `provider_price_id` below is compat-only and never consulted for purchasability. */
export type ProviderIdentifiers = Record<string, string>

export interface SubscriptionPlan {
  uuid: string
  plan_key: string
  display_name: string
  description: string | null
  entitlements: PlanEntitlements
  provider_price_id: string | null
  provider_identifiers: ProviderIdentifiers
  status: PlanStatus
  sort_order: number
  created_at: string | null
  updated_at: string | null
}

export interface CreatePlanInput {
  plan_key: string
  display_name: string
  description?: string | null
  entitlements: PlanEntitlements
  provider_price_id?: string | null
  /** PATCH/POST both send the FULL map -- replacement semantics (2.2.0 contract), never a merge. */
  provider_identifiers?: ProviderIdentifiers
  status: PlanStatus
  sort_order?: number
}

export interface UpdatePlanInput {
  display_name?: string
  description?: string | null
  entitlements?: PlanEntitlements
  provider_price_id?: string | null
  /** Sending `{}` clears the map entirely -- there is no separate add/remove endpoint. */
  provider_identifiers?: ProviderIdentifiers
  status?: PlanStatus
  sort_order?: number
}

function normalizeEntitlements(raw: unknown): PlanEntitlements {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return {}
  const out: PlanEntitlements = {}
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (typeof value === 'boolean' || typeof value === 'number' || value === null) {
      out[key] = value
    }
  }
  return out
}

function normalizePlanStatus(value: unknown): PlanStatus {
  return value === 'draft' || value === 'archived' ? value : 'active'
}

/** Absent/null normalizes to `{}` (never dropped -- the editor always has a map to render), and
 * any entry whose value isn't a string is dropped rather than surfaced malformed. */
function normalizeProviderIdentifiers(raw: unknown): ProviderIdentifiers {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return {}
  const out: ProviderIdentifiers = {}
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (typeof value === 'string') out[key] = value
  }
  return out
}

function normalizePlan(raw: Record<string, unknown>): SubscriptionPlan {
  return {
    uuid: String(raw.uuid ?? ''),
    plan_key: String(raw.plan_key ?? ''),
    display_name: String(raw.display_name ?? ''),
    description: typeof raw.description === 'string' ? raw.description : null,
    entitlements: normalizeEntitlements(raw.entitlements),
    provider_price_id: typeof raw.provider_price_id === 'string' ? raw.provider_price_id : null,
    provider_identifiers: normalizeProviderIdentifiers(raw.provider_identifiers),
    status: normalizePlanStatus(raw.status),
    sort_order: typeof raw.sort_order === 'number' ? raw.sort_order : 0,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

export async function fetchPlans(): Promise<SubscriptionPlan[]> {
  const json = await authFetch(`${base()}/plans`)
  const raw = (json.data ?? json) as { plans?: unknown[] }
  const rows = Array.isArray(raw.plans) ? raw.plans : []
  return rows.map((p) => normalizePlan(p as Record<string, unknown>))
}

export async function createPlan(input: CreatePlanInput): Promise<SubscriptionPlan> {
  const json = await authFetch(`${base()}/plans`, { method: 'POST', body: JSON.stringify(input) })
  return normalizePlan((json.data ?? json) as Record<string, unknown>)
}

export async function updatePlan(planKey: string, input: UpdatePlanInput): Promise<SubscriptionPlan> {
  const json = await authFetch(`${base()}/plans/${encodeURIComponent(planKey)}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
  return normalizePlan((json.data ?? json) as Record<string, unknown>)
}

export async function archivePlan(planKey: string): Promise<SubscriptionPlan> {
  const json = await authFetch(`${base()}/plans/${encodeURIComponent(planKey)}/archive`, {
    method: 'POST',
  })
  return normalizePlan((json.data ?? json) as Record<string, unknown>)
}

export interface ImportPlansConfigInput {
  force?: boolean
  status?: PlanStatus
}

export async function importPlansConfig(input: ImportPlansConfigInput = {}): Promise<SubscriptionPlan[]> {
  const json = await authFetch(`${base()}/plans/import-config`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
  const raw = (json.data ?? json) as { plans?: unknown[] }
  const rows = Array.isArray(raw.plans) ? raw.plans : []
  return rows.map((p) => normalizePlan(p as Record<string, unknown>))
}

export const qkPlans = () => ['subscriptions', 'plans'] as const

export function usePlans(enabled: MaybeRefOrGetter<boolean> = true) {
  return useQuery({ key: qkPlans(), query: fetchPlans, enabled })
}

export function usePlanMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qkPlans() })
  return {
    create: useMutation({
      mutation: (input: CreatePlanInput) => createPlan(input),
      onSettled: invalidate,
    }),
    update: useMutation({
      mutation: (vars: { planKey: string; input: UpdatePlanInput }) => updatePlan(vars.planKey, vars.input),
      onSettled: invalidate,
    }),
    archive: useMutation({
      mutation: (planKey: string) => archivePlan(planKey),
      onSettled: invalidate,
    }),
    importConfig: useMutation({
      mutation: (input?: ImportPlansConfigInput) => importPlansConfig(input),
      onSettled: invalidate,
    }),
  }
}

// ── Workspace billing (Task 9) ───────────────────────────────────────────────
// `GET /workspaces` (paginated directory + subscription join), `GET /workspaces/{uuid}` (detail +
// overrides), `PUT .../plan`, `POST .../cancel`, `PUT`/`DELETE .../overrides/{entitlement}`.

export interface WorkspaceTenant {
  uuid: string
  slug: string
  name: string
  status: string
  deleted_at: string | null
  deleted_from_status: string | null
  purge_after: string | null
}

/** `WorkspaceBillingController::projectSubscription()`'s exact projected shape -- never the raw
 * engine subscription row. */
export interface WorkspaceSubscriptionSummary {
  status: string
  plan_key: string | null
  plan_display_name: string | null
  trial_ends_at: string | null
  grace_ends_at: string | null
  provider_managed: boolean
}

export interface WorkspaceRow {
  tenant: WorkspaceTenant
  subscription: WorkspaceSubscriptionSummary | null
}

export interface WorkspaceListFilters {
  page?: number
  perPage?: number
}

export interface WorkspaceListPage {
  rows: WorkspaceRow[]
  total: number
  current_page: number
  per_page: number
  total_pages: number
  has_next_page: boolean
  has_previous_page: boolean
}

/** `OverrideRepository::listForSubject()`'s exact per-row projection -- every row the workspace
 * ever had for its subject triple, ACTIVE AND EXPIRED alike (never pre-filtered to active-only,
 * unlike the `activeForSubject()` value-map other callers use). */
export interface WorkspaceOverride {
  entitlement: string
  value: unknown
  expires_at: string | null
  reason: string | null
  created_at: string | null
  updated_at: string | null
}

export interface WorkspaceDetail {
  tenant: WorkspaceTenant
  subscription: WorkspaceSubscriptionSummary | null
  overrides: WorkspaceOverride[]
}

function normalizeTenant(raw: Record<string, unknown>): WorkspaceTenant {
  return {
    uuid: String(raw.uuid ?? ''),
    slug: String(raw.slug ?? ''),
    name: String(raw.name ?? ''),
    status: String(raw.status ?? ''),
    deleted_at: typeof raw.deleted_at === 'string' ? raw.deleted_at : null,
    deleted_from_status: typeof raw.deleted_from_status === 'string' ? raw.deleted_from_status : null,
    purge_after: typeof raw.purge_after === 'string' ? raw.purge_after : null,
  }
}

function normalizeSubscriptionSummary(raw: unknown): WorkspaceSubscriptionSummary | null {
  if (typeof raw !== 'object' || raw === null) return null
  const r = raw as Record<string, unknown>
  return {
    status: String(r.status ?? ''),
    plan_key: typeof r.plan_key === 'string' ? r.plan_key : null,
    plan_display_name: typeof r.plan_display_name === 'string' ? r.plan_display_name : null,
    trial_ends_at: typeof r.trial_ends_at === 'string' ? r.trial_ends_at : null,
    grace_ends_at: typeof r.grace_ends_at === 'string' ? r.grace_ends_at : null,
    provider_managed: r.provider_managed === true,
  }
}

function normalizeWorkspaceRow(raw: Record<string, unknown>): WorkspaceRow {
  return {
    tenant: normalizeTenant((raw.tenant ?? {}) as Record<string, unknown>),
    subscription: normalizeSubscriptionSummary(raw.subscription ?? null),
  }
}

function normalizeOverride(raw: Record<string, unknown>): WorkspaceOverride {
  return {
    entitlement: String(raw.entitlement ?? ''),
    value: 'value' in raw ? raw.value : null,
    expires_at: typeof raw.expires_at === 'string' ? raw.expires_at : null,
    reason: typeof raw.reason === 'string' ? raw.reason : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

/** An override's `expires_at` (if any) has passed -- WorkspaceDrawer badges expired overrides
 * without hiding them (brief: "active AND expired overrides with expiry/reason intact"). */
export function isOverrideExpired(override: WorkspaceOverride, now: Date = new Date()): boolean {
  return override.expires_at !== null && new Date(override.expires_at).getTime() <= now.getTime()
}

/** No caller-supplied `?uuids=` filter exists on this endpoint (design ruling) -- only
 * `page`/`per_page` are ever sent. */
export async function fetchWorkspaces(filters: WorkspaceListFilters = {}): Promise<WorkspaceListPage> {
  const params = new URLSearchParams()
  if (filters.page !== undefined) params.set('page', String(filters.page))
  if (filters.perPage !== undefined) params.set('per_page', String(filters.perPage))
  const qs = params.toString()
  const json = await authFetch(`${base()}/workspaces${qs ? `?${qs}` : ''}`)
  const rows = Array.isArray(json.data) ? json.data : []
  return {
    rows: rows.map((r) => normalizeWorkspaceRow(r as Record<string, unknown>)),
    total: typeof json.total === 'number' ? json.total : 0,
    current_page: typeof json.current_page === 'number' ? json.current_page : (filters.page ?? 1),
    per_page: typeof json.per_page === 'number' ? json.per_page : (filters.perPage ?? 20),
    total_pages: typeof json.total_pages === 'number' ? json.total_pages : 0,
    has_next_page: json.has_next_page === true,
    has_previous_page: json.has_previous_page === true,
  }
}

export async function fetchWorkspace(uuid: string): Promise<WorkspaceDetail> {
  const json = await authFetch(`${base()}/workspaces/${encodeURIComponent(uuid)}`)
  const raw = (json.data ?? json) as Record<string, unknown>
  const overrides = Array.isArray(raw.overrides) ? raw.overrides : []
  return {
    tenant: normalizeTenant((raw.tenant ?? {}) as Record<string, unknown>),
    subscription: normalizeSubscriptionSummary(raw.subscription ?? null),
    overrides: overrides.map((o) => normalizeOverride(o as Record<string, unknown>)),
  }
}

/**
 * `PUT /workspaces/{uuid}/plan` -- `start()` (no existing subscription) or `changePlan()`
 * (existing), decided server-side. The success payload is the raw engine subscription row, NOT
 * the controller's projected `WorkspaceSubscriptionSummary` shape (that projection only exists in
 * the index/show actions) -- callers rely on the workspace-detail invalidation this mutation
 * triggers to refetch the authoritative projected view, never this return value directly.
 */
export async function setWorkspacePlan(uuid: string, planKey: string): Promise<unknown> {
  const json = await authFetch(`${base()}/workspaces/${encodeURIComponent(uuid)}/plan`, {
    method: 'PUT',
    body: JSON.stringify({ plan_key: planKey }),
  })
  return json.data ?? json
}

/** `POST /workspaces/{uuid}/cancel` -- same "raw engine row, refetch for the projected view"
 * caveat as {@see setWorkspacePlan}. */
export async function cancelWorkspaceSubscription(uuid: string, atPeriodEnd = true): Promise<unknown> {
  const json = await authFetch(`${base()}/workspaces/${encodeURIComponent(uuid)}/cancel`, {
    method: 'POST',
    body: JSON.stringify({ at_period_end: atPeriodEnd }),
  })
  return json.data ?? json
}

export interface UpsertOverrideInput {
  value: EntitlementValue
  expires_at?: string | null
  reason?: string | null
}

/** `PUT /workspaces/{uuid}/overrides/{entitlement}` -- echoes back exactly
 * `{entitlement, value, expires_at, reason}` (no timestamps on the immediate response). */
export async function upsertWorkspaceOverride(
  uuid: string,
  entitlement: string,
  input: UpsertOverrideInput,
): Promise<WorkspaceOverride> {
  const json = await authFetch(
    `${base()}/workspaces/${encodeURIComponent(uuid)}/overrides/${encodeURIComponent(entitlement)}`,
    {
      method: 'PUT',
      body: JSON.stringify({
        value: input.value,
        expires_at: input.expires_at ?? null,
        reason: input.reason ?? null,
      }),
    },
  )
  return normalizeOverride((json.data ?? json) as Record<string, unknown>)
}

/** `DELETE /workspaces/{uuid}/overrides/{entitlement}` -- a no-op (never an error) when the
 * override doesn't exist, so a retried revoke is always safe. */
export async function deleteWorkspaceOverride(uuid: string, entitlement: string): Promise<void> {
  await authFetch(
    `${base()}/workspaces/${encodeURIComponent(uuid)}/overrides/${encodeURIComponent(entitlement)}`,
    { method: 'DELETE' },
  )
}

export const qkWorkspaces = () => ['subscriptions', 'workspaces'] as const
export const qkWorkspace = (uuid: string) => ['subscriptions', 'workspace', uuid] as const

export function useWorkspaces(
  filters: MaybeRefOrGetter<WorkspaceListFilters>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => {
      const f = toValue(filters)
      return [...qkWorkspaces(), f.page ?? 1, f.perPage ?? 20]
    },
    query: () => fetchWorkspaces(toValue(filters)),
    enabled,
  })
}

/** `enabled` defaults to always-on but callers that must NEVER issue this request until a
 * concrete uuid is known (e.g. the tenancy-off "This site's plan" panel, which must not fetch at
 * all while `default_tenant_uuid` is null) simply never mount the component that calls this --
 * the `!!toValue(uuid)` guard below is the last line of defense, not the primary one. */
export function useWorkspace(uuid: MaybeRefOrGetter<string>, enabled: MaybeRefOrGetter<boolean> = true) {
  return useQuery({
    key: () => qkWorkspace(toValue(uuid)),
    query: () => fetchWorkspace(toValue(uuid)),
    enabled: () => !!toValue(uuid) && toValue(enabled),
  })
}

export function useWorkspaceMutations() {
  const cache = useQueryCache()
  const invalidate = (uuid: string) => {
    cache.invalidateQueries({ key: qkWorkspace(uuid) })
    cache.invalidateQueries({ key: qkWorkspaces() })
  }
  return {
    setPlan: useMutation({
      mutation: (vars: { uuid: string; planKey: string }) => setWorkspacePlan(vars.uuid, vars.planKey),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    cancel: useMutation({
      mutation: (vars: { uuid: string; atPeriodEnd?: boolean }) =>
        cancelWorkspaceSubscription(vars.uuid, vars.atPeriodEnd ?? true),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    upsertOverride: useMutation({
      mutation: (vars: { uuid: string; entitlement: string; input: UpsertOverrideInput }) =>
        upsertWorkspaceOverride(vars.uuid, vars.entitlement, vars.input),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    deleteOverride: useMutation({
      mutation: (vars: { uuid: string; entitlement: string }) =>
        deleteWorkspaceOverride(vars.uuid, vars.entitlement),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
  }
}
