<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): the platform Plans admin page. Meta-first fetch
// (binding behavior rule): `GET /meta` decides which of the three engine states to render before
// the plans list is ever requested -- `usePlans()`'s `enabled` stays false until the engine
// reports `ready`, so a disabled/unmigrated engine never issues the list request at all (it would
// 409 anyway, but the meta probe already told us not to bother).
import { computed, ref } from 'vue'
import {
  useSubscriptionsMeta,
  usePlans,
  usePlanMutations,
  type SubscriptionPlan,
} from '@/queries/subscriptionsBilling'
import { useNotify } from '@/composables/useNotify'
import EngineStateNotice from '../components/EngineStateNotice.vue'
import PlanEditor from '../components/PlanEditor.vue'

definePage({ meta: { requiresAuth: true, requiresCapability: 'thallo.subscriptions' } })

const { success, error: notifyError } = useNotify()

// `metaStatus === 'error'` is its OWN branch (final-wave fix E): the meta probe failing is not the
// same thing as the engine being disabled, and rendering the pending skeleton for it left this page
// in a perpetual "loading" state that never resolved. Retry via the query's own refetch.
const { data: meta, status: metaStatus, refetch: refetchMeta } = useSubscriptionsMeta()
const engineReady = computed(() => meta.value?.engine === 'ready')

const { data: plans, status: plansStatus } = usePlans(engineReady)
const rows = computed<SubscriptionPlan[]>(() => plans.value ?? [])

const { archive, importConfig } = usePlanMutations()

// Spec §4 lists an import-config action on this page: seeds the platform catalog from the
// `subscriptions.plans` config block (`PlansController::importConfig()`). Non-forcing by default --
// existing plans are left alone unless the operator opts into overwriting them.
const importForce = ref(false)
const importOpen = ref(false)
async function confirmImport() {
  try {
    const imported = await importConfig.mutateAsync({ force: importForce.value })
    const count = Array.isArray(imported) ? imported.length : 0
    success('Plans imported', `${count} plan${count === 1 ? '' : 's'} imported from config.`)
    importOpen.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t import plans')
  }
}

const formOpen = ref(false)
const editingPlan = ref<SubscriptionPlan | null>(null)

function openCreate() {
  editingPlan.value = null
  formOpen.value = true
}
function openEdit(plan: SubscriptionPlan) {
  editingPlan.value = plan
  formOpen.value = true
}

const pendingArchive = ref<SubscriptionPlan | null>(null)
async function confirmArchive() {
  const plan = pendingArchive.value
  if (!plan) return
  try {
    await archive.mutateAsync(plan.plan_key)
    success('Plan archived', `“${plan.display_name}” was archived.`)
  } catch (e) {
    notifyError(e, 'Couldn’t archive plan')
  } finally {
    pendingArchive.value = null
  }
}
</script>

<template>
  <UDashboardPanel id="subscriptions-plans">
    <template #header>
      <UDashboardNavbar title="Plans">
        <template #right>
          <UButton
            v-if="engineReady"
            icon="i-lucide-download"
            color="neutral"
            variant="ghost"
            data-test="import-plans"
            @click="importOpen = true"
          >
            Import from config
          </UButton>
          <UButton
            v-if="engineReady"
            icon="i-lucide-plus"
            data-test="new-plan"
            @click="openCreate"
          >
            New plan
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="metaStatus === 'pending'" class="p-6" data-test="plans-meta-loading">
        <USkeleton class="h-24 w-full" />
      </div>
      <div v-else-if="metaStatus === 'error'" class="p-6" data-test="plans-meta-error">
        <UAlert
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Couldn't load subscriptions status"
          description="The subscriptions status check failed, so the plan catalog can't be shown. Check your connection and try again."
        />
        <UButton
          class="mt-4"
          color="neutral"
          variant="subtle"
          icon="i-lucide-refresh-cw"
          label="Retry"
          data-test="plans-meta-retry"
          @click="refetchMeta()"
        />
      </div>
      <EngineStateNotice
        v-else-if="meta && meta.engine !== 'ready'"
        :state="meta.engine === 'schema_not_ready' ? 'schema_not_ready' : 'engine_disabled'"
      />
      <div v-else class="p-6">
        <div v-if="plansStatus === 'pending'" data-test="plans-loading">
          <USkeleton class="h-24 w-full" />
        </div>
        <div v-else-if="plansStatus === 'error'" data-test="plans-error">
          <UAlert color="error" variant="subtle" title="Couldn't load plans." />
        </div>
        <div v-else-if="rows.length === 0" data-test="plans-empty" class="text-sm text-muted">
          No plans yet. Create one to get started.
        </div>
        <table v-else class="w-full text-left text-sm" data-test="plans-table">
          <thead>
            <tr class="border-b border-default text-muted">
              <th class="py-2">Plan</th>
              <th class="py-2">Status</th>
              <th class="py-2">Sort</th>
              <th class="py-2" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="plan in rows" :key="plan.uuid" class="border-b border-default" data-test="plan-row">
              <td class="py-2">
                <p class="font-medium" data-test="plan-display-name">{{ plan.display_name }}</p>
                <p class="text-xs text-muted" data-test="plan-key">{{ plan.plan_key }}</p>
              </td>
              <td class="py-2" data-test="plan-status">{{ plan.status }}</td>
              <td class="py-2">{{ plan.sort_order }}</td>
              <td class="py-2 text-right">
                <UButton size="xs" variant="ghost" label="Edit" data-test="plan-edit" @click="openEdit(plan)" />
                <UButton
                  v-if="plan.status !== 'archived'"
                  size="xs"
                  color="error"
                  variant="ghost"
                  label="Archive"
                  data-test="plan-archive"
                  @click="pendingArchive = plan"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </UDashboardPanel>

  <PlanEditor v-model:open="formOpen" :plan="editingPlan" />

  <UModal
    :open="importOpen"
    title="Import plans from config"
    @update:open="(v: boolean) => { if (!v) importOpen = false }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Seeds the platform catalog from this site's <span class="text-default">subscriptions.plans</span>
        config block. Plans that already exist are skipped unless you overwrite them.
      </p>
      <UCheckbox
        v-model="importForce"
        class="mt-4"
        label="Overwrite plans that already exist"
        data-test="import-plans-force"
      />
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="importConfig.isLoading.value"
          @click="() => { importOpen = false }"
        />
        <UButton
          label="Import"
          data-test="import-plans-confirm"
          :loading="importConfig.isLoading.value"
          @click="confirmImport"
        />
      </div>
    </template>
  </UModal>

  <UModal
    :open="pendingArchive !== null"
    title="Archive plan"
    @update:open="(v: boolean) => { if (!v) pendingArchive = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Archive <span class="text-default">“{{ pendingArchive?.display_name }}”</span>? Existing subscribers
        keep their access; no new subscriptions can start on it.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="archive.isLoading.value"
          @click="() => { pendingArchive = null }"
        />
        <UButton
          color="error"
          label="Archive"
          data-test="plan-archive-confirm"
          :loading="archive.isLoading.value"
          @click="confirmArchive"
        />
      </div>
    </template>
  </UModal>
</template>
