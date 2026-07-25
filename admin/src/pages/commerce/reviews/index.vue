<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  useCommerceReviews,
  useCommerceReviewMutations,
  REVIEW_STATUSES,
  type CommerceReview,
  type CommerceReviewBulkAction,
} from '@/queries/commerceReviews'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'
import ReviewsTable from './components/ReviewsTable.vue'

const { success, warning, error: notifyError } = useNotify()

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

// ── Filters ──────────────────────────────────────────────────────────────────
// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so the "All" option uses a non-empty sentinel, translated to `undefined` (no
// filter) at the query boundary (mirrors the products/orders/discounts list pages).
const ALL = 'all'
const statusFilter = ref(ALL)
const page = ref(1)
const perPage = ref(25)

const statusFilterItems = [
  { label: 'All statuses', value: ALL },
  ...REVIEW_STATUSES.map((s) => ({ label: s, value: s })),
]

const filters = computed(() => ({
  status: statusFilter.value === ALL ? undefined : statusFilter.value,
  page: page.value,
  perPage: perPage.value,
}))

const { data, status: queryStatus } = useCommerceReviews(filters)
const rows = computed<CommerceReview[]>(() => data.value?.reviews ?? [])

const { approve, spam, remove, bulk } = useCommerceReviewMutations()

// ── Row-level moderation: approve / spam are direct (reversible); delete requires confirmation ──

async function onApprove(review: CommerceReview) {
  try {
    await approve.mutateAsync(review.uuid)
    success('Review approved', `“${review.author_name}”’s review is now live.`)
  } catch (e) {
    notifyError(e, 'Couldn’t approve review')
  }
}

async function onSpam(review: CommerceReview) {
  try {
    await spam.mutateAsync(review.uuid)
    success('Review marked as spam', `“${review.author_name}”’s review was hidden.`)
  } catch (e) {
    notifyError(e, 'Couldn’t mark review as spam')
  }
}

const pendingDelete = ref<CommerceReview | null>(null)
async function confirmDelete() {
  const review = pendingDelete.value
  if (!review) return
  try {
    await remove.mutateAsync(review.uuid)
    success('Review deleted', `“${review.author_name}”’s review was removed.`)
    selected.value = selected.value.filter((u) => u !== review.uuid)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete review')
    pendingDelete.value = null
  }
}

// ── Selection + bulk moderation ──────────────────────────────────────────────
const selected = ref<string[]>([])
function toggleSelect(uuid: string) {
  selected.value = selected.value.includes(uuid)
    ? selected.value.filter((u) => u !== uuid)
    : [...selected.value, uuid]
}
function selectAllVisible() {
  const visible = rows.value.map((r) => r.uuid)
  const allSelected = visible.length > 0 && visible.every((u) => selected.value.includes(u))
  selected.value = allSelected ? [] : visible
}

async function applyBulk(action: CommerceReviewBulkAction) {
  if (selected.value.length === 0) return
  const uuids = selected.value
  try {
    const result = await bulk.mutateAsync({ action, uuids })
    if (result.failed.length > 0) {
      warning(
        'Some reviews couldn’t be updated',
        `${result.applied.length} updated, ${result.failed.length} failed.`,
      )
    } else {
      success('Reviews updated', `${result.applied.length} review(s) ${bulkPastTense(action)}.`)
    }
    selected.value = []
  } catch (e) {
    notifyError(e, 'Couldn’t update reviews')
  }
}

function bulkPastTense(action: CommerceReviewBulkAction): string {
  switch (action) {
    case 'approve':
      return 'approved'
    case 'spam':
      return 'marked as spam'
    case 'delete':
      return 'deleted'
  }
}

// Bulk delete is the one irreversible bulk action — requires the same explicit confirmation as a
// single-row delete, unlike bulk approve/spam (both reversible transitions).
const showBulkDeleteConfirm = ref(false)
async function confirmBulkDelete() {
  showBulkDeleteConfirm.value = false
  await applyBulk('delete')
}
</script>

<template>
  <UDashboardPanel id="commerce-reviews">
    <template #header>
      <UDashboardNavbar title="Reviews">
        <template #right>
          <USelect v-model="statusFilter" :items="statusFilterItems" class="w-36" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="canManage && selected.length > 0"
        class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-default p-3"
        data-test="review-bulk-bar"
      >
        <span class="text-sm text-muted">{{ selected.length }} selected</span>
        <UButton size="xs" color="neutral" variant="ghost" label="Clear" @click="() => { selected = [] }" />
        <UButton
          size="sm"
          color="success"
          label="Approve"
          icon="i-lucide-check"
          data-test="review-bulk-approve"
          :loading="bulk.isLoading.value"
          @click="applyBulk('approve')"
        />
        <UButton
          size="sm"
          color="warning"
          label="Mark as spam"
          icon="i-lucide-flag"
          data-test="review-bulk-spam"
          :loading="bulk.isLoading.value"
          @click="applyBulk('spam')"
        />
        <UButton
          size="sm"
          color="error"
          label="Delete"
          icon="i-lucide-trash-2"
          data-test="review-bulk-delete"
          :loading="bulk.isLoading.value"
          @click="() => { showBulkDeleteConfirm = true }"
        />
      </div>

      <div v-if="canManage && rows.length > 0" class="mb-2">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          data-test="review-select-all"
          @click="selectAllVisible"
        >
          {{ rows.every((r) => selected.includes(r.uuid)) ? 'Clear selection' : 'Select all on page' }}
        </UButton>
      </div>

      <ReviewsTable
        :rows="rows"
        :status="queryStatus"
        :can-manage="canManage"
        :selected="selected"
        :approve-loading="approve.isLoading.value"
        :spam-loading="spam.isLoading.value"
        @toggle-select="toggleSelect"
        @approve-request="onApprove"
        @spam-request="onSpam"
        @delete-request="(row) => { pendingDelete = row }"
      />

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="reviews"
      />
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete !== null"
    title="Delete review"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete the review by <span class="text-default">“{{ pendingDelete?.author_name }}”</span>? This can’t be
        undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="review-delete-confirm"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>

  <UModal
    :open="showBulkDeleteConfirm"
    title="Delete reviews"
    @update:open="(v: boolean) => { showBulkDeleteConfirm = v }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">{{ selected.length }}</span> selected review(s)? This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="bulk.isLoading.value"
          @click="() => { showBulkDeleteConfirm = false }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="review-bulk-delete-confirm"
          :loading="bulk.isLoading.value"
          @click="confirmBulkDelete"
        />
      </div>
    </template>
  </UModal>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
