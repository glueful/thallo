<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { CommerceProduct } from '@/queries/commerceCatalog'
import { useMoney } from '@/composables/useMoney'

const props = defineProps<{
  rows: CommerceProduct[]
  status: 'pending' | 'error' | 'success' | 'idle'
  canManage: boolean
  selected: string[]
}>()

const emit = defineEmits<{
  'toggle-select': [uuid: string]
  'toggle-select-all': []
  'delete-request': [row: CommerceProduct]
}>()

function isSelected(uuid: string): boolean {
  return props.selected.includes(uuid)
}

// Header checkbox state (the Nuxt UI table pattern): checked when every page row is selected,
// indeterminate when only some are — toggling asks the parent to select/clear the whole page.
const allSelected = computed(
  () => props.rows.length > 0 && props.rows.every((r) => props.selected.includes(r.uuid)),
)
const headerSelectState = computed<boolean | 'indeterminate'>(() => {
  if (allSelected.value) return true
  return props.rows.some((r) => props.selected.includes(r.uuid)) ? 'indeterminate' : false
})

const columns = computed<TableColumn<CommerceProduct>[]>(() => [
  ...(props.canManage ? [{ id: 'select', header: '' }] : []),
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'slug', header: 'Slug' },
  { accessorKey: 'type', header: 'Type' },
  { id: 'price', header: 'Price' },
  { id: 'stock', header: 'Stock' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'updated_at', header: 'Updated' },
  ...(props.canManage ? [{ id: 'actions', header: '' }] : []),
])

// Price/stock come from the LIST summary (commerce 1.6.0). Every cell degrades to "—" when the
// summary is absent (an older commerce) or the value is genuinely unknown — a fabricated 0 would
// read as "out of stock", and a fabricated price would be a lie about what the merchant charges.
const { format } = useMoney()
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return String(minor)
  }
}

/** One amount for a single-variant product, a range when variants differ. */
function priceDisplay(product: CommerceProduct): string {
  const summary = product.summary
  if (!summary || summary.price_from === null || summary.price_to === null) return '—'
  return summary.price_from === summary.price_to
    ? money(summary.price_from)
    : `${money(summary.price_from)} – ${money(summary.price_to)}`
}

function statusColor(s: string): 'success' | 'neutral' {
  return s === 'active' ? 'success' : 'neutral'
}

function fmtDate(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="products-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load products"
    description="Something went wrong loading the product catalog. Try again."
    data-test="products-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-package"
    title="No products"
    description="Create your first product to start selling."
    data-test="products-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template v-if="canManage" #select-header>
      <UCheckbox
        :model-value="headerSelectState"
        aria-label="Select all on page"
        data-test="product-select-all"
        @update:model-value="emit('toggle-select-all')"
      />
    </template>

    <template v-if="canManage" #select-cell="{ row }">
      <UCheckbox
        :model-value="isSelected(row.original.uuid)"
        aria-label="Select product"
        data-test="product-select"
        @update:model-value="emit('toggle-select', row.original.uuid)"
      />
    </template>

    <template #name-cell="{ row }">
      <div data-test="product-row" class="flex items-center gap-2">
        <RouterLink
          :to="`/commerce/products/${row.original.uuid}`"
          class="font-medium text-default hover:underline"
        >
          {{ row.original.name }}
        </RouterLink>
      </div>
    </template>

    <template #type-cell="{ row }">
      <UBadge color="neutral" variant="subtle" size="sm">{{ row.original.type }}</UBadge>
    </template>

    <template #price-cell="{ row }">
      <span class="text-sm whitespace-nowrap text-default" data-test="product-price">
        {{ priceDisplay(row.original) }}
      </span>
      <span
        v-if="(row.original.summary?.variant_count ?? 0) > 1"
        class="ml-1 text-xs text-muted whitespace-nowrap"
      >
        · {{ row.original.summary?.variant_count }} variants
      </span>
    </template>

    <template #stock-cell="{ row }">
      <!-- Tracked → the number (0 is a REAL zero: genuinely out of stock). Untracked or
           unknown → an honest dash, never a fabricated quantity. -->
      <span
        v-if="row.original.summary?.stock_tracked && row.original.summary?.stock_quantity !== null"
        class="text-sm"
        :class="(row.original.summary?.stock_quantity ?? 0) > 0 ? 'text-default' : 'text-warning'"
        data-test="product-stock"
      >
        {{ row.original.summary?.stock_quantity }}
      </span>
      <span v-else class="text-sm text-muted" data-test="product-stock">—</span>
    </template>

    <template #status-cell="{ row }">
      <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
        {{ row.original.status }}
      </UBadge>
    </template>

    <template #updated_at-cell="{ row }">
      <span class="text-sm text-muted">{{ fmtDate(row.original.updated_at) }}</span>
    </template>

    <template v-if="canManage" #actions-cell="{ row }">
      <div class="flex justify-end gap-1">
        <UButton
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-pencil"
          aria-label="Edit product"
          :to="`/commerce/products/${row.original.uuid}`"
        />
        <UButton
          color="error"
          variant="ghost"
          size="xs"
          icon="i-lucide-trash-2"
          aria-label="Delete product"
          data-test="product-delete"
          @click="emit('delete-request', row.original)"
        />
      </div>
    </template>
  </UTable>
</template>
