<script setup lang="ts">
import { computed, h, ref } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import {
  useCollection,
  useCollectionRows,
  useCollectionRowMutations,
  type CollectionRow,
} from '@/queries/collections'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'
import RowDrawer from './RowDrawer.vue'

const props = defineProps<{ collectionName: string }>()
const emit = defineEmits<{ 'edit-schema': [] }>()
const { success, error: notifyError } = useNotify()

const name = computed(() => props.collectionName)
const { data: collection } = useCollection(name)

const page = ref(1)
const perPage = ref(10)
const { data: pageData, status } = useCollectionRows(name, page, perPage)
const { create, update, remove } = useCollectionRowMutations(name)

// The data browser shows the system columns (except id) + custom fields, in the definition's
// display order; columns missing from the stored order are appended (e.g. fields added later).
const SYSTEM_DISPLAY = ['uuid', 'created_at', 'updated_at']
const SYSTEM_HEADERS: Record<string, string> = {
  uuid: 'UUID',
  created_at: 'Created',
  updated_at: 'Updated',
}

const columns = computed<TableColumn<CollectionRow>[]>(() => {
  const def = collection.value
  const customNames = (def?.fields ?? []).map((f) => f.name)
  const displayable = [...SYSTEM_DISPLAY, ...customNames]
  const valid = new Set(displayable)

  const ordered = (def?.fieldOrder ?? []).filter((n) => n !== 'id' && valid.has(n))
  for (const n of displayable) if (!ordered.includes(n)) ordered.push(n)

  return [
    ...ordered.map((n) => ({
      accessorKey: n,
      header: SYSTEM_HEADERS[n] ?? n,
      // Cap a cell's width and wrap, so long values (e.g. rich text) don't push other columns
      // off-screen and force horizontal scrolling.
      cell: ({ getValue }: { getValue: () => unknown }) =>
        h(
          'div',
          { class: 'line-clamp-2 max-w-xs whitespace-normal break-words' },
          String(getValue() ?? ''),
        ),
    })),
    { id: 'actions', header: '' },
  ]
})

const drawerOpen = ref(false)
const editingRow = ref<CollectionRow | null>(null)
function openCreate() {
  editingRow.value = null
  drawerOpen.value = true
}
function openEdit(row: CollectionRow) {
  editingRow.value = row
  drawerOpen.value = true
}

const saving = computed(() => create.isLoading.value || update.isLoading.value)
async function onSave(payload: CollectionRow) {
  try {
    const editing = editingRow.value
    if (editing && typeof editing.uuid === 'string') {
      await update.mutateAsync({ uuid: editing.uuid, row: payload })
      success('Row updated', 'Changes were saved.')
    } else {
      await create.mutateAsync(payload)
      success('Row created', 'The row was added.')
    }
    drawerOpen.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t save row')
  }
}

const pendingDelete = ref<CollectionRow | null>(null)
async function confirmDelete() {
  const row = pendingDelete.value
  if (!row || typeof row.uuid !== 'string') return
  try {
    await remove.mutateAsync(row.uuid)
    success('Row deleted', 'The row was removed.')
    pendingDelete.value = null
  } catch (e) {
    // A restrict-referenced delete returns 409 — surfaced to the operator.
    notifyError(e, 'Couldn’t delete row')
  }
}
</script>

<template>
  <div data-test="collection-data-pane" class="flex h-full min-h-0 flex-col">
    <div class="flex items-center justify-between gap-2 pb-3">
      <div class="min-w-0">
        <h2 class="truncate text-base font-semibold text-default">
          {{ collection?.label || collectionName }}
        </h2>
        <p class="text-xs text-muted">{{ pageData?.total ?? 0 }} rows</p>
      </div>
      <div class="flex items-center gap-2">
        <UButton
          variant="ghost"
          color="neutral"
          size="sm"
          icon="i-lucide-settings-2"
          @click="emit('edit-schema')"
        >
          Edit schema
        </UButton>
        <UButton size="sm" icon="i-lucide-plus" data-test="new-row" @click="openCreate"
          >New row</UButton
        >
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
      <UTable :data="pageData?.rows ?? []" :columns="columns" :loading="status === 'pending'">
        <template #actions-cell="{ row }">
          <div data-test="row" class="flex justify-end gap-1">
            <UButton
              size="xs"
              variant="ghost"
              icon="i-lucide-pencil"
              aria-label="Edit row"
              @click="openEdit(row.original)"
            />
            <UButton
              size="xs"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              aria-label="Delete row"
              @click="() => { pendingDelete = row.original }"
            />
          </div>
        </template>

        <template #empty>
          <UEmpty
            icon="i-lucide-rows-3"
            variant="naked"
            title="No rows"
            description="Create the first row in this collection."
          >
            <template #actions>
              <UButton icon="i-lucide-plus" @click="openCreate">New row</UButton>
            </template>
          </UEmpty>
        </template>
      </UTable>
    </div>

    <TablePagination
      v-model:page="page"
      v-model:perPage="perPage"
      :total="pageData?.total ?? 0"
      label="rows"
    />

    <RowDrawer
      :open="drawerOpen"
      :collection="collection ?? null"
      :row="editingRow"
      :loading="saving"
      @update:open="drawerOpen = $event"
      @save="onSave"
    />

    <UModal
      :open="pendingDelete !== null"
      title="Delete row"
      @update:open="
        (v: boolean) => {
          if (!v) pendingDelete = null
        }
      "
    >
      <template #body>
        <p class="text-sm text-muted">Delete this row? This cannot be undone.</p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancel"
            :disabled="remove.isLoading.value"
            @click="() => { pendingDelete = null }"
          />
          <UButton
            color="error"
            icon="i-lucide-trash-2"
            label="Delete"
            :loading="remove.isLoading.value"
            @click="confirmDelete"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
