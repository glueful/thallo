<script setup lang="ts">
// The `token` field type (visual builder spec §1.7): block semantics that stay in `data` —
// animated text's per-part colours, for one — pick from the platform vocabulary the way a
// style control does, and the template applies them through `token_class()`. The stored value
// is the typed `{type: 'token', value: 'color.accent'}`; unset is absent.
import { computed } from 'vue'
import type { FieldDef } from '../types'
import { useStyleSchema } from '@/queries/styleSchema'
import TokenScaleControl from '@/editor/inspector/controls/TokenScaleControl.vue'

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<{ type: 'token'; value: string } | null | undefined>()

const { data: schema } = useStyleSchema()
const domain = computed(() => props.field.domain ?? '')
const names = computed(() => schema.value?.vocabulary.domains[domain.value] ?? [])
const values = computed(() => schema.value?.vocabulary.values ?? {})
const current = computed(() => (model.value?.type === 'token' ? model.value.value : null))

function pick(token: string): void {
  model.value = { type: 'token', value: token }
}
function clear(): void {
  model.value = undefined
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <div class="space-y-1">
      <TokenScaleControl
        :domain="domain"
        :names="names"
        :values="values"
        :model-value="current"
        @update:model-value="pick"
      />
      <button
        v-if="current !== null"
        type="button"
        class="text-[11px] text-muted hover:text-default"
        :data-test="`token-clear-${field.name}`"
        @click="clear()"
      >
        Clear
      </button>
    </div>
  </UFormField>
</template>
