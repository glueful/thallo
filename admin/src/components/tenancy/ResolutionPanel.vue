<script setup lang="ts">
import { computed } from 'vue'
import type { ResolutionStatus, ResolutionStep } from '@/queries/tenancyResolution'

const props = defineProps<{ status: ResolutionStatus; busy?: boolean; error?: string | null }>()
const emit = defineEmits<{ activate: [retry: boolean]; deactivate: []; reset: [] }>()

type Phase = 'hosts' | 'map' | 'verify' | 'rebuild' | 'restart' | 'live'

const PHASES: { value: Phase; title: string; icon: string; run: string }[] = [
  { value: 'hosts', title: 'Set your hosts', icon: 'i-lucide-pencil-line', run: '' },
  { value: 'map', title: 'Map hosts to the workspace', icon: 'i-lucide-map-pin', run: 'Mapping your hosts…' },
  { value: 'verify', title: 'Check the hosts resolve', icon: 'i-lucide-globe', run: 'Checking DNS…' },
  { value: 'rebuild', title: 'Rebuild routes', icon: 'i-lucide-route', run: 'Rebuilding routes…' },
  { value: 'restart', title: 'Restart to finish', icon: 'i-lucide-rotate-cw', run: '' },
  { value: 'live', title: 'Live', icon: 'i-lucide-check', run: '' },
]

const RUNNING_STEPS: ResolutionStep[] = ['mapping_hosts', 'verifying_wiring', 'rebuilding_routes']

const full = computed(() => props.status.step === 'full')
const failed = computed(() => props.status.step === 'failed')
const awaiting = computed(() => props.status.step === 'awaiting_fresh_boot')
const running = computed(() => RUNNING_STEPS.includes(props.status.step))
const inactive = computed(() => props.status.step === 'inactive')

// Which timeline phase the machine currently sits on (undefined = stopped / failed).
const phase = computed<Phase | undefined>(() => {
  switch (props.status.step) {
    case 'inactive':
      return 'hosts'
    case 'mapping_hosts':
      return 'map'
    case 'verifying_wiring':
      return 'verify'
    case 'rebuilding_routes':
      return 'rebuild'
    case 'awaiting_fresh_boot':
      return 'restart'
    case 'full':
      return 'live'
    default:
      return undefined
  }
})

const reachedIndex = computed(() => PHASES.findIndex((p) => p.value === phase.value))

function isDone(index: number, value: Phase): boolean {
  if (full.value) return true
  // Hosts is "set" the moment activation moves past editing (any step other than inactive).
  if (value === 'hosts') return props.status.step !== 'inactive'
  return reachedIndex.value > -1 && index < reachedIndex.value
}

const items = computed(() =>
  PHASES.map((p, i) => {
    const current = p.value === phase.value
    return {
      value: p.value,
      title: p.title,
      icon: isDone(i, p.value) ? 'i-lucide-check' : p.icon,
      description:
        current && running.value
          ? p.run
          : p.value === 'restart' && awaiting.value
            ? 'Waiting for a restart'
            : undefined,
      slot: current && running.value ? 'run' : undefined,
    }
  }),
)

const badge = computed(() => {
  if (full.value) return { label: 'Live', color: 'success' as const }
  if (awaiting.value) return { label: 'Restart required', color: 'warning' as const }
  if (running.value) return { label: 'Activating…', color: 'warning' as const }
  if (failed.value) return { label: 'Stopped', color: 'error' as const }
  return { label: 'Off', color: 'neutral' as const }
})

const runningLabel = computed(() => PHASES.find((p) => p.value === phase.value)?.run ?? 'Working…')
</script>

<template>
  <section class="rounded-lg border border-default" aria-labelledby="resolution-heading">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-default px-5 py-4">
      <div>
        <h2 id="resolution-heading" class="text-base font-semibold">Domain routing</h2>
        <p class="mt-1 max-w-md text-sm text-muted">
          Route each workspace to its own domain or subdomain. Optional — leave off if one shared
          address is fine.
        </p>
      </div>
      <UBadge :color="badge.color" variant="subtle">{{ badge.label }}</UBadge>
    </div>

    <div class="grid gap-x-8 gap-y-5 px-5 py-5 sm:grid-cols-[190px_minmax(0,1fr)]">
      <!-- Progress rail -->
      <UTimeline
        :items="items"
        :model-value="phase"
        :color="full ? 'success' : 'primary'"
        size="xs"
        :ui="{
          description: 'text-primary',
          title: 'font-medium text-xs',
          root: 'gap-0',
          container: 'gap-0',
          separator: 'min-h-5',
        }"
      >
        <template #run-indicator>
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin text-primary" />
        </template>
      </UTimeline>

      <!-- Working area -->
      <div class="min-w-0">
        <UAlert
          v-if="full"
          color="success"
          variant="subtle"
          icon="i-lucide-check"
          title="Custom domains are live"
          description="Requests to your hosts now resolve to this workspace."
          class="mb-4"
        />
        <UAlert
          v-else-if="awaiting"
          color="warning"
          variant="subtle"
          icon="i-lucide-rotate-cw"
          title="Restart required to finish"
          description="The routes are rebuilt. Restart the app, then continue — this is the only restart."
          class="mb-4"
        />
        <p v-else-if="failed && (status.failure || error)" class="mb-4 text-sm text-error" role="alert">
          {{ error ?? status.failure }}
        </p>
        <div v-else-if="running" class="mb-4 flex items-center gap-2.5 text-sm text-highlighted">
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin text-primary" />
          {{ runningLabel }}
        </div>

        <p
          v-if="status.origin_restart_required"
          class="mb-4 text-sm text-warning"
          role="status"
          data-testid="resolution-restart-note"
        >
          The hosts changed since this process started — restart the app to continue activating.
        </p>

        <slot name="hosts" />

        <div class="mt-5 flex flex-wrap items-center gap-3">
          <template v-if="full">
            <UButton
              icon="i-lucide-unplug"
              color="neutral"
              :loading="busy"
              data-testid="resolution-action-deactivate"
              @click="emit('deactivate')"
            >
              Turn off
            </UButton>
          </template>

          <template v-else-if="awaiting">
            <UButton
              icon="i-lucide-rotate-cw"
              :loading="busy"
              data-testid="resolution-reload-continue"
              @click="emit('activate', false)"
            >
              I’ve restarted — continue
            </UButton>
          </template>

          <template v-else-if="failed">
            <UButton
              icon="i-lucide-rotate-ccw"
              :loading="busy"
              data-testid="resolution-action-activate"
              @click="emit('activate', true)"
            >
              Retry <span class="font-normal opacity-70">· resume where it stopped</span>
            </UButton>
            <UButton
              icon="i-lucide-undo-2"
              color="error"
              variant="subtle"
              :disabled="busy"
              data-testid="resolution-action-reset"
              @click="emit('reset')"
            >
              Reset <span class="font-normal opacity-70">· start over</span>
            </UButton>
          </template>

          <template v-else-if="running">
            <UButton icon="i-lucide-loader-circle" loading disabled>Activating…</UButton>
          </template>

          <template v-else-if="inactive">
            <UButton
              icon="i-lucide-zap"
              :loading="busy"
              data-testid="resolution-action-activate"
              @click="emit('activate', false)"
            >
              Activate
            </UButton>
            <span class="text-xs text-muted">Runs a few checks, then one restart to finish.</span>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
