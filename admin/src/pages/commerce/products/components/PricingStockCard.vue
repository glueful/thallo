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
import {
  useMoney,
  minorToMajorInputString,
  parseMajorAmountToMinorUnits,
} from '@/composables/useMoney'
import { useCommerceMeta } from '@/queries/commerceMeta'
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

// ── Major-unit money inputs (condensed-cards pass) ─────────────────────────────────────────────
// Admins type prices the way the storefront shows them ("19.99"), never raw minor units. Both
// hydration (minor → input string) and parsing (input string → minor) gate on the tenant
// currency's exponent from /commerce/meta — a fallback exponent would silently RESCALE amounts
// (700 GHS vs 70000 GHS), so until meta resolves the price fields stay unhydrated and parsing
// reports invalid. Meta settles alongside the page's own queries; the gate is unreachable in
// practice and exists purely so a wrong-scale write is impossible.

const { data: moneyMeta } = useCommerceMeta()
const exponent = computed<number | null>(() => moneyMeta.value?.currency_exponent ?? null)
const currencyCode = computed(() => moneyMeta.value?.currency ?? '')
const amountPlaceholder = computed(() => {
  const exp = exponent.value
  return exp === null || exp === 0 ? '0' : `0.${'0'.repeat(exp)}`
})

/** Major-unit input string → minor-unit integer, or null when unparseable (or meta unresolved). */
function parseMajor(input: string): number | null {
  const exp = exponent.value
  if (exp === null) return null
  const minor = parseMajorAmountToMinorUnits(input, exp)
  if (minor === null || minor > BigInt(Number.MAX_SAFE_INTEGER)) return null
  return Number(minor)
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
  // Major-unit decimal strings, parsed via BigInt (`parseMajorAmountToMinorUnits`) — never coerced
  // through Number(), so no float rounding can smuggle in an off-by-one-minor-unit amount.
  price: z
    .string()
    .min(1, 'Price is required.')
    .refine((v) => parseMajor(v) !== null, 'Enter a valid amount, e.g. 19.99.'),
  compareAtPriceInput: z
    .string()
    .refine(
      (v) => v.trim() === '' || parseMajor(v) !== null,
      'Original price must be a valid amount, or blank.',
    ),
})
type Schema = z.output<typeof schema>

function fromVariant(v: CommerceVariant, exp: number) {
  return {
    sku: v.sku,
    price: minorToMajorInputString(v.price, exp),
    compareAtPriceInput:
      v.compare_at_price === null ? '' : minorToMajorInputString(v.compare_at_price, exp),
  }
}

const state = reactive({ sku: '', price: '', compareAtPriceInput: '' })
const formError = ref<string | null>(null)
const formRef = useTemplateRef<Form<Schema>>('formRef')

// Guards the server-state reset from being misread as a user edit — same `syncingFromProduct`
// pattern as ProductForm.vue's own file-level note.
let syncingFromProduct = false

// `exponent` is a watch source too: hydration needs it, and meta may settle after the product —
// the second firing then hydrates (still guarded by `dirty`, so it never clobbers user edits).
watch(
  [() => props.product, exponent],
  ([p, exp]) => {
    const v = p.variants[0]
    if (!v || exp === null || dirty.value) return
    syncingFromProduct = true
    Object.assign(state, fromVariant(v, exp))
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
  const minor = parseMajor(state.price)
  return minor === null ? null : money(minor)
})

async function onSubmit(event: FormSubmitEvent<Schema>): Promise<void> {
  const v = singleVariant.value
  if (!v) return
  formError.value = null
  // Zod's refines above already rejected unparseable amounts — these guards are belt-and-braces
  // so an impossible null can never reach the payload as a wrong value.
  const priceMinor = parseMajor(event.data.price)
  if (priceMinor === null) return
  const compareAt =
    event.data.compareAtPriceInput.trim() === '' ? null : parseMajor(event.data.compareAtPriceInput)
  if (event.data.compareAtPriceInput.trim() !== '' && compareAt === null) return
  beginSave()
  try {
    await updateVariant.mutateAsync({
      uuid: v.uuid,
      productUuid: props.product.uuid,
      input: {
        sku: event.data.sku,
        price: priceMinor,
        // ALWAYS present on updates: a blank field sends an explicit null, which the backend
        // binds as SQL NULL (clears an existing compare-at/sale price). Omitting the key would
        // leave the old value silently untouched behind a "saved" toast — C7 review Critical.
        // (CREATE paths correctly omit-when-blank: nothing to clear on a new variant.)
        compare_at_price: compareAt,
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

      <UForm ref="formRef" :schema="schema" :state="state" class="space-y-3" @submit="onSubmit">
        <!-- Three fields, three columns — no orphan grid hole; the action row sits below. -->
        <div class="grid gap-3 sm:grid-cols-3">
          <UFormField label="SKU" name="sku" required>
            <UInput
              v-model="state.sku"
              class="w-full"
              :disabled="!canManage"
              data-test="pricing-sku-input"
            />
          </UFormField>
          <UFormField label="Price" name="price" required :help="currencyCode || undefined">
            <UInput
              v-model="state.price"
              inputmode="decimal"
              :placeholder="amountPlaceholder"
              class="w-full"
              :disabled="!canManage"
              data-test="pricing-price-input"
            />
          </UFormField>
          <UFormField
            label="Original price"
            name="compareAtPriceInput"
            help="Shown crossed out beside the price, marking a sale"
          >
            <UInput
              v-model="state.compareAtPriceInput"
              inputmode="decimal"
              class="w-full"
              :disabled="!canManage"
              placeholder="Optional"
              data-test="pricing-compare-at-input"
            />
          </UFormField>
        </div>
        <div class="flex items-center gap-3">
          <UButton
            v-if="canManage"
            type="submit"
            size="sm"
            :loading="updateVariant.isLoading.value"
            label="Save pricing"
            data-test="pricing-save"
          />
          <p v-if="pricePreview" class="text-xs text-muted" data-test="pricing-price-preview">
            {{ pricePreview }}
          </p>
        </div>
      </UForm>

      <div
        v-if="!stockUnavailable && singleVariantTracked"
        class="space-y-3 rounded-md border border-default p-3"
      >
        <div class="flex items-center justify-between gap-3">
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
            variant="subtle"
            icon="i-lucide-package"
            label="Adjust stock"
            data-test="pricing-adjust-toggle"
            @click="toggleAdjust"
          />
        </div>

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
