// Number fields are plain text boxes that take only a number — no stepper — and a block whose
// schema has `width` then `height` (the Image block) shows them on ONE row: Width × Height.
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import BlockFields from '@/fields/components/blocks/BlockFields.vue'
import type { BlockType } from '@/queries/blockTypes'
import { cleanNumberText, parseNumberText } from '@/fields/numberText'

vi.mock('@/queries/navigation', () => ({ useNavMenus: () => ({ data: ref([]) }) }))

const type = (slug: string, schema: BlockType['schema']): BlockType =>
  ({
    uuid: slug,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema,
    style_capabilities: [],
    style_targets: null,
    flags: null,
    starter_content: null,
  }) as BlockType

const image = type('image', [
  { name: 'width', type: 'number', min: 1, max: 4000 },
  { name: 'height', type: 'number', min: 1, max: 4000 },
  { name: 'fill', type: 'boolean' },
] as BlockType['schema'])
const carousel = type('carousel', [{ name: 'interval', type: 'number' }] as BlockType['schema'])

function fields(blockType: BlockType, data: Record<string, unknown> = {}) {
  return mount(BlockFields, {
    props: {
      block: { id: 'b1', type: blockType.slug, data, settings: {} },
      type: blockType,
    } as never,
  })
}

describe('the number text helpers', () => {
  it('keep only what can be part of a number, and read an empty box as not set', () => {
    expect(cleanNumberText('12a.5b')).toBe('12.5')
    expect(cleanNumberText('1.2.3')).toBe('1.23')
    expect(cleanNumberText('-4')).toBe('-4')
    expect(cleanNumberText('480px', { integer: true })).toBe('480')
    expect(cleanNumberText('4.5', { integer: true })).toBe('45')
    expect(parseNumberText('')).toBeNull()
    expect(parseNumberText('-')).toBeNull()
    expect(parseNumberText('12.5')).toBe(12.5)
  })
})

describe('a number field', () => {
  it('is a plain text box, not a stepper, and writes the number it holds', async () => {
    const w = fields(carousel, { interval: 4 })
    expect(w.findComponent({ name: 'UInputNumber' }).exists()).toBe(false)
    const input = w.find('input')
    expect((input.element as HTMLInputElement).value).toBe('4')
    expect(input.attributes('inputmode')).toBe('decimal')
    await input.setValue('6.5s')
    expect(w.emitted('patch')!.slice(-1)[0]).toEqual(['interval', 6.5])
    await input.setValue('')
    expect(w.emitted('patch')!.slice(-1)[0]).toEqual(['interval', null])
  })
})

describe('width and height', () => {
  it('sit on one row, Width × Height, each a whole number of pixels', async () => {
    const w = fields(image, { width: 480 })
    const row = w.find('[data-test="field-size"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('×')
    expect(row.text()).toContain('Width')
    expect(row.text()).toContain('Height')
    const [width, height] = row.findAll('input')
    expect((width!.element as HTMLInputElement).value).toBe('480')
    expect((height!.element as HTMLInputElement).value).toBe('')
    expect(width!.attributes('inputmode')).toBe('numeric')

    await height!.setValue('300px')
    expect(w.emitted('patch')!.slice(-1)[0]).toEqual(['height', 300])
    await width!.setValue('')
    expect(w.emitted('patch')!.slice(-1)[0]).toEqual(['width', null])
    // Not also rendered as two separate fields.
    expect(w.findAll('input').length).toBe(2)
  })
})
