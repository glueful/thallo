<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): the ONE workspace billing detail surface, used in TWO
// places (brief, binding): as a slideover opened from a directory row when tenancy is ON, and
// EMBEDDED inline as the tenancy-OFF "This site's plan" panel, bound to the single-store
// `default_tenant_uuid` -- same component, same behavior, just a different chrome (`embedded`
// mirrors `settings/workspaces/index.vue`'s `<PublicOriginSettings embedded />` convention). The
// actual body lives in `WorkspaceDetailPanel.vue`, mounted once regardless of which chrome wraps
// it here.
import WorkspaceDetailPanel from './WorkspaceDetailPanel.vue'

withDefaults(defineProps<{ uuid: string; open?: boolean; embedded?: boolean }>(), {
  open: true,
  embedded: false,
})
const emit = defineEmits<{ 'update:open': [value: boolean] }>()
</script>

<template>
  <div v-if="embedded" data-test="workspace-panel-embedded">
    <WorkspaceDetailPanel :uuid="uuid" />
  </div>
  <USlideover
    v-else
    :open="open"
    title="Workspace billing"
    :ui="{ content: 'sm:max-w-xl' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <WorkspaceDetailPanel :uuid="uuid" />
    </template>
  </USlideover>
</template>
