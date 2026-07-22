<script setup lang="ts">
// Task 15a (admin-commerce-area plan, slice 3): Settings shell — started with only the completed
// Shipping zones tab. Task 15b adds the Shipping classes tab below; Task 15c (Tax rates) adds its
// own tab here once its own panel lands, never before (no incomplete tabs, mirrors the nav's
// "append only once green" discipline).
import { computed, ref } from 'vue'
import { useCommerceMeta } from '@/queries/commerceMeta'
import ZonesPanel from './components/ZonesPanel.vue'
import ClassesPanel from './components/ClassesPanel.vue'

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

const tab = ref('zones')
const tabItems = [
  { label: 'Shipping zones', value: 'zones' },
  { label: 'Shipping classes', value: 'classes' },
]
</script>

<template>
  <UDashboardPanel id="commerce-settings">
    <template #header>
      <UDashboardNavbar title="Settings" />
    </template>

    <template #body>
      <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" data-test="settings-tabs" />

      <template v-if="tab === 'zones'">
        <ZonesPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'classes'">
        <ClassesPanel :can-manage="canManage" />
      </template>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
