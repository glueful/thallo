import { describe, it, expect } from 'vitest'
import { enumItems, fromSelected, toSelected, UNSET } from '@/fields/enumOptions'
import type { FieldDef } from '@/fields/types'

const field = (over: Partial<FieldDef> = {}): FieldDef =>
  ({ name: 'title_align', type: 'enum', enum: ['start', 'center', 'end'], ...over }) as FieldDef

// An optional enum can be put back to "not set": the stored key becomes null, which the
// validator accepts for an optional field and the template reads as its default.
describe('enum options', () => {
  it('an optional enum offers Default first, storing null; a required one offers only its values', () => {
    expect(enumItems(field()).map((i) => i.value)).toEqual([UNSET, 'start', 'center', 'end'])
    expect(enumItems(field())[0]!.label).toBe('Default')
    expect(enumItems(field({ required: true })).map((i) => i.value)).toEqual([
      'start',
      'center',
      'end',
    ])
  })

  it('labels come from enum_labels when given', () => {
    const items = enumItems(field({ enumLabels: { start: 'Left' } }))
    expect(items.find((i) => i.value === 'start')!.label).toBe('Left')
    expect(items.find((i) => i.value === 'end')!.label).toBe('end')
  })

  it('the select shows the sentinel for a null or missing value and writes null back for it', () => {
    expect(toSelected(null)).toBe(UNSET)
    expect(toSelected(undefined)).toBe(UNSET)
    expect(toSelected('center')).toBe('center')
    expect(fromSelected(UNSET)).toBeNull()
    expect(fromSelected('end')).toBe('end')
  })
})
