<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import {
  useCommerceDiscounts,
  useCommerceDiscountMutations,
  DISCOUNT_STATUSES,
  type CommerceDiscount,
} from '@/queries/commerceDiscounts'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'
import DiscountsTable from './components/DiscountsTable.vue'
import DiscountForm from './components/DiscountForm.vue'

const { success, error: notifyError } = useNotify()

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

// ── Filters ──────────────────────────────────────────────────────────────────
// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so the "All" option uses a non-empty sentinel, translated to `undefined` (no
// filter) at the query boundary (mirrors the products/orders list pages).
const ALL = 'all'
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const statusFilter = ref(ALL)
const page = ref(1)
const perPage = ref(24)

const statusFilterItems = [
  { label: 'All statuses', value: ALL },
  ...DISCOUNT_STATUSES.map((s) => ({ label: s, value: s })),
]

const filters = computed(() => ({
  status: statusFilter.value === ALL ? undefined : statusFilter.value,
  q: debouncedSearch.value || undefined,
  page: page.value,
  perPage: perPage.value,
}))

const { data, status: queryStatus } = useCommerceDiscounts(filters)
const rows = computed<CommerceDiscount[]>(() => data.value?.discounts ?? [])

const { remove } = useCommerceDiscountMutations()

// ── Create / edit (shared slideover) ─────────────────────────────────────────
const formOpen = ref(false)
const editingDiscount = ref<CommerceDiscount | null>(null)

function openCreate() {
  editingDiscount.value = null
  formOpen.value = true
}
function openEdit(discount: CommerceDiscount) {
  editingDiscount.value = discount
  formOpen.value = true
}

// ── Delete ───────────────────────────────────────────────────────────────────
const pendingDelete = ref<CommerceDiscount | null>(null)
async function confirmDelete() {
  const discount = pendingDelete.value
  if (!discount) return
  try {
    await remove.mutateAsync(discount.uuid)
    success('Discount deleted', `“${discount.code}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    // A discount with redemptions 409s with "…Disable it via status instead." — surfaced
    // verbatim rather than a generic failure message (DiscountRedeemedException).
    notifyError(e, 'Couldn’t delete discount')
    pendingDelete.value = null
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-discounts">
    <template #header>
      <UDashboardNavbar title="Discounts">
        <template #right>
          <UInput v-model="search" icon="i-lucide-search" placeholder="Search codes…" class="w-56" />
          <USelect v-model="statusFilter" :items="statusFilterItems" class="w-36" />
          <UButton v-if="canManage" icon="i-lucide-plus" data-test="new-discount" @click="openCreate">
            New discount
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <DiscountsTable
        :rows="rows"
        :status="queryStatus"
        :can-manage="canManage"
        @edit-request="openEdit"
        @delete-request="(row) => { pendingDelete = row }"
      />

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="discounts"
      />
    </template>
  </UDashboardPanel>

  <DiscountForm v-model:open="formOpen" :discount="editingDiscount" />

  <UModal
    :open="pendingDelete !== null"
    title="Delete discount"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.code }}”</span>? This can’t be undone.
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
          data-test="discount-delete-confirm"
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
