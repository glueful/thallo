<script setup lang="ts">
import { computed, ref } from 'vue'
import { useWorkflowState, useWorkflowMutations, type WorkflowStateName } from '@/queries/workflow'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string; locale: string; enabled: boolean }>()
const { success, error: notifyError } = useNotify()

const { data } = useWorkflowState(
  () => props.uuid,
  () => props.locale,
  () => props.enabled,
)
const mutations = useWorkflowMutations(props.uuid, props.locale)

const state = computed<WorkflowStateName>(() => data.value?.state ?? 'draft')

const STATE_LABEL: Record<WorkflowStateName, string> = {
  draft: 'Draft',
  in_review: 'In review',
  approved: 'Approved',
  changes_requested: 'Changes requested',
}
const STATE_COLOR: Record<WorkflowStateName, 'neutral' | 'info' | 'success' | 'warning'> = {
  draft: 'neutral',
  in_review: 'info',
  approved: 'success',
  changes_requested: 'warning',
}

// The reviewer's most recent note — shown while the author is revising.
const lastNote = computed(() => {
  if (state.value !== 'changes_requested') return null
  return data.value?.history.find((t) => t.action === 'request_changes')?.note ?? null
})

// Request-changes needs a note; the form is an inline collapsible (portaled modals are
// untestable in jsdom — same reasoning as SeoPanel's inline sections).
const noteOpen = ref(false)
const note = ref('')

async function run(action: 'submit' | 'approve' | 'withdraw') {
  try {
    await mutations[action].mutateAsync(undefined)
    success('Workflow updated')
  } catch (e) {
    notifyError(e, 'Workflow action failed')
  }
}

function toggleNote(): void {
  noteOpen.value = !noteOpen.value
}

async function confirmRequestChanges() {
  if (note.value.trim() === '') return
  try {
    await mutations.requestChanges.mutateAsync(note.value.trim())
    note.value = ''
    noteOpen.value = false
    success('Changes requested')
  } catch (e) {
    notifyError(e, 'Workflow action failed')
  }
}
</script>

<template>
  <!-- A SECTION, not a card: the parent slots this into the Publishing card so review
       state and publish state share one editorial box. -->
  <div v-if="enabled" data-test="workflow-panel">
    <div class="flex items-center justify-between">
      <span class="text-sm font-medium">Review</span>
      <UBadge :color="STATE_COLOR[state]" variant="subtle" data-test="workflow-state">
        {{ STATE_LABEL[state] }}
      </UBadge>
    </div>

    <p
      v-if="lastNote"
      class="text-muted mt-3 border-l-2 pl-3 text-sm"
      data-test="workflow-last-note"
    >
      {{ lastNote }}
    </p>

    <div class="mt-4 flex flex-wrap gap-2">
      <UButton
        v-if="state === 'draft' || state === 'changes_requested'"
        size="sm"
        data-test="workflow-submit"
        :loading="mutations.submit.isLoading.value"
        @click="run('submit')"
      >
        Submit for review
      </UButton>

      <template v-if="state === 'in_review'">
        <UButton
          size="sm"
          color="success"
          data-test="workflow-approve"
          :loading="mutations.approve.isLoading.value"
          @click="run('approve')"
        >
          Approve
        </UButton>
        <UButton
          size="sm"
          color="warning"
          variant="outline"
          data-test="workflow-request-changes"
          @click="toggleNote"
        >
          Request changes
        </UButton>
        <UButton
          size="sm"
          color="neutral"
          variant="ghost"
          data-test="workflow-withdraw"
          :loading="mutations.withdraw.isLoading.value"
          @click="run('withdraw')"
        >
          Withdraw
        </UButton>
      </template>
    </div>

    <div v-if="noteOpen && state === 'in_review'" class="mt-3 space-y-2">
      <UTextarea
        v-model="note"
        :rows="2"
        placeholder="What needs to change? (required)"
        data-test="workflow-request-changes-note"
        class="w-full"
      />
      <UButton
        size="sm"
        color="warning"
        :disabled="note.trim() === ''"
        data-test="workflow-request-changes-confirm"
        :loading="mutations.requestChanges.isLoading.value"
        @click="confirmRequestChanges"
      >
        Send feedback
      </UButton>
    </div>
  </div>
</template>
