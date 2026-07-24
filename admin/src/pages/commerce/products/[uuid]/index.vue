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
import { computed, ref, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCommerceProduct, useCommerceProductMutations } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import {
  useProductMedia,
  useProductCategories,
  useProductTags,
  useProductAttributes,
  useProductChildren,
} from '@/queries/commerceProductSections'
import { useNotify } from '@/composables/useNotify'
import {
  createDirtyRegistry,
  useUnsavedGuard,
  type SectionState,
} from '@/composables/useSectionState'
import { useProductRevisionCoordinator } from '@/composables/useProductRevisionCoordinator'
import EditorSectionCard from '../components/EditorSectionCard.vue'
import SectionNav, {
  resolveSectionIndicator,
  type SectionNavItem,
  type SectionNavIndicator,
} from '../components/SectionNav.vue'
import ProductForm from '../components/ProductForm.vue'
import PricingStockCard from '../components/PricingStockCard.vue'
import MediaPanel from '../components/MediaPanel.vue'
import OrganizationCard from '../components/OrganizationCard.vue'
import AddonsPanel from '../components/AddonsPanel.vue'
import DownloadsPanel from '../components/DownloadsPanel.vue'
import ChildrenCard from '../components/ChildrenCard.vue'
import ProductIdentityBar from '../components/ProductIdentityBar.vue'
import ProductHealthStrip from '../components/ProductHealthStrip.vue'
import ProductEntryLinkPanel from '@/components/commerce/ProductEntryLinkPanel.vue'

const route = useRoute()
const router = useRouter()
const uuid = computed(() => String(route.params.uuid))

const { success, error: notifyError } = useNotify()
const { data: product, status } = useCommerceProduct(uuid)
const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)
const { remove } = useCommerceProductMutations()

// Page-level state, wired exactly once here (Task C2 / Task C3 contracts).
const dirtyRegistry = createDirtyRegistry()
useUnsavedGuard(dirtyRegistry)
useProductRevisionCoordinator()

// Task C5: Details (ProductForm) and Images (MediaPanel) each own their own `useSectionState()`
// call and emit the resulting `SectionState` object ONCE, on mount — the shell just needs a live
// reference to hand to `EditorSectionCard`'s `state` prop (its chip reads `.phase.value`/
// `.dirty.value` reactively off the SAME refs) and to derive this card's nav indicator. An emit
// was chosen over hoisting `useSectionState()` up to this shell and passing the transition
// functions down as props: it keeps each card the sole owner of its own save flow (matching how
// C1's mutations/C3's coordinator registration already live inside the card, not here), and scales
// to C6-C9's remaining cards without growing this shell's own prop-plumbing per card.
//
// `shallowRef`, deliberately NOT `ref`: a plain `ref()` runs `toReactive()` on whatever object is
// assigned to it, which would silently wrap the emitted `SectionState` in a `reactive()` proxy —
// and `reactive()` auto-unwraps NESTED refs (`phase`, `dirty`) read off it, turning
// `detailsState.value.dirty` into a plain boolean frozen at assignment time instead of the live
// `Ref<boolean>` every consumer (`EditorSectionCard`'s chip, `stateIndicator` below) expects to
// read via `.value`. `shallowRef` stores the object as-is, preserving its own nested refs.
const detailsState = shallowRef<SectionState | null>(null)
const mediaState = shallowRef<SectionState | null>(null)
// Task C7: PricingStockCard emits its own 'pricing' SectionState the same emit-once way — its
// card-level chip AND this shell's nav indicator both read off it, combined (worst-wins, via
// `resolveSectionIndicator`) with the pre-existing draft-only "Variants · n" hint below.
const pricingState = shallowRef<SectionState | null>(null)
// Task C8: ChildrenCard emits its own 'children' SectionState the same emit-once way (grouped
// products only).
const childrenState = shallowRef<SectionState | null>(null)

// Task C6: Organization's three subsections (Categories/Tags/Attributes) each self-register their
// OWN `useSectionState()` inside `OrganizationCard` (which "hoists nothing" — see its own
// docblock) and re-emit their `state` tagged with which subsection it came from; this shell just
// needs live references to the three to compute ONE aggregated nav indicator (spec §5.1: "the nav
// indicator aggregates the three, worst state wins").
const categoriesState = shallowRef<SectionState | null>(null)
const tagsState = shallowRef<SectionState | null>(null)
const attributesState = shallowRef<SectionState | null>(null)

function onOrganizationState(id: 'categories' | 'tags' | 'attributes', s: SectionState): void {
  if (id === 'categories') categoriesState.value = s
  else if (id === 'tags') tagsState.value = s
  else attributesState.value = s
}

// Media's own section read (Task C1) — MediaPanel holds its own subscription to the SAME Colada
// query key for rendering; this is a second, cheap subscriber (no extra request) purely so the nav
// can show an honest, draft-only "Images · n" empty-hint (spec §5.1) without this shell owning any
// of MediaPanel's hydration/reorder logic. Same reasoning for the three Organization section reads
// below — each subsection ALSO holds its own subscription for its own hydration.
const { data: mediaSection, status: mediaSectionStatus } = useProductMedia(uuid)
const { data: categoriesSection, status: categoriesSectionStatus } = useProductCategories(uuid)
const { data: tagsSection, status: tagsSectionStatus } = useProductTags(uuid)
const { data: attributesSection, status: attributesSectionStatus } = useProductAttributes(uuid)
// Task C8: ChildrenCard holds its own subscription to the SAME Colada query key for hydration —
// this is a second, cheap subscriber (no extra request) purely for the nav's draft-only
// "Children · n" empty-hint, same reasoning as `mediaSection` above.
const { data: childrenSection, status: childrenSectionStatus } = useProductChildren(uuid)

const isDigital = computed(() => product.value?.type === 'digital')
const isGrouped = computed(() => product.value?.type === 'grouped')
const isDraft = computed(() => product.value?.status === 'draft')

/** A card's own save-state indicator (error > unsaved > null) — `null` when idle+clean or when the
 * card hasn't emitted its state yet (still mounting). */
function stateIndicator(state: SectionState | null): SectionNavIndicator {
  if (!state) return null
  if (state.phase.value === 'error') return 'error'
  if (state.dirty.value || state.phase.value === 'saving') return 'unsaved'
  return null
}

// Draft-only, count-based, and ONLY once the section's own read has actually resolved (spec §5.1:
// "while loading, indicator null") — never a fabricated count from a still-pending/errored query.
const mediaHint = computed<string | undefined>(() => {
  if (!isDraft.value) return undefined
  if (mediaSectionStatus.value !== 'success' || !mediaSection.value) return undefined
  return `Images · ${mediaSection.value.items.length}`
})

// Task C6: Organization's hint combines all three subsections' counts — draft-only, and `null`
// (undefined) while ANY of the three reads is still pending/errored, same "never a fabricated
// count" discipline as `mediaHint` above.
const organizationHint = computed<string | undefined>(() => {
  if (!isDraft.value) return undefined
  if (categoriesSectionStatus.value !== 'success' || !categoriesSection.value) return undefined
  if (tagsSectionStatus.value !== 'success' || !tagsSection.value) return undefined
  if (attributesSectionStatus.value !== 'success' || !attributesSection.value) return undefined
  return (
    `Categories · ${categoriesSection.value.items.length} · ` +
    `Tags · ${tagsSection.value.items.length} · ` +
    `Attributes · ${attributesSection.value.items.length}`
  )
})

// Task C6: worst-wins across the three subsection states PLUS the hint (spec §5.1: "the nav
// indicator aggregates the three, worst state wins") — reuses the same `resolveSectionIndicator`
// precedence (error > unsaved > hint) every other aggregation in this file already applies.
const organizationIndicator = computed<SectionNavIndicator>(() =>
  resolveSectionIndicator([
    stateIndicator(categoriesState.value),
    stateIndicator(tagsState.value),
    stateIndicator(attributesState.value),
    organizationHint.value ? 'hint' : null,
  ]),
)

// Task C8: same draft-only, count-based, load-resolved-only discipline as `mediaHint` above.
const childrenHint = computed<string | undefined>(() => {
  if (!isDraft.value) return undefined
  if (childrenSectionStatus.value !== 'success' || !childrenSection.value) return undefined
  return `Children · ${childrenSection.value.items.length}`
})

// Nav indicators: HONESTY over completeness (Task C4 brief), now extended by Task C5/C6/C7/C8's
// real Details/Images/Organization/Pricing/Grouped-products wiring. Add-ons/Downloads/Linked
// content stay null — they're immediate-mutation CRUD panels (every add/edit/delete commits
// straight away, no draft-and-save flow), so there is no `useSectionState()` phase/dirty to show.
const navSections = computed<SectionNavItem[]>(() => {
  const p = product.value
  if (!p) return []
  const draft = p.status === 'draft'
  const mediaIndicator = resolveSectionIndicator([
    stateIndicator(mediaState.value),
    mediaHint.value ? 'hint' : null,
  ])
  const pricingHint = draft ? `Variants · ${p.variants.length}` : undefined
  const pricingIndicator = resolveSectionIndicator([
    stateIndicator(pricingState.value),
    pricingHint ? 'hint' : null,
  ])
  const items: SectionNavItem[] = [
    { id: 'details', label: 'Details', indicator: stateIndicator(detailsState.value) },
    { id: 'media', label: 'Images', indicator: mediaIndicator, hint: mediaHint.value },
    {
      id: 'pricing',
      label: 'Pricing & stock',
      indicator: pricingIndicator,
      hint: pricingHint,
    },
    {
      id: 'organization',
      label: 'Organization',
      indicator: organizationIndicator.value,
      hint: organizationHint.value,
    },
    { id: 'addons', label: 'Add-ons', indicator: null },
  ]
  if (p.type === 'digital') {
    items.push({ id: 'downloads', label: 'Downloads', indicator: null })
  }
  items.push({ id: 'content', label: 'Linked content', indicator: null })
  if (p.type === 'grouped') {
    const childrenIndicator = resolveSectionIndicator([
      stateIndicator(childrenState.value),
      childrenHint.value ? 'hint' : null,
    ])
    items.push({
      id: 'children',
      label: 'Grouped products',
      indicator: childrenIndicator,
      hint: childrenHint.value,
    })
  }
  return items
})

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
        <!-- Spec §5.4b: the identity bar is the page's spine (replaces the C4 draft banner —
             its Activate shortcut lives in the bar now); the Health strip opens ACTIVE
             products only (drafts lead with the editor). -->
        <ProductIdentityBar :key="product.uuid" :product="product" />
        <ProductHealthStrip
          v-if="product.status === 'active'"
          :key="`health-${product.uuid}`"
          :product="product"
        />

        <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
          <div class="min-w-0 flex-1 space-y-6">
            <EditorSectionCard
              section-id="details"
              title="Details"
              :state="detailsState ?? undefined"
            >
              <ProductForm
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="(s) => (detailsState = s)"
              />
            </EditorSectionCard>

            <EditorSectionCard section-id="media" title="Images" :state="mediaState ?? undefined">
              <MediaPanel
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="(s) => (mediaState = s)"
              />
            </EditorSectionCard>

            <EditorSectionCard
              section-id="pricing"
              title="Pricing & stock"
              :state="pricingState ?? undefined"
            >
              <PricingStockCard
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="(s) => (pricingState = s)"
              />
            </EditorSectionCard>

            <!-- Organization: categories/tags/attributes stacked in ONE card (spec §5.1 item 4) —
                 each subsection keeps its own save control and atomic endpoint; the card's own
                 header chip is omitted (no single `state` prop given) since each subsection shows
                 its OWN chip — only the nav indicator aggregates the three (see
                 `organizationIndicator` above). -->
            <EditorSectionCard section-id="organization" title="Organization">
              <OrganizationCard
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="onOrganizationState"
              />
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

            <EditorSectionCard
              v-if="isGrouped"
              section-id="children"
              title="Grouped products"
              :state="childrenState ?? undefined"
            >
              <ChildrenCard
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="(s) => (childrenState = s)"
              />
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
