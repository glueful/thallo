// Which corners the Corners size rounds, and which sides a border is drawn on: any set of them, picked
// as toggles that write one value naming the set (`tl-bl`, `top-bottom`; every one is `all`). The
// corners travel with Corners: a block that offers Corners offers them, and alone they are nothing.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
import { expandDeclaration } from '@/style/capabilities'
import { CORNERS, SIDES, membersOf, sets, valueOf } from '@/style/choiceSets'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

const schema = classEditorSchema()
const card = {
  uuid: 'card',
  slug: 'card',
  label: 'card',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: ['radius', 'border'],
  style_targets: null,
  flags: null,
  starter_content: null,
} as BlockType

function mountTab(style: Record<string, unknown> = {}) {
  return mount(StyleTab, {
    props: {
      block: { id: 'b1', type: 'card', data: {}, settings: { style } },
      blockType: card,
      schema,
      classes: [],
      activeBreakpoint: 'base',
    } as never,
  })
}

const pressed = (field: ReturnType<typeof mountTab>, path: string) =>
  field
    .findAll(`[data-test="style-field-${path}"] [data-test^="set-"]`)
    .filter((b) => b.attributes('aria-pressed') === 'true')
    .map((b) => b.attributes('data-test')!.slice('set-'.length))

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('the contract the admin mirrors', () => {
  it('names any set of sides or corners, and the corners travel with Corners', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    expect(by.get('border.sides')?.choices).toEqual(sets(SIDES))
    expect(by.get('corners')).toMatchObject({
      group: 'radius',
      responsive: false,
      choices: sets(CORNERS),
    })
    expect(sets(CORNERS)).toHaveLength(15)
    expect(expandDeclaration(['radius']).has('corners')).toBe(true)
    expect(expandDeclaration(['corners']).has('corners')).toBe(false)
  })

  it('reads a value as its members and writes members as their one value', () => {
    expect(membersOf(null, CORNERS)).toEqual(CORNERS)
    expect(membersOf('all', SIDES)).toEqual(SIDES)
    expect(membersOf('tl-bl', CORNERS)).toEqual(['tl', 'bl'])
    expect(valueOf(['bl', 'tl'], CORNERS)).toBe('tl-bl')
    expect(valueOf(['left', 'top', 'right', 'bottom'], SIDES)).toBe('all')
    expect(valueOf(['bottom', 'top'], SIDES)).toBe('top-bottom')
  })
})

describe('the Style tab', () => {
  it('draws Rounded corners and Border sides as toggles, every one on until set', () => {
    const w = mountTab()
    const corners = w.find('[data-test="style-field-corners"]')
    expect(corners.text()).toContain('Rounded corners')
    expect(pressed(w, 'corners')).toEqual(['tl', 'tr', 'br', 'bl'])
    expect(pressed(w, 'border.sides')).toEqual(['top', 'right', 'bottom', 'left'])
  })

  it('turning corners or sides off and on writes the set that is left', async () => {
    const w = mountTab({ border: { sides: { type: 'choice', value: 'bottom' } } })
    expect(pressed(w, 'border.sides')).toEqual(['bottom'])
    await w.find('[data-test="style-field-corners"] [data-test="set-tr"]').trigger('click')
    await w.find('[data-test="style-field-border.sides"] [data-test="set-top"]').trigger('click')
    expect(w.emitted('set')).toEqual([
      ['corners', null, { type: 'choice', value: 'tl-br-bl' }],
      ['border.sides', null, { type: 'choice', value: 'top-bottom' }],
    ])
  })

  it('the last one on cannot be turned off: no corners is Corners None, no sides is Border None', async () => {
    const w = mountTab({ corners: { type: 'choice', value: 'tl' } })
    const last = w.find('[data-test="style-field-corners"] [data-test="set-tl"]')
    expect(last.attributes('disabled')).toBeDefined()
    expect(last.attributes('title')).toContain('Corners')
    await last.trigger('click')
    expect(w.emitted('set')).toBeUndefined()
  })
})
