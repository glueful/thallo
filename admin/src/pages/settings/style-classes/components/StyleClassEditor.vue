<script setup lang="ts">
// A style class's declarations (visual builder spec §4.1): every §1.3 property through the same
// controls the block inspector uses — a class declares no capabilities, so every group shows.
// Two tabs, split by the same tab map as the inspector's (container-layout spec §12.2): Style, and
// a class Layout tab that borrows no context from a block.
import { computed, nextTick, ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import { useStyleSchema } from '@/queries/styleSchema'
import type { Breakpoint, StyleValue } from '@/style/types'
import { absent, present } from '@/editor/ops/types'
import { setPath, settingSegments } from '@/editor/ops/apply'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import ClassLayoutTab from './ClassLayoutTab.vue'
import InvalidChoiceNotice from '@/editor/inspector/controls/InvalidChoiceNotice.vue'
import { REPLACEMENT, invalidChoicesIn } from '@/editor/inspector/layoutContext'
import { propertyDefinition } from '@/style/schema'

const props = defineProps<{ modelValue: Record<string, unknown> }>()
const emit = defineEmits<{ 'update:modelValue': [style: Record<string, unknown>] }>()

const { data: schema } = useStyleSchema()
const activeBreakpoint = ref<Breakpoint>('base')
/** Style first; the breakpoint above is shared, so switching tab keeps the one being edited. */
const tab = ref<'style' | 'layout'>('style')
const TABS = [
  { label: 'Style', value: 'style', slot: 'style' },
  { label: 'Layout', value: 'layout', slot: 'layout' },
]

/** Every capability: a class has them all. */
const everything = computed<BlockType>(() => ({
  uuid: 'style-class',
  slug: 'style_class',
  label: 'Style class',
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: [...new Set((schema.value?.properties ?? []).map((row) => row.group))],
  style_targets: null,
  flags: null,
  starter_content: null,
}))

const block = computed(() => ({
  id: 'style-class',
  type: 'style_class',
  data: {},
  settings: { style: props.modelValue },
}))

/**
 * What this editor emitted earlier IN THIS TICK. One click can write several declarations — a
 * linked box writes every side — each emitted before the parent has fed the last one back as
 * `modelValue`, which happens a tick later. Built on the prop alone, every write in the burst would
 * start from the same stale value and the last would overwrite the rest. So within a tick a write
 * builds on the one before it; once the tick has passed the prop is the authority again, whether
 * or not the parent took what it was handed.
 */
let pending: Record<string, unknown> | null = null

function write(mutate: (settings: Record<string, unknown>) => Record<string, unknown>): void {
  if (pending === null) {
    void nextTick(() => {
      pending = null
    })
  }
  const next = mutate({ style: pending ?? props.modelValue })
  const style = next.style
  pending = typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {}
  emit('update:modelValue', pending)
}

function onSet(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
  write((s) => setPath(s, settingSegments(path, bp), value === null ? absent() : present(value)))
}

/**
 * Stored choices the contract no longer offers (container-layout spec §11.1). The tab below shows
 * style paths only, so a layout value held here would otherwise be invisible on the one page the
 * block inspector sends an author to repair it. Repair only: a class's layout is not edited here.
 */
const needsAttention = computed(() => invalidChoicesIn(props.modelValue))
const labelOf = (path: string): string => {
  const words = path.replace(/[._]/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}
/** A property that is not responsive is stored bare, not under a breakpoint. */
const storedAt = (path: string, bp: Breakpoint): Breakpoint | null =>
  propertyDefinition(path)?.responsive ? bp : null

function onSetAll(path: string, value: StyleValue): void {
  write((s) => {
    let next = s
    for (const bp of ['base', 'md', 'lg'] as Breakpoint[]) {
      next = setPath(next, settingSegments(path, bp), present(value))
    }
    return next
  })
}
</script>

<template>
  <div data-test="style-class-editor">
    <section
      v-if="needsAttention.length > 0"
      class="mb-4 space-y-2"
      data-test="style-class-needs-attention"
    >
      <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Needs attention</h3>
      <InvalidChoiceNotice
        v-for="invalid in needsAttention"
        :key="`${invalid.path}:${invalid.breakpoint}`"
        :label="labelOf(invalid.path)"
        :path="invalid.path"
        :value="invalid.value"
        :breakpoint="invalid.breakpoint"
        :replacement="REPLACEMENT[invalid.path]"
        @replace="(path, bp, value) => onSet(path, storedAt(path, bp), { type: 'choice', value })"
        @remove="(path, bp) => onSet(path, storedAt(path, bp), null)"
      />
    </section>
    <!-- Outside both panels, so it is on both tabs and cannot be lost with either one's markup.
         The editor shows every property because a class declares no capabilities — which must
         not read as a promise that every block the class is applied to uses every declaration. -->
    <p
      class="mb-3 rounded bg-elevated px-2 py-1.5 text-[11px] text-muted"
      data-test="style-class-capability-note"
    >
      A declaration applies only to blocks that support that property. On a block that does not, it
      is kept and unused.
    </p>
    <p v-if="!schema" class="text-xs text-muted">Loading the style schema…</p>
    <UTabs
      v-else
      v-model="tab"
      :items="TABS"
      :unmount-on-hide="false"
      size="xs"
      variant="link"
      data-test="style-class-tabs"
    >
      <template #style>
        <StyleTab
          :block="block"
          :block-type="everything"
          :schema="schema"
          :classes="[]"
          context="class"
          :active-breakpoint="activeBreakpoint"
          @set="onSet"
          @set-all="onSetAll"
          @update:active-breakpoint="(bp) => (activeBreakpoint = bp)"
        />
      </template>
      <template #layout>
        <ClassLayoutTab
          :model-style="modelValue"
          :schema="schema"
          :active-breakpoint="activeBreakpoint"
          @set="onSet"
          @set-all="onSetAll"
          @update:active-breakpoint="(bp) => (activeBreakpoint = bp)"
        />
      </template>
    </UTabs>
  </div>
</template>
