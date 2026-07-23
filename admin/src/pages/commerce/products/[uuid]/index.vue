<script setup lang="ts">
// Single-page product editor plan, Task C4: the editor shell — spec §5.1 turns this page from a
// UTabs layout into ONE scrollable page of section cards with a sticky, scroll-spied nav (see
// `.superpowers/sdd/editor/task-C4-brief.md`). This task builds the SHELL only: card chrome
// (`EditorSectionCard`), the nav (`SectionNav`), the draft banner + Activate shortcut, and the
// page-level composables every section will wire into starting at C5 — `createDirtyRegistry()` +
// `useUnsavedGuard()` (Task C2) and `useProductRevisionCoordinator()` (Task C3), each called
// exactly ONCE here. No section here fetches its own data yet (the six per-product reads from
// Task C1 are consumed card-by-card in C5-C8) and no tab component's internals change — every
// existing panel (ProductForm/VariantsPanel/MediaPanel/...) renders unmodified inside a card.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCommerceProduct, useCommerceProductMutations } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useNotify } from '@/composables/useNotify'
import { createDirtyRegistry, useUnsavedGuard } from '@/composables/useSectionState'
import { useProductRevisionCoordinator } from '@/composables/useProductRevisionCoordinator'
import EditorSectionCard from '../components/EditorSectionCard.vue'
import SectionNav, { type SectionNavItem } from '../components/SectionNav.vue'
import ProductForm from '../components/ProductForm.vue'
import VariantsPanel from '../components/VariantsPanel.vue'
import MediaPanel from '../components/MediaPanel.vue'
import CategoriesTab from '../components/CategoriesTab.vue'
import TagsTab from '../components/TagsTab.vue'
import AttributesTab from '../components/AttributesTab.vue'
import AddonsPanel from '../components/AddonsPanel.vue'
import DownloadsPanel from '../components/DownloadsPanel.vue'
import ProductEntryLinkPanel from '@/components/commerce/ProductEntryLinkPanel.vue'

const route = useRoute()
const router = useRouter()
const uuid = computed(() => String(route.params.uuid))

const { success, error: notifyError } = useNotify()
const { data: product, status } = useCommerceProduct(uuid)
const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)
const { remove } = useCommerceProductMutations()

// Page-level state, wired exactly once here (Task C2 / Task C3 contracts). Sections register
// themselves against these starting at C5 — nothing on this page calls `useSectionState()` yet.
const dirtyRegistry = createDirtyRegistry()
useUnsavedGuard(dirtyRegistry)
useProductRevisionCoordinator()

const isDigital = computed(() => product.value?.type === 'digital')
const isGrouped = computed(() => product.value?.type === 'grouped')
const isDraft = computed(() => product.value?.status === 'draft')

// Nav indicators, for now: HONESTY over completeness (Task C4 brief). Until C5-C8 wire each
// card's real `useSectionState()`, every indicator is null EXCEPT Pricing's draft-only "Variants ·
// n" hint, which is computable from data this page already has (`product.variants`). No fabricated
// counts for sections whose data isn't loaded here (categories/tags/attributes/media/children all
// come from the Task C1 reads, which this shell deliberately does not fetch).
const navSections = computed<SectionNavItem[]>(() => {
  const p = product.value
  if (!p) return []
  const draft = p.status === 'draft'
  const items: SectionNavItem[] = [
    { id: 'details', label: 'Details', indicator: null },
    { id: 'media', label: 'Images', indicator: null },
    {
      id: 'pricing',
      label: 'Pricing & stock',
      indicator: draft ? 'hint' : null,
      hint: draft ? `Variants · ${p.variants.length}` : undefined,
    },
    { id: 'organization', label: 'Organization', indicator: null },
    { id: 'addons', label: 'Add-ons', indicator: null },
  ]
  if (p.type === 'digital') {
    items.push({ id: 'downloads', label: 'Downloads', indicator: null })
  }
  items.push({ id: 'content', label: 'Linked content', indicator: null })
  if (p.type === 'grouped') {
    items.push({ id: 'children', label: 'Grouped products', indicator: null })
  }
  return items
})

// Draft banner's Activate shortcut (spec §5.4): jumps to the Details card so the author can flip
// status themselves — this is a scroll shortcut ONLY, never a status mutation on its own.
function scrollToDetails(): void {
  document.getElementById('section-details')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

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
            @click="
              () => {
                pendingDelete = true
              }
            "
          >
            Delete
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="status === 'pending'"
        class="flex justify-center py-10"
        data-test="product-detail-loading"
      >
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
        <div
          v-if="isDraft"
          data-test="draft-banner"
          class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-elevated/50 px-4 py-3"
        >
          <div class="flex items-center gap-2 text-sm text-default">
            <UIcon name="i-lucide-pencil-ruler" class="size-4 shrink-0 text-muted" />
            <span>This product is a draft — not visible in the store yet.</span>
          </div>
          <UButton
            size="xs"
            variant="subtle"
            label="Activate"
            data-test="draft-activate-shortcut"
            @click="scrollToDetails"
          />
        </div>

        <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
          <div class="min-w-0 flex-1 space-y-6">
            <EditorSectionCard section-id="details" title="Details">
              <ProductForm :key="product.uuid" :product="product" :can-manage="canManage" />
            </EditorSectionCard>

            <EditorSectionCard section-id="media" title="Images">
              <MediaPanel :key="product.uuid" :product="product" :can-manage="canManage" />
            </EditorSectionCard>

            <EditorSectionCard section-id="pricing" title="Pricing & stock">
              <VariantsPanel :key="product.uuid" :product="product" :can-manage="canManage" />
            </EditorSectionCard>

            <!-- Organization: categories/tags/attributes stacked in ONE card (spec §5.1 item 4) —
                 each subsection keeps its own save control and atomic endpoint; only the card
                 chrome and grouping are new here. -->
            <EditorSectionCard section-id="organization" title="Organization">
              <div class="space-y-8">
                <CategoriesTab :key="product.uuid" :product="product" :can-manage="canManage" />
                <div class="border-t border-default pt-8">
                  <TagsTab :key="product.uuid" :product="product" :can-manage="canManage" />
                </div>
                <div class="border-t border-default pt-8">
                  <AttributesTab :key="product.uuid" :product="product" :can-manage="canManage" />
                </div>
              </div>
            </EditorSectionCard>

            <EditorSectionCard section-id="addons" title="Add-ons">
              <AddonsPanel :key="product.uuid" :product="product" :can-manage="canManage" />
            </EditorSectionCard>

            <EditorSectionCard v-if="isDigital" section-id="downloads" title="Downloads">
              <DownloadsPanel :key="product.uuid" :product="product" :can-manage="canManage" />
            </EditorSectionCard>

            <EditorSectionCard section-id="content" title="Linked content">
              <ProductEntryLinkPanel
                :key="product.uuid"
                mode="product"
                :product-uuid="product.uuid"
              />
            </EditorSectionCard>

            <!-- Grouped products: the actual composition editor still lives inside VariantsPanel's
                 "Child products" section (untouched, above) — nothing standalone exists yet, so
                 this card is a placeholder shell until ChildrenCard proper ships in Task C8. -->
            <EditorSectionCard v-if="isGrouped" section-id="children" title="Grouped products">
              <p class="text-sm text-muted" data-test="children-card-placeholder">
                Child products are managed from the
                <a href="#section-pricing" class="text-primary underline">Pricing & stock</a>
                section above for now. A dedicated editor lands here soon.
              </p>
            </EditorSectionCard>
          </div>

          <SectionNav :sections="navSections" />
        </div>
      </template>
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete"
    title="Delete product"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = false
      }
    "
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
          @click="
            () => {
              pendingDelete = false
            }
          "
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
