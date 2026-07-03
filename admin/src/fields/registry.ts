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
  blocks: BlocksField,
}

// Unknown types degrade to a string input rather than crashing the editor.
export function fieldComponent(type: FieldDef['type']): Component {
  return registry[type] ?? StringField
}
