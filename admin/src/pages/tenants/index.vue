<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, apiErrorDetails } from '@/api/errors'
import { useAllTenants, useTenantMutations } from '@/queries/tenants'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenantTarget } from '@/composables/useTenantTarget'

definePage({ meta: { requiresAuth: true } })

const accessStore = useTenancyAccessStore()
const canManage = computed(() => accessStore.access.manage_platform)
const { data: tenants, status } = useAllTenants(canManage)
const mutations = useTenantMutations()
const { selectThenNavigate } = useTenantTarget()
const createOpen = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const repairUuid = ref<string | null>(null)
const repairCommand = ref<string | null>(null)

async function createTenant(input: { slug: string; name: string }): Promise<void> {
  error.value = null
  fieldErrors.value = {}
  try {
    await mutations.create.mutateAsync(input)
    createOpen.value = false
  } catch (caught) {
    if (caught instanceof ApiError) {
      error.value = caught.message
      fieldErrors.value = caught.fieldErrors
      const details = apiErrorDetails(caught)
      repairUuid.value = typeof details?.tenant_uuid === 'string' ? details.tenant_uuid : null
      repairCommand.value =
        typeof details?.repair_command === 'string' ? details.repair_command : null
    }
  }
}

async function repair(uuid: string): Promise<void> {
  try {
    await mutations.repair.mutateAsync(uuid)
    repairUuid.value = null
    repairCommand.value = null
    error.value = null
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Seed repair failed.'
  }
}
</script>

<template>
  <UDashboardPanel id="tenants">
    <template #header>
      <UDashboardNavbar title="Tenants">
        <template #right>
          <OperatorModeToggle v-if="accessStore.access.access_any" />
          <UButton v-if="canManage" icon="i-lucide-plus" @click="createOpen = true"
            >New tenant</UButton
          >
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <p v-if="error" class="py-4 text-sm text-error" role="alert">{{ error }}</p>
        <div
          v-if="repairUuid"
          class="flex flex-wrap items-center gap-3 border-b border-default py-4"
        >
          <UBadge color="warning" variant="subtle">Provisioning</UBadge>
          <UButton
            icon="i-lucide-refresh-cw"
            :loading="mutations.repair.isLoading.value"
            data-testid="tenant-retry-seed"
            @click="repair(repairUuid)"
          >
            Retry seeding
          </UButton>
          <code v-if="repairCommand" class="text-xs text-muted">{{ repairCommand }}</code>
        </div>

        <div v-if="status === 'pending'" class="grid gap-3 py-6">
          <USkeleton v-for="i in 3" :key="i" class="h-16 w-full" />
        </div>
        <ul v-else class="divide-y divide-default" role="list">
          <li
            v-for="tenant in tenants ?? []"
            :key="tenant.uuid"
            class="flex flex-wrap items-center gap-4 py-4"
            :data-testid="`tenant-row-${tenant.uuid}`"
          >
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ tenant.name }}</p>
              <p class="text-xs text-muted">{{ tenant.slug }}</p>
            </div>
            <UBadge color="neutral" variant="subtle">{{ tenant.status }}</UBadge>
            <div class="flex items-center gap-1">
              <UButton
                icon="i-lucide-globe-2"
                color="neutral"
                variant="ghost"
                @click="selectThenNavigate(tenant.uuid, 'domains')"
              >
                Domains
              </UButton>
              <UButton
                icon="i-lucide-users"
                color="neutral"
                variant="ghost"
                @click="selectThenNavigate(tenant.uuid, 'members')"
              >
                Members
              </UButton>
              <UButton
                v-if="tenant.status === 'active'"
                icon="i-lucide-pause"
                color="neutral"
                variant="ghost"
                aria-label="Suspend tenant"
                @click="mutations.suspend.mutate(tenant.uuid)"
              />
              <UButton
                v-else-if="tenant.status === 'suspended'"
                icon="i-lucide-play"
                color="neutral"
                variant="ghost"
                aria-label="Reactivate tenant"
                @click="mutations.reactivate.mutate(tenant.uuid)"
              />
            </div>
          </li>
        </ul>
      </div>
    </template>
  </UDashboardPanel>

  <TenantCreateModal
    v-model:open="createOpen"
    :busy="mutations.create.isLoading.value"
    :errors="fieldErrors"
    @submit="createTenant"
  />
</template>
