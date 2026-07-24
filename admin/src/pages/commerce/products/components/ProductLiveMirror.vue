<script setup lang="ts">
// Spec §5.4b (composed edit page, phase 3): the Live Mirror — the REAL storefront product page
// embedded beside the editor. Fidelity is free because it IS the storefront (the server-built
// absolute `storefront_url` from the product-link projection; the client never assembles shop
// URLs). The pane refreshes when the product's `updated_at` moves (every catalog-revision claim
// bumps it, so section saves reload the frame) and on the manual refresh control.
//
// HONESTY: the storefront refuses drafts (`ShopCatalogController::product` requires status
// `active`), so drafts get a truthful placeholder here — never a fake preview. A real
// draft-preview mode (authenticated render + cache bypass) is a noted follow-on, not smoke.
import { computed, ref } from 'vue'
import type { CommerceProduct } from '@/queries/commerceCatalog'

const props = defineProps<{ product: CommerceProduct; storefrontUrl: string | null }>()

const manualRefresh = ref(0)
const frameKey = computed(
  () => `${props.product.updated_at ?? ''}-${manualRefresh.value}`,
)

const showFrame = computed(() => props.product.status === 'active' && props.storefrontUrl !== null)
</script>

<template>
  <div
    class="overflow-hidden rounded-lg border border-default bg-default"
    data-test="live-mirror"
  >
    <div
      class="flex items-center justify-between gap-3 border-b border-default bg-elevated/40 px-3 py-2 text-xs text-muted"
    >
      <span class="inline-flex items-center gap-2">
        <span class="size-2 rounded-full" :class="showFrame ? 'bg-primary' : 'bg-accented'" />
        <span data-test="mirror-mode">
          {{ showFrame ? 'Live — your store, as customers see it' : 'Preview unavailable' }}
        </span>
      </span>
      <span class="inline-flex items-center gap-1">
        <UButton
          v-if="showFrame"
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-refresh-cw"
          aria-label="Refresh preview"
          data-test="mirror-refresh"
          @click="manualRefresh++"
        />
        <UButton
          v-if="storefrontUrl"
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-external-link"
          :to="storefrontUrl"
          target="_blank"
          aria-label="Open in a new tab"
          data-test="mirror-open"
        />
      </span>
    </div>

    <iframe
      v-if="showFrame && storefrontUrl"
      :key="frameKey"
      :src="storefrontUrl"
      title="Storefront preview"
      class="h-[70vh] w-full border-0 bg-white"
      data-test="mirror-frame"
    />
    <div
      v-else
      class="flex h-[40vh] flex-col items-center justify-center gap-2 px-6 text-center text-sm text-muted"
      data-test="mirror-draft-placeholder"
    >
      <UIcon name="i-lucide-eye-off" class="size-6" />
      <template v-if="product.status !== 'active'">
        <p class="font-medium text-default">The storefront can’t preview drafts yet.</p>
        <p class="text-xs">Activate the product to see it exactly as customers will.</p>
      </template>
      <p v-else class="text-xs">The storefront address isn’t available for this product.</p>
    </div>
  </div>
</template>
