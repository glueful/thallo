<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useVersions, useRollback } from '@/queries/versions'
import { runtimeConfig } from '@/runtime/config'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const { success, error: notifyError } = useNotify()
const type = computed(() => String(route.params.type))
const uuid = computed(() => String(route.params.uuid))
const locale = computed(() => String(route.query.locale ?? runtimeConfig.defaultLocale))

const { data: versions, status } = useVersions(uuid, locale)
const rollback = useRollback(uuid.value, locale.value, type.value)

// Make live re-pins the publication; the draft is left as it is. To bring a version back into the
// draft, use Restore to draft on the editor's Versions tab.
async function onRollback(versionUuid: string, version: number | undefined) {
  try {
    await rollback.mutateAsync(versionUuid)
    const name = version === undefined ? 'That version' : `Version ${version}`
    success(`${name} is live`, 'The live page shows this version again. Your draft is unchanged.')
    router.push(`/content/${type.value}/${uuid.value}?locale=${locale.value}`)
  } catch (e) {
    notifyError(e, 'Couldn’t make it live')
  }
}
</script>

<template>
  <UDashboardPanel id="entry-versions">
    <template #header>
      <UDashboardNavbar :title="`Versions · ${locale}`">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/content/${type}/${uuid}?locale=${locale}`"
            aria-label="Back to editor"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="space-y-2">
        <USkeleton v-for="n in 4" :key="n" class="h-10" />
      </div>
      <UEmpty
        v-else-if="!versions?.length"
        icon="i-lucide-history"
        title="No versions yet"
        description="Published changes create versions you can roll back to."
      />
      <ul v-else class="divide-y divide-default rounded-lg border border-default">
        <li v-for="v in versions" :key="v.uuid" class="flex items-center justify-between p-3">
          <div>
            <p class="text-sm font-medium text-default">Version {{ v.version ?? v.uuid }}</p>
            <p class="text-xs text-muted">{{ v.created_at ?? '' }}</p>
          </div>
          <UButton
            size="sm"
            variant="subtle"
            :loading="rollback.isLoading.value"
            :data-test="`version-make-live-${v.uuid}`"
            @click="onRollback(v.uuid, v.version)"
          >
            Make live
          </UButton>
        </li>
      </ul>
    </template>
  </UDashboardPanel>
</template>
