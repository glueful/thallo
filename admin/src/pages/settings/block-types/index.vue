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
      <div v-if="status === 'pending'" class="space-y-2">
        <USkeleton v-for="n in 4" :key="n" class="h-12" />
      </div>
      <UEmpty
        v-else-if="!blockTypes?.length"
        icon="i-lucide-blocks"
        title="No block types yet"
        description="Block types are reusable schemas that blocks fields compose into pages."
      />
      <ul v-else class="divide-y divide-default rounded-lg border border-default">
        <li
          v-for="t in blockTypes"
          :key="t.uuid"
          class="flex items-center gap-3 p-3"
          :data-test="`block-type-row-${t.slug}`"
        >
          <UIcon :name="t.icon || 'i-lucide-box'" class="shrink-0" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-default">
              {{ t.label }}
              <span class="ml-1 font-mono text-xs text-muted">{{ t.slug }}</span>
            </p>
            <p v-if="t.description" class="truncate text-xs text-muted">{{ t.description }}</p>
          </div>
          <UBadge v-if="t.category" size="xs" color="neutral" variant="subtle">
            {{ t.category }}
          </UBadge>
          <UBadge v-if="!t.active" size="xs" color="warning" variant="subtle">inactive</UBadge>
          <USwitch
            :model-value="t.active"
            :aria-label="`${t.active ? 'Deactivate' : 'Activate'} ${t.label}`"
            :data-test="`block-type-active-${t.slug}`"
            @update:model-value="toggleActive(t.slug, $event)"
          />
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-pencil"
            :to="`/settings/block-types/${t.slug}`"
            :aria-label="`Edit ${t.label}`"
          />
        </li>
      </ul>
    </template>
  </UDashboardPanel>
</template>
