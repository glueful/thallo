<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCommerceProduct, useCommerceProductMutations } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import ProductForm from '../components/ProductForm.vue'
import VariantsPanel from '../components/VariantsPanel.vue'
import MediaPanel from '../components/MediaPanel.vue'
import CategoriesTab from '../components/CategoriesTab.vue'
import ProductEntryLinkPanel from '@/components/commerce/ProductEntryLinkPanel.vue'

const route = useRoute()
const router = useRouter()
const uuid = computed(() => String(route.params.uuid))

const { success, error: notifyError } = useNotify()
const { data: product, status } = useCommerceProduct(uuid)
const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)
const { remove } = useCommerceProductMutations()

const tab = ref('details')
const tabItems = [
  { label: 'Details', value: 'details' },
  { label: 'Variants', value: 'variants' },
  { label: 'Media', value: 'media' },
  { label: 'Categories', value: 'categories' },
  // Task 12: the bidirectional product<->entry linkage panel, product-mode side.
  { label: 'Content', value: 'content' },
]

const pendingDelete = ref(false)
async function confirmDelete() {
  const p = product.value
  if (!p) return
  try {
    await remove.mutateAsync(p.uuid)
    success('Product deleted', `“${p.name}” was removed.`)
    pendingDelete.value = false
    router.push('/commerce/products')
  } catch (e) {
    notifyError(e, 'Couldn’t delete product')
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-product-detail">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/commerce/products"
            aria-label="Back to products"
          />
        </template>
        <template #title>{{ product?.name ?? 'Product' }}</template>
        <template #right>
          <UButton
            v-if="canManage"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            data-test="product-delete"
            @click="() => { pendingDelete = true }"
          >
            Delete
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="product-detail-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load this product"
        description="Something went wrong loading the product. Try again."
        data-test="product-detail-error"
      />

      <template v-else-if="product">
        <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" />

        <ProductForm
          v-if="tab === 'details'"
          :key="product.uuid"
          :product="product"
          :can-manage="canManage"
        />

        <VariantsPanel
          v-else-if="tab === 'variants'"
          :key="product.uuid"
          :product="product"
          :can-manage="canManage"
        />

        <MediaPanel
          v-else-if="tab === 'media'"
          :key="product.uuid"
          :product="product"
          :can-manage="canManage"
        />

        <CategoriesTab
          v-else-if="tab === 'categories'"
          :key="product.uuid"
          :product="product"
          :can-manage="canManage"
        />

        <ProductEntryLinkPanel
          v-else-if="tab === 'content'"
          :key="product.uuid"
          mode="product"
          :product-uuid="product.uuid"
        />
      </template>
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete"
    title="Delete product"
    @update:open="(v: boolean) => { if (!v) pendingDelete = false }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ product?.name }}”</span>? This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="() => { pendingDelete = false }"
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
