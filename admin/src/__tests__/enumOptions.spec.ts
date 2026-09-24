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

  it('a list that names its own default offers ONE Default, and it means not set', () => {
    // The Code block's size is ['default', 'compact']: the synthetic Default beside the listed
    // one showed "Default" and "default". The listed one is the Default — shown once, labelled
    // "Default", and stored as null, exactly like the synthetic one.
    const size = field({ enum: ['default', 'compact'] })
    expect(enumItems(size)).toEqual([
      { label: 'Default', value: 'default' },
      { label: 'compact', value: 'compact' },
    ])
    expect(toSelected(null, size)).toBe('default')
    expect(toSelected(undefined, size)).toBe('default')
    expect(fromSelected('default', size)).toBeNull()
    expect(fromSelected('compact', size)).toBe('compact')
    // A required list is left as it is: its default is a value an author picks.
    const required = field({ enum: ['default', 'compact'], required: true })
    expect(enumItems(required).map((i) => i.value)).toEqual(['default', 'compact'])
    expect(fromSelected('default', required)).toBe('default')
  })
})
