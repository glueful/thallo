<script setup lang="ts">
// The Frame tab (type layouts spec §6.4): the page's width and whether the header and footer show,
// for every page of the kind. A page's own settings win where it sets them. It edits a settings
// record and hands back a new one; the page writes it into the document, so every change is an edit
// history records and undo reverts. A default is removed, not stored.
import { computed } from 'vue'

const props = defineProps<{ settings: Record<string, unknown> }>()
const emit = defineEmits<{ 'update:settings': [settings: Record<string, unknown>] }>()

const widthOptions = [
  { label: 'Theme default', value: 'default' },
  { label: 'Contained', value: 'contained' },
  { label: 'Full width', value: 'full' },
]

function set(key: string, value: string | null): void {
  const { [key]: _dropped, ...rest } = props.settings
  emit('update:settings', value === null ? rest : { ...rest, [key]: value })
}

const width = computed<string>({
  get: () => (props.settings.width as string | undefined) ?? 'default',
  set: (v) => set('width', v === 'default' ? null : v),
})
const header = computed<boolean>({
  get: () => props.settings.header !== 'hidden',
  set: (show) => set('header', show ? null : 'hidden'),
})
const footer = computed<boolean>({
  get: () => props.settings.footer !== 'hidden',
  set: (show) => set('footer', show ? null : 'hidden'),
})
</script>

<template>
  <div class="space-y-4 pt-3" data-test="layout-frame">
    <p class="text-sm text-muted">
      The page around the layout. A page’s own settings win where it sets them.
    </p>
    <UFormField label="Width">
      <USelect
        v-model="width"
        :items="widthOptions"
        class="w-full"
        data-test="layout-frame-width"
      />
    </UFormField>
    <USwitch v-model="header" label="Show the header" data-test="layout-frame-header" />
    <USwitch v-model="footer" label="Show the footer" data-test="layout-frame-footer" />
  </div>
</template>
