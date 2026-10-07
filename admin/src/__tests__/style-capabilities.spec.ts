import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { tabOf } from '@/editor/inspector/tabMap'
import { effectivePaths, expandDeclaration, HOVER_OF, partBlockType } from '@/style/capabilities'
import type { BlockStylePaths, BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

// What a block type offers (hover state spec §2.2.1): the admin reads the server's expansion,
// `style_paths`, for every block type and part — the shared fixtures (style-capability-fixtures/v1)
// run here through the Style tab, as PHP runs them through StyleTargets and the payload.
const FIXTURES = resolve(process.cwd(), '../packages/thallo-contracts/style-capability-fixtures/v1')
const read = <T>(file: string): T => JSON.parse(readFileSync(`${FIXTURES}/${file}`, 'utf8')) as T

interface ExpansionCase {
  name: string
  declaration: { style_capabilities: string[]; style_targets: Record<string, unknown> }
  expect?: BlockStylePaths
  error?: string
}
const expansion = read<{ cases: ExpansionCase[] }>('expansion.json').cases
const multiSelect = read<{ cases: { name: string; slugs: string[]; expect_hover: string[] }[] }>(
  'multi-select.json',
).cases
const starters = read<Record<string, BlockStylePaths>>('starters.json')

const schema = classEditorSchema()
const isHover = (path: string) => HOVER_OF[path] !== undefined

function blockType(slug: string, decl: Partial<BlockType> = {}): BlockType {
  return {
    uuid: slug,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [],
    style_capabilities: null,
    style_targets: null,
    flags: null,
    starter_content: null,
    ...decl,
  } as BlockType
}

function rendered(type: BlockType, extra: Record<string, unknown> = {}): string[] {
  const w = mount(StyleTab, {
    props: {
      block: { id: 'b1', type: type.slug, data: {}, settings: { style: {} } },
      blockType: type,
      schema,
      classes: [],
      activeBreakpoint: 'base',
      ...extra,
    } as never,
  })
  return w.findAll('[data-test^="style-field-"]').map((f) => f.attributes('data-test')!.slice(12))
}

/** What the Style tab shows at rest: the style-tab paths, without the hover rows (the switch's). */
const styleRows = (paths: Iterable<string>) =>
  [...paths].filter((p) => tabOf(p) === 'style' && !isHover(p)).sort()

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('the shared expansion fixtures', () => {
  for (const c of expansion.filter((x) => x.expect !== undefined)) {
    it(`${c.name}: the block offers exactly its style_paths`, () => {
      const type = blockType('fixture', { ...c.declaration, style_paths: c.expect! })
      expect(effectivePaths(type)).toEqual(new Set(c.expect!.block))
      expect(rendered(type).sort()).toEqual(styleRows(c.expect!.block))
    })
    for (const [part, paths] of Object.entries(c.expect!.parts)) {
      it(`${c.name}: part ${part} offers exactly its style_paths`, () => {
        const type = blockType('fixture', { ...c.declaration, style_paths: c.expect! })
        expect(effectivePaths(partBlockType(type, part))).toEqual(new Set(paths))
      })
    }
  }
})

describe('a sibling multi-selection', () => {
  for (const c of multiSelect) {
    it(`${c.name}: shows only what every block offers`, () => {
      const types = c.slugs.map((slug) => blockType(slug, { style_paths: starters[slug] }))
      const shared = types
        .map(effectivePaths)
        .reduce((a, b) => new Set([...a].filter((p) => b.has(p))))
      expect([...shared].filter(isHover).sort()).toEqual([...c.expect_hover].sort())
      const blocks = types.map((t, i) => ({ id: `b${i}`, type: t.slug, data: {}, settings: {} }))
      expect(rendered(types[0]!, { blocks, blockTypes: types }).sort()).toEqual(styleRows(shared))
    })
  }
})

describe('synthetic types expand locally, with the hover rule', () => {
  it('a style class offers every hover path', () => {
    const groups = [...new Set(schema.properties.map((r) => r.group))]
    const paths = effectivePaths(blockType('style_class', { style_capabilities: groups }))
    for (const hover of Object.keys(HOVER_OF)) expect(paths.has(hover)).toBe(true)
  })
  it('a region offers none', () => {
    const paths = effectivePaths(
      blockType('region_header', {
        style_capabilities: ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop'],
      }),
    )
    expect([...paths].filter(isHover)).toEqual([])
  })
  it('a hover path exists only beside its resting path', () => {
    expect(expandDeclaration(['hover'])).toEqual(new Set())
    expect(expandDeclaration(['hover', 'colors.text'])).toEqual(
      new Set(['colors.text', 'hover.colors.text']),
    )
    expect(expandDeclaration(['hover.colors.surface', 'colors.text'])).toEqual(
      new Set(['colors.text']),
    )
  })
})
