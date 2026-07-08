<script setup lang="ts">
import { computed } from 'vue'
import { useBlockTypes, useBlockTypeMutations, type BlockType } from '@/queries/blockTypes'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data: blockTypes, status } = useBlockTypes()
const { setActive } = useBlockTypeMutations()

// Group the flat list into category sections (like the block picker's own grouping).
// Known categories lead in a curated order; any others follow alphabetically, and
// uncategorized block types collect under "Other" at the end. Order within a group
// is whatever the API returns (already label-sorted).
const CATEGORY_ORDER = ['Layout', 'Content', 'Media', 'Items']
const groupedBlockTypes = computed<{ category: string; items: BlockType[] }[]>(() => {
  const groups = new Map<string, BlockType[]>()
  for (const t of blockTypes.value ?? []) {
    const key = t.category?.trim() || 'Other'
    ;(groups.get(key) ?? groups.set(key, []).get(key)!).push(t)
  }
  const rank = (c: string): number => {
    if (c === 'Other') return CATEGORY_ORDER.length + 1
    const i = CATEGORY_ORDER.indexOf(c)
    return i === -1 ? CATEGORY_ORDER.length : i
  }
  return [...groups.keys()]
    .sort((a, b) => rank(a) - rank(b) || a.localeCompare(b))
    .map((category) => ({ category, items: groups.get(category)! }))
})

async function toggleActive(slug: string, active: boolean) {
  try {
    await setActive.mutateAsync({ slug, active })
    success(active ? 'Block type activated' : 'Block type deactivated',
      active ? 'It appears in the block picker again.' : 'Existing content keeps rendering.')
  } catch (e) {
    notifyError(e, 'Couldn’t update the block type')
  }
}
</script>

<template>
  <UDashboardPanel id="block-types">
    <template #header>
      <UDashboardNavbar title="Block types">
        <template #right>
          <UButton icon="i-lucide-plus" to="/settings/block-types/new" data-test="new-block-type">
            New block type
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <USkeleton v-for="n in 8" :key="n" class="h-32" />
      </div>
      <UEmpty
        v-else-if="!blockTypes?.length"
        icon="i-lucide-blocks"
        title="No block types yet"
        description="Block types are reusable schemas that blocks fields compose into pages."
      />
      <!-- Grouped by category; each card leads with a visual header panel (enlarged
           icon), then label/slug/description. Responsive 1 → 2 → 3 → 4; inactive dim. -->
      <div v-else class="space-y-8 pb-5">
        <section v-for="group in groupedBlockTypes" :key="group.category">
          <div class="mb-4 flex items-baseline gap-2 border-b border-default pb-2">
            <h2 class="text-base font-semibold text-highlighted">{{ group.category }}</h2>
            <span class="font-mono text-xs text-muted">
              {{ group.items.length }} {{ group.items.length === 1 ? 'block' : 'blocks' }}
            </span>
          </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div
              v-for="t in group.items"
              :key="t.uuid"
              class="flex flex-col overflow-hidden rounded-lg border border-default bg-default transition-colors hover:border-accented"
              :class="t.active ? '' : 'opacity-70'"
              :data-test="`block-type-row-${t.slug}`"
            >
              <!-- Visual header: tinted panel with the enlarged block icon; the
                   activate toggle overlays the top-right corner. -->
              <div class="relative flex h-24 items-center justify-center border-b border-default bg-elevated">
                <UIcon :name="t.icon || 'i-lucide-box'" class="size-9 text-muted" />
                <USwitch
                  class="absolute end-2 top-2"
                  :model-value="t.active"
                  :aria-label="`${t.active ? 'Deactivate' : 'Activate'} ${t.label}`"
                  :data-test="`block-type-active-${t.slug}`"
                  @update:model-value="toggleActive(t.slug, $event)"
                />
              </div>

              <div class="flex flex-1 flex-col gap-2 p-3">
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium text-default">{{ t.label }}</p>
                  <p class="truncate font-mono text-xs text-muted">{{ t.slug }}</p>
                </div>
                <p v-if="t.description" class="line-clamp-2 text-xs text-muted">{{ t.description }}</p>
                <div class="mt-auto flex items-center gap-1.5 pt-1">
                  <UBadge v-if="!t.active" size="xs" color="warning" variant="subtle">inactive</UBadge>
                  <UButton
                    class="ms-auto"
                    size="xs"
                    variant="ghost"
                    color="neutral"
                    icon="i-lucide-pencil"
                    :to="`/settings/block-types/${t.slug}`"
                    :aria-label="`Edit ${t.label}`"
                  />
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>
