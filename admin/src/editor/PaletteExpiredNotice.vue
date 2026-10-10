<script setup lang="ts">
// The palette changed past what this editor can reconcile (custom palette spec §5.3): its
// replacement history was pruned while the editor stayed open. Undo and redo are off; the document
// can still be saved, and a reload starts the editor over from the stored document.
defineProps<{ show: boolean }>()
const emit = defineEmits<{ reload: [] }>()
</script>

<template>
  <UAlert
    v-if="show"
    class="mb-2"
    color="warning"
    variant="subtle"
    icon="i-lucide-palette"
    title="The palette changed while this editor was open for a long time. Reload to keep editing."
    :actions="[
      { label: 'Reload', color: 'warning', variant: 'solid', onClick: () => emit('reload') },
    ]"
    data-test="palette-expired-notice"
  />
</template>
