import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'
import {
  normalizeAddresses,
  normalizeOrderLineAddon,
  type CommerceOrderAddresses,
  type CommerceOrderLineAddon,
} from './commerceOrders'

// ── Invoice data (orders-invoices-receipts spec, Task 8) ──────────────────────────────────────
//
// `GET /commerce/orders/{uuid}/invoice-data` (`thallo.commerce.admin.orders.invoice_data`,
// AdminOrderController::invoiceData(), view-graded) — mirrors `InvoiceData::build()`
// (Invoices/InvoiceData.php) field-for-field. Every `*_minor` amount is a genuine integer
// minor-unit value (format with `formatMoney`, never `Number()`) and `refunds` is already
// completed-only, exactly whitelisted (`date`, `amount_minor`, `method` — never `reason`) by the
// backend itself.
//
// THE ONE implementation: moved out of `commerceOrders.ts` in Task 8 so there is exactly one
// query/type surface for this endpoint — both the order-detail page's formatted "Invoice data"
// modal (`orders/[uuid]/index.vue`) and the printable invoice/receipt page
// (`orders/[uuid]/invoice.vue`) import from here, never a second parallel fetch/normalize.
//
// Commerce v1.9.1 (spec §2.6.1) added `order.currency_exponent` — the order's OWN currency's
// exponent, historically correct for a receipt printed long after a store-wide currency change,
// never today's store exponent — and bumped `schema_version` to 2 alongside it.

export interface CommerceInvoiceLine {
  name: string
  sku: string
  quantity: number
  /** Minor-unit integer amount — format with `formatMoney`, never `Number()`. */
  unit_minor: number
  /** Minor-unit integer amount — format with `formatMoney`, never `Number()`. */
  subtotal_minor: number
  addons: CommerceOrderLineAddon[]
}

export interface CommerceInvoiceRefund {
  date: string | null
  /** Minor-unit integer amount — format with `formatMoney`, never `Number()`. */
  amount_minor: number
  method: string
}

export interface CommerceInvoiceData {
  schema_version: number
  seller: { name: string | null; address: string | null; tax_id: string | null }
  buyer: { email: string | null; addresses: CommerceOrderAddresses | null }
  order: {
    number: string | null
    dates: { placed_at: string | null; created_at: string | null; updated_at: string | null }
    currency: string | null
    /** The order's OWN currency exponent (commerce v1.9.1) — format every amount in this payload
     * with THIS value, never the live store's current exponent (`useCommerceMeta()`). */
    currency_exponent: number
    status: string | null
  }
  lines: CommerceInvoiceLine[]
  totals: {
    subtotal_minor: number
    discount_minor: number
    shipping_minor: number
    tax_minor: number
    grand_minor: number
    refunded_minor: number
  }
  refunds: CommerceInvoiceRefund[]
}

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function normalizeInvoiceLine(raw: Record<string, unknown>): CommerceInvoiceLine {
  const addons = Array.isArray(raw.addons) ? raw.addons : []
  return {
    name: String(raw.name ?? ''),
    sku: String(raw.sku ?? ''),
    quantity: typeof raw.quantity === 'number' ? raw.quantity : 0,
    unit_minor: typeof raw.unit_minor === 'number' ? raw.unit_minor : 0,
    subtotal_minor: typeof raw.subtotal_minor === 'number' ? raw.subtotal_minor : 0,
    addons: addons.map((a) => normalizeOrderLineAddon(a as Record<string, unknown>)),
  }
}

function normalizeInvoiceRefund(raw: Record<string, unknown>): CommerceInvoiceRefund {
  return {
    date: typeof raw.date === 'string' ? raw.date : null,
    amount_minor: typeof raw.amount_minor === 'number' ? raw.amount_minor : 0,
    method: String(raw.method ?? ''),
  }
}

function normalizeInvoiceData(raw: Record<string, unknown>): CommerceInvoiceData {
  const seller = asRecord(raw.seller)
  const buyer = asRecord(raw.buyer)
  const orderInfo = asRecord(raw.order)
  const dates = asRecord(orderInfo.dates)
  const totals = asRecord(raw.totals)
  const lines = Array.isArray(raw.lines) ? raw.lines : []
  const refunds = Array.isArray(raw.refunds) ? raw.refunds : []

  return {
    schema_version: typeof raw.schema_version === 'number' ? raw.schema_version : 2,
    seller: {
      name: typeof seller.name === 'string' ? seller.name : null,
      address: typeof seller.address === 'string' ? seller.address : null,
      tax_id: typeof seller.tax_id === 'string' ? seller.tax_id : null,
    },
    buyer: {
      email: typeof buyer.email === 'string' ? buyer.email : null,
      addresses: normalizeAddresses(buyer.addresses),
    },
    order: {
      number: typeof orderInfo.number === 'string' ? orderInfo.number : null,
      dates: {
        placed_at: typeof dates.placed_at === 'string' ? dates.placed_at : null,
        created_at: typeof dates.created_at === 'string' ? dates.created_at : null,
        updated_at: typeof dates.updated_at === 'string' ? dates.updated_at : null,
      },
      currency: typeof orderInfo.currency === 'string' ? orderInfo.currency : null,
      currency_exponent:
        typeof orderInfo.currency_exponent === 'number' ? orderInfo.currency_exponent : 2,
      status: typeof orderInfo.status === 'string' ? orderInfo.status : null,
    },
    lines: lines.map((l) => normalizeInvoiceLine(l as Record<string, unknown>)),
    totals: {
      subtotal_minor: typeof totals.subtotal_minor === 'number' ? totals.subtotal_minor : 0,
      discount_minor: typeof totals.discount_minor === 'number' ? totals.discount_minor : 0,
      shipping_minor: typeof totals.shipping_minor === 'number' ? totals.shipping_minor : 0,
      tax_minor: typeof totals.tax_minor === 'number' ? totals.tax_minor : 0,
      grand_minor: typeof totals.grand_minor === 'number' ? totals.grand_minor : 0,
      refunded_minor: typeof totals.refunded_minor === 'number' ? totals.refunded_minor : 0,
    },
    refunds: refunds.map((r) => normalizeInvoiceRefund(r as Record<string, unknown>)),
  }
}

/** `GET /commerce/orders/{uuid}/invoice-data` (AdminOrderController::invoiceData()). */
export async function fetchOrderInvoiceData(orderUuid: string): Promise<CommerceInvoiceData> {
  const { data, error, response } = await client.GET('/commerce/orders/{uuid}/invoice-data', {
    params: { path: { uuid: orderUuid } },
  })
  if (error) throw toApiError(error, response)
  const raw = (data as { data?: unknown } | undefined)?.data
  return normalizeInvoiceData(asRecord(raw))
}

/** `enabled` defaults to always-on but a caller may pass a ref so the request only fires once a
 * modal is actually opened (mirrors `useCommerceProduct()`'s identical `enabled` parameter); the
 * print page (`orders/[uuid]/invoice.vue`) leaves it at the default since the route itself is the
 * gate. */
export function useOrderInvoiceData(
  uuid: MaybeRefOrGetter<string>,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  return useQuery({
    key: () => qk.commerceOrderInvoiceData(toValue(uuid)),
    query: () => fetchOrderInvoiceData(toValue(uuid)),
    enabled: () => toValue(enabled) && !!toValue(uuid),
  })
}
