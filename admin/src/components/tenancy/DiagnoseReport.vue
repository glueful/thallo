<script setup lang="ts">
import type { DiagnoseReport } from '@/queries/tenancyDiagnose'

defineProps<{ report?: DiagnoseReport | null; busy?: boolean }>()
defineEmits<{ run: [] }>()

function color(status: string): 'success' | 'warning' | 'error' | 'neutral' {
  if (status === 'ok') return 'success'
  if (status === 'warn') return 'warning'
  if (status === 'fail') return 'error'
  return 'neutral'
}
</script>

<template>
  <section
    class="rounded-lg border border-default px-5 py-4"
    aria-labelledby="diagnostics-heading"
  >
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 id="diagnostics-heading" class="text-sm font-semibold text-highlighted">Diagnostics</h2>
        <p class="mt-1 text-sm text-muted">
          Check tenancy coherence — schema state, table registration, membership integrity, and
          policy drift. Read-only; changes nothing.
        </p>
      </div>
      <UButton
        icon="i-lucide-stethoscope"
        color="neutral"
        variant="outline"
        :loading="busy"
        class="shrink-0"
        @click="$emit('run')"
      >
        Run diagnostics
      </UButton>
    </div>
    <ul v-if="report" class="mt-5 divide-y divide-default" role="list">
      <li
        v-for="(section, key) in report.sections"
        :key="key"
        class="flex items-start justify-between gap-4 py-3"
        :data-testid="`diagnose-section-${key}`"
      >
        <div class="min-w-0">
          <p class="font-medium capitalize">{{ String(key).replace(/_/g, ' ') }}</p>
          <pre class="mt-1 whitespace-pre-wrap break-words text-xs text-muted font-sans">{{
            section.detail
          }}</pre>
        </div>
        <UBadge :color="color(section.status)" variant="subtle">{{ section.status }}</UBadge>
      </li>
    </ul>
  </section>
</template>
