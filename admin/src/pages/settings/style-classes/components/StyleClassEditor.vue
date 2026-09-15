<script setup lang="ts">
// A style class's declarations (visual builder spec §4.1): every §1.3 property through the same
// controls the block inspector uses — a class declares no capabilities, so every group shows.
import { computed, ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import { useStyleSchema } from '@/queries/styleSchema'
import type { Breakpoint, StyleValue } from '@/style/types'
import { absent, present } from '@/editor/ops/types'
import { setPath, settingSegments } from '@/editor/ops/apply'
import StyleTab from '@/editor/inspector/StyleTab.vue'

const props = defineProps<{ modelValue: Record<string, unknown> }>()
const emit = defineEmits<{ 'update:modelValue': [style: Record<string, unknown>] }>()

const { data: schema } = useStyleSchema()
const activeBreakpoint = ref<Breakpoint>('base')

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

function write(mutate: (settings: Record<string, unknown>) => Record<string, unknown>): void {
  const next = mutate({ style: props.modelValue })
  const style = next.style
  emit(
    'update:modelValue',
    typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {},
  )
}

function onSet(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
  write((s) => setPath(s, settingSegments(path, bp), value === null ? absent() : present(value)))
}

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
    <p v-if="!schema" class="text-xs text-muted">Loading the style schema…</p>
    <StyleTab
      v-else
      :block="block"
      :block-type="everything"
      :schema="schema"
      :classes="[]"
      :active-breakpoint="activeBreakpoint"
      @set="onSet"
      @set-all="onSetAll"
      @update:active-breakpoint="(bp) => (activeBreakpoint = bp)"
    />
  </div>
</template>
