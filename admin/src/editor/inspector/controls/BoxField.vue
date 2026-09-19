<script setup lang="ts">
// A four-sided property as ONE row (visual builder spec §3.4, box presentation): a cell per
// side showing its effective token and state, a link toggle that writes every side at once,
// and — for the cell that is open — the token pills with Reset, Clear and Apply to all. The
// writes are exactly those of four separate fields; only the presentation is shared.
//
// In a style class (`context="class"`, container-layout spec §12.4) each side says what the CLASS
// declares, and the open panel offers Remove and Use theme default. Remove touches only the sides
// that hold a declaration at the breakpoint being edited. The block inspector is unchanged.
import { computed, ref, watch } from 'vue'
import { resolve } from '@/style/resolver'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from '@/style/types'
import type { StylePropertyRow } from '@/queries/styleSchema'
import TokenScaleControl from './TokenScaleControl.vue'
import { classFieldState, type ClassFieldState } from '@/editor/inspector/classFieldState'

const props = defineProps<{
  label: string
  /** The sides in display order; `key` labels the cell (top, right, bottom, left). */
  sides: { key: string; def: StylePropertyRow }[]
  style: Record<string, unknown>
  classes: StyleClassRef[]
  activeBreakpoint: Breakpoint
  vocabulary: { domains: Record<string, string[]>; values: Record<string, string> }
  classNames?: Record<string, string>
  reResolving?: boolean
  styles?: Record<string, unknown>[]
  /** Where the row is: a block's inspector (the default) or a style class's editor. */
  context?: 'block' | 'class' | 'region'
}>()
const emit = defineEmits<{
  set: [path: string, breakpoint: Breakpoint | null, value: StyleValue | null]
  'set-all': [path: string, value: StyleValue]
}>()

type Cell = {
  key: string
  def: StylePropertyRow
  breakpoint: Breakpoint | null
  resolution: Resolution
  mixed: boolean
  /** The token name (`spacing.lg`) or null. */
  token: string | null
  /** The short name shown in the cell (`lg`), or '' for the theme's. */
  short: string
  state: string
  /** What the class declares for this side; null in the block inspector. */
  inClass: ClassFieldState | null
}

function definitionOf(def: StylePropertyRow) {
  return {
    path: def.path,
    group: def.group,
    responsive: def.responsive,
    tokenDomain: def.token_domain,
    choices: def.choices,
  }
}
function resolutionsOf(def: StylePropertyRow, style: Record<string, unknown>) {
  return resolve(def.path, props.classes, style, definitionOf(def)) as Record<string, Resolution>
}

const cells = computed<Cell[]>(() =>
  props.sides.map(({ key, def }) => {
    const bp: Breakpoint | null = def.responsive ? props.activeBreakpoint : null
    const at = bp ?? 'base'
    const resolution = resolutionsOf(def, props.style)[at]!
    const styles = props.styles ?? []
    const mixed =
      styles.length > 1 &&
      styles.some(
        (s) =>
          JSON.stringify(resolutionsOf(def, s)[at]?.value ?? null) !==
          JSON.stringify(resolutionsOf(def, styles[0]!)[at]?.value ?? null),
      )
    const domainNames = def.token_domain
      ? (props.vocabulary.domains[def.token_domain] ?? []).map(
          (name) => `${def.token_domain}.${name}`,
        )
      : undefined
    const inClass =
      props.context === 'class'
        ? classFieldState(definitionOf(def), props.style, props.activeBreakpoint, domainNames)
        : null
    const v = resolution.value
    // A class presents a token only where it supplies one the vocabulary has: a reset, an absence
    // and an invalid value show none.
    const supplies = inClass === null || inClass.kind === 'set' || inClass.kind === 'inherited'
    const token = mixed || !supplies ? null : v && 'value' in v ? v.value : null
    return {
      key,
      def,
      breakpoint: bp,
      resolution,
      mixed,
      token,
      short: token === null ? '' : token.slice(token.indexOf('.') + 1),
      inClass,
      state: inClass
        ? inClass.label
        : mixed
          ? 'mixed'
          : props.reResolving && resolution.state !== 'explicit'
            ? 're-resolving'
            : resolution.state === 'explicit'
              ? 'set'
              : resolution.state === 'inherited'
                ? 'inherited'
                : resolution.state === 'reset'
                  ? 'reset'
                  : 'theme',
    }
  }),
)

/** Linked when every side agrees; the user can flip it either way. */
const linked = ref(true)
watch(
  () => cells.value.map((c) => `${c.state}:${c.token ?? ''}`).join('|'),
  () => {
    linked.value = cells.value.every((c) => c.token === cells.value[0]!.token && !c.mixed)
  },
  { immediate: true },
)

const open = ref<string | null>(null)
function toggle(path: string): void {
  open.value = open.value === path ? null : path
}
const openCell = computed(() => cells.value.find((c) => c.def.path === open.value) ?? null)
/** The cells a write goes to: every side when linked, the open one otherwise. */
const targets = computed<Cell[]>(() =>
  linked.value ? cells.value : openCell.value ? [openCell.value] : [],
)
const domain = computed(() => props.sides[0]?.def.token_domain ?? '')

function pick(token: string): void {
  for (const c of targets.value)
    emit('set', c.def.path, c.breakpoint, { type: 'token', value: token })
}
function reset(): void {
  for (const c of targets.value) emit('set', c.def.path, c.breakpoint, { type: 'reset' })
}
function clear(): void {
  for (const c of targets.value) emit('set', c.def.path, c.breakpoint, null)
}
/** Remove, in a class: only the targeted sides that hold a declaration at this breakpoint. */
const removable = computed(() => targets.value.filter((c) => c.inClass?.declaredHere === true))
function remove(): void {
  for (const c of removable.value) emit('set', c.def.path, c.breakpoint, null)
}
function applyToAll(): void {
  const token = openCell.value?.token
  if (!token) return
  for (const c of targets.value) emit('set-all', c.def.path, { type: 'token', value: token })
}
function sourceLabel(c: Cell): string {
  const id = c.resolution.source.slice('class:'.length)
  return `from ${props.classNames?.[id] ?? id}`
}
const showClear = computed(() =>
  targets.value.some((c) => c.resolution.state === 'explicit' || c.resolution.state === 'reset'),
)
</script>

<template>
  <div class="space-y-1.5" :data-test="`box-${label.toLowerCase()}`">
    <div class="flex items-center justify-between gap-2">
      <span class="text-xs font-medium">{{ label }}</span>
      <button
        type="button"
        class="rounded p-1 text-muted hover:text-default"
        :class="linked ? 'bg-elevated text-default' : ''"
        :aria-pressed="linked ? 'true' : 'false'"
        :title="
          linked
            ? 'Sides move together — click to set each side alone'
            : 'Sides are separate — click to link them'
        "
        data-test="box-link"
        @click="linked = !linked"
      >
        <UIcon :name="linked ? 'i-lucide-link' : 'i-lucide-unlink'" class="size-3.5" />
      </button>
    </div>
    <!-- Each side's wrapper is display: contents, so the cell and (when open) its panel are both
         grid items: the panel spans every column and, by order, sits after every cell. -->
    <div
      class="grid gap-1"
      :style="{ gridTemplateColumns: `repeat(${cells.length}, minmax(0, 1fr))` }"
    >
      <div
        v-for="c in cells"
        :key="c.def.path"
        class="contents"
        :data-test="`style-field-${c.def.path}`"
      >
        <button
          type="button"
          class="relative flex w-full flex-col items-center rounded-md border px-1 py-1.5 text-xs"
          :class="
            open === c.def.path
              ? 'border-primary bg-primary/10'
              : c.resolution.state === 'explicit' && !c.mixed
                ? 'border-primary/40'
                : 'border-default hover:border-muted'
          "
          :aria-expanded="open === c.def.path ? 'true' : 'false'"
          :title="!c.inClass && c.resolution.source.startsWith('class:') ? sourceLabel(c) : c.state"
          :data-test="`box-cell-${c.def.path}`"
          @click="toggle(c.def.path)"
        >
          <span class="font-medium" data-test="box-cell-value">{{
            c.mixed ? '…' : c.short || '–'
          }}</span>
          <span class="text-[10px] capitalize text-muted">{{ c.key }}</span>
          <span
            class="absolute top-1 right-1 size-1.5 rounded-full"
            :class="
              c.inClass
                ? c.inClass.kind === 'invalid'
                  ? 'bg-error'
                  : c.inClass.kind === 'set' || c.inClass.kind === 'reset-here'
                    ? 'bg-primary'
                    : c.inClass.kind === 'not-set'
                      ? 'bg-transparent'
                      : 'bg-muted'
                : c.mixed
                  ? 'bg-warning'
                  : c.resolution.state === 'explicit'
                    ? 'bg-primary'
                    : c.resolution.state === 'inherited'
                      ? 'bg-muted'
                      : 'bg-transparent'
            "
            data-test="style-state"
            :data-source="c.resolution.source"
            :data-kind="c.inClass ? c.inClass.kind : undefined"
            ><span class="sr-only">{{ c.state }}</span></span
          >
          <span
            v-if="!c.inClass && c.resolution.source.startsWith('class:')"
            class="sr-only"
            data-test="style-source"
            >{{ sourceLabel(c) }}</span
          >
        </button>
        <div
          v-if="open === c.def.path"
          class="order-last col-span-full space-y-1.5 rounded-md bg-elevated/60 p-2"
          data-test="box-panel"
        >
          <p class="text-[11px] text-muted">
            <span class="capitalize">{{ linked ? 'All sides' : c.key }}</span>
            <span v-if="!c.inClass && c.resolution.source.startsWith('class:')">
              · {{ sourceLabel(c) }}</span
            >
            <template v-if="c.inClass">
              · <span data-test="style-state-label">{{ c.inClass.label }}</span>
            </template>
          </p>
          <p
            v-if="c.inClass?.kind === 'invalid' && c.inClass.value && 'value' in c.inClass.value"
            class="text-[11px] text-error"
            data-test="style-invalid-value"
          >
            Stored: {{ c.inClass.value.value }}
          </p>
          <!-- The value chooser: "nothing pressed" is asserted within it, not within the box,
               whose link toggle uses aria-pressed for its own state. -->
          <div data-test="style-chooser">
            <TokenScaleControl
              :domain="domain"
              :names="vocabulary.domains[domain] ?? []"
              :values="vocabulary.values"
              :model-value="c.token"
              @update:model-value="pick"
            />
          </div>
          <div class="flex gap-2 text-[11px]">
            <template v-if="c.inClass">
              <button
                type="button"
                class="text-muted hover:text-default"
                data-test="style-use-theme-default"
                @click="reset()"
              >
                Use theme default
              </button>
              <button
                v-if="removable.length > 0"
                type="button"
                class="text-muted hover:text-default"
                data-test="style-remove"
                @click="remove()"
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
                v-if="showClear"
                type="button"
                class="text-muted hover:text-default"
                data-test="style-clear"
                @click="clear()"
              >
                Clear
              </button>
            </template>
            <button
              v-if="c.def.responsive && c.token !== null"
              type="button"
              class="text-muted hover:text-default"
              data-test="style-apply-all"
              @click="applyToAll()"
            >
              Apply to all breakpoints
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
