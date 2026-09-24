<script setup lang="ts">
import { computed } from 'vue'
import { useVersions, useRollback, type VersionRow } from '@/queries/versions'
import { useNotify } from '@/composables/useNotify'

// Sidebar Versions tab. Two things can be done with a published version:
// - Restore to draft: the page puts the version's content into the draft, as one change the
//   author can undo, review and publish. Nothing goes live. The panel only says which version.
// - Make live: the server re-pins the publication to it; the draft is left exactly as it is,
//   and the toast says so.
const props = defineProps<{ uuid: string; locale: string; type: string }>()
const emit = defineEmits<{
  'restore-draft': [version: { version: number | undefined; fields: Record<string, unknown> }]
}>()
const { success, error: notifyError } = useNotify()

const { data: versions, status } = useVersions(
  () => props.uuid,
  () => props.locale,
)
const rollback = useRollback(props.uuid, props.locale, props.type)
const restoring = computed(() => rollback.isLoading.value)

function onRestoreDraft(v: VersionRow) {
  emit('restore-draft', { version: v.version, fields: (v.fields ?? {}) as Record<string, unknown> })
}

async function onMakeLive(v: VersionRow) {
  try {
    await rollback.mutateAsync(v.uuid)
    const name = v.version === undefined ? 'That version' : `Version ${v.version}`
    success(`${name} is live`, 'The live page shows this version again. Your draft is unchanged.')
  } catch (e) {
    notifyError(e, 'Couldn’t make it live')
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
        <div class="flex shrink-0 items-center gap-1.5">
          <UButton
            size="sm"
            variant="subtle"
            :data-test="`version-restore-draft-${v.uuid}`"
            @click="onRestoreDraft(v)"
          >
            Restore to draft
          </UButton>
          <UButton
            size="sm"
            variant="ghost"
            color="neutral"
            :loading="restoring"
            :data-test="`version-make-live-${v.uuid}`"
            @click="onMakeLive(v)"
          >
            Make live
          </UButton>
        </div>
      </li>
    </ul>
  </div>
</template>
