<script setup lang="ts">
import { ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { resetEmailTemplate, saveEmailPartial, type EmailPartialRow } from '@/queries/email'
import TemplateEditor from '@/pages/templates/components/TemplateEditor.vue'

// Body-only editing for layout furniture (layout/header/footer/styles). The
// styles partial edits as CSS — the clean injection point for restyling every
// email; the others edit as HTML.
const props = defineProps<{ partial: EmailPartialRow }>()
const emit = defineEmits<{ saved: []; reset: [] }>()

const notify = useNotify()
const body = ref(props.partial.body)
const dirty = ref(false)
const saving = ref(false)
const violations = ref<string[]>([])

watch(
  () => props.partial,
  (p) => {
    if (!dirty.value) body.value = p.body
  },
)
watch(body, () => {
  if (body.value !== props.partial.body) dirty.value = true
})

async function onSave() {
  saving.value = true
  violations.value = []
  try {
    await saveEmailPartial(props.partial.key, body.value)
    dirty.value = false
    notify.success('Partial saved')
    emit('saved')
  } catch (e) {
    const errors = (e as ApiError | null)?.body as { errors?: unknown } | undefined
    if (e instanceof ApiError && e.status === 422 && Array.isArray(errors?.errors)) {
      violations.value = (errors.errors as unknown[]).map(String)
    } else if (e instanceof ApiError && e.status === 422) {
      violations.value = [e.message]
    } else {
      notify.error(e, "Couldn't save the partial")
    }
  } finally {
    saving.value = false
  }
}

async function onReset() {
  if (!confirm(`Reset “${props.partial.label}” to the shipped default?`)) return
  try {
    await resetEmailTemplate(props.partial.key)
    dirty.value = false
    violations.value = []
    notify.success('Partial reset to default')
    emit('reset')
  } catch (e) {
    notify.error(e, "Couldn't reset the partial")
  }
}
</script>

<template>
  <div class="space-y-4 px-1 pb-2 pt-3">
    <p class="text-xs text-muted">{{ partial.description }}</p>

    <UFormField :label="partial.language === 'css' ? 'Styles (CSS)' : 'Body (HTML)'">
      <TemplateEditor v-model="body" :language="partial.language" />
    </UFormField>

    <ul
      v-if="violations.length"
      class="space-y-1 text-sm text-error"
      :data-test="`partial-violations-${partial.key}`"
    >
      <li v-for="v in violations" :key="v">{{ v }}</li>
    </ul>

    <div class="flex items-center justify-end gap-2">
      <UButton
        v-if="partial.overridden"
        variant="ghost"
        color="neutral"
        icon="i-lucide-rotate-ccw"
        label="Reset to default"
        :data-test="`partial-reset-${partial.key}`"
        @click="onReset"
      />
      <UButton
        icon="i-lucide-save"
        label="Save"
        :loading="saving"
        :data-test="`partial-save-${partial.key}`"
        @click="onSave"
      />
    </div>
  </div>
</template>
