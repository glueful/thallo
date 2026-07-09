<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  useSubmissions,
  useSubmission,
  useSubmissionMutations,
  downloadSubmissionsCsv,
  type SubmissionStatus,
  type SubmissionSummary,
} from '@/queries/formSubmissions'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()

// Status filter (form_key filtering is available via the API for deep links; the UI
// filters by triage state, which is what an editor actually triages by).
const statusFilter = ref<'' | SubmissionStatus>('')
const filter = computed(() => ({ status: statusFilter.value }))
const { data: submissions } = useSubmissions(filter)
const rows = computed<SubmissionSummary[]>(() => submissions.value ?? [])

const selected = ref('')
const { data: detail } = useSubmission(selected, () => selected.value !== '')
const mutations = useSubmissionMutations()

// Selecting a row opens the detail pane AND marks it read (best-effort — a failed
// read must never block reading the message).
async function selectRow(row: SubmissionSummary): Promise<void> {
  selected.value = row.uuid
  if (row.status === 'unread') {
    try {
      await mutations.markRead.mutateAsync(row.uuid)
    } catch {
      // non-fatal: the message is still shown
    }
  }
}

/** Value rendered against its sealed label; checkbox booleans read as Yes/No. */
const detailFields = computed(() => {
  const d = detail.value
  if (!d) return []
  return d.fields_snapshot.map((f) => {
    const raw = d.values[f.key]
    return { key: f.key, label: f.label, value: typeof raw === 'boolean' ? (raw ? 'Yes' : 'No') : String(raw ?? '') }
  })
})

// Delete confirmation — destructive, so it names the target before removing.
const deleteOpen = ref(false)
const deleteUuid = ref('')
function openDelete(row: SubmissionSummary): void {
  deleteUuid.value = row.uuid
  deleteOpen.value = true
}
async function confirmDelete(): Promise<void> {
  try {
    await mutations.remove.mutateAsync(deleteUuid.value)
    if (selected.value === deleteUuid.value) selected.value = ''
    success('Submission deleted')
  } catch (e) {
    notifyError(e, 'Couldn’t delete the submission')
  }
  deleteOpen.value = false
}

const exporting = ref(false)
async function exportCsv(): Promise<void> {
  exporting.value = true
  try {
    await downloadSubmissionsCsv({ status: statusFilter.value })
  } catch (e) {
    notifyError(e, 'Couldn’t export submissions')
  } finally {
    exporting.value = false
  }
}

const filters: { label: string; value: '' | SubmissionStatus; test: string }[] = [
  { label: 'All', value: '', test: 'filter-all' },
  { label: 'Unread', value: 'unread', test: 'filter-unread' },
  { label: 'Read', value: 'read', test: 'filter-read' },
]
</script>

<template>
  <UDashboardPanel id="submissions" data-test="submissions-page">
    <template #header>
      <UDashboardNavbar title="Submissions">
        <template #right>
          <UButton
            icon="i-lucide-download"
            color="neutral"
            variant="subtle"
            :loading="exporting"
            data-test="submissions-export"
            @click="exportCsv"
          >
            Export CSV
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full min-h-0 flex-col gap-6 lg:flex-row">
        <aside class="w-full shrink-0 lg:w-96" data-test="submissions-list">
          <div class="mb-4 flex gap-1" role="group" aria-label="Filter by status">
            <UButton
              v-for="f in filters"
              :key="f.test"
              size="xs"
              :color="statusFilter === f.value ? 'primary' : 'neutral'"
              :variant="statusFilter === f.value ? 'solid' : 'ghost'"
              :aria-pressed="statusFilter === f.value ? 'true' : 'false'"
              :data-test="f.test"
              @click="() => { statusFilter = f.value }"
            >
              {{ f.label }}
            </UButton>
          </div>

          <p v-if="rows.length === 0" class="py-8 text-center text-sm text-muted" data-test="submissions-empty">
            No submissions yet.
          </p>

          <ul v-else class="flex flex-col gap-1">
            <li v-for="row in rows" :key="row.uuid">
              <button
                type="button"
                class="flex w-full items-start gap-2 rounded-md px-3 py-2 text-left hover:bg-elevated/50"
                :class="{ 'bg-elevated/60': selected === row.uuid }"
                data-test="submission-row"
                :data-status="row.status"
                :aria-current="selected === row.uuid ? 'true' : undefined"
                @click="() => selectRow(row)"
              >
                <span
                  v-if="row.status === 'unread'"
                  class="mt-1.5 size-2 shrink-0 rounded-full bg-primary"
                  data-test="submission-unread-dot"
                  aria-label="Unread"
                />
                <span v-else class="mt-1.5 size-2 shrink-0" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium" :class="{ 'font-semibold': row.status === 'unread' }">
                    {{ row.form_name }}
                  </span>
                  <span class="block truncate text-xs text-muted">{{ row.submitted_at }}</span>
                </span>
              </button>
            </li>
          </ul>
        </aside>

        <section class="min-w-0 flex-1" data-test="submissions-detail">
          <div
            v-if="!detail"
            class="flex h-full flex-col items-center justify-center gap-2 text-muted"
          >
            <UIcon name="i-lucide-inbox" class="size-8" />
            <p class="text-sm">Select a submission to read it.</p>
          </div>

          <div v-else class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">{{ detail.form_name }}</h2>
                <p class="text-xs text-muted">{{ detail.submitted_at }}</p>
              </div>
              <UButton
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                size="sm"
                data-test="submission-delete-open"
                @click="() => openDelete(detail as SubmissionSummary)"
              >
                Delete
              </UButton>
            </div>

            <dl class="grid gap-3">
              <div v-for="f in detailFields" :key="f.key" class="grid gap-0.5">
                <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ f.label }}</dt>
                <dd class="whitespace-pre-wrap break-words" data-test="submission-value">{{ f.value }}</dd>
              </div>
            </dl>

            <p v-if="detail.source_url" class="text-xs text-muted">
              Submitted from
              <span class="font-mono">{{ detail.source_url }}</span>
            </p>
          </div>
        </section>
      </div>

      <!-- Delete confirmation (teleports). MUST live inside #body so UDashboardPanel renders it. -->
      <UModal v-model:open="deleteOpen" title="Delete submission">
        <template #body>
          <p class="text-sm text-muted">
            This permanently deletes the submission. This can’t be undone.
          </p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton color="neutral" variant="ghost" @click="() => { deleteOpen = false }">Cancel</UButton>
            <UButton color="error" data-test="submission-delete" @click="confirmDelete">Delete</UButton>
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
