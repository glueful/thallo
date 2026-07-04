<script setup lang="ts">
import { useBlockTypes, useBlockTypeMutations } from '@/queries/blockTypes'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data: blockTypes, status } = useBlockTypes()
const { setActive } = useBlockTypeMutations()

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
      <!-- Responsive card grid: 1 → 2 → 3 → 4 columns; inactive cards dim. -->
      <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 pb-5">
        <div
          v-for="t in blockTypes"
          :key="t.uuid"
          class="flex flex-col gap-2 rounded-lg border border-default bg-default p-3 transition-colors hover:border-accented"
          :class="t.active ? '' : 'opacity-70'"
          :data-test="`block-type-row-${t.slug}`"
        >
          <div class="flex items-start justify-between gap-2">
            <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-elevated">
              <UIcon :name="t.icon || 'i-lucide-box'" class="size-4 text-muted" />
            </span>
            <USwitch
              :model-value="t.active"
              :aria-label="`${t.active ? 'Deactivate' : 'Activate'} ${t.label}`"
              :data-test="`block-type-active-${t.slug}`"
              @update:model-value="toggleActive(t.slug, $event)"
            />
          </div>
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-default">{{ t.label }}</p>
            <p class="truncate font-mono text-xs text-muted">{{ t.slug }}</p>
          </div>
          <p v-if="t.description" class="line-clamp-2 text-xs text-muted">{{ t.description }}</p>
          <div class="mt-auto flex items-center gap-1.5 pt-1">
            <UBadge v-if="t.category" size="xs" color="neutral" variant="subtle">
              {{ t.category }}
            </UBadge>
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
    </template>
  </UDashboardPanel>
</template>
