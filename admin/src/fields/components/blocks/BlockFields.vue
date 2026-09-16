<script setup lang="ts">
// A block's schema form (visual builder spec §3.4 — the Content tab): every field of the
// block type rendered through the field registry, with the seeded types' cosmetic
// ergonomics (columns layout picker, navigation menu select). Blocks-typed fields (container
// regions) are handed to the `blocks` slot: the editor's card nests a BlockList inside the
// ops-owning tree, the inspector shows a summary. Context-free, so both can host it.
import { computed } from 'vue'
import { createReusableTemplate } from '@vueuse/core'
import { fieldComponent } from '../../registry'
import { toFieldDef } from '../../normalize'
import type { ContentTypeField } from '@/queries/contentTypes'
import type { BlockType } from '@/queries/blockTypes'
import { useNavMenus } from '@/queries/navigation'
import type { BlockInstance } from './useBlockListOps'
import ColumnsLayoutField from './ColumnsLayoutField.vue'

const props = defineProps<{
  block: BlockInstance
  type: BlockType | undefined
  /** Field names the host edits elsewhere (a prose body edited on the stage). */
  exclude?: string[]
}>()
const emit = defineEmits<{
  patch: [name: string, value: unknown]
  'insert-into': [field: string]
}>()

function patchData(name: string, value: unknown): void {
  emit('patch', name, value)
}

// Cosmetic editor ergonomics for SEEDED block types, keyed by their immutable
// type slugs (frontend-only by decision — no schema vocabulary). Columns:
// col_3 hides unless layout is 3, and the widths presets narrow to the
// current layout's column count. Hiding is COSMETIC — hidden fields keep
// their data (the render template already ignores them), so flipping layout
// back restores everything. Navigation: the `menu` slug field renders as a
// select over existing menus (nav-v2 spec §2) — the picker is cosmetic, the
// slug + pattern rule stay the contract. Custom block types are unaffected.
const columnsLayout = computed(() => (String(props.block.data.layout ?? '2') === '3' ? 3 : 2))

function fieldVisible(name: string): boolean {
  if (props.exclude?.includes(name)) return false
  if (props.block.type !== 'columns') return true
  if (name === 'col_3') return columnsLayout.value === 3
  // `widths` is folded into the combined visual layout picker (rendered at `layout`).
  if (name === 'widths') return false
  return true
}

// The `widths` enum, surfaced as swatches by the combined columns layout picker.
const columnsWidthPresets = computed<string[]>(() => {
  const wf = props.type?.schema.find((f) => f.name === 'widths')
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
  for (const f of props.type?.schema ?? []) {
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
</script>

<template>
  <div class="space-y-3">
    <!-- Per-field row defined once (widget dispatch), reused flat and inside groups.
         toFieldDef: block schemas arrive snake_case; widgets consume camelCase
         FieldDef. Blocks-typed fields go to the host through the `blocks` slot. -->
    <DefineFieldRow v-slot="{ f }">
      <template v-if="fieldVisible(f.name)">
        <slot
          v-if="toFieldDef(f).type === 'blocks'"
          name="blocks"
          :field="f"
          :label="humanize(f.name)"
        >
          <div class="flex items-center justify-between gap-2">
            <p class="text-xs text-muted" :data-test="`region-summary-${f.name}`">
              {{ humanize(f.name) }}: {{ ((block.data[f.name] as unknown[]) ?? []).length }} blocks
            </p>
            <UButton
              size="xs"
              variant="ghost"
              icon="i-lucide-plus"
              :aria-label="`Add a block to ${humanize(f.name)}`"
              :data-test="`region-add-${f.name}`"
              @click="emit('insert-into', f.name)"
            >
              Add
            </UButton>
          </div>
        </slot>
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
</template>
