<script setup lang="ts">
// Single-page product editor plan, Task C4: the card chrome every section of the editor shell
// (`pages/commerce/products/[uuid]/index.vue`) renders inside — see
// `.superpowers/sdd/editor/task-C4-brief.md`. Chrome ONLY: this component owns the anchor id, the
// header + state chip, and nothing about a section's own content or save flow. `state` is the
// SAME `useSectionState()` return value (Task C2) a card wires up once it has a real save flow.
//
// Condensed-cards pass (spec §5.4b's approved composed mock): a `collapsible` card rests as a
// single summary row — title, one-line digest (`summary` prop or the richer `#summary` slot), and
// the state chip — and expands to the full editing panel on header click or when the shell says so
// (`collapsed` is CONTROLLED by the shell; this component only emits `toggle`). The body is hidden
// with CSS (`ui.body: 'hidden'`), never unmounted: every panel keeps its queries, section state,
// and coordinator registration alive across collapse/expand, so expanding is instant and no dirty
// draft can be lost to a remount.
//
// Attention beats collapse: a section holding anything the user could lose or must see — unsaved
// edits, an in-flight save, a failed save — refuses to collapse regardless of the `collapsed`
// prop. `forceExpanded` lets the shell extend the same rule to a card whose states live one level
// down (Organization's three subsections own their chips; the card itself has no `state`).
//
// Task C6 review fix: the phase×dirty → chip mapping itself lives in `SectionStateChip.vue`
// (shared with Organization's three subsections). `state` (when provided) holds real `Ref`s from
// `useSectionState()` — a plain prop object does NOT auto-unwrap nested refs the way a
// `reactive()` proxy would, so `.value` is read explicitly here rather than relying on template
// auto-unwrapping (which only applies to top-level refs owned by this component's own `setup()`).
import { computed } from 'vue'
import type { SectionState } from '@/composables/useSectionState'
import SectionStateChip from './SectionStateChip.vue'

const props = defineProps<{
  sectionId: string
  title: string
  state?: SectionState
  collapsible?: boolean
  /** Controlled by the shell — only meaningful when `collapsible`. */
  collapsed?: boolean
  /** One-line digest shown while collapsed; the `#summary` slot overrides it for rich content. */
  summary?: string
  /** Shell-side attention override for cards whose section states live in subsections. */
  forceExpanded?: boolean
}>()

const emit = defineEmits<{ toggle: [] }>()

const attentionExpanded = computed(() => {
  if (props.forceExpanded === true) return true
  const s = props.state
  if (!s) return false
  return s.dirty.value || s.phase.value === 'saving' || s.phase.value === 'error'
})

const effectiveCollapsed = computed(
  () => props.collapsible === true && props.collapsed === true && !attentionExpanded.value,
)
</script>

<template>
  <UCard
    :id="`section-${sectionId}`"
    :data-test="`editor-section-${sectionId}`"
    :data-collapsed="effectiveCollapsed ? 'true' : 'false'"
    class="scroll-mt-6"
    :ui="effectiveCollapsed ? { header: 'border-b-0', body: 'hidden' } : undefined"
  >
    <template #header>
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
          <h2 class="text-base font-semibold text-default">
            <button
              v-if="collapsible"
              type="button"
              class="flex w-full items-center gap-2 text-left"
              :aria-expanded="!effectiveCollapsed"
              :data-test="`editor-section-${sectionId}-toggle`"
              @click="emit('toggle')"
            >
              <UIcon
                name="i-lucide-chevron-right"
                class="size-4 shrink-0 text-muted transition-transform"
                :class="effectiveCollapsed ? '' : 'rotate-90'"
                aria-hidden="true"
              />
              <span>{{ title }}</span>
            </button>
            <template v-else>{{ title }}</template>
          </h2>
          <!-- Whole-row affordance: the summary line expands too (the button above stays the
               accessible control; this is a convenience target, hence aria-hidden-free plain p). -->
          <p
            v-if="effectiveCollapsed && (summary || $slots.summary)"
            class="mt-1 truncate pl-6 text-sm text-muted"
            :class="collapsible ? 'cursor-pointer' : ''"
            :data-test="`editor-section-${sectionId}-summary`"
            @click="collapsible && emit('toggle')"
          >
            <slot name="summary">{{ summary }}</slot>
          </p>
        </div>
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
