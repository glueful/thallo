<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { useCollections, type Collection } from '@/queries/collections'

const { data, status } = useCollections()

const columns: TableColumn<Collection>[] = [
  { accessorKey: 'name', header: 'Collection' },
  { accessorKey: 'label', header: 'Label' },
  { accessorKey: 'fields', header: 'Fields' },
]
</script>

<template>
  <UDashboardPanel id="collections-data-picker">
    <template #header>
      <UDashboardNavbar title="Browse data" />
    </template>

    <template #body>
      <p class="text-sm text-muted">Pick a collection to browse and edit its rows.</p>

      <UTable :data="data ?? []" :columns="columns" :loading="status === 'pending'">
        <template #name-cell="{ row }">
          <ULink :to="`/collections/${row.original.name}/data`" class="font-medium text-default">
            {{ row.original.name }}
          </ULink>
        </template>
        <template #label-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.label }}</span>
        </template>
        <template #fields-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.fields.length }}</span>
        </template>
      </UTable>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.collections
</route>
