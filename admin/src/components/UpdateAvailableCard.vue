<script setup lang="ts">
// The update notice. Everyone who sees it gets the terminal command. The owner (system.config), on
// an install that turned updating from the admin on, also gets an Update button when preflight is
// ready — or one sentence per reason it is not. A null preflight (403, or not asked) or one that is
// simply `disabled` leaves the card as it always was.
import { computed, ref } from 'vue'
import UpdateConfirmModal from '@/components/UpdateConfirmModal.vue'
import {
  REASON_TEXT,
  UPGRADE_COMMAND,
  type UpdatePreflight,
  type UpdateStatus,
} from '@/queries/updates'

const props = defineProps<{ status: UpdateStatus; preflight?: UpdatePreflight | null }>()
const emit = defineEmits<{ dismiss: [] }>()

const confirming = ref(false)
const offered = computed(
  () => props.preflight != null && !props.preflight.reasons.includes('disabled'),
)
const ready = computed(() => offered.value && props.preflight?.ready === true)
const reasons = computed(() =>
  offered.value && !ready.value ? (props.preflight?.reasons ?? []) : [],
)
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
        <UButton
          v-if="ready"
          :label="`Update to ${preflight?.target}`"
          icon="i-lucide-download"
          data-testid="update-available-start"
          @click="confirming = true"
        />
        <div v-if="reasons.length > 0" class="text-sm" data-testid="update-available-reasons">
          <p class="text-muted">Updating from here is unavailable:</p>
          <ul class="mt-1 list-disc space-y-1 pl-5 text-default">
            <li v-for="reason in reasons" :key="reason">{{ REASON_TEXT[reason] ?? reason }}</li>
          </ul>
        </div>
        <p class="text-xs text-muted">
          Reload PHP-FPM only if OPcache has timestamp validation off. The update runs on the server
          as the code owner, never in this browser.
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
    <UpdateConfirmModal
      v-if="ready"
      v-model:open="confirming"
      :current="preflight?.current ?? status.current"
      :target="preflight?.target ?? status.latest"
      :notes-url="status.notesUrl"
    />
  </UCard>
</template>
