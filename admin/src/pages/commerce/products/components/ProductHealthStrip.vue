<script setup lang="ts">
// Spec §5.4b (composed edit page, approved 2026-07-24): the Command Center strip, shown for
// ACTIVE products only (drafts lead with the editor).
// - Health card (phase 1): facts and counts only, computed from the already-shipped per-product
//   reads (all shared Colada cache entries with the section cards — no extra requests): image
//   count (media read), category count (categories read), low stock (stock read vs
//   meta.low_stock_threshold). A stock-read failure renders an honest "unavailable" row — never
//   fabricated zeros (StockIntegrityException semantics). Warning rows deep-link to sections.
// - Trade tile + Recent orders (phase 2, commerce 1.6.0's per-product order activity read):
//   render ONLY when the activity query succeeds — an admin running against an older commerce
//   simply doesn't get these panels (graceful absence, never an error banner).
import { computed } from 'vue'
import type { CommerceProduct } from '@/queries/commerceCatalog'
import {
  useProductCategories,
  useProductMedia,
  useProductStock,
} from '@/queries/commerceProductSections'
import { useProductOrderActivity } from '@/queries/commerceOrders'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney } from '@/composables/useMoney'

const props = defineProps<{ product: CommerceProduct }>()

const { data: meta } = useCommerceMeta()
const { format } = useMoney()
const { data: media, status: mediaStatus } = useProductMedia(() => props.product.uuid)
const { data: categories, status: categoriesStatus } = useProductCategories(
  () => props.product.uuid,
)
const { data: stock, status: stockStatus } = useProductStock(() => props.product.uuid)
const { data: activity, status: activityStatus } = useProductOrderActivity(() => props.product.uuid)

const showActivity = computed(
  () => activityStatus.value === 'success' && activity.value !== undefined,
)

/** useMoney().format() throws until /commerce/meta resolves — same guard every card uses. */
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return String(minor)
  }
}

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
  <div
    class="mb-4 grid gap-3"
    :class="showActivity ? 'sm:grid-cols-3' : ''"
    data-test="product-health-strip"
  >
    <UCard>
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

    <!-- Phase 2 panels — present ONLY when commerce serves the order-activity read. -->
    <UCard v-if="showActivity && activity" data-test="product-trade-tile">
      <p class="text-[0.68rem] font-bold tracking-wider text-muted uppercase">
        Last {{ activity.window_days }} days
      </p>
      <p class="mt-1 text-lg font-bold" data-test="trade-revenue">
        {{ money(activity.summary.revenue_minor) }}
      </p>
      <p class="text-xs text-muted" data-test="trade-orders">
        {{ activity.summary.orders }} {{ activity.summary.orders === 1 ? 'order' : 'orders' }}
        · this product’s share
      </p>
    </UCard>

    <UCard v-if="showActivity && activity" data-test="product-recent-orders">
      <p class="text-[0.68rem] font-bold tracking-wider text-muted uppercase">Recent orders</p>
      <div v-if="activity.recent.length === 0" class="mt-1.5 text-xs text-muted">
        No orders containing this product yet.
      </div>
      <div v-else class="mt-1.5 space-y-1 text-xs">
        <RouterLink
          v-for="order in activity.recent"
          :key="order.uuid"
          :to="`/commerce/orders/${order.uuid}`"
          class="flex items-center justify-between gap-2 rounded px-1 py-0.5 hover:bg-elevated/60"
          data-test="recent-order-row"
        >
          <span class="truncate font-medium">{{ order.order_number }}</span>
          <span class="text-muted">{{ money(order.grand_total) }}</span>
          <UBadge size="sm" color="neutral" variant="subtle">{{ order.status }}</UBadge>
        </RouterLink>
      </div>
    </UCard>
  </div>
</template>
