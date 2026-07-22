<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { CommerceProduct } from '@/queries/commerceCatalog'

const props = defineProps<{
  rows: CommerceProduct[]
  status: 'pending' | 'error' | 'success' | 'idle'
  canManage: boolean
  selected: string[]
}>()

const emit = defineEmits<{
  'toggle-select': [uuid: string]
  'delete-request': [row: CommerceProduct]
}>()

function isSelected(uuid: string): boolean {
  return props.selected.includes(uuid)
}

const columns = computed<TableColumn<CommerceProduct>[]>(() => [
  ...(props.canManage ? [{ id: 'select', header: '' }] : []),
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'slug', header: 'Slug' },
  { accessorKey: 'type', header: 'Type' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'updated_at', header: 'Updated' },
  ...(props.canManage ? [{ id: 'actions', header: '' }] : []),
])

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
