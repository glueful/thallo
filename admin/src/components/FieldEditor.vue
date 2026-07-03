<script setup lang="ts">
import type { ComponentPublicInstance } from 'vue'
import type { FieldDef } from '@/fields/types'
import { fieldComponent } from '@/fields/registry'

defineProps<{ schema: FieldDef[] }>()
// The draft's field values, keyed by field name. We reassign (not mutate in place) on each field
// change so defineModel emits update:modelValue with the full record.
const model = defineModel<Record<string, unknown>>({ required: true })

// ── Canvas selection plumbing (visual-canvas spec §5) ──────────────────────────
// Track rendered BlocksField instances by field name so a stage selection can be
// routed to the field that owns the block. Ref-cleanup pin (review P2): Vue calls
// a function ref with NULL on unmount and a NEW instance on swap — DELETE on null
// so a schema change never leaves a stale component in the map.
interface BlocksFieldExposed {
  hasBlock: (id: string) => boolean
  selectBlock: (id: string) => void
}

const blocksFields = new Map<string, BlocksFieldExposed>()

function trackField(name: string, type: string, el: Element | ComponentPublicInstance | null): void {
  if (type !== 'blocks') return
  if (el === null) {
    blocksFields.delete(name)
    return
  }
  blocksFields.set(name, el as unknown as BlocksFieldExposed)
}

defineExpose({
  /**
   * Find the blocks field containing `id` and drive its selectBlock. Returns
   * true when found — entry-wide block-id uniqueness makes the bare id
   * unambiguous across fields (visual-canvas spec §5). Iterates only LIVE refs.
   */
  selectBlockById(id: string): boolean {
    for (const field of blocksFields.values()) {
      if (field.hasBlock?.(id)) {
        field.selectBlock(id)
        return true
      }
    }
    return false
  },
})
</script>

<template>
  <div class="space-y-4">
    <component
      :is="fieldComponent(field.type)"
      v-for="field in schema"
      :key="field.name"
      :ref="(el: Element | ComponentPublicInstance | null) => trackField(field.name, field.type, el)"
      :model-value="model[field.name]"
      :field="field"
      @update:model-value="(v: unknown) => (model = { ...model, [field.name]: v })"
    />
  </div>
</template>
