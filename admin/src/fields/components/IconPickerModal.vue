<script setup lang="ts">
// The icon picker (icon-picker spec §3): a MODAL over the server's vendored
// inventory — search focused on open, client-side filtering, page-numbered
// pagination (80/page; exactly ONE page of tiles in the DOM), pinned current
// selection with Clear, empty state. Emits BARE names; brand: prefixing is
// the calling field's concern.
import { computed, nextTick, ref, watch } from 'vue'
import { useIcons, type IconSetName } from '@/queries/icons'

const props = defineProps<{
  set: IconSetName
  /** The current DISPLAY name (bare, no namespace). */
  modelValue?: string
}>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ select: [name: string]; clear: [] }>()

const PER_PAGE = 80
const { data: inventory, status } = useIcons(() => props.set)

const query = ref('')
const page = ref(1)
const searchEl = ref<{ inputRef?: HTMLInputElement } | null>(null)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  const all = inventory.value?.icons ?? []
  return q === '' ? all : all.filter((n) => n.includes(q))
})
const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)))
const visible = computed(() =>
  filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE),
)
const rangeLabel = computed(() => {
  const total = filtered.value.length
  if (total === 0) return 'No matches'
  const from = (page.value - 1) * PER_PAGE + 1
  const to = Math.min(page.value * PER_PAGE, total)
  return `Showing ${from}–${to} of ${total}`
})

// Search change resets to page 1 (catalog semantics).
watch(query, () => {
  page.value = 1
})
// Fresh state each open; focus the search.
watch(open, (o) => {
  if (!o) return
  query.value = ''
  page.value = 1
  void nextTick(() => searchEl.value?.inputRef?.focus())
})

const brandSvg = (n: string): string | undefined => inventory.value?.svgs[n]

function pick(name: string): void {
  emit('select', name)
  open.value = false
}
function clear(): void {
  emit('clear')
  open.value = false
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="Choose an icon"
    :description="set === 'brands' ? 'Brand icons (Simple Icons).' : 'Lucide icons.'"
    :ui="{ content: 'sm:max-w-2xl' }"
  >
    <template #body>
      <div class="space-y-3">
        <div class="flex items-center gap-2">
          <UInput
            ref="searchEl"
            v-model="query"
            icon="i-lucide-search"
            placeholder="Search icons…"
            class="w-full"
            data-test="icon-picker-search"
          />
        </div>

        <!-- Current selection pinned (spec §3). -->
        <div
          v-if="modelValue"
          class="flex items-center justify-between rounded-md border border-default px-3 py-2"
          data-test="icon-picker-current"
        >
          <span class="flex items-center gap-2 text-sm">
            <span
              v-if="set === 'brands' && brandSvg(modelValue)"
              class="inline-flex size-4 items-center justify-center [&>svg]:h-full [&>svg]:w-full"
              v-html="brandSvg(modelValue)"
            />
            <UIcon v-else-if="set === 'lucide'" :name="`i-lucide-${modelValue}`" class="size-4" />
            <code>{{ modelValue }}</code>
          </span>
          <UButton
            size="xs"
            variant="ghost"
            color="neutral"
            icon="i-lucide-x"
            data-test="icon-picker-clear"
            @click="clear()"
          >
            Clear
          </UButton>
        </div>

        <div v-if="status === 'pending'" class="grid grid-cols-8 gap-2">
          <USkeleton v-for="n in 16" :key="n" class="h-14" />
        </div>
        <UEmpty
          v-else-if="visible.length === 0"
          icon="i-lucide-search-x"
          title="No icons match"
          description="Try a different search."
          data-test="icon-picker-empty"
        />
        <div
          v-else
          class="grid max-h-96 grid-cols-[repeat(auto-fill,minmax(5.5rem,1fr))] gap-1 overflow-y-auto overscroll-contain"
        >
          <button
            v-for="n in visible"
            :key="n"
            type="button"
            class="flex flex-col items-center gap-1 rounded px-1 py-2 text-center hover:bg-elevated focus-visible:bg-elevated"
            :title="n"
            :data-test="`icon-tile-${n}`"
            @click="pick(n)"
          >
            <span
              v-if="set === 'brands' && brandSvg(n)"
              class="inline-flex size-5 items-center justify-center [&>svg]:h-full [&>svg]:w-full"
              v-html="brandSvg(n)"
            />
            <UIcon v-else-if="set === 'lucide'" :name="`i-lucide-${n}`" class="size-5" />
            <span v-else class="inline-flex size-5" />
            <span class="w-full truncate text-[11px] text-muted">{{ n }}</span>
          </button>
        </div>

        <div
          v-if="pageCount > 1"
          class="flex items-center justify-between text-sm text-muted"
          data-test="icon-picker-pages"
        >
          <span data-test="icon-picker-range">{{ rangeLabel }}</span>
          <UPagination
            v-model:page="page"
            :total="filtered.length"
            :items-per-page="PER_PAGE"
            size="sm"
          />
        </div>
        <p v-else class="text-sm text-muted" data-test="icon-picker-range">{{ rangeLabel }}</p>
      </div>
    </template>
  </UModal>
</template>
