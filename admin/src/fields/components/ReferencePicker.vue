<script setup lang="ts">
// Searchable entry picker for a `reference` field with a known target content type. Server-side
// search via the entries query (debounced); binds the selected entry's UUID.
import { computed, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useEntries } from '@/queries/entries'

const props = defineProps<{ target: string }>()
const model = defineModel<string>()
// Fires alongside the model update with the picked entry's display title, for
// consumers that need more than the uuid (e.g. nav items inheriting the title).
const emit = defineEmits<{ picked: [{ uuid: string; title: string }] }>()

const searchTerm = ref('')
const debounced = refDebounced(searchTerm, 250)

const { data } = useEntries(
  () => props.target,
  () => 1,
  () => 20,
  () => debounced.value || undefined,
)

const items = computed(() =>
  (data.value?.entries ?? []).map((e) => ({ label: e.display_title || e.uuid, value: e.uuid })),
)

watch(model, (uuid) => {
  if (!uuid) return
  const entry = (data.value?.entries ?? []).find((e) => e.uuid === uuid)
  if (entry) emit('picked', { uuid, title: entry.display_title || '' })
})
</script>

<template>
  <USelectMenu
    v-model="model"
    :items="items"
    value-key="value"
    placeholder="Choose an entry…"
    class="w-full"
    @update:search-term="searchTerm = $event"
  />
</template>
