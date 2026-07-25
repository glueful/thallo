<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import {
  useCommerceProducts,
  useCommerceProductMutations,
  PRODUCT_STATUSES,
  PRODUCT_TYPES,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'
import ProductsTable from './components/ProductsTable.vue'
import CategoriesTab from './components/CategoriesTab.vue'
import TagsTab from './components/TagsTab.vue'
import AttributesTab from './components/AttributesTab.vue'

const router = useRouter()
const { success, warning, error: notifyError } = useNotify()

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

// Task 10d: taxonomy lives as a tab within the Products AREA (design spec §6), not a separate
// nav item — CategoriesTab.vue (management mode: no `product` prop) mounts here.
// Task 19a: Tags joins as a third tab, same reasoning — TagsTab.vue (management mode) mounts here.
// Task 19b: Attributes joins as a fourth tab, same reasoning — AttributesTab.vue (management mode)
// mounts here.
const tab = ref<'products' | 'categories' | 'tags' | 'attributes'>('products')
const tabItems = [
  { label: 'Products', value: 'products' },
  { label: 'Categories', value: 'categories' },
  { label: 'Tags', value: 'tags' },
  { label: 'Attributes', value: 'attributes' },
]

// ── Filters ──────────────────────────────────────────────────────────────────
// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so the "All" option uses a non-empty sentinel, translated to `undefined` (no
// filter) at the query boundary.
const ALL = 'all'
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const statusFilter = ref(ALL)
const typeFilter = ref(ALL)
const page = ref(1)
const perPage = ref(25)

const statusFilterItems = [
  { label: 'All statuses', value: ALL },
  ...PRODUCT_STATUSES.map((s) => ({ label: s, value: s })),
]
const typeFilterItems = [
  { label: 'All types', value: ALL },
  ...PRODUCT_TYPES.map((t) => ({ label: t, value: t })),
]
const bulkStatusItems: Array<{ label: string; value: string }> = PRODUCT_STATUSES.map((s) => ({
  label: s,
  value: s,
}))

const filters = computed(() => ({
  status: statusFilter.value === ALL ? undefined : statusFilter.value,
  type: typeFilter.value === ALL ? undefined : typeFilter.value,
  q: debouncedSearch.value || undefined,
  page: page.value,
  perPage: perPage.value,
}))

const { data, status: queryStatus } = useCommerceProducts(filters)
const rows = computed<CommerceProduct[]>(() => data.value?.products ?? [])

const { remove, bulkStatus } = useCommerceProductMutations()

// ── Create ───────────────────────────────────────────────────────────────────
// Spec §5.4: the create slideover is gone — "New product" navigates to the full-page create
// route, which renders the editor shell in create mode.
function openCreate() {
  router.push('/commerce/products/new')
}

// ── Selection + bulk status ─────────────────────────────────────────────────
const selected = ref<string[]>([])
function toggleSelect(uuid: string) {
  selected.value = selected.value.includes(uuid)
    ? selected.value.filter((u) => u !== uuid)
    : [...selected.value, uuid]
}
function selectAllVisible() {
  const visible = rows.value.map((r) => r.uuid)
  const allSelected = visible.length > 0 && visible.every((u) => selected.value.includes(u))
  selected.value = allSelected ? [] : visible
}

const bulkTarget = ref('')
async function applyBulkStatus() {
  if (!bulkTarget.value || selected.value.length === 0) return
  try {
    const result = await bulkStatus.mutateAsync({ uuids: selected.value, status: bulkTarget.value })
    if (result.failed.length > 0) {
      warning(
        'Some products couldn’t be updated',
        `${result.applied.length} updated, ${result.failed.length} failed.`,
      )
    } else {
      success('Status updated', `${result.applied.length} product(s) set to “${bulkTarget.value}”.`)
    }
    selected.value = []
    bulkTarget.value = ''
  } catch (e) {
    notifyError(e, 'Couldn’t update product status')
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────
const pendingDelete = ref<CommerceProduct | null>(null)
async function confirmDelete() {
  const product = pendingDelete.value
  if (!product) return
  try {
    await remove.mutateAsync(product.uuid)
    success('Product deleted', `“${product.name}” was removed.`)
    selected.value = selected.value.filter((u) => u !== product.uuid)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete product')
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-products">
    <template #header>
      <UDashboardNavbar title="Products">
        <template #right>
          <UButton
            v-if="canManage && tab === 'products'"
            icon="i-lucide-plus"
            data-test="new-product"
            @click="openCreate"
          >
            New product
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" />

      <template v-if="tab === 'products'">
        <!-- The table toolbar (the Nuxt UI table layout): search on the left, filters on the
             right — moved out of the dashboard navbar so the controls sit with the data. -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search products…"
            class="w-64 max-w-full"
            data-test="products-search"
          />
          <div class="flex items-center gap-2">
            <USelect v-model="statusFilter" :items="statusFilterItems" class="w-36" />
            <USelect v-model="typeFilter" :items="typeFilterItems" class="w-36" />
          </div>
        </div>

        <div
          v-if="canManage && selected.length > 0"
          class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-default p-3"
          data-test="bulk-status-bar"
        >
          <span class="text-sm text-muted">{{ selected.length }} selected</span>
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            label="Clear"
            @click="() => { selected = [] }"
          />
          <USelect
            v-model="bulkTarget"
            :items="bulkStatusItems"
            placeholder="Set status…"
            class="w-40"
            data-test="bulk-status"
          />
          <UButton
            size="sm"
            label="Apply"
            data-test="bulk-status-apply"
            :disabled="!bulkTarget"
            :loading="bulkStatus.isLoading.value"
            @click="applyBulkStatus"
          />
        </div>

        <ProductsTable
          :rows="rows"
          :status="queryStatus"
          :can-manage="canManage"
          :selected="selected"
          @toggle-select="toggleSelect"
          @toggle-select-all="selectAllVisible"
          @delete-request="(row) => { pendingDelete = row }"
        />

        <TablePagination
          v-if="(data?.total ?? 0) > 0"
          v-model:page="page"
          v-model:per-page="perPage"
          :total="data?.total ?? 0"
          label="products"
        />
      </template>

      <CategoriesTab v-else-if="tab === 'categories'" :can-manage="canManage" />

      <TagsTab v-else-if="tab === 'tags'" :can-manage="canManage" />

      <AttributesTab v-else-if="tab === 'attributes'" :can-manage="canManage" />
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete !== null"
    title="Delete product"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This can’t be undone.
      </p>
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
          data-test="product-delete-confirm"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.commerce
</route>
