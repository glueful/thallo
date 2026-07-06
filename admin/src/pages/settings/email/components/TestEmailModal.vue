<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useNotify } from '@/composables/useNotify'
import { testEmailSettings, testEmailTemplate, type EmailTemplateRow } from '@/queries/email'

// The PocketBase send-test modal: a leading "Transport test" option (value '')
// plus one option per registered template. Both are REAL sends — the server
// renders templates with their placeholder samples and the domain policy applies.
const props = defineProps<{ templates: EmailTemplateRow[]; preselect?: string }>()
const open = defineModel<boolean>('open', { required: true })

const { success, error: notifyError } = useNotify()
const selected = ref<string>('')
const to = ref('')
const sending = ref(false)

watch(open, (isOpen) => {
  if (isOpen) selected.value = props.preselect ?? ''
})

const options = computed(() => [
  { label: 'Transport test (no template)', value: '' },
  ...props.templates.map((t) => ({ label: t.label, value: t.key })),
])

const valid = computed(() => /.+@.+\..+/.test(to.value.trim()))

async function send() {
  if (!valid.value) return
  sending.value = true
  const address = to.value.trim()
  try {
    if (selected.value === '') {
      await testEmailSettings(address)
    } else {
      await testEmailTemplate(selected.value, address)
    }
    success('Test email sent', `Sent to ${address}.`)
    open.value = false
  } catch (e) {
    notifyError(e, 'Test email failed')
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Send test email" data-test="test-email-modal">
    <template #body>
      <div class="space-y-4">
        <URadioGroup
          v-model="selected"
          :items="options"
          value-key="value"
          data-test="test-email-template"
        />
        <UFormField label="To email address" required>
          <UInput
            v-model="to"
            type="email"
            placeholder="you@example.com"
            class="w-full"
            data-test="test-email-to"
            @keyup.enter="send"
          />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" color="neutral" label="Close" @click="open = false" />
          <UButton
            icon="i-lucide-send"
            label="Send"
            :loading="sending"
            :disabled="!valid"
            data-test="test-email-send"
            @click="send"
          />
        </div>
      </div>
    </template>
  </UModal>
</template>
