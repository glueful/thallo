<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, apiErrorDetails } from '@/api/errors'
import { useAllTenants, useTenantMutations } from '@/queries/tenants'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import { useTenantTarget } from '@/composables/useTenantTarget'
import { useTenantStore } from '@/stores/tenant'
import type { TenantSummary } from '@/queries/tenants'

definePage({ meta: { requiresAuth: true } })

const accessStore = useTenancyAccessStore()
const canManage = computed(() => accessStore.access.manage_platform)
const { data: tenants, status } = useAllTenants(canManage)
const mutations = useTenantMutations()
const { selectThenNavigate } = useTenantTarget()
const tenantStore = useTenantStore()
const createOpen = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const repairUuid = ref<string | null>(null)
const repairCommand = ref<string | null>(null)
const trashCandidate = ref<TenantSummary | null>(null)
const purgeCandidate = ref<TenantSummary | null>(null)
const trashOpen = computed({
  get: () => trashCandidate.value !== null,
  set: (value: boolean) => {
    if (!value) trashCandidate.value = null
  },
})
const purgeOpen = computed({
  get: () => purgeCandidate.value !== null,
  set: (value: boolean) => {
    if (!value) purgeCandidate.value = null
  },
})

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

async function trash(): Promise<void> {
  if (!trashCandidate.value) return
  try {
    await mutations.delete.mutateAsync(trashCandidate.value.uuid)
    trashCandidate.value = null
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Workspace deletion failed.'
  }
}

async function restore(uuid: string): Promise<void> {
  try {
    await mutations.restore.mutateAsync(uuid)
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Workspace restore failed.'
  }
}

async function purge(input: { uuid: string; confirm: string }): Promise<void> {
  try {
    await mutations.purge.mutateAsync(input)
    purgeCandidate.value = null
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Workspace purge failed.'
  }
}
</script>

<template>
  <UDashboardPanel id="tenants">
    <template #header>
      <UDashboardNavbar title="Workspaces">
        <template #right>
          <OperatorModeToggle v-if="accessStore.access.access_any" />
          <UButton v-if="canManage" icon="i-lucide-plus" @click="() => { createOpen = true }"
            >New workspace</UButton
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
              <p
                v-if="tenant.status === 'deleted' && tenant.purge_after"
                class="text-xs text-muted"
              >
                Restore available until {{ tenant.purge_after }}
              </p>
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
                aria-label="Suspend workspace"
                @click="mutations.suspend.mutate(tenant.uuid)"
              />
              <UButton
                v-else-if="tenant.status === 'suspended'"
                icon="i-lucide-play"
                color="neutral"
                variant="ghost"
                aria-label="Reactivate workspace"
                @click="mutations.reactivate.mutate(tenant.uuid)"
              />
              <UButton
                v-if="tenant.status === 'active' || tenant.status === 'suspended'"
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                aria-label="Move workspace to trash"
                @click="() => { trashCandidate = tenant }"
              />
              <UButton
                v-if="tenant.status === 'deleted'"
                icon="i-lucide-rotate-ccw"
                color="neutral"
                variant="ghost"
                aria-label="Restore workspace"
                @click="restore(tenant.uuid)"
              />
              <UButton
                v-if="tenant.status === 'deleted' && tenantStore.selectedUuid !== tenant.uuid"
                icon="i-lucide-trash-2"
                color="error"
                variant="soft"
                @click="() => { purgeCandidate = tenant }"
              >
                Purge
              </UButton>
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

  <UModal v-model:open="trashOpen" title="Move workspace to trash">
    <template #body>
      <p class="text-sm text-muted">
        This workspace will stop resolving immediately. Its data and hosts remain reserved until it
        is restored or permanently purged.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="() => { trashCandidate = null }">Cancel</UButton>
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          :loading="mutations.delete.isLoading.value"
          @click="trash"
        >
          Move to trash
        </UButton>
      </div>
    </template>
  </UModal>

  <TenantPurgeModal
    v-model:open="purgeOpen"
    :workspace="purgeCandidate"
    :busy="mutations.purge.isLoading.value"
    @confirm="purge"
  />
</template>
