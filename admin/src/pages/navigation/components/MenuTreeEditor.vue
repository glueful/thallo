<script setup lang="ts">
import { computed, ref } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import type { NavTreeItem, NavTargetStatus } from '@/queries/navigation'

// Recursive tree editor level. Mutates THIS level's `items` array in place (the page owns
// the working tree as a reactive clone) and bubbles `changed`. Outdent is handled by the
// PARENT level (which owns both arrays): a child level emits `outdent(childIndex)` and the
// parent splices the item out of the child array into its own, after the holding item.
const props = defineProps<{
  items: NavTreeItem[]
  locale: string
  canOutdent?: boolean
}>()
const emit = defineEmits<{ changed: []; outdent: [index: number] }>()

const STATUS_COLOR: Record<NavTargetStatus, 'success' | 'warning' | 'error' | 'neutral' | 'info'> = {
  published: 'success',
  routeless: 'info',
  unpublished: 'warning',
  deleted: 'error',
  missing: 'error',
}
const STATUS_LABEL: Record<NavTargetStatus, string> = {
  published: 'published',
  routeless: 'needs a route',
  unpublished: 'unpublished',
  deleted: 'deleted',
  missing: 'missing',
}

function changed(): void {
  emit('changed')
}

function move(index: number, delta: number): void {
  const target = index + delta
  if (target < 0 || target >= props.items.length) return
  const [item] = props.items.splice(index, 1)
  props.items.splice(target, 0, item!)
  changed()
}

function remove(index: number): void {
  props.items.splice(index, 1)
  changed()
}

// Two-way view of THIS level's array for vue-draggable-plus. The setter mutates the
// prop array IN PLACE (like the splice calls above) — never reassigns the prop — so
// drags commit straight into the page's working tree and `changed()` bubbles.
const list = computed<NavTreeItem[]>({
  get: () => props.items,
  set: (next) => {
    props.items.splice(0, props.items.length, ...next)
    changed()
  },
})

// Reject dropping an item into its own subtree (would detach a cycle). Sortable's move
// event carries the dragged element and the destination list element.
function onMove(e: { dragged: HTMLElement; to: HTMLElement }): boolean {
  return !e.dragged.contains(e.to)
}

// Exposed for tests: `onMove` (guard) and `list` (the drag-commit setter).
defineExpose({ onMove, list })

/** Indent: nest under the previous sibling. */
function indent(index: number): void {
  if (index === 0) return
  const [item] = props.items.splice(index, 1)
  props.items[index - 1]!.children.push(item!)
  changed()
}

/** A child level asked to outdent its item at childIndex out of items[holder].children. */
function outdentChild(holder: number, childIndex: number): void {
  const children = props.items[holder]!.children
  const [item] = children.splice(childIndex, 1)
  props.items.splice(holder + 1, 0, item!)
  changed()
}

function setLabel(item: NavTreeItem, value: string): void {
  item.labels[props.locale] = value
  changed()
}

// Optional per-item description (nav-v2 megamenu). Coerce to an object first: the
// server sends an empty map as `[]` (PHP), and setting a string key on a JS array
// would be dropped by JSON.stringify — so a blank-then-typed description would be
// lost on save without this.
function setDescription(item: NavTreeItem, value: string): void {
  const map = item.descriptions && !Array.isArray(item.descriptions) ? item.descriptions : {}
  map[props.locale] = value
  item.descriptions = map
  changed()
}

// Icon picker (icon-picker spec §5, direct use): one modal per level, aimed
// at the item being edited.
import IconPickerModal from '@/fields/components/IconPickerModal.vue'
const iconPickerFor = ref<NavTreeItem | null>(null)
const iconPickerOpen = computed({
  get: () => iconPickerFor.value !== null,
  set: (v: boolean) => {
    if (!v) iconPickerFor.value = null
  },
})
function onIconSelect(name: string): void {
  if (!iconPickerFor.value) return
  iconPickerFor.value.icon = name
  changed()
}
function onIconClear(): void {
  if (!iconPickerFor.value) return
  iconPickerFor.value.icon = null
  changed()
}
</script>

<template>
  <VueDraggable
    v-model="list"
    :group="{ name: 'nav-tree' }"
    handle="[data-test='tree-item-drag']"
    :move="onMove"
    :animation="150"
    tag="ul"
    class="space-y-2"
    :class="{ 'min-h-8 rounded border border-dashed border-default': items.length === 0 }"
    data-test="tree-children"
  >
    <li v-for="(item, i) in items" :key="item.uuid ?? `new-${i}`" data-test="tree-item">
      <div class="border-default flex flex-wrap items-center gap-2 rounded border p-2">
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          icon="i-lucide-grip-vertical"
          class="cursor-grab"
          aria-label="Drag to reorder"
          data-test="tree-item-drag"
          @click.prevent
        />
        <UInput
          :model-value="item.labels[locale] ?? ''"
          size="sm"
          class="w-44"
          :placeholder="item.kind === 'entry' && item.target_title ? item.target_title : `Label (${locale})`"
          data-test="tree-item-label"
          @update:model-value="(v: string) => setLabel(item, v)"
        />
        <UInput
          v-if="item.kind === 'url'"
          v-model="item.url"
          size="sm"
          class="w-52"
          placeholder="/path or https://…"
          data-test="tree-item-url"
          @update:model-value="changed()"
        />
        <template v-else>
          <UBadge
            v-if="item.target_status"
            :color="STATUS_COLOR[item.target_status]"
            variant="subtle"
            data-test="tree-item-status"
          >
            {{ STATUS_LABEL[item.target_status] }}
          </UBadge>
          <code v-if="item.target_url" class="text-muted max-w-48 truncate text-xs" data-test="tree-item-path">
            {{ item.target_url }}
          </code>
        </template>
        <!-- Optional per-item description (nav-v2 megamenu): a short supporting
             line rendered under the label in dropdown/megamenu panels. -->
        <UInput
          :model-value="item.descriptions?.[locale] ?? ''"
          size="sm"
          class="w-56"
          :placeholder="`Description (${locale}) — optional`"
          data-test="tree-item-description"
          @update:model-value="(v: string) => setDescription(item, v)"
        />
        <!-- Optional Lucide icon (nav-v2 + icon-picker spec §5, direct use:
             tree items are not schema fields). Picker over the vendored
             inventory; preview via the admin's i-lucide-* set (same names). -->
        <div class="flex items-center gap-1" data-test="tree-item-icon">
          <UIcon v-if="item.icon" :name="`i-lucide-${item.icon}`" class="size-4 shrink-0 text-muted" />
          <UButton
            size="xs"
            variant="subtle"
            color="neutral"
            data-test="tree-item-icon-choose"
            @click="iconPickerFor = item"
          >
            {{ item.icon ?? 'Icon' }}
          </UButton>
          <UButton
            v-if="item.icon"
            size="xs"
            variant="ghost"
            color="neutral"
            icon="i-lucide-x"
            aria-label="Clear icon"
            data-test="tree-item-icon-clear"
            @click="() => { item.icon = null; changed() }"
          />
        </div>

        <span class="grow" />
        <UButton size="xs" variant="ghost" icon="i-lucide-arrow-up" data-test="tree-item-up" @click="move(i, -1)" />
        <UButton
          size="xs"
          variant="ghost"
          icon="i-lucide-arrow-down"
          data-test="tree-item-down"
          @click="move(i, 1)"
        />
        <UButton
          size="xs"
          variant="ghost"
          icon="i-lucide-indent-increase"
          data-test="tree-item-indent"
          @click="indent(i)"
        />
        <UButton
          v-if="canOutdent"
          size="xs"
          variant="ghost"
          icon="i-lucide-indent-decrease"
          data-test="tree-item-outdent"
          @click="emit('outdent', i)"
        />
        <UButton
          size="xs"
          color="error"
          variant="ghost"
          icon="i-lucide-trash-2"
          data-test="tree-item-remove"
          @click="remove(i)"
        />
      </div>

      <!-- Children ALWAYS render as a droppable level (empty → a thin dashed strip via the
           child level's own tree-children class), so an item can receive children by drag. -->
      <div class="border-default mt-2 ml-6 border-l pl-3">
        <MenuTreeEditor
          :items="item.children"
          :locale="locale"
          :can-outdent="true"
          @changed="changed()"
          @outdent="(childIndex: number) => outdentChild(i, childIndex)"
        />
      </div>
    </li>
  </VueDraggable>

  <IconPickerModal
    v-model:open="iconPickerOpen"
    set="lucide"
    :model-value="iconPickerFor?.icon ?? undefined"
    @select="onIconSelect"
    @clear="onIconClear"
  />
</template>
