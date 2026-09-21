<script setup lang="ts">
// The site's accent: one of the colour families, or the site's own brand colour as a hex. The
// model is that one string (`teal`, `#0a7c66`), exactly what the settings store. A hex becomes
// the accent only while it IS a colour — a half-typed one leaves the last good colour in place —
// and the field says, before anything is saved, how the colour will read: the label a button
// gets on it (the server picks white or black by the same sums, so a label is always readable),
// and whether the colour itself reads as text on the page, where it is links and small accents.
import { computed, ref, watch } from 'vue'
import { brandReport, normalizeHex } from '@/style/contrast'

const model = defineModel<string>({ required: true })

// Each family with its 500 stop, the swatch shown beside the select.
const FAMILIES: Array<{ value: string; swatch: string }> = [
  { value: 'red', swatch: '#ef4444' },
  { value: 'orange', swatch: '#f97316' },
  { value: 'amber', swatch: '#f59e0b' },
  { value: 'yellow', swatch: '#eab308' },
  { value: 'lime', swatch: '#84cc16' },
  { value: 'green', swatch: '#22c55e' },
  { value: 'emerald', swatch: '#10b981' },
  { value: 'teal', swatch: '#14b8a6' },
  { value: 'cyan', swatch: '#06b6d4' },
  { value: 'sky', swatch: '#0ea5e9' },
  { value: 'blue', swatch: '#3b82f6' },
  { value: 'indigo', swatch: '#6366f1' },
  { value: 'violet', swatch: '#8b5cf6' },
  { value: 'purple', swatch: '#a855f7' },
  { value: 'fuchsia', swatch: '#d946ef' },
  { value: 'pink', swatch: '#ec4899' },
  { value: 'rose', swatch: '#f43f5e' },
]
const items = [
  ...FAMILIES.map((f) => ({ value: f.value, label: f.value })),
  { value: 'custom', label: 'Brand colour…' },
]

const isBrand = computed(() => model.value.startsWith('#'))
const swatch = computed(() =>
  isBrand.value
    ? model.value
    : (FAMILIES.find((f) => f.value === model.value)?.swatch ?? '#3b82f6'),
)
const choice = computed({
  get: () => (isBrand.value ? 'custom' : model.value),
  set: (value: string) => {
    // The brand colour starts from the colour the site has now, not from an arbitrary one.
    model.value = value === 'custom' ? swatch.value : value
  },
})

// What is in the hex box: follows the accent, but may run ahead of it while being typed.
const typed = ref(isBrand.value ? model.value : '')
watch(model, (value) => {
  if (value.startsWith('#') && normalizeHex(typed.value) !== value) typed.value = value
})
const typedIsColor = computed(() => normalizeHex(typed.value) !== null)
function onTyped(value: string): void {
  typed.value = value
  const hex = normalizeHex(value)
  if (hex !== null) model.value = hex
}

const report = computed(() => (isBrand.value ? brandReport(model.value) : null))
const ratio = (n: number): string => `${n.toFixed(1)}:1`
const TEXT_NOTE = {
  good: 'Reads well as links and small text on the page.',
  large:
    'Fine for headings and buttons; thin as links and small text. A darker shade reads better.',
  poor: 'Hard to read as links and text on a white page. A darker shade would fix that; buttons are unaffected.',
} as const
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-2">
      <span
        class="inline-block size-4 shrink-0 rounded-full ring-1 ring-default"
        :style="{ background: swatch }"
        data-test="theme-accent-swatch"
      />
      <USelect
        v-model="choice"
        :items="items"
        value-key="value"
        class="w-full"
        data-test="theme-accent"
      />
    </div>

    <template v-if="isBrand">
      <div class="flex items-center gap-2">
        <input
          type="color"
          class="h-8 w-10 shrink-0 cursor-pointer rounded border border-default bg-transparent p-0.5"
          :value="model"
          aria-label="Pick your brand colour"
          data-test="brand-picker"
          @input="onTyped(($event.target as HTMLInputElement).value)"
        />
        <UInput
          :model-value="typed"
          class="w-full font-mono"
          placeholder="#0a7c66"
          aria-label="Brand colour, as a hex"
          data-test="brand-hex"
          @update:model-value="(v: string | number) => onTyped(String(v))"
        />
      </div>
      <p v-if="!typedIsColor" class="text-xs text-error" data-test="brand-hex-error">
        Not a colour yet. Use a hex like #0a7c66.
      </p>

      <div
        v-if="report"
        class="space-y-2 rounded-md bg-elevated p-3 text-xs"
        data-test="brand-report"
      >
        <div class="flex items-center gap-3">
          <span
            class="rounded-full px-3 py-1.5 text-xs font-semibold"
            :style="{ background: model, color: report.ink }"
            data-test="brand-sample"
          >
            Button
          </span>
          <span class="text-toned">
            Buttons get {{ report.ink === '#ffffff' ? 'white' : 'black' }} text —
            {{ ratio(report.onAccent) }}, always readable.
          </span>
        </div>
        <p
          class="flex items-start gap-1.5"
          :class="report.asText === 'good' ? 'text-toned' : 'text-warning'"
          :data-level="report.asText"
          data-test="brand-report-text"
        >
          <UIcon
            :name="report.asText === 'good' ? 'i-lucide-check' : 'i-lucide-triangle-alert'"
            class="mt-0.5 size-3.5 shrink-0"
          />
          <span>{{ ratio(report.onPage) }} on a white page. {{ TEXT_NOTE[report.asText] }}</span>
        </p>
        <p class="text-muted">On a dark page the colour is lightened until it can be seen.</p>
      </div>
    </template>
  </div>
</template>
