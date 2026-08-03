<script setup lang="ts">
// Task 11 (thallo-subscriptions Phase B): the ONE degraded-engine notice both the Plans and
// Billing pages render whenever `GET /meta`'s `engine` field isn't `'ready'` -- shared so the
// two "engine unavailable" messages never independently drift (mirrors `EngineGateway`'s own
// two-state vocabulary, `'engine_disabled' | 'schema_not_ready'`; `'ready'` never reaches here).
withDefaults(defineProps<{ state: 'engine_disabled' | 'schema_not_ready' }>(), {})
</script>

<template>
  <div
    class="flex h-full flex-col items-center justify-center gap-3 p-8 text-center"
    data-test="engine-state-notice"
  >
    <UIcon
      :name="state === 'engine_disabled' ? 'i-lucide-power-off' : 'i-lucide-database'"
      class="size-8 text-muted"
    />
    <p class="font-medium" data-test="engine-state-title">
      {{
        state === 'engine_disabled'
          ? 'The subscriptions engine is disabled'
          : 'The subscriptions schema is not ready'
      }}
    </p>
    <p class="max-w-md text-sm text-muted" data-test="engine-state-description">
      {{
        state === 'engine_disabled'
          ? 'Enable the subscriptions extension to manage plans and workspace billing.'
          : 'Run the pending database migrations before subscriptions can be managed here.'
      }}
    </p>
    <UButton
      v-if="state === 'engine_disabled'"
      to="/extensions"
      icon="i-lucide-puzzle"
      label="Go to Extensions"
      data-test="engine-state-action"
    />
  </div>
</template>
