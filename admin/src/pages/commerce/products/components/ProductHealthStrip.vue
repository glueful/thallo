<script setup lang="ts">
// Spec §5.4b (composed edit page, approved 2026-07-24): the Command Center strip's Phase-1
// content — the Health card, shown for ACTIVE products only (drafts lead with the editor).
// Facts and counts only, computed from the already-shipped per-product reads (all shared
// Colada cache entries with the section cards — no extra requests): image count (media read),
// category count (categories read), low stock (stock read vs meta.low_stock_threshold). A
// stock-read failure renders an honest "unavailable" row — never fabricated zeros
// (StockIntegrityException semantics). Every warning row deep-links to its owning section.
// Phase 2 adds recent-orders + 30-day trade panels once the orders-by-product read exists.
import { computed } from 'vue'
import type { CommerceProduct } from '@/queries/commerceCatalog'
import {
  useProductCategories,
  useProductMedia,
  useProductStock,
} from '@/queries/commerceProductSections'
import { useCommerceMeta } from '@/queries/commerceMeta'

const props = defineProps<{ product: CommerceProduct }>()

const { data: meta } = useCommerceMeta()
const { data: media, status: mediaStatus } = useProductMedia(() => props.product.uuid)
const { data: categories, status: categoriesStatus } = useProductCategories(
  () => props.product.uuid,
)
const { data: stock, status: stockStatus } = useProductStock(() => props.product.uuid)

const imageCount = computed(() =>
  mediaStatus.value === 'success' ? (media.value?.items.length ?? 0) : null,
)
const categoryCount = computed(() =>
  categoriesStatus.value === 'success' ? (categories.value?.items.length ?? 0) : null,
)

/** Lowest TRACKED quantity, or null when nothing is tracked / the read hasn't settled. */
const lowestTracked = computed(() => {
  if (stockStatus.value !== 'success') return null
  const tracked = (stock.value?.items ?? []).filter((s) => s.tracked)
  if (tracked.length === 0) return null
  return tracked.reduce((min, s) => Math.min(min, s.quantity), Infinity)
})
const lowStock = computed(() => {
  const threshold = meta.value?.low_stock_threshold ?? 0
  return lowestTracked.value !== null && threshold > 0 && lowestTracked.value <= threshold
})

function jumpTo(sectionId: string): void {
  document
    .getElementById(`section-${sectionId}`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
</script>

<template>
  <UCard class="mb-4" data-test="product-health-strip">
    <p class="text-[0.68rem] font-bold tracking-wider text-muted uppercase">Health</p>
    <div class="mt-1.5 space-y-1 text-sm">
      <!-- Images -->
      <div class="flex items-center gap-2" data-test="health-images">
        <template v-if="imageCount === null">
          <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin text-muted" />
          <span class="text-muted">Images</span>
        </template>
        <template v-else-if="imageCount > 0">
          <UIcon name="i-lucide-circle-check" class="size-3.5 text-primary" />
          <span>{{ imageCount }} {{ imageCount === 1 ? 'image' : 'images' }}</span>
        </template>
        <template v-else>
          <UIcon name="i-lucide-circle-alert" class="size-3.5 text-warning" />
          <span>No images</span>
          <UButton
            size="xs"
            variant="link"
            label="→ Images"
            data-test="health-jump-media"
            @click="jumpTo('media')"
          />
        </template>
      </div>

      <!-- Categories -->
      <div class="flex items-center gap-2" data-test="health-categories">
        <template v-if="categoryCount === null">
          <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin text-muted" />
          <span class="text-muted">Categories</span>
        </template>
        <template v-else-if="categoryCount > 0">
          <UIcon name="i-lucide-circle-check" class="size-3.5 text-primary" />
          <span>{{ categoryCount }} {{ categoryCount === 1 ? 'category' : 'categories' }}</span>
        </template>
        <template v-else>
          <UIcon name="i-lucide-circle-alert" class="size-3.5 text-warning" />
          <span>No categories</span>
          <UButton
            size="xs"
            variant="link"
            label="→ Organization"
            data-test="health-jump-organization"
            @click="jumpTo('organization')"
          />
        </template>
      </div>

      <!-- Stock (tracked variants only; honest about read failure) -->
      <div class="flex items-center gap-2" data-test="health-stock">
        <template v-if="stockStatus === 'error'">
          <UIcon name="i-lucide-circle-help" class="size-3.5 text-muted" />
          <span class="text-muted">Stock data unavailable</span>
        </template>
        <template v-else-if="lowStock">
          <UIcon name="i-lucide-circle-alert" class="size-3.5 text-warning" />
          <span>Low stock — {{ lowestTracked }} left</span>
          <UButton
            size="xs"
            variant="link"
            label="→ Pricing & stock"
            data-test="health-jump-pricing"
            @click="jumpTo('pricing')"
          />
        </template>
        <template v-else-if="lowestTracked !== null">
          <UIcon name="i-lucide-circle-check" class="size-3.5 text-primary" />
          <span>{{ lowestTracked }} in stock</span>
        </template>
        <template v-else>
          <UIcon name="i-lucide-minus" class="size-3.5 text-muted" />
          <span class="text-muted">Stock not tracked</span>
        </template>
      </div>
    </div>
  </UCard>
</template>
