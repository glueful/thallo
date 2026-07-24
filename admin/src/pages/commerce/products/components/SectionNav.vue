<script lang="ts">
// Single-page product editor plan, Task C4: the sticky section nav for the editor shell — see
// `.superpowers/sdd/editor/task-C4-brief.md` and spec §5.1's "sticky right-hand section nav ...
// scroll-spies the active section and per-section shows, in precedence order: error > unsaved >
// empty-hint." The types/helper below are exported from a plain `<script>` block (named exports
// aren't allowed from `<script setup>`) so a card that aggregates several sub-section states into
// ONE nav indicator (e.g. Organization's categories/tags/attributes — spec §5.1 item 4: "the nav
// indicator aggregates the three, worst state wins") can reuse the exact same precedence rule
// rather than re-deriving it.

/** One nav item's resolved state. Already-resolved by the caller: `SectionNav` itself never
 * aggregates — a card with several sub-sections (Organization) computes its OWN single indicator
 * via `resolveSectionIndicator()` before handing it here. */
export type SectionNavIndicator = 'error' | 'unsaved' | 'hint' | null

export interface SectionNavItem {
  id: string
  label: string
  indicator: SectionNavIndicator
  /** Compact empty-hint text, e.g. "· 0" (the mock's form — the item label already names the
   * section) — draft-only, count-based. Hidden while an attention pill (`error`/`unsaved`) shows:
   * the pill outranks the hint on the same right-hand slot. */
  hint?: string
}

const PRECEDENCE: Record<Exclude<SectionNavIndicator, null>, number> = {
  error: 3,
  unsaved: 2,
  hint: 1,
}

/** Resolves several sub-section indicators into the ONE a card's nav item shows, honoring
 * precedence error > unsaved > hint > null (spec §5.1). */
export function resolveSectionIndicator(indicators: SectionNavIndicator[]): SectionNavIndicator {
  let best: SectionNavIndicator = null
  for (const indicator of indicators) {
    if (indicator === null) continue
    if (best === null || PRECEDENCE[indicator] > PRECEDENCE[best]) best = indicator
  }
  return best
}
</script>

<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps<{ sections: SectionNavItem[] }>()

// Condensed-cards pass: cards rest collapsed, so a raw `#section-…` anchor jump would land on a
// summary row BEFORE the shell expands it (wrong scroll offset once the card opens). Clicks are
// intercepted and emitted instead — the shell expands the target card first, then scrolls on
// nextTick. The href stays on the anchor purely as semantics/fallback.
const emit = defineEmits<{ navigate: [id: string] }>()

const activeId = ref<string | null>(props.sections[0]?.id ?? null)

let observer: IntersectionObserver | null = null

function disconnectObserver(): void {
  observer?.disconnect()
  observer = null
}

function observeSections(): void {
  disconnectObserver()
  // jsdom (this app's test environment) has no IntersectionObserver — feature-detect and no-op
  // rather than crash. `activeId` simply stays whatever it was (the first section, initially);
  // scroll-spy is a real-browser-only enhancement, never load-bearing for correctness.
  if (typeof IntersectionObserver === 'undefined') return

  observer = new IntersectionObserver(
    (entries) => {
      const intersecting = entries.filter((entry) => entry.isIntersecting)
      if (intersecting.length === 0) return
      // The section whose top edge is closest to the viewport's top among those currently
      // intersecting reads as "active" — matches how a reader's eye tracks scroll position.
      const top = intersecting.reduce((a, b) =>
        a.boundingClientRect.top <= b.boundingClientRect.top ? a : b,
      )
      const id = top.target.id.replace(/^section-/, '')
      if (id) activeId.value = id
    },
    // Shrinks the observed viewport to a thin band near the top so a section is only marked
    // active once it's actually the one under the reader's focus, not merely peeking into view.
    { rootMargin: '-96px 0px -70% 0px', threshold: 0 },
  )
  for (const section of props.sections) {
    const el = document.getElementById(`section-${section.id}`)
    if (el) observer.observe(el)
  }
}

onMounted(observeSections)
onUnmounted(disconnectObserver)
// Re-observe whenever the section LIST shape changes (e.g. Downloads/Children mounting once the
// product loads) — a plain re-render of the same ids doesn't need a new observer.
watch(() => props.sections.map((section) => section.id).join(','), observeSections)
</script>

<template>
  <!-- The mock's rail nav (xl+): a continuous hairline down the left, the active item marked by a
       primary accent segment + bolder text — never a filled background. Attention (error/unsaved)
       is a tinted pill with a filled dot on the right; quiet "· n" hints share that slot. On
       smaller screens the nav stays the horizontal chip list (no rail to draw). -->
  <nav
    data-test="section-nav"
    aria-label="Section navigation"
    class="xl:sticky xl:top-6 xl:w-56 xl:shrink-0 xl:self-start"
  >
    <ul
      class="flex gap-1 overflow-x-auto whitespace-nowrap pb-2 xl:flex-col xl:gap-0.5 xl:overflow-visible xl:border-l xl:border-default xl:pb-0"
    >
      <li v-for="section in sections" :key="section.id" class="shrink-0 xl:shrink">
        <a
          :href="`#section-${section.id}`"
          :data-test="`section-nav-${section.id}`"
          :data-indicator="section.indicator ?? undefined"
          :aria-current="activeId === section.id ? 'true' : undefined"
          class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors xl:-ml-px xl:rounded-none xl:border-l-2 xl:pl-3.5"
          :class="
            activeId === section.id
              ? 'bg-elevated font-medium text-default xl:border-primary xl:bg-transparent xl:font-semibold'
              : 'text-muted hover:bg-elevated/60 hover:text-default xl:border-transparent xl:hover:bg-transparent'
          "
          @click.prevent="emit('navigate', section.id)"
        >
          <span>{{ section.label }}</span>
          <span
            v-if="section.indicator === 'error' || section.indicator === 'unsaved'"
            class="ml-auto flex items-center rounded-full px-1.5 py-1"
            :class="section.indicator === 'error' ? 'bg-error/10' : 'bg-warning/10'"
            aria-hidden="true"
          >
            <span
              class="size-1.5 rounded-full"
              :class="section.indicator === 'error' ? 'bg-error' : 'bg-warning'"
            />
          </span>
          <span v-else-if="section.hint" class="ml-auto text-xs whitespace-nowrap text-muted">
            {{ section.hint }}
          </span>
        </a>
      </li>
    </ul>
  </nav>
</template>
