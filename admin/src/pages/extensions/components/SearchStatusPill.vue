<script setup lang="ts">
import { computed } from 'vue'
import { useSearchStatus } from '@/queries/searchStatus'

// The Search capability's index state on Extensions › Capabilities (search block spec §3.8):
// Ready, Rebuilding, or Needs attention, linking to Settings › Search. Shown only while Search is on.
const { data } = useSearchStatus()
const pill = computed(() => {
  const kinds = (data.value?.kinds ?? []).filter((k) => k.available)
  if (kinds.length === 0) return null
  if (kinds.some((k) => k.status === 'out_of_date' || k.status === 'failed' || k.stalled)) {
    return { label: 'Needs attention', color: 'warning' as const }
  }
  if (kinds.some((k) => k.status === 'pending' || k.status === 'building')) {
    return { label: 'Rebuilding', color: 'info' as const }
  }
  return { label: 'Ready', color: 'success' as const }
})
</script>

<template>
  <RouterLink v-if="pill" to="/settings/search" data-test="search-status-pill">
    <UBadge :label="pill.label" :color="pill.color" variant="outline" size="xs" />
  </RouterLink>
</template>
