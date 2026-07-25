<script setup lang="ts">
import { computed } from 'vue'
import type { EnablementStatus } from '@/queries/tenancyEnablement'

const props = defineProps<{ status: EnablementStatus; busy?: boolean; error?: string | null }>()
const emit = defineEmits<{
  action: [name: 'begin' | 'retry' | 'finalize' | 'disable' | 'cancel']
  confirm: [value: { slug: string; name: string }]
}>()

const action = computed(() => {
  const step = props.status.step
  // disabled_widened is the disable direction's RESTING state, with one transient sub-state:
  // while `reloading` (the retrofit guard persists) the run awaits fresh-boot verification —
  // the settle path is disable() again, never begin() (begin is the RE-ENABLE entry and
  // refuses with "awaiting fresh-boot verification" until settled, a dead-end Continue).
  if (step === 'disabled_widened') return props.status.reloading ? 'disable' : 'begin'
  if (
    [
      'off',
      'installing',
      'awaiting_install',
      'enabling_extension',
      'migrating_extension',
      'awaiting_provider_boot',
      'enabling_enforcement',
    ].includes(step)
  )
    return 'begin'
  if (step === 'failed') return 'retry'
  if (step === 'reloading' || step === 'finalizing') return 'finalize'
  if (step === 'on' || step === 'disabling') return 'disable'
  return null
})
const label = computed(() => {
  if (props.status.step === 'disabled_widened') {
    return props.status.reloading ? 'Verify and finish disable' : 'Re-enable workspaces'
  }
  if (action.value === 'retry') return 'Retry'
  if (action.value === 'finalize') return 'Reload and continue'
  if (action.value === 'disable') return 'Disable workspaces'
  return props.status.step === 'off' ? 'Enable workspaces' : 'Continue'
})
const showConfirm = computed(
  () =>
    props.status.step === 'awaiting_confirm' ||
    (props.status.step === 'retrofitting' &&
      (!props.status.pending_slug || !props.status.pending_name)),
)
</script>

<template>
  <section
    class="rounded-lg border border-default px-5 py-4"
    aria-labelledby="enablement-heading"
  >
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 id="enablement-heading" class="text-sm font-semibold text-highlighted">
          Multi-workspace mode
        </h2>
        <p class="mt-1 text-sm text-muted">
          Turn this single site into many independent workspaces, each with its own content,
          domains, members, and roles. Enabling runs a one-time migration and finishes after a
          restart.
        </p>
        <p
          v-if="status.step !== 'off' && status.step !== 'on'"
          class="mt-2 text-xs text-muted"
          data-testid="enablement-step"
        >
          Step: {{ status.step.replace(/_/g, ' ') }}
        </p>
      </div>
      <UBadge :color="status.enabled ? 'success' : 'neutral'" variant="subtle">
        {{ status.enabled ? 'Enabled' : 'Disabled' }}
      </UBadge>
    </div>

    <UProgress class="mt-5" :model-value="status.progress" />
    <p
      v-if="status.failure || error"
      class="mt-4 text-sm text-error"
      role="alert"
      data-testid="enablement-error"
    >
      {{ error ?? status.failure }}
    </p>
    <p v-if="status.cli_fallback" class="mt-4 text-sm font-mono break-all">
      {{ status.cli_fallback }}
    </p>

    <div v-if="action" class="mt-5">
      <UButton
        :icon="action === 'disable' ? 'i-lucide-power' : 'i-lucide-arrow-right'"
        :color="action === 'disable' ? 'error' : 'primary'"
        :loading="busy"
        :data-testid="
          action === 'finalize' ? 'enablement-reload-continue' : `enablement-action-${action}`
        "
        @click="emit('action', action)"
      >
        {{ label }}
      </UButton>
    </div>

    <FirstTenantConfirmForm
      v-if="showConfirm"
      class="mt-5"
      :initial-slug="status.pending_slug"
      :initial-name="status.pending_name"
      :busy="busy"
      @submit="emit('confirm', $event)"
    />
    <div v-else-if="status.step === 'retrofitting'" class="mt-5">
      <UButton
        icon="i-lucide-arrow-right"
        :loading="busy"
        data-testid="enablement-action-confirm"
        @click="emit('confirm', { slug: status.pending_slug!, name: status.pending_name! })"
      >
        Continue
      </UButton>
    </div>
    <UButton
      v-if="status.step === 'awaiting_confirm'"
      class="mt-3"
      color="neutral"
      variant="ghost"
      :disabled="busy"
      @click="emit('action', 'cancel')"
    >
      Cancel
    </UButton>
  </section>
</template>
