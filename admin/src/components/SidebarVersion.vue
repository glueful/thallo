<script setup lang="ts">
import { computed } from 'vue'
import { useUpdateNotice, versionLabel } from '@/composables/useUpdateNotice'

defineProps<{ collapsed?: boolean }>()

// The Thallo version this admin runs, and a link to the update when a newer one is published (Home
// carries the card with the release notes and the upgrade command).
const { status, visible } = useUpdateNotice()
const version = computed(() => versionLabel(status.value))
const update = computed(() =>
  visible.value && status.value?.latest ? `Update available: ${status.value.latest}` : null,
)
</script>

<template>
  <div
    v-if="version && (!collapsed || update)"
    class="flex items-center gap-1.5 px-2.5 pb-1 text-xs text-muted"
    :class="collapsed ? 'justify-center' : ''"
    data-test="sidebar-version"
  >
    <span v-if="!collapsed" class="truncate">{{ version }}</span>
    <template v-if="update">
      <span v-if="!collapsed" aria-hidden="true">·</span>
      <UTooltip :text="update">
        <RouterLink
          to="/"
          class="inline-flex items-center rounded text-primary hover:text-primary/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :aria-label="update"
          data-test="sidebar-version-update"
        >
          <UIcon name="i-lucide-arrow-up-circle" class="size-3.5" />
        </RouterLink>
      </UTooltip>
    </template>
  </div>
</template>
