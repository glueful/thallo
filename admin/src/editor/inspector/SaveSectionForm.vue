<script setup lang="ts">
// Save the selected block — with everything inside it — to the Blocks tab's library, as a section
// of this site's own: inserted as a copy wherever it is used next.
import { computed, ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { useSavedSections, type SectionPlace } from '@/queries/patterns'
import { useNotify } from '@/composables/useNotify'
import { ApiError } from '@/api/errors'

// A section saved from the header or footer is offered there again, and only there.
const props = defineProps<{ block: BlockInstance; place?: SectionPlace }>()
const emit = defineEmits<{ close: [] }>()

const { save } = useSavedSections()
const { success, error: notifyError } = useNotify()

const name = ref('')
const category = ref('')
const description = ref('')
const errors = ref<Record<string, string>>({})
const busy = ref(false)
// What the server refused about the block itself — a type the region does not take, a value a
// save would reject — has no field of its own here, so it is said above the buttons.
const LABEL_FIELDS = ['name', 'category', 'description']
const refused = computed(() =>
  Object.entries(errors.value)
    .filter(([path]) => !LABEL_FIELDS.includes(path))
    .map(([, message]) => message),
)

async function onSave(): Promise<void> {
  errors.value = {}
  if (name.value.trim() === '') {
    errors.value = { name: 'Give the section a name.' }
    return
  }
  busy.value = true
  try {
    await save(props.block, {
      name: name.value.trim(),
      category: category.value.trim(),
      description: description.value.trim(),
      ...(props.place ?? { scope: 'page' }),
    })
    success('Saved to Sections', 'Find it in the Blocks tab under Sections.')
    emit('close')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors
    else notifyError(e, 'Couldn’t save the section')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form
    class="space-y-3 rounded-md border border-default p-3"
    data-test="save-section-form"
    @submit.prevent="onSave"
  >
    <p class="text-xs text-muted">
      Saves this block and everything inside it to the Blocks tab’s Sections, to reuse as a copy.
    </p>
    <UFormField label="Name" :error="errors.name">
      <UInput v-model="name" size="sm" class="w-full" autofocus data-test="save-section-name" />
    </UFormField>
    <UFormField
      label="Category"
      description="Where it is listed. Saved, if left empty."
      :error="errors.category"
    >
      <UInput
        v-model="category"
        size="sm"
        class="w-full"
        placeholder="Saved"
        data-test="save-section-category"
      />
    </UFormField>
    <UFormField label="Description" :error="errors.description">
      <UTextarea
        v-model="description"
        size="sm"
        :rows="2"
        class="w-full"
        data-test="save-section-description"
      />
    </UFormField>
    <p
      v-if="refused.length"
      class="text-xs text-error"
      role="alert"
      data-test="save-section-refused"
    >
      This block can’t be saved here: {{ refused.join('; ') }}.
    </p>
    <div class="flex justify-end gap-2">
      <UButton size="sm" variant="ghost" color="neutral" @click="emit('close')">Cancel</UButton>
      <UButton type="submit" size="sm" :loading="busy">Save section</UButton>
    </div>
  </form>
</template>
