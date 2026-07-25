<script setup lang="ts">
// Single-page product editor plan, Task C6: the Organization card — spec §5.1 item 4 groups
// Categories, Tags, and Attributes into ONE card body as three independently-saving subsections.
// This component "hoists nothing": each subsection (`CategoriesTab`/`TagsTab`/`AttributesTab`, in
// their product-assignment mode) self-contains its own `useSectionState()` call and its own
// `ProductRevisionCoordinator` registration — mirroring `MediaPanel.vue`'s self-registration
// (Task C5) — exactly like every other section of this editor. This component is chrome + layout
// only: three subheadings separated by a divider, and a thin re-emit of each subsection's own
// `state` event (tagged with WHICH subsection it came from) so the shell (`[uuid]/index.vue`) can
// aggregate the three into ONE nav indicator (spec §5.1: "the nav indicator aggregates the three,
// worst state wins") without this component owning any of that aggregation itself.
import type { CommerceProduct } from '@/queries/commerceCatalog'
import type { SectionState } from '@/composables/useSectionState'
import CategoriesTab from './CategoriesTab.vue'
import TagsTab from './TagsTab.vue'
import AttributesTab from './AttributesTab.vue'

defineProps<{ product: CommerceProduct; canManage: boolean }>()
const emit = defineEmits<{
  state: [id: 'categories' | 'tags' | 'attributes', state: SectionState]
}>()
</script>

<template>
  <div class="space-y-8">
    <CategoriesTab
      :product="product"
      :can-manage="canManage"
      @state="(s) => emit('state', 'categories', s)"
    />
    <div class="border-t border-default pt-8">
      <TagsTab
        :product="product"
        :can-manage="canManage"
        @state="(s) => emit('state', 'tags', s)"
      />
    </div>
    <div class="border-t border-default pt-8">
      <AttributesTab
        :product="product"
        :can-manage="canManage"
        @state="(s) => emit('state', 'attributes', s)"
      />
    </div>
  </div>
</template>
