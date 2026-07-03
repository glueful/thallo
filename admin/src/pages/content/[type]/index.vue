<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import { useEntries, useCreateEntry, useDeleteEntry, type EntryListRow } from '@/queries/entries'
import { useLocales } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const type = computed(() => String(route.params.type))

const { success, error: notifyError } = useNotify()
const { mutateAsync: createEntry, isLoading: creating } = useCreateEntry()
const { mutateAsync: deleteEntry, isLoading: deleting } = useDeleteEntry()

async function onCreate() {
  try {
    const uuid = await createEntry(type.value)
    if (uuid) router.push(`/content/${type.value}/${uuid}`)
  } catch (e) {
    notifyError(e, 'Could not create entry')
  }
}

// Delete confirmation: hold the pending row so the modal can name it.
const pendingDelete = ref<EntryListRow | null>(null)

async function confirmDelete() {
  const entry = pendingDelete.value
  if (!entry) return
  try {
    await deleteEntry({ uuid: entry.uuid, type: type.value })
    success('Entry deleted', `“${entry.display_title}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete entry')
  }
}

const page = ref(1)
const perPage = ref(20)
const search = ref('')
const debouncedSearch = refDebounced(search, 300)

const { data, status } = useEntries(type, page, perPage, debouncedSearch)

const { data: allLocales } = useLocales()
// Coverage is measured against currently-enabled locales, and only counts an entry's locales that are
// still enabled — otherwise a disabled-but-still-populated locale could read e.g. "3 / 2".
const enabledCodes = computed(
  () => new Set((allLocales.value ?? []).filter((l) => l.enabled).map((l) => l.code)),
)
const enabledCount = computed(() => enabledCodes.value.size)
// The design route is locale-addressed; from the list, open the DEFAULT locale
// (the same one the editor lands on) — 'en' until locales load.
const defaultLocale = computed(
  () => (allLocales.value ?? []).find((l) => l.is_default)?.code ?? 'en',
)
function translatedCount(locales: string[]): number {
  return locales.filter((c) => enabledCodes.value.has(c)).length
}

const columns: TableColumn<EntryListRow>[] = [
  { accessorKey: 'display_title', header: 'Title' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'locales', header: 'Locales' },
  { accessorKey: 'updated_at', header: 'Updated' },
  { id: 'actions', header: '' },
]

function statusColor(s: string): 'success' | 'warning' | 'neutral' {
  if (s === 'published') return 'success'
  if (s === 'scheduled') return 'warning'
  return 'neutral'
}
</script>

<template>
  <UDashboardPanel id="content-entries">
    <template #header>
      <UDashboardNavbar>
        <template #title
          ><span class="capitalize">{{ type }}</span></template
        >
        <template #right>
          <UInput v-model="search" icon="i-lucide-search" placeholder="Search…" class="w-64" />
          <UButton
            variant="subtle"
            color="neutral"
            icon="i-lucide-signpost"
            :to="`/content/${type}/redirects`"
          >
            Redirects
          </UButton>
          <UButton icon="i-lucide-plus" class="capitalize" :loading="creating" @click="onCreate">
            New {{ type }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="data?.entries ?? []" :columns="columns" :loading="status === 'pending'">
        <template #display_title-cell="{ row }">
          <ULink :to="`/content/${type}/${row.original.uuid}`" class="font-medium text-default">
            {{ row.original.display_title }}
          </ULink>
        </template>

        <template #status-cell="{ row }">
          <UBadge :color="statusColor(row.original.status)" variant="subtle">
            {{ row.original.status }}
          </UBadge>
        </template>

        <template #locales-cell="{ row }">
          <div class="flex items-center gap-2">
            <UBadge
              v-if="enabledCount > 1"
              :color="translatedCount(row.original.locales) >= enabledCount ? 'success' : 'neutral'"
              variant="subtle"
              size="sm"
            >
              {{ translatedCount(row.original.locales) }} / {{ enabledCount }}
            </UBadge>
            <div class="flex flex-wrap gap-1">
              <UBadge
                v-for="loc in row.original.locales"
                :key="loc"
                color="neutral"
                variant="outline"
                size="sm"
              >
                {{ loc }}
              </UBadge>
            </div>
          </div>
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
              :to="`/content/${type}/${row.original.uuid}`"
            />
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-layout-template"
              aria-label="Design"
              data-test="row-design-link"
              :to="`/content/${type}/${row.original.uuid}/design/${defaultLocale}`"
            />
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-trash-2"
              aria-label="Delete"
              @click="() => { pendingDelete = row.original }"
            />
          </div>
        </template>
      </UTable>

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="entries"
      />
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete !== null"
    title="Delete entry"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.display_title }}”</span>? This can’t be
        undone, and it will fail if other content still references this entry.
      </p>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="deleting"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          :loading="deleting"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
