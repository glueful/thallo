<script setup lang="ts">
// A collection `text` (TEXT column) field: defaults to the rich editor (UEditor), with a toggle to
// drop to a plain textarea. Either way the stored value is a string (HTML when rich).
import { computed, ref } from 'vue'
import type { CollectionField } from '@/queries/collections'
import RichText from '@/components/RichText.vue'

const props = defineProps<{ field: CollectionField }>()
const model = defineModel<unknown>()

const text = computed<string>({
  get: () => (typeof model.value === 'string' ? model.value : ''),
  set: (v) => {
    model.value = v
  },
})

const plain = ref(false)
</script>

<template>
  <UFormField :label="field.name" :required="field.settings.nullable === false" :name="field.name">
    <template #hint>
      <UButton
        variant="link"
        color="neutral"
        size="xs"
        :label="plain ? 'Rich text' : 'Plain text'"
        @click="
          () => {
            plain = !plain
          }
        "
      />
    </template>
    <UTextarea v-if="plain" v-model="text" :rows="4" class="w-full" />
    <div v-else class="overflow-hidden rounded-md border border-default">
      <RichText v-model="text" :placeholder="field.name" content-class="max-h-96 overflow-y-auto" />
    </div>
  </UFormField>
</template>
