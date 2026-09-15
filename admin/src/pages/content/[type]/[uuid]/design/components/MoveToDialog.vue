<script setup lang="ts">
// Keyboard reparenting (visual builder spec §5.5): an explicit "Move to…" naming the destination
// slot — a root field or a block's slot — and the position in it; only legal destinations are
// offered (the same rules every drop is judged by).
import { computed, ref, watch } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import { checkMoves, type LegalityContext } from '@/editor/structure/legality'
import type { EditorDocument } from '@/editor/ops/types'

const props = defineProps<{
  open: boolean
  blockId: string | null
  doc: EditorDocument
  legality: LegalityContext
}>()
const emit = defineEmits<{
  'update:open': [open: boolean]
  confirm: [zone: { parent: string | null; slot: string | null; index: number }]
}>()

interface Destination {
  key: string
  label: string
  parent: string | null
  slot: string | null
  /** The blocks the slot holds now, the moving block excluded. */
  items: { id: string; label: string }[]
}

function asList(value: unknown): BlockInstance[] {
  return Array.isArray(value) ? (value as BlockInstance[]) : []
}

const destinations = computed<Destination[]>(() => {
  if (props.blockId === null) return []
  const out: Destination[] = []
  const label = (block: BlockInstance) =>
    props.legality.blockTypes().find((t) => t.slug === block.type)?.label ?? block.type
  const walk = (list: BlockInstance[]) => {
    for (const block of list) {
      const type = props.legality.blockTypes().find((t) => t.slug === block.type)
      for (const slot of Object.keys(type?.slots ?? {})) {
        const items = asList(block.data[slot]).filter((b) => b.id !== props.blockId)
        out.push({
          key: `${block.id}:${slot}`,
          label: `${label(block)} › ${slot}`,
          parent: block.id,
          slot,
          items: items.map((b) => ({ id: b.id, label: label(b) })),
        })
        walk(asList(block.data[slot]))
      }
    }
  }
  for (const field of Object.keys(props.legality.rootSlots())) {
    const items = asList(props.doc.fields[field]).filter((b) => b.id !== props.blockId)
    out.push({
      key: `root:${field}`,
      label: field,
      parent: null,
      slot: field,
      items: items.map((b) => ({ id: b.id, label: label(b) })),
    })
    walk(asList(props.doc.fields[field]))
  }
  // Only destinations where at least the first position is legal.
  return out.filter(
    (d) =>
      checkMoves(
        props.doc,
        [{ block: props.blockId!, to: { parent: d.parent, slot: d.slot, index: 0 } }],
        props.legality,
      ).ok,
  )
})

const destinationKey = ref<string | undefined>(undefined)
const position = ref(0)
watch(
  () => props.open,
  (open) => {
    if (open) {
      destinationKey.value = destinations.value[0]?.key
      position.value = 0
    }
  },
)
const destination = computed(
  () => destinations.value.find((d) => d.key === destinationKey.value) ?? null,
)
const positions = computed(() => {
  const items = destination.value?.items ?? []
  return [
    { label: 'at the start', value: 0 },
    ...items.map((item, i) => ({ label: `after ${item.label}`, value: i + 1 })),
  ]
})
const legal = computed(() => {
  const d = destination.value
  if (d === null || props.blockId === null) return false
  return checkMoves(
    props.doc,
    [{ block: props.blockId, to: { parent: d.parent, slot: d.slot, index: position.value } }],
    props.legality,
  ).ok
})
function confirm(): void {
  const d = destination.value
  if (d === null || !legal.value) return
  emit('confirm', { parent: d.parent, slot: d.slot, index: position.value })
}
</script>

<template>
  <UModal
    :open="open"
    title="Move to…"
    data-test="move-to-dialog"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-3 text-sm">
        <UFormField label="Destination">
          <USelectMenu
            v-model="destinationKey"
            :items="destinations.map((d) => ({ label: d.label, value: d.key }))"
            value-key="value"
            class="w-full"
            data-test="move-to-destination"
          />
        </UFormField>
        <UFormField label="Position">
          <USelectMenu
            v-model="position"
            :items="positions"
            value-key="value"
            class="w-full"
            data-test="move-to-position"
          />
        </UFormField>
        <p v-if="destinations.length === 0" class="text-muted" data-test="move-to-none">
          No other place can hold this block.
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton variant="ghost" color="neutral" @click="emit('update:open', false)"
          >Cancel</UButton
        >
        <UButton :disabled="!legal" data-test="move-to-confirm" @click="confirm">Move</UButton>
      </div>
    </template>
  </UModal>
</template>
