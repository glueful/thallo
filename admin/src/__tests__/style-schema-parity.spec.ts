import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { styleProperties } from '@/style/schema'
import { HOVER_OF } from '@/style/capabilities'

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

  it('maps each hover path to its resting path as the PHP schema does', () => {
    const snap = JSON.parse(readFileSync(SNAPSHOT, 'utf8')) as {
      properties: { path: string; group: string }[]
    }
    // StyleSchema::HOVER is exactly the `hover` group, each path the resting one under `hover.`.
    const expected = Object.fromEntries(
      snap.properties.filter((r) => r.group === 'hover').map((r) => [r.path, r.path.slice(6)]),
    )
    expect(HOVER_OF).toEqual(expected)
  })
})
