<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): the workspace billing admin page. Meta-first fetch
// (binding behavior rule) decides between four renders: an engine-state notice, the tenancy-ON
// paginated workspace directory, the tenancy-OFF "This site's plan" panel (the SAME
// `WorkspaceDrawer`, embedded, bound to the non-null `default_tenant_uuid`), or -- when tenancy
// is off AND no default workspace is established yet -- a repair notice that issues NO workspace
// request at all: `WorkspaceDrawer` (and the `useWorkspace()` query inside it) is only ever
// mounted once a concrete uuid is known, so the null-default branch below simply never renders it.
import { computed, ref } from 'vue'
import {
  useSubscriptionsMeta,
  useSelfServeCheckoutMutation,
  useWorkspaces,
  type WorkspaceRow,
} from '@/queries/subscriptionsBilling'
import TablePagination from '@/components/TablePagination.vue'
import EngineStateNotice from '../components/EngineStateNotice.vue'
import WorkspaceDrawer from '../components/WorkspaceDrawer.vue'
import { toApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

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
// TablePagination's page-size dropdown only offers [10, 25, 50, 100] (its own default
// `pageSizes`, never overridden here) — the initial value MUST be one of those or the select
// renders with no matching option. 25 mirrors every other TablePagination consumer's default.
const perPage = ref(25)
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

// Task 15 (spec §5.1): the platform-only self-serve checkout kill switch. Rendered as soon as
// meta loads successfully -- independent of engine readiness/tenancy mode, since the switch and
// its gateway-capability gate are a Payvia concern, not a subscriptions-engine one. Turning it ON
// requires `self_serve_gateway_capable`; turning it OFF is always allowed (kill switch), so the
// control is disabled ONLY while off-and-incapable, never while already on.
const { success, error: notifyError } = useNotify()
const selfServeMutation = useSelfServeCheckoutMutation()
const selfServeEnabled = computed(() => meta.value?.self_serve_checkout_enabled ?? false)
const selfServeGatewayCapable = computed(() => meta.value?.self_serve_gateway_capable ?? false)
const selfServeSwitchDisabled = computed(() => !selfServeEnabled.value && !selfServeGatewayCapable.value)
const selfServeError = ref<string | null>(null)

// Names the blocking gateway concretely wherever we can -- both the standing "why is this
// unavailable" panel below and a refused enable attempt (the 409's `error.details` carries the
// SAME `reason`/`gateway` pair `SelfServeGatewayCapability::evaluate()` produced) render through
// this ONE function, so the explanation never drifts between the two call sites.
function describeGatewayUnavailable(reason: string | null, gateway: string | null): string {
  if (reason === 'payvia_unavailable') {
    return 'No payment gateway is configured, so self-serve checkout can’t be turned on.'
  }
  if (gateway) {
    return `The configured default gateway (${gateway}) does not support subscription checkout — configure a capable gateway such as Stripe as payvia’s default.`
  }
  return 'The configured payment gateway doesn’t support subscription checkout, so self-serve checkout can’t be turned on.'
}

const selfServeUnavailableMessage = computed<string | null>(() => {
  if (selfServeGatewayCapable.value) return null
  return describeGatewayUnavailable(
    meta.value?.self_serve_gateway_capable_reason ?? null,
    meta.value?.self_serve_gateway ?? null,
  )
})

async function onToggleSelfServe(next: boolean) {
  selfServeError.value = null
  try {
    await selfServeMutation.mutateAsync(next)
    success(next ? 'Self-serve checkout enabled' : 'Self-serve checkout disabled')
  } catch (e) {
    const err = toApiError(e)
    if (apiErrorCode(e) === 'no_capable_gateway') {
      const details = apiErrorDetails(e)
      const reason = typeof details?.reason === 'string' ? details.reason : null
      const gateway = typeof details?.gateway === 'string' ? details.gateway : null
      selfServeError.value = describeGatewayUnavailable(reason, gateway)
    } else {
      selfServeError.value = err.message
    }
    notifyError(err, 'Couldn’t update self-serve checkout')
  }
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
      <template v-else>
        <div class="border-b border-default p-6" data-test="self-serve-switch-panel">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="font-medium">Self-serve checkout</p>
              <p class="text-sm text-muted">
                Lets a workspace with billing.manage start its own subscription checkout.
              </p>
            </div>
            <USwitch
              :model-value="selfServeEnabled"
              :disabled="selfServeSwitchDisabled"
              data-test="self-serve-switch"
              @update:model-value="onToggleSelfServe"
            />
          </div>
          <UAlert
            v-if="selfServeUnavailableMessage"
            class="mt-3"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="selfServeUnavailableMessage"
            data-test="self-serve-unavailable-reason"
          />
          <UAlert
            v-if="selfServeError"
            class="mt-3"
            color="error"
            variant="subtle"
            :description="selfServeError"
            data-test="self-serve-switch-error"
          />
        </div>

        <EngineStateNotice
          v-if="meta && meta.engine !== 'ready'"
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
                <!-- The tenant's OWN lifecycle status (final-wave fix C): the directory lists
                     every live workspace, including `provisioning`/`suspended` ones whose
                     billing writes the API refuses with 409 `workspace_not_active`, so the
                     operator must be able to see that state here rather than discovering it on a
                     failed save. -->
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
    </template>
  </UDashboardPanel>

  <WorkspaceDrawer
    v-if="selectedUuid"
    :uuid="selectedUuid"
    :open="selectedUuid !== null"
    @update:open="(v: boolean) => { if (!v) selectedUuid = null }"
  />
</template>
