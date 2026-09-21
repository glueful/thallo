<script setup lang="ts">
// The Appearance page's theme chooser: each selectable theme as a card — its screenshot, what it
// calls itself, who made it. Choosing one is only a choice (the page's Save applies it, like
// every field beside it), so this is a radio group: one chosen card, arrow keys move the choice,
// and only the chosen card is in the tab order. The live theme is marked; a chosen theme that is
// not live yet says what will make it so. The preview pane beside it shows the chosen theme.
import { computed, reactive } from 'vue'
import type { ThemeCard } from '@/queries/templates'

const props = defineProps<{
  cards: ThemeCard[]
  /** The chosen theme's name (the form's value). */
  modelValue: string
  /** The theme the site is serving now (the saved value). */
  live: string
}>()
const emit = defineEmits<{ 'update:modelValue': [name: string] }>()

// A screenshot that fails to load (a deleted file, a host that does not proxy /_thallo) gives
// way to the drawn thumbnail rather than a broken image.
const failed = reactive(new Set<string>())

// With nothing chosen yet the first card takes the tab stop, so the group is reachable.
const tabStop = computed(() =>
  props.cards.some((c) => c.name === props.modelValue)
    ? props.modelValue
    : (props.cards[0]?.name ?? ''),
)

function thumbStyle(card: ThemeCard): Record<string, string> {
  const out: Record<string, string> = {}
  if (card.colors?.background) out['--thumb-bg'] = card.colors.background
  if (card.colors?.text) out['--thumb-text'] = card.colors.text
  if (card.colors?.accent) out['--thumb-accent'] = card.colors.accent
  return out
}

function onKeydown(event: KeyboardEvent, index: number): void {
  const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key]
  if (step === undefined || props.cards.length === 0) return
  event.preventDefault()
  const to = (index + step + props.cards.length) % props.cards.length
  const next = props.cards[to]
  if (!next) return
  emit('update:modelValue', next.name)
  // The cards are the group's children, in order: focus follows the choice.
  const group = (event.currentTarget as HTMLElement).parentElement
  ;(group?.children[to] as HTMLElement | undefined)?.focus()
}
</script>

<template>
  <div
    role="radiogroup"
    aria-label="Theme"
    class="grid gap-3"
    :class="cards.length > 1 ? 'grid-cols-2' : 'grid-cols-1'"
    data-test="theme-gallery"
  >
    <button
      v-for="(card, index) in cards"
      :key="card.name"
      type="button"
      role="radio"
      :aria-checked="card.name === modelValue ? 'true' : 'false'"
      :tabindex="card.name === tabStop ? 0 : -1"
      :data-test="`theme-option-${card.name}`"
      class="group flex flex-col overflow-hidden rounded-lg bg-default text-left ring transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      :class="card.name === modelValue ? 'ring-2 ring-primary' : 'ring-default hover:ring-accented'"
      @click="emit('update:modelValue', card.name)"
      @keydown="onKeydown($event, index)"
    >
      <span
        class="relative block aspect-[4/3] w-full overflow-hidden border-b border-default bg-elevated"
      >
        <img
          v-if="card.screenshot_url && !failed.has(card.name)"
          :src="card.screenshot_url"
          alt=""
          loading="lazy"
          decoding="async"
          class="size-full object-cover object-top"
          @error="failed.add(card.name)"
        />
        <!-- No screenshot: a small page drawn in the theme's own colours (theme.json `colors`),
             or in neutral ones when it names none. Never a blank box. -->
        <span
          v-else
          class="theme-thumb flex size-full flex-col gap-[6%] p-[8%]"
          :style="thumbStyle(card)"
          data-test="theme-thumbnail-drawn"
          aria-hidden="true"
        >
          <span class="flex items-center justify-between">
            <span class="theme-thumb__ink h-[5px] w-1/4 rounded-full opacity-80" />
            <span class="theme-thumb__ink h-[4px] w-1/3 rounded-full opacity-30" />
          </span>
          <span class="mt-[6%] flex flex-col items-center gap-[5px]">
            <span class="theme-thumb__ink h-[8px] w-3/4 rounded-full" />
            <span class="theme-thumb__ink h-[4px] w-1/2 rounded-full opacity-40" />
            <span class="theme-thumb__accent mt-[3px] h-[10px] w-1/4 rounded-full" />
          </span>
          <span class="mt-auto grid grid-cols-3 gap-[6%]">
            <span v-for="n in 3" :key="n" class="theme-thumb__box aspect-[4/3] rounded-[3px]" />
          </span>
        </span>
        <UBadge
          v-if="card.name === live"
          color="success"
          variant="solid"
          size="sm"
          class="absolute start-1.5 top-1.5"
          data-test="theme-live"
        >
          Live
        </UBadge>
      </span>
      <span class="flex min-w-0 flex-1 flex-col gap-0.5 p-2.5">
        <span class="flex min-w-0 items-baseline gap-1.5">
          <span class="truncate text-sm font-semibold text-default">{{ card.title }}</span>
          <span v-if="card.version" class="shrink-0 text-[11px] text-muted tabular-nums">
            {{ card.version }}
          </span>
        </span>
        <span v-if="card.author" class="truncate text-[11px] text-muted">by {{ card.author }}</span>
        <span
          v-if="card.description"
          class="line-clamp-2 text-xs text-toned"
          :title="card.description"
        >
          {{ card.description }}
        </span>
        <span v-if="card.tags.length" class="truncate text-[11px] text-dimmed">
          {{ card.tags.join(' · ') }}
        </span>
        <span
          v-if="card.name === modelValue && card.name !== live"
          class="mt-1 text-[11px] font-medium text-primary"
          data-test="theme-pending"
        >
          Chosen — Save to make it live
        </span>
      </span>
    </button>
  </div>
</template>

<style scoped>
.theme-thumb {
  background: var(--thumb-bg, var(--ui-bg));
}
.theme-thumb__ink {
  background: var(--thumb-text, var(--ui-text-highlighted));
}
.theme-thumb__accent {
  background: var(--thumb-accent, var(--ui-primary));
}
.theme-thumb__box {
  background: color-mix(in srgb, var(--thumb-text, var(--ui-text-highlighted)) 10%, transparent);
}
</style>
