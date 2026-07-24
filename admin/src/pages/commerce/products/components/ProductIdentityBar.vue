<script setup lang="ts">
// Spec §5.4b (composed edit page, approved 2026-07-24): the identity bar — the page's spine,
// always present above the section cards. It DISPLAYS identity (thumbnail, name, slug · type ·
// price, status pill) and carries the primary action; it never edits in place — clicking the
// name jumps to Details' name input (one source of truth, no dual-bound draft). Replaces the
// C4 draft banner (its Activate shortcut moves here). Design reference:
// https://claude.ai/code/artifact/07f49d95-b8ec-4faf-88c2-7783bcd2b1c5
import { computed } from 'vue'
import type { CommerceProduct } from '@/queries/commerceCatalog'
import { useProductMedia } from '@/queries/commerceProductSections'
import { blobDisplayUrl } from '@/queries/media'
import { useMoney } from '@/composables/useMoney'

const props = defineProps<{
  product: CommerceProduct
  /** Server-built absolute storefront URL (product-link projection) — null while loading. */
  storefrontUrl?: string | null
  mirrorOpen?: boolean
}>()
// `jump` (not a local scrollIntoView): with the condensed-cards pass the target card may rest
// collapsed — the shell expands it first, THEN scrolls (same handler as the section nav).
const emit = defineEmits<{ 'toggle-mirror': []; jump: [sectionId: string] }>()

const { format } = useMoney()

// Shares the Images card's Colada cache entry (same query key) — no extra request.
const { data: media } = useProductMedia(() => props.product.uuid)

const thumbUrl = computed(() => {
  const first = media.value?.items[0]
  return first ? blobDisplayUrl(first.blob_uuid) : null
})

/** useMoney().format() throws until /commerce/meta resolves — same guard every card uses. */
const priceText = computed(() => {
  const variant = props.product.variants[0]
  if (!variant) return null
  try {
    return format(variant.price)
  } catch {
    return null
  }
})

const STATUS_COLOR: Record<string, 'info' | 'primary' | 'neutral'> = {
  draft: 'info',
  active: 'primary',
  archived: 'neutral',
}
const statusColor = computed(() => STATUS_COLOR[props.product.status] ?? 'neutral')
</script>

<template>
  <div
    class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-default bg-default px-4 py-3"
    data-test="product-identity-bar"
  >
    <button
      type="button"
      class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-md border border-dashed border-accented bg-elevated/50 text-muted"
      aria-label="Jump to images"
      data-test="identity-thumb"
      @click="emit('jump', 'media')"
    >
      <img
        v-if="thumbUrl"
        :src="thumbUrl"
        alt=""
        class="size-full object-cover"
        data-test="identity-thumb-img"
      />
      <UIcon v-else name="i-lucide-image-plus" class="size-4" />
    </button>

    <div class="min-w-0 flex-1">
      <button
        type="button"
        class="max-w-full truncate text-left text-base font-bold hover:underline"
        aria-label="Jump to details"
        data-test="identity-name"
        @click="emit('jump', 'details')"
      >
        {{ product.name }}
      </button>
      <p class="truncate text-xs text-muted" data-test="identity-meta">
        {{ product.slug }} · {{ product.type
        }}<template v-if="priceText"> · {{ priceText }}</template>
      </p>
    </div>

    <UBadge :color="statusColor" variant="subtle" data-test="identity-status">
      {{ product.status }}
    </UBadge>

    <!-- Spec §5.4b phase 3: the Live Mirror toggle — available in every state (drafts get the
         pane's honest placeholder). -->
    <UButton
      size="sm"
      color="neutral"
      :variant="mirrorOpen ? 'subtle' : 'ghost'"
      icon="i-lucide-app-window"
      :label="mirrorOpen ? 'Mirror on' : 'Mirror'"
      data-test="identity-mirror-toggle"
      @click="emit('toggle-mirror')"
    />

    <!-- Active products link out to the live page (server-built URL, never assembled here). -->
    <UButton
      v-if="product.status === 'active' && storefrontUrl"
      size="sm"
      color="neutral"
      variant="ghost"
      icon="i-lucide-external-link"
      label="View in store"
      :to="storefrontUrl"
      target="_blank"
      data-test="identity-view-in-store"
    />

    <!-- Drafts: the one action that matters. Scroll shortcut ONLY, never a mutation itself
         (same semantics as the C4 draft banner this bar replaces). -->
    <UButton
      v-if="product.status === 'draft'"
      size="sm"
      label="Activate"
      data-test="identity-activate"
      @click="emit('jump', 'details')"
    />
  </div>
</template>
