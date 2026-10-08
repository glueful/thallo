import { describe, it, expect } from 'vitest'
import { componentFor, fieldComponent } from '@/fields/registry'
import type { FieldDef } from '@/fields/types'
import StringField from '@/fields/components/StringField.vue'
import TextField from '@/fields/components/TextField.vue'
import NumberField from '@/fields/components/NumberField.vue'
import BooleanField from '@/fields/components/BooleanField.vue'
import DatetimeField from '@/fields/components/DatetimeField.vue'
import EnumField from '@/fields/components/EnumField.vue'
import AssetField from '@/fields/components/AssetField.vue'
import ReferenceField from '@/fields/components/ReferenceField.vue'
import JsonField from '@/fields/components/JsonField.vue'
import OptionsSourceField from '@/fields/components/OptionsSourceField.vue'

describe('field registry', () => {
  it('maps every field type to its component', () => {
    const cases: Array<[FieldDef['type'], unknown]> = [
      ['string', StringField],
      ['text', TextField],
      ['number', NumberField],
      ['boolean', BooleanField],
      ['datetime', DatetimeField],
      ['enum', EnumField],
      ['asset', AssetField],
      ['reference', ReferenceField],
      ['json', JsonField],
    ]
    for (const [type, component] of cases) {
      expect(fieldComponent(type)).toBe(component)
    }
  })

  it('degrades an unknown field type to the string component', () => {
    expect(fieldComponent('weird' as FieldDef['type'])).toBe(StringField)
  })

  // Wherever a field renders (an entry, a collection row, a block): a string field whose choices
  // come from the server is the options-source control, single or multiple (product grid spec §5.3).
  it('gives a string field with an options source its choices control, single or multiple', () => {
    const source = {
      name: 'cats',
      type: 'string' as const,
      optionsSource: 'thallo-commerce.categories',
    }
    expect(componentFor(source)).toBe(OptionsSourceField)
    expect(componentFor({ ...source, multiple: true })).toBe(OptionsSourceField)
    expect(componentFor({ name: 'title', type: 'string' })).toBe(StringField)
    expect(componentFor({ name: 'body', type: 'text', optionsSource: 'x' })).toBe(TextField)
  })
})
