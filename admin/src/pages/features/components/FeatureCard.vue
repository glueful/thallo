<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  useActivationFlow,
  activationSummary,
  type ActivationRecord,
} from '@/queries/capabilityActivation'
import {
  setCapabilityState,
  useCapabilityStateMutations,
  enableBlockedReason,
  type ManagedCapability,
} from '@/queries/capabilityManagement'
import { useNotify } from '@/composables/useNotify'
import ActivationProgress from './ActivationProgress.vue'
import { featureCopy } from '../featureCopy'

// One feature on the Features page. A feature with an activation flow turns on with one action
// (confirm → start → continue while the server asks) and shows where its activation stands; a
// workspaces feature links to its own settings; any other capability keeps a plain switch.
const props = defineProps<{ capability: ManagedCapability }>()

const { success, error: notifyError } = useNotify()
const flow = useActivationFlow(() => props.capability.id)
const { setState } = useCapabilityStateMutations()

const label = computed(() => props.capability.label ?? props.capability.id)
const copy = computed(() => featureCopy(props.capability.id, label.value))
const confirming = ref<'on' | 'off' | null>(null)
const turningOff = ref(false)

/** The activation as this card knows it: this browser's latest response, else the server's. */
const activation = computed<ActivationRecord | null>(() =>
  flow.superseded.value ? null : (flow.record.value ?? props.capability.activation),
)

type CardState =
  | 'simple'
  | 'workspaces'
  | 'preparing'
  | 'failed'
  | 'open'
  | 'on'
  | 'read-only'
  | 'off'

const state = computed<CardState>(() => {
  const cap = props.capability
  if (cap.management === 'workspaces') return 'workspaces'
  if (cap.management !== 'activation') return 'simple'
  if (flow.running.value) return 'preparing'
  const status = activation.value?.status
  if (status === 'failed') return 'failed'
  if (status === 'preparing') return 'open'
  // On means effective now: a succeeded activation whose engine was since disabled outside
  // Thallo is off and unavailable, and turning it on runs a normal activation.
  if (!flow.superseded.value && (cap.effective || (status === 'succeeded' && cap.available)))
    return 'on'
  if (!cap.application_files_writable && !cap.engine_enabled) return 'read-only'
  return 'off'
})

/**
 * Why it can't be on, when that is news: always for a plain switch, and for an activation
 * feature once someone asked for it (a fresh install's untouched Commerce is simply off).
 */
const showUnavailable = computed(() => {
  const cap = props.capability
  if (cap.available || state.value === 'read-only' || state.value === 'on') return false
  return cap.management !== 'activation' || cap.requested
})

/** A plain switch's badge: on, asked for but its engine can't back it, or off. */
const simpleState = computed<{ label: string; color: 'success' | 'warning' | 'neutral' }>(() => {
  const cap = props.capability
  if (cap.effective) return { label: 'On', color: 'success' }
  if (cap.requested && !cap.available)
    return { label: 'Requested · engine unavailable', color: 'warning' }
  return { label: 'Off', color: 'neutral' }
})

const prepareCommand = computed(
  () => `php glueful thallo:features:enable ${props.capability.id} --prepare`,
)

function askTurnOn(): void {
  confirming.value = 'on'
}

async function turnOn(): Promise<void> {
  confirming.value = null
  await flow.start()
}

async function turnOff(): Promise<void> {
  confirming.value = null
  turningOff.value = true
  try {
    await setCapabilityState(props.capability.id, false)
    flow.record.value = null
    await flow.converge()
    success(`${label.value} is off`, label.value)
  } catch (e) {
    notifyError(e, `Could not turn off ${label.value}`)
  } finally {
    turningOff.value = false
  }
}

async function toggleSimple(): Promise<void> {
  const cap = props.capability
  const enabled = !cap.requested
  if (enabled) {
    const blocked = enableBlockedReason(cap)
    if (blocked) {
      notifyError(new Error(blocked), `Cannot enable ${label.value}`)
      return
    }
  }
  try {
    await setState.mutateAsync({ id: cap.id, enabled })
    success(enabled ? 'Capability enabled' : 'Capability disabled', label.value)
  } catch (e) {
    notifyError(e, 'Could not update capability')
  }
}
</script>

<template>
  <li class="flex flex-col gap-3 py-4" :data-test="`feature-${capability.id}`">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <p class="text-sm font-medium text-default">{{ label }}</p>
          <UBadge
            v-if="state === 'simple'"
            :label="simpleState.label"
            :color="simpleState.color"
            variant="subtle"
            size="xs"
            data-test="state-badge"
          />
        </div>
        <p v-if="capability.description" class="mt-0.5 text-xs text-muted">
          {{ capability.description }}
        </p>
        <p v-if="showUnavailable" class="mt-1 text-xs text-warning" data-test="unavailable-reason">
          {{ capability.reason }}
          <code v-if="capability.remedy" class="ms-1">{{ capability.remedy }}</code>
        </p>
      </div>

      <div class="shrink-0">
        <USwitch
          v-if="state === 'simple'"
          :model-value="capability.requested"
          :disabled="setState.isLoading.value"
          @update:model-value="toggleSimple"
        />
        <USwitch v-else-if="state === 'off'" :model-value="false" @update:model-value="askTurnOn" />
        <USwitch
          v-else-if="state === 'on'"
          :model-value="true"
          :disabled="turningOff"
          @update:model-value="() => (confirming = 'off')"
        />
        <ULink
          v-else-if="state === 'workspaces'"
          to="/settings/workspaces"
          class="text-sm text-primary"
        >
          Settings › Workspaces
        </ULink>
      </div>
    </div>

    <ActivationProgress v-if="state === 'preparing'" :label="label" :step="activation?.next_step" />

    <div v-else-if="state === 'failed'" class="flex flex-col gap-2 text-sm">
      <p class="text-error">{{ activation?.error }}</p>
      <code v-if="activation?.remedy" class="text-xs">{{ activation.remedy }}</code>
      <div class="flex gap-2">
        <UButton
          label="Retry"
          size="sm"
          data-test="feature-retry"
          @click="flow.resume(activation!.generation)"
        />
        <UButton
          label="Cancel"
          size="sm"
          color="neutral"
          variant="outline"
          data-test="feature-cancel"
          @click="flow.cancel(activation!.generation)"
        />
      </div>
    </div>

    <div v-else-if="state === 'open'" class="flex flex-col gap-2 text-sm">
      <p class="text-muted">Turning on {{ label }} didn't finish.</p>
      <div class="flex gap-2">
        <UButton
          label="Continue"
          size="sm"
          data-test="feature-continue"
          @click="flow.resume(activation!.generation)"
        />
        <UButton
          label="Cancel"
          size="sm"
          color="neutral"
          variant="outline"
          data-test="feature-cancel"
          @click="flow.cancel(activation!.generation)"
        />
      </div>
    </div>

    <div v-else-if="state === 'on'" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
      <span class="text-default" data-test="feature-summary">{{
        activationSummary(label, activation)
      }}</span>
      <ULink v-for="link in copy.links" :key="link.to" :to="link.to" class="text-primary">
        {{ link.label }}
      </ULink>
    </div>

    <div v-else-if="state === 'read-only'" class="flex flex-col gap-1 text-sm">
      <p class="text-muted">
        Application files can't be written on this host. Prepare {{ label }} at deploy time, then
        turn it on here:
      </p>
      <code class="text-xs">{{ prepareCommand }}</code>
    </div>

    <p v-if="flow.superseded.value" class="text-xs text-muted">
      A newer decision was made for {{ label }} elsewhere; this is where it stands now.
    </p>
    <p v-else-if="flow.error.value" class="text-xs text-error">{{ flow.error.value }}</p>

    <UModal
      :open="confirming !== null"
      :title="confirming === 'off' ? `Turn off ${label}?` : `Turn on ${label}?`"
      @update:open="
        (open: boolean) => {
          if (!open) confirming = null
        }
      "
    >
      <template #body>
        <div data-test="feature-confirm" class="flex flex-col gap-4">
          <p class="text-sm text-default">
            {{ confirming === 'off' ? copy.turnOff : copy.turnOn }}
          </p>
          <div class="flex justify-end gap-2">
            <UButton label="Cancel" color="neutral" variant="outline" @click="confirming = null" />
            <UButton
              v-if="confirming === 'on'"
              label="Turn on"
              data-test="feature-confirm-turn-on"
              @click="turnOn"
            />
            <UButton
              v-else
              label="Turn off"
              color="error"
              data-test="feature-confirm-turn-off"
              @click="turnOff"
            />
          </div>
        </div>
      </template>
    </UModal>
  </li>
</template>
