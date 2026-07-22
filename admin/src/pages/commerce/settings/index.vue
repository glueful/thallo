<script setup lang="ts">
// Task 15a (admin-commerce-area plan, slice 3): Settings shell with only the completed Shipping
// zones tab — Task 15b (Shipping classes) and Task 15c (Tax rates) each add their own tab here once
// their own panel lands, never before (no incomplete tabs, mirrors the nav's "append only once
// green" discipline).
import { computed, ref } from 'vue'
import { useCommerceMeta } from '@/queries/commerceMeta'
import ZonesPanel from './components/ZonesPanel.vue'

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

const tab = ref('zones')
const tabItems = [{ label: 'Shipping zones', value: 'zones' }]
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
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
