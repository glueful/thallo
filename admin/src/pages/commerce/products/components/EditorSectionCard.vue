<script setup lang="ts">
// Single-page product editor plan, Task C4: the card chrome every section of the editor shell
// (`pages/commerce/products/[uuid]/index.vue`) renders inside — see
// `.superpowers/sdd/editor/task-C4-brief.md`. Chrome ONLY: this component owns the anchor id, the
// header + state chip, and nothing about a section's own content or save flow. `state` is the
// SAME `useSectionState()` return value (Task C2) a card wires up once it has a real save flow —
// C4 itself doesn't wire any (that starts at C5), so every card renders with `state` omitted for
// now and simply shows no chip.
import { computed } from 'vue'
import type { SectionState } from '@/composables/useSectionState'

const props = defineProps<{
  sectionId: string
  title: string
  state?: SectionState
}>()

type ChipColor = 'neutral' | 'error' | 'success' | 'warning'

// `state` (when provided) holds real `Ref`s from `useSectionState()` — a plain prop object does
// NOT auto-unwrap nested refs the way a `reactive()` proxy would, so read `.value` explicitly
// here rather than relying on template auto-unwrapping (which only applies to top-level refs
// owned by this component's own `setup()`).
const chip = computed<{ label: string; color: ChipColor } | null>(() => {
  const phase = props.state?.phase.value ?? 'idle'
  const dirty = props.state?.dirty.value ?? false
  // Order matters: a failed save is BOTH `error` and still `dirty` (Global Constraints §10) — the
  // error chip takes precedence over the plain "unsaved changes" one.
  if (phase === 'saving') return { label: 'Saving…', color: 'neutral' }
  if (phase === 'error') return { label: 'Save failed — unsaved changes', color: 'error' }
  if (phase === 'saved') return { label: 'Saved', color: 'success' }
  if (dirty) return { label: 'Unsaved changes', color: 'warning' }
  return null
})
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
        <UBadge
          v-if="chip"
          :color="chip.color"
          variant="subtle"
          size="sm"
          :data-test="`editor-section-${sectionId}-chip`"
        >
          {{ chip.label }}
        </UBadge>
      </div>
    </template>
    <slot />
  </UCard>
</template>
