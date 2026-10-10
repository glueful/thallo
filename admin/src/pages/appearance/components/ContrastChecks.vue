<script setup lang="ts">
// Contrast checks of the pending palette (custom palette spec §3): the pairs a site's text sits on,
// in light and dark mode, from the server's own resolution of the unsaved look. A pair under 4.5:1
// is a warning, never a refusal.
import { ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { previewPalette, type ContrastRow, type PaletteLook } from '@/queries/palette'

const props = defineProps<{ look: PaletteLook }>()

const LABELS: Record<string, string> = {
  background: 'Background',
  surface: 'Surface',
  'surface-2': 'Surface 2',
  text: 'Text',
  muted: 'Muted',
  line: 'Line',
  accent: 'Accent',
  'accent-contrast': 'Accent — text',
}

const rows = ref<ContrastRow[]>([])
const failed = ref(false)
let asked = 0

function label(name: string): string {
  const brand = /^brand-(\d+)(-contrast)?$/.exec(name)
  if (brand) {
    const entry = props.look.palette.brands.find((b) => b.id === Number(brand[1]))
    const base = entry?.name || `Brand ${brand[1]}`
    return brand[2] ? `${base} — text` : base
  }
  return LABELS[name] ?? name
}

function text(row: ContrastRow): string {
  return `${label(row.fg)} on ${label(row.on)} (${row.mode}) — ${row.ratio}:1 ${row.passes ? '✓' : '⚠'}`
}

const check = useDebounceFn(async () => {
  const mine = ++asked
  try {
    const preview = await previewPalette(props.look)
    if (mine !== asked) return
    rows.value = preview.rows
    failed.value = false
  } catch {
    if (mine === asked) failed.value = true
  }
}, 600)

watch(
  () => JSON.stringify(props.look),
  () => void check(),
  { immediate: true },
)
</script>

<template>
  <div class="space-y-2" data-test="contrast-checks">
    <ul class="space-y-1 text-sm">
      <li
        v-for="row in rows"
        :key="`${row.mode}-${row.fg}-${row.on}`"
        :class="row.passes ? 'text-muted' : 'text-warning'"
        :data-test="`contrast-row-${row.mode}-${row.fg}-${row.on}`"
      >
        {{ text(row) }}
      </li>
    </ul>
    <p v-if="failed" class="text-xs text-muted">The contrast checks are unavailable right now.</p>
    <p class="text-xs text-muted">
      These pairs are checked; other combinations a block can make are not.
    </p>
  </div>
</template>
