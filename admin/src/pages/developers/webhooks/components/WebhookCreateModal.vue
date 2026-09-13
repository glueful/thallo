<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import {
  useWebhookMutations,
  WEBHOOK_EVENTS,
  WEBHOOK_EVENT_PATTERNS,
  type WebhookSubscription,
} from '@/queries/webhooks'
import { useNotify } from '@/composables/useNotify'

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{
  created: [result: { subscription: WebhookSubscription; secret: string }]
}>()

const { create } = useWebhookMutations()
const { error: notifyError } = useNotify()

const eventItems: string[] = [...WEBHOOK_EVENT_PATTERNS, ...WEBHOOK_EVENTS]

const form = reactive({ url: '' })
const events = ref<string[]>([])
const errors = reactive({ url: '', events: '' })

watch(open, (isOpen) => {
  if (isOpen) {
    form.url = ''
    events.value = []
    errors.url = ''
    errors.events = ''
  }
})

async function submit() {
  errors.url = ''
  errors.events = ''
  if (!form.url.trim()) {
    errors.url = 'An endpoint URL is required.'
    return
  }
  if (!events.value.length) {
    errors.events = 'Select at least one event.'
    return
  }
  try {
    const result = await create.mutateAsync({ url: form.url.trim(), events: events.value })
    open.value = false
    emit('created', result)
  } catch (e) {
    notifyError(e, 'Could not create the webhook')
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="New webhook"
    description="Send content events to an external endpoint."
  >
    <template #body>
      <div class="flex flex-col gap-5">
        <!-- Endpoint URL -->
        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
            Endpoint URL
          </label>
          <UInput
            v-model="form.url"
            placeholder="https://example.com/webhooks"
            class="w-full"
            autofocus
          />
          <p v-if="errors.url" class="mt-1 text-xs text-error">{{ errors.url }}</p>
        </div>

        <!-- Events -->
        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">
            Events
          </label>
          <p class="mb-2 text-xs text-muted">
            Pick specific events, or a wildcard like <code>*</code> (all) or <code>entry.*</code>.
          </p>
          <USelectMenu
            v-model="events"
            :items="eventItems"
            multiple
            placeholder="Select events…"
            class="w-full"
          />
          <p v-if="errors.events" class="mt-1 text-xs text-error">{{ errors.events }}</p>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="create.isLoading.value"
          @click="
            () => {
              open = false
            }
          "
        />
        <UButton
          label="Create webhook"
          icon="i-lucide-webhook"
          :loading="create.isLoading.value"
          @click="submit"
        />
      </div>
    </template>
  </UModal>
</template>
