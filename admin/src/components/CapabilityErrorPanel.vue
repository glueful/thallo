<script setup lang="ts">
/**
 * The ONE Retry surface for a failed capability-discovery fetch (store status `error`).
 *
 * Used in two places, deliberately sharing a single component so every capability page
 * does not grow its own error handling:
 * - the authenticated layout's capability boundary renders it INSTEAD of the routed page
 *   when the destination declares `meta.requiresCapability` and discovery errored;
 * - pages that gate locally instead of via route meta (currently /navigation) render it
 *   themselves in their capability-error branch.
 *
 * Dumb by design: no store access — the parent owns retry wiring (usually
 * `useCapabilitiesStore().retry()`), so tests can assert the emit without a store.
 */
withDefaults(
  defineProps<{
    title?: string
    description?: string
    retrying?: boolean
  }>(),
  {
    title: "We couldn't check which features are enabled",
    description:
      'The feature list did not load, so this page cannot tell whether it is available yet. ' +
      'Your data is untouched — retry to check again.',
    retrying: false,
  },
)

const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div
    class="flex h-full flex-col items-center justify-center gap-3 p-8 text-center"
    data-testid="capability-error-panel"
  >
    <UIcon name="i-lucide-cloud-off" class="text-muted size-8" />
    <p class="font-medium">{{ title }}</p>
    <p class="text-muted max-w-md text-sm">{{ description }}</p>
    <UButton
      icon="i-lucide-refresh-cw"
      :loading="retrying"
      data-testid="capability-error-retry"
      @click="emit('retry')"
    >
      Retry
    </UButton>
  </div>
</template>
