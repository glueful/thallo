<script setup lang="ts">
// Thin wrapper registered as the entry editor's "Commerce" side-tab (registry/entryEditorPanels.ts).
// The manifest binds `{ entryUuid: ctx.uuid }` onto this component (see `props:` there) — this is
// the ONE place that fixes the shared panel to entry-side mode, so the manifest itself stays
// mode-agnostic and the product-detail mount (products/[uuid]/index.vue) can bind `mode="product"`
// directly without going through a wrapper of its own.
import ProductEntryLinkPanel from '@/components/commerce/ProductEntryLinkPanel.vue'

defineProps<{ entryUuid: string }>()
</script>

<template>
  <ProductEntryLinkPanel mode="entry" :entry-uuid="entryUuid" />
</template>
