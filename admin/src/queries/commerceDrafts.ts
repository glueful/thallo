import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { ApiError, toApiError } from '@/api/errors'
import { qk } from './keys'
import {
  normalizeAddresses,
  normalizeOrderLineAddon,
  type CommerceOrderAddresses,
  type CommerceOrderLineAddon,
} from './commerceOrders'

// ── Admin walk-in order drafts (admin-order-creation cycle 2, Task 14) ─────────────────────────
//
// `/commerce/orders/drafts*` (AdminOrderDraftController, commerce v1.10.0) is now in the generated
// OpenAPI schema (Task 16 regeneration) and rides the typed `client` below — every route here
// carries a documented `path`/`requestBody` shape (no `#[QueryParam]`-less gap like
// `commerceOrderSearch.ts`'s `/search`), so no `as never` query cast is needed; request bodies are
// still cast (`as never`) where the schema's own nullability doesn't line up field-for-field with
// the input types below, mirroring every other typed mutation in this codebase. `finalize`'s
// `X-Idempotency-Key` header rides the client's plain top-level `headers` option, which is NOT
// constrained by the schema's (undocumented) `header` parameter type — the same mechanism
// `commerceOrders.ts`'s `createRefund()` already uses for its own `Idempotency-Key` header.
//
// EVERY mutation here sends `expected_revision` — the client-level staleness guard the task brief
// pins as binding: a draft's `draft_revision` is CAS-incremented by exactly 1 on every successful
// mutation (customer/mode/address/shipping/discount update, line add/update/delete, recalculate),
// and a losing CAS comes back as a typed 409 `{conflict: 'stale_revision'}` — surfaced by the
// workspace page, never silently retried. PATCH-style bodies here are presence-sensitive
// (explicit `null` clears a field; an ABSENT key leaves it untouched) — `JSON.stringify` (the typed
// client's own default body serializer, same as raw `fetch`) already drops `undefined` keys, so a
// caller only needs to omit a field it doesn't want to touch.

export type DraftFulfillmentMode = 'in_store' | 'delivery'

/** A draft line — the SAME shape a finalized order's `lines` carry (`DraftOrderProjection::line()`
 * is reused verbatim by the finalize response), but carries `variant_uuid` where an ordinary
 * `CommerceOrderLine` (commerceOrders.ts) never does — do NOT cache one as the other (accumulated
 * contract note). */
export interface CommerceDraftLine {
  uuid: string
  variant_uuid: string
  product_name: string
  sku: string
  quantity: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  unit_price: number
  /** Minor-unit integer amount — format with `useMoney`, never `Number()`. */
  line_total: number
  option_values: Record<string, unknown>
  addons: CommerceOrderLineAddon[]
}

/** `DraftOrderProjection::forAdmin()` — `OrderProjection::FIELDS` plus `draft_revision`. */
export interface CommerceDraft {
  uuid: string
  /** Null until finalize allocates one (migration 022 relaxed this to nullable for drafts). */
  order_number: string | null
  status: string
  fulfillment_status: string
  /** Ruling 4: a walk-in draft may be fully anonymous — never a fabricated placeholder. */
  email: string | null
  user_uuid: string | null
  customer_name: string | null
  phone_normalized: string | null
  phone_display: string | null
  fulfillment_mode: DraftFulfillmentMode
  origin: string
  currency: string
  subtotal: number
  discount_total: number
  shipping_total: number
  tax_total: number
  grand_total: number
  refunded_total: number
  discount_code: string | null
  shipping_method: string | null
  addresses: CommerceOrderAddresses | null
  placed_at: string | null
  created_at: string | null
  updated_at: string | null
  /** The CAS counter every mutation below increments by exactly 1 on success. */
  draft_revision: number
  lines: CommerceDraftLine[]
}

/** The finalize response's order wire — `OrderProjection::forAdmin()`, NOT the draft wire (no
 * `draft_revision` key at all), but its `lines` still come from `DraftOrderProjection::line()` —
 * so `variant_uuid` IS present here too (accumulated contract note: don't assume it's stripped). */
export interface CommerceFinalizedOrder {
  uuid: string
  order_number: string
  status: string
  fulfillment_status: string
  email: string | null
  user_uuid: string | null
  customer_name: string | null
  phone_normalized: string | null
  phone_display: string | null
  fulfillment_mode: DraftFulfillmentMode
  origin: string
  currency: string
  subtotal: number
  discount_total: number
  shipping_total: number
  tax_total: number
  grand_total: number
  refunded_total: number
  discount_code: string | null
  shipping_method: string | null
  addresses: CommerceOrderAddresses | null
  placed_at: string | null
  created_at: string | null
  updated_at: string | null
  lines: CommerceDraftLine[]
}

function num(v: unknown): number {
  return typeof v === 'number' ? v : 0
}

function normalizeDraftLine(raw: Record<string, unknown>): CommerceDraftLine {
  const addons = Array.isArray(raw.addons) ? raw.addons : []
  const optionValues = raw.option_values
  return {
    uuid: String(raw.uuid ?? ''),
    variant_uuid: String(raw.variant_uuid ?? ''),
    product_name: String(raw.product_name ?? ''),
    sku: String(raw.sku ?? ''),
    quantity: typeof raw.quantity === 'number' ? raw.quantity : 0,
    unit_price: typeof raw.unit_price === 'number' ? raw.unit_price : 0,
    line_total: typeof raw.line_total === 'number' ? raw.line_total : 0,
    option_values:
      typeof optionValues === 'object' && optionValues !== null && !Array.isArray(optionValues)
        ? (optionValues as Record<string, unknown>)
        : {},
    addons: addons.map((a) => normalizeOrderLineAddon(a as Record<string, unknown>)),
  }
}

/** Shared field mapping between a draft and a finalized order — every field except
 * `draft_revision`/`order_number`'s nullability is identical between the two wires. */
function normalizeCommonOrderFields(raw: Record<string, unknown>) {
  const lines = Array.isArray(raw.lines) ? raw.lines : []
  return {
    uuid: String(raw.uuid ?? ''),
    status: String(raw.status ?? 'draft'),
    fulfillment_status: String(raw.fulfillment_status ?? 'unfulfilled'),
    email: typeof raw.email === 'string' ? raw.email : null,
    user_uuid: typeof raw.user_uuid === 'string' ? raw.user_uuid : null,
    customer_name: typeof raw.customer_name === 'string' ? raw.customer_name : null,
    phone_normalized: typeof raw.phone_normalized === 'string' ? raw.phone_normalized : null,
    phone_display: typeof raw.phone_display === 'string' ? raw.phone_display : null,
    fulfillment_mode: (raw.fulfillment_mode === 'delivery' ? 'delivery' : 'in_store') as DraftFulfillmentMode,
    origin: String(raw.origin ?? 'admin'),
    currency: String(raw.currency ?? ''),
    subtotal: num(raw.subtotal),
    discount_total: num(raw.discount_total),
    shipping_total: num(raw.shipping_total),
    tax_total: num(raw.tax_total),
    grand_total: num(raw.grand_total),
    refunded_total: num(raw.refunded_total),
    discount_code: typeof raw.discount_code === 'string' ? raw.discount_code : null,
    shipping_method: typeof raw.shipping_method === 'string' ? raw.shipping_method : null,
    addresses: normalizeAddresses(raw.addresses),
    placed_at: typeof raw.placed_at === 'string' ? raw.placed_at : null,
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
    lines: lines.map((l) => normalizeDraftLine(l as Record<string, unknown>)),
  }
}

function normalizeDraft(raw: Record<string, unknown>): CommerceDraft {
  return {
    ...normalizeCommonOrderFields(raw),
    order_number: typeof raw.order_number === 'string' ? raw.order_number : null,
    draft_revision: typeof raw.draft_revision === 'number' ? raw.draft_revision : 0,
  }
}

function normalizeFinalizedOrder(raw: Record<string, unknown>): CommerceFinalizedOrder {
  return {
    ...normalizeCommonOrderFields(raw),
    order_number: String(raw.order_number ?? ''),
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

export async function fetchDraft(uuid: string): Promise<CommerceDraft> {
  const { data, error, response } = await client.GET('/commerce/orders/drafts/{uuid}', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  if (!raw) throw new ApiError('Draft not found.', response?.status ?? 404, {}, data)
  return normalizeDraft(raw as Record<string, unknown>)
}

export function useCommerceDraft(
  uuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceDraft(toValue(uuid)),
    query: () => fetchDraft(toValue(uuid)),
    enabled: () => toValue(enabled) && !!toValue(uuid),
  })
}

// ── Drafts list (Task 15, admin-order-creation cycle 2) ─────────────────────────────────────
//
// `GET /commerce/orders/drafts` (AdminOrderDraftController::index(), 'view'-graded server-side —
// unlike every mutation on this resource, which is 'manage') — this is the ONE draft-inclusive
// listing anywhere in the admin SPA (design spec's binding ruling); the ordinary orders list
// (`commerceOrderSearch.ts`) stays draft-blind by construction. Paginated exactly like
// `commerceOrderSearch.ts`'s own `{data, current_page, per_page, total}` envelope
// (`Response::paginated()`), so the fetcher mirrors that file's parsing idiom rather than
// inventing a new one.

export interface DraftsListFilters {
  page: number
  perPage: number
}

export interface CommerceDraftListPage {
  drafts: CommerceDraft[]
  total: number
  current_page: number
  per_page: number
}

export async function fetchDraftsList(filters: DraftsListFilters): Promise<CommerceDraftListPage> {
  const { data, error, response } = await client.GET('/commerce/orders/drafts', {
    params: { query: { page: filters.page, per_page: filters.perPage } },
  })
  if (error) throw toApiError(error, response)
  const body = data as
    | { data?: unknown[]; current_page?: number; per_page?: number; total?: number }
    | undefined
  const rows = Array.isArray(body?.data) ? body.data : []
  return {
    drafts: rows.map((r) => normalizeDraft(r as Record<string, unknown>)),
    total: typeof body?.total === 'number' ? body.total : 0,
    current_page: typeof body?.current_page === 'number' ? body.current_page : filters.page,
    per_page: typeof body?.per_page === 'number' ? body.per_page : filters.perPage,
  }
}

export function useDraftsList(filters: MaybeRefOrGetter<DraftsListFilters>) {
  return useQuery({
    key: () => qk.commerceDraftsList(toValue(filters).page, toValue(filters).perPage),
    query: () => fetchDraftsList(toValue(filters)),
  })
}

export interface CreateDraftInput {
  fulfillment_mode?: DraftFulfillmentMode
  customer_name?: string
  email?: string
  phone?: string
  user_uuid?: string
}

/** `POST /commerce/orders/drafts` — route custody's ONE creation call site (the workspace page
 * calls this exactly once, only when its URL carries no `?draft=` uuid). */
export async function createDraft(input: CreateDraftInput = {}): Promise<CommerceDraft> {
  const { data, error, response } = await client.POST('/commerce/orders/drafts', {
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

export interface DraftAddressInput {
  shipping?: Record<string, unknown> | null
  billing?: Record<string, unknown> | null
}

export interface UpdateDraftInput {
  fulfillment_mode?: DraftFulfillmentMode
  customer_name?: string | null
  email?: string | null
  phone?: string | null
  user_uuid?: string | null
  addresses?: DraftAddressInput | null
  shipping_method?: string | null
  discount_code?: string | null
  expected_revision?: number
}

export async function updateDraft(uuid: string, input: UpdateDraftInput): Promise<CommerceDraft> {
  const { data, error, response } = await client.PATCH('/commerce/orders/drafts/{uuid}', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

export interface DraftLineAddonInput {
  addon_uuid: string
  choice_key?: string
  value?: unknown
}

export interface AddDraftLineInput {
  variant_uuid: string
  quantity?: number
  addons?: DraftLineAddonInput[]
  expected_revision?: number
}

export async function addDraftLine(uuid: string, input: AddDraftLineInput): Promise<CommerceDraft> {
  const { data, error, response } = await client.POST('/commerce/orders/drafts/{uuid}/lines', {
    params: { path: { uuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

export interface UpdateDraftLineInput {
  quantity?: number
  addons?: DraftLineAddonInput[]
  expected_revision?: number
}

export async function updateDraftLine(
  uuid: string,
  lineUuid: string,
  input: UpdateDraftLineInput,
): Promise<CommerceDraft> {
  const { data, error, response } = await client.PATCH('/commerce/orders/drafts/{uuid}/lines/{lineUuid}', {
    params: { path: { uuid, lineUuid } },
    body: input as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

export async function deleteDraftLine(
  uuid: string,
  lineUuid: string,
  expectedRevision?: number,
): Promise<CommerceDraft> {
  const { data, error, response } = await client.DELETE('/commerce/orders/drafts/{uuid}/lines/{lineUuid}', {
    params: { path: { uuid, lineUuid } },
    body: { expected_revision: expectedRevision } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

export async function recalculateDraft(uuid: string, expectedRevision?: number): Promise<CommerceDraft> {
  const { data, error, response } = await client.POST('/commerce/orders/drafts/{uuid}/recalculate', {
    params: { path: { uuid } },
    body: { expected_revision: expectedRevision } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

export async function cancelDraft(uuid: string): Promise<CommerceDraft> {
  const { data, error, response } = await client.POST('/commerce/orders/drafts/{uuid}/cancel', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeDraft((raw ?? {}) as Record<string, unknown>)
}

/**
 * `POST /commerce/orders/drafts/{uuid}/finalize` — the ONE call site the finalize idempotency-key
 * contract binds to: `X-Idempotency-Key` header (`[A-Za-z0-9._:-]{16,191}`, minted by the caller —
 * see `getOrCreateFinalizeIdempotencyKey()` below) and a REQUIRED `expected_revision` body field
 * (unlike every other mutation above, where it's optional). Never wrapped in `useCommerceDraft
 * Mutations()`'s generic invalidate-on-settle pattern — the caller (the workspace page) owns the
 * conflict classification and the idempotency-key lifecycle around this call directly.
 */
export async function finalizeDraft(
  uuid: string,
  expectedRevision: number,
  idempotencyKey: string,
): Promise<CommerceFinalizedOrder> {
  const { data, error, response } = await client.POST('/commerce/orders/drafts/{uuid}/finalize', {
    params: { path: { uuid } },
    headers: { 'X-Idempotency-Key': idempotencyKey },
    body: { expected_revision: expectedRevision } as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeFinalizedOrder((raw ?? {}) as Record<string, unknown>)
}

/**
 * Every ordinary draft mutation invalidates ONLY its own draft's query — no list view read
 * through this cache key until Task 15's drafts list. `cancel` additionally invalidates the
 * drafts list's own prefix (mirrors `useCommerceOrderMutations()`'s identical
 * `qk.commerceOrderSearch()` prefix-only invalidation): a canceled draft must disappear from
 * the list too, and pinia-colada's `invalidateQueries` matches by prefix (element-wise from
 * index 0), so the bare `['commerce-drafts-list']` array — never the full
 * `qk.commerceDraftsList(page, perPage)` key, whose page/per_page this call site doesn't know —
 * matches every page/per_page variant the list view might currently be querying.
 */
export function useCommerceDraftMutations() {
  const cache = useQueryCache()
  const invalidate = (uuid: string) => cache.invalidateQueries({ key: qk.commerceDraft(uuid) })
  const invalidateDraftsList = () => cache.invalidateQueries({ key: ['commerce-drafts-list'] })

  return {
    update: useMutation({
      mutation: (vars: { uuid: string; input: UpdateDraftInput }) => updateDraft(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    addLine: useMutation({
      mutation: (vars: { uuid: string; input: AddDraftLineInput }) => addDraftLine(vars.uuid, vars.input),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    updateLine: useMutation({
      mutation: (vars: { uuid: string; lineUuid: string; input: UpdateDraftLineInput }) =>
        updateDraftLine(vars.uuid, vars.lineUuid, vars.input),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    deleteLine: useMutation({
      mutation: (vars: { uuid: string; lineUuid: string; expectedRevision?: number }) =>
        deleteDraftLine(vars.uuid, vars.lineUuid, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    recalculate: useMutation({
      mutation: (vars: { uuid: string; expectedRevision?: number }) =>
        recalculateDraft(vars.uuid, vars.expectedRevision),
      onSettled: (_d, _e, vars) => invalidate(vars.uuid),
    }),
    cancel: useMutation({
      mutation: (uuid: string) => cancelDraft(uuid),
      onSettled: (_d, _e, uuid) => {
        invalidate(uuid)
        invalidateDraftsList()
      },
    }),
  }
}

// ── Complete sale (Task 15, admin-order-creation cycle 2, design spec §2.8) ──────────────────
//
// `POST /commerce/orders/{uuid}/complete-sale` — the walk-in counter's one-click finish for a
// FINALIZED order (never a draft), chaining mark-paid + fulfill server-side
// (`CompleteSaleCoordinator`). The wire is CLOSED and tiny — `{tracking_ref?: string|null}` ONLY,
// any other key is a 422 — and every outcome (200 both done, 409 conflicts, sanitized 500s) rides
// the SAME envelope shape: `{steps: [{step, status, error?}], order: <admin order row>|null}`.
//
// Deliberately NOT thrown-on-non-2xx the way every other call in this file is: a 409/500 here is
// still a fully-informative, well-formed RESULT the caller renders (spec §2.8's five outcomes),
// not a blank failure. Now on the typed `client` (Task 16 regeneration): the typed client hands a
// non-ok response back as `error` (the parsed JSON body) rather than throwing, so this function
// checks `error` for a genuine complete-sale envelope FIRST (it always is one, for every status
// this endpoint itself returns) and resolves with it instead of raising — the same outcome the
// previous authFetch-based try/catch produced, just without needing to catch a thrown ApiError to
// get there. Only a truly unexpected failure — a network error (still thrown by `fetch` itself,
// same as before) or a body this endpoint could never actually produce — surfaces as a thrown
// `ApiError`, for the caller's ordinary catch-all.
//
// `order` is `OrderProjection::forAdmin()` — the ordinary finalized-order row, but WITHOUT
// `lines`/`events` (that projection carries neither) — so it is never a substitute for the
// order-detail page's own `useCommerceOrder` read. Callers use it only for the immediate step
// outcome, then let the page's own invalidation-triggered refetch bring the full order (and its
// updated `lines`/`events`) back into view.

export type CompleteSaleStepName = 'mark_paid' | 'fulfill'
export type CompleteSaleStepStatus = 'done' | 'failed' | 'skipped'

export interface CompleteSaleStep {
  step: CompleteSaleStepName
  status: CompleteSaleStepStatus
  error?: string
}

export interface CompleteSaleResult {
  message: string
  steps: CompleteSaleStep[]
  /** `OrderProjection::forAdmin()` row, or `null` when the order stopped resolving mid-flight
   * (e.g. a `fulfill()` precheck NotFoundException) — ALWAYS tolerated, never dereferenced without
   * a null check. */
  order: Record<string, unknown> | null
}

function isCompleteSaleBody(body: unknown): body is { message?: unknown; data?: { steps?: unknown; order?: unknown } } {
  return (
    typeof body === 'object' &&
    body !== null &&
    typeof (body as { data?: unknown }).data === 'object' &&
    (body as { data?: unknown }).data !== null &&
    Array.isArray(((body as { data?: { steps?: unknown } }).data as { steps?: unknown }).steps)
  )
}

function normalizeCompleteSaleResult(body: {
  message?: unknown
  data?: { steps?: unknown; order?: unknown }
}): CompleteSaleResult {
  const steps = Array.isArray(body.data?.steps) ? (body.data!.steps as CompleteSaleStep[]) : []
  const order = body.data?.order
  return {
    message: typeof body.message === 'string' ? body.message : '',
    steps,
    order: typeof order === 'object' && order !== null ? (order as Record<string, unknown>) : null,
  }
}

export async function completeSale(uuid: string, trackingRef: string | null = null): Promise<CompleteSaleResult> {
  const { data, error, response } = await client.POST('/commerce/orders/{uuid}/complete-sale', {
    params: { path: { uuid } },
    body: { tracking_ref: trackingRef } as never,
  })
  if (!error) return normalizeCompleteSaleResult(data as never)
  if (isCompleteSaleBody(error)) return normalizeCompleteSaleResult(error)
  throw toApiError(error, response)
}

/**
 * The ONE mutation wrapper for `completeSale()` — invalidates the order detail query on settle
 * (mirrors `useCommerceOrderMutations()`'s identical lifecycle-action invalidation) so the
 * page's own `useCommerceOrder` read brings back the full, current row (lines/events included)
 * regardless of which of the five outcomes just happened.
 */
export function useCompleteSaleMutation() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (vars: { uuid: string; trackingRef?: string | null }) =>
      completeSale(vars.uuid, vars.trackingRef ?? null),
    onSettled: (_d, _e, vars) => cache.invalidateQueries({ key: qk.commerceOrder(vars.uuid) }),
  })
}

// ── Finalize idempotency-key custody (task brief, binding) ──────────────────────────────────
//
// One opaque `crypto.randomUUID()` per `(draft_uuid, expected_revision)` pair, held in
// `sessionStorage` — scoping the storage key by revision is what gives "rotate ONLY when
// draft_revision changes" for free: a revision bump is a DIFFERENT storage key, so the next call
// mints a fresh value, while repeated calls at the SAME revision (a reload, or a retry after an
// ambiguous network failure) always return the SAME value already stored. The value carries no
// customer/order data — it is a bare random UUID.

const IDEMPOTENCY_KEY_PREFIX = 'thallo:commerce:draft-finalize-key:'

function idempotencyStorageKey(draftUuid: string, expectedRevision: number): string {
  return `${IDEMPOTENCY_KEY_PREFIX}${draftUuid}:${expectedRevision}`
}

// Review fix (round 1, Important): `sessionStorage` CAN throw on read/write (Safari private
// browsing historically, a full quota, or a browser policy) — a caller that let that escape
// (create.vue used to call the getter BEFORE its own try block) would abort `finalize()` with
// neither a rendered error nor a reset loading state. Every storage access here is defensive: on
// a throw, it degrades to this in-memory map, which keeps the SAME-PAGE-LIFETIME half of the
// contract working (mint once, reuse across retries) even though it can no longer survive an
// actual reload — strictly better than crashing the workspace. Never consulted when the real
// sessionStorage works normally.
const inMemoryFallback = new Map<string, string>()

function safeGetItem(key: string): string | null {
  try {
    return sessionStorage.getItem(key)
  } catch {
    return inMemoryFallback.get(key) ?? null
  }
}

function safeSetItem(key: string, value: string): void {
  try {
    sessionStorage.setItem(key, value)
  } catch {
    inMemoryFallback.set(key, value)
  }
}

function safeRemoveItem(key: string): void {
  try {
    sessionStorage.removeItem(key)
  } catch {
    // Fall through: still attempt to drop it from the fallback map below.
  }
  inMemoryFallback.delete(key)
}

function safeKeysWithPrefix(prefix: string): string[] {
  const out = new Set<string>()
  try {
    for (let i = 0; i < sessionStorage.length; i += 1) {
      const key = sessionStorage.key(i)
      if (key && key.startsWith(prefix)) out.add(key)
    }
  } catch {
    // sessionStorage itself is unusable — fall through to whatever the in-memory map has.
  }
  for (const key of inMemoryFallback.keys()) {
    if (key.startsWith(prefix)) out.add(key)
  }
  return [...out]
}

export function getOrCreateFinalizeIdempotencyKey(draftUuid: string, expectedRevision: number): string {
  const storageKey = idempotencyStorageKey(draftUuid, expectedRevision)
  const existing = safeGetItem(storageKey)
  if (existing) return existing
  const fresh = crypto.randomUUID()
  safeSetItem(storageKey, fresh)
  return fresh
}

/**
 * Drops the ONE key minted for this exact `(draft_uuid, expected_revision)` pair — the remedy for
 * a confirmed `idempotency_key` 409 (the server has bound this key to a DIFFERENT request; per
 * `DraftConflictException::idempotencyKeyReuse()` the fix is a fresh key, never a retry with the
 * same one). Deliberately narrower than `clearFinalizeIdempotencyKeys()`: this fires on a
 * conflict, not a confirmed finalize/cancel, so every OTHER revision's key (if any survive) is
 * left alone.
 */
export function dropFinalizeIdempotencyKey(draftUuid: string, expectedRevision: number): void {
  safeRemoveItem(idempotencyStorageKey(draftUuid, expectedRevision))
}

/** Clears EVERY finalize key ever minted for this draft (every revision it passed through), not
 * just the current one — called once after a CONFIRMED finalize or cancel, per the brief. */
export function clearFinalizeIdempotencyKeys(draftUuid: string): void {
  const prefix = `${IDEMPOTENCY_KEY_PREFIX}${draftUuid}:`
  safeKeysWithPrefix(prefix).forEach((key) => safeRemoveItem(key))
}
