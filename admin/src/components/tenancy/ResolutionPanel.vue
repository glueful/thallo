<script setup lang="ts">
import { computed } from 'vue'
import type { ResolutionStatus } from '@/queries/tenancyResolution'

const props = defineProps<{ status: ResolutionStatus; busy?: boolean; error?: string | null }>()
const emit = defineEmits<{ activate: [retry: boolean]; deactivate: [] }>()
const full = computed(() => props.status.step === 'full')
const retry = computed(() => props.status.step === 'failed')
const reload = computed(() => props.status.step === 'awaiting_fresh_boot')
</script>

<template>
  <section class="rounded-lg border border-default px-5 py-4" aria-labelledby="resolution-heading">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 id="resolution-heading" class="text-base font-semibold">Workspace resolution</h2>
        <p class="text-sm text-muted mt-1">{{ status.step.replace(/_/g, ' ') }}</p>
      </div>
      <UBadge :color="full ? 'success' : 'neutral'" variant="subtle">{{ status.mode }}</UBadge>
    </div>
    <p v-if="status.failure || error" class="mt-4 text-sm text-error" role="alert">
      {{ error ?? status.failure }}
    </p>
    <div class="mt-5">
      <UButton
        v-if="full"
        icon="i-lucide-unplug"
        color="neutral"
        :loading="busy"
        data-testid="resolution-action-deactivate"
        @click="emit('deactivate')"
      >
        Deactivate resolution
      </UButton>
      <UButton
        v-else
        icon="i-lucide-route"
        :loading="busy"
        :data-testid="reload ? 'resolution-reload-continue' : 'resolution-action-activate'"
        @click="emit('activate', retry)"
      >
        {{ reload ? 'Reload and continue' : retry ? 'Retry activation' : 'Continue activation' }}
      </UButton>
    </div>
  </section>
</template>
