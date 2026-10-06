import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import type { BlockType } from '@/queries/blockTypes'
import type { FontLibraryResult } from '@/queries/fontLibrary'
import { classEditorSchema } from './helpers/classEditorSchema'
import { fontLibrary } from './helpers/fontLibraryFixture'

// Letter spacing, Text transform and Text decoration (settings version 12): in Typography after
// Line height, each set once for every width — saying so beside the group's breakpoints — and each
// writing only itself.
const library = ref<FontLibraryResult | undefined>(fontLibrary())
vi.mock('@/queries/fontLibrary', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/fontLibrary')>()),
  useFontLibrary: () => ({ data: library }),
}))
vi.mock('@/fonts/loadFamilyFaces', () => ({ loadFamilyFaces: vi.fn(() => Promise.resolve()) }))

const { default: StyleTab } = await import('@/editor/inspector/StyleTab.vue')

const heading = {
  uuid: 'heading',
  slug: 'heading',
  label: 'Heading',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: ['typography'],
  style_targets: null,
  flags: null,
  starter_content: null,
} as BlockType

function mountTab(style: Record<string, unknown> = {}) {
  return mount(StyleTab, {
    props: {
      block: { id: 'b1', type: 'heading', data: {}, settings: { style } },
      blockType: heading,
      schema: classEditorSchema(),
      classes: [],
      activeBreakpoint: 'lg',
    } as never,
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

const field = (w: ReturnType<typeof mountTab>, path: string) =>
  w.find(`[data-test="style-field-${path}"]`)

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('Letter spacing, Text transform and Text decoration', () => {
  it('follow Line height in Typography, labelled', () => {
    const fields = mountTab()
      .find('[data-test="style-group-typography"]')
      .findAll('[data-test^="style-field-typography."]')
    expect(fields.map((f) => f.attributes('data-test'))).toEqual([
      'style-field-typography.family',
      'style-field-typography.size',
      'style-field-typography.weight',
      'style-field-typography.line_height',
      'style-field-typography.letter_spacing',
      'style-field-typography.transform',
      'style-field-typography.decoration',
    ])
    expect(fields[4]!.text()).toContain('Letter spacing')
    expect(fields[5]!.text()).toContain('Text transform')
    expect(fields[6]!.text()).toContain('Text decoration')
  })

  it('offer their choices in words', () => {
    const w = mountTab()
    const words = (path: string) =>
      field(w, path)
        .findAll('[data-test^="choice-"]')
        .filter((c) => c.attributes('data-test') !== 'choice-mark')
        .map((c) => c.text())
    expect(words('typography.letter_spacing')).toEqual(['Tight', 'Normal', 'Wide', 'Wider'])
    expect(words('typography.transform')).toEqual(['None', 'Uppercase', 'Lowercase', 'Capitalize'])
    expect(words('typography.decoration')).toEqual(['None', 'Underline', 'Line-through'])
  })

  it('say they apply at all sizes, beside the group’s breakpoints', () => {
    const w = mountTab()
    for (const path of [
      'typography.letter_spacing',
      'typography.transform',
      'typography.decoration',
    ]) {
      expect(field(w, path).find('[data-test="style-all-sizes"]').text()).toBe(
        'Applies at all sizes',
      )
    }
    expect(field(w, 'typography.line_height').find('[data-test="style-all-sizes"]').exists()).toBe(
      false,
    )
  })

  it('write once for every width, and only themselves', async () => {
    const w = mountTab({ typography: { letter_spacing: { type: 'choice', value: 'wide' } } })
    await field(w, 'typography.transform').find('[data-test="choice-uppercase"]').trigger('click')
    expect(w.emitted('set')).toEqual([
      ['typography.transform', null, { type: 'choice', value: 'uppercase' }],
    ])
  })
})
