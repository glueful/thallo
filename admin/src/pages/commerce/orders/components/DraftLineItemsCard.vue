<script setup lang="ts">
// Task 14 (admin-order-creation): the draft line picker. Search hits the SAME admin product
// search every product-management surface already uses (`useCommerceProducts()`); commerce
// v1.10.0 additively carries `admin_draft_eligible`/`admin_draft_ineligible_reason` on each row —
// server-computed, NEVER re-derived here from `type`/a seller uuid. The paginated search list
// never attaches `variants` (commerceCatalog.ts's own docblock), so adding a line is a two-step
// flow: pick an eligible PRODUCT from search, then fetch that one product in full
// (`useCommerceProduct()`, which DOES carry `variants`) to pick the specific `variant_uuid` a
// draft line actually targets.
import { ref, computed } from 'vue'
import { useCommerceProducts, useCommerceProduct, type CommerceProduct } from '@/queries/commerceCatalog'
import { useCommerceDraftMutations, type CommerceDraft } from '@/queries/commerceDrafts'
import { useMoney } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'

const props = defineProps<{
  draft: CommerceDraft
}>()

const { addLine, updateLine, deleteLine } = useCommerceDraftMutations()
const { format } = useMoney()

function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

const INELIGIBLE_LABELS: Record<string, string> = {
  digital: 'Digital product — cannot be added to a walk-in order.',
  marketplace: 'Marketplace seller product — cannot be added.',
  unavailable: 'Unavailable.',
}

const query = ref('')
const { data: searchPage } = useCommerceProducts(() => ({ q: query.value, page: 1, perPage: 8 }))
const products = computed<CommerceProduct[]>(() => searchPage.value?.products ?? [])

const selectedProductUuid = ref<string | null>(null)
const { data: selectedProduct } = useCommerceProduct(
  () => selectedProductUuid.value ?? '',
  () => !!selectedProductUuid.value,
)
const selectedVariantUuid = ref<string | null>(null)
const qty = ref(1)
const addLineError = ref<string | null>(null)

function selectProduct(uuid: string) {
  selectedProductUuid.value = uuid
  selectedVariantUuid.value = null
  qty.value = 1
  addLineError.value = null
}

function cancelSelection() {
  selectedProductUuid.value = null
  selectedVariantUuid.value = null
  addLineError.value = null
}

async function confirmAddLine() {
  if (!selectedVariantUuid.value) return
  addLineError.value = null
  try {
    await addLine.mutateAsync({
      uuid: props.draft.uuid,
      input: {
        variant_uuid: selectedVariantUuid.value,
        quantity: qty.value,
        expected_revision: props.draft.draft_revision,
      },
    })
    cancelSelection()
    query.value = ''
  } catch (e) {
    addLineError.value = toApiError(e).message
  }
}

const lineErrors = ref<Record<string, string>>({})

async function changeQuantity(lineUuid: string, quantity: number) {
  if (!Number.isInteger(quantity) || quantity < 1) return
  try {
    await updateLine.mutateAsync({
      uuid: props.draft.uuid,
      lineUuid,
      input: { quantity, expected_revision: props.draft.draft_revision },
    })
    delete lineErrors.value[lineUuid]
  } catch (e) {
    lineErrors.value = { ...lineErrors.value, [lineUuid]: toApiError(e).message }
  }
}

async function removeLine(lineUuid: string) {
  try {
    await deleteLine.mutateAsync({
      uuid: props.draft.uuid,
      lineUuid,
      expectedRevision: props.draft.draft_revision,
    })
  } catch (e) {
    lineErrors.value = { ...lineErrors.value, [lineUuid]: toApiError(e).message }
  }
}
</script>

<template>
  <UCard data-test="draft-lines-card">
    <template #header>
      <h3 class="text-sm font-medium">Items</h3>
    </template>

    <div class="flex flex-col gap-4">
      <UEmpty
        v-if="draft.lines.length === 0"
        icon="i-lucide-package"
        title="No items yet"
        data-test="draft-lines-empty"
      />
      <ul v-else class="flex flex-col divide-y divide-default">
        <li
          v-for="line in draft.lines"
          :key="line.uuid"
          data-test="draft-line-row"
          class="flex flex-col gap-1 py-2"
        >
          <div class="flex items-center justify-between gap-2 text-sm">
            <span class="font-medium text-default">{{ line.product_name }} ({{ line.sku }})</span>
            <span>{{ money(line.line_total) }}</span>
          </div>
          <div class="flex items-center gap-2">
            <UInput
              type="number"
              :model-value="line.quantity"
              min="1"
              class="w-20"
              data-test="draft-line-qty"
              @change="(e: Event) => changeQuantity(line.uuid, Number((e.target as HTMLInputElement).value))"
            />
            <UButton
              size="xs"
              color="error"
              variant="ghost"
              data-test="draft-line-remove"
              @click="removeLine(line.uuid)"
            >
              Remove
            </UButton>
          </div>
          <UAlert
            v-if="lineErrors[line.uuid]"
            color="error"
            variant="subtle"
            :title="lineErrors[line.uuid]"
            data-test="draft-line-error"
          />
        </li>
      </ul>

      <div class="flex flex-col gap-2 border-t border-default pt-4">
        <UInput
          v-model="query"
          placeholder="Search products by name or SKU…"
          icon="i-lucide-search"
          data-test="draft-product-search"
        />

        <ul class="flex flex-col divide-y divide-default">
          <li
            v-for="p in products"
            :key="p.uuid"
            data-test="draft-product-row"
            class="flex items-center justify-between gap-2 py-1.5 text-sm"
          >
            <span>{{ p.name }}</span>
            <span
              v-if="!p.admin_draft_eligible"
              class="text-xs text-muted"
              data-test="draft-product-ineligible-reason"
            >
              {{ INELIGIBLE_LABELS[p.admin_draft_ineligible_reason ?? 'unavailable'] }}
            </span>
            <UButton v-else size="xs" data-test="draft-product-select" @click="selectProduct(p.uuid)">
              Select
            </UButton>
          </li>
        </ul>

        <div v-if="selectedProduct" data-test="draft-variant-picker" class="flex flex-col gap-2 rounded-md border border-default p-3">
          <p class="text-sm font-medium">{{ selectedProduct.name }}</p>
          <label
            v-for="v in selectedProduct.variants"
            :key="v.uuid"
            data-test="draft-variant-row"
            class="flex items-center gap-2 text-sm"
          >
            <input
              type="radio"
              :value="v.uuid"
              v-model="selectedVariantUuid"
              data-test="draft-variant-radio"
            />
            <span>{{ v.sku }} — {{ money(v.price) }}</span>
          </label>

          <UInput
            v-model.number="qty"
            type="number"
            min="1"
            class="w-24"
            data-test="draft-line-qty-input"
          />

          <UAlert
            v-if="addLineError"
            color="error"
            variant="subtle"
            :title="addLineError"
            data-test="draft-line-add-error"
          />

          <div class="flex gap-2">
            <UButton
              :disabled="!selectedVariantUuid"
              :loading="addLine.isLoading.value"
              data-test="draft-line-add"
              @click="confirmAddLine"
            >
              Add to order
            </UButton>
            <UButton color="neutral" variant="ghost" data-test="draft-line-add-cancel" @click="cancelSelection">
              Cancel
            </UButton>
          </div>
        </div>
      </div>
    </div>
  </UCard>
</template>
