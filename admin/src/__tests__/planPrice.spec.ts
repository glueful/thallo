import { describe, it, expect } from 'vitest'
import { formatPlanPrice, fromMinorUnits, planChoiceLabel, toMinorUnits } from '@/utils/planPrice'

describe('plan prices', () => {
  it('formats an amount in minor units with its currency and interval', () => {
    expect(formatPlanPrice(1900, 'USD', 'month')).toBe('$19.00 / month')
    expect(formatPlanPrice(500000, 'NGN', 'year')).toMatch(/5,000\.00 \/ year$/)
  })

  it('knows currencies without minor units', () => {
    expect(formatPlanPrice(1900, 'JPY', 'month')).toMatch(/1,900 \/ month$/)
    expect(toMinorUnits('1900', 'JPY')).toBe(1900)
  })

  it('says nothing for a plan without a price', () => {
    expect(formatPlanPrice(null, null, null)).toBeNull()
  })

  it('turns a typed price into minor units and back', () => {
    expect(toMinorUnits('19', 'USD')).toBe(1900)
    expect(toMinorUnits('19.99', 'usd')).toBe(1999)
    expect(toMinorUnits('abc', 'USD')).toBeNull()
    expect(toMinorUnits('-1', 'USD')).toBeNull()
    expect(fromMinorUnits(1999, 'USD')).toBe('19.99')
  })

  it('labels a plan choice with its price when it has one', () => {
    expect(
      planChoiceLabel({
        name: 'Pro',
        price_amount: 1900,
        price_currency: 'USD',
        billing_interval: 'month',
      }),
    ).toBe('Pro — $19.00 / month')
    expect(planChoiceLabel({ name: 'Free' })).toBe('Free')
  })
})
