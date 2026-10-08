<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { Collection, CollectionRow, CollectionField } from '@/queries/collections'
import type { FieldDef } from '@/fields/types'
import { componentFor } from '@/fields/registry'
import CollectionRelationField from './CollectionRelationField.vue'
import CollectionTextField from './CollectionTextField.vue'

const props = defineProps<{
  open: boolean
  collection: Collection | null
  row?: CollectionRow | null
  loading?: boolean
}>()
const emit = defineEmits<{ save: [row: CollectionRow]; 'update:open': [value: boolean] }>()

// Typed value per column (string | number | boolean | string[]); seeded from the row on edit.
const state = reactive<Record<string, unknown>>({})

// Map a collection field type onto the shared content field registry (relation is handled apart).
const TYPE_MAP: Record<string, FieldDef['type']> = {
  'collections.string': 'string',
  'collections.text': 'text',
  'collections.integer': 'number',
  'collections.decimal': 'number',
  'collections.boolean': 'boolean',
  'collections.date': 'datetime',
  'collections.datetime': 'datetime',
  'collections.json': 'json',
  'collections.email': 'string',
  'collections.url': 'string',
  'collections.enum': 'enum',
  'collections.asset': 'string',
}
function toFieldDef(f: CollectionField): FieldDef {
  return {
    name: f.name,
    type: TYPE_MAP[f.type] ?? 'string',
    required: f.settings.nullable === false,
    enum: Array.isArray(f.settings.values) ? (f.settings.values as string[]) : undefined,
    multiple: f.settings.multi === true,
  }
}

const renderFields = computed(() =>
  (props.collection?.fields ?? []).map((f) => ({
    field: f,
    def: toFieldDef(f),
    relation: f.type === 'collections.relation',
  })),
)

// Coerce a stored value into the shape the field input expects.
function coerceIn(f: CollectionField, raw: unknown): unknown {
  const multi = f.settings.multi === true
  if (f.type === 'collections.relation') {
    if (multi) {
      if (typeof raw === 'string') {
        try {
          const a = JSON.parse(raw)
          return Array.isArray(a) ? a : []
        } catch {
          return []
        }
      }
      return Array.isArray(raw) ? raw : []
    }
    return raw == null ? undefined : String(raw)
  }
  switch (f.type) {
    case 'collections.boolean':
      return raw === true || raw === 'true' || raw === 1 || raw === '1'
    case 'collections.integer':
    case 'collections.decimal':
      return raw == null || raw === '' ? undefined : Number(raw)
    default:
      return raw == null ? '' : String(raw)
  }
}

function seed() {
  if (!props.collection) return
  for (const f of props.collection.fields) {
    state[f.name] = coerceIn(f, props.row ? props.row[f.name] : undefined)
  }
}

watch(
  () => [props.open, props.row, props.collection] as const,
  () => {
    if (props.open) seed()
  },
  { immediate: true },
)

const isEdit = () => props.row != null

function onSave() {
  const payload: CollectionRow = {}
  for (const f of props.collection?.fields ?? []) payload[f.name] = state[f.name]
  emit('save', payload)
}
</script>

<template>
  <USlideover
    :open="open"
    :title="isEdit() ? 'Edit row' : 'New row'"
    data-test="row-drawer"
    :ui="{ content: 'sm:max-w-2xl' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-4">
        <template v-for="rf in renderFields" :key="rf.field.name">
          <CollectionRelationField
            v-if="rf.relation"
            v-model="state[rf.field.name]"
            :field="rf.field"
          />
          <CollectionTextField
            v-else-if="rf.field.type === 'collections.text'"
            v-model="state[rf.field.name]"
            :field="rf.field"
          />
          <component
            :is="componentFor(rf.def)"
            v-else
            v-model="state[rf.field.name]"
            :field="rf.def"
          />
        </template>
        <p v-if="renderFields.length === 0" class="text-sm text-muted">
          This collection has no fields yet.
        </p>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="loading"
          @click="emit('update:open', false)"
        />
        <UButton :label="isEdit() ? 'Save' : 'Create'" :loading="loading" @click="onSave" />
      </div>
    </template>
  </USlideover>
</template>
