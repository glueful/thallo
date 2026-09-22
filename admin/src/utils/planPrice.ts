// A plan's display price: an integer in the currency's minor units (1900 = $19.00), an ISO 4217
// currency and a billing interval. For showing what a plan costs; the gateway decides the charge.

export type BillingInterval = 'day' | 'week' | 'month' | 'year'
export const BILLING_INTERVALS: BillingInterval[] = ['month', 'year', 'week', 'day']

/** How many minor units the currency has (2 for USD, 0 for JPY), as the browser knows it. */
function fractionDigits(currency: string): number {
  try {
    return (
      new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions()
        .maximumFractionDigits ?? 2
    )
  } catch {
    return 2
  }
}

export function formatPlanPrice(
  amount: number | null | undefined,
  currency: string | null | undefined,
  interval: string | null | undefined,
): string | null {
  if (amount === null || amount === undefined || !currency) return null
  const digits = fractionDigits(currency)
  let money: string
  try {
    money = new Intl.NumberFormat('en', { style: 'currency', currency }).format(
      amount / 10 ** digits,
    )
  } catch {
    money = `${(amount / 10 ** digits).toFixed(digits)} ${currency}`
  }
  return interval ? `${money} / ${interval}` : money
}

/** A typed price ("19.99") in minor units, or null when it is not a non-negative number. */
export function toMinorUnits(input: string, currency: string): number | null {
  const text = input.trim()
  if (!/^\d+(\.\d+)?$/.test(text)) return null
  return Math.round(Number(text) * 10 ** fractionDigits(currency.toUpperCase()))
}

export function fromMinorUnits(amount: number, currency: string): string {
  const digits = fractionDigits(currency)
  return (amount / 10 ** digits).toFixed(digits)
}

/** A plan in a picker: its name, and its price when it has one. */
export function planChoiceLabel(plan: {
  name: string
  price_amount?: number | null
  price_currency?: string | null
  billing_interval?: string | null
}): string {
  const price = formatPlanPrice(plan.price_amount, plan.price_currency, plan.billing_interval)
  return price === null ? plan.name : `${plan.name} — ${price}`
}
