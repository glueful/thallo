<script setup lang="ts">
// Single-page product editor plan, Task C6 review fix: the phase×dirty → save-state chip mapping,
// pulled out of `EditorSectionCard.vue` so a card WITHOUT its own card-level `state` prop can still
// render the identical chip per subsection. This is the Organization card's case (spec §5.1 item
// 4): Categories/Tags/Attributes each keep their "own save control, state chip, and atomic
// endpoint" even though the Organization `EditorSectionCard` itself renders no card-level chip (a
// single aggregated chip there would duplicate/blur the three subsection chips — the nav indicator
// already aggregates them, see `[uuid]/index.vue`'s `organizationIndicator`).
//
// Plain `phase`/`dirty` values (not a `SectionState` object holding `Ref`s): every caller already
// has these unwrapped one way or another — `EditorSectionCard` reads `.value` explicitly off its
// prop-held `SectionState` (a plain prop object doesn't auto-unwrap nested refs), while the
// Categories/Tags/Attributes subsections pass their own top-level `phase`/`dirty` refs, which
// `<script setup>`'s template compiler already auto-unwraps.
import { computed } from 'vue'
import type { SectionPhase } from '@/composables/useSectionState'

const props = defineProps<{
  phase: SectionPhase
  dirty: boolean
}>()

type ChipColor = 'neutral' | 'error' | 'success' | 'warning'

// Order matters: a failed save is BOTH `error` and still `dirty` (Global Constraints §10) — the
// error chip takes precedence over the plain "unsaved changes" one.
const chip = computed<{ label: string; color: ChipColor } | null>(() => {
  if (props.phase === 'saving') return { label: 'Saving…', color: 'neutral' }
  if (props.phase === 'error') return { label: 'Save failed — unsaved changes', color: 'error' }
  if (props.phase === 'saved') return { label: 'Saved', color: 'success' }
  if (props.dirty) return { label: 'Unsaved changes', color: 'warning' }
  return null
})
</script>

<template>
  <UBadge v-if="chip" :color="chip.color" variant="subtle" size="sm">
    {{ chip.label }}
  </UBadge>
</template>
