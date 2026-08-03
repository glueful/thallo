<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): the workspace billing admin page. Meta-first fetch
// (binding behavior rule) decides between four renders: an engine-state notice, the tenancy-ON
// paginated workspace directory, the tenancy-OFF "This site's plan" panel (the SAME
// `WorkspaceDrawer`, embedded, bound to the non-null `default_tenant_uuid`), or -- when tenancy
// is off AND no default workspace is established yet -- a repair notice that issues NO workspace
// request at all: `WorkspaceDrawer` (and the `useWorkspace()` query inside it) is only ever
// mounted once a concrete uuid is known, so the null-default branch below simply never renders it.
import { computed, ref } from 'vue'
import { useSubscriptionsMeta, useWorkspaces, type WorkspaceRow } from '@/queries/subscriptionsBilling'
import TablePagination from '@/components/TablePagination.vue'
import EngineStateNotice from '../components/EngineStateNotice.vue'
import WorkspaceDrawer from '../components/WorkspaceDrawer.vue'

definePage({ meta: { requiresAuth: true, requiresCapability: 'thallo.subscriptions' } })

// `metaStatus === 'error'` is its OWN branch (final-wave fix E): without it a failed meta probe fell
// through to the `!tenancyEnabled` branch (meta is undefined ⇒ tenancy reads as off ⇒ default uuid
// reads as null) and rendered the "no default workspace is established yet" repair notice — a
// actively misleading diagnosis of a transport failure. Retry via the query's own refetch.
const { data: meta, status: metaStatus, refetch: refetchMeta } = useSubscriptionsMeta()
const engineReady = computed(() => meta.value?.engine === 'ready')
const tenancyEnabled = computed(() => meta.value?.tenancy_enabled ?? false)
const defaultUuid = computed(() => meta.value?.default_tenant_uuid ?? null)

const page = ref(1)
const perPage = ref(20)
const directoryEnabled = computed(() => engineReady.value && tenancyEnabled.value)

const { data: workspaces, status: workspacesStatus } = useWorkspaces(
  () => ({ page: page.value, perPage: perPage.value }),
  directoryEnabled,
)
const rows = computed<WorkspaceRow[]>(() => workspaces.value?.rows ?? [])

const selectedUuid = ref<string | null>(null)
function openWorkspace(row: WorkspaceRow) {
  selectedUuid.value = row.tenant.uuid
}
</script>

<template>
  <UDashboardPanel id="subscriptions-billing">
    <template #header>
      <UDashboardNavbar title="Billing" />
    </template>

    <template #body>
      <div v-if="metaStatus === 'pending'" class="p-6" data-test="billing-meta-loading">
        <USkeleton class="h-24 w-full" />
      </div>
      <div v-else-if="metaStatus === 'error'" class="p-6" data-test="billing-meta-error">
        <UAlert
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Couldn't load subscriptions status"
          description="The subscriptions status check failed, so workspace billing can't be shown. Check your connection and try again."
        />
        <UButton
          class="mt-4"
          color="neutral"
          variant="subtle"
          icon="i-lucide-refresh-cw"
          label="Retry"
          data-test="billing-meta-retry"
          @click="refetchMeta()"
        />
      </div>
      <EngineStateNotice
        v-else-if="meta && meta.engine !== 'ready'"
        :state="meta.engine === 'schema_not_ready' ? 'schema_not_ready' : 'engine_disabled'"
      />
      <div v-else-if="!tenancyEnabled" class="p-6">
        <div v-if="defaultUuid === null" data-test="billing-default-missing">
          <UAlert
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="No default workspace is established yet"
            description="This site runs in single-workspace mode, but no default workspace has been established. Billing can't be managed until one exists."
          />
        </div>
        <div v-else data-test="billing-single-panel">
          <p class="mb-4 text-sm text-muted">This site's plan</p>
          <WorkspaceDrawer :uuid="defaultUuid" embedded />
        </div>
      </div>
      <div v-else class="p-6">
        <div v-if="workspacesStatus === 'pending'" data-test="workspaces-loading">
          <USkeleton class="h-24 w-full" />
        </div>
        <div v-else-if="workspacesStatus === 'error'" data-test="workspaces-error">
          <UAlert color="error" variant="subtle" title="Couldn't load workspaces." />
        </div>
        <div v-else-if="rows.length === 0" data-test="workspaces-empty" class="text-sm text-muted">
          No workspaces yet.
        </div>
        <table v-else class="w-full text-left text-sm" data-test="workspaces-table">
          <thead>
            <tr class="border-b border-default text-muted">
              <th class="py-2">Workspace</th>
              <th class="py-2">Workspace status</th>
              <th class="py-2">Plan</th>
              <th class="py-2">Subscription</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.tenant.uuid"
              class="cursor-pointer border-b border-default hover:bg-elevated"
              data-test="workspace-row"
              @click="openWorkspace(row)"
            >
              <td class="py-2">
                <p class="font-medium" data-test="workspace-row-name">{{ row.tenant.name }}</p>
                <p class="text-xs text-muted">{{ row.tenant.slug }}</p>
              </td>
              <!-- The tenant's OWN lifecycle status (final-wave fix C): the directory lists every
                   live workspace, including `provisioning`/`suspended` ones whose billing writes the
                   API refuses with 409 `workspace_not_active`, so the operator must be able to see
                   that state here rather than discovering it on a failed save. -->
              <td class="py-2" data-test="workspace-row-tenant-status">
                <UBadge
                  size="sm"
                  variant="subtle"
                  :color="row.tenant.status === 'active' ? 'neutral' : 'warning'"
                  :label="row.tenant.status"
                />
              </td>
              <td class="py-2" data-test="workspace-row-plan">
                {{ row.subscription?.plan_display_name ?? '—' }}
              </td>
              <td class="py-2" data-test="workspace-row-status">
                {{ row.subscription?.status ?? 'none' }}
              </td>
            </tr>
          </tbody>
        </table>

        <TablePagination
          v-if="(workspaces?.total ?? 0) > 0"
          v-model:page="page"
          v-model:per-page="perPage"
          :total="workspaces?.total ?? 0"
          label="workspaces"
        />
      </div>
    </template>
  </UDashboardPanel>

  <WorkspaceDrawer
    v-if="selectedUuid"
    :uuid="selectedUuid"
    :open="selectedUuid !== null"
    @update:open="(v: boolean) => { if (!v) selectedUuid = null }"
  />
</template>
