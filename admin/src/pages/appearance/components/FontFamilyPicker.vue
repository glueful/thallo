<script setup lang="ts">
// Custom's Text or Headings (block typeface spec §2.8; plan Task 11): Not set, the built-ins and the
// site's own families, each set in its own face; a saved family the library no longer offers is
// named; and "Add a font…" adds a family to the library and picks it.
import { computed, ref, watch } from 'vue'
import { useFontLibrary, type FontFamily } from '@/queries/fontLibrary'
import { loadFamilyFaces } from '@/fonts/loadFamilyFaces'
import { FONT_STACKS, THEME_FACE_FAMILY, fallbackStack } from '@/fonts/stacks'
import FontFamilyDialog from './FontFamilyDialog.vue'

const props = defineProps<{
  /** A font ID, or '' for Not set. */
  modelValue: string
  /** The role, for the option group's accessible name. */
  label: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

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
const families = computed(() => library.value?.families ?? BUILT_INS)
const builtIns = computed(() => families.value.filter((f) => f.kind === 'builtin'))
const yours = computed(() => families.value.filter((f) => f.kind === 'uploaded' && !f.removed))
const themeFace = computed(() => library.value?.theme_face ?? null)

watch(
  library,
  (lib) => {
    if (lib) void loadFamilyFaces(lib.families, lib.theme_face)
  },
  { immediate: true },
)

const missing = computed<string | null>(() => {
  if (props.modelValue === '' || library.value === undefined) return null
  const family = families.value.find((f) => f.id === props.modelValue)
  if (!family) return `Unknown typeface (${props.modelValue})`
  return family.removed ? `Removed typeface: ${family.name}` : null
})

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

const adding = ref(false)
</script>

<template>
  <div class="space-y-2" role="group" :aria-label="label" data-test="font-family-picker">
    <p
      v-if="missing"
      class="rounded-md border border-warning/40 bg-warning/5 p-2 text-xs"
      data-test="family-missing"
    >
      {{ missing }} — this role falls back until another is chosen.
    </p>
    <div class="flex flex-wrap gap-1">
      <button
        type="button"
        class="rounded-md border px-2 py-1 text-sm"
        :class="
          modelValue === ''
            ? 'border-primary bg-primary/10 text-primary'
            : 'border-default text-muted hover:text-default'
        "
        :aria-pressed="modelValue === '' ? 'true' : 'false'"
        data-test="family-option-unset"
        @click="emit('update:modelValue', '')"
      >
        Not set
      </button>
      <button
        v-for="family in [...builtIns, ...yours]"
        :key="family.id"
        type="button"
        class="rounded-md border px-2 py-1 text-sm"
        :class="
          family.id === modelValue
            ? 'border-primary bg-primary/10 text-primary'
            : 'border-default text-muted hover:text-default'
        "
        :style="{ fontFamily: specimen(family) }"
        :title="family.id === 'theme' ? 'The theme’s original face.' : undefined"
        :aria-pressed="family.id === modelValue ? 'true' : 'false'"
        :data-test="`family-option-${family.id}`"
        @click="emit('update:modelValue', family.id)"
      >
        {{ family.name }}
      </button>
      <button
        type="button"
        class="rounded-md border border-dashed border-default px-2 py-1 text-sm text-muted hover:text-default"
        data-test="family-add"
        @click="adding = true"
      >
        Add a font…
      </button>
    </div>
    <FontFamilyDialog v-model:open="adding" @saved="(id) => emit('update:modelValue', id)" />
  </div>
</template>
