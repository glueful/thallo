<script setup lang="ts">
// Contrast checks of the pending palette (custom palette spec §6): the pairs a site's text sits on,
// in light and dark mode, from the server's own resolution of the unsaved look — one row per pair,
// its two modes side by side, and a line saying how many fall short. A pair under 4.5:1 is a
// warning, never a refusal.
import { computed, ref, watch } from 'vue'
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

/** One row per pair, in the server's order, with each mode's result. */
const pairs = computed(() => {
  const out = new Map<string, { fg: string; on: string; light?: ContrastRow; dark?: ContrastRow }>()
  for (const row of rows.value) {
    const key = `${row.fg}|${row.on}`
    const pair = out.get(key) ?? { fg: row.fg, on: row.on }
    pair[row.mode] = row
    out.set(key, pair)
  }
  return [...out.values()]
})
const short = computed(() => rows.value.filter((r) => !r.passes).length)
const hasDark = computed(() => rows.value.some((r) => r.mode === 'dark'))

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
    <p
      v-if="rows.length > 0"
      class="flex items-center gap-1.5 text-sm"
      :class="short > 0 ? 'text-warning' : 'text-muted'"
      data-test="contrast-summary"
    >
      <UIcon
        :name="short > 0 ? 'i-lucide-triangle-alert' : 'i-lucide-circle-check'"
        class="size-4"
      />
      {{
        short > 0
          ? `${short} ${short === 1 ? 'pair reads' : 'pairs read'} below 4.5:1`
          : 'Every pair reads at 4.5:1 or better'
      }}
    </p>
    <table v-if="rows.length > 0" class="w-full text-sm">
      <thead>
        <tr class="text-xs text-dimmed">
          <th class="py-1 text-left font-normal">Pair</th>
          <th class="w-24 py-1 text-right font-normal">Light</th>
          <th v-if="hasDark" class="w-24 py-1 text-right font-normal">Dark</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="pair in pairs"
          :key="`${pair.fg}-${pair.on}`"
          class="border-t border-default"
          :data-test="`contrast-pair-${pair.fg}-${pair.on}`"
        >
          <td class="py-1.5 text-default">{{ label(pair.fg) }} on {{ label(pair.on) }}</td>
          <template
            v-for="mode in hasDark ? (['light', 'dark'] as const) : (['light'] as const)"
            :key="mode"
          >
            <td
              class="py-1.5 text-right whitespace-nowrap tabular-nums"
              :class="pair[mode] && !pair[mode]!.passes ? 'font-medium text-warning' : 'text-muted'"
              :data-test="`contrast-row-${mode}-${pair.fg}-${pair.on}`"
            >
              <span v-if="pair[mode]" class="inline-flex items-center justify-end gap-1">
                <UIcon
                  v-if="!pair[mode]!.passes"
                  name="i-lucide-triangle-alert"
                  class="size-3.5 shrink-0"
                  aria-hidden="true"
                />
                {{ pair[mode]!.ratio }}:1
                <span class="sr-only">{{ pair[mode]!.passes ? 'passes' : 'below 4.5:1' }}</span>
              </span>
            </td>
          </template>
        </tr>
      </tbody>
    </table>
    <p v-if="failed" class="text-xs text-muted">The contrast checks are unavailable right now.</p>
    <p class="text-xs text-dimmed">
      These pairs are checked; other combinations a block can make are not.
    </p>
  </div>
</template>
