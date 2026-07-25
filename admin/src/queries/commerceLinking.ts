import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { computed, toValue, type MaybeRefOrGetter, type Ref } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'
import { useCommerceMeta } from './commerceMeta'
import { fetchProducts, type CommerceProduct } from './commerceCatalog'

// Task 12 (admin-commerce-area plan, slice 3): the bidirectional product<->entry linkage query
// layer — the shared panel (ProductEntryLinkPanel.vue) consumes every export here from BOTH its
// product-mode and entry-mode mounts.

/**
 * A `commerce_product_links` row as the admin linkage endpoints project it
 * ({@see \Thallo\Commerce\Http\ProductLinkController::toArray()}). Deliberately NOT
 * `{uuid, tenant_uuid, product_uuid, entry_uuid, created_at, updated_at}` — the controller's own
 * projection never includes `tenant_uuid` (verified against the actual controller source, not
 * the design doc), so this type mirrors what the API actually returns.
 */
export interface CommerceProductLink {
  uuid: string
  product_uuid: string
  entry_uuid: string
  created_at: string | null
  updated_at: string | null
}

/** `GET /commerce/products/{productUuid}/link` — always 200 for an accessible product, `link`
 * nullable. `storefront_url` is server-built and ABSOLUTE; render it verbatim, never reassemble
 * it client-side. */
export interface ProductLinkProjection {
  product_uuid: string
  storefront_url: string
  link: CommerceProductLink | null
}

/** One row of `GET /commerce/entries?q=`'s five-field projection (EntryLinkSearch). */
export interface EntrySearchResult {
  uuid: string
  title: string
  content_type: string
  status: string
  locale: string
}

export interface SetLinkInput {
  entryUuid: string
  /** CAS token for a relink — the CURRENTLY-linked entry's uuid. Omitted = first-time link. */
  expectedEntryUuid?: string
}

function normalizeLink(raw: Record<string, unknown>): CommerceProductLink {
  return {
    uuid: String(raw.uuid ?? ''),
    product_uuid: String(raw.product_uuid ?? ''),
    entry_uuid: String(raw.entry_uuid ?? ''),
    created_at: typeof raw.created_at === 'string' ? raw.created_at : null,
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : null,
  }
}

function normalizeEntrySearchResult(raw: Record<string, unknown>): EntrySearchResult {
  return {
    uuid: String(raw.uuid ?? ''),
    title: String(raw.title ?? ''),
    content_type: String(raw.content_type ?? ''),
    status: String(raw.status ?? ''),
    locale: String(raw.locale ?? ''),
  }
}

// ── Fetchers ─────────────────────────────────────────────────────────────────

export async function fetchProductLink(productUuid: string): Promise<ProductLinkProjection> {
  const { data, error, response } = await client.GET('/commerce/products/{productUuid}/link', {
    params: { path: { productUuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (
    data as { data?: { product_uuid?: string; storefront_url?: string; link?: unknown } } | undefined
  )?.data ?? {}
  return {
    product_uuid: String(raw.product_uuid ?? productUuid),
    storefront_url: String(raw.storefront_url ?? ''),
    link: raw.link ? normalizeLink(raw.link as Record<string, unknown>) : null,
  }
}

/**
 * `GET /commerce/entries/{entryUuid}/link` — UNLIKE `fetchProductLink`'s always-200 projection,
 * a 404 here is the ORDINARY case (most entries are never linked to any product), not a failure,
 * so it resolves to `null` rather than throwing. Any other failure (401/403/500) still throws.
 */
export async function fetchEntryLink(entryUuid: string): Promise<CommerceProductLink | null> {
  const { data, error, response } = await client.GET('/commerce/entries/{entryUuid}/link', {
    params: { path: { entryUuid } },
  })
  if (error) {
    if (response?.status === 404) return null
    throw toApiError(error, response)
  }
  const raw = (data as { data?: unknown } | undefined)?.data
  return raw ? normalizeLink(raw as Record<string, unknown>) : null
}

/** `GET /commerce/entries?q=` (manage-graded — search exists to feed link mutations). `q` must be
 * at least 2 characters (backend 422s otherwise); callers gate on length before calling this. */
export async function searchLinkEntries(q: string): Promise<EntrySearchResult[]> {
  const { data, error, response } = await client.GET('/commerce/entries', {
    params: { query: { q } } as never,
  })
  if (error) throw toApiError(error, response)
  const rows = (data as { data?: unknown[] } | undefined)?.data
  return Array.isArray(rows) ? rows.map((r) => normalizeEntrySearchResult(r as Record<string, unknown>)) : []
}

/** `PUT /commerce/products/{productUuid}/link` — 201 create / 200 relink; `expected_entry_uuid`
 * is the CAS token and is only present on the wire when explicitly supplied (never sent as an
 * explicit `undefined`). */
export async function setProductLink(
  productUuid: string,
  input: SetLinkInput,
): Promise<CommerceProductLink> {
  const body: Record<string, unknown> = { entry_uuid: input.entryUuid }
  if (input.expectedEntryUuid !== undefined) body.expected_entry_uuid = input.expectedEntryUuid
  const { data, error, response } = await client.PUT('/commerce/products/{productUuid}/link', {
    params: { path: { productUuid } },
    body: body as never,
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeLink((raw ?? {}) as Record<string, unknown>)
}

/** `DELETE /commerce/products/{productUuid}/link` — idempotent 200 whether or not a link existed. */
export async function unlinkProduct(productUuid: string): Promise<void> {
  const { error, response } = await client.DELETE('/commerce/products/{productUuid}/link', {
    params: { path: { productUuid } },
  })
  if (error) throw toApiError(error, response)
}

// ── Query/mutation wrappers ──────────────────────────────────────────────────

export function useProductLink(
  productUuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceLink(toValue(productUuid)),
    query: () => fetchProductLink(toValue(productUuid)),
    enabled: () => toValue(enabled) && !!toValue(productUuid),
  })
}

export function useEntryLink(
  entryUuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceLinkByEntry(toValue(entryUuid)),
    query: () => fetchEntryLink(toValue(entryUuid)),
    enabled: () => toValue(enabled) && !!toValue(entryUuid),
  })
}

/** The linkage picker's entry search (product-mode side) — never fires below 2 characters, the
 * same floor the backend enforces (422 otherwise). */
export function useEntrySearch(q: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.commerceEntrySearch(toValue(q)),
    query: () => searchLinkEntries(toValue(q)),
    enabled: () => toValue(q).trim().length >= 2,
  })
}

/** The linkage picker's product search (entry-mode side) — reuses the existing product list
 * endpoint (`fetchProducts`, view-graded); a fresh, small key of its own so this narrow picker
 * query never collides with (or unnecessarily populates) the main products list's cache entries
 * under `qk.commerceProducts()`. Same 2-character floor as `useEntrySearch`, for the same reason:
 * an unfiltered picker would otherwise fetch and show the entire catalog by default. */
export function useProductSearchForLink(q: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => ['commerce-product-search-for-link', toValue(q)] as const,
    query: () =>
      fetchProducts({ q: toValue(q), page: 1, perPage: 10 }).then(
        (page): CommerceProduct[] => page.products,
      ),
    enabled: () => toValue(q).trim().length >= 2,
  })
}

/**
 * Link/unlink mutations. BOTH invalidate the affected product's link projection
 * (`qk.commerceLink(productUuid)`) AND the affected entry's by-entry lookup
 * (`qk.commerceLinkByEntry(entryUuid)`) — every caller passes the relevant entry uuid(s)
 * explicitly (never inferred from the mutation's own response), mirroring
 * `useCommerceProductMutations()`'s "vars carry the owning identity" convention:
 *
 * - `link`: `vars.entryUuid` is the NEW entry being linked — always invalidated. A relink
 *   ALSO carries `vars.previousEntryUuid` (the entry that's being replaced, known from the
 *   panel's own displayed state) so that entry's by-entry lookup is invalidated too — otherwise
 *   its editor-side "Commerce" tab would keep showing a stale link.
 * - `unlink`: `vars.entryUuid` is the entry that WAS linked, when known (product-mode always
 *   knows it from the displayed link; entry-mode passes its own uuid). Omitted only for the
 *   idempotent no-op unlink of a product that was never linked, where there's nothing to
 *   invalidate on the entry side.
 */
export function useCommerceLinkMutations() {
  const cache = useQueryCache()

  return {
    link: useMutation({
      mutation: (vars: {
        productUuid: string
        entryUuid: string
        expectedEntryUuid?: string
        previousEntryUuid?: string
      }) => setProductLink(vars.productUuid, { entryUuid: vars.entryUuid, expectedEntryUuid: vars.expectedEntryUuid }),
      onSettled: (_data, _error, vars) => {
        cache.invalidateQueries({ key: qk.commerceLink(vars.productUuid) })
        cache.invalidateQueries({ key: qk.commerceLinkByEntry(vars.entryUuid) })
        if (vars.previousEntryUuid) {
          cache.invalidateQueries({ key: qk.commerceLinkByEntry(vars.previousEntryUuid) })
        }
      },
    }),
    unlink: useMutation({
      mutation: (vars: { productUuid: string; entryUuid?: string }) => unlinkProduct(vars.productUuid),
      onSettled: (_data, _error, vars) => {
        cache.invalidateQueries({ key: qk.commerceLink(vars.productUuid) })
        if (vars.entryUuid) cache.invalidateQueries({ key: qk.commerceLinkByEntry(vars.entryUuid) })
      },
    }),
  }
}

// ── Entry-editor gate ────────────────────────────────────────────────────────

/**
 * The `commerce-link` entry-editor panel's `useGate` (registry/entryEditorPanels.ts). Wraps the
 * shared `useCommerceMeta()` query — settle-before-admit: `'loading'` while the query hasn't
 * settled, `'hidden'` on a load failure (a 403 surfaces as a query error) or a settled
 * `can_view: false`, `'ready'` only once settled AND `can_view` is true.
 *
 * Deliberately does NOT re-check the `thallo.commerce` capability itself — the manifest's
 * `requiresCapability: 'thallo.commerce'` already gates that independently
 * (`useVisibleEditorPanels`); this gate is about the CALLER's own `commerce.view` permission,
 * which is orthogonal to whether the extension is installed/enabled at all.
 */
export function useCommerceLinkGate(): Readonly<Ref<'ready' | 'hidden' | 'loading'>> {
  const { data, status } = useCommerceMeta()
  return computed<'ready' | 'hidden' | 'loading'>(() => {
    if (status.value === 'pending') return 'loading'
    if (status.value === 'error') return 'hidden'
    return data.value?.can_view ? 'ready' : 'hidden'
  })
}
