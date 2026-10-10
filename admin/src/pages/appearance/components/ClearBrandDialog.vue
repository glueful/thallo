<script setup lang="ts">
// Clear, or Replace with… (custom palette spec §4, §4.2). A brand colour no current page uses is
// cleared outright. One that is used cannot be: the dialog says where, and offers to replace it
// everywhere it is used — with a colour the rules allow, its text colour mapped to the destination's
// own pair, or chosen here when the destination has none and something uses the slot's text colour,
// with that pair's contrast in light and dark mode. History is never rewritten: restoring an older
// version shows the cleared colour as unavailable.
import { computed, ref, watch } from 'vue'
import TokenScaleControl from '@/editor/inspector/controls/TokenScaleControl.vue'
import type { StyleSchemaResult } from '@/queries/styleSchema'
import { contrast } from '@/style/contrast'
import {
  clearBrand,
  fetchPaletteUsage,
  PaletteConflict,
  previewPalette,
  replaceBrand,
  type PaletteLook,
  type PaletteUsage,
} from '@/queries/palette'

const props = defineProps<{
  open: boolean
  /** The brand colour's permanent id. */
  id: number
  /** The slot's name, as configured. */
  name: string
  /** The style schema's palette: which slots are configured, swatches and names. */
  palette: StyleSchemaResult['palette']
  /** The colour vocabulary's names (`vocabulary.domains.color`). */
  colours: string[]
  /** The look the contrast of a chosen pair is judged in. */
  look: PaletteLook
}>()
const emit = defineEmits<{
  'update:open': [open: boolean]
  /** A Clear carries the list it wrote; a started replacement carries nothing. */
  done: [result?: { cleared: number; brandColors: string | null }]
}>()

const usage = ref<PaletteUsage | null>(null)
const failed = ref<string | null>(null)
const conflict = ref<string | null>(null)
const busy = ref(false)
const to = ref<string | null>(null)
const contrastTo = ref<string | null>(null)
const ratios = ref<{ light: number; dark: number } | null>(null)

watch(
  () => [props.open, props.id] as const,
  async ([open]) => {
    if (!open) return
    usage.value = null
    failed.value = null
    conflict.value = null
    to.value = null
    contrastTo.value = null
    ratios.value = null
    try {
      usage.value = await fetchPaletteUsage(props.id)
    } catch {
      failed.value = 'Couldn’t check where this colour is used.'
    }
  },
  { immediate: true },
)

const inUse = computed(() => (usage.value?.blocking.total ?? 0) > 0)
const history = computed(() => usage.value?.historical.total ?? 0)

const REGION_LABELS: Record<string, string> = { header: 'Header', footer: 'Footer' }
/** The blocking uses, grouped, each with its count and its first five names. */
const groups = computed(() => {
  const b = usage.value?.blocking
  if (!b) return []
  const five = (names: string[]) => names.slice(0, 5)
  return [
    { label: 'Pages', names: b.entries.map((e) => e.title || 'Untitled') },
    { label: 'Header and footer', names: b.regions.map((r) => REGION_LABELS[r] ?? r) },
    { label: 'Layouts', names: b.layouts.map((l) => l.name) },
    { label: 'Saved sections', names: b.saved_sections.map((s) => s.name) },
    { label: 'Style classes', names: b.style_classes.map((c) => c.name) },
  ]
    .filter((g) => g.names.length > 0)
    .map((g) => ({ ...g, count: g.names.length, shown: five(g.names) }))
})

/** What can take the slot's place: no text colour, not the slot, no slot that is unset or replaced. */
const destinations = computed(() =>
  props.colours.filter((name) => {
    if (name.endsWith('-contrast') || name === 'transparent') return false
    const m = /^brand-(\d+)$/.exec(name)
    if (!m) return true
    return Number(m[1]) !== props.id && props.palette?.slots[name]?.state === 'configured'
  }),
)

const hasPair = (token: string) => token === 'color.accent' || /^color\.brand-\d+$/.test(token)
/** The destination has no text colour of its own and something uses the slot's: ask for one. */
const needsContrast = computed(
  () =>
    to.value !== null && !hasPair(to.value) && usage.value?.blocking.contrast_references === true,
)
const ready = computed(
  () => to.value !== null && (!needsContrast.value || contrastTo.value !== null),
)

watch([to, contrastTo], async () => {
  ratios.value = null
  if (!needsContrast.value || to.value === null || contrastTo.value === null) return
  const fill = to.value.replace(/^color\./, '')
  const ink = contrastTo.value.replace(/^color\./, '')
  try {
    const preview = await previewPalette(props.look)
    const ratio = (mode: 'light' | 'dark') => {
      const a = preview.values[mode][fill]
      const b = preview.values[mode][ink]
      return a && b ? Math.round(contrast(a, b) * 100) / 100 : 0
    }
    ratios.value = { light: ratio('light'), dark: ratio('dark') }
  } catch {
    ratios.value = null
  }
})

/** Runs a Clear or a Replace; `done` carries what the work resolves to describe (a Clear's list). */
async function run(
  work: () => Promise<{ cleared: number; brandColors: string | null } | undefined>,
): Promise<void> {
  busy.value = true
  conflict.value = null
  try {
    const result = await work()
    if (result === undefined) emit('done')
    else emit('done', result)
    emit('update:open', false)
  } catch (e) {
    conflict.value = e instanceof PaletteConflict ? e.message : 'Something went wrong. Try again.'
  } finally {
    busy.value = false
  }
}

function onClear(): void {
  // The list this Clear wrote travels with `done`: the page may take it as its new base.
  void run(async () => ({ cleared: props.id, brandColors: await clearBrand(props.id) }))
}
function onReplace(): void {
  if (!ready.value || to.value === null) return
  const mapping = needsContrast.value ? (contrastTo.value ?? undefined) : undefined
  void run(async () => {
    await replaceBrand(props.id, to.value as string, mapping)
    return undefined
  })
}
</script>

<template>
  <UModal
    :open="open"
    :title="inUse ? `Replace ${name}` : `Clear ${name}`"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-4 text-sm" data-test="clear-brand">
        <p v-if="failed" class="text-error">{{ failed }}</p>
        <p v-else-if="usage === null" class="text-muted">Checking where {{ name }} is used…</p>
        <template v-else>
          <template v-if="!inUse">
            <p>{{ name }} isn't used on any current page.</p>
          </template>
          <template v-else>
            <p>
              {{ name }} is used in {{ usage.blocking.total }}
              {{ usage.blocking.total === 1 ? 'place' : 'places' }}, so it can't be cleared. Replace
              it everywhere it is used with another colour, then it is cleared.
            </p>
            <ul class="space-y-1" data-test="clear-brand-usage">
              <li v-for="g in groups" :key="g.label">
                <span class="font-medium">{{ g.label }} ({{ g.count }})</span>:
                {{ g.shown.join(', ') }}<span v-if="g.count > g.shown.length">, …</span>
              </li>
            </ul>
          </template>
          <p v-if="history > 0" class="text-muted">
            {{ history }} older {{ history === 1 ? 'version also uses' : 'versions also use' }}
            {{ name }}; restoring one shows it as an unavailable colour.
          </p>
          <template v-if="inUse">
            <UFormField :label="`Replace ${name} with…`">
              <div data-test="replace-to">
                <TokenScaleControl
                  :manage-link="false"
                  domain="color"
                  :names="destinations"
                  :values="{}"
                  :model-value="to"
                  :palette="palette"
                  @update:model-value="(t: string) => (to = t)"
                />
              </div>
            </UFormField>
            <UFormField v-if="needsContrast" :label="`Text on ${name} becomes…`" required>
              <div data-test="contrast-to">
                <TokenScaleControl
                  :manage-link="false"
                  domain="color"
                  :names="destinations"
                  :values="{}"
                  :model-value="contrastTo"
                  :palette="palette"
                  @update:model-value="(t: string) => (contrastTo = t)"
                />
              </div>
            </UFormField>
            <ul v-if="needsContrast && ratios" class="space-y-1 text-xs">
              <li
                v-for="mode in ['light', 'dark'] as const"
                :key="mode"
                :class="ratios[mode] >= 4.5 ? 'text-muted' : 'text-warning'"
                :data-test="`contrast-ratio-${mode}`"
              >
                {{ mode === 'light' ? 'Light' : 'Dark' }} mode — {{ ratios[mode] }}:1
                {{ ratios[mode] >= 4.5 ? '✓' : '⚠ under 4.5:1' }}
              </li>
            </ul>
          </template>
          <p v-if="conflict" class="text-error" data-test="palette-conflict">{{ conflict }}</p>
        </template>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          data-test="clear-cancel"
          @click="emit('update:open', false)"
        >
          Cancel
        </UButton>
        <UButton
          v-if="usage !== null && !inUse"
          color="error"
          :loading="busy"
          data-test="clear-confirm"
          @click="onClear"
        >
          Clear {{ name }}
        </UButton>
        <UButton
          v-if="usage !== null && inUse"
          :disabled="!ready || busy"
          :loading="busy"
          data-test="replace-confirm"
          @click="onReplace"
        >
          Replace everywhere
        </UButton>
      </div>
    </template>
  </UModal>
</template>
