<script setup lang="ts">
// Task 15 (admin-order-creation cycle 2, design spec §2.2/§2.3): the drafts LIST view — the ONE
// draft-inclusive listing anywhere in the admin SPA (the ordinary orders list stays draft-blind
// by construction, server-enforced). Two actions only:
//
//  - Resume: a plain navigation link to `/commerce/orders/create?draft={uuid}` — route custody
//    (Task 14, binding) means the workspace page LOADS that exact uuid rather than creating a new
//    one, so this view never itself calls `createDraft()`.
//  - Cancel: destructive, confirm-gated (mirrors `create.vue`'s own inline-confirm pattern for the
//    SAME action), then a list refresh so the canceled row disappears immediately rather than
//    waiting for the next natural refetch.
//
// `GET /orders/drafts` is 'view'-graded server-side (unlike every draft mutation, which is
// 'manage') — so the listing itself renders for any commerce-capable viewer, but Resume/Cancel
// stay `can_manage`-gated here, same principle as "Create order" on the orders list: a view-only
// user has nothing legal to do with either action anyway (the workspace's own mutations would
// 403, and cancel is a manage-graded endpoint outright).
import { computed, ref } from 'vue'
import { useDraftsList, useCommerceDraftMutations, type CommerceDraft } from '@/queries/commerceDrafts'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import TablePagination from '@/components/TablePagination.vue'

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

const page = ref(1)
const perPage = ref(25)
const filters = computed(() => ({ page: page.value, perPage: perPage.value }))
const { data, status, refetch } = useDraftsList(filters)
const rows = computed<CommerceDraft[]>(() => data.value?.drafts ?? [])

const { cancel } = useCommerceDraftMutations()
const { format } = useMoney()

// useMoney().format() throws until /commerce/meta resolves — same guard as every other money
// render site in the commerce area (order detail, orders table).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

function fmtDateTime(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function resumeHref(uuid: string): string {
  return `/commerce/orders/create?draft=${encodeURIComponent(uuid)}`
}

// ── Cancel: one shared confirm target — at most one row's confirm panel is open at a time
// (mirrors create.vue's own single-target `cancelPending` pattern for this identical action). ──

const cancelPendingUuid = ref<string | null>(null)
const cancelError = ref<string | null>(null)

function openCancelConfirm(uuid: string) {
  cancelPendingUuid.value = uuid
  cancelError.value = null
}

function dismissCancelConfirm() {
  cancelPendingUuid.value = null
  cancelError.value = null
}

async function confirmCancel() {
  if (!cancelPendingUuid.value) return
  cancelError.value = null
  const uuid = cancelPendingUuid.value
  try {
    await cancel.mutateAsync(uuid)
    cancelPendingUuid.value = null
    // Explicit list refresh (task brief, binding: "cancel with confirm dialog (then list
    // refresh)") — on top of `useCommerceDraftMutations()`'s own cache invalidation, so the
    // canceled row disappears immediately even in a host that doesn't share this page's cache.
    await refetch()
  } catch (e) {
    cancelError.value = toApiError(e).message
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-order-drafts">
    <template #header>
      <UDashboardNavbar title="Drafts">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/commerce/orders"
            aria-label="Back to orders"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="drafts-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load drafts"
        description="Something went wrong loading draft orders. Try again."
        data-test="drafts-error"
      />

      <UEmpty
        v-else-if="rows.length === 0"
        icon="i-lucide-file-clock"
        title="No draft orders"
        description="Walk-in orders started from the counter and not yet finalized will appear here."
        data-test="drafts-empty"
      />

      <template v-else>
        <ul class="flex flex-col divide-y divide-default" data-test="drafts-list">
          <li v-for="d in rows" :key="d.uuid" data-test="draft-row" class="flex flex-col gap-2 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-2">
                <!-- Drafts carry no order_number (migration 022 relaxed it to nullable for
                     exactly this row) — a placeholder stands in rather than a blank cell. -->
                <span class="font-medium text-default" data-test="draft-number">Draft</span>
                <UBadge color="neutral" variant="subtle" size="sm">{{ d.fulfillment_mode }}</UBadge>
              </div>
              <div class="flex items-center gap-2 text-sm text-muted">
                <span data-test="draft-created">Created {{ fmtDateTime(d.created_at) }}</span>
                <span class="text-dimmed">·</span>
                <span data-test="draft-updated">Updated {{ fmtDateTime(d.updated_at) }}</span>
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="text-sm">
                <span
                  data-test="draft-customer"
                  :class="{ 'italic text-muted': !d.email && !d.customer_name }"
                >
                  {{ d.customer_name ?? d.email ?? 'Walk-in customer' }}
                </span>
              </div>
              <!-- Advisory total only (task brief, binding): a pre-finalization figure that can
                   still change (recalculate, further line edits) — never treated as the order's
                   final truth the way the finalized-order detail's totals are.
                   Deliberately NO line-count cell: `AdminOrderDraftController::index()` (vendored
                   engine, out of this task's scope) calls `DraftOrderProjection::forAdmin($row)`
                   WITHOUT the `$lines` argument, so every listed draft's `lines` array is always
                   `[]` on this endpoint regardless of its real contents — `d.lines.length` here
                   would be a confidently WRONG "0 item(s)" for every real draft, not merely an
                   approximate one. Restore a count cell once the engine's list endpoint hydrates
                   `lines` (or exposes a line count some other way) — the wire genuinely doesn't
                   carry the information yet. -->
              <div class="flex items-center gap-2 text-sm text-muted">
                <span data-test="draft-total">{{ money(d.grand_total) }} (advisory)</span>
              </div>
            </div>

            <div v-if="canManage" class="flex flex-wrap items-center gap-2">
              <RouterLink
                :to="resumeHref(d.uuid)"
                data-test="draft-resume"
                class="inline-flex items-center gap-1.5 rounded-md border border-default px-2.5 py-1.5 text-sm font-medium text-default hover:bg-elevated"
              >
                <UIcon name="i-lucide-play" class="size-4" />
                Resume
              </RouterLink>
              <UButton
                color="error"
                variant="outline"
                size="sm"
                icon="i-lucide-ban"
                data-test="draft-cancel"
                @click="openCancelConfirm(d.uuid)"
              >
                Cancel
              </UButton>
            </div>

            <div
              v-if="cancelPendingUuid === d.uuid"
              class="rounded-md border border-error p-3 text-sm"
              data-test="draft-cancel-panel"
            >
              <p>Cancel this draft? This can’t be undone.</p>
              <UAlert
                v-if="cancelError"
                color="error"
                variant="subtle"
                icon="i-lucide-triangle-alert"
                :title="cancelError"
                data-test="draft-cancel-error"
                class="mt-2"
              />
              <div class="mt-2 flex gap-2">
                <UButton
                  size="xs"
                  color="error"
                  :loading="cancel.isLoading.value"
                  data-test="draft-cancel-confirm"
                  @click="confirmCancel"
                >
                  Confirm cancel
                </UButton>
                <UButton
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  data-test="draft-cancel-dismiss"
                  @click="dismissCancelConfirm"
                >
                  Dismiss
                </UButton>
              </div>
            </div>
          </li>
        </ul>

        <TablePagination
          v-if="(data?.total ?? 0) > 0"
          v-model:page="page"
          v-model:per-page="perPage"
          :total="data?.total ?? 0"
          label="drafts"
        />
      </template>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
