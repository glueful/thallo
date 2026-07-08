<script setup lang="ts">
import { computed, inject } from 'vue'
import { createReusableTemplate } from '@vueuse/core'
import { fieldComponent } from '../../registry'
import { toFieldDef } from '../../normalize'
import type { ContentTypeField } from '@/queries/contentTypes'
import { useNavMenus } from '@/queries/navigation'
import { BlocksContextKey } from './context'
import type { BlockInstance } from './useBlockListOps'
import { newBlockId } from './useBlockListOps'
import { isProseBlockType, proseRichFieldName } from './proseDetection'
import BlockList from './BlockList.vue'
import ProseBlockEditor from './ProseBlockEditor.vue'
import ColumnsLayoutField from './ColumnsLayoutField.vue'

// One block: header chrome (icon, label, summary, actions), delete-confirm, and
// the schema-form body. Container regions recurse through BlockList — NOT the
// registry's BlocksField — so the ops-owning root stays the single writer for
// the whole tree (cross-container drag and the outline depend on that).
const props = defineProps<{
  block: BlockInstance
  depth: number
  parentId: string | null
  region: string | null
  index: number
}>()

const emit = defineEmits<{ 'request-insert': [index: number] }>()

const ctx = inject(BlocksContextKey)!

const type = computed(() => ctx.bySlug.value.get(props.block.type))

// Prose seam (spec §3): the CONVENTION predicate decides chromeless rendering.
const prose = computed(() => (type.value ? isProseBlockType(type.value) : false))
const richField = computed(() => (type.value ? proseRichFieldName(type.value) : null))

// This block's containing-list picker rules (stage-toolbar spec §5): the `/`
// menu inserts split-siblings into the SAME list, so it uses the same resolver
// as the insert dividers.
const listPickerTypes = computed(() => ctx.pickerTypesForList(props.parentId, props.region))

function onInsertBlock(payload: { slug: string; beforeHtml: string; afterHtml: string }): void {
  const name = richField.value
  if (!name || payload.slug === '') return
  ctx.apply((t) =>
    ctx.ops.splitRichTextAt(t, props.block.id, name, payload.beforeHtml, payload.afterHtml, {
      id: newBlockId(),
      type: payload.slug,
      data: {},
    }),
  )
}

const summary = computed(() => {
  for (const f of type.value?.schema ?? []) {
    const v = props.block.data[f.name]
    if (typeof v === 'string' && v.trim() !== '') return v.slice(0, 60)
  }
  return ''
})

// Cosmetic editor ergonomics for SEEDED block types, keyed by their immutable
// type slugs (frontend-only by decision — no schema vocabulary). Columns:
// col_3 hides unless layout is 3, and the widths presets narrow to the
// current layout's column count. Hiding is COSMETIC — hidden fields keep
// their data (the render template already ignores them), so flipping layout
// back restores everything. Navigation: the `menu` slug field renders as a
// select over existing menus (nav-v2 spec §2) — the picker is cosmetic, the
// slug + pattern rule stay the contract. Custom block types are unaffected.
const columnsLayout = computed(() =>
  String(props.block.data.layout ?? '2') === '3' ? 3 : 2,
)

function fieldVisible(name: string): boolean {
  if (props.block.type !== 'columns') return true
  if (name === 'col_3') return columnsLayout.value === 3
  // `widths` is folded into the combined visual layout picker (rendered at `layout`).
  if (name === 'widths') return false
  return true
}

// The `widths` enum, surfaced as swatches by the combined columns layout picker.
const columnsWidthPresets = computed<string[]>(() => {
  const wf = type.value?.schema.find((f) => f.name === 'widths')
  return (wf ? toFieldDef(wf).enum : undefined) ?? []
})

// One click sets BOTH coupled fields, so column count and ratio never drift.
function selectColumnsLayout(v: { layout: string; widths: string }): void {
  patchData('layout', v.layout)
  patchData('widths', v.widths)
}

function displayFieldDef(f: Parameters<typeof toFieldDef>[0]): ReturnType<typeof toFieldDef> {
  const base = toFieldDef(f)
  // Human-readable label from the snake_case field name (e.g. background_image →
  // "background image"), unless the schema declared an explicit label.
  const def = { ...base, label: base.label ?? humanize(base.name) }
  if (props.block.type === 'columns' && def.name === 'widths' && def.enum) {
    return { ...def, enum: def.enum.filter((v) => v.split('-').length === columnsLayout.value) }
  }
  return def
}

// Region/field names arrive snake_case (col_1); show them space-separated
// ("col 1") without inventing a schema-level label vocabulary.
const humanize = (name: string): string => name.replace(/_/g, ' ')

// Collapsible-group layout: fields carrying a `group` fold into labelled sections
// (collapsed by default); ungrouped fields render flat, always visible. Schema order
// is preserved, and consecutive same-group (or ungrouped) fields merge into one
// section — so a block that declares no groups renders exactly as a single flat run.
const sections = computed<{ group: string | null; fields: ContentTypeField[] }[]>(() => {
  const out: { group: string | null; fields: ContentTypeField[] }[] = []
  for (const f of type.value?.schema ?? []) {
    const g = f.group ?? null
    const last = out[out.length - 1]
    if (last && last.group === g) last.fields.push(f)
    else out.push({ group: g, fields: [f] })
  }
  return out
})

// Define the per-field row ONCE and reuse it both flat and inside groups (avoids
// duplicating the widget-dispatch template).
const [DefineFieldRow, FieldRow] = createReusableTemplate<{ f: ContentTypeField }>()

// Rules of hooks: called unconditionally; the enabled-gate means it only
// FETCHES for navigation blocks.
const menusQuery = useNavMenus(() => props.block.type === 'navigation')
const menuOptions = computed(() =>
  (menusQuery.data.value ?? []).map((m) => ({ label: m.name || m.slug, value: m.slug })),
)

// Per-card confirm state (the pre-refactor field-global ref behaved identically
// for a single user; local state avoids plumbing a model through every level).
import { ref } from 'vue'
const pendingDelete = ref<string | null>(null)

function toggleExpanded(): void {
  ctx.expanded[props.block.id] = !ctx.expanded[props.block.id]
}

function move(delta: number): void {
  ctx.apply((t) => ctx.ops.moveById(t, props.block.id, delta))
}

function duplicate(): void {
  ctx.apply((t) => ctx.ops.duplicateById(t, props.block.id))
}

function askDelete(): void {
  pendingDelete.value = props.block.id
}

function cancelDelete(): void {
  pendingDelete.value = null
}

function remove(): void {
  ctx.apply((t) => ctx.ops.removeById(t, props.block.id))
  pendingDelete.value = null
}

function patchData(name: string, value: unknown): void {
  ctx.apply((t) => ctx.ops.patchDataById(t, props.block.id, name, value))
}

/**
 * Keyboard movement on the HEADER only (spec §2): plain typing inside nested
 * field inputs never reaches this handler. Roving tabindex lives on the header
 * button (natively focusable).
 */
function onHeaderKeydown(event: KeyboardEvent): void {
  const meta = event.metaKey || event.altKey
  if (meta && event.key === 'ArrowUp') {
    move(-1)
  } else if (meta && event.key === 'ArrowDown') {
    move(1)
  } else if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'd') {
    duplicate()
  } else if (event.key === 'Delete' || event.key === 'Backspace') {
    askDelete()
  } else if (event.key === '/') {
    emit('request-insert', props.index + 1)
  } else {
    return // Enter keeps the button's native click -> toggleExpanded
  }
  event.preventDefault()
  event.stopPropagation()
}
</script>

<template>
  <!-- PROSE path (spec §3): chromeless flowing text — action chrome on hover
       only, no border, no expand toggle (always "open"). -->
  <div
    v-if="prose && richField"
    class="group/prose relative"
    :data-test="`prose-block-${block.id}`"
  >
    <div
      class="absolute -top-2 right-0 z-10 flex items-center gap-1 opacity-0 transition-opacity group-hover/prose:opacity-100 focus-within:opacity-100"
    >
      <button
        class="cursor-grab"
        type="button"
        :data-test="`block-drag-${block.id}`"
        aria-label="Drag to reorder"
      >
        <UIcon name="i-lucide-grip-vertical" class="size-4 text-muted" />
      </button>
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-copy"
        :data-test="`block-duplicate-${block.id}`"
        aria-label="Duplicate"
        @click="duplicate()"
      />
      <UButton
        variant="ghost"
        color="error"
        size="xs"
        icon="i-lucide-trash-2"
        :data-test="`block-delete-${block.id}`"
        aria-label="Delete"
        @click="askDelete()"
      />
    </div>
    <div
      v-if="pendingDelete === block.id"
      class="mb-1 flex items-center gap-2 rounded border border-default px-3 py-2"
    >
      <span class="flex-1 text-sm text-muted">Delete this text block?</span>
      <UButton size="xs" color="error" data-test="block-delete-confirm" @click="remove()">
        Delete
      </UButton>
      <UButton size="xs" variant="ghost" color="neutral" @click="cancelDelete()">Cancel</UButton>
    </div>
    <ProseBlockEditor
      :model-value="(block.data[richField] as string) ?? ''"
      :picker-types="listPickerTypes"
      @update:model-value="(v: string) => patchData(richField!, v)"
      @insert-block="onInsertBlock"
    />
  </div>

  <!-- WIDGET path: the card. -->
  <div
    v-else
    class="rounded-lg border border-default"
    :data-test="`block-card-${block.id}`"
    :data-block-id="block.id"
  >
    <div class="group/card flex items-center gap-2 px-3 py-2">
      <button
        class="cursor-grab opacity-0 transition-opacity group-hover/card:opacity-100 focus-visible:opacity-100"
        type="button"
        :data-test="`block-drag-${block.id}`"
        aria-label="Drag to reorder"
      >
        <UIcon name="i-lucide-grip-vertical" class="size-4 text-muted" />
      </button>
      <UIcon :name="type?.icon || 'i-lucide-box'" class="shrink-0" />
      <button
        class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
        type="button"
        :data-test="`block-toggle-${block.id}`"
        @click="toggleExpanded()"
        @keydown="onHeaderKeydown"
      >
        <span class="font-medium">{{ type?.label ?? block.type }}</span>
        <span class="truncate text-muted">{{ summary }}</span>
      </button>
      <UBadge
        v-if="type && !type.active"
        size="xs"
        color="warning"
        variant="subtle"
        :data-test="`block-inactive-${block.id}`"
      >
        inactive
      </UBadge>
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-chevron-up"
        :data-test="`block-move-up-${block.id}`"
        aria-label="Move up"
        @click="move(-1)"
      />
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-chevron-down"
        :data-test="`block-move-down-${block.id}`"
        aria-label="Move down"
        @click="move(1)"
      />
      <UButton
        variant="ghost"
        color="neutral"
        size="xs"
        icon="i-lucide-copy"
        :data-test="`block-duplicate-${block.id}`"
        aria-label="Duplicate"
        @click="duplicate()"
      />
      <UButton
        variant="ghost"
        color="error"
        size="xs"
        icon="i-lucide-trash-2"
        :data-test="`block-delete-${block.id}`"
        aria-label="Delete"
        @click="askDelete()"
      />
    </div>
    <div
      v-if="pendingDelete === block.id"
      class="flex items-center gap-2 border-t border-default px-3 py-2"
    >
      <span class="flex-1 text-sm text-muted">Delete this block?</span>
      <UButton size="xs" color="error" data-test="block-delete-confirm" @click="remove()">
        Delete
      </UButton>
      <UButton size="xs" variant="ghost" color="neutral" @click="cancelDelete()">Cancel</UButton>
    </div>
    <div v-if="ctx.expanded[block.id]" class="space-y-3 border-t border-default p-3">
      <!-- Per-field row defined once (widget dispatch), reused flat and inside groups.
           toFieldDef: block schemas arrive snake_case; widgets consume camelCase
           FieldDef. Blocks-typed fields render a NESTED BlockList inside the same
           ops-owning tree, or the max-depth notice at the cap. -->
      <DefineFieldRow v-slot="{ f }">
        <template v-if="fieldVisible(f.name)">
          <p
            v-if="toFieldDef(f).type === 'blocks' && depth >= ctx.maxDepth"
            class="rounded border border-dashed border-default px-2 py-1.5 text-xs text-muted"
            data-test="max-depth-notice"
          >
            “{{ humanize(f.name) }}”: maximum nesting depth ({{ ctx.maxDepth }}) reached.
          </p>
          <UFormField v-else-if="toFieldDef(f).type === 'blocks'" :label="humanize(f.name)" :name="f.name">
            <BlockList
              :blocks="(block.data[f.name] as BlockInstance[]) ?? []"
              :parent-id="block.id"
              :region="f.name"
              :depth="depth + 1"
            />
          </UFormField>
          <UFormField
            v-else-if="block.type === 'navigation' && f.name === 'menu'"
            label="menu"
            name="menu"
          >
            <USelect
              :model-value="(block.data.menu as string) ?? ''"
              :items="menuOptions"
              class="w-full"
              data-test="nav-menu-select"
              @update:model-value="(v: unknown) => patchData('menu', v)"
            />
          </UFormField>
          <UFormField
            v-else-if="block.type === 'columns' && f.name === 'layout'"
            label="Layout"
            name="layout"
          >
            <ColumnsLayoutField
              :layout="(block.data.layout as string) ?? '2'"
              :widths="(block.data.widths as string) ?? ''"
              :presets="columnsWidthPresets"
              @select="selectColumnsLayout"
            />
          </UFormField>
          <component
            :is="fieldComponent(toFieldDef(f).type)"
            v-else
            :field="displayFieldDef(f)"
            :model-value="block.data[f.name]"
            @update:model-value="(v: unknown) => patchData(f.name, v)"
          />
        </template>
      </DefineFieldRow>

      <template v-for="section in sections" :key="section.group ?? '__flat'">
        <template v-if="section.group === null">
          <FieldRow v-for="f in section.fields" :key="f.name" :f="f" />
        </template>
        <details
          v-else
          class="group/sect rounded-md border border-default"
          :data-test="`block-group-${section.group}`"
        >
          <summary
            class="flex cursor-pointer select-none list-none items-center justify-between gap-2 px-3 py-2 text-sm font-medium [&::-webkit-details-marker]:hidden"
          >
            <span>{{ section.group }}</span>
            <UIcon
              name="i-lucide-chevron-down"
              class="size-4 shrink-0 text-muted transition-transform group-open/sect:rotate-180"
            />
          </summary>
          <div class="space-y-3 border-t border-default p-3">
            <FieldRow v-for="f in section.fields" :key="f.name" :f="f" />
          </div>
        </details>
      </template>
    </div>
  </div>
</template>
