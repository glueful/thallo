<script setup lang="ts">
import { computed, ref } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useMoney } from '@/composables/useMoney'
import { useNotify } from '@/composables/useNotify'
import {
  isOrderNotDeletable,
  useCommerceOrderMutations,
  type CommerceOrder,
} from '@/queries/commerceOrders'

const props = withDefaults(
  defineProps<{
    rows: CommerceOrder[]
    status: 'pending' | 'error' | 'success' | 'idle'
    /** Manage grade (`/commerce/meta`'s `can_manage`). Gates the ONE destructive action below —
     * the artifact delete, whose route is manage-graded server-side. Defaults to false so a
     * caller that has not resolved the grade yet never offers a control the server would 403. */
    canManage?: boolean
  }>(),
  { canManage: false },
)

const { format } = useMoney()
const { error: notifyError } = useNotify()

const columns = computed<TableColumn<CommerceOrder>[]>(() => [
  { accessorKey: 'order_number', header: 'Order' },
  { accessorKey: 'email', header: 'Customer' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'fulfillment_status', header: 'Fulfillment' },
  { accessorKey: 'grand_total', header: 'Total' },
  { accessorKey: 'placed_at', header: 'Date' },
  { id: 'actions', header: 'Actions' },
])

// The order-detail page's own Print link composes the SAME `/commerce/orders/{uuid}/invoice`
// path (orders/[uuid]/index.vue's `invoiceHref`, mirrored verbatim by OrderStickyRail.vue) — kept
// as an equally trivial one-line local composition here rather than a shared import, mirroring
// this codebase's established per-file small-helper convention (see OrderStickyRail.vue's own
// note on `statusColor`). Never diverges from the other two: always `uuid` + `/invoice`, no
// alternate field. There is currently no print-availability gating by order status anywhere in
// the codebase — the detail page's Print link renders unconditionally for every order — so this
// action is likewise always enabled.
function invoiceHref(row: CommerceOrder): string {
  return `/commerce/orders/${row.uuid}/invoice`
}

function statusColor(s: string): 'success' | 'info' | 'warning' | 'error' | 'neutral' {
  switch (s) {
    case 'fulfilled':
      return 'success'
    case 'paid':
      return 'info'
    case 'pending_payment':
      return 'warning'
    case 'canceled':
      return 'error'
    case 'refunded':
      return 'neutral'
    default:
      return 'neutral'
  }
}

function fulfillmentColor(s: string): 'success' | 'info' | 'warning' | 'neutral' {
  switch (s) {
    case 'fulfilled':
      return 'success'
    case 'partial':
      return 'info'
    case 'unfulfilled':
      return 'warning'
    default:
      return 'neutral'
  }
}

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the table render (mirrors ProductForm.vue).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

function fmtDate(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}

// ── Draft-artifact delete (cleanup-train Task 9) ───────────────────────────────────────────────
//
// A row with NO order number never completed checkout: a number is issued at finalize, so there
// is no payment, no invoice and no receipt attached to it, and this list is draft-blind — the
// only numberless rows that reach it are canceled artifacts, exactly the shape the engine's
// `DELETE /orders/{uuid}/artifact` accepts (`order_number IS NULL AND status = 'canceled'`).
//
// The row gate is therefore the ABSENCE OF A NUMBER, and nothing else. A numbered row is a real
// order with real money history and can never be deleted — it does not render the control at all,
// rather than rendering a disabled one that would invite the question. The route is also
// manage-graded, so a view-only operator is never offered it either. The server stays
// authoritative either way: a row that stopped being an artifact between render and click is
// refused with its own typed 409, surfaced verbatim below rather than guessed at here.
function isDeletableArtifact(row: CommerceOrder): boolean {
  return props.canManage && row.order_number === null
}

const deleteTarget = ref<CommerceOrder | null>(null)
const deleteInFlight = ref(false)
const { deleteArtifact } = useCommerceOrderMutations()

function askDelete(row: CommerceOrder) {
  deleteTarget.value = row
}

async function confirmDelete() {
  const target = deleteTarget.value
  if (target === null || deleteInFlight.value) return
  deleteInFlight.value = true
  try {
    // Resolves for a 404 too — the row already being gone IS the outcome asked for, and the
    // mutation's own invalidation refreshes the list either way.
    await deleteArtifact.mutateAsync(target.uuid)
  } catch (e) {
    // The engine's ONE typed refusal (`order_not_deletable`) carries the remedy in its message
    // ("Cancel this draft before deleting it." / "…has been placed and can never be deleted.") —
    // surfaced verbatim, never restated in copy that could drift from the server's rule.
    notifyError(e, isOrderNotDeletable(e) ? 'This order can’t be deleted' : 'Couldn’t delete this order')
  } finally {
    deleteInFlight.value = false
    deleteTarget.value = null
  }
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="orders-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load orders"
    description="Something went wrong loading orders. Try again."
    data-test="orders-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-receipt"
    title="No orders"
    description="Orders will appear here once customers start checking out."
    data-test="orders-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template #order_number-cell="{ row }">
      <!-- A numberless row is still a real row with a detail page — it is named for what it is
           ("No number", muted) rather than left as a blank cell that reads as a rendering bug. -->
      <RouterLink
        :to="`/commerce/orders/${row.original.uuid}`"
        class="font-medium hover:underline"
        :class="row.original.order_number === null ? 'text-muted italic' : 'text-default'"
        data-test="order-row"
      >
        {{ row.original.order_number ?? 'No number' }}
      </RouterLink>
    </template>

    <template #email-cell="{ row }">
      <span class="text-sm" :class="{ 'text-muted italic': !row.original.email }">
        {{ row.original.email ?? 'Walk-in customer' }}
      </span>
    </template>

    <template #status-cell="{ row }">
      <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm" data-test="order-status">
        {{ row.original.status }}
      </UBadge>
    </template>

    <template #fulfillment_status-cell="{ row }">
      <UBadge
        :color="fulfillmentColor(row.original.fulfillment_status)"
        variant="subtle"
        size="sm"
        data-test="order-fulfillment"
      >
        {{ row.original.fulfillment_status }}
      </UBadge>
    </template>

    <template #grand_total-cell="{ row }">
      <span data-test="order-total">{{ money(row.original.grand_total) }}</span>
    </template>

    <template #placed_at-cell="{ row }">
      <span class="text-sm text-muted" data-test="order-date">
        {{ fmtDate(row.original.placed_at ?? row.original.created_at) }}
      </span>
    </template>

    <template #actions-cell="{ row }">
      <!-- Icon-only links, aria-labeled with a native `title` tooltip — a plain `<RouterLink>`
           styled as a compact ghost icon button, mirroring the order-number cell's own RouterLink
           (above) and the detail page's Print link (orders/[uuid]/index.vue's `order-print-link`)
           rather than `UButton`'s `to` prop: `UButton` resolves its link through Nuxt UI's `Link`
           wrapper, which only produces a real `href` via the REAL `RouterLink`'s scoped-slot data —
           this codebase's established `RouterLinkStub` (a plain non-scoped stub, used everywhere
           these tables are unit-tested) can't supply that, so a `UButton :to=...` renders hrefless
           in every existing spec. A direct `<RouterLink>` needs no scoped-slot data and stays
           testable exactly like the order-number cell already is. -->
      <div class="flex items-center justify-end gap-1">
        <RouterLink
          :to="`/commerce/orders/${row.original.uuid}`"
          aria-label="View order details"
          title="View order details"
          data-test="order-row-view"
          class="inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-default"
        >
          <UIcon name="i-lucide-eye" class="size-4" />
        </RouterLink>
        <RouterLink
          :to="invoiceHref(row.original)"
          target="_blank"
          rel="noopener"
          aria-label="Print receipt"
          title="Print receipt"
          data-test="order-row-print"
          class="inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-default"
        >
          <UIcon name="i-lucide-printer" class="size-4" />
        </RouterLink>
        <!-- The ONLY destructive control in this table, and it exists on numberless rows alone
             (see `isDeletableArtifact`). A button, not a link: it opens the confirmation and
             performs no request of its own. -->
        <button
          v-if="isDeletableArtifact(row.original)"
          type="button"
          aria-label="Delete this draft artifact"
          title="Delete this draft artifact"
          data-test="order-row-delete"
          class="inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-error"
          @click="askDelete(row.original)"
        >
          <UIcon name="i-lucide-trash-2" class="size-4" />
        </button>
      </div>
    </template>
  </UTable>

  <!-- Permanent, unrecoverable, and stated as such. The copy names the three things whose absence
       is what makes the deletion safe at all — no number, no payments, no invoices. -->
  <UModal
    :open="deleteTarget !== null"
    title="Delete this draft permanently"
    @update:open="(v: boolean) => { if (!v) deleteTarget = null }"
  >
    <template #body>
      <p class="text-sm" data-test="order-artifact-delete-dialog">
        This never-completed draft has no order number, payments, or invoices — delete permanently?
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          data-test="order-artifact-delete-dismiss"
          @click="deleteTarget = null"
        >
          Dismiss
        </UButton>
        <UButton
          color="error"
          data-test="order-artifact-delete-confirm"
          :loading="deleteInFlight"
          @click="confirmDelete"
        >
          Delete permanently
        </UButton>
      </div>
    </template>
  </UModal>
</template>
