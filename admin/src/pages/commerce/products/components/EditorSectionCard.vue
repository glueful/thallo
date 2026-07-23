<script setup lang="ts">
// Single-page product editor plan, Task C4: the card chrome every section of the editor shell
// (`pages/commerce/products/[uuid]/index.vue`) renders inside — see
// `.superpowers/sdd/editor/task-C4-brief.md`. Chrome ONLY: this component owns the anchor id, the
// header + state chip, and nothing about a section's own content or save flow. `state` is the
// SAME `useSectionState()` return value (Task C2) a card wires up once it has a real save flow —
// C4 itself doesn't wire any (that starts at C5), so every card renders with `state` omitted for
// now and simply shows no chip.
//
// Task C6 review fix: the phase×dirty → chip mapping itself now lives in `SectionStateChip.vue`
// (shared with Organization's three subsections, which have no card-level `state` of their own —
// see that component's docblock). `state` (when provided) holds real `Ref`s from
// `useSectionState()` — a plain prop object does NOT auto-unwrap nested refs the way a
// `reactive()` proxy would, so `.value` is read explicitly here rather than relying on template
// auto-unwrapping (which only applies to top-level refs owned by this component's own `setup()`).
import type { SectionState } from '@/composables/useSectionState'
import SectionStateChip from './SectionStateChip.vue'

const props = defineProps<{
  sectionId: string
  title: string
  state?: SectionState
}>()
</script>

<template>
  <UCard
    :id="`section-${sectionId}`"
    :data-test="`editor-section-${sectionId}`"
    class="scroll-mt-6"
  >
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-base font-semibold text-default">{{ title }}</h2>
        <SectionStateChip
          :phase="props.state?.phase.value ?? 'idle'"
          :dirty="props.state?.dirty.value ?? false"
          :data-test="`editor-section-${sectionId}-chip`"
        />
      </div>
    </template>
    <slot />
  </UCard>
</template>
