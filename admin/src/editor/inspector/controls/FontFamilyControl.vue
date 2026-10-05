<script setup lang="ts">
// The Typeface control (block typeface spec §4.1–§4.4): the built-ins and the site's own families,
// each option set in its own face; a faces line for the one chosen; and, for a stored family the
// library no longer offers — removed, or from another site — a notice naming it, saying what renders,
// and offering a way out. The same list serves a block's target, a part, and a style class.
import { computed, ref, watch } from 'vue'
import type { StyleValue } from '@/style/types'
import { facesLabel, suppliedWeights, useFontLibrary, type FontFamily } from '@/queries/fontLibrary'
import { loadFamilyFaces } from '@/fonts/loadFamilyFaces'
import { FONT_STACKS, THEME_FACE_FAMILY, fallbackStack } from '@/fonts/stacks'

const props = defineProps<{
  /** The value in force here: a font ID, or null (nothing chosen, a reset, mixed). */
  value: StyleValue | null
  context: 'block' | 'part' | 'class' | 'region'
  /** What the stage reports the target renders in (block typeface plan Task 10). */
  computed?: { weight: number; style: string } | null
}>()
const emit = defineEmits<{ pick: [id: string]; clear: [] }>()

/** The built-ins, always offered — also before the library answers, or where there is none. */
const BUILT_INS: FontFamily[] = [
  ['theme', 'Theme'],
  ['serif', 'Serif'],
  ['humanist', 'Humanist'],
  ['geometric', 'Geometric'],
  ['slab', 'Slab'],
  ['mono', 'Mono'],
  ['system', 'System'],
].map(([id, name]) => ({
  id: id!,
  name: name!,
  kind: 'builtin',
  fallback: null,
  removed: false,
  faces: [],
}))

const { data: library } = useFontLibrary()
const families = computed<FontFamily[]>(() => library.value?.families ?? BUILT_INS)
const builtIns = computed(() => families.value.filter((f) => f.kind === 'builtin'))
const uploaded = computed(() => families.value.filter((f) => f.kind === 'uploaded' && !f.removed))
const themeFace = computed(() => library.value?.theme_face ?? null)

// Specimens: the library's faces through the FontFace API, kept in line with every read.
watch(
  library,
  (lib) => {
    if (lib) void loadFamilyFaces(lib.families, lib.theme_face)
  },
  { immediate: true },
)

const chosenId = computed(() => (props.value?.type === 'font' ? props.value.value : null))
const chosen = computed(() => families.value.find((f) => f.id === chosenId.value) ?? null)
/** A stored family the picker cannot offer: removed, or unknown here (another site's, purged). */
const missing = computed<{ label: string } | null>(() => {
  if (chosenId.value === null || library.value === undefined) return null
  const family = chosen.value
  if (family === null) return { label: `Unknown typeface (${chosenId.value})` }
  return family.removed ? { label: `Removed typeface: ${family.name}` } : null
})
const facesLine = computed(() =>
  chosen.value !== null && !chosen.value.removed ? facesLabel(chosen.value, themeFace.value) : null,
)

/**
 * What the stage renders that the chosen family does not supply (block typeface spec §4.2): a
 * block's target or a part only — a class renders nowhere — and only for a family whose faces are
 * known; a built-in or a file whose weights could not be read says nothing.
 */
const notices = computed<string[]>(() => {
  const family = chosen.value
  const measured = props.computed
  if (props.context === 'class' || props.context === 'region' || !measured || !family) return []
  if (family.removed || suppliedWeights(family) === null) return []
  const out: string[] = []
  const weight = measured.weight
  if (!family.faces.some((f) => weight >= f.weight_min && weight <= f.weight_max)) {
    out.push(
      `${weight} isn't supplied by ${family.name}; the browser will select an available face.`,
    )
  }
  if (measured.style !== 'normal' && !family.faces.some((f) => f.italic)) {
    out.push("There's no italic face; the browser may slant the text.")
  }
  return out
})

/** Each option's own face: the generated family before its fallback, a built-in's named stack. */
function specimen(family: FontFamily): string {
  if (family.kind === 'uploaded')
    return `"thallo-font-${family.id}",${fallbackStack(family.fallback)}`
  if (family.id === 'theme') {
    return themeFace.value?.files.length
      ? `"${THEME_FACE_FAMILY}",${FONT_STACKS.system}`
      : FONT_STACKS.system!
  }
  return FONT_STACKS[family.id] ?? FONT_STACKS.system!
}

const list = ref<HTMLElement | null>(null)
function chooseAnother(): void {
  list.value?.querySelector<HTMLButtonElement>('button[data-test^="typeface-option-"]')?.focus()
}
</script>

<template>
  <div class="space-y-2" data-test="typeface-control">
    <div
      v-if="missing"
      class="space-y-1 rounded-md border border-warning/40 bg-warning/5 p-2"
      data-test="typeface-missing"
    >
      <button
        type="button"
        disabled
        class="w-full rounded px-2 py-1 text-start text-xs font-medium"
        data-test="typeface-missing-option"
      >
        {{ missing.label }}
      </button>
      <p class="text-[11px] text-muted" data-test="typeface-missing-line">
        Renders inheriting the enclosing font.
      </p>
      <div class="flex flex-wrap gap-2 text-[11px]">
        <button
          type="button"
          class="text-primary hover:underline"
          data-test="typeface-choose-another"
          @click="chooseAnother()"
        >
          Choose another
        </button>
        <button
          type="button"
          class="text-muted hover:text-default"
          data-test="typeface-clear"
          @click="emit('clear')"
        >
          Clear
        </button>
        <RouterLink
          v-if="library?.can_manage"
          to="/appearance#typefaces"
          class="text-muted hover:text-default"
          data-test="typeface-restore"
        >
          Restore in Site › Appearance
        </RouterLink>
      </div>
    </div>

    <div ref="list" class="space-y-2">
      <div role="group" aria-label="Built-in" data-test="typeface-group-builtin">
        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Built-in</p>
        <div class="flex flex-wrap gap-1">
          <button
            v-for="family in builtIns"
            :key="family.id"
            type="button"
            class="rounded-md border px-2 py-1 text-sm"
            :class="
              family.id === chosenId
                ? 'border-primary bg-primary/10 text-primary'
                : 'border-default text-muted hover:text-default'
            "
            :style="{ fontFamily: specimen(family) }"
            :title="family.id === 'theme' ? 'The theme’s original face.' : undefined"
            :aria-pressed="family.id === chosenId ? 'true' : 'false'"
            :data-test="`typeface-option-${family.id}`"
            @click="emit('pick', family.id)"
          >
            {{ family.name }}
          </button>
        </div>
      </div>
      <div
        v-if="uploaded.length > 0"
        role="group"
        aria-label="Your fonts"
        data-test="typeface-group-uploaded"
      >
        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Your fonts</p>
        <div class="flex flex-wrap gap-1">
          <button
            v-for="family in uploaded"
            :key="family.id"
            type="button"
            class="rounded-md border px-2 py-1 text-sm"
            :class="
              family.id === chosenId
                ? 'border-primary bg-primary/10 text-primary'
                : 'border-default text-muted hover:text-default'
            "
            :style="{ fontFamily: specimen(family) }"
            :aria-pressed="family.id === chosenId ? 'true' : 'false'"
            :data-test="`typeface-option-${family.id}`"
            @click="emit('pick', family.id)"
          >
            {{ family.name }}
          </button>
        </div>
      </div>
    </div>

    <p v-if="facesLine" class="text-[11px] text-muted" data-test="typeface-faces">
      {{ facesLine }}
    </p>
    <div
      v-if="notices.length > 0"
      class="space-y-0.5 text-[11px] text-warning"
      data-test="typeface-notice"
    >
      <p v-for="line in notices" :key="line">{{ line }}</p>
    </div>
  </div>
</template>
