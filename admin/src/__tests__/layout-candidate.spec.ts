import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'
import { checkLayoutCandidate, type LayoutRules } from '@/editor/structure/layoutCandidate'
import type { EditorDocument } from '@/editor/ops/types'

// A section is checked against the whole layout before it lands (sections and templates design §4),
// with the server's own rules. The cases are shared with the server's parity test
// (tests/Integration/Content/Layouts/LayoutCandidateParityTest.php): what this check accepts, apply
// accepts, and what it refuses, apply refuses for the same rule.

interface Field {
  name: string
  type: string
  format?: string
  label?: string
}
interface Fixture {
  types: Record<string, { name: string; schema: Field[] }>
  cases: {
    name: string
    surface: string
    target: string
    blocks: unknown[]
    expect: string
  }[]
}

const fixture = JSON.parse(
  readFileSync(
    resolvePath(__dirname, '../../../tests/fixtures/layouts/candidate-cases.json'),
    'utf8',
  ),
) as Fixture

// The session's constants, as LayoutFragmentTest asserts them.
const BINDINGS: Record<string, string[]> = {
  entry_cover: ['asset'],
  entry_terms: ['reference'],
  entry_excerpt: ['string', 'text'],
  entry_field: ['string', 'text', 'text:rich', 'number', 'boolean', 'datetime', 'enum'],
  entry_content: ['blocks'],
}
const DEFAULT_FIELDS: Record<string, string> = {
  entry_content: 'body',
  entry_cover: 'cover',
  entry_excerpt: 'excerpt',
  entry_terms: 'categories',
}
const FORMAT_NEEDS: Record<string, string[]> = { date: ['datetime'], number: ['number'] }
const LABELS: Record<string, string> = {
  product_buy: 'Product buy box',
  entry_cover: 'Cover',
  entry_content: 'Entry content',
  entry_field: 'Entry field',
}
const human = (name: string) => name.charAt(0).toUpperCase() + name.slice(1).replace(/_/g, ' ')

function rulesFor(surface: string, target: string): LayoutRules {
  const base = {
    field: 'blocks',
    bindings: BINDINGS,
    defaultFields: DEFAULT_FIELDS,
    formatNeeds: FORMAT_NEEDS,
  }
  const label = (type: string) => LABELS[type] ?? type
  if (surface === 'product') {
    return {
      ...base,
      label,
      required: [{ type: 'product_buy' }],
      bindable: {},
      fieldLabels: {},
      typeName: null,
    }
  }
  const type = fixture.types[target]!
  const bindable: Record<string, string> = {}
  const fieldLabels: Record<string, string> = {}
  for (const f of type.schema) {
    bindable[f.name] = f.type === 'text' && f.format === 'rich' ? 'text:rich' : f.type
    fieldLabels[f.name] = f.label ?? human(f.name)
  }
  const blocksFields = type.schema.filter((f) => f.type === 'blocks').map((f) => f.name)
  const primary = blocksFields.includes('body') ? 'body' : blocksFields[0]
  return {
    ...base,
    label,
    required: primary === undefined ? [] : [{ type: 'entry_content', field: primary }],
    bindable,
    fieldLabels,
    typeName: type.name,
  }
}

const doc = (blocks: unknown[]): EditorDocument => ({ fields: { blocks } })
const check = (name: string) => {
  const c = fixture.cases.find((x) => x.name === name)!
  return checkLayoutCandidate(doc(c.blocks), rulesFor(c.surface, c.target))
}

describe('the candidate check agrees with the server', () => {
  for (const c of fixture.cases) {
    it(`${c.name}: ${c.expect === 'ok' ? 'accepted' : `refused (rule ${c.expect})`}`, () => {
      const verdict = checkLayoutCandidate(doc(c.blocks), rulesFor(c.surface, c.target))
      expect(verdict.ok).toBe(c.expect === 'ok')
    })
  }
})

describe('each refusal says why', () => {
  it('a second required block', () => {
    expect(check('second buy box')).toEqual({
      ok: false,
      reason: 'type-not-allowed',
      message: 'The Product buy box can appear only once',
    })
  })
  it('a field the type lacks, by its humanised name', () => {
    expect(check('missing field')).toMatchObject({
      message: 'This section shows “Subtitle”, which LF posts doesn’t have',
    })
  })
  it('a field the block cannot show, by its label', () => {
    expect(check('incompatible field')).toMatchObject({
      message: 'The Cover block can’t show “Headline”',
    })
  })
  it('a format the field cannot take', () => {
    expect(check('date format on a string')).toMatchObject({
      message: '“Headline” can’t be shown as a date',
    })
  })
  it('a blocks field shown twice', () => {
    expect(check('body shown twice')).toMatchObject({
      message: '“Body” is already shown by another block',
    })
    expect(check('secondary blocks field shown twice')).toMatchObject({
      message: '“Sidebar” is already shown by another block',
    })
  })
  it('an unbound Entry content counts as showing body', () => {
    expect(check('unbound Entry content on a content-named body')).toMatchObject({
      message: 'This section shows “Body”, which LF content doesn’t have',
    })
    expect(check('unbound Entry content on a rich-text body')).toMatchObject({
      message: 'The Entry content block can’t show “Body”',
    })
  })
  it('without a type name the page is named', () => {
    const c = fixture.cases.find((x) => x.name === 'missing field')!
    expect(
      checkLayoutCandidate(doc(c.blocks), { ...rulesFor(c.surface, c.target), typeName: null }),
    ).toMatchObject({ message: 'This section shows “Subtitle”, which this page doesn’t have' })
  })
})
