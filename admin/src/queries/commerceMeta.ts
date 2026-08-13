import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

/**
 * `GET /commerce/meta` response (design spec §4.3) — the single settings/entitlement probe the
 * Commerce admin area fetches once and shares across every page and editor panel.
 */
export interface CommerceMeta {
  currency: string
  currency_exponent: number
  shop_index_url: string
  low_stock_threshold: number
  can_view: boolean
  can_manage: boolean
  can_attach_user: boolean
  /**
   * Whether this installation has a rich email channel at all (payment links Task 12, spec §2.4).
   * The server emits it on EVERY response, unconditionally, computed through the same
   * `RichEmailAvailability` authority the send endpoint's own 503 uses — so the payment-link
   * card's disabled-with-reason Send control and the endpoint can never disagree. Required here:
   * a missing key is a hard `false`, never `undefined`.
   */
  email_available: boolean
}

// The admin envelope is doc-only in the OpenAPI schema (see collections.ts's identical note), so
// normalize the raw `{ data: {...} }` JSON into the typed shape above at the boundary.
export async function fetchCommerceMeta(): Promise<CommerceMeta> {
  const { data, error, response } = await client.GET('/commerce/meta')
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: Partial<CommerceMeta> } | undefined)?.data ?? {}
  return {
    currency: raw.currency ?? 'USD',
    currency_exponent: raw.currency_exponent ?? 2,
    shop_index_url: raw.shop_index_url ?? '',
    low_stock_threshold: raw.low_stock_threshold ?? 0,
    can_view: raw.can_view ?? false,
    can_manage: raw.can_manage ?? false,
    can_attach_user: raw.can_attach_user ?? false,
    email_available: raw.email_available ?? false,
  }
}

export function useCommerceMeta() {
  return useQuery({ key: qk.commerceMeta(), query: fetchCommerceMeta })
}
