// The Style tab's Normal / Hover switch (hover state spec §6.2): on the sections that have a
// hoverable row — Colours and Effects — Hover swaps those rows for their hover counterparts and hides
// the rest; one state for the whole tab, back to Normal on a new selection; a dot marks a declared
// hover value; hover values are written bare (one value for every width).
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { partBlockType } from '@/style/capabilities'
import type { BlockStylePaths, BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

const FIXTURES = resolve(process.cwd(), '../packages/thallo-contracts/style-capability-fixtures/v1')
const starters = JSON.parse(readFileSync(`${FIXTURES}/starters.json`, 'utf8')) as Record<
  string,
  BlockStylePaths
>
const schema = classEditorSchema()

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
const button = blockType('button', { style_paths: starters.button })
const socialLink = blockType('social_link', {
  style_paths: starters.social_link,
  style_targets: { parts: { icon: { label: 'Icon', capabilities: [] } } },
})

function mountTab(
  type: BlockType,
  style: Record<string, unknown> = {},
  extra: Record<string, unknown> = {},
  id = 'b1',
) {
  return mount(StyleTab, {
    props: {
      block: { id, type: type.slug, data: {}, settings: { style } },
      blockType: type,
      schema,
      classes: [],
      activeBreakpoint: 'base',
      ...extra,
    } as never,
  })
}
const fields = (w: ReturnType<typeof mountTab>, group: string) =>
  w
    .find(`[data-test="style-group-${group}"]`)
    .findAll('[data-test^="style-field-"]')
    .map((f) => f.attributes('data-test')!.slice(12))

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('the Normal / Hover switch', () => {
  it('shows on Colours and Effects only', () => {
    const w = mountTab(button)
    expect(w.find('[data-test="style-state-hover-colors"]').exists()).toBe(true)
    expect(w.find('[data-test="style-state-hover-effects"]').exists()).toBe(true)
    expect(w.find('[data-test="style-state-hover-typography"]').exists()).toBe(false)
    expect(w.find('[data-test="style-state-hover-spacing"]').exists()).toBe(false)
  })

  it('swaps the hoverable rows for their hover counterparts, in the same order, and hides the rest', async () => {
    const w = mountTab(button)
    expect(fields(w, 'colors')).toEqual(['colors.surface', 'colors.text', 'colors.border'])
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    // Each row keeps its place: a click on the first row is the background in either state.
    expect(fields(w, 'colors')).toEqual([
      'hover.colors.surface',
      'hover.colors.text',
      'hover.colors.border',
    ])
    // Effects keeps only what hover can change: no corners, no shadow.
    expect(fields(w, 'effects')).toEqual(['hover.opacity'])
  })

  it('is one state for the whole tab', async () => {
    const w = mountTab(button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    expect(w.find('[data-test="style-state-hover-effects"]').attributes('aria-pressed')).toBe(
      'true',
    )
    await w.find('[data-test="style-state-normal-effects"]').trigger('click')
    expect(fields(w, 'colors')).toEqual(['colors.surface', 'colors.text', 'colors.border'])
  })

  it('writes a hover value bare', async () => {
    const w = mountTab(button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    await w
      .find('[data-test="style-field-hover.colors.surface"] [data-test="token-color.accent"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['hover.colors.surface', null, { type: 'token', value: 'color.accent' }],
    ])
  })

  it('marks a declared hover value with a dot, seen from Normal', () => {
    const plain = mountTab(button)
    expect(plain.find('[data-test="style-state-dot-colors"]').exists()).toBe(false)
    const set = mountTab(button, {
      hover: { colors: { text: { type: 'token', value: 'color.accent' } } },
    })
    expect(set.find('[data-test="style-state-dot-colors"]').exists()).toBe(true)
  })

  it('returns to Normal on a new selection', async () => {
    const w = mountTab(button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    await w.setProps({ block: { id: 'b2', type: 'button', data: {}, settings: { style: {} } } })
    expect(fields(w, 'colors')).toEqual(['colors.surface', 'colors.text', 'colors.border'])
  })

  it('shows no breakpoint chips while Hover is on', async () => {
    const w = mountTab(button)
    const chips = () =>
      w.find('[data-test="style-group-effects"]').findAll('[data-test^="group-breakpoint-"]')
    expect(chips().length).toBeGreaterThan(0)
    await w.find('[data-test="style-state-hover-effects"]').trigger('click')
    expect(chips()).toHaveLength(0)
  })

  it('labels opacity, at rest and on hover, as percentages', async () => {
    const w = mountTab(button)
    const labels = () =>
      w
        .find('[data-test="style-group-effects"]')
        .findAll('[data-test^="choice-"]')
        .map((b) => b.text())
    expect(labels()).toContain('80%')
    await w.find('[data-test="style-state-hover-effects"]').trigger('click')
    expect(labels()).toEqual(['100%', '90%', '80%', '70%', '60%', '50%'])
  })

  it('never gives the hover group a section of its own', () => {
    expect(mountTab(button).find('[data-test="style-group-hover"]').exists()).toBe(false)
  })

  it("shows on a part's tab and in the style-class editor", () => {
    const part = mountTab(partBlockType(socialLink, 'icon'), {}, { context: 'part', part: 'icon' })
    expect(part.find('[data-test="style-state-hover-colors"]').exists()).toBe(true)
    const groups = [...new Set(schema.properties.map((r) => r.group))]
    const klass = mountTab(
      blockType('style_class', { style_capabilities: groups }),
      {},
      {
        context: 'class',
      },
    )
    expect(klass.find('[data-test="style-state-hover-colors"]').exists()).toBe(true)
  })

  it('never shows in a region editor', () => {
    const region = blockType('region_header', {
      style_capabilities: ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop'],
    })
    const w = mountTab(region, {}, { context: 'region' })
    expect(w.find('[data-test^="style-state-"]').exists()).toBe(false)
  })
})
