<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useTenancyEnablement, useTenancyEnablementMutations } from '@/queries/tenancyEnablement'
import {
  useTenancyResolution,
  useTenancyResolutionMutations,
  type ResolutionStep,
} from '@/queries/tenancyResolution'
import { useTenancyDiagnose } from '@/queries/tenancyDiagnose'

definePage({ meta: { requiresAuth: true } })

const {
  data: enablement,
  status: enablementQuery,
  refresh: refreshEnablement,
} = useTenancyEnablement()
const enablementMutations = useTenancyEnablementMutations()
const {
  data: resolution,
  status: resolutionQuery,
  refresh: refreshResolution,
} = useTenancyResolution()
const resolutionMutations = useTenancyResolutionMutations()
const { data: diagnose, refresh: runDiagnose, isLoading: diagnosing } = useTenancyDiagnose()
const busy = ref(false)
const error = ref<string | null>(null)

const loading = computed(
  () => enablementQuery.value === 'pending' || resolutionQuery.value === 'pending',
)

async function run(operation: () => Promise<unknown>, refresh: () => unknown): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await operation()
    await refresh()
  } catch (caught) {
    error.value =
      caught instanceof ApiError || caught instanceof Error ? caught.message : 'Request failed.'
  } finally {
    busy.value = false
  }
}

function onEnablementAction(action: 'begin' | 'retry' | 'finalize' | 'disable' | 'cancel') {
  const mutation = enablementMutations[action]
  return run(() => mutation.mutateAsync(), refreshEnablement)
}

function onConfirm(input: { slug: string; name: string }) {
  return run(() => enablementMutations.confirm.mutateAsync(input), refreshEnablement)
}

// The machine advances one internal step per POST. Auto-advance through the internal steps so the
// operator clicks once — stopping only where a human is actually needed: a restart, a failure, or
// full. A mid-activation failure surfaces as a rejected request (422), which `run()` catches; the
// refetched status then shows the failed state.
const ACTIVATION_GATES = new Set<ResolutionStep>(['awaiting_fresh_boot', 'full', 'failed'])

function onActivate(retry: boolean) {
  return run(async () => {
    let status = await resolutionMutations.activate.mutateAsync(retry)
    let guard = 0
    while (!ACTIVATION_GATES.has(status.step) && guard++ < 8) {
      status = await resolutionMutations.activate.mutateAsync(false)
    }
    return status
  }, refreshResolution)
}
</script>

<template>
  <UDashboardPanel id="settings-tenancy">
    <template #header>
      <UDashboardNavbar title="Workspaces" />
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
        <p class="text-sm text-muted">
          By default Thallo runs as a single site. Turn on multi-workspace mode to host many
          independent workspaces, then choose whether visitors can create their own.
        </p>
        <div v-if="loading" class="flex flex-col gap-6">
          <USkeleton class="h-28 w-full" />
          <USkeleton class="h-28 w-full" />
        </div>
        <template v-else>
          <!-- Prerequisite first: everything below depends on it. -->
          <EnablementPanel
            v-if="enablement"
            :status="enablement"
            :busy="busy"
            :error="error"
            @action="onEnablementAction"
            @confirm="onConfirm"
          />
          <!-- Domain routing: set hosts (step 1) then activate (step 2), one card, one narrative. -->
          <ResolutionPanel
            v-if="resolution && enablement?.enabled"
            :status="resolution"
            :busy="busy"
            :error="error"
            @activate="onActivate"
            @deactivate="run(() => resolutionMutations.deactivate.mutateAsync(), refreshResolution)"
            @reset="run(() => resolutionMutations.reset.mutateAsync(), refreshResolution)"
          >
            <template #hosts>
              <PublicOriginSettings embedded />
            </template>
          </ResolutionPanel>
          <!-- Dependent on multi-workspace mode: the switch is locked until it's on. -->
          <WorkspaceSignupSettings :workspaces-enabled="enablement?.enabled ?? false" />
          <DiagnoseReport :report="diagnose" :busy="diagnosing" @run="runDiagnose" />
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
