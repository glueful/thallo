<script setup lang="ts">
import { computed } from 'vue'
import { useUpdateNotice, versionLabel } from '@/composables/useUpdateNotice'
import UpdateCheckNow from '@/components/UpdateCheckNow.vue'

defineProps<{ collapsed?: boolean }>()

// The Thallo version this admin runs, and a link to the update when a newer one is published (Home
// carries the card with the release notes and the upgrade command). The version opens Health's
// "Check for updates", so checking sooner than the daily 04:00 check is a click from anywhere. A
// development checkout is never checked: its version is only a label.
const { status, visible } = useUpdateNotice()
const version = computed(() => versionLabel(status.value))
const checkable = computed(
  () => !!status.value && !status.value.development && status.value.enabled !== false,
)
const update = computed(() =>
  visible.value && status.value?.latest ? `Update available: ${status.value.latest}` : null,
)
</script>

<template>
  <div
    v-if="version && (!collapsed || update)"
    class="flex items-center gap-1.5 px-2.5 text-xs text-muted"
    :class="collapsed ? 'justify-center' : ''"
    data-test="sidebar-version"
  >
    <template v-if="!collapsed">
      <UPopover
        v-if="checkable"
        :content="{ side: 'top', align: 'start' }"
        :ui="{ content: 'w-72 p-3' }"
      >
        <button
          type="button"
          class="truncate rounded hover:text-default focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :aria-label="`${version}: check for updates`"
          data-test="sidebar-version-toggle"
        >
          {{ version }}
        </button>
        <template #content>
          <UpdateCheckNow :status="status" />
        </template>
      </UPopover>
      <span v-else class="truncate">{{ version }}</span>
    </template>
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
