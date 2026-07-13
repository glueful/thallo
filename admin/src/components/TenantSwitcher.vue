<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import { useTenantStore } from '@/stores/tenant'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'

const props = defineProps<{ collapsed?: boolean }>()

const store = useTenantStore()
const access = useTenancyAccessStore()
const switcherOpen = ref(false)
const currentName = computed(
  () => store.tenants.find((tenant) => tenant.uuid === store.selectedUuid)?.name ?? 'Workspace',
)
const options = computed(() =>
  store.tenants.map((tenant) => ({
    label: tenant.name,
    description: tenant.slug,
    value: tenant.uuid,
  })),
)
const menuItems = computed<DropdownMenuItem[]>(() =>
  store.tenants.map((tenant) => ({
    label: tenant.name,
    type: 'checkbox' as const,
    checked: tenant.uuid === store.selectedUuid,
    onSelect: () => store.select(tenant.uuid),
  })),
)
const selected = computed({
  get: () => store.selectedUuid ?? undefined,
  set: (value: string | undefined) => {
    if (value) store.select(value)
  },
})

function onSwitchRequired(): void {
  store.setOperatorMode(false)
  access.reset()
  void access.refresh()
  switcherOpen.value = true
}

onMounted(() => window.addEventListener('tenant-switch-required', onSwitchRequired))
onBeforeUnmount(() => window.removeEventListener('tenant-switch-required', onSwitchRequired))
</script>

<template>
  <div
    v-if="store.tenants.length > 1"
    :class="props.collapsed ? 'flex justify-center' : 'border-t border-default pt-3'"
    data-testid="tenant-switcher-region"
  >
    <UDropdownMenu
      v-if="props.collapsed"
      v-model:open="switcherOpen"
      :items="menuItems"
      :content="{ side: 'right', align: 'start' }"
      :ui="{ content: 'min-w-56' }"
    >
      <UButton
        icon="i-lucide-briefcase-business"
        color="neutral"
        variant="ghost"
        square
        aria-label="Current workspace"
        :title="currentName"
        data-testid="tenant-switcher"
        :data-switcher-open="switcherOpen ? 'true' : 'false'"
      />
    </UDropdownMenu>

    <template v-else>
      <p class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-dimmed">
        Workspace
      </p>
      <USelectMenu
        v-model="selected"
        v-model:open="switcherOpen"
        :items="options"
        value-key="value"
        icon="i-lucide-briefcase-business"
        trailing-icon="i-lucide-chevrons-up-down"
        aria-label="Current workspace"
        data-testid="tenant-switcher"
        :data-switcher-open="switcherOpen ? 'true' : 'false'"
        class="w-full"
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
  </div>
</template>
