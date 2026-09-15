import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  candidateTree,
  checkInsert,
  checkMoves,
  type LegalityContext,
} from '@/editor/structure/legality'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

// Visual builder spec §5.2: one legality module, its rules shared with the server validator
// through `tests/fixtures/structure/legality` — each case names the builder's expectation
// (always enforcing allow-lists) and the server's (enforce_block_types is its switch).
const FIXTURES = join(__dirname, '../../../tests/fixtures/structure/legality')

interface FixtureType {
  slug: string
  label: string
  schema: { name: string; type: string; block_types?: string[] }[]
}
interface FixtureCase {
  name: string
  blockTypes: FixtureType[]
  rootSlots: Record<string, { block_types: string[] }>
  doc: { fields: Record<string, unknown> }
  moves?: { block: string; to: { parent: string | null; slot: string | null; index: number } }[]
  insert?: {
    position: { parent: string | null; slot: string | null; index: number }
    block: BlockInstance
  }
  candidate?: { fields: Record<string, unknown> }
  expect: { builder: string; server: string | null }
}

function contextOf(c: FixtureCase): LegalityContext {
  const types = c.blockTypes.map((t) => ({
    slug: t.slug,
    label: t.label,
    slots: Object.fromEntries(
      t.schema
        .filter((f) => f.type === 'blocks')
        .map((f) => [f.name, { blockTypes: f.block_types ?? [] }]),
    ),
  }))
  return {
    regionsOf: (slug) => Object.keys(types.find((t) => t.slug === slug)?.slots ?? {}),
    blockTypes: () => types,
    rootSlots: () =>
      Object.fromEntries(
        Object.entries(c.rootSlots).map(([k, v]) => [k, { blockTypes: v.block_types }]),
      ),
    maxDepth: 5,
  }
}

const files = readdirSync(FIXTURES).filter((f) => f.endsWith('.json'))

describe('tree legality fixtures', () => {
  it('has fixtures', () => {
    expect(files.length).toBeGreaterThan(0)
  })

  for (const file of files) {
    const doc = JSON.parse(readFileSync(join(FIXTURES, file), 'utf8')) as { cases: FixtureCase[] }
    for (const c of doc.cases) {
      it(`${file} / ${c.name}`, () => {
        const ctx = contextOf(c)
        const verdict = c.moves
          ? checkMoves(c.doc, c.moves, ctx)
          : checkInsert(c.doc, c.insert!.position, c.insert!.block, ctx)
        expect(verdict.ok ? 'ok' : verdict.reason).toBe(c.expect.builder)
        if (c.candidate && c.moves) {
          expect(candidateTree(c.doc, c.moves, ctx)).toEqual(c.candidate)
        }
      })
    }
  }

  it('an illegal verdict carries a message a person can act on', () => {
    const ctx: LegalityContext = {
      regionsOf: (slug) => (slug === 'section' ? ['content'] : []),
      blockTypes: () => [
        { slug: 'section', label: 'Section', slots: { content: { blockTypes: [] } } },
      ],
      rootSlots: () => ({ body: { blockTypes: [] } }),
      maxDepth: 5,
    }
    const doc = {
      fields: { body: [{ id: 'a', type: 'section', data: { content: [] }, settings: {} }] },
    }
    const verdict = checkMoves(
      doc,
      [{ block: 'a', to: { parent: 'a', slot: 'content', index: 0 } }],
      ctx,
    )
    expect(verdict).toEqual({ ok: false, reason: 'cycle', message: 'A block cannot hold itself' })
  })
})
