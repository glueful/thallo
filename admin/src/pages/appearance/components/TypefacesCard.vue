<script setup lang="ts">
// Site › Appearance › Typefaces (block typeface spec §4.6; plan Task 11): the built-ins, and the
// site's own families — each set in its own face, with its faces, its fallback and how often it is
// used. Add, edit, remove (after saying where it is used), restore, delete permanently, and read a
// family's files again when they could not be read before.
import { computed, ref, watch } from 'vue'
import {
  facesLabel,
  fetchFontUsage,
  fetchFontUsageCounts,
  useFontLibrary,
  useFontLibraryMutations,
  usageCount,
  type FontFamily,
  type FontUsage,
} from '@/queries/fontLibrary'
import { loadFamilyFaces } from '@/fonts/loadFamilyFaces'
import { FONT_STACKS, THEME_FACE_FAMILY, fallbackStack } from '@/fonts/stacks'
import { useNotify } from '@/composables/useNotify'
import FontFamilyDialog from './FontFamilyDialog.vue'
import FontUsageDialog from './FontUsageDialog.vue'

const { success, error: notifyError } = useNotify()
const { data: library } = useFontLibrary()
const mutations = useFontLibraryMutations()

const families = computed(() => library.value?.families ?? [])
const builtIns = computed(() => families.value.filter((f) => f.kind === 'builtin'))
const yours = computed(() => families.value.filter((f) => f.kind === 'uploaded' && !f.removed))
const removed = computed(() => families.value.filter((f) => f.kind === 'uploaded' && f.removed))
const themeFace = computed(() => library.value?.theme_face ?? null)

watch(
  library,
  (lib) => {
    if (lib) void loadFamilyFaces(lib.families, lib.theme_face)
  },
  { immediate: true },
)

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

// How often each family is used: one request for all of them, after the list shows — never part
// of the list's own request, and never a full scan per family.
const counts = ref<Record<string, number>>({})
let countsAsked = ''
watch(
  yours,
  (list) => {
    const ids = list.map((f) => f.id).join(',')
    if (ids === '' || ids === countsAsked) return
    countsAsked = ids
    fetchFontUsageCounts()
      .then((c) => (counts.value = c))
      .catch(() => {
        countsAsked = '' // shown as unknown; asked again when the list changes
      })
  },
  { immediate: true },
)
function usageLine(id: string): string {
  const n = counts.value[id]
  if (n === undefined) return ''
  return n === 0 ? 'Not used' : n === 1 ? 'Used in 1 place' : `Used in ${n} places`
}

// Add and edit.
const dialogOpen = ref(false)
const editing = ref<FontFamily | null>(null)
function add(): void {
  editing.value = null
  dialogOpen.value = true
}
function edit(family: FontFamily): void {
  editing.value = family
  dialogOpen.value = true
}
function onSaved(): void {
  success(editing.value ? 'Family saved' : 'Family added')
}

// Remove: first where it is used.
const removing = ref<FontFamily | null>(null)
const removingUsage = ref<FontUsage | null>(null)
async function askRemove(family: FontFamily): Promise<void> {
  removing.value = family
  removingUsage.value = null
  try {
    removingUsage.value = await fetchFontUsage(family.id)
    counts.value = { ...counts.value, [family.id]: usageCount(removingUsage.value) }
  } catch (e) {
    removing.value = null
    notifyError(e, 'Couldn’t find where the family is used')
  }
}
async function confirmRemove(): Promise<void> {
  const family = removing.value
  if (!family) return
  try {
    await mutations.remove.mutateAsync(family.id)
    removing.value = null
    success('Family removed', 'Restore it from Removed families.')
  } catch (e) {
    notifyError(e, 'Couldn’t remove the family')
  }
}

// Removed families: restore, or delete permanently after a second click.
const showRemoved = ref(false)
const purging = ref<string | null>(null)
async function restore(family: FontFamily): Promise<void> {
  try {
    await mutations.restore.mutateAsync(family.id)
    success('Family restored')
  } catch (e) {
    notifyError(e, 'Couldn’t restore the family')
  }
}
async function purge(id: string): Promise<void> {
  try {
    await mutations.purge.mutateAsync(id)
    purging.value = null
    success('Family deleted')
  } catch (e) {
    notifyError(e, 'Couldn’t delete the family')
  }
}

// Read again: only for a family whose files could not be read; the result may look different.
const rereading = ref<string | null>(null)
async function readAgain(id: string): Promise<void> {
  try {
    await mutations.readAgain.mutateAsync(id)
    rereading.value = null
    success('Files read again')
  } catch (e) {
    notifyError(e, 'Couldn’t read the files again')
  }
}
const hasUnknown = (family: FontFamily) => family.faces.some((f) => f.unknown)
</script>

<template>
  <UCard id="typefaces" data-test="typefaces-card">
    <template #header>
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold text-default">Font library</h2>
          <p class="text-sm text-muted">
            What blocks, style classes and Custom can be set in. Built-ins cost a visitor nothing to
            download.
          </p>
        </div>
        <UTooltip text="Add family">
          <UButton
            size="sm"
            icon="i-lucide-plus"
            square
            aria-label="Add family"
            class="shrink-0"
            data-test="typefaces-add"
            @click="add"
          />
        </UTooltip>
      </div>
    </template>

    <div class="space-y-5">
      <section>
        <h3 class="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Built-in</h3>
        <ul class="divide-y divide-default">
          <li
            v-for="family in builtIns"
            :key="family.id"
            class="flex items-baseline justify-between gap-3 py-1.5"
            :data-test="`typeface-builtin-${family.id}`"
          >
            <span class="text-base" :style="{ fontFamily: specimen(family) }" data-test="specimen">
              {{ family.name }}
            </span>
            <span class="text-right text-xs text-muted">{{ facesLabel(family, themeFace) }}</span>
          </li>
        </ul>
      </section>

      <section>
        <h3 class="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Your fonts</h3>
        <p v-if="yours.length === 0" class="text-sm text-muted">
          None yet. Add a family from .woff2 files.
        </p>
        <ul v-else class="divide-y divide-default">
          <li
            v-for="family in yours"
            :key="family.id"
            class="space-y-1 py-2"
            :data-test="`typeface-family-${family.id}`"
          >
            <div class="flex items-baseline justify-between gap-3">
              <span
                class="truncate text-lg"
                :style="{ fontFamily: specimen(family) }"
                data-test="specimen"
              >
                <span data-test="family-name">{{ family.name }}</span>
              </span>
              <span class="shrink-0 text-xs text-muted" data-test="family-usage">
                {{ usageLine(family.id) }}
              </span>
            </div>
            <p class="text-xs text-muted">
              {{ facesLabel(family) }} · Falls back to {{ family.fallback ?? 'sans-serif' }}
            </p>
            <p
              v-if="family.faces.some((f) => f.url === '')"
              class="text-xs text-warning"
              data-test="family-unserved"
            >
              A file of this family is private, so visitors get the fallback. Add it again from a
              public upload.
            </p>
            <div class="flex flex-wrap gap-2 text-xs">
              <button
                type="button"
                class="text-primary hover:underline"
                data-test="family-edit"
                @click="edit(family)"
              >
                Edit
              </button>
              <button
                v-if="hasUnknown(family)"
                type="button"
                class="text-muted hover:text-default"
                data-test="family-read-again"
                @click="rereading = family.id"
              >
                Read again
              </button>
              <button
                type="button"
                class="text-error hover:underline"
                data-test="family-remove"
                @click="askRemove(family)"
              >
                Remove
              </button>
            </div>
            <div
              v-if="rereading === family.id"
              class="flex flex-wrap items-center gap-2 rounded-md bg-elevated p-2 text-xs"
            >
              <span>If this succeeds, the font may render differently.</span>
              <UButton
                size="xs"
                data-test="family-read-again-confirm"
                @click="readAgain(family.id)"
              >
                Read again
              </UButton>
              <UButton size="xs" color="neutral" variant="ghost" @click="rereading = null">
                Cancel
              </UButton>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="removed.length > 0" data-test="typefaces-removed">
        <button
          type="button"
          class="flex items-center gap-1 text-xs font-semibold tracking-wide text-muted uppercase"
          :aria-expanded="showRemoved ? 'true' : 'false'"
          data-test="typefaces-removed-toggle"
          @click="showRemoved = !showRemoved"
        >
          <UIcon :name="showRemoved ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'" />
          Removed families ({{ removed.length }})
        </button>
        <ul class="mt-2 divide-y divide-default">
          <li
            v-for="family in removed"
            v-show="showRemoved"
            :key="family.id"
            class="space-y-1 py-2"
            :data-test="`typeface-removed-${family.id}`"
          >
            <span class="text-sm text-muted">{{ family.name }}</span>
            <div class="flex flex-wrap gap-2 text-xs">
              <button
                type="button"
                class="text-primary hover:underline"
                data-test="family-restore"
                @click="restore(family)"
              >
                Restore
              </button>
              <button
                type="button"
                class="text-error hover:underline"
                data-test="family-purge"
                @click="purging = family.id"
              >
                Delete permanently
              </button>
            </div>
            <div
              v-if="purging === family.id"
              class="flex flex-wrap items-center gap-2 rounded-md bg-elevated p-2 text-xs"
            >
              <span
                >Its files go back to the media library; whatever still names it renders as
                unknown.</span
              >
              <UButton
                size="xs"
                color="error"
                data-test="family-purge-confirm"
                @click="purge(family.id)"
              >
                Delete permanently
              </UButton>
              <UButton size="xs" color="neutral" variant="ghost" @click="purging = null">
                Cancel
              </UButton>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <FontFamilyDialog v-model:open="dialogOpen" :family="editing" @saved="onSaved" />
    <FontUsageDialog
      :open="removing !== null"
      :name="removing?.name ?? ''"
      :usage="removingUsage"
      :removing="mutations.remove.isLoading.value"
      @update:open="(v: boolean) => !v && (removing = null)"
      @confirm="confirmRemove"
    />
  </UCard>
</template>
