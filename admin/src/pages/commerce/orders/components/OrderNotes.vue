<script setup lang="ts">
// Task 13d (admin-commerce-area plan, slice 3): append-only order notes. `GET /notes` is
// view-graded (AdminRouteCatalog: 'orders.notes.index' -> 'view'), so the notes list itself is
// ALWAYS visible on the order detail page regardless of `canManage` — mirrors how the Refunds
// list section on OrderDetail is unconditionally rendered. `POST /notes` is manage-graded
// ('orders.notes.store' -> 'manage'), so only the add-note form (never the list) is hidden for a
// view-only user — the one "mutation control" this component owns.
import { ref } from 'vue'
import { useOrderNotes, useCommerceOrderMutations, type CreateOrderNoteInput } from '@/queries/commerceOrders'
import { toApiError } from '@/api/errors'

const props = defineProps<{
  orderUuid: string
  canManage: boolean
}>()

const { data: notes, status } = useOrderNotes(() => props.orderUuid)
const { addNote } = useCommerceOrderMutations()

const bodyInput = ref('')
const submitError = ref<string | null>(null)

function fmtDateTime(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

// Every note this form submits is internal and non-notifying — task-13d brief ships no
// visibility/notify UI (CreateOrderNoteData.php's `notify: true` requires `visibility: 'customer'`
// server-side or the request 422s, so pinning both here can never trip that guard).
async function submit() {
  submitError.value = null
  const trimmed = bodyInput.value.trim()
  if (trimmed === '') {
    submitError.value = 'Enter a note.'
    return
  }

  const input: CreateOrderNoteInput = { body: trimmed, visibility: 'internal', notify: false }
  try {
    await addNote.mutateAsync({ uuid: props.orderUuid, input })
    bodyInput.value = ''
  } catch (e) {
    submitError.value = toApiError(e).message
  }
}
</script>

<template>
  <UCard :ui="{ body: notes && notes.length > 0 ? 'p-0' : undefined }">
    <template #header>
      <h3 class="text-sm font-medium">Notes</h3>
    </template>

    <div v-if="status === 'pending'" class="flex justify-center py-6" data-test="order-notes-loading">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
    </div>
    <UAlert
      v-else-if="status === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load notes"
      description="Something went wrong loading this order's notes. Try again."
      data-test="order-notes-error"
    />
    <UEmpty
      v-else-if="!notes || notes.length === 0"
      icon="i-lucide-sticky-note"
      title="No notes yet"
      data-test="order-notes-empty"
    />
    <ul v-else class="flex flex-col divide-y divide-default">
      <li v-for="n in notes" :key="n.uuid" data-test="order-note" class="flex flex-col gap-1 p-3 text-sm">
        <p class="text-default" data-test="order-note-body">{{ n.body }}</p>
        <div class="flex items-center gap-2 text-xs text-muted">
          <UBadge color="neutral" variant="subtle" size="sm">{{ n.visibility }}</UBadge>
          <span>{{ fmtDateTime(n.created_at) }}</span>
        </div>
      </li>
    </ul>

    <template v-if="canManage" #footer>
      <div class="flex flex-col gap-2">
        <UTextarea
          v-model="bodyInput"
          placeholder="Add an internal note…"
          :rows="2"
          class="w-full"
          data-test="order-note-input"
        />
        <UAlert
          v-if="submitError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="submitError"
          data-test="order-note-error"
        />
        <div class="flex justify-end">
          <UButton
            size="sm"
            data-test="order-note-submit"
            :loading="addNote.isLoading.value"
            @click="submit"
          >
            Add note
          </UButton>
        </div>
      </div>
    </template>
  </UCard>
</template>
