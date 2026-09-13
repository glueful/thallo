<script setup lang="ts">
import { computed } from 'vue'
import { COLLECTION_FIELD_TYPE_META, type CollectionFieldType } from '@/queries/collections'
import FieldSettingsPanel from './FieldSettingsPanel.vue'

interface FieldModel {
  name: string
  type: CollectionFieldType
  settings: Record<string, unknown>
  open?: boolean
  // System fields (id/uuid/created_at/updated_at) render read-only — expandable to view, never edited.
  system?: boolean
  // Already-persisted custom fields: name/settings read-only (no rename), but can be dropped/reordered.
  existing?: boolean
}

// `draft` shows Cancel/Save field actions (add flow); `draggable` renders the reorder handle.
defineProps<{ draft?: boolean; draggable?: boolean }>()
const field = defineModel<FieldModel>({ required: true })
const emit = defineEmits<{ remove: []; save: []; cancel: [] }>()

const meta = computed(() => COLLECTION_FIELD_TYPE_META[field.value.type])
</script>

<template>
  <div data-test="field-row" class="rounded-md border border-default bg-default">
    <div class="flex items-center gap-2 px-3 py-2">
      <UIcon
        v-if="draggable"
        name="i-lucide-grip-vertical"
        class="field-drag-handle size-4 shrink-0 cursor-grab text-dimmed"
        aria-label="Drag to reorder"
      />
      <UBadge color="neutral" variant="subtle" size="sm" :icon="meta.icon">{{ meta.label }}</UBadge>
      <span
        class="flex-1 truncate text-sm font-medium"
        :class="field.name ? 'text-default' : 'text-muted'"
      >
        {{ field.name || 'Untitled field' }}
      </span>
      <span v-if="field.system" class="flex items-center gap-1 text-xs font-medium text-muted">
        <UIcon name="i-lucide-lock" class="size-3" />
        System
      </span>
      <UButton
        :color="field.open ? 'primary' : 'neutral'"
        icon="i-lucide-settings-2"
        variant="ghost"
        size="xs"
        aria-label="Toggle field settings"
        @click="
          () => {
            field.open = !field.open
          }
        "
      />
      <UButton
        v-if="!draft && !field.system"
        icon="i-lucide-trash-2"
        color="error"
        variant="ghost"
        size="xs"
        aria-label="Remove field"
        @click="emit('remove')"
      />
    </div>

    <div v-if="field.open" class="space-y-4 border-t border-default px-3 py-3">
      <UFormField label="Field name" required>
        <UInput
          v-model="field.name"
          placeholder="e.g. title"
          class="w-full"
          data-test="field-name"
          :disabled="field.system || field.existing"
        />
      </UFormField>

      <FieldSettingsPanel
        v-model:settings="field.settings"
        :type="field.type"
        :disabled="field.system || field.existing"
        :index-editable="field.existing && !field.system"
      />

      <div v-if="draft" class="flex justify-end gap-2">
        <UButton color="neutral" variant="ghost" label="Cancel" @click="emit('cancel')" />
        <UButton label="Save field" data-test="save-field" @click="emit('save')" />
      </div>
    </div>
  </div>
</template>
