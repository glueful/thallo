<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useCollections, type Collection } from '@/queries/collections'

const props = defineProps<{ selectedName?: string }>()
const emit = defineEmits<{ select: [collection: Collection]; create: [] }>()

const { data, status } = useCollections()

// Collections come back as one list (no server pagination), so search + paging are client-side.
const search = ref('')
const debounced = refDebounced(search, 200)

const filtered = computed<Collection[]>(() => {
  const all = data.value ?? []
  const q = debounced.value.trim().toLowerCase()
  if (!q) return all
  return all.filter((c) => c.name.toLowerCase().includes(q) || c.label.toLowerCase().includes(q))
})

const page = ref(1)
const perPage = 25
const total = computed(() => filtered.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
const paged = computed(() => filtered.value.slice((page.value - 1) * perPage, page.value * perPage))

watch(debounced, () => {
  page.value = 1
})
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">Collections</h2>
      <UButton
        icon="i-lucide-plus"
        class="px-3 rounded-xl"
        size="sm"
        data-test="new-collection"
        aria-label="New collection"
        @click="emit('create')"
      />
    </div>

    <UInput
      v-model="search"
      icon="i-lucide-search"
      placeholder="Search collections…"
      class="w-full"
    />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!filtered.length"
        icon="i-lucide-database"
        title="No collections"
        description="Create your first collection to start storing data."
      />
      <div v-else class="flex flex-col gap-0.5">
        <button
          v-for="c in paged"
          :key="c.name"
          type="button"
          data-test="collection-row"
          class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left hover:bg-elevated"
          :class="c.name === selectedName ? 'bg-elevated' : ''"
          @click="emit('select', c)"
        >
          <span class="min-w-0">
            <span class="block truncate text-sm font-medium text-default">{{ c.name }}</span>
            <span class="block truncate text-xs text-muted">{{ c.label }}</span>
          </span>
          <UBadge color="neutral" variant="subtle" size="xs">{{ c.fields.length }}</UBadge>
        </button>
      </div>
    </div>

    <div
      v-if="total > 0"
      class="flex items-center justify-between gap-2 border-t border-default py-3 text-muted"
    >
      <span class="text-xs font-medium uppercase tracking-wide">{{ total }} collections</span>
      <div class="flex items-center gap-1">
        <span class="text-sm">Page {{ page }} / {{ totalPages }}</span>
        <UButton
          icon="i-lucide-chevron-left"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page <= 1"
          aria-label="Previous page"
          @click="
            () => {
              page--
            }
          "
        />
        <UButton
          icon="i-lucide-chevron-right"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page >= totalPages"
          aria-label="Next page"
          @click="
            () => {
              page++
            }
          "
        />
      </div>
    </div>
  </div>
</template>
