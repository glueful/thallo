<script setup lang="ts">
// Task 15a (admin-commerce-area plan, slice 3): Settings shell — started with only the completed
// Shipping zones tab. Task 15b added the Shipping classes tab; Task 15c adds the Tax rates tab
// below, completing phase P5 (no incomplete tabs, mirrors the nav's "append only once green"
// discipline).
import { computed, ref } from 'vue'
import { useCommerceMeta } from '@/queries/commerceMeta'
import StorePanel from './components/StorePanel.vue'
import ZonesPanel from './components/ZonesPanel.vue'
import ClassesPanel from './components/ClassesPanel.vue'
import TaxRatesPanel from './components/TaxRatesPanel.vue'
import PaymentsPanel from './components/PaymentsPanel.vue'

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

// Store leads (store-settings spec §3.5): the runtime store configuration is the tab merchants
// reach for first; the shipping/tax CRUD tabs follow.
const tab = ref('store')
const tabItems = [
  { label: 'Store', value: 'store' },
  { label: 'Payments', value: 'payments' },
  { label: 'Shipping zones', value: 'zones' },
  { label: 'Shipping classes', value: 'classes' },
  { label: 'Tax rates', value: 'rates' },
]
</script>

<template>
  <UDashboardPanel id="commerce-settings">
    <template #header>
      <UDashboardNavbar title="Settings" />
    </template>

    <template #body>
      <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" data-test="settings-tabs" />

      <template v-if="tab === 'store'">
        <StorePanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'payments'">
        <PaymentsPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'zones'">
        <ZonesPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'classes'">
        <ClassesPanel :can-manage="canManage" />
      </template>
      <template v-else-if="tab === 'rates'">
        <TaxRatesPanel :can-manage="canManage" />
      </template>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
