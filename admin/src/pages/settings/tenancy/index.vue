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
      <UDashboardNavbar title="Tenancy" />
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-5xl px-4 sm:px-6">
        <div v-if="loading" class="grid gap-4 py-6">
          <USkeleton class="h-28 w-full" />
          <USkeleton class="h-28 w-full" />
        </div>
        <template v-else>
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
          <DiagnoseReport :report="diagnose" :busy="diagnosing" @run="runDiagnose" />
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
