<script setup lang="ts">
import { ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import {
  resetEmailTemplate,
  saveEmailTemplate,
  type EmailTemplateRow as TemplateData,
} from '@/queries/email'
import TemplateEditor from '@/pages/templates/components/TemplateEditor.vue'

const props = defineProps<{ template: TemplateData }>()
const emit = defineEmits<{ saved: []; reset: [] }>()

const notify = useNotify()
/** `}}` inside a template interpolation terminates it — build the chip text in JS. */
const chip = (name: string) => '{{' + name + '}}'
const subject = ref(props.template.subject)
const body = ref(props.template.body)
const dirty = ref(false)
const saving = ref(false)
const violations = ref<string[]>([])

// Re-seed from the server row only while clean (the dirty-guard idiom).
watch(
  () => props.template,
  (t) => {
    if (!dirty.value) {
      subject.value = t.subject
      body.value = t.body
    }
  },
)
watch([subject, body], () => {
  if (subject.value !== props.template.subject || body.value !== props.template.body) {
    dirty.value = true
  }
})

async function onSave() {
  saving.value = true
  violations.value = []
  try {
    await saveEmailTemplate(props.template.key, { subject: subject.value, body: body.value })
    dirty.value = false
    notify.success('Template saved')
    emit('saved')
  } catch (e) {
    const errors = (e as ApiError | null)?.body as { errors?: unknown } | undefined
    if (e instanceof ApiError && e.status === 422 && Array.isArray(errors?.errors)) {
      violations.value = (errors.errors as unknown[]).map(String)
    } else if (e instanceof ApiError && e.status === 422) {
      violations.value = [e.message]
    } else {
      notify.error(e, "Couldn't save the template")
    }
  } finally {
    saving.value = false
  }
}

async function onReset() {
  if (!confirm(`Reset “${props.template.label}” to its default subject and body?`)) return
  try {
    await resetEmailTemplate(props.template.key)
    dirty.value = false
    violations.value = []
    notify.success('Template reset to default')
    emit('reset')
  } catch (e) {
    notify.error(e, "Couldn't reset the template")
  }
}
</script>

<template>
  <div class="space-y-4 px-1 pb-2 pt-3">
    <UFormField label="Subject">
      <UInput
        v-model="subject"
        class="w-full font-mono"
        :data-test="`template-subject-${template.key}`"
      />
    </UFormField>
    <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
      <span>Placeholders:</span>
      <UBadge
        v-for="p in template.placeholders"
        :key="p.name"
        color="neutral"
        variant="subtle"
        class="font-mono"
        :title="p.description"
        :data-test="`placeholder-chip-${p.name}`"
      >
        {{ chip(p.name) }}
      </UBadge>
    </div>

    <UFormField label="Body (HTML)">
      <TemplateEditor v-model="body" language="html" />
    </UFormField>

    <ul
      v-if="violations.length"
      class="space-y-1 text-sm text-error"
      :data-test="`template-violations-${template.key}`"
    >
      <li v-for="v in violations" :key="v">{{ v }}</li>
    </ul>

    <div class="flex items-center justify-end gap-2">
      <UButton
        v-if="template.overridden"
        variant="ghost"
        color="neutral"
        icon="i-lucide-rotate-ccw"
        label="Reset to default"
        :data-test="`template-reset-${template.key}`"
        @click="onReset"
      />
      <UButton
        icon="i-lucide-save"
        label="Save"
        :loading="saving"
        :data-test="`template-save-${template.key}`"
        @click="onSave"
      />
    </div>
  </div>
</template>
