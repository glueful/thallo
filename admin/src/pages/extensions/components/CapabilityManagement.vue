<script setup lang="ts">
import { computed } from 'vue'
import {
  useCapabilityManagement,
  useCapabilityStateMutations,
  enableBlockedReason,
  type ManagedCapability,
} from '@/queries/capabilityManagement'
import { useNotify } from '@/composables/useNotify'

const { data, status } = useCapabilityManagement()
const { setState } = useCapabilityStateMutations()
const { success, error: notifyError } = useNotify()

const capabilities = computed<ManagedCapability[]>(() => data.value ?? [])
const busy = computed(() => setState.isLoading.value)

function stateLabel(cap: ManagedCapability): string {
  if (cap.effective) return 'On'
  if (cap.requested && !cap.available) return 'Requested · engine unavailable'
  return 'Off'
}

function stateColor(cap: ManagedCapability): 'success' | 'warning' | 'neutral' {
  if (cap.effective) return 'success'
  if (cap.requested && !cap.available) return 'warning'
  return 'neutral'
}

async function toggle(cap: ManagedCapability) {
  const enabled = !cap.requested
  // Disable is always allowed; a blocked enable is refused server-side too — surface the
  // reason without a round-trip.
  if (enabled) {
    const blocked = enableBlockedReason(cap)
    if (blocked) {
      notifyError(new Error(blocked), `Cannot enable ${cap.label ?? cap.id}`)
      return
    }
  }
  try {
    await setState.mutateAsync({ id: cap.id, enabled })
    success(enabled ? 'Capability enabled' : 'Capability disabled', cap.label ?? cap.id)
  } catch (e) {
    notifyError(e, 'Could not update capability')
  }
}
</script>

<template>
  <div class="h-full min-h-0 overflow-y-auto">
    <div v-if="status === 'pending'" class="flex justify-center py-10">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
    </div>
    <UEmpty
      v-else-if="status === 'error'"
      icon="i-lucide-shield-alert"
      title="Operator access required"
      description="Managing capabilities requires the system.access permission."
    />
    <UEmpty
      v-else-if="!capabilities.length"
      icon="i-lucide-toggle-left"
      title="No capabilities registered"
      description="Installed packs register their capabilities at boot."
    />
    <ul v-else class="divide-y divide-default">
      <li
        v-for="cap in capabilities"
        :key="cap.id"
        class="flex items-start justify-between gap-4 py-3"
        :data-test="`capability-${cap.id}`"
      >
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <p class="text-sm font-medium text-default">{{ cap.label ?? cap.id }}</p>
            <UBadge
              :label="stateLabel(cap)"
              :color="stateColor(cap)"
              variant="subtle"
              size="xs"
              data-test="state-badge"
            />
          </div>
          <p class="text-xs text-muted">{{ cap.id }}</p>
          <p v-if="cap.description" class="mt-1 text-xs text-muted">{{ cap.description }}</p>
          <p v-if="cap.owning_package" class="mt-1 text-xs text-muted">
            Engine: <code>{{ cap.owning_package }}</code>
          </p>
          <p v-if="!cap.available" class="mt-1 text-xs text-warning" data-test="unavailable-reason">
            {{ cap.reason }}
            <code v-if="cap.remedy" class="ms-1">{{ cap.remedy }}</code>
          </p>
        </div>
        <USwitch
          :model-value="cap.requested"
          :disabled="busy"
          :data-test="`toggle-${cap.id}`"
          @update:model-value="() => toggle(cap)"
        />
      </li>
    </ul>
  </div>
</template>
