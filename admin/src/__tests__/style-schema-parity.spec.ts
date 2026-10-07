import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { styleProperties } from '@/style/schema'

// The admin's mirror of the style schema is the PHP schema (hover state spec §8): both are checked
// against one committed snapshot, so whichever side falls behind fails.
const SNAPSHOT = resolve(process.cwd(), '../packages/thallo-contracts/style-schema/v1.json')

describe('the style schema mirror', () => {
  it('is the PHP schema, row for row', () => {
    const snap = JSON.parse(readFileSync(SNAPSHOT, 'utf8')) as { properties: unknown[] }
    const ts = styleProperties().map((p) => ({
      path: p.path,
      group: p.group,
      responsive: p.responsive,
      token_domain: p.tokenDomain,
      choices: p.choices,
      kinds: p.kinds,
    }))
    expect(ts).toEqual(snap.properties)
  })
})
