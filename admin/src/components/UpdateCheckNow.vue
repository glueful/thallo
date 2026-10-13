<script setup lang="ts">
// Health's "Check now": asks Packagist without waiting for the daily 04:00 check. The checked status
// replaces the cached one, so the Home card, the sidebar and the nav badge follow at once. Nothing
// to offer a development checkout or an install with the check turned off: neither is ever checked.
import { computed, ref } from 'vue'
import { useCheckForUpdates, type UpdateStatus } from '@/queries/updates'

const props = defineProps<{ status: UpdateStatus | null | undefined }>()

const { mutateAsync, isLoading } = useCheckForUpdates()
const result = ref<string | null>(null)

const offered = computed(() => !!props.status && !props.status.development && props.status.enabled)

const lastChecked = computed(() => {
  const at = props.status?.checkedAt
  if (!at) return 'Not checked yet'
  const d = new Date(at)
  return Number.isNaN(d.getTime())
    ? 'Not checked yet'
    : `Last checked ${d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`
})

async function checkNow(): Promise<void> {
  result.value = null
  try {
    const status = await mutateAsync()
    if (status.failedAt) result.value = 'Could not reach Packagist. Try again later.'
    else if (status.available && status.latest)
      result.value = `Thallo ${status.latest} is available.`
    else result.value = 'You are on the newest version.'
  } catch (err) {
    result.value = `The check did not run: ${err instanceof Error ? err.message : String(err)}`
  }
}
</script>

<template>
  <div v-if="offered" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
    <UButton
      icon="i-lucide-refresh-cw"
      color="neutral"
      variant="outline"
      size="sm"
      :loading="isLoading"
      data-testid="update-check-now"
      @click="checkNow"
    >
      Check for updates
    </UButton>
    <span class="text-muted" data-testid="update-check-last">{{ lastChecked }}</span>
    <span v-if="result" class="text-default" role="status" data-testid="update-check-result">
      {{ result }}
    </span>
  </div>
</template>
