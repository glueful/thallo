<script setup lang="ts">
import { UPGRADE_COMMAND, type UpdateStatus } from '@/queries/updates'

defineProps<{ status: UpdateStatus }>()
const emit = defineEmits<{ dismiss: [] }>()
</script>

<template>
  <UCard data-testid="update-available-card">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-arrow-up-circle" class="size-5 text-primary" />
          <h2 class="text-base font-semibold text-highlighted">
            Thallo {{ status.latest }} is available
          </h2>
        </div>
        <p class="text-sm text-muted">
          This site runs {{ status.current }}. Read the
          <a
            :href="status.notesUrl"
            target="_blank"
            rel="noopener"
            class="text-primary underline underline-offset-2"
            data-testid="update-available-notes"
            >release notes</a
          >, then upgrade from the server as the deploy user:
        </p>
        <pre
          class="overflow-x-auto rounded-md bg-elevated px-3 py-2 text-xs text-default"
        ><code data-testid="update-available-command">{{ UPGRADE_COMMAND }}</code></pre>
        <p class="text-xs text-muted">
          Then reload PHP-FPM. Composer runs on the server, never from this page.
        </p>
      </div>
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-x"
        aria-label="Dismiss until the next release"
        data-testid="update-available-dismiss"
        @click="emit('dismiss')"
      />
    </div>
  </UCard>
</template>
