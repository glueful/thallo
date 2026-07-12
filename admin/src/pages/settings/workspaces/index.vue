<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useTenancyEnablement, useTenancyEnablementMutations } from '@/queries/tenancyEnablement'
import { useTenancyResolution, useTenancyResolutionMutations } from '@/queries/tenancyResolution'
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

function onActivate(retry: boolean) {
  return run(() => resolutionMutations.activate.mutateAsync(retry), refreshResolution)
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
          <ResolutionPanel
            v-if="resolution && enablement?.enabled"
            :status="resolution"
            :busy="busy"
            :error="error"
            @activate="onActivate"
            @deactivate="run(() => resolutionMutations.deactivate.mutateAsync(), refreshResolution)"
          />
          <!-- Dependent on multi-workspace mode: the switch is locked until it's on. -->
          <WorkspaceSignupSettings :workspaces-enabled="enablement?.enabled ?? false" />
          <DiagnoseReport :report="diagnose" :busy="diagnosing" @run="runDiagnose" />
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
