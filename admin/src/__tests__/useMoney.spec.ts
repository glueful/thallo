import { describe, it, expect } from 'vitest'
import { useMoney } from '@/composables/useMoney'

const USD = { currency: 'USD', currency_exponent: 2 }
const JPY = { currency: 'JPY', currency_exponent: 0 }
const KWD = { currency: 'KWD', currency_exponent: 3 }

// useMoney() parses the exact minor-unit value to BigInt, splits major/fraction with
// 10n ** BigInt(exponent), and uses Intl.NumberFormat(...).formatToParts() only for
// currency symbol/grouping/placement — the full decimal amount is never coerced through
// JavaScript Number (design spec §5, task-9 brief).
describe('useMoney — exponent-safe BigInt money formatting', () => {
  it('formats a zero-decimal currency (JPY, exponent 0)', () => {
    const { format } = useMoney(JPY)
    expect(format(1234)).toBe('¥1,234')
    expect(format(-1234)).toBe('-¥1,234')
  })

  it('formats a two-decimal currency (USD, exponent 2)', () => {
    const { format } = useMoney(USD)
    expect(format(123456)).toBe('$1,234.56')
  })

  it('formats -1 minor unit at exponent 2 as "-$0.01"', () => {
    const { format } = useMoney(USD)
    expect(format(-1)).toBe('-$0.01')
  })

  it('formats a three-decimal currency (KWD, exponent 3)', () => {
    // en-US has no native KWD symbol, so Intl falls back to the ISO code "KWD" followed by a
    // NON-BREAKING space (U+00A0, not a plain " ") before the amount. formatMoney reproduces
    // Intl's own literal verbatim, so the expected strings below embed a real U+00A0.
    const { format } = useMoney(KWD)
    expect(format(1234500)).toBe('KWD 1,234.500')
    expect(format(-1234500)).toBe('-KWD 1,234.500')
  })

  it('formats a string minor-unit input larger than Number.MAX_SAFE_INTEGER exactly', () => {
    const { format } = useMoney(USD)
    expect(format('12345678901234567890')).toBe('$123,456,789,012,345,678.90')
  })

  it('formats a bigint minor-unit input larger than Number.MAX_SAFE_INTEGER exactly', () => {
    const { format } = useMoney(USD)
    expect(format(12345678901234567890n)).toBe('$123,456,789,012,345,678.90')
  })

  it('never coerces the assembled amount through Number (precision would be lost)', () => {
    // Sanity check on the premise: naive Number-based division loses precision for this input.
    const naive = Number('12345678901234567890') / 100
    expect(String(naive)).not.toBe('123456789012345678.9')

    const { format } = useMoney(USD)
    expect(format('12345678901234567890')).toBe('$123,456,789,012,345,678.90')
  })

  it('rejects a non-integer number amount', () => {
    const { format } = useMoney(USD)
    expect(() => format(1.5)).toThrow()
  })

  it('rejects an unsafe-integer number amount', () => {
    const { format } = useMoney(USD)
    expect(() => format(Number.MAX_SAFE_INTEGER + 1)).toThrow()
  })
})
