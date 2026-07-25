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

// Status → semantic badge color, so state reads at a glance instead of a uniform grey.
function statusColor(status: string): 'success' | 'warning' | 'info' | 'neutral' {
  switch (status) {
    case 'active':
      return 'success'
    case 'suspended':
      return 'warning'
    case 'provisioning':
      return 'info'
    default:
      return 'neutral' // deleted / unknown
  }
}

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
          <UButton v-if="canManage" icon="i-lucide-plus" @click="() => { createOpen = true }"
            >New workspace</UButton
          >
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-6 sm:px-6">
        <div v-if="accessStore.access.access_any" class="flex justify-end">
          <OperatorModeToggle />
        </div>
        <p v-if="error" class="text-sm text-error" role="alert">{{ error }}</p>
        <div
          v-if="repairUuid"
          class="flex flex-wrap items-center gap-3 rounded-lg border border-warning/30 bg-warning/5 px-4 py-3"
        >
          <UBadge color="warning" variant="subtle">Provisioning</UBadge>
          <span class="text-sm text-muted">Seeding didn’t finish — retry to complete setup.</span>
          <UButton
            icon="i-lucide-refresh-cw"
            size="sm"
            :loading="mutations.repair.isLoading.value"
            data-testid="tenant-retry-seed"
            @click="repair(repairUuid)"
          >
            Retry seeding
          </UButton>
          <code v-if="repairCommand" class="text-xs text-muted">{{ repairCommand }}</code>
        </div>

        <!-- Loading -->
        <div v-if="status === 'pending'" class="grid gap-3">
          <USkeleton v-for="i in 3" :key="i" class="h-16 w-full rounded-lg" />
        </div>

        <!-- Empty -->
        <div
          v-else-if="(tenants ?? []).length === 0"
          class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-default px-6 py-16 text-center"
        >
          <div class="flex size-11 items-center justify-center rounded-full bg-elevated">
            <UIcon name="i-lucide-building-2" class="size-5 text-dimmed" />
          </div>
          <div class="space-y-1">
            <p class="text-sm font-medium text-default">No workspaces yet</p>
            <p class="text-sm text-muted">Create your first workspace to get started.</p>
          </div>
          <UButton
            v-if="canManage"
            icon="i-lucide-plus"
            size="sm"
            @click="() => { createOpen = true }"
          >
            New workspace
          </UButton>
        </div>

        <!-- List -->
        <div v-else class="overflow-hidden rounded-lg border border-default">
          <ul class="divide-y divide-default" role="list">
            <li
              v-for="tenant in tenants ?? []"
              :key="tenant.uuid"
              class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3.5 transition-colors hover:bg-elevated/40"
              :data-testid="`tenant-row-${tenant.uuid}`"
            >
              <UAvatar
                :text="(tenant.name || tenant.slug || '?').charAt(0).toUpperCase()"
                size="md"
                class="shrink-0"
              />
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-default">{{ tenant.name }}</p>
                <p class="truncate font-mono text-xs text-muted">{{ tenant.slug }}</p>
                <p
                  v-if="tenant.status === 'deleted' && tenant.purge_after"
                  class="mt-0.5 text-xs text-dimmed"
                >
                  Restore available until {{ tenant.purge_after }}
                </p>
                <p
                  v-if="tenant.status === 'purging' && tenant.purge_stalled"
                  class="mt-0.5 text-xs text-warning"
                  data-test="workspace-purge-stalled"
                >
                  Purge is waiting for a queue worker — run
                  <code>php glueful queue:work</code> (or
                  <code>thallo:tenancy:purge:recover</code> if it failed).
                </p>
              </div>

              <UBadge
                :label="tenant.status"
                :color="statusColor(tenant.status)"
                variant="subtle"
                size="xs"
                class="shrink-0 capitalize"
              />

              <div class="flex items-center gap-1">
                <UButton
                  icon="i-lucide-globe-2"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  @click="selectThenNavigate(tenant.uuid, 'domains')"
                >
                  Domains
                </UButton>
                <UButton
                  icon="i-lucide-users"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  @click="selectThenNavigate(tenant.uuid, 'members')"
                >
                  Members
                </UButton>

                <div
                  v-if="['active', 'suspended', 'deleted'].includes(tenant.status)"
                  class="mx-1 h-5 w-px bg-default"
                  aria-hidden="true"
                />

                <UTooltip v-if="tenant.status === 'active'" text="Suspend workspace">
                  <UButton
                    icon="i-lucide-pause"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    aria-label="Suspend workspace"
                    @click="mutations.suspend.mutate(tenant.uuid)"
                  />
                </UTooltip>
                <UTooltip v-else-if="tenant.status === 'suspended'" text="Reactivate workspace">
                  <UButton
                    icon="i-lucide-play"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    aria-label="Reactivate workspace"
                    @click="mutations.reactivate.mutate(tenant.uuid)"
                  />
                </UTooltip>
                <UTooltip
                  v-if="tenant.status === 'active' || tenant.status === 'suspended'"
                  text="Move to trash"
                >
                  <UButton
                    icon="i-lucide-trash-2"
                    color="error"
                    variant="ghost"
                    size="sm"
                    aria-label="Move workspace to trash"
                    @click="() => { trashCandidate = tenant }"
                  />
                </UTooltip>
                <UTooltip v-if="tenant.status === 'deleted'" text="Restore workspace">
                  <UButton
                    icon="i-lucide-rotate-ccw"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    aria-label="Restore workspace"
                    @click="restore(tenant.uuid)"
                  />
                </UTooltip>
                <UButton
                  v-if="tenant.status === 'deleted' && tenantStore.selectedUuid !== tenant.uuid"
                  icon="i-lucide-trash-2"
                  color="error"
                  variant="soft"
                  size="sm"
                  @click="() => { purgeCandidate = tenant }"
                >
                  Purge
                </UButton>
              </div>
            </li>
          </ul>
        </div>
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
