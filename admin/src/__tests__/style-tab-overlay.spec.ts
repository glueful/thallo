// A Container's overlay over its background (settings version 22): an Overlay section of its own in
// the Style tab, its colour and strength per screen size, so text over a photo can have it on a
// phone and not on a wide screen.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'
import { CHOICE_LABELS } from '@/editor/inspector/choiceLabels'
import { propertyDefinition } from '@/style/schema'

const schema = classEditorSchema()
const container = {
  uuid: 'ctr',
  slug: 'container',
  label: 'Container',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: ['colors', 'overlay'],
  style_targets: null,
  flags: null,
  starter_content: null,
} as BlockType

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe("a container's Overlay section", () => {
  it('offers the overlay and its opacity, per screen size', () => {
    const w = mount(StyleTab, {
      props: {
        block: { id: 'c1', type: 'container', data: {}, settings: { style: {} } },
        blockType: container,
        schema,
        classes: [],
        activeBreakpoint: 'base',
      } as never,
    })
    const group = w.find('[data-test="style-group-overlay"]')
    expect(group.find('[data-test="style-field-overlay.tone"]').text()).toContain('Overlay')
    expect(group.find('[data-test="style-field-overlay.opacity"]').text()).toContain(
      'Overlay opacity',
    )
    expect(propertyDefinition('overlay.tone')).toMatchObject({
      group: 'overlay',
      responsive: true,
      choices: ['none', 'light', 'dark'],
    })
    expect(propertyDefinition('overlay.opacity')).toMatchObject({
      group: 'overlay',
      responsive: true,
      choices: ['25', '50', '75'],
    })
    expect(CHOICE_LABELS['overlay.tone']).toEqual({ none: 'None', light: 'Light', dark: 'Dark' })
    expect(CHOICE_LABELS['overlay.opacity']).toEqual({ '25': '25%', '50': '50%', '75': '75%' })
  })
})
