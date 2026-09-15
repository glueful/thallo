import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { capabilityPaths, detachStyleClass } from '@/style/detach'

// Visual builder spec §4.4: one fixture set, two runtimes, byte-equivalent normalised output
// (`packages/thallo-render/detach-fixtures/v1`).
const FIXTURES = join(__dirname, '../../../packages/thallo-render/detach-fixtures/v1')

interface FixtureCase {
  name: string
  capabilities: string[]
  classes: { id: string; style: Record<string, unknown> }[]
  instance: Record<string, unknown>
  detach: string
  expect: Record<string, unknown>
}

const files = readdirSync(FIXTURES).filter((f) => f.endsWith('.json'))

describe('detach fixtures', () => {
  it('has fixtures', () => {
    expect(files.length).toBeGreaterThan(0)
  })

  for (const file of files) {
    const doc = JSON.parse(readFileSync(join(FIXTURES, file), 'utf8')) as { cases: FixtureCase[] }
    for (const c of doc.cases) {
      it(`${file} / ${c.name}`, () => {
        const out = detachStyleClass(
          c.classes,
          c.instance,
          c.detach,
          capabilityPaths(c.capabilities),
        )
        expect(JSON.stringify(out, null, 2)).toBe(JSON.stringify(c.expect, null, 2))
      })
    }
  }

  it('is pure: the inputs are untouched', () => {
    const classes = [{ id: 'c1', style: { radius: { type: 'token', value: 'radius.lg' } } }]
    const instance: Record<string, unknown> = {}
    detachStyleClass(classes, instance, 'c1', capabilityPaths(['radius']))
    expect(instance).toEqual({})
    expect(classes[0]!.style).toEqual({ radius: { type: 'token', value: 'radius.lg' } })
  })

  it('expands a group declaration to its paths and keeps exact paths', () => {
    expect([...capabilityPaths(['spacing', 'radius'])]).toContain('spacing.margin.top')
    expect(capabilityPaths(['nope']).size).toBe(0)
  })
})
