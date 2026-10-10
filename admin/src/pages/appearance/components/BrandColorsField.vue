<script setup lang="ts">
// The three brand colours (custom palette spec §2, §4, §5.2): each a name and a hex, offered in
// every block colour picker. A configured slot can be cleared (the page's dialog checks where it is
// used); a slot being replaced shows the replacement's progress instead of its fields; a slot a
// running replacement writes to stays editable but cannot be cleared.
import type { BrandKey, PaletteJob } from '@/queries/palette'
import HexInput from './HexInput.vue'

export type BrandDraft = { name: string; hex: string }

const props = defineProps<{
  /** The form's slots as edited. */
  slots: Record<BrandKey, BrandDraft>
  /** Whether each slot is configured as saved. */
  configured: Record<BrandKey, boolean>
  /** The style schema's slot states (`palette.slots`). */
  palette: Record<
    string,
    {
      name?: string | null
      state?: string
      reserved?: boolean
      replacing?: { to_label?: string } | null
    }
  >
  jobs: PaletteJob[]
}>()
const emit = defineEmits<{
  'update:slots': [value: Record<BrandKey, BrandDraft>]
  clear: [slot: 1 | 2 | 3]
}>()

const KEYS: BrandKey[] = ['1', '2', '3']
const NAME_MAX = 32

function nameOf(key: BrandKey): string {
  return props.palette[`brand-${key}`]?.name || props.slots[key].name || `Brand ${key}`
}

function set(key: BrandKey, part: keyof BrandDraft, value: string): void {
  emit('update:slots', { ...props.slots, [key]: { ...props.slots[key], [part]: value } })
}

function replacing(key: BrandKey): boolean {
  return props.palette[`brand-${key}`]?.state === 'replacing'
}

function progress(key: BrandKey): string {
  const job = props.jobs.find((j) => String(j.slot) === key)
  const to = props.palette[`brand-${key}`]?.replacing?.to_label ?? 'its replacement'
  const counts = job ? ` — ${job.work_items_done} of ${job.work_items_total}` : ''
  const state = job?.status === 'failed' || job?.status === 'interrupted' ? ` (${job.status})` : ''
  return `Replacing ${nameOf(key)} with ${to}${counts}${state}`
}

/** The replacement writing to this slot, by the name of the slot it replaces. */
function reservedBy(key: BrandKey): string | null {
  if (!props.palette[`brand-${key}`]?.reserved) return null
  const token = `color.brand-${key}`
  const job = props.jobs.find(
    (j) => j.to === token || j.contrast_to === `${token}-contrast` || j.contrast_to === token,
  )
  return job ? nameOf(String(job.slot) as BrandKey) : 'another'
}
</script>

<template>
  <div class="space-y-4" data-test="brand-colors">
    <div v-for="key in KEYS" :key="key" class="space-y-2" :data-test="`brand-${key}`">
      <p v-if="replacing(key)" class="text-sm text-muted" :data-test="`brand-${key}-progress`">
        {{ progress(key) }}
      </p>
      <template v-else>
        <div class="flex items-start gap-2">
          <UInput
            :model-value="slots[key].name"
            :maxlength="NAME_MAX"
            :placeholder="`Brand ${key}`"
            class="w-40 shrink-0"
            :aria-label="`Brand ${key} name`"
            :data-test="`brand-${key}-name`"
            @update:model-value="(v: string | number) => set(key, 'name', String(v))"
          />
          <HexInput
            class="min-w-0 flex-1"
            :model-value="slots[key].hex"
            :label="`Brand ${key}`"
            :data-test="`brand-${key}-hex`"
            @update:model-value="(v: string) => set(key, 'hex', v)"
          />
          <UButton
            v-if="configured[key] && !reservedBy(key)"
            color="neutral"
            variant="ghost"
            icon="i-lucide-x"
            :aria-label="`Clear ${nameOf(key)}`"
            :data-test="`brand-${key}-clear`"
            @click="emit('clear', Number(key) as 1 | 2 | 3)"
          >
            Clear
          </UButton>
        </div>
        <p v-if="reservedBy(key)" class="text-xs text-muted" :data-test="`brand-${key}-reserved`">
          Reserved by the {{ reservedBy(key) }} replacement
        </p>
      </template>
    </div>
  </div>
</template>
