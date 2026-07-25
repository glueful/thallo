import { useCommerceMeta } from '@/queries/commerceMeta'

/** The minimal shape `formatMoney`/`useMoney` need off `/commerce/meta`. */
export interface MoneyMeta {
  currency: string
  currency_exponent: number
}

// Fixed locale: the admin SPA has no i18n surface today, and pinning the locale keeps grouping/
// sign/currency-symbol placement (extracted from Intl below) deterministic across environments.
const LOCALE = 'en-US'

// A structural template amount, magnitude only — large enough to force Intl to emit a `group`
// part (so we can read its separator character) regardless of currency/exponent. Never the real
// amount: the real minor-unit value is never passed through Number, only through BigInt below.
const TEMPLATE_MAGNITUDE = 1234

/**
 * Parse a minor-unit amount to an exact BigInt. Rejects anything that cannot be represented
 * losslessly: non-integer numbers, numbers outside Number.MAX_SAFE_INTEGER, and non-integer
 * strings. `bigint` inputs pass straight through untouched.
 */
function parseMinorUnits(minor: number | string | bigint): bigint {
  if (typeof minor === 'bigint') return minor

  if (typeof minor === 'number') {
    if (!Number.isInteger(minor)) {
      throw new Error(
        `useMoney: amount must be an integer minor-unit value, got ${minor}. ` +
          'Pass whole minor units (e.g. cents), never a decimal amount.',
      )
    }
    if (!Number.isSafeInteger(minor)) {
      throw new Error(
        `useMoney: amount ${minor} exceeds Number.MAX_SAFE_INTEGER — pass a string or bigint ` +
          'instead so the value isn’t silently rounded.',
      )
    }
    return BigInt(minor)
  }

  if (typeof minor === 'string') {
    const trimmed = minor.trim()
    if (!/^-?\d+$/.test(trimmed)) {
      throw new Error(`useMoney: amount string must be a plain integer, got "${minor}".`)
    }
    return BigInt(trimmed)
  }

  throw new Error(`useMoney: unsupported amount type "${typeof minor}".`)
}

/** Split an exact minor-unit BigInt into its sign, major-unit digits, and zero-padded fraction. */
function splitMinorUnits(
  amount: bigint,
  exponent: number,
): { negative: boolean; major: string; fraction: string } {
  if (!Number.isInteger(exponent) || exponent < 0) {
    throw new Error(`useMoney: invalid currency_exponent ${exponent}.`)
  }
  const negative = amount < 0n
  const abs = negative ? -amount : amount
  if (exponent === 0) {
    return { negative, major: abs.toString(), fraction: '' }
  }
  const scale = 10n ** BigInt(exponent)
  const major = abs / scale
  const fraction = (abs % scale).toString().padStart(exponent, '0')
  return { negative, major: major.toString(), fraction }
}

/** Insert `separator` into a plain digit string every 3 digits from the right. */
function groupDigits(digits: string, separator: string): string {
  if (separator === '') return digits
  const groups: string[] = []
  let end = digits.length
  while (end > 3) {
    groups.unshift(digits.slice(end - 3, end))
    end -= 3
  }
  groups.unshift(digits.slice(0, end))
  return groups.join(separator)
}

/**
 * Parse a user-typed MAJOR-unit decimal string (e.g. "12.34") into an exact minor-unit `BigInt`
 * (e.g. `1234n`), mirroring `splitMinorUnits`'s discipline in reverse: the string is validated and
 * parsed as plain digit groups via a regex + `BigInt`, NEVER coerced through `Number` — so no
 * float rounding can smuggle in an off-by-one-minor-unit amount (task-13c brief, Refunds).
 *
 * Returns `null` — never throws — for anything that isn't a plain unsigned decimal with AT MOST
 * `exponent` fractional digits: empty/whitespace-only input, a sign, thousands separators,
 * multiple decimal points, non-digit characters, or MORE fractional digits than the currency's own
 * minor-unit resolution (e.g. `"12.345"` at exponent 2 is finer granularity than the currency
 * has — every minor unit boundary must be exact, so this rejects rather than rounds). Fewer
 * fractional digits than `exponent` are right-padded with zeros (`"12.3"` at exponent 2 -> 1230),
 * matching how `splitMinorUnits` pads the fraction on the way out.
 */
export function parseMajorAmountToMinorUnits(input: string, exponent: number): bigint | null {
  if (!Number.isInteger(exponent) || exponent < 0) {
    throw new Error(`parseMajorAmountToMinorUnits: invalid currency_exponent ${exponent}.`)
  }

  const trimmed = input.trim()
  const pattern = exponent === 0 ? /^\d+$/ : new RegExp(`^\\d+(\\.\\d{1,${exponent}})?$`)
  if (!pattern.test(trimmed)) return null

  const [integerPart, fractionPart = ''] = trimmed.split('.')
  const scale = 10n ** BigInt(exponent)
  const fractionMinor = exponent === 0 ? 0n : BigInt(fractionPart.padEnd(exponent, '0'))
  return BigInt(integerPart) * scale + fractionMinor
}

/**
 * Render a minor-unit amount as the plain MAJOR-unit decimal string a text input hydrates with —
 * the exact inverse of `parseMajorAmountToMinorUnits` (round-trip safe: parse(format(x)) === x).
 * No currency symbol, no grouping separators, full zero-padded fraction (`1999` at exponent 2 →
 * `"19.99"`, `700_00` → `"700.00"`, exponent 0 passes digits through). Same BigInt discipline as
 * `formatMoney`: the amount never touches float arithmetic.
 */
export function minorToMajorInputString(minor: number | string | bigint, exponent: number): string {
  const { negative, major, fraction } = splitMinorUnits(parseMinorUnits(minor), exponent)
  const digits = exponent === 0 ? major : `${major}.${fraction}`
  return negative ? `-${digits}` : digits
}

/**
 * Format an exact minor-unit amount as a localized currency string, never passing the full
 * decimal amount through JavaScript `Number`. The amount is parsed straight to `BigInt` and
 * split into major/fraction with `10n ** BigInt(exponent)`; `Intl.NumberFormat(...)
 * .formatToParts()` is consulted only for the currency symbol, literal spacing, decimal
 * separator, grouping separator, and sign placement — never for the digits themselves.
 */
export function formatMoney(minor: number | string | bigint, meta: MoneyMeta): string {
  const amount = parseMinorUnits(minor)
  const { negative, major, fraction } = splitMinorUnits(amount, meta.currency_exponent)

  const formatter = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: meta.currency,
    minimumFractionDigits: meta.currency_exponent,
    maximumFractionDigits: meta.currency_exponent,
  })

  // Structural template only — its digits are discarded, we keep just the part layout (sign,
  // currency symbol/position, literals, decimal separator, group separator).
  const templateParts = formatter.formatToParts(negative ? -TEMPLATE_MAGNITUDE : TEMPLATE_MAGNITUDE)
  const groupSeparator = templateParts.find((p) => p.type === 'group')?.value ?? ''
  const groupedMajor = groupDigits(major, groupSeparator)

  let out = ''
  let integerWritten = false
  for (const part of templateParts) {
    if (part.type === 'integer' || part.type === 'group') {
      if (!integerWritten) {
        out += groupedMajor
        integerWritten = true
      }
      continue
    }
    if (part.type === 'fraction') {
      out += fraction
      continue
    }
    out += part.value
  }
  return out
}

/**
 * `{ format }` for rendering minor-unit money amounts. Reads `currency`/`currency_exponent`
 * reactively from `useCommerceMeta()` by default; pass `meta` to override (tests, or call sites
 * that already have the settled meta and want to skip the query dependency).
 */
export function useMoney(meta?: MoneyMeta) {
  const query = meta ? null : useCommerceMeta()

  function format(minor: number | string | bigint): string {
    const resolved = meta ?? query?.data.value
    if (!resolved) {
      throw new Error(
        'useMoney: commerce meta is not loaded yet — cannot format an amount before ' +
          '/commerce/meta resolves.',
      )
    }
    return formatMoney(minor, resolved)
  }

  return { format }
}
