<script setup lang="ts">
import { computed } from 'vue'
import { useVersions, useRollback } from '@/queries/versions'
import { useNotify } from '@/composables/useNotify'

// Sidebar Versions tab: the same list/restore as the standalone versions page, in
// panel form. Restoring stays in-tab — useRollback invalidates the draft query and
// the editor re-seeds its fields from the refreshed draft (no navigation needed).
const props = defineProps<{ uuid: string; locale: string; type: string }>()
const { success, error: notifyError } = useNotify()

const { data: versions, status } = useVersions(
  () => props.uuid,
  () => props.locale,
)
const rollback = useRollback(props.uuid, props.locale, props.type)
const restoring = computed(() => rollback.isLoading.value)

async function onRestore(versionUuid: string) {
  try {
    await rollback.mutateAsync(versionUuid)
    success('Version restored', 'The draft now carries this version’s content.')
  } catch (e) {
    notifyError(e, 'Restore failed')
  }
}
</script>

<template>
  <div data-test="versions-panel">
    <div v-if="status === 'pending'" class="space-y-2">
      <USkeleton v-for="n in 4" :key="n" class="h-10" />
    </div>
    <UEmpty
      v-else-if="!versions?.length"
      icon="i-lucide-history"
      title="No versions yet"
      description="Published changes create versions you can roll back to."
    />
    <ul v-else class="divide-y divide-default">
      <li
        v-for="v in versions"
        :key="v.uuid"
        class="flex items-center justify-between py-2.5"
        :data-test="`version-row-${v.uuid}`"
      >
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-default">Version {{ v.version ?? v.uuid }}</p>
          <p class="text-xs text-muted">{{ v.created_at ?? '' }}</p>
        </div>
        <UButton
          size="sm"
          variant="subtle"
          :loading="restoring"
          :data-test="`version-restore-${v.uuid}`"
          @click="onRestore(v.uuid)"
        >
          Restore
        </UButton>
      </li>
    </ul>
  </div>
</template>
