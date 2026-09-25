<script setup lang="ts">
// One managed property (visual builder spec §3.4): the breakpoint indicator bound to the
// ACTIVE breakpoint, and per breakpoint the effective value, its source and its state —
// explicit, inherited, theme-default or reset — with Reset and "apply to all breakpoints" one
// click each. A non-responsive property shows a single value and no indicator.
//
// In a style class (`context="class"`, container-layout spec §12.4) the same row says what the
// CLASS declares — there is no theme default "in force" to report, only whether this class says
// anything — and its two actions are named for what they do: Remove and Use theme default. The
// block inspector, the default context, is unchanged.
import { computed } from 'vue'
import { resolve } from '@/style/resolver'
import { readPath, settingSegments } from '@/editor/ops/apply'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from '@/style/types'
import type { StylePropertyRow } from '@/queries/styleSchema'
import { BREAKPOINT_LABELS } from '@/editor/breakpoint'
import { classFieldState } from '@/editor/inspector/classFieldState'
import { CHOICE_LABELS } from '@/editor/inspector/choiceLabels'
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
  /** Hide this row's breakpoint chips: the Style tab's group header carries them instead. */
  hideBreakpoints?: boolean
  /** Where the row is: a block's inspector (the default) or a style class's editor. */
  context?: 'block' | 'class' | 'region' | 'part'
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
const inClass = computed(() => props.context === 'class')
/** What the class itself declares here: its label, and whether there is anything to remove. */
const classState = computed(() =>
  classFieldState(
    definition.value,
    props.style,
    props.activeBreakpoint,
    props.def.token_domain !== null
      ? (props.vocabulary.domains[props.def.token_domain] ?? []).map(
          (name) => `${props.def.token_domain}.${name}`,
        )
      : undefined,
  ),
)
const currentValue = computed(() => {
  if (mixed.value) return null
  // A class presents a value only where it supplies one that the contract offers: a reset, an
  // absence and an invalid value press nothing (spec §12.4, §12.5).
  if (inClass.value) {
    const kind = classState.value.kind
    if (kind !== 'set' && kind !== 'inherited') return null
  }
  const v = current.value.value
  return v && 'value' in v ? v.value : null
})
/** A stored value the contract does not offer, in words: it is never shown as a choice. */
const invalidValue = computed(() => {
  const v = classState.value.value
  return inClass.value && classState.value.kind === 'invalid' && v && 'value' in v ? v.value : null
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
  if (inClass.value) return classState.value.label
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
            inClass
              ? classState.kind === 'invalid'
                ? 'bg-error/10 text-error normal-case'
                : classState.kind === 'set' || classState.kind === 'reset-here'
                  ? 'bg-primary/10 text-primary normal-case'
                  : 'bg-elevated text-muted normal-case'
              : current.state === 'explicit' && !mixed
                ? 'bg-primary/10 text-primary'
                : 'bg-elevated text-muted'
          "
          data-test="style-state"
          :data-source="current.source"
          :data-kind="inClass ? classState.kind : undefined"
        >
          {{ stateLabel }}
        </span>
        <span
          v-if="!inClass && current.source.startsWith('class:')"
          class="max-w-28 truncate text-[10px] text-muted"
          data-test="style-source"
        >
          {{ sourceLabel }}
        </span>
        <span
          v-if="inClass && !def.responsive"
          class="text-[10px] text-muted"
          data-test="style-all-sizes"
        >
          Applies at all sizes
        </span>
        <div
          v-if="def.responsive && !hideBreakpoints"
          class="flex gap-0.5"
          role="group"
          aria-label="Breakpoint"
        >
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
    <p v-if="invalidValue !== null" class="text-[11px] text-error" data-test="style-invalid-value">
      Stored: {{ invalidValue }}
    </p>
    <!-- The value chooser, and nothing else: "nothing pressed" is asserted within this element,
         because the breakpoint chips above use aria-pressed for a different thing. A caller may
         supply its own chooser (icons, track swatches); the state and the actions stay the row's. -->
    <div data-test="style-chooser">
      <slot name="control" :value="currentValue" :pick="onPick">
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
          :labels="CHOICE_LABELS[def.path]"
          :model-value="currentValue"
          :name="def.path"
          @update:model-value="onPick"
        />
      </slot>
    </div>
    <div class="flex gap-2 text-[11px]">
      <template v-if="inClass">
        <button
          type="button"
          class="text-muted hover:text-default"
          data-test="style-use-theme-default"
          @click="reset()"
        >
          Use theme default
        </button>
        <button
          v-if="classState.declaredHere"
          type="button"
          class="text-muted hover:text-default"
          data-test="style-remove"
          @click="clear()"
        >
          Remove
        </button>
      </template>
      <template v-else>
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
      </template>
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
