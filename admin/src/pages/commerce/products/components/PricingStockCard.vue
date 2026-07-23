<script setup lang="ts">
// Single-page product editor plan, Task C7: the Pricing & stock card — spec §5.3's progressive
// disclosure. A SIMPLE product (exactly one variant AND no option axes on `product.options` — see
// `CommerceProduct.options`'s own docblock in commerceCatalog.ts) gets a compact card: the single
// variant's SKU/price/compare-at inline, plus (when tracked) its current quantity and an inline
// adjust control. Anything else (2+ variants, OR a single variant whose product already defines
// option axes) renders the full `VariantsPanel` table directly, now with a real per-variant stock
// Quantity column.
//
// "Add more variants" is UI-ONLY expansion (spec §5.3, pinned): it flips a local `expanded` flag
// to reveal the full table — no mutation fires. The product only actually BECOMES multi-variant
// once a second variant is successfully created through `VariantsPanel`'s own existing endpoint, at
// which point `isSimple` itself goes false and the table renders unconditionally (the `expanded`
// flag becomes moot). Collapsing back to the compact card is equally free while still simple.
//
// Coordinator involvement is narrow, per the task brief: this card registers ONLY the 'stock'
// section (so stock quantities refresh after ANY product-scoped mutation elsewhere on the page,
// not just this card's own saves) and calls `afterMutation()` after its own two mutations (compact
// save, compact stock adjust). Stock has NO local draft — the adjust form is transient input, not a
// draft of server state — so `reconcileRemote` is identical to `adoptRemote`: there is nothing to
// preserve against a dirty local edit, because this section is never dirty from the coordinator's
// point of view (`stockNeverDirty` stays `false` always).
//
// This card owns the ONE stock-section subscription for the whole product editor and passes
// `stockItems`/`stockUnavailable` down to `VariantsPanel` as plain props — `VariantsPanel` stays
// presentational about stock (no query of its own), matching the brief's explicit steer.
import { computed, inject, nextTick, onUnmounted, reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceProductMutations,
  type CommerceProduct,
  type CommerceVariant,
} from '@/queries/commerceCatalog'
import {
  useProductStock,
  type SectionEnvelope,
  type VariantStock,
} from '@/queries/commerceProductSections'
import { useMoney } from '@/composables/useMoney'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import VariantsPanel from './VariantsPanel.vue'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()
const emit = defineEmits<{ state: [SectionState] }>()

const { success, error: notifyError } = useNotify()
const { format } = useMoney()
const { updateVariant, stockAdjust } = useCommerceProductMutations()
const coordinator = inject(ProductRevisionCoordinatorKey, null)

// Same emit-once wiring rationale as ProductForm.vue/MediaPanel.vue — this card's whole
// `SectionState` is emitted ONCE so the shell can hand it to `EditorSectionCard`'s chip and the nav
// indicator, while this card stays the sole owner of its own save flow.
const sectionState = useSectionState('pricing', 'Pricing & stock')
const { dirty, markDirty, beginSave, saveSucceeded, saveFailed } = sectionState
emit('state', sectionState)

function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return String(minor)
  }
}

// ── Disclosure branch (spec §5.3) ───────────────────────────────────────────────────────────────

const hasOptionAxes = computed(() => Object.keys(props.product.options ?? {}).length > 0)
const isSimple = computed(() => props.product.variants.length === 1 && !hasOptionAxes.value)
const expanded = ref(false)
const showTable = computed(() => !isSimple.value || expanded.value)

function expandVariants(): void {
  // UI-only — no mutation, no coordinator call. The product becomes multi-variant only once a
  // second variant is actually created through VariantsPanel's own existing endpoint.
  expanded.value = true
}
function collapseVariants(): void {
  expanded.value = false
}

// ── Stock read — owned here, registered with the coordinator under 'stock' ─────────────────────

const productUuid = computed(() => props.product.uuid)
const stockQuery = useProductStock(productUuid)
const stockBaseRevision = ref<number | null>(null)
const stockItems = ref<VariantStock[]>([])
// Stock never carries a local draft (the adjust form below is transient input, not a draft of
// server state) — this ref stays false always, so the coordinator only ever calls `adoptRemote`.
const stockNeverDirty = ref(false)

function adoptStock(remote: SectionEnvelope<VariantStock>): void {
  stockBaseRevision.value = remote.revision
  stockItems.value = remote.items
}

watch(
  () => stockQuery.data.value,
  (envelope) => {
    if (!envelope) return
    adoptStock(envelope)
  },
  { immediate: true },
)

if (coordinator) {
  const deregister = coordinator.register<VariantStock>('stock', {
    baseRevision: stockBaseRevision,
    dirty: stockNeverDirty,
    refetch: async () => {
      const result = await stockQuery.refetch(true)
      if (result.status !== 'success') throw result.error ?? new Error('Failed to refresh stock.')
      return result.data
    },
    adoptRemote: adoptStock,
    // No local draft ever exists for stock, so there is nothing to reconcile against — adopting
    // outright is correct regardless of which callback the coordinator picks.
    reconcileRemote: adoptStock,
  })
  onUnmounted(deregister)
}

/** A missing `commerce_stock` row is an integrity failure on the backend (StockIntegrityException)
 * — the read surfaces as an 'error' status. Never fabricate `{tracked: false, quantity: 0}`: show
 * an honest alert instead, and no quantities anywhere on this card. */
const stockUnavailable = computed(() => stockQuery.status.value === 'error')

function stockFor(variantUuid: string): VariantStock | undefined {
  return stockItems.value.find((s) => s.variant_uuid === variantUuid)
}

const singleVariant = computed<CommerceVariant | null>(() => props.product.variants[0] ?? null)
const singleVariantStock = computed(() => {
  const v = singleVariant.value
  return v ? stockFor(v.uuid) : undefined
})
const singleVariantTracked = computed(() => singleVariantStock.value?.tracked === true)

// ── Compact inline pricing form (SKU / price / compare-at) ─────────────────────────────────────

const schema = z.object({
  sku: z.string().min(1, 'SKU is required.'),
  price: z
    .number({ message: 'Price is required.' })
    .int('Whole minor units only.')
    .nonnegative('Cannot be negative.'),
  // A plain digit string, blank allowed (no compare-at price set) — never coerced through
  // Number() until submit, and only after this regex confirms it's a clean non-negative integer.
  compareAtPriceInput: z
    .string()
    .regex(/^\d*$/, 'Compare-at price must be a whole, non-negative number.'),
})
type Schema = z.output<typeof schema>

function fromVariant(v: CommerceVariant) {
  return {
    sku: v.sku,
    price: v.price,
    compareAtPriceInput: v.compare_at_price === null ? '' : String(v.compare_at_price),
  }
}

const state = reactive({ sku: '', price: 0, compareAtPriceInput: '' })
const formError = ref<string | null>(null)
const formRef = useTemplateRef<Form<Schema>>('formRef')

// Guards the server-state reset from being misread as a user edit — same `syncingFromProduct`
// pattern as ProductForm.vue's own file-level note.
let syncingFromProduct = false

watch(
  () => props.product,
  (p) => {
    const v = p.variants[0]
    if (!v || dirty.value) return
    syncingFromProduct = true
    Object.assign(state, fromVariant(v))
    void nextTick(() => {
      syncingFromProduct = false
    })
  },
  { immediate: true },
)

watch(
  state,
  () => {
    if (syncingFromProduct) return
    markDirty()
  },
  { deep: true },
)

const pricePreview = computed(() => {
  if (!Number.isInteger(state.price) || state.price < 0) return null
  return money(state.price)
})

async function onSubmit(event: FormSubmitEvent<Schema>): Promise<void> {
  const v = singleVariant.value
  if (!v) return
  formError.value = null
  // Blank compare-at is OMITTED from the payload (not sent as an explicit null) — matches
  // VariantsPanel's own create/edit forms for this task, so a never-touched compare-at price never
  // round-trips as a spurious explicit-null write.
  const compareAt =
    event.data.compareAtPriceInput === '' ? null : Number(event.data.compareAtPriceInput)
  beginSave()
  try {
    await updateVariant.mutateAsync({
      uuid: v.uuid,
      productUuid: props.product.uuid,
      input: {
        sku: event.data.sku,
        price: event.data.price,
        ...(compareAt !== null ? { compare_at_price: compareAt } : {}),
      },
    })
    saveSucceeded()
    await coordinator?.afterMutation()
    success('Pricing saved', `SKU “${event.data.sku}” was updated.`)
  } catch (e) {
    saveFailed()
    const err = toApiError(e)
    const fieldErrs = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrs.length > 0) formRef.value?.setErrors(fieldErrs)
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t save pricing')
  }
}

// ── Stock adjust (compact mode) — reuses the same mutation VariantsPanel's table mode uses ────

const adjustOpen = ref(false)
const adjustDelta = ref<number | null>(null)
const adjustReason = ref('adjustment')
const adjustError = ref<string | null>(null)

function toggleAdjust(): void {
  adjustOpen.value = !adjustOpen.value
  adjustDelta.value = null
  adjustReason.value = 'adjustment'
  adjustError.value = null
}

async function applyAdjust(): Promise<void> {
  const v = singleVariant.value
  const delta = adjustDelta.value
  if (!v || delta === null || !Number.isInteger(delta) || delta === 0) return
  adjustError.value = null
  try {
    await stockAdjust.mutateAsync({
      variantUuid: v.uuid,
      productUuid: props.product.uuid,
      input: { delta, reason: adjustReason.value || 'adjustment' },
    })
    await coordinator?.afterMutation()
    success('Stock adjusted', 'Quantity updated.')
    adjustOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    adjustError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t adjust stock')
  }
}
</script>

<template>
  <div data-test="pricing-stock-card" class="space-y-4">
    <UAlert
      v-if="stockUnavailable"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Stock data is unavailable for this product"
      data-test="stock-unavailable"
    />

    <div v-if="!showTable" data-test="pricing-compact" class="space-y-4">
      <UAlert
        v-if="formError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="pricing-form-error"
        :title="formError"
      />

      <UForm
        ref="formRef"
        :schema="schema"
        :state="state"
        class="grid grid-cols-2 gap-3 sm:grid-cols-4"
        @submit="onSubmit"
      >
        <UFormField label="SKU" name="sku" required>
          <UInput
            v-model="state.sku"
            class="w-full"
            :disabled="!canManage"
            data-test="pricing-sku-input"
          />
        </UFormField>
        <UFormField label="Price" name="price" required help="Minor units">
          <UInput
            v-model.number="state.price"
            type="number"
            :min="0"
            class="w-full"
            :disabled="!canManage"
            data-test="pricing-price-input"
          />
        </UFormField>
        <UFormField
          label="Compare-at price"
          name="compareAtPriceInput"
          help="Optional, minor units"
        >
          <UInput
            v-model="state.compareAtPriceInput"
            class="w-full"
            :disabled="!canManage"
            placeholder="Optional"
            data-test="pricing-compare-at-input"
          />
        </UFormField>
        <div class="col-span-2 flex flex-col justify-end gap-1 sm:col-span-4">
          <p v-if="pricePreview" class="text-xs text-muted" data-test="pricing-price-preview">
            {{ pricePreview }}
          </p>
          <UButton
            v-if="canManage"
            type="submit"
            size="xs"
            :loading="updateVariant.isLoading.value"
            label="Save"
            data-test="pricing-save"
          />
        </div>
      </UForm>

      <div
        v-if="!stockUnavailable && singleVariantTracked"
        class="space-y-2 rounded-md border border-default p-3"
      >
        <p class="text-sm text-default">
          <span class="text-muted">Stock</span>
          <span class="ml-2 font-medium text-default" data-test="pricing-quantity">
            {{ singleVariantStock?.quantity }}
          </span>
        </p>
        <UButton
          v-if="canManage"
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-package"
          label="Adjust stock"
          data-test="pricing-adjust-toggle"
          @click="toggleAdjust"
        />

        <UAlert
          v-if="adjustOpen && adjustError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          data-test="pricing-adjust-error"
          :title="adjustError"
        />

        <div v-if="adjustOpen" class="grid grid-cols-2 gap-3">
          <UFormField label="Delta" help="Positive to add, negative to remove">
            <UInput
              v-model.number="adjustDelta"
              type="number"
              class="w-full"
              data-test="pricing-adjust-delta"
            />
          </UFormField>
          <UFormField label="Reason">
            <UInput v-model="adjustReason" class="w-full" data-test="pricing-adjust-reason" />
          </UFormField>
          <div class="col-span-2 flex gap-2">
            <UButton
              size="xs"
              :loading="stockAdjust.isLoading.value"
              label="Apply"
              data-test="pricing-adjust-apply"
              @click="applyAdjust"
            />
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              label="Cancel"
              @click="adjustOpen = false"
            />
          </div>
        </div>
      </div>

      <UButton
        v-if="canManage"
        size="xs"
        color="neutral"
        variant="subtle"
        icon="i-lucide-list-plus"
        label="Add more variants"
        data-test="pricing-add-more-variants"
        @click="expandVariants"
      />
    </div>

    <template v-else>
      <div v-if="isSimple && expanded" class="flex justify-end">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Collapse"
          data-test="pricing-collapse"
          @click="collapseVariants"
        />
      </div>
      <VariantsPanel
        :product="product"
        :can-manage="canManage"
        :stock-items="stockItems"
        :stock-unavailable="stockUnavailable"
      />
    </template>
  </div>
</template>
