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
import { computed, nextTick, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCommerceProduct, useCommerceProductMutations } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import {
  useProductMedia,
  useProductCategories,
  useProductTags,
  useProductAttributes,
  useProductChildren,
  useProductStock,
} from '@/queries/commerceProductSections'
import { useMoney } from '@/composables/useMoney'
import { blobDisplayUrl } from '@/queries/media'
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
import ProductLiveMirror from '../components/ProductLiveMirror.vue'
import ProductEntryLinkPanel from '@/components/commerce/ProductEntryLinkPanel.vue'
import { useProductLink } from '@/queries/commerceLinking'

const route = useRoute()
const router = useRouter()
const uuid = computed(() => String(route.params.uuid))

const { success, error: notifyError } = useNotify()
const { data: product, status } = useCommerceProduct(uuid)
const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)
const { remove, update } = useCommerceProductMutations()

// Page-level state, wired exactly once here (Task C2 / Task C3 contracts).
const dirtyRegistry = createDirtyRegistry()
useUnsavedGuard(dirtyRegistry)
const coordinator = useProductRevisionCoordinator()

/** The identity bar's Activate — a REAL status mutation (user feedback 2026-07-24: the earlier
 * scroll-shortcut Activate read as "nothing happens"). Activation bumps the catalog revision, so
 * the coordinator refresh keeps every section's baseRevision current — the same discipline as any
 * section's own save. Reversible via Details' Status select. */
async function activateProduct(): Promise<void> {
  const p = product.value
  if (!p || p.status !== 'draft' || update.isLoading.value) return
  try {
    await update.mutateAsync({ uuid: p.uuid, input: { status: 'active' } })
    await coordinator.afterMutation()
    success('Product activated', `“${p.name}” is now live in your store.`)
  } catch (e) {
    notifyError(e, 'Couldn’t activate product')
  }
}

// Spec §5.4b phase 3: the Live Mirror. The server-built absolute storefront URL rides the
// product-link projection (ALWAYS present for an accessible product, link or no link) — the
// same Colada cache entry the Linked-content panel uses, so this adds no request.
const { data: linkProjection } = useProductLink(uuid)
const storefrontUrl = computed(() => linkProjection.value?.storefront_url ?? null)
const mirrorOpen = ref(false)

// ── Condensed cards (the approved composed mock's resting state) ───────────────────────────────
// Every stateful section card rests COLLAPSED as a one-line digest and expands on header click or
// nav click; a card holding unsaved edits / an in-flight save / a failed save refuses to collapse
// (EditorSectionCard's own attention rule). The rarely-touched tail — Add-ons, Downloads, Linked
// content — condenses further into ONE quiet row until asked for. Collapse hides with CSS only
// (`ui.body: 'hidden'`), so every panel stays mounted: queries, section states, and coordinator
// registrations survive collapse, and expanding is instant.
const expandedSections = reactive<Record<string, boolean>>({})
const tailExpanded = ref(false)
const TAIL_SECTION_IDS = ['addons', 'downloads', 'content']

function toggleSection(id: string): void {
  expandedSections[id] = !(expandedSections[id] ?? false)
}

/** Nav click: expand first (tail ids open the tail group), THEN scroll — a raw anchor jump would
 * measure the collapsed layout and land wrong once the card opens. */
function onNavigate(id: string): void {
  if (TAIL_SECTION_IDS.includes(id)) tailExpanded.value = true
  else expandedSections[id] = true
  void nextTick(() => {
    document.getElementById(`section-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

// Switching to a different product resets the page to its resting overview.
watch(uuid, () => {
  for (const key of Object.keys(expandedSections)) delete expandedSections[key]
  tailExpanded.value = false
})

// ── Collapsed-card digests ─────────────────────────────────────────────────────────────────────
// Same honesty discipline as the nav hints below: counts/quantities appear only once their read
// has actually resolved — never a fabricated value from a pending/errored query. Falls back to a
// muted field roster ("what lives here") until then.

const { format } = useMoney()
/** `useMoney().format()` throws until `/commerce/meta` resolves — same guard every card uses. */
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return String(minor)
  }
}

// The Pricing digest's stock quantity: a second, cheap subscriber to the SAME Colada key
// PricingStockCard owns (no extra request) — mirrors the `mediaSection` pattern below.
const { data: stockSection, status: stockSectionStatus } = useProductStock(uuid)

const detailsSummary = 'name · slug · description · status · tax class'

const pricingSummary = computed<string | undefined>(() => {
  const p = product.value
  if (!p) return undefined
  if (p.type === 'grouped') return 'Priced per child product'
  if (p.variants.length !== 1) return `${p.variants.length} variants`
  const v = p.variants[0]!
  const parts = [`SKU ${v.sku}`, money(v.price)]
  if (v.compare_at_price !== null) parts.push(`was ${money(v.compare_at_price)}`)
  if (stockSectionStatus.value === 'success') {
    const s = stockSection.value?.items.find((i) => i.variant_uuid === v.uuid)
    if (s?.tracked) parts.push(`${s.quantity} in stock`)
  }
  return parts.join(' · ')
})

const organizationSummary = computed<string>(() => {
  if (
    categoriesSectionStatus.value === 'success' &&
    categoriesSection.value &&
    tagsSectionStatus.value === 'success' &&
    tagsSection.value &&
    attributesSectionStatus.value === 'success' &&
    attributesSection.value
  ) {
    return (
      `Categories · ${categoriesSection.value.items.length} · ` +
      `Tags · ${tagsSection.value.items.length} · ` +
      `Attributes · ${attributesSection.value.items.length}`
    )
  }
  return 'categories · tags · attributes'
})

const childrenSummary = computed<string>(() => {
  if (childrenSectionStatus.value === 'success' && childrenSection.value) {
    return `Children · ${childrenSection.value.items.length}`
  }
  return 'child products'
})

/** Up to four thumbnails for the collapsed Images digest (the mock leads with the pictures). */
const mediaThumbs = computed(() => {
  if (mediaSectionStatus.value !== 'success') return []
  return (mediaSection.value?.items ?? [])
    .slice(0, 4)
    .map((item) => ({ uuid: item.uuid, url: blobDisplayUrl(item.blob_uuid) }))
})
const mediaExtraCount = computed(() => {
  const total = mediaSection.value?.items.length ?? 0
  return Math.max(0, total - mediaThumbs.value.length)
})
const mediaSummaryText = computed(() =>
  mediaSectionStatus.value === 'success' ? 'No images yet' : 'images',
)

/** Organization has no card-level `state` (its three subsections own their chips) — the shell
 * extends the attention rule instead: any subsection unsaved/erroring pins the card open. */
const organizationAttention = computed(() => {
  const aggregated = resolveSectionIndicator([
    stateIndicator(categoriesState.value),
    stateIndicator(tagsState.value),
    stateIndicator(attributesState.value),
  ])
  return aggregated === 'error' || aggregated === 'unsaved'
})

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
// Compact "· n" form (the mock's): the nav item label already names the section, and the card
// digests carry the verbose breakdown.
const mediaHint = computed<string | undefined>(() => {
  if (!isDraft.value) return undefined
  if (mediaSectionStatus.value !== 'success' || !mediaSection.value) return undefined
  return `· ${mediaSection.value.items.length}`
})

// Task C6: Organization's hint combines all three subsections' counts (total assignments) —
// draft-only, and `null` (undefined) while ANY of the three reads is still pending/errored, same
// "never a fabricated count" discipline as `mediaHint` above.
const organizationHint = computed<string | undefined>(() => {
  if (!isDraft.value) return undefined
  if (categoriesSectionStatus.value !== 'success' || !categoriesSection.value) return undefined
  if (tagsSectionStatus.value !== 'success' || !tagsSection.value) return undefined
  if (attributesSectionStatus.value !== 'success' || !attributesSection.value) return undefined
  return `· ${
    categoriesSection.value.items.length +
    tagsSection.value.items.length +
    attributesSection.value.items.length
  }`
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
  return `· ${childrenSection.value.items.length}`
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
  const pricingHint = draft ? `· ${p.variants.length}` : undefined
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
  ]
  // Grouped products' composition is core editing work — it sits with the stateful cards, ahead
  // of the quiet tail (Add-ons / Downloads / Linked content), matching the card order below.
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
  items.push({ id: 'addons', label: 'Add-ons', indicator: null })
  if (p.type === 'digital') {
    items.push({ id: 'downloads', label: 'Downloads', indicator: null })
  }
  items.push({ id: 'content', label: 'Linked content', indicator: null })
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
        <!-- The identity bar below owns the product's name (the mock's single spine) — repeating
             it here read as two stacked title bars. The navbar stays the quiet frame: back
             context + destructive action. -->
        <template #title>Products</template>
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
        <ProductIdentityBar
          :key="product.uuid"
          :product="product"
          :storefront-url="storefrontUrl"
          :mirror-open="mirrorOpen"
          :activating="update.isLoading.value"
          @toggle-mirror="mirrorOpen = !mirrorOpen"
          @jump="onNavigate"
          @activate="activateProduct"
        />
        <ProductHealthStrip
          v-if="product.status === 'active'"
          :key="`health-${product.uuid}`"
          :product="product"
          @jump="onNavigate"
        />

        <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
          <div class="min-w-0 flex-1 space-y-6">
            <EditorSectionCard
              section-id="details"
              title="Details"
              :state="detailsState ?? undefined"
              collapsible
              :collapsed="!expandedSections.details"
              :summary="detailsSummary"
              @toggle="toggleSection('details')"
            >
              <ProductForm
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="(s) => (detailsState = s)"
              />
            </EditorSectionCard>

            <EditorSectionCard
              section-id="media"
              title="Images"
              :state="mediaState ?? undefined"
              collapsible
              :collapsed="!expandedSections.media"
              @toggle="toggleSection('media')"
            >
              <template #summary>
                <span v-if="mediaThumbs.length > 0" class="flex items-center gap-1.5">
                  <img
                    v-for="thumb in mediaThumbs"
                    :key="thumb.uuid"
                    :src="thumb.url"
                    alt=""
                    class="size-8 rounded object-cover"
                  />
                  <span v-if="mediaExtraCount > 0" class="text-xs">+{{ mediaExtraCount }}</span>
                </span>
                <template v-else>{{ mediaSummaryText }}</template>
              </template>
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
              collapsible
              :collapsed="!expandedSections.pricing"
              :summary="pricingSummary"
              @toggle="toggleSection('pricing')"
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
                 `organizationIndicator` above). `force-expanded` extends the attention rule here
                 since the card itself has no state to refuse collapse with. -->
            <EditorSectionCard
              section-id="organization"
              title="Organization"
              collapsible
              :collapsed="!expandedSections.organization"
              :summary="organizationSummary"
              :force-expanded="organizationAttention"
              @toggle="toggleSection('organization')"
            >
              <OrganizationCard
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="onOrganizationState"
              />
            </EditorSectionCard>

            <EditorSectionCard
              v-if="isGrouped"
              section-id="children"
              title="Grouped products"
              :state="childrenState ?? undefined"
              collapsible
              :collapsed="!expandedSections.children"
              :summary="childrenSummary"
              @toggle="toggleSection('children')"
            >
              <ChildrenCard
                :key="product.uuid"
                :product="product"
                :can-manage="canManage"
                @state="(s) => (childrenState = s)"
              />
            </EditorSectionCard>

            <!-- The quiet tail (the mock's "Add-ons · Linked content …" row): rarely-touched,
                 immediate-mutation CRUD panels condensed into one row until asked for. The cards
                 stay MOUNTED behind v-show so their panels keep working state across the toggle. -->
            <button
              v-if="!tailExpanded"
              type="button"
              data-test="editor-tail-row"
              class="w-full rounded-lg bg-default px-4 py-3 text-left text-sm text-muted ring ring-default transition-colors hover:bg-elevated/60"
              @click="tailExpanded = true"
            >
              Add-ons ·{{ isDigital ? ' Downloads ·' : '' }} Linked content&ensp;…
            </button>
            <div v-show="tailExpanded" class="space-y-6">
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
            </div>
          </div>

          <!-- The Mirror trades places with the rail (spec §5.4b phase 3): scroll-spy nav for
               table work, the real storefront for visual work — never both fighting for width. -->
          <SectionNav v-if="!mirrorOpen" :sections="navSections" @navigate="onNavigate" />
          <div v-else class="w-full xl:sticky xl:top-4 xl:w-[44%] xl:shrink-0">
            <ProductLiveMirror :product="product" :storefront-url="storefrontUrl" />
          </div>
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
