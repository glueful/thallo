<script setup lang="ts">
// Task 14 (admin-order-creation): the draft workspace's all-optional customer block — email,
// phone, name, and (only when `can_attach_user`) an existing-account picker. Every field is
// genuinely optional (Ruling 4: a walk-in draft may stay fully anonymous), so "Save" always sends
// a legal request even when every field is blank (each blank field becomes an explicit `null`,
// clearing whatever the draft previously held — never an empty-string placeholder).
import { ref, watch } from 'vue'
import { useCommerceDraftMutations, type CommerceDraft } from '@/queries/commerceDrafts'
import { toApiError } from '@/api/errors'
import DraftUserPicker from './DraftUserPicker.vue'

const props = defineProps<{
  draft: CommerceDraft
  canAttachUser: boolean
}>()

const { update } = useCommerceDraftMutations()

const email = ref('')
const phone = ref('')
const name = ref('')
const userUuid = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
// Review fix (round 1): a save can fail with NO field errors at all — a stale_revision/
// user_email_mismatch 409 (`DraftConflictException`), or a bare 5xx — and until now the card had
// no surface for that at all: the button just stopped loading with nothing visibly wrong. This
// carries the human message whenever the failure isn't (fully) explained by a field error.
const saveError = ref<string | null>(null)
const saved = ref(false)

function syncFromDraft() {
  email.value = props.draft.email ?? ''
  // `phone_display` is the operator's own preserved formatting — the natural seed for re-editing,
  // never `phone_normalized` (which would silently rewrite whatever they originally typed).
  phone.value = props.draft.phone_display ?? ''
  name.value = props.draft.customer_name ?? ''
  userUuid.value = props.draft.user_uuid
  fieldErrors.value = {}
  saveError.value = null
  saved.value = false
}

// Re-seed ONLY when switching to a genuinely different draft — a revision-driven refetch of the
// SAME draft (triggered by an unrelated mutation, e.g. adding a line) must never clobber
// in-progress, unsaved edits to these fields.
watch(() => props.draft.uuid, syncFromDraft, { immediate: true })

async function save() {
  fieldErrors.value = {}
  saveError.value = null
  saved.value = false
  try {
    await update.mutateAsync({
      uuid: props.draft.uuid,
      input: {
        email: email.value.trim() === '' ? null : email.value.trim(),
        // Posts the RAW input verbatim (task brief) — the server owns normalization/validation
        // (`DraftPhone::parse()`), never trimmed or reshaped here beyond "blank means clear".
        phone: phone.value === '' ? null : phone.value,
        customer_name: name.value.trim() === '' ? null : name.value.trim(),
        user_uuid: userUuid.value,
        expected_revision: props.draft.draft_revision,
      },
    })
    saved.value = true
  } catch (e) {
    const err = toApiError(e)
    fieldErrors.value = err.fieldErrors
    // A field error already explains itself inline next to its input; a message-level banner on
    // top of that would be redundant. Only surface the banner when there is nothing field-shaped
    // to show (a conflict, a network failure, a bare 5xx).
    saveError.value = Object.keys(err.fieldErrors).length === 0 ? err.message : null
  }
}
</script>

<template>
  <UCard data-test="draft-customer-card">
    <template #header>
      <h3 class="text-sm font-medium">Customer (optional)</h3>
    </template>

    <div class="flex flex-col gap-4">
      <UFormField label="Email" name="email" :error="fieldErrors.email">
        <UInput v-model="email" type="email" placeholder="customer@example.com" class="w-full" data-test="draft-customer-email" />
      </UFormField>

      <UFormField label="Phone" name="phone" :error="fieldErrors.phone">
        <UInput v-model="phone" placeholder="+1 555 010 9999" class="w-full" data-test="draft-customer-phone" />
      </UFormField>

      <UFormField label="Name" name="customer_name" :error="fieldErrors.customer_name">
        <UInput v-model="name" placeholder="Walk-in customer name" class="w-full" data-test="draft-customer-name" />
      </UFormField>

      <div v-if="canAttachUser" class="flex flex-col gap-2">
        <span class="text-xs font-medium uppercase text-muted">Attach an existing account</span>
        <DraftUserPicker v-model="userUuid" />
        <UAlert
          v-if="fieldErrors.user_uuid"
          color="error"
          variant="subtle"
          :title="fieldErrors.user_uuid"
          data-test="draft-customer-user-error"
        />
      </div>

      <UAlert
        v-if="saveError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="saveError"
        data-test="draft-customer-save-error"
      />

      <div class="flex items-center gap-2">
        <UButton :loading="update.isLoading.value" data-test="draft-customer-save" @click="save">
          Save customer
        </UButton>
        <span v-if="saved" class="text-sm text-success" data-test="draft-customer-saved">Saved</span>
      </div>
    </div>
  </UCard>
</template>
