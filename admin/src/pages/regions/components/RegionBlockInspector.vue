<script setup lang="ts">
// A region block's settings, from the Regions page: the Design page's block inspector without its
// stage. The block is chosen from its card; Layout, Style and Advanced are the same tabs over the
// same settings a page's block has (the server validates and renders a region's blocks exactly as
// it does an entry's). What is NOT here is the Design page's machinery: no operations, no history
// — an edit is written straight into the region's working copy, which the page saves as a whole.
import { computed, nextTick, watch } from 'vue'
import { useBlockTypes } from '@/queries/blockTypes'
import { useStyleSchema } from '@/queries/styleSchema'
import { useStyleClasses } from '@/queries/styleClasses'
import { toFieldDef } from '@/fields/normalize'
import type { Breakpoint, StyleClassRef, StyleValue } from '@/style/types'
import { createBlockListOps, type BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { absent, present } from '@/editor/ops/types'
import { setPath, settingSegments } from '@/editor/ops/apply'
import { capabilityPaths, detachStyleClass } from '@/style/detach'
import BlockInspector from '@/editor/inspector/BlockInspector.vue'

type Settings = Record<string, unknown>

const props = defineProps<{
  /** The region's block list — the working copy this writes into. */
  blocks: BlockInstance[]
  blockId: string
  activeBreakpoint: Breakpoint
}>()
const emit = defineEmits<{
  'update:blocks': [blocks: BlockInstance[]]
  'update:activeBreakpoint': [breakpoint: Breakpoint]
  /** The block is gone — deleted from its card while this was open. */
  close: []
}>()

const { data: allTypes } = useBlockTypes()
const { data: schema } = useStyleSchema()
const { data: styleClassList } = useStyleClasses()

const bySlug = computed(() => new Map((allTypes.value ?? []).map((t) => [t.slug, t])))
const regionsOf = (slug: string): string[] =>
  (bySlug.value.get(slug)?.schema ?? [])
    .filter((f) => toFieldDef(f).type === 'blocks')
    .map((f) => f.name)
const ops = createBlockListOps(regionsOf)

const block = computed(() => ops.findById(props.blocks, props.blockId))
const blockType = computed(() =>
  block.value ? (bySlug.value.get(block.value.type) ?? null) : null,
)
const parent = computed(() => {
  const parentId = ops.locateById(props.blocks, props.blockId)?.parentId ?? null
  return parentId === null ? null : ops.findById(props.blocks, parentId)
})
const parentType = computed(() =>
  parent.value ? (bySlug.value.get(parent.value.type) ?? null) : null,
)
watch(
  block,
  (now) => {
    if (now === null) emit('close')
  },
  { immediate: true },
)

function classRefsFor(of: BlockInstance | null): StyleClassRef[] {
  const ids = Array.isArray(of?.settings?.classes) ? (of!.settings.classes as string[]) : []
  const byId = new Map((styleClassList.value?.classes ?? []).map((c) => [c.id, c]))
  return ids.flatMap((id) => {
    const c = byId.get(id)
    return c ? [{ id: c.id, style: c.style }] : []
  })
}
const classNames = computed<Record<string, string>>(() =>
  Object.fromEntries((styleClassList.value?.classes ?? []).map((c) => [c.id, c.name])),
)
const classOptions = computed(() =>
  (styleClassList.value?.classes ?? []).map((c) => ({
    id: c.id,
    name: c.name,
    archived: c.archived,
    locked: c.locked_by_job !== null,
  })),
)

/**
 * The list as this component emitted it earlier IN THIS TICK. One click can write several
 * settings (a linked box writes every side), each emitted before the page has fed the last one
 * back as `blocks`: built on the prop alone, the last would overwrite the rest. Within a tick a
 * write builds on the one before it; afterwards the prop is the authority again.
 */
let pending: BlockInstance[] | null = null

function writeSettings(mutate: (settings: Settings) => Settings): void {
  if (pending === null) {
    void nextTick(() => {
      pending = null
    })
  }
  const tree = pending ?? props.blocks
  const current = ops.findById(tree, props.blockId)
  if (current === null) return
  const next = mutate((current.settings ?? {}) as Settings)
  pending = ops.patchSettingsById(tree, props.blockId, prune(next))
  emit('update:blocks', pending)
}

/** `style: {}` and `advanced: {}` are not settings: an emptied record is dropped. */
function prune(settings: Settings): Settings {
  const out: Settings = {}
  for (const [key, value] of Object.entries(settings)) {
    const empty =
      typeof value === 'object' &&
      value !== null &&
      !Array.isArray(value) &&
      Object.keys(value).length === 0
    if (!empty) out[key] = value
  }
  return out
}

function onSetSetting(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
  writeSettings((s) =>
    setPath(s, settingSegments(path, bp), value === null ? absent() : present(value)),
  )
}
function onSetAll(path: string, value: StyleValue): void {
  writeSettings((s) => {
    let next = s
    for (const bp of ['base', 'md', 'lg'] as Breakpoint[]) {
      next = setPath(next, settingSegments(path, bp), present(value))
    }
    return next
  })
}
function onSetAdvanced(path: string, value: unknown): void {
  writeSettings((s) =>
    setPath(s, ['advanced', ...path.split('.')], value === null ? absent() : present(value)),
  )
}

function writeClasses(mutate: (ids: string[]) => string[]): void {
  writeSettings((s) => {
    const ids = Array.isArray(s.classes) ? (s.classes as string[]) : []
    const next = mutate(ids)
    const { classes: _dropped, ...rest } = s
    return next.length === 0 ? rest : { ...rest, classes: next }
  })
}
/** Materialise what the class contributed to this block, then remove the reference (spec §4.4). */
function detach(classIds: string[]): void {
  const allowed = capabilityPaths(blockType.value?.style_capabilities)
  writeSettings((s) => {
    let settings = s
    for (const classId of classIds) {
      const refs = classRefsFor({ ...block.value!, settings })
      const style = (
        typeof settings.style === 'object' && settings.style !== null ? settings.style : {}
      ) as Record<string, unknown>
      const ids = (Array.isArray(settings.classes) ? settings.classes : []) as string[]
      const { classes: _dropped, ...rest } = settings
      const remaining = ids.filter((id) => id !== classId)
      settings = {
        ...rest,
        style: detachStyleClass(refs, style, classId, allowed),
        ...(remaining.length === 0 ? {} : { classes: remaining }),
      }
    }
    return settings
  })
}
function detachAll(): void {
  const ids = Array.isArray(block.value?.settings?.classes)
    ? [...(block.value!.settings.classes as string[])]
    : []
  // Last first: each detach resolves against the classes still applied beneath it.
  detach(ids.reverse())
}
</script>

<template>
  <BlockInspector
    v-if="block"
    :block="block"
    :block-type="blockType"
    :schema="schema ?? null"
    :classes="classRefsFor(block)"
    :class-names="classNames"
    :class-options="classOptions"
    :active-breakpoint="activeBreakpoint"
    :parent="parent"
    :parent-type="parentType"
    :parent-classes="classRefsFor(parent)"
    no-content
    no-save-as-class
    @set-setting="onSetSetting"
    @set-all="onSetAll"
    @set-advanced="onSetAdvanced"
    @update:active-breakpoint="(bp) => emit('update:activeBreakpoint', bp)"
    @apply-class="(id) => writeClasses((ids) => (ids.includes(id) ? ids : [...ids, id]))"
    @remove-class="(id) => writeClasses((ids) => ids.filter((x) => x !== id))"
    @reorder-classes="(ids) => writeClasses(() => ids)"
    @detach-class="(id) => detach([id])"
    @detach-all="detachAll"
  />
</template>
