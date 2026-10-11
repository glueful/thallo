<script setup lang="ts">
// Confirming an update from the admin (docs/upgrading.md, Updating from the admin). It says what
// will happen, files the request, keeps the one-time token for the /_update page in this tab's
// sessionStorage, and hands the tab over to /_update — a page the server's update guard serves, which
// keeps working while the site is closed and its code replaced. A refused start (409) stays here
// and says why.
import { ref } from 'vue'
import { REASON_TEXT, UPDATE_TOKEN_KEY, refusalReasons, startUpdate } from '@/queries/updates'

const props = withDefaults(
  defineProps<{
    open: boolean
    current: string | null
    target: string | null
    notesUrl: string
    /** Where the tab goes after the request is filed (a seam for tests). */
    navigate?: (url: string) => void
  }>(),
  { navigate: (url: string) => window.location.assign(url) },
)
const emit = defineEmits<{ 'update:open': [open: boolean] }>()

const starting = ref(false)
const reasons = ref<string[]>([])
const failed = ref('')

async function start() {
  starting.value = true
  reasons.value = []
  failed.value = ''
  try {
    if (!props.target) throw new Error('There is no version to install.')
    const { token } = await startUpdate(props.target)
    try {
      sessionStorage.setItem(UPDATE_TOKEN_KEY, token)
    } catch {
      // Storage blocked: /_update asks for a token from `php bin/thallo-updater token` instead.
    }
    props.navigate('/_update')
  } catch (err) {
    reasons.value = refusalReasons(err)
    if (reasons.value.length === 0) {
      failed.value = err instanceof Error ? err.message : 'The update could not be started.'
    }
    starting.value = false
  }
}
</script>

<template>
  <UModal
    :open="open"
    title="Update Thallo?"
    @update:open="(value: boolean) => emit('update:open', value)"
  >
    <template #body>
      <div class="space-y-3 text-sm">
        <p class="text-default">
          From <span class="font-medium">{{ current }}</span> to
          <span class="font-medium">{{ target }}</span
          >. Read the
          <a
            :href="notesUrl"
            target="_blank"
            rel="noopener"
            class="text-primary underline underline-offset-2"
            >release notes</a
          >
          first, including any upgrade notes for this release.
        </p>
        <p class="text-muted">
          Your site is closed to visitors and to other admins while the update runs — usually a few
          minutes. A verified database backup is taken first.
        </p>
        <ul
          v-if="reasons.length > 0"
          class="list-disc space-y-1 pl-5 text-error"
          data-testid="update-confirm-reasons"
        >
          <li v-for="reason in reasons" :key="reason">{{ REASON_TEXT[reason] ?? reason }}</li>
        </ul>
        <p v-if="failed" class="text-error" data-testid="update-confirm-error">{{ failed }}</p>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-testid="update-confirm-cancel"
          @click="emit('update:open', false)"
        />
        <UButton
          :loading="starting"
          :label="`Update to ${target}`"
          data-testid="update-confirm-start"
          @click="start"
        />
      </div>
    </template>
  </UModal>
</template>
