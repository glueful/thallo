<script setup lang="ts">
// The in-app leave-confirmation modal for `useUnsavedGuard` (user feedback 2026-07-25 — the
// native window.confirm read as a browser artifact, not part of the app). Purely presentational:
// the guard owns the parked navigation; every path out of this modal — Keep editing, Discard,
// backdrop/Esc dismiss — resolves it exactly once via the `resolve` emit (dismiss = stay, the
// safe default).
import type { LeaveConfirm } from '@/composables/useSectionState'

defineProps<{ state: LeaveConfirm }>()
const emit = defineEmits<{ resolve: [leave: boolean] }>()
</script>

<template>
  <UModal
    :open="state.open"
    title="Discard unsaved changes?"
    @update:open="
      (open: boolean) => {
        if (!open) emit('resolve', false)
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted" data-test="unsaved-modal-body">
        You have unsaved changes in
        <span class="font-medium text-default">{{ state.sections.join(', ') }}</span
        >. Leaving this page will discard them.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Keep editing"
          data-test="unsaved-keep"
          @click="emit('resolve', false)"
        />
        <UButton
          color="error"
          label="Discard changes"
          data-test="unsaved-discard"
          @click="emit('resolve', true)"
        />
      </div>
    </template>
  </UModal>
</template>
