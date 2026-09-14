import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { resolve } from '@/style/resolver'
import { propertyDefinition } from '@/style/schema'
import type { StyleValue } from '@/style/types'

// Visual builder spec §3.3: one fixture set, two runtimes, byte-equivalent normalised output.
const FIXTURES = join(__dirname, '../../../packages/thallo-render/resolver-fixtures/v1')

interface FixtureCase {
  name: string
  property: string
  classes: { id: string; style: Record<string, unknown> }[]
  instance: Record<string, unknown>
  expect: Record<string, { value: StyleValue | null; source: string; state: string }>
}

const files = readdirSync(FIXTURES).filter((f) => f.endsWith('.json'))

describe('cascade resolver fixtures', () => {
  it('has fixtures', () => {
    expect(files.length).toBeGreaterThan(0)
  })

  for (const file of files) {
    const doc = JSON.parse(readFileSync(join(FIXTURES, file), 'utf8')) as { cases: FixtureCase[] }
    for (const c of doc.cases) {
      it(`${file} / ${c.name}`, () => {
        const def = propertyDefinition(c.property)
        expect(def).not.toBeNull()
        const out = resolve(c.property, c.classes, c.instance, def!)
        const normalised: Record<string, unknown> = {}
        for (const [bp, r] of Object.entries(out)) {
          normalised[bp] = { value: r.value, source: r.source, state: r.state }
        }
        expect(JSON.stringify(normalised, null, 2)).toBe(JSON.stringify(c.expect, null, 2))
      })
    }
  }
})
