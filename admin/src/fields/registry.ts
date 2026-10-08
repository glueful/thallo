import { defineAsyncComponent, type Component } from 'vue'
import type { FieldDef } from './types'
import StringField from './components/StringField.vue'
import TextField from './components/TextField.vue'
import NumberField from './components/NumberField.vue'
import BooleanField from './components/BooleanField.vue'
import DatetimeField from './components/DatetimeField.vue'
import EnumField from './components/EnumField.vue'
import AssetField from './components/AssetField.vue'
import ReferenceField from './components/ReferenceField.vue'
import JsonField from './components/JsonField.vue'
import BoxField from './components/BoxField.vue'
import TokenField from './components/TokenField.vue'
import OptionsSourceField from './components/OptionsSourceField.vue'

// BlocksField recurses through fieldComponent(); loading it async removes the
// registry ↔ widget static import cycle (nesting amendment §A4).
const BlocksField = defineAsyncComponent(() => import('./components/BlocksField.vue'))

const registry: Record<FieldDef['type'], Component> = {
  string: StringField,
  text: TextField,
  number: NumberField,
  boolean: BooleanField,
  datetime: DatetimeField,
  enum: EnumField,
  asset: AssetField,
  reference: ReferenceField,
  json: JsonField,
  box: BoxField,
  blocks: BlocksField,
  token: TokenField,
}

// Unknown types degrade to a string input rather than crashing the editor.
export function fieldComponent(type: FieldDef['type']): Component {
  return registry[type] ?? StringField
}

/**
 * The control for a field as declared: a string field whose choices come from the server
 * (`options_source`) is the options-source control — single, or a multi-select with `multiple`
 * (product grid spec §5.3) — wherever it renders; every other field is its type's component.
 */
export function componentFor(field: FieldDef): Component {
  return field.type === 'string' && field.optionsSource
    ? OptionsSourceField
    : fieldComponent(field.type)
}
