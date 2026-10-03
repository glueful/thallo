<script setup lang="ts">
// The one "Turning on <label>…" state a feature shows while its activation runs, with the step the
// server reports next. The steps themselves belong to the server.
defineProps<{ label: string; step?: string | null }>()

const STEP_LABELS: Record<string, string> = {
  mark_preparing: 'Starting',
  enable_engine: 'Preparing the engine',
  verify_boot: 'Checking the engine is running',
  seed_blocks: 'Adding blocks',
  grant_permissions: 'Granting permissions',
  finalize: 'Switching it on',
}
</script>

<template>
  <div class="flex items-center gap-2 text-sm text-default" data-test="capability-preparing">
    <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin text-muted" />
    <span>Turning on {{ label }}…</span>
    <span v-if="step && STEP_LABELS[step]" class="text-xs text-muted">{{ STEP_LABELS[step] }}</span>
  </div>
</template>
