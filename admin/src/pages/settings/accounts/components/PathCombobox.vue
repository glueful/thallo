<script setup lang="ts">
import { computed, ref } from 'vue'
import type { AccountSuggestion } from '@/queries/accountSettings'

// A free-text input with a full-width suggestion dropdown. Unlike a native <datalist> (narrow
// browser popup + a black indicator icon), this is a styled combobox: type ANY path, or pick a
// suggestion. The typed value is always the model — suggestions are convenience only.
const props = withDefaults(
  defineProps<{
    modelValue: string
    suggestions: AccountSuggestion[]
    placeholder?: string
    testid?: string
  }>(),
  { placeholder: '', testid: undefined },
)
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const open = ref(false)
const active = ref(-1)
// On (re)focus we show the whole list so an operator can switch even from a filled field; only
// once they TYPE do we filter, so a saved value like `/account/orders` never hides `/account`.
const filtering = ref(false)

const value = computed({
  get: () => props.modelValue,
  set: (v: string) => emit('update:modelValue', v),
})

const filtered = computed<AccountSuggestion[]>(() => {
  if (!filtering.value) return props.suggestions
  const q = value.value.trim().toLowerCase()
  if (q === '') return props.suggestions
  return props.suggestions.filter(
    (s) => s.path.toLowerCase().includes(q) || s.label.toLowerCase().includes(q),
  )
})

let blurTimer: ReturnType<typeof setTimeout> | undefined

function openMenu(): void {
  if (blurTimer) clearTimeout(blurTimer)
  filtering.value = false
  active.value = -1
  open.value = true
}
function onInput(): void {
  if (blurTimer) clearTimeout(blurTimer)
  filtering.value = true
  active.value = -1
  open.value = true
}
function closeSoon(): void {
  // Delay so a mousedown on an option is honoured before the menu closes.
  blurTimer = setTimeout(() => (open.value = false), 120)
}
function pick(path: string): void {
  value.value = path
  open.value = false
  active.value = -1
}
function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    open.value = false
    return
  }
  if (!open.value && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
    open.value = true
    return
  }
  const items = filtered.value
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    active.value = Math.min(active.value + 1, items.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    active.value = Math.max(active.value - 1, 0)
  } else if (e.key === 'Enter' && active.value >= 0 && items[active.value]) {
    e.preventDefault()
    pick(items[active.value].path)
  }
}
</script>

<template>
  <div class="relative" @focusin="openMenu" @focusout="closeSoon" @keydown="onKeydown">
    <UInput
      v-model="value"
      :placeholder="placeholder"
      class="w-full"
      :data-testid="testid"
      autocomplete="off"
      @update:model-value="onInput"
    />
    <ul
      v-if="open && filtered.length > 0"
      class="absolute inset-x-0 top-full z-10 mt-1 max-h-64 overflow-auto rounded-md border border-default bg-default py-1 shadow-lg"
      :data-testid="testid ? `${testid}-menu` : undefined"
    >
      <li v-for="(s, i) in filtered" :key="s.path">
        <button
          type="button"
          class="flex w-full flex-col items-start px-3 py-1.5 text-left"
          :class="i === active ? 'bg-elevated' : 'hover:bg-elevated'"
          data-testid="path-suggestion"
          @mousedown.prevent="pick(s.path)"
          @mouseenter="active = i"
        >
          <span class="text-sm text-highlighted">{{ s.path }}</span>
          <span v-if="s.label && s.label !== s.path" class="text-xs text-muted">{{ s.label }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
