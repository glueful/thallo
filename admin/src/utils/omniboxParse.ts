// Spec §5.4 (Omnibox Launcher): the conservative parse behind the create omnibox. Pure — no
// Vue, no I/O — so the grammar can be pinned exhaustively in isolation.
//
// ONLY a clean TRAILING money token is lifted out as the price. Currency-NEUTRAL by design —
// nobody is forced into "$" for a non-dollar tenant:
//   - `89.99`               (bare number WITH decimals)
//   - `$89` / `$89.99`      ("$" kept as a universally-recognized generic price marker)
//   - `89 GHS` / `GHS 89`   (the TENANT'S currency code as suffix or prefix, case-insensitive —
//                            the neutral whole-number path, and the ONLY whole-number path for
//                            zero-decimal currencies where "89.99" is not a valid amount)
// A bare trailing integer with no marker ("Lamp 89") stays in the name — it is at least as
// likely to be a model number as a price. A money token with NO name before it is a name, not
// a price ("$89" alone names a product "$89"). Everything ambiguous resolves toward "it's the
// name": a wrong price guess costs money-trust; a longer name costs nothing.
//
// The token is returned as the raw MAJOR-unit string; conversion to minor units happens at the
// call site through `parseMajorAmountToMinorUnits` with the tenant meta's currency exponent
// (BigInt string math, never Number() float math). If that conversion rejects the token (e.g.
// "89.99" under a zero-exponent currency), the caller must fall back to treating the WHOLE
// input as the name — text the merchant typed is never silently dropped.

export interface OmniboxParse {
  /** The product name — the input minus a lifted money token, whitespace-trimmed. */
  name: string
  /** The trailing money token's major-unit digits ("89", "89.99"), or null when none lifted. */
  majorToken: string | null
}

const AMOUNT = String.raw`\d+(?:\.\d{1,3})?`
const DOLLAR_MARKER = new RegExp(String.raw`^(.*\S)\s+\$(${AMOUNT})$`)
const BARE_DECIMAL = new RegExp(String.raw`^(.*\S)\s+(\d+\.\d{1,3})$`)

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export function parseOmnibox(text: string, currencyCode?: string): OmniboxParse {
  const trimmed = text.trim().replace(/\s+/g, ' ')
  if (trimmed === '') return { name: '', majorToken: null }

  const patterns: RegExp[] = [DOLLAR_MARKER]
  if (currencyCode && currencyCode.trim() !== '') {
    const code = escapeRegExp(currencyCode.trim())
    // Code patterns run BEFORE the bare-decimal one so "Lamp GHS 89.99" lifts as prefix-marked
    // (name "Lamp"), never as a bare decimal that would leave "GHS" stranded in the name.
    patterns.push(
      new RegExp(String.raw`^(.*\S)\s+(${AMOUNT})\s+${code}$`, 'i'),
      new RegExp(String.raw`^(.*\S)\s+${code}\s+(${AMOUNT})$`, 'i'),
    )
  }
  patterns.push(BARE_DECIMAL)

  for (const pattern of patterns) {
    const match = trimmed.match(pattern)
    if (match) {
      const name = match[1].trim()
      if (name === '') break
      return { name, majorToken: match[2] }
    }
  }
  return { name: trimmed, majorToken: null }
}
