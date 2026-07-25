<script setup lang="ts">
import { computed } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useMoney } from '@/composables/useMoney'
import type { CommerceCustomer } from '@/queries/commerceCustomers'

const props = defineProps<{
  rows: CommerceCustomer[]
  status: 'pending' | 'error' | 'success' | 'idle'
}>()

const { format } = useMoney()

const columns = computed<TableColumn<CommerceCustomer>[]>(() => [
  { accessorKey: 'email', header: 'Customer' },
  { accessorKey: 'orders_count', header: 'Orders' },
  { accessorKey: 'total_spent_minor', header: 'Total spent' },
  { accessorKey: 'last_order_at', header: 'Last order' },
])

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the table render (mirrors OrdersTable.vue).
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

/** The customer's `{key}` is either a user uuid or an already-normalized email — neither is
 * guaranteed URL-safe as a raw path segment (an email always contains `@`), so every link encodes
 * it explicitly. `by` travels as a query param, matching `CustomerLookupQuery`'s required field. */
function detailLink(row: CommerceCustomer): string {
  return `/commerce/customers/${encodeURIComponent(row.key)}?by=${row.key_type}`
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="customers-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load customers"
    description="Something went wrong loading customers. Try again."
    data-test="customers-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-users"
    title="No customers"
    description="Customers appear here once orders start coming in."
    data-test="customers-empty"
  />

  <UTable v-else :data="rows" :columns="columns" :ui="{ td: 'align-middle' }">
    <template #email-cell="{ row }">
      <RouterLink
        :to="detailLink(row.original)"
        class="flex flex-col text-default hover:underline"
        data-test="customer-row"
      >
        <span class="font-medium" data-test="customer-email">
          {{ row.original.username ?? row.original.email }}
        </span>
        <span v-if="row.original.username" class="text-xs text-muted">{{ row.original.email }}</span>
      </RouterLink>
      <UBadge
        :color="row.original.key_type === 'user' ? 'info' : 'neutral'"
        variant="subtle"
        size="sm"
        class="mt-1"
        data-test="customer-type"
      >
        {{ row.original.key_type === 'user' ? 'Registered' : 'Guest' }}
      </UBadge>
    </template>

    <template #orders_count-cell="{ row }">
      <span data-test="customer-orders-count">{{ row.original.orders_count }}</span>
    </template>

    <template #total_spent_minor-cell="{ row }">
      <span data-test="customer-total">{{ money(row.original.total_spent_minor) }}</span>
    </template>

    <template #last_order_at-cell="{ row }">
      <span class="text-sm text-muted" data-test="customer-last-order">
        {{ fmtDate(row.original.last_order_at) }}
      </span>
    </template>
  </UTable>
</template>
