<script setup lang="ts">
// Before a class saves (visual builder spec §4.3): saving changes published pages immediately,
// and the editor shows where the class is used — references, active and dormant per property.
import { computed } from 'vue'
import type { StyleClassUsage } from '@/queries/styleClasses'

const props = defineProps<{
  open: boolean
  name: string
  usage: StyleClassUsage | null | undefined
  saving: boolean
}>()
const emit = defineEmits<{ 'update:open': [open: boolean]; confirm: [] }>()

const properties = computed(() =>
  Object.entries(props.usage?.properties ?? {}).map(([path, counts]) => ({ path, ...counts })),
)
</script>

<template>
  <UModal
    :open="open"
    :title="`Save “${name}”?`"
    data-test="style-class-save-dialog"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-3 text-sm">
        <p class="text-default">
          Saving changes published pages immediately. Every block that carries this class resolves
          the new declarations on its next render.
        </p>
        <p v-if="usage === undefined || usage === null" class="text-muted">Counting usage…</p>
        <template v-else>
          <p class="text-default" data-test="style-class-usage-summary">
            Used by <strong>{{ usage.references }}</strong>
            {{ usage.references === 1 ? 'block' : 'blocks' }} —
            {{ usage.by_source.entry_drafts }} in drafts,
            {{ usage.by_source.entry_published }} published, {{ usage.by_source.entry_versions }} in
            retained revisions, {{ usage.by_source.regions }} in regions.
          </p>
          <ul v-if="properties.length" class="space-y-1 text-xs text-muted">
            <li v-for="p in properties" :key="p.path" :data-test="`style-class-usage-${p.path}`">
              <span class="font-mono text-default">{{ p.path }}</span>
              — active on {{ p.active }}, dormant on {{ p.dormant }}
            </li>
          </ul>
        </template>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton variant="ghost" color="neutral" @click="emit('update:open', false)"
          >Cancel</UButton
        >
        <UButton :loading="saving" data-test="style-class-save-confirm" @click="emit('confirm')">
          Save
        </UButton>
      </div>
    </template>
  </UModal>
</template>
