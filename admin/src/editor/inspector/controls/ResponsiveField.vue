<script setup lang="ts">
// One managed property (visual builder spec §3.4): the breakpoint indicator bound to the
// ACTIVE breakpoint, and per breakpoint the effective value, its source and its state —
// explicit, inherited, theme-default or reset — with Reset and "apply to all breakpoints" one
// click each. A non-responsive property shows a single value and no indicator.
import { computed } from 'vue'
import { resolve } from '@/style/resolver'
import { readPath, settingSegments } from '@/editor/ops/apply'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from '@/style/types'
import type { StylePropertyRow } from '@/queries/styleSchema'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import TokenScaleControl from './TokenScaleControl.vue'
import ChoiceControl from './ChoiceControl.vue'

const props = defineProps<{
  def: StylePropertyRow
  label: string
  /** The block's `settings.style`. */
  style: Record<string, unknown>
  classes: StyleClassRef[]
  activeBreakpoint: Breakpoint
  vocabulary: { domains: Record<string, string[]>; values: Record<string, string> }
  /** Class id => name, so a value inherited from a class is labelled by the class (spec §3.4). */
  classNames?: Record<string, string>
  reResolving?: boolean
  /** Every selected block's `settings.style` (a multi-selection, spec §5.5); `style` is the anchor's. */
  styles?: Record<string, unknown>[]
}>()
const emit = defineEmits<{
  /** Set (or clear with null) the value at one breakpoint (null breakpoint = non-responsive). */
  set: [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  /** Write the same value at every breakpoint. */
  'set-all': [path: string, value: StyleValue]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
}>()

const BREAKPOINTS: Breakpoint[] = ['base', 'md', 'lg']

const definition = computed(() => ({
  path: props.def.path,
  group: props.def.group,
  responsive: props.def.responsive,
  tokenDomain: props.def.token_domain,
  choices: props.def.choices,
}))

const resolutions = computed(
  () =>
    resolve(props.def.path, props.classes, props.style, definition.value) as Record<
      string,
      { value: StyleValue | null; source: string; state: string }
    >,
)

const breakpoint = computed<Breakpoint | null>(() =>
  props.def.responsive ? props.activeBreakpoint : null,
)
const current = computed(() => resolutions.value[breakpoint.value ?? 'base']!)
/** A multi-selection whose blocks resolve to different values here shows no value: mixed. */
const mixed = computed(() => {
  const styles = props.styles ?? []
  if (styles.length < 2) return false
  const bp = breakpoint.value ?? 'base'
  const at = (s: Record<string, unknown>) =>
    JSON.stringify(
      (resolve(props.def.path, props.classes, s, definition.value) as Record<string, Resolution>)[
        bp
      ]?.value ?? null,
    )
  const first = at(styles[0]!)
  return styles.some((s) => at(s) !== first)
})
const currentValue = computed(() => {
  if (mixed.value) return null
  const v = current.value.value
  return v && 'value' in v ? v.value : null
})

/** Which breakpoints carry an exact instance declaration (an indicator dot). */
const declaredAt = computed(() =>
  BREAKPOINTS.filter(
    (bp) => readPath(props.style, settingSegments(props.def.path, bp).slice(1)).present,
  ),
)

function toValue(raw: string): StyleValue {
  return props.def.token_domain !== null
    ? { type: 'token', value: raw }
    : { type: 'choice', value: raw }
}

function onPick(raw: string): void {
  emit('set', props.def.path, breakpoint.value, toValue(raw))
}

function reset(): void {
  emit('set', props.def.path, breakpoint.value, { type: 'reset' })
}

function clear(): void {
  emit('set', props.def.path, breakpoint.value, null)
}

function applyToAll(): void {
  if (currentValue.value === null) return
  emit('set-all', props.def.path, toValue(currentValue.value))
}

const stateLabel = computed(() => {
  if (mixed.value) return 'mixed'
  if (props.reResolving && current.value.state !== 'explicit') return 're-resolving'
  switch (current.value.state) {
    case 'explicit':
      return 'set'
    case 'inherited':
      return 'inherited'
    case 'reset':
      return 'reset'
    default:
      return 'theme'
  }
})

/** Where the value comes from, for the badge: the class by name, or here. */
const sourceLabel = computed(() => {
  const source = current.value.source
  if (source === 'instance') return 'set here'
  if (source.startsWith('class:')) {
    const id = source.slice('class:'.length)
    return `from ${props.classNames?.[id] ?? id}`
  }
  return 'theme default'
})
</script>

<template>
  <div class="space-y-1.5" :data-test="`style-field-${def.path}`">
    <div class="flex items-center justify-between gap-2">
      <span class="text-xs font-medium">{{ label }}</span>
      <div class="flex items-center gap-1">
        <span
          class="rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide"
          :class="
            current.state === 'explicit' && !mixed
              ? 'bg-primary/10 text-primary'
              : 'bg-elevated text-muted'
          "
          data-test="style-state"
          :data-source="current.source"
        >
          {{ stateLabel }}
        </span>
        <span
          v-if="current.source.startsWith('class:')"
          class="max-w-28 truncate text-[10px] text-muted"
          data-test="style-source"
        >
          {{ sourceLabel }}
        </span>
        <div v-if="def.responsive" class="flex gap-0.5" role="group" aria-label="Breakpoint">
          <button
            v-for="bp in BREAKPOINTS"
            :key="bp"
            type="button"
            class="relative rounded px-1.5 py-0.5 text-[10px]"
            :class="
              bp === activeBreakpoint ? 'bg-primary text-inverted' : 'text-muted hover:text-default'
            "
            :aria-pressed="bp === activeBreakpoint ? 'true' : 'false'"
            :title="BREAKPOINT_LABELS[bp]"
            :data-test="`breakpoint-${bp}`"
            @click="emit('update:activeBreakpoint', bp)"
          >
            {{ bp }}
            <span
              v-if="declaredAt.includes(bp)"
              class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
              aria-hidden="true"
            />
          </button>
        </div>
      </div>
    </div>
    <TokenScaleControl
      v-if="def.token_domain !== null"
      :domain="def.token_domain"
      :names="vocabulary.domains[def.token_domain] ?? []"
      :values="vocabulary.values"
      :model-value="currentValue !== null ? currentValue : null"
      @update:model-value="onPick"
    />
    <ChoiceControl
      v-else
      :choices="def.choices ?? []"
      :model-value="currentValue"
      :name="def.path"
      @update:model-value="onPick"
    />
    <div class="flex gap-2 text-[11px]">
      <button
        type="button"
        class="text-muted hover:text-default"
        data-test="style-reset"
        @click="reset()"
      >
        Reset to theme
      </button>
      <button
        v-if="current.state === 'explicit' || current.state === 'reset'"
        type="button"
        class="text-muted hover:text-default"
        data-test="style-clear"
        @click="clear()"
      >
        Clear
      </button>
      <button
        v-if="def.responsive && currentValue !== null"
        type="button"
        class="text-muted hover:text-default"
        data-test="style-apply-all"
        @click="applyToAll()"
      >
        Apply to all breakpoints
      </button>
    </div>
  </div>
</template>
