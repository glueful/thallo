<script setup lang="ts">
// A chrome region's Style tab: the block inspector's Style tab over the region's own style record
// (`settings.style`). What it offers is what the SERVER says a region may be styled with — the
// regions API names the capabilities — so nothing is offered that a save would refuse. A region is
// not a block: it has no style classes and no Advanced fields.
import { computed } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import { useStyleSchema } from '@/queries/styleSchema'
import type { Breakpoint } from '@/style/types'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { useStyleRecord } from '@/editor/inspector/useStyleRecord'

const props = defineProps<{
  region: string
  modelValue: Record<string, unknown>
  capabilities: string[]
  activeBreakpoint: Breakpoint
}>()
const emit = defineEmits<{
  'update:modelValue': [style: Record<string, unknown>]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
}>()

const { data: schema } = useStyleSchema()

/** The Style tab reads capabilities off a block type: this is the region, dressed as one. */
const regionType = computed<BlockType>(() => ({
  uuid: `region-${props.region}`,
  slug: `region_${props.region}`,
  label: props.region,
  icon: null,
  category: null,
  description: null,
  active: true,
  schema: [],
  style_capabilities: props.capabilities,
  style_targets: null,
  flags: null,
  starter_content: null,
}))
const block = computed(() => ({
  id: `region-${props.region}`,
  type: `region_${props.region}`,
  data: {},
  settings: { style: props.modelValue },
}))

const { set, setAll } = useStyleRecord(
  () => props.modelValue,
  (next) => emit('update:modelValue', next),
)
</script>

<template>
  <div :data-test="`region-style-${region}`">
    <p v-if="!schema" class="text-xs text-muted">Loading the style schema…</p>
    <StyleTab
      v-else
      :block="block"
      :block-type="regionType"
      :schema="schema"
      :classes="[]"
      context="region"
      :active-breakpoint="activeBreakpoint"
      @set="set"
      @set-all="setAll"
      @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
    />
  </div>
</template>
