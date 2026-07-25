import { describe, it, expect } from 'vitest'
import { useMoney, parseMajorAmountToMinorUnits } from '@/composables/useMoney'

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

// parseMajorAmountToMinorUnits() — the inverse of splitMinorUnits(): a user-typed MAJOR-unit
// decimal string parsed to an exact minor-unit BigInt via regex + BigInt only, never Number
// (task-13c brief, Refunds — "NO float arithmetic").
describe('parseMajorAmountToMinorUnits — decimal-string to exact minor units', () => {
  it('parses a full two-decimal amount at exponent 2', () => {
    expect(parseMajorAmountToMinorUnits('12.34', 2)).toBe(1234n)
  })

  it('right-pads a short fraction at exponent 2 ("12.3" -> 1230)', () => {
    expect(parseMajorAmountToMinorUnits('12.3', 2)).toBe(1230n)
  })

  it('parses a whole amount with no decimal point at exponent 2', () => {
    expect(parseMajorAmountToMinorUnits('12', 2)).toBe(1200n)
  })

  it('parses the finest fraction a 3-decimal currency allows ("0.001" -> 1)', () => {
    expect(parseMajorAmountToMinorUnits('0.001', 3)).toBe(1n)
  })

  it('parses a whole amount at exponent 0 (e.g. JPY)', () => {
    expect(parseMajorAmountToMinorUnits('1234', 0)).toBe(1234n)
  })

  it('rejects a decimal point at exponent 0', () => {
    expect(parseMajorAmountToMinorUnits('12.3', 0)).toBeNull()
  })

  it('rejects more fractional digits than the currency exponent allows ("12.345" at exponent 2)', () => {
    expect(parseMajorAmountToMinorUnits('12.345', 2)).toBeNull()
  })

  it('rejects non-numeric input', () => {
    expect(parseMajorAmountToMinorUnits('abc', 2)).toBeNull()
  })

  it('rejects an empty or whitespace-only string', () => {
    expect(parseMajorAmountToMinorUnits('', 2)).toBeNull()
    expect(parseMajorAmountToMinorUnits('   ', 2)).toBeNull()
  })

  it('rejects a negative amount', () => {
    expect(parseMajorAmountToMinorUnits('-12.34', 2)).toBeNull()
  })

  it('rejects a trailing decimal point with no fractional digits', () => {
    expect(parseMajorAmountToMinorUnits('12.', 2)).toBeNull()
  })

  it('rejects multiple decimal points', () => {
    expect(parseMajorAmountToMinorUnits('12.3.4', 2)).toBeNull()
  })

  it('rejects a thousands separator', () => {
    expect(parseMajorAmountToMinorUnits('1,234.56', 2)).toBeNull()
  })

  it('tolerates surrounding whitespace', () => {
    expect(parseMajorAmountToMinorUnits('  12.34  ', 2)).toBe(1234n)
  })

  it('parses an amount far beyond Number.MAX_SAFE_INTEGER exactly', () => {
    expect(parseMajorAmountToMinorUnits('123456789012345678.90', 2)).toBe(12345678901234567890n)
  })
})
