<script setup lang="ts">
import { ref } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useContentTypes, useContentTypeMutations } from '@/queries/contentTypes'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useContentTypes()
const { remove } = useContentTypeMutations()

// The list row shape comes straight from the query (the OpenAPI-typed content-type item).
type Row = NonNullable<typeof data.value>[number]

const columns: TableColumn<Row>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'slug', header: 'Slug' },
  { accessorKey: 'schema', header: 'Fields' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'updated_at', header: 'Updated' },
  { id: 'actions', header: '' },
]

// Delete confirmation: hold the pending row so the modal can name it.
const pendingDelete = ref<Row | null>(null)

async function confirmDelete() {
  const slug = pendingDelete.value?.slug
  if (slug === undefined) return
  try {
    await remove.mutateAsync(slug)
    success('Content type deleted', `“${pendingDelete.value?.name ?? slug}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete content type')
  }
}
</script>

<template>
  <UDashboardPanel id="content-types">
    <template #header>
      <UDashboardNavbar title="Content types">
        <template #right>
          <UButton icon="i-lucide-plus" to="/settings/content-types/new">New content type</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <p class="text-sm text-muted">Define the structured content authors can create.</p>

      <UTable :data="data ?? []" :columns="columns" :loading="status === 'pending'">
        <template #name-cell="{ row }">
          <ULink
            :to="`/settings/content-types/${row.original.slug}`"
            class="font-medium text-default"
          >
            {{ row.original.name ?? row.original.slug }}
          </ULink>
        </template>

        <template #slug-cell="{ row }">
          <code class="text-xs text-muted">{{ row.original.slug }}</code>
        </template>

        <template #schema-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.schema?.length ?? 0 }}</span>
        </template>

        <template #status-cell="{ row }">
          <UBadge
            :color="row.original.status === 'active' ? 'success' : 'neutral'"
            variant="subtle"
          >
            {{ row.original.status ?? 'active' }}
          </UBadge>
        </template>

        <template #updated_at-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.updated_at ?? '—' }}</span>
        </template>

        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-pencil"
              aria-label="Edit"
              :to="`/settings/content-types/${row.original.slug}`"
            />
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-trash-2"
              aria-label="Delete"
              @click="
                () => {
                  pendingDelete = row.original
                }
              "
            />
          </div>
        </template>

        <template #empty>
          <UEmpty
            variant="naked"
            icon="i-lucide-shapes"
            title="No content types"
            description="Create your first content type to start authoring."
          >
            <template #actions>
              <UButton icon="i-lucide-plus" to="/settings/content-types/new">
                New content type
              </UButton>
            </template>
          </UEmpty>
        </template>
      </UTable>
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete !== null"
    title="Delete content type"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name ?? pendingDelete?.slug }}”</span>?
        Existing entries stay in storage but the type is hidden from listing and delivery.
      </p>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="
            () => {
              pendingDelete = null
            }
          "
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
</template>
