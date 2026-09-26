<script setup lang="ts">
// A saved section's own actions on its library card: rename it in place, or delete it after a
// confirmation. Pages that inserted it keep their copies — a saved section is inserted as a copy.
import { ref } from 'vue'
import { useSavedSections, type Pattern } from '@/queries/patterns'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ pattern: Pattern }>()

const { rename, remove } = useSavedSections()
const { success, error: notifyError } = useNotify()
const id = props.pattern.id ?? ''

const mode = ref<'idle' | 'rename' | 'delete'>('idle')
const name = ref('')
const busy = ref(false)

function startRename(): void {
  name.value = props.pattern.label
  mode.value = 'rename'
}
async function onRename(): Promise<void> {
  const next = name.value.trim()
  if (next === '' || next === props.pattern.label) {
    mode.value = 'idle'
    return
  }
  busy.value = true
  try {
    await rename(id, { name: next })
    mode.value = 'idle'
  } catch (e) {
    notifyError(e, 'Couldn’t rename the section')
  } finally {
    busy.value = false
  }
}
async function onDelete(): Promise<void> {
  busy.value = true
  try {
    await remove(id)
    success('Section deleted', 'Pages that already use it keep their copies.')
  } catch (e) {
    notifyError(e, 'Couldn’t delete the section')
  } finally {
    busy.value = false
    mode.value = 'idle'
  }
}
</script>

<template>
  <div class="border-t border-default px-2 py-1.5 text-xs">
    <form
      v-if="mode === 'rename'"
      class="flex items-center gap-1"
      :data-test="`saved-section-rename-save-${id}`"
      @submit.prevent="onRename"
    >
      <UInput
        v-model="name"
        size="xs"
        class="flex-1"
        autofocus
        :data-test="`saved-section-name-${id}`"
        @keydown.escape.prevent="mode = 'idle'"
      />
      <UButton type="submit" size="xs" :loading="busy">Save</UButton>
    </form>
    <div v-else-if="mode === 'delete'" class="flex items-center justify-between gap-2">
      <span class="text-muted">Delete this section?</span>
      <span class="flex gap-1">
        <UButton size="xs" variant="ghost" color="neutral" @click="mode = 'idle'">Keep</UButton>
        <UButton
          size="xs"
          color="error"
          :loading="busy"
          :data-test="`saved-section-delete-confirm-${id}`"
          @click="onDelete"
        >
          Delete
        </UButton>
      </span>
    </div>
    <div v-else class="flex justify-end gap-1">
      <UButton
        size="xs"
        variant="ghost"
        color="neutral"
        icon="i-lucide-pencil"
        aria-label="Rename this section"
        :data-test="`saved-section-rename-${id}`"
        @click="startRename"
      />
      <UButton
        size="xs"
        variant="ghost"
        color="neutral"
        icon="i-lucide-trash-2"
        aria-label="Delete this section"
        :data-test="`saved-section-delete-${id}`"
        @click="mode = 'delete'"
      />
    </div>
  </div>
</template>
