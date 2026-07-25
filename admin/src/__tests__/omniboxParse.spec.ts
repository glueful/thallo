import { describe, it, expect } from 'vitest'
import { parseOmnibox } from '@/utils/omniboxParse'

// Spec §5.4 (Omnibox Launcher): the conservative-parse grammar, pinned case by case. The rule
// under test everywhere: ambiguity resolves toward "it's the name", never toward a price guess.

describe('parseOmnibox', () => {
  it.each<[string, string, string | null]>([
    // dollar-prefixed trailing tokens lift
    ['Aurora Desk Lamp $89', 'Aurora Desk Lamp', '89'],
    ['Aurora Desk Lamp $89.99', 'Aurora Desk Lamp', '89.99'],
    ['Aurora Desk Lamp $89.9', 'Aurora Desk Lamp', '89.9'],
    // bare decimals lift
    ['Aurora Desk Lamp 89.99', 'Aurora Desk Lamp', '89.99'],
    // bare integers do NOT lift — model numbers are names
    ['Aurora Desk Lamp 89', 'Aurora Desk Lamp 89', null],
    ['PS 5', 'PS 5', null],
    // a money token with no name IS the name
    ['$89', '$89', null],
    ['89.99', '89.99', null],
    // money-looking text mid-name never lifts
    ['$5 Footlong Sandwich', '$5 Footlong Sandwich', null],
    // whitespace normalization
    ['  Aurora   Desk  Lamp   $89  ', 'Aurora Desk Lamp', '89'],
    // empty input
    ['', '', null],
    ['   ', '', null],
    // dollar token glued to the name (no space) stays in the name
    ['Lamp$89', 'Lamp$89', null],
    // more than 3 decimals is not a money token
    ['Widget 1.2345', 'Widget 1.2345', null],
  ])('parseOmnibox(%j) -> name %j, token %j', (input, name, token) => {
    expect(parseOmnibox(input)).toEqual({ name, majorToken: token })
  })

  // ── The currency-neutral marker: the tenant's own code, suffix or prefix ────────────────────

  it.each<[string, string, string, string | null]>([
    // suffix + prefix forms, whole numbers included (the neutral whole-number path)
    ['Aurora Desk Lamp 89 GHS', 'GHS', 'Aurora Desk Lamp', '89'],
    ['Aurora Desk Lamp GHS 89', 'GHS', 'Aurora Desk Lamp', '89'],
    ['Aurora Desk Lamp 89.99 GHS', 'GHS', 'Aurora Desk Lamp', '89.99'],
    ['Lamp GHS 89.99', 'GHS', 'Lamp', '89.99'],
    // case-insensitive
    ['Aurora Desk Lamp 89 ghs', 'GHS', 'Aurora Desk Lamp', '89'],
    // zero-decimal currencies get whole numbers without any "$"
    ['Ramen Bowl 1200 JPY', 'JPY', 'Ramen Bowl', '1200'],
    // a DIFFERENT code than the tenant's is not a marker
    ['Aurora Desk Lamp 89 EUR', 'GHS', 'Aurora Desk Lamp 89 EUR', null],
    // the code alone with no name is a name, not a price
    ['GHS 89', 'GHS', 'GHS 89', null],
    // mid-name code never lifts
    ['GHS 89 Special Edition', 'GHS', 'GHS 89 Special Edition', null],
  ])('parseOmnibox(%j, %j) -> name %j, token %j', (input, code, name, token) => {
    expect(parseOmnibox(input, code)).toEqual({ name, majorToken: token })
  })

  it('without a currency code, code-marked tokens stay in the name', () => {
    expect(parseOmnibox('Aurora Desk Lamp 89 GHS')).toEqual({
      name: 'Aurora Desk Lamp 89 GHS',
      majorToken: null,
    })
  })

  it('the dollar marker still works regardless of tenant currency', () => {
    expect(parseOmnibox('Aurora Desk Lamp $89', 'GHS')).toEqual({
      name: 'Aurora Desk Lamp',
      majorToken: '89',
    })
  })
})
