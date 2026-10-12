// A Navigation block's menu button — the hamburger a phone shows (settings version 21): its icon's
// size sits in an Icon section of its own, one choice for every width, beside its colours, border
// and corners.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'
import { CHOICE_LABELS } from '@/editor/inspector/choiceLabels'
import { propertyDefinition } from '@/style/schema'

const schema = classEditorSchema()
const button = {
  uuid: 'nav',
  slug: 'navigation',
  label: 'Navigation',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: ['colors', 'border', 'radius', 'menu_button'],
  style_targets: null,
  flags: null,
  starter_content: null,
} as BlockType

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe("a menu button's Style section", () => {
  it('offers the icon size as Small, Medium or Large, one for every width', () => {
    const w = mount(StyleTab, {
      props: {
        block: { id: 'n1', type: 'navigation', data: {}, settings: { style: {} } },
        blockType: button,
        schema,
        classes: [],
        activeBreakpoint: 'base',
      } as never,
    })
    expect(
      w
        .find('[data-test="style-group-menu_button"] [data-test="style-field-menu_button.size"]')
        .text(),
    ).toContain('Icon size')
    expect(propertyDefinition('menu_button.size')).toMatchObject({
      group: 'menu_button',
      responsive: false,
      choices: ['sm', 'md', 'lg'],
    })
    expect(CHOICE_LABELS['menu_button.size']).toEqual({ sm: 'Small', md: 'Medium', lg: 'Large' })
  })
})
