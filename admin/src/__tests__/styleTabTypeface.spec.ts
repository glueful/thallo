import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import type { BlockType } from '@/queries/blockTypes'
import type { FontLibraryResult } from '@/queries/fontLibrary'
import { classEditorSchema } from './helpers/classEditorSchema'
import { fontLibrary } from './helpers/fontLibraryFixture'
import { StageTypographyKey } from '@/editor/stage/stageTypography'

// Typeface on the Style tab (block typeface spec §4.1–§4.2; plan Task 9): first in Typography, a
// font value when picked, "Use theme default" as its reset, and Weight marking the weights the
// chosen family does not supply.
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
      activeBreakpoint: 'base',
    } as never,
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

const family = (id: string) => ({ typography: { family: { type: 'font', value: id } } })

beforeEach(() => {
  localStorage.clear()
  resetFolds()
  library.value = fontLibrary()
})

describe('Typeface on the Style tab', () => {
  it('leads the Typography group, labelled Typeface', () => {
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
      'style-field-typography.style',
    ])
    expect(fields[0]!.text()).toContain('Typeface')
  })

  it('writes a font value, once for every width', async () => {
    const w = mountTab()
    await w.find('[data-test="typeface-option-serif"]').trigger('click')
    expect(w.emitted('set')).toEqual([
      ['typography.family', null, { type: 'font', value: 'serif' }],
    ])
  })

  it('resets with Use theme default, which says what it does', async () => {
    const w = mountTab(family('serif'))
    const reset = w.find('[data-test="style-field-typography.family"] [data-test="style-reset"]')
    expect(reset.text()).toBe('Use theme default')
    expect(reset.attributes('title')).toBe('Returns this target to its contextual default.')
    await reset.trigger('click')
    expect(w.emitted('set')).toEqual([['typography.family', null, { type: 'reset' }]])
  })

  it('clears a removed family from its notice', async () => {
    const w = mountTab(family('Rm3dE5fG7hJ9'))
    await w.find('[data-test="typeface-clear"]').trigger('click')
    expect(w.emitted('set')).toEqual([['typography.family', null, null]])
  })

  it('marks the weights the chosen family does not supply, still choosable', async () => {
    const weight = (style: Record<string, unknown>) =>
      mountTab(style).find('[data-test="style-field-typography.weight"]')
    const brand = weight(family('Ab3dE5fG7hJ9'))
    expect(brand.find('[data-test="choice-medium"]').text()).toContain('— not in this family')
    expect(brand.find('[data-test="choice-semibold"]').text()).toContain('— not in this family')
    expect(brand.find('[data-test="choice-regular"]').text()).not.toContain('not in this family')
    expect(brand.find('[data-test="choice-bold"]').text()).not.toContain('not in this family')
    expect(brand.find('[data-test="choice-medium"]').attributes('disabled')).toBeUndefined()
    // A variable family covers its range; built-ins and unknown faces are never marked.
    for (const id of ['Vr3dE5fG7hJ9', 'serif', 'Uk3dE5fG7hJ9']) {
      expect(weight(family(id)).text()).not.toContain('not in this family')
    }
  })
})

// What the stage renders (plan Task 10): the tab asks the stage for the target the typography
// group maps to — or for its part — and asks again after every stage render; the control's
// not-supplied notice reads the answer.
describe('Typeface and the stage', () => {
  const links = {
    ...heading,
    uuid: 'links',
    slug: 'links',
    style_targets: { targets: { root: {}, title: {} }, map: { typography: 'title' } },
  } as unknown as BlockType

  function mountWithStage(
    props: Record<string, unknown>,
    answer: { weight: number; style: string } | null = { weight: 600, style: 'normal' },
  ) {
    const renders = ref(0)
    const request = vi.fn(() => Promise.resolve(answer))
    const w = mount(StyleTab, {
      props: {
        block: {
          id: 'b1',
          type: 'links',
          data: {},
          settings: { style: family('Ab3dE5fG7hJ9') },
        },
        blockType: links,
        schema: classEditorSchema(),
        classes: [],
        activeBreakpoint: 'base',
        ...props,
      } as never,
      global: {
        stubs: { RouterLink: { template: '<a><slot /></a>' } },
        provide: { [StageTypographyKey as symbol]: { request, renders } },
      },
    })
    return { w, request, renders }
  }

  it("asks for the target the typography group maps to, and shows what isn't supplied", async () => {
    const { w, request } = mountWithStage({})
    await flushPromises()
    expect(request).toHaveBeenCalledWith('b1', 'title')
    expect(w.find('[data-test="typeface-notice"]').text()).toContain("600 isn't supplied by Brand")
  })

  it('asks again after each stage render', async () => {
    const { request, renders } = mountWithStage({})
    await flushPromises()
    renders.value++
    await flushPromises()
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('asks for a part by its name', async () => {
    const { request } = mountWithStage({ context: 'part', part: 'link' })
    await flushPromises()
    expect(request).toHaveBeenCalledWith('b1', 'link')
  })

  it('never asks for a class or a multi-selection', async () => {
    const { request } = mountWithStage({ context: 'class' })
    await flushPromises()
    expect(request).not.toHaveBeenCalled()
    const block = { id: 'b2', type: 'links', data: {}, settings: {} }
    const multi = mountWithStage({ blocks: [block, block], blockTypes: [links, links] })
    await flushPromises()
    expect(multi.request).not.toHaveBeenCalled()
  })
})
