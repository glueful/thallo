<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceProductMutations,
  PRODUCT_STATUSES,
  type CommerceProduct,
  type CommerceVariant,
} from '@/queries/commerceCatalog'
import { useMoney } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()

const { success, error: notifyError } = useNotify()
const { format } = useMoney()
const { createVariant, updateVariant, bulkPrice, setChildren, stockAdjust } = useCommerceProductMutations()

const statusItems = PRODUCT_STATUSES.map((s) => ({ label: s, value: s }))

/** `useMoney().format()` throws until `/commerce/meta` resolves — guard every render site so an
 * unsettled meta query never crashes the panel (mirrors ProductForm's `basePriceText`). */
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return String(minor)
  }
}

// ── Add variant ──────────────────────────────────────────────────────────────────────────────

const addOpen = ref(false)
const addSchema = z.object({
  sku: z.string().min(1, 'SKU is required.'),
  price: z.number({ message: 'Price is required.' }).int('Whole minor units only.').nonnegative('Cannot be negative.'),
  currency: z.string().length(3, 'Currency must be a 3-letter code.').transform((v) => v.toUpperCase()),
  status: z.enum(PRODUCT_STATUSES),
})
type AddSchema = z.output<typeof addSchema>

function blankAddState() {
  return { sku: '', price: 0, currency: props.product.variants[0]?.currency ?? 'USD', status: 'active' as const }
}
const addState = reactive(blankAddState())
const addFormError = ref<string | null>(null)
const addFormRef = useTemplateRef<Form<AddSchema>>('addFormRef')

function openAdd() {
  Object.assign(addState, blankAddState())
  addFormError.value = null
  addOpen.value = true
}

async function submitAdd(event: FormSubmitEvent<AddSchema>) {
  try {
    await createVariant.mutateAsync({
      productUuid: props.product.uuid,
      input: { sku: event.data.sku, price: event.data.price, currency: event.data.currency, status: event.data.status },
    })
    success('Variant added', `SKU “${event.data.sku}” was created.`)
    addOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    const fieldErrs = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrs.length > 0) addFormRef.value?.setErrors(fieldErrs)
    // Constraint violations from CatalogService (e.g. "Cannot add variants to a 'grouped'
    // product.") key off `product_uuid` — a field this form doesn't have — so setErrors()
    // alone would silently drop them. Always ALSO surface a plain-text message.
    addFormError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t add variant')
  }
}

// ── Edit variant (one at a time) ─────────────────────────────────────────────────────────────

const editingUuid = ref<string | null>(null)
const editSchema = z.object({
  sku: z.string().min(1, 'SKU is required.'),
  price: z.number({ message: 'Price is required.' }).int('Whole minor units only.').nonnegative('Cannot be negative.'),
  status: z.enum(PRODUCT_STATUSES),
})
type EditSchema = z.output<typeof editSchema>
const editState = reactive({ sku: '', price: 0, status: 'active' as (typeof PRODUCT_STATUSES)[number] })
const editFormError = ref<string | null>(null)
// No useTemplateRef('editFormRef') here: the edit UForm lives inside the variants `v-for`, so a
// shared template ref name would resolve to an ARRAY of Form instances, not one — setErrors()
// would need index-tracking for no real benefit. The inline `editFormError` banner below already
// renders every field message (including ones like `sku` that map to a real input here).
function startEdit(variant: CommerceVariant) {
  editingUuid.value = variant.uuid
  editState.sku = variant.sku
  editState.price = variant.price
  editState.status = (variant.status as (typeof PRODUCT_STATUSES)[number]) || 'active'
  editFormError.value = null
}

function cancelEdit() {
  editingUuid.value = null
}

async function submitEdit(event: FormSubmitEvent<EditSchema>) {
  const uuid = editingUuid.value
  if (!uuid) return
  try {
    await updateVariant.mutateAsync({
      uuid,
      productUuid: props.product.uuid,
      input: { sku: event.data.sku, price: event.data.price, status: event.data.status },
    })
    success('Variant saved', `SKU “${event.data.sku}” was updated.`)
    editingUuid.value = null
  } catch (e) {
    const err = toApiError(e)
    editFormError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t save variant')
  }
}

// ── Bulk price ───────────────────────────────────────────────────────────────────────────────

const selected = ref<string[]>([])
function isSelected(uuid: string): boolean {
  return selected.value.includes(uuid)
}
function toggleSelect(uuid: string) {
  selected.value = isSelected(uuid) ? selected.value.filter((u) => u !== uuid) : [...selected.value, uuid]
}

const bulkPriceValue = ref<number | null>(null)
const bulkPriceError = ref<string | null>(null)

async function applyBulkPrice() {
  const price = bulkPriceValue.value
  if (price === null || !Number.isInteger(price) || price < 0 || selected.value.length === 0) return
  bulkPriceError.value = null
  try {
    await bulkPrice.mutateAsync({
      productUuid: props.product.uuid,
      items: selected.value.map((uuid) => ({ uuid, price })),
    })
    success('Prices updated', `${selected.value.length} variant(s) updated.`)
    selected.value = []
    bulkPriceValue.value = null
  } catch (e) {
    const err = toApiError(e)
    bulkPriceError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t apply bulk price')
  }
}

// ── Stock adjust (one at a time) ─────────────────────────────────────────────────────────────

const adjustingUuid = ref<string | null>(null)
const stockDelta = ref<number | null>(null)
const stockReason = ref('adjustment')
const stockFormError = ref<string | null>(null)
/** Last known quantity per variant, from that variant's own most recent adjust response — there
 * is no admin GET for current stock, so this is the only source of truth the SPA ever has. */
const lastKnownQuantity = reactive<Record<string, number>>({})

function toggleStockAdjust(uuid: string) {
  if (adjustingUuid.value === uuid) {
    adjustingUuid.value = null
    return
  }
  adjustingUuid.value = uuid
  stockDelta.value = null
  stockReason.value = 'adjustment'
  stockFormError.value = null
}

async function applyStockAdjust() {
  const uuid = adjustingUuid.value
  const delta = stockDelta.value
  if (!uuid || delta === null || !Number.isInteger(delta) || delta === 0) return
  stockFormError.value = null
  try {
    const result = await stockAdjust.mutateAsync({
      variantUuid: uuid,
      productUuid: props.product.uuid,
      input: { delta, reason: stockReason.value || 'adjustment' },
    })
    lastKnownQuantity[uuid] = result.quantity
    success('Stock adjusted', `Quantity is now ${result.quantity}.`)
    adjustingUuid.value = null
  } catch (e) {
    const err = toApiError(e)
    stockFormError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t adjust stock')
  }
}

// ── Children (grouped products only) ────────────────────────────────────────────────────────

const isGrouped = computed(() => props.product.type === 'grouped')
const childrenInput = ref('')
const childrenError = ref<string | null>(null)
const knownChildren = ref<CommerceProduct[] | null>(null)

function parseChildUuids(raw: string): string[] {
  return raw
    .split(/[,\n]/)
    .map((s) => s.trim())
    .filter((s) => s !== '')
}

async function saveChildren() {
  childrenError.value = null
  const uuids = parseChildUuids(childrenInput.value)
  try {
    const children = await setChildren.mutateAsync({ productUuid: props.product.uuid, childUuids: uuids })
    knownChildren.value = children
    childrenInput.value = children.map((c) => c.uuid).join(', ')
    success('Children updated', `${children.length} child product(s) set.`)
  } catch (e) {
    const err = toApiError(e)
    childrenError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t set children')
  }
}
</script>

<template>
  <div class="space-y-8">
    <!-- Variants ------------------------------------------------------------------------- -->
    <section class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-medium text-default">Variants</h3>
        <UButton
          v-if="canManage"
          size="xs"
          icon="i-lucide-plus"
          label="Add variant"
          data-test="variant-add"
          @click="openAdd"
        />
      </div>
      <p v-if="canManage" class="text-xs text-muted">
        Variants can only be added to physical or digital products.
      </p>

      <UAlert
        v-if="addFormError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="variant-form-error"
        :title="addFormError"
      />

      <UForm
        v-if="addOpen"
        id="variant-add-form"
        ref="addFormRef"
        :schema="addSchema"
        :state="addState"
        class="grid grid-cols-2 gap-3 rounded-md border border-default p-3 sm:grid-cols-4"
        @submit="submitAdd"
      >
        <UFormField label="SKU" name="sku" required>
          <UInput v-model="addState.sku" class="w-full" data-test="variant-sku-input" />
        </UFormField>
        <UFormField label="Price" name="price" required help="Minor units">
          <UInput v-model.number="addState.price" type="number" :min="0" class="w-full" data-test="variant-price-input" />
        </UFormField>
        <UFormField label="Currency" name="currency" required>
          <UInput v-model="addState.currency" class="w-full uppercase" data-test="variant-currency-input" />
        </UFormField>
        <UFormField label="Status" name="status">
          <USelect v-model="addState.status" :items="statusItems" class="w-full" data-test="variant-status-input" />
        </UFormField>
        <div class="col-span-2 flex gap-2 sm:col-span-4">
          <UButton
            type="submit"
            size="xs"
            :loading="createVariant.isLoading.value"
            label="Create"
            data-test="variant-create-submit"
          />
          <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="addOpen = false" />
        </div>
      </UForm>

      <UAlert
        v-if="product.variants.length === 0"
        color="neutral"
        variant="subtle"
        icon="i-lucide-package"
        title="No variants yet"
        data-test="variants-empty"
      />

      <div
        v-for="variant in product.variants"
        :key="variant.uuid"
        data-test="variant-row"
        class="space-y-3 rounded-md border border-default p-3"
      >
        <div class="flex flex-wrap items-center gap-3">
          <UCheckbox
            v-if="canManage"
            :model-value="isSelected(variant.uuid)"
            aria-label="Select variant"
            data-test="variant-select"
            @update:model-value="toggleSelect(variant.uuid)"
          />
          <span class="font-medium text-default">{{ variant.sku }}</span>
          <span data-test="variant-price" class="text-default">{{ money(variant.price) }}</span>
          <span v-if="variant.compare_at_price !== null" class="text-xs text-muted line-through">
            {{ money(variant.compare_at_price) }}
          </span>
          <UBadge color="neutral" variant="subtle" size="sm">{{ variant.status }}</UBadge>
          <span v-if="lastKnownQuantity[variant.uuid] !== undefined" class="text-xs text-muted" data-test="variant-stock-quantity">
            Stock: {{ lastKnownQuantity[variant.uuid] }}
          </span>

          <div v-if="canManage" class="ml-auto flex gap-1">
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-pencil"
              aria-label="Edit variant"
              data-test="variant-edit"
              @click="startEdit(variant)"
            />
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-package"
              aria-label="Adjust stock"
              data-test="stock-adjust"
              @click="toggleStockAdjust(variant.uuid)"
            />
          </div>
        </div>

        <UAlert
          v-if="editingUuid === variant.uuid && editFormError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          data-test="variant-edit-error"
          :title="editFormError"
        />

        <UForm
          v-if="editingUuid === variant.uuid"
          :id="`variant-edit-form-${variant.uuid}`"
          ref="editFormRef"
          :schema="editSchema"
          :state="editState"
          class="grid grid-cols-2 gap-3 sm:grid-cols-4"
          @submit="submitEdit"
        >
          <UFormField label="SKU" name="sku" required>
            <UInput v-model="editState.sku" class="w-full" data-test="variant-edit-sku-input" />
          </UFormField>
          <UFormField label="Price" name="price" required help="Minor units">
            <UInput v-model.number="editState.price" type="number" :min="0" class="w-full" data-test="variant-edit-price-input" />
          </UFormField>
          <UFormField label="Status" name="status">
            <USelect v-model="editState.status" :items="statusItems" class="w-full" data-test="variant-edit-status-input" />
          </UFormField>
          <div class="col-span-2 flex items-end gap-2 sm:col-span-4">
            <UButton type="submit" size="xs" :loading="updateVariant.isLoading.value" label="Save" data-test="variant-save" />
            <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelEdit" />
          </div>
        </UForm>

        <UAlert
          v-if="adjustingUuid === variant.uuid && stockFormError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          data-test="stock-adjust-error"
          :title="stockFormError"
        />

        <div v-if="adjustingUuid === variant.uuid" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <UFormField label="Delta" help="Positive to add, negative to remove">
            <UInput v-model.number="stockDelta" type="number" class="w-full" data-test="stock-adjust-delta" />
          </UFormField>
          <UFormField label="Reason">
            <UInput v-model="stockReason" class="w-full" data-test="stock-adjust-reason" />
          </UFormField>
          <div class="col-span-2 flex items-end gap-2 sm:col-span-2">
            <UButton
              size="xs"
              :loading="stockAdjust.isLoading.value"
              label="Apply"
              data-test="stock-adjust-apply"
              @click="applyStockAdjust"
            />
            <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="adjustingUuid = null" />
          </div>
        </div>
      </div>

      <!-- Bulk price ---------------------------------------------------------------------- -->
      <div
        v-if="canManage && selected.length > 0"
        data-test="bulk-price-bar"
        class="flex flex-wrap items-center gap-3 rounded-md border border-default bg-elevated/50 p-3"
      >
        <span class="text-sm text-muted">{{ selected.length }} selected</span>
        <UInput
          v-model.number="bulkPriceValue"
          type="number"
          :min="0"
          placeholder="New price (minor units)"
          data-test="bulk-price-input"
        />
        <UButton
          size="xs"
          :loading="bulkPrice.isLoading.value"
          label="Apply price"
          data-test="bulk-price-apply"
          @click="applyBulkPrice"
        />
        <UButton size="xs" color="neutral" variant="ghost" label="Clear selection" @click="selected = []" />
        <UAlert
          v-if="bulkPriceError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          data-test="bulk-price-error"
          :title="bulkPriceError"
          class="w-full"
        />
      </div>
    </section>

    <!-- Children (grouped products only) --------------------------------------------------- -->
    <section v-if="isGrouped" data-test="children-section" class="space-y-3 border-t border-default pt-6">
      <h3 class="text-sm font-medium text-default">Child products</h3>
      <p class="text-xs text-muted">
        Comma-separated child product UUIDs. Saving replaces the entire child list.
      </p>
      <UTextarea
        v-model="childrenInput"
        :rows="2"
        :disabled="!canManage"
        placeholder="e.g. prod_abc123, prod_def456"
        class="w-full"
        data-test="children-input"
      />
      <UAlert
        v-if="childrenError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="children-error"
        :title="childrenError"
      />
      <UButton
        v-if="canManage"
        size="xs"
        :loading="setChildren.isLoading.value"
        label="Save children"
        data-test="children-save"
        @click="saveChildren"
      />
      <ul v-if="knownChildren && knownChildren.length > 0" data-test="children-list" class="space-y-1 text-sm text-muted">
        <li v-for="child in knownChildren" :key="child.uuid">{{ child.name }} ({{ child.slug }})</li>
      </ul>
    </section>
  </div>
</template>
