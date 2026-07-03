<script setup lang="ts">
import { computed, ref } from 'vue'
import type { FieldDef } from '../types'
import { fieldComponent } from '../registry'
import { toFieldDef } from '../normalize'
import { useBlockTypes, type BlockType } from '@/queries/blockTypes'

interface BlockInstance {
  id: string
  type: string
  data: Record<string, unknown>
}

const props = defineProps<{ field: FieldDef }>()
const model = defineModel<BlockInstance[]>({ default: () => [] })

const { data: allTypes } = useBlockTypes()
const bySlug = computed(() => new Map((allTypes.value ?? []).map((t) => [t.slug, t])))

// Picker: ACTIVE types, filtered by the field's picker-only allowlist (spec §1).
const pickerTypes = computed(() => {
  const allow = props.field.blockTypes ?? []
  return (allTypes.value ?? []).filter(
    (t) => t.active && (allow.length === 0 || allow.includes(t.slug)),
  )
})

// Grouped by the free-form category (presentation only): named categories
// alphabetical, uncategorized under "Other" last. Headings render only when
// there's more than one group (a flat picker needs no chrome).
const pickerGroups = computed(() => {
  const groups = new Map<string, BlockType[]>()
  for (const t of pickerTypes.value) {
    const key = t.category?.trim() || 'Other'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(t)
  }
  return [...groups.entries()].sort(([a], [b]) =>
    a === 'Other' ? 1 : b === 'Other' ? -1 : a.localeCompare(b),
  )
})

const pickerOpen = ref(false)
const expanded = ref<Record<string, boolean>>({})
const pendingDelete = ref<string | null>(null)

// Client-side nanoid-ish id for stable list keys (the server generates one when absent).
function newId(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(12)))
    .map((b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36])
    .join('')
}

function addBlock(type: BlockType) {
  const block: BlockInstance = { id: newId(), type: type.slug, data: {} }
  model.value = [...(model.value ?? []), block]
  expanded.value[block.id] = true
  pickerOpen.value = false
}

function move(id: string, delta: number) {
  const list = [...(model.value ?? [])]
  const from = list.findIndex((b) => b.id === id)
  const to = from + delta
  if (from < 0 || to < 0 || to >= list.length) return
  const [item] = list.splice(from, 1)
  list.splice(to, 0, item!)
  model.value = list
}

function duplicate(id: string) {
  const list = [...(model.value ?? [])]
  const index = list.findIndex((b) => b.id === id)
  if (index < 0) return
  const copy = { ...list[index]!, id: newId(), data: { ...list[index]!.data } }
  list.splice(index + 1, 0, copy)
  model.value = list
}

function remove(id: string) {
  model.value = (model.value ?? []).filter((b) => b.id !== id)
  pendingDelete.value = null
}

function patchData(id: string, name: string, value: unknown) {
  model.value = (model.value ?? []).map((b) =>
    b.id === id ? { ...b, data: { ...b.data, [name]: value } } : b,
  )
}

function summary(block: BlockInstance, type: BlockType | undefined): string {
  for (const f of type?.schema ?? []) {
    const v = block.data[f.name]
    if (typeof v === 'string' && v.trim() !== '') return v.slice(0, 60)
  }
  return ''
}
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <div class="space-y-2" data-test="blocks-field">
      <div
        v-for="block in model"
        :key="block.id"
        class="rounded-lg border border-default"
        :data-test="`block-card-${block.id}`"
      >
        <div class="flex items-center gap-2 px-3 py-2">
          <UIcon :name="bySlug.get(block.type)?.icon || 'i-lucide-box'" class="shrink-0" />
          <button
            class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
            type="button"
            :data-test="`block-toggle-${block.id}`"
            @click="expanded[block.id] = !expanded[block.id]"
          >
            <span class="font-medium">{{ bySlug.get(block.type)?.label ?? block.type }}</span>
            <span class="truncate text-muted">{{ summary(block, bySlug.get(block.type)) }}</span>
          </button>
          <UBadge
            v-if="bySlug.get(block.type) && !bySlug.get(block.type)!.active"
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
            @click="move(block.id, -1)"
          />
          <UButton
            variant="ghost"
            color="neutral"
            size="xs"
            icon="i-lucide-chevron-down"
            :data-test="`block-move-down-${block.id}`"
            aria-label="Move down"
            @click="move(block.id, 1)"
          />
          <UButton
            variant="ghost"
            color="neutral"
            size="xs"
            icon="i-lucide-copy"
            :data-test="`block-duplicate-${block.id}`"
            aria-label="Duplicate"
            @click="duplicate(block.id)"
          />
          <UButton
            variant="ghost"
            color="error"
            size="xs"
            icon="i-lucide-trash-2"
            :data-test="`block-delete-${block.id}`"
            aria-label="Delete"
            @click="pendingDelete = block.id"
          />
        </div>
        <div
          v-if="pendingDelete === block.id"
          class="flex items-center gap-2 border-t border-default px-3 py-2"
        >
          <span class="flex-1 text-sm text-muted">Delete this block?</span>
          <UButton size="xs" color="error" data-test="block-delete-confirm" @click="remove(block.id)">
            Delete
          </UButton>
          <UButton size="xs" variant="ghost" color="neutral" @click="pendingDelete = null">
            Cancel
          </UButton>
        </div>
        <div v-if="expanded[block.id]" class="space-y-3 border-t border-default p-3">
          <!-- toFieldDef: block schemas arrive snake_case; widgets consume camelCase
               FieldDef (ReferenceField reads field.referenceType). -->
          <component
            :is="fieldComponent(toFieldDef(f).type)"
            v-for="f in bySlug.get(block.type)?.schema ?? []"
            :key="f.name"
            :field="toFieldDef(f)"
            :model-value="block.data[f.name]"
            @update:model-value="(v: unknown) => patchData(block.id, f.name, v)"
          />
        </div>
      </div>

      <div class="relative">
        <UButton
          variant="subtle"
          color="neutral"
          icon="i-lucide-plus"
          data-test="add-block"
          @click="pickerOpen = !pickerOpen"
        >
          Add block
        </UButton>
        <div
          v-if="pickerOpen"
          class="mt-2 rounded-lg border border-default p-1"
          data-test="block-picker"
        >
          <template v-for="[category, types] in pickerGroups" :key="category">
            <p
              v-if="pickerGroups.length > 1"
              class="px-2 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted"
              :data-test="`picker-group-${category}`"
            >
              {{ category }}
            </p>
            <button
              v-for="t in types"
              :key="t.slug"
              class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-elevated"
              type="button"
              :data-test="`picker-item-${t.slug}`"
              @click="addBlock(t)"
            >
              <UIcon :name="t.icon || 'i-lucide-box'" />
              <span class="font-medium">{{ t.label }}</span>
              <span v-if="t.description" class="truncate text-muted">{{ t.description }}</span>
            </button>
          </template>
          <p v-if="!pickerTypes.length" class="px-2 py-1.5 text-sm text-muted">
            No block types available.
          </p>
        </div>
      </div>
    </div>
  </UFormField>
</template>
