<script setup lang="ts">
import { computed, ref } from 'vue'
import { useCapabilityManagement, type ManagedCapability } from '@/queries/capabilityManagement'
import CapabilityCard from './components/CapabilityCard.vue'
import InstalledPackages from './components/InstalledPackages.vue'

definePage({ meta: { requiresAuth: true } })

// One place for what this install can do. Capabilities: every capability, each switched the way
// it is managed (an activation, its own flow, or a plain switch). Installed: the extension packages
// Composer found and who manages each one. The data behind both is system.access-gated.
const view = ref<'capabilities' | 'installed'>('capabilities')
const viewItems = [
  { label: 'Capabilities', value: 'capabilities', icon: 'i-lucide-toggle-right' },
  { label: 'Installed', value: 'installed', icon: 'i-lucide-package-check' },
]

const { data, status } = useCapabilityManagement()
const capabilities = computed<ManagedCapability[]>(() => data.value ?? [])
</script>

<template>
  <UDashboardPanel id="extensions" :ui="{ body: 'overflow-hidden' }">
    <template #body>
      <div class="flex h-full min-h-0 flex-col p-1">
        <div class="mb-3 shrink-0">
          <h1 class="mb-3 text-lg font-semibold text-highlighted">Extensions</h1>
          <UTabs v-model="view" :items="viewItems" variant="link" :content="false" />
        </div>
        <div class="min-h-0 flex-1">
          <InstalledPackages v-if="view === 'installed'" />
          <div v-else class="h-full min-h-0 overflow-y-auto">
            <div v-if="status === 'pending'" class="flex justify-center py-10">
              <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
            </div>
            <UEmpty
              v-else-if="status === 'error'"
              icon="i-lucide-shield-alert"
              title="Operator access required"
              description="Managing capabilities requires the system.access permission."
            />
            <UEmpty
              v-else-if="!capabilities.length"
              icon="i-lucide-toggle-left"
              title="No capabilities registered"
              description="Installed packs declare their capabilities."
            />
            <ul v-else class="divide-y divide-default">
              <CapabilityCard v-for="cap in capabilities" :key="cap.id" :capability="cap" />
            </ul>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
