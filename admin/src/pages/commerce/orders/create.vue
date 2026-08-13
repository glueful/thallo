<script setup lang="ts">
// Task 14 (admin-order-creation): the walk-in order draft workspace.
//
// Route custody (task brief, binding): `/commerce/orders/create?draft={uuid}` — absent uuid ⇒
// create ONE draft then `router.replace()` the uuid; present ⇒ load only. `onMounted` runs
// exactly once per mount, so a refresh (which always arrives with `?draft=` already in the URL,
// since the replace above rewrites the address bar) and a "Resume" link (which points straight at
// an existing draft's uuid) both take the "present" branch identically — neither ever creates a
// second draft.
//
// Idempotency-key custody (task brief, binding) lives in `commerceDrafts.ts`
// (`getOrCreateFinalizeIdempotencyKey`/`clearFinalizeIdempotencyKeys`) — this page is the ONE call
// site for both: minted lazily right before a finalize attempt (scoped to the draft's CURRENT
// revision, so a revision bump — from any mutation below — naturally rotates it), reused across
// retries at the same revision, and cleared only after a CONFIRMED finalize or cancel.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  useCommerceDraft,
  useCommerceDraftMutations,
  createDraft,
  finalizeDraft,
  getOrCreateFinalizeIdempotencyKey,
  clearFinalizeIdempotencyKeys,
  dropFinalizeIdempotencyKey,
} from '@/queries/commerceDrafts'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney } from '@/composables/useMoney'
import { toApiError, apiErrorDetails } from '@/api/errors'
import DraftCustomerCard from './components/DraftCustomerCard.vue'
import DraftFulfillmentCard from './components/DraftFulfillmentCard.vue'
import DraftLineItemsCard from './components/DraftLineItemsCard.vue'

const route = useRoute()
const router = useRouter()

const { data: meta } = useCommerceMeta()
const canAttachUser = computed(() => meta.value?.can_attach_user ?? false)

const initialDraftUuid = typeof route.query.draft === 'string' ? route.query.draft : null
const draftUuid = ref<string | null>(initialDraftUuid)
const creating = ref(false)
const createError = ref<string | null>(null)

onMounted(async () => {
  if (draftUuid.value) return
  creating.value = true
  createError.value = null
  try {
    const created = await createDraft({})
    draftUuid.value = created.uuid
    router.replace({ query: { ...route.query, draft: created.uuid } })
  } catch (e) {
    createError.value = toApiError(e).message
  } finally {
    creating.value = false
  }
})

const {
  data: draft,
  status: draftStatus,
  refetch: refetchDraft,
} = useCommerceDraft(
  () => draftUuid.value ?? '',
  () => !!draftUuid.value,
)

const { update, recalculate, cancel } = useCommerceDraftMutations()
const { format } = useMoney()
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

// ── Discount code ────────────────────────────────────────────────────────────

const discountCode = ref('')
watch(
  () => draft.value?.uuid,
  () => {
    discountCode.value = draft.value?.discount_code ?? ''
  },
  { immediate: true },
)
const discountError = ref<string | null>(null)

async function applyDiscount() {
  if (!draft.value) return
  discountError.value = null
  try {
    await update.mutateAsync({
      uuid: draft.value.uuid,
      input: {
        discount_code: discountCode.value.trim() === '' ? null : discountCode.value.trim(),
        expected_revision: draft.value.draft_revision,
      },
    })
  } catch (e) {
    const err = toApiError(e)
    discountError.value = err.fieldErrors.discount_code ?? err.message
  }
}

async function doRecalculate() {
  if (!draft.value) return
  try {
    await recalculate.mutateAsync({ uuid: draft.value.uuid, expectedRevision: draft.value.draft_revision })
  } catch {
    // Recalculate is forgiving server-side (it drops/refreshes whatever no longer resolves rather
    // than failing outright) — a rejection here just means the draft is now more stale than a
    // recalculate alone can fix; the finalize attempt (and its own conflict rendering) is the
    // strict authority, so no separate error surface is needed for this action.
  }
}

// ── Cancel (inline confirm — mirrors OrderActions.vue's established confirm-panel pattern) ────

const cancelPending = ref(false)
const cancelError = ref<string | null>(null)

async function confirmCancel() {
  if (!draft.value) return
  cancelError.value = null
  try {
    const uuid = draft.value.uuid
    await cancel.mutateAsync(uuid)
    // Custody cleared BEFORE navigation (task brief, binding): every idempotency key this draft
    // ever minted is gone, and this page's own knowledge of the uuid is dropped, before we leave.
    clearFinalizeIdempotencyKeys(uuid)
    draftUuid.value = null
    cancelPending.value = false
    await router.push('/commerce/orders')
  } catch (e) {
    cancelError.value = toApiError(e).message
  }
}

// ── Finalize + conflict classification (task brief, binding) ──────────────────────────────────

interface DraftLineConflict {
  line_uuid: string
  variant_uuid: string
  sku: string
  product_name: string
  quantity: number
  reason: string
  unit_price?: number
  current_unit_price?: number | null
  currency?: string | null
  available?: number
}

type FinalizeConflict =
  | { type: 'line_conflicts'; lines: DraftLineConflict[] }
  | { type: 'stale_revision' | 'currency' | 'idempotency_key' | 'not_draft' | 'shipping_method' | 'discount' }
  | { type: 'error'; message: string }

// Review fix (round 1, minor): the closed per-line reasons split into two genuinely different
// remedies. `drift`/`stock`/`currency` are snapshot problems — a recalculate can refresh the price
// or re-check availability and may clear the conflict outright. `unavailable`/`digital`/
// `marketplace` are NOT snapshot problems: no recalculate will ever make a digital or
// marketplace-seller line sellable in a walk-in order, or an unpublished/deleted one reappear —
// the only remedy is removing the line (already available in the Items card above). Offering
// "Refresh prices" for those was actively misleading.
const RECALCULATE_FIXABLE_REASONS = new Set(['drift', 'stock', 'currency'])
const LINE_CONFLICT_LABELS: Record<string, string> = {
  drift: 'Price or add-on pricing changed since this line was added.',
  stock: 'Not enough stock available.',
  currency: 'No longer priced in this draft’s currency.',
  unavailable: 'No longer available.',
  digital: 'Digital product — cannot be sold in a walk-in order.',
  marketplace: 'Marketplace-seller product — cannot be sold in a walk-in order.',
}
function lineConflictLabel(reason: string): string {
  return LINE_CONFLICT_LABELS[reason] ?? reason
}

const finalizing = ref(false)
const finalizeConflict = ref<FinalizeConflict | null>(null)
// Vue's template compiler does not narrow a ref's union type across a v-else-if chain, so the
// error message is read through a computed instead of relying on template-level narrowing.
const finalizeErrorMessage = computed(() => {
  const conflict = finalizeConflict.value
  return conflict && conflict.type === 'error' ? conflict.message : ''
})

/** True only when at least one conflicting line has a reason recalculate can actually help with —
 * the "Refresh prices" action is hidden entirely when every conflict is remove-only. */
const hasFixableLineConflicts = computed(() => {
  const conflict = finalizeConflict.value
  if (!conflict || conflict.type !== 'line_conflicts') return false
  return conflict.lines.some((l) => RECALCULATE_FIXABLE_REASONS.has(l.reason))
})

/** True when at least one conflicting line can ONLY be resolved by removing it. */
const hasRemoveOnlyLineConflicts = computed(() => {
  const conflict = finalizeConflict.value
  if (!conflict || conflict.type !== 'line_conflicts') return false
  return conflict.lines.some((l) => !RECALCULATE_FIXABLE_REASONS.has(l.reason))
})

async function finalize() {
  if (!draft.value) return
  finalizeConflict.value = null
  finalizing.value = true
  const uuid = draft.value.uuid
  const revision = draft.value.draft_revision
  try {
    // Reused across ambiguous failures/reloads at this SAME revision; rotates automatically once
    // the revision above changes (a fresh call after any successful mutation reads a new value).
    // Minted INSIDE the try (review fix, round 1, Important): sessionStorage can throw (Safari
    // private browsing, quota, policy) and this call used to sit before the try — a throw there
    // escaped both `catch` and `finally`, wedging `finalizing` at true forever with no rendered
    // error. `getOrCreateFinalizeIdempotencyKey` itself is now storage-throw-safe too (see
    // commerceDrafts.ts), so this is defense in depth, not the only guard.
    const idempotencyKey = getOrCreateFinalizeIdempotencyKey(uuid, revision)
    const finalized = await finalizeDraft(uuid, revision, idempotencyKey)
    clearFinalizeIdempotencyKeys(uuid)
    draftUuid.value = null
    await router.push(`/commerce/orders/${finalized.uuid}`)
  } catch (e) {
    const details = apiErrorDetails(e)
    const conflict = typeof details?.conflict === 'string' ? details.conflict : null
    if (conflict === 'line_conflicts') {
      finalizeConflict.value = {
        type: 'line_conflicts',
        lines: Array.isArray(details?.lines) ? (details.lines as DraftLineConflict[]) : [],
      }
    } else if (conflict === 'idempotency_key') {
      // Review fix (round 1, Important): the server has confirmed THIS key is already bound to a
      // different request — retrying with the identical stored key would 409 forever. The
      // documented remedy is a fresh key, so drop the stored one for this exact (draft, revision)
      // now; the next finalize attempt at this revision mints a new one.
      dropFinalizeIdempotencyKey(uuid, revision)
      finalizeConflict.value = { type: 'idempotency_key' }
    } else if (
      conflict === 'stale_revision' ||
      conflict === 'currency' ||
      conflict === 'not_draft' ||
      conflict === 'shipping_method' ||
      conflict === 'discount'
    ) {
      finalizeConflict.value = { type: conflict }
    } else {
      // Ambiguous network failure, or a 422 validation message (empty draft, missing delivery
      // address/shipping method) — the idempotency key is intentionally NOT cleared here, so a
      // retry at the same revision reuses it.
      finalizeConflict.value = { type: 'error', message: toApiError(e).message }
    }
  } finally {
    finalizing.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-order-create">
    <template #header>
      <UDashboardNavbar title="New order">
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
      <div v-if="creating" class="flex justify-center py-10" data-test="draft-creating">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="createError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="createError"
        data-test="draft-create-error"
      />

      <div v-else-if="draftStatus === 'pending'" class="flex justify-center py-10" data-test="draft-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="draftStatus === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load this draft"
        description="Something went wrong loading this draft order. Try again."
        data-test="draft-error"
      />

      <div v-else-if="draft" class="flex flex-col gap-6" data-test="draft-workspace">
        <DraftCustomerCard :draft="draft" :can-attach-user="canAttachUser" />
        <DraftFulfillmentCard :draft="draft" />
        <DraftLineItemsCard :draft="draft" />

        <UCard data-test="draft-summary-card">
          <template #header>
            <h3 class="text-sm font-medium">Summary</h3>
          </template>

          <div class="flex flex-col gap-4">
            <UFormField label="Discount code" :error="discountError ?? undefined">
              <div class="flex gap-2">
                <UInput v-model="discountCode" class="w-full" data-test="draft-discount-code" />
                <UButton :loading="update.isLoading.value" data-test="draft-discount-apply" @click="applyDiscount">
                  Apply
                </UButton>
              </div>
            </UFormField>

            <div class="flex items-center justify-between gap-2 rounded-md border border-default p-3" data-test="draft-totals-badge">
              <div class="text-sm">
                <p class="text-muted">Estimated total (recalculate for the latest pricing)</p>
                <p class="text-lg font-semibold text-default">{{ money(draft.grand_total) }}</p>
              </div>
              <UButton
                color="neutral"
                variant="outline"
                :loading="recalculate.isLoading.value"
                data-test="draft-recalculate"
                @click="doRecalculate"
              >
                Recalculate
              </UButton>
            </div>

            <div
              v-if="finalizeConflict"
              class="rounded-md border border-error p-3 text-sm"
              data-test="draft-finalize-conflict"
            >
              <template v-if="finalizeConflict.type === 'line_conflicts'">
                <p class="mb-2 font-medium">Some items changed since this draft was started.</p>
                <ul class="mb-2 flex flex-col gap-1">
                  <li v-for="l in finalizeConflict.lines" :key="l.line_uuid" data-test="draft-line-conflict-row">
                    <span>{{ l.product_name }} ({{ l.sku }}) — {{ lineConflictLabel(l.reason) }}</span>
                    <span v-if="l.reason === 'stock'" data-test="draft-line-conflict-available">
                      Available: {{ l.available }}
                    </span>
                  </li>
                </ul>
                <!-- unavailable/digital/marketplace lines have NO price/snapshot to refresh — the
                     only remedy is removing them (Items card above already has that control), so
                     this hint replaces "Refresh prices" for those rather than sitting alongside a
                     button that would do nothing for them. -->
                <p v-if="hasRemoveOnlyLineConflicts" class="mb-2" data-test="draft-line-conflict-remove-hint">
                  Remove the affected line(s) above (Items) to continue.
                </p>
                <UButton
                  v-if="hasFixableLineConflicts"
                  size="sm"
                  data-test="draft-conflict-refresh-prices"
                  @click="doRecalculate"
                >
                  Refresh prices
                </UButton>
              </template>

              <template v-else-if="finalizeConflict.type === 'stale_revision'">
                <p class="mb-2">This draft changed elsewhere. Reload it to see the latest before finalizing.</p>
                <UButton size="sm" data-test="draft-conflict-reload" @click="() => { void refetchDraft() }">
                  Reload draft
                </UButton>
              </template>

              <template v-else-if="finalizeConflict.type === 'not_draft'">
                <p class="mb-2">This draft is no longer open — it may already be finalized or canceled.</p>
                <UButton size="sm" data-test="draft-conflict-reload" @click="() => { void refetchDraft() }">
                  Reload draft
                </UButton>
              </template>

              <template v-else-if="finalizeConflict.type === 'currency'">
                <p class="mb-2">The store currency changed since this draft was started. Cancel this draft and start a new order.</p>
                <UButton size="sm" color="error" data-test="draft-conflict-cancel" @click="cancelPending = true">
                  Cancel draft
                </UButton>
              </template>

              <template v-else-if="finalizeConflict.type === 'idempotency_key'">
                <p data-test="draft-conflict-idempotency">
                  This finalize attempt conflicts with a previous one for this draft. Reload and try again.
                </p>
              </template>

              <template v-else-if="finalizeConflict.type === 'shipping_method' || finalizeConflict.type === 'discount'">
                <p data-test="draft-finalize-error">
                  {{
                    finalizeConflict.type === 'shipping_method'
                      ? 'The selected shipping method is no longer available. Choose another.'
                      : 'The discount code is no longer valid. Remove it or choose another.'
                  }}
                </p>
              </template>

              <template v-else>
                <p data-test="draft-finalize-error">{{ finalizeErrorMessage }}</p>
              </template>
            </div>

            <div v-if="cancelError" data-test="draft-cancel-error" class="text-sm text-error">
              {{ cancelError }}
            </div>

            <div v-if="cancelPending" class="rounded-md border border-error p-3 text-sm" data-test="draft-cancel-panel">
              <p>Cancel this draft? This can’t be undone.</p>
              <div class="mt-2 flex gap-2">
                <UButton size="xs" color="error" :loading="cancel.isLoading.value" data-test="draft-cancel-confirm" @click="confirmCancel">
                  Confirm cancel
                </UButton>
                <UButton size="xs" color="neutral" variant="ghost" data-test="draft-cancel-dismiss" @click="cancelPending = false">
                  Dismiss
                </UButton>
              </div>
            </div>

            <div class="flex justify-between gap-2">
              <UButton color="error" variant="outline" data-test="draft-cancel" @click="cancelPending = true">
                Cancel draft
              </UButton>
              <UButton color="primary" :loading="finalizing" data-test="draft-finalize" @click="finalize">
                Finalize order
              </UButton>
            </div>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
