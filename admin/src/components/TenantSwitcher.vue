<script setup lang="ts">
import { computed } from 'vue'
import { useTenantStore } from '@/stores/tenant'

const store = useTenantStore()
const options = computed(() =>
  store.tenants.map((tenant) => ({
    label: tenant.name,
    description: tenant.slug,
    value: tenant.uuid,
  })),
)
const selected = computed({
  get: () => store.selectedUuid ?? undefined,
  set: (value: string | undefined) => {
    if (value) store.select(value)
  },
})
</script>

<template>
  <USelectMenu
    v-if="store.tenants.length > 1"
    v-model="selected"
    :items="options"
    value-key="value"
    icon="i-lucide-building-2"
    aria-label="Current tenant"
    data-testid="tenant-switcher"
    class="min-w-0 flex-1"
    :ui="{ content: 'min-w-56' }"
  >
    <template #item="{ item }">
      <span data-testid="tenant-switcher-item" class="min-w-0">
        <span class="block truncate text-sm text-default">{{ item.label }}</span>
        <span class="block truncate text-xs text-muted">{{ item.description }}</span>
      </span>
    </template>
  </USelectMenu>
</template>
