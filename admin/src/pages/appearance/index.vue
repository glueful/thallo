<script setup lang="ts">
// Site › Appearance: how the site LOOKS, in five tabs — Theme, Colours, Design, Typefaces, Logos &
// site icon (appearance tabs spec). These are general settings and share that endpoint with
// Settings › General, which owns how the site behaves; each page edits and saves only its own keys
// (useSettingsForm), and one Save saves every tab.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQueryCache } from '@pinia/colada'
import { useGeneralSettings, useGeneralSettingsMutations } from '@/queries/generalSettings'
import { useSettingsForm } from '@/composables/useSettingsForm'
import AssetField from '@/fields/components/AssetField.vue'
import FaviconPreview from './components/FaviconPreview.vue'
import AppearancePreview from './components/AppearancePreview.vue'
import { blobDisplayUrl } from '@/queries/media'
import { fetchRenderThemes, type ThemeCard } from '@/queries/templates'
import ThemeGallery from './components/ThemeGallery.vue'
import BrandColorField from './components/BrandColorField.vue'
import TypefacesCard from './components/TypefacesCard.vue'
import FontFamilyPicker from './components/FontFamilyPicker.vue'
import { useNotify } from '@/composables/useNotify'
import { useAppearanceChanges } from '@/composables/useAppearanceChanges'
import { useStyleSchema } from '@/queries/styleSchema'
import { qk } from '@/queries/keys'
import { normalizeHex } from '@/style/contrast'
import {
  fetchPaletteJobs,
  previewPalette,
  type BrandKey,
  type NeutralKey,
  type PaletteJob,
  type PaletteLook,
} from '@/queries/palette'
import CustomNeutralFields from './components/CustomNeutralFields.vue'
import BrandColorsField, { type BrandDraft } from './components/BrandColorsField.vue'
import ContrastChecks from './components/ContrastChecks.vue'
import ClearBrandDialog from './components/ClearBrandDialog.vue'
import {
  TAB_LABELS,
  availableTabs,
  tabFromQuery,
  tabsHolding,
  type AppearanceTab,
} from './appearanceTabs'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useGeneralSettings()
const { save } = useGeneralSettingsMutations()

const { form, dirty, payload, saved } = useSettingsForm(data, {
  theme: '',
  theme_accent: 'blue',
  theme_neutral: 'slate',
  theme_radius: 'round',
  theme_font: 'sans',
  theme_font_text_family: '',
  theme_font_headings_family: '',
  theme_background: 'plain',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
  theme_neutral_custom: '',
  theme_dark_base: '',
  theme_brand_1: '',
  theme_brand_2: '',
  theme_brand_3: '',
})
/** Read, never written here: the favicon preview's tab title, and what a preview opens through. */
const siteName = computed(() => data.value?.site_name ?? '')

// The neutral is a closed family, shown with its 500 stop; the accent is BrandColorField's.
const NEUTRAL_FAMILIES: Array<{ value: string; swatch: string }> = [
  { value: 'slate', swatch: '#64748b' },
  { value: 'gray', swatch: '#6b7280' },
  { value: 'zinc', swatch: '#71717a' },
  { value: 'neutral', swatch: '#737373' },
  { value: 'stone', swatch: '#78716c' },
]
// Design settings (website plan phase 1b): closed enums, labelled for the operator.
const RADIUS_ITEMS = [
  { value: 'sharp', label: 'Sharp — 4px corners, square buttons' },
  { value: 'soft', label: 'Soft — 12px corners, rounded buttons' },
  { value: 'round', label: 'Round — 12px corners, pill buttons (default)' },
]
// Every pairing but Custom is a system stack: it costs a visitor nothing to download.
const FONT_ITEMS = [
  { value: 'sans', label: 'Sans — Figtree throughout (default)' },
  { value: 'editorial', label: 'Editorial — serif headings, sans body' },
  { value: 'serif', label: 'Serif — serif throughout' },
  { value: 'humanist', label: 'Humanist — warm, open sans' },
  { value: 'geometric', label: 'Geometric — round, even sans' },
  { value: 'slab', label: 'Slab — slab-serif headings, sans body' },
  { value: 'mono', label: 'Mono — monospace throughout' },
  { value: 'system', label: "System — each visitor's own interface font" },
  { value: 'custom', label: 'Custom — choose from the font library' },
]
const BACKGROUND_ITEMS = [
  { value: 'plain', label: 'Plain — white page, tinted panels (default)' },
  { value: 'tinted', label: 'Tinted — tinted page, white panels' },
]
const neutralItems = [
  ...NEUTRAL_FAMILIES.map((f) => ({ value: f.value, label: f.value })),
  { value: 'custom', label: 'Custom — your own colours' },
]
const neutralSwatch = computed(() =>
  form.theme_neutral === 'custom'
    ? (neutralCustom.value?.bg ?? '#ffffff')
    : (NEUTRAL_FAMILIES.find((f) => f.value === form.theme_neutral)?.swatch ?? '#64748b'),
)

// ── The palette (custom palette spec §2, §5.1) ───────────────────────────────────────────────
// The form keeps each palette key as the JSON string the server stores; these read and write it.
const NEUTRAL_KEYS: NeutralKey[] = ['bg', 'surface', 'surface_2', 'ink', 'muted', 'line']
const BRAND_KEYS: BrandKey[] = ['1', '2', '3']
const brandField = (key: BrandKey) => `theme_brand_${key}` as const

function parseJson(value: string | undefined): Record<string, unknown> | null {
  if (!value) return null
  try {
    const parsed: unknown = JSON.parse(value)
    return typeof parsed === 'object' && parsed !== null
      ? (parsed as Record<string, unknown>)
      : null
  } catch {
    return null
  }
}

const neutralCustom = computed<Record<NeutralKey, string> | null>(() => {
  const parsed = parseJson(form.theme_neutral_custom)
  if (parsed === null) return null
  const out = {} as Record<NeutralKey, string>
  for (const key of NEUTRAL_KEYS) out[key] = String(parsed[key] ?? '')
  return out
})
function setNeutralCustom(next: Record<NeutralKey, string>): void {
  form.theme_neutral_custom = JSON.stringify(next)
}

const brandDrafts = computed<Record<BrandKey, BrandDraft>>(() => {
  const out = {} as Record<BrandKey, BrandDraft>
  for (const key of BRAND_KEYS) {
    const parsed = parseJson(form[brandField(key)])
    out[key] = { name: String(parsed?.name ?? ''), hex: String(parsed?.hex ?? '') }
  }
  return out
})
function setBrandDrafts(next: Record<BrandKey, BrandDraft>): void {
  for (const key of BRAND_KEYS) form[brandField(key)] = JSON.stringify(next[key])
}
/** A slot as the server would store it, or null while it is not a name and a colour. */
function validBrand(draft: BrandDraft): BrandDraft | null {
  const name = draft.name.trim()
  const hex = normalizeHex(draft.hex)
  return name !== '' && name.length <= 32 && hex !== null ? { name, hex } : null
}
const configured = computed<Record<BrandKey, boolean>>(() => {
  const out = {} as Record<BrandKey, boolean>
  for (const key of BRAND_KEYS) out[key] = parseJson(data.value?.[brandField(key)]) !== null
  return out
})

// The family the form had before Custom: Custom's first values come from it, and Reset returns to it.
const lastFamily = ref(form.theme_neutral === 'custom' ? '' : form.theme_neutral)
watch(
  () => form.theme_neutral,
  async (next, previous) => {
    if (next !== 'custom') {
      lastFamily.value = next
      return
    }
    const family = previous && previous !== 'custom' ? previous : lastFamily.value || 'slate'
    if (form.theme_dark_base === '') form.theme_dark_base = family
    if (neutralCustom.value !== null) return // stored or already edited: never pre-filled again
    try {
      const look = await previewPalette({ theme_neutral: family, palette: emptyPalette() })
      const light = look.values.light
      if (form.theme_neutral === 'custom' && neutralCustom.value === null) {
        setNeutralCustom({
          bg: light.background ?? '#ffffff',
          surface: light.surface ?? '#ffffff',
          surface_2: light['surface-2'] ?? '#ffffff',
          ink: light.text ?? '#000000',
          muted: light.muted ?? '#666666',
          line: light.line ?? '#dddddd',
        })
      }
    } catch {
      // the six fields stay empty to fill in by hand
    }
  },
)
function resetNeutral(): void {
  form.theme_neutral = form.theme_dark_base || lastFamily.value || 'slate'
  form.theme_neutral_custom = ''
}
function emptyPalette(): PaletteLook['palette'] {
  return { neutral_custom: null, dark_base: null, brands: { '1': null, '2': null, '3': null } }
}
const resetFamily = computed(() => form.theme_dark_base || lastFamily.value || 'slate')

const { data: styleSchema } = useStyleSchema()
const colorMode = computed(() => styleSchema.value?.palette?.color_mode !== false)
const schemaSlots = computed(() => styleSchema.value?.palette?.slots ?? {})

/** The pending palette: what the preview frames and the contrast checks judge. */
const pendingPalette = computed<PaletteLook['palette']>(() => {
  const brands = {} as PaletteLook['palette']['brands']
  for (const key of BRAND_KEYS) brands[key] = validBrand(brandDrafts.value[key])
  return {
    neutral_custom: form.theme_neutral === 'custom' ? neutralCustom.value : null,
    dark_base: form.theme_dark_base || null,
    brands,
  }
})
/** The saved palette, read as the form reads it. */
const savedPalette = computed<PaletteLook['palette']>(() => {
  const stored = data.value as Record<string, string | undefined> | undefined
  const custom = parseJson(stored?.theme_neutral_custom)
  const brands = {} as PaletteLook['palette']['brands']
  for (const key of BRAND_KEYS) {
    const parsed = parseJson(stored?.[brandField(key)])
    brands[key] =
      parsed === null
        ? null
        : validBrand({ name: String(parsed.name ?? ''), hex: String(parsed.hex ?? '') })
  }
  return {
    neutral_custom:
      stored?.theme_neutral === 'custom' && custom !== null
        ? (Object.fromEntries(NEUTRAL_KEYS.map((k) => [k, String(custom[k] ?? '')])) as Record<
            NeutralKey,
            string
          >)
        : null,
    dark_base: stored?.theme_dark_base || null,
    brands,
  }
})
const paletteChanged = computed(
  () => JSON.stringify(pendingPalette.value) !== JSON.stringify(savedPalette.value),
)
const contrastLook = computed<PaletteLook>(() => ({
  theme_accent: form.theme_accent,
  theme_neutral: form.theme_neutral,
  theme_background: form.theme_background,
  palette: pendingPalette.value,
}))

// The running replacements (custom palette spec §4.4): their progress, refreshed every 2 s while
// one runs; when the last one ends, the palette is read again.
const jobs = ref<PaletteJob[]>([])
let jobTimer: ReturnType<typeof setInterval> | null = null
const queryCache = useQueryCache()
async function loadJobs(): Promise<void> {
  try {
    const before = jobs.value.some((j) => j.status === 'running')
    jobs.value = await fetchPaletteJobs()
    const running = jobs.value.some((j) => j.status === 'running')
    if (running && jobTimer === null) jobTimer = setInterval(() => void loadJobs(), 2000)
    if (!running && jobTimer !== null) {
      clearInterval(jobTimer)
      jobTimer = null
    }
    if (before && !running) {
      void queryCache.invalidateQueries({ key: qk.styleSchema() })
      void queryCache.invalidateQueries({ key: ['settings', 'general'] })
    }
  } catch {
    jobs.value = []
  }
}
onMounted(() => void loadJobs())
onBeforeUnmount(() => {
  if (jobTimer !== null) clearInterval(jobTimer)
})

/** Clear opens the usage dialog: Clear when nothing uses the colour, else Replace with…. */
const clearing = ref<1 | 2 | 3 | null>(null)
function onClearBrand(slot: 1 | 2 | 3): void {
  clearing.value = slot
}
const clearingName = computed(() => {
  if (clearing.value === null) return ''
  const key = String(clearing.value) as BrandKey
  const stored = parseJson(data.value?.[brandField(key)])
  return String(stored?.name ?? '') || `Brand ${key}`
})
/** A clear or a replacement started: read the palette, the settings and the jobs again. */
function onPaletteChanged(): void {
  void queryCache.invalidateQueries({ key: qk.styleSchema() })
  void queryCache.invalidateQueries({ key: ['settings', 'general'] })
  appearanceChanges.notify('appearance')
  void loadJobs()
}

/**
 * This page's keys as the server should take them: a palette key only when it changed — a brand
 * colour only once it is a name and a colour (Clear is how one is taken off), the dark base never
 * as '' — and the rest as they stand.
 */
function savePayload(): Record<string, unknown> {
  const out: Record<string, unknown> = { ...payload() }
  const stored = (key: string) =>
    String((data.value as Record<string, unknown> | undefined)?.[key] ?? '')
  for (const key of BRAND_KEYS) {
    const field = brandField(key)
    const valid = validBrand(brandDrafts.value[key])
    const canonical = valid === null ? null : JSON.stringify(valid)
    if (
      canonical === null ||
      canonical === stored(field) ||
      JSON.stringify(parseJson(stored(field))) === canonical
    ) {
      delete out[field]
    } else {
      out[field] = canonical
    }
  }
  if (form.theme_neutral_custom === stored('theme_neutral_custom')) delete out.theme_neutral_custom
  if (form.theme_dark_base === '' || form.theme_dark_base === stored('theme_dark_base'))
    delete out.theme_dark_base
  return out
}

/** What the preview frames: the look as it stands in the form, saved or not. */
const pendingLook = computed(() => ({
  // The palette rides along only once it differs from the saved one (custom palette spec §5.1).
  ...(paletteChanged.value ? { palette: pendingPalette.value } : {}),
  // Only a theme OTHER than the live one is previewed as a theme; see PendingLook.
  theme: form.theme !== '' && form.theme !== (data.value?.theme ?? '') ? form.theme : '',
  accent: form.theme_accent,
  neutral: form.theme_neutral,
  radius: form.theme_radius,
  font: form.theme_font,
  background: form.theme_background,
  // Custom's families ride along only with the pairing that uses them. `none` is a saved family
  // taken off but not yet saved: '' would mean "as saved" to the preview.
  ...(form.theme_font === 'custom'
    ? {
        font_text_family: form.theme_font_text_family || 'none',
        font_headings_family: form.theme_font_headings_family || 'none',
      }
    : {}),
}))

// Live theme options (theme-setting spec §4): fetched from the render pack. A fetch failure (pack
// absent, no permission) or a body without the list hides the Theme tab once the load ends; while
// it is pending the tab stays, so a page opened on Theme never jumps there from Colours.
const themeCards = ref<ThemeCard[]>([])
const themesLoaded = ref(false)
onMounted(async () => {
  try {
    // A body without the list must not be assigned — the template reads its length.
    const cards: unknown = (await fetchRenderThemes()).cards
    themeCards.value = Array.isArray(cards) ? (cards as ThemeCard[]) : []
  } catch {
    themeCards.value = []
  } finally {
    themesLoaded.value = true
  }
})

// ── The tabs (appearance tabs spec §2–§3) ─────────────────────────────────────────────────────
// The open tab is the URL's ?tab= — the default leaves it out — so a refresh, a shared link, and
// back and forward all open the same tab. A tab the user picks is pushed, so Back returns to the
// last one; the panels are UTabs' own and never unmount, so nothing unsaved is lost.
const route = useRoute()
const router = useRouter()
const tabs = computed(() => availableTabs(!themesLoaded.value || themeCards.value.length > 0))
const tab = computed<AppearanceTab>({
  get: () => tabFromQuery(route.query.tab, tabs.value),
  set: (next) => {
    const query = { ...route.query }
    if (next === tabs.value[0]) delete query.tab
    else query.tab = next
    void router.push({ query })
  },
})
// Each item names its panel's slot: `#theme`, `#colours`, … below.
const tabItems = computed(() =>
  tabs.value.map((value) => ({ label: TAB_LABELS[value], value, slot: value })),
)

/** The keys Save would send that differ from what is stored: each one's tab shows a dot. */
const dirtyTabs = computed(() => {
  const stored = (data.value ?? {}) as Record<string, unknown>
  const out = savePayload()
  return tabsHolding(
    Object.keys(out).filter((key) => String(out[key] ?? '') !== String(stored[key] ?? '')),
  )
})
/** The tabs holding a field the last refused save named; cleared when the next save starts. */
const errorTabs = ref(new Set<AppearanceTab>())

// AssetField drives the same picker the block editor uses; single asset.
const logoField = { name: 'site_logo', label: '', type: 'asset' } as const
const logoDarkField = { name: 'site_logo_dark', label: '', type: 'asset' } as const
const faviconField = { name: 'site_favicon', label: '', type: 'asset' } as const

// A saved appearance changes every stage's head: the admin's other tabs reload theirs.
const appearanceChanges = useAppearanceChanges()

async function onSave() {
  errorTabs.value = new Set()
  try {
    await save.mutateAsync(savePayload())
    saved()
    appearanceChanges.notify('appearance')
    success('Appearance saved', 'Changes apply on the next page view.')
  } catch (e) {
    // A refused save opens the first tab, in tab order, holding a field it names (spec §3).
    const fields = (e as { fieldErrors?: Record<string, string> }).fieldErrors ?? {}
    const holding = tabsHolding(Object.keys(fields))
    errorTabs.value = holding
    const first = tabs.value.find((t) => holding.has(t))
    if (first !== undefined) tab.value = first
    notifyError(e, 'Couldn’t save the appearance settings')
  }
}
</script>

<template>
  <UDashboardPanel id="appearance">
    <template #header>
      <UDashboardNavbar title="Appearance">
        <template #right>
          <UChip :show="dirty" color="warning" size="sm">
            <UButton
              icon="i-lucide-save"
              :loading="save.isLoading.value"
              data-test="appearance-save"
              @click="onSave"
            >
              Save
            </UButton>
          </UChip>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-[110rem] pb-5">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-28" />
          <USkeleton class="h-40" />
          <USkeleton class="h-40" />
        </div>
        <!-- The settings in tabs on the left; the homepage wearing them on the right, pinned while
             the cards scroll, whichever tab is open. Below xl the preview comes first, full width. -->
        <div v-else class="grid gap-6 xl:grid-cols-[minmax(0,25rem)_minmax(0,1fr)]">
          <div class="order-2 xl:order-1">
            <!-- UTabs' own panels, kept mounted: each is wired to its tab (id / aria-labelledby /
                 aria-controls), hidden with the `hidden` attribute, and nothing unsaved is lost on
                 a switch. -->
            <UTabs
              v-model="tab"
              variant="link"
              :items="tabItems"
              :unmount-on-hide="false"
              :ui="{ list: 'mb-4' }"
              data-test="appearance-tabs"
            >
              <template #trailing="{ item }">
                <span
                  v-if="errorTabs.has(item.value as AppearanceTab)"
                  class="inline-block size-2 rounded-full bg-error"
                  :data-test="`appearance-tab-error-${item.value}`"
                  ><span class="sr-only">{{ item.label }} — has errors</span></span
                >
                <span
                  v-else-if="dirtyTabs.has(item.value as AppearanceTab)"
                  class="inline-block size-2 rounded-full bg-warning"
                  :data-test="`appearance-tab-dirty-${item.value}`"
                  ><span class="sr-only">{{ item.label }} — unsaved changes</span></span
                >
              </template>

              <template #theme>
                <div class="space-y-6" data-test="appearance-panel-theme">
                  <USkeleton v-if="!themesLoaded" class="h-40" />
                  <UCard v-else-if="themeCards.length > 0" data-test="theme-card">
                    <template #header>
                      <h2 class="font-semibold text-default">Theme</h2>
                      <p class="text-sm text-muted">
                        Choose one to see it in the preview; Save makes it live on the next page
                        view. To make your own, duplicate a theme in the
                        <RouterLink to="/templates" class="text-primary hover:underline"
                          >Theme editor</RouterLink
                        >.
                      </p>
                    </template>
                    <ThemeGallery
                      v-model="form.theme"
                      :cards="themeCards"
                      :live="data?.theme ?? ''"
                    />
                  </UCard>
                </div>
              </template>

              <template #colours>
                <div class="space-y-6" data-test="appearance-panel-colours">
                  <UCard data-test="theme-colors-card">
                    <template #header>
                      <h2 class="font-semibold text-default">Colours</h2>
                    </template>
                    <div class="space-y-6">
                      <p class="text-sm text-muted">
                        Re-skins the theme's tokens only — never changes templates. The default blue
                        / slate reproduces the current look.
                      </p>
                      <!-- One per row, like the design settings below: side by side at this width the longer
                         description wrapped and pushed its select out of line with the other. -->
                      <div class="grid gap-6">
                        <UFormField
                          label="Accent"
                          description="A colour family, or your own brand colour."
                        >
                          <BrandColorField v-model="form.theme_accent" />
                        </UFormField>
                        <UFormField label="Neutral" description="Backgrounds, text, borders.">
                          <div class="flex items-center gap-2">
                            <span
                              class="inline-block size-4 rounded-full ring-1 ring-default"
                              :style="{ background: neutralSwatch }"
                              data-test="theme-neutral-swatch"
                            />
                            <USelect
                              v-model="form.theme_neutral"
                              :items="neutralItems"
                              value-key="value"
                              class="w-full"
                              data-test="theme-neutral"
                            />
                          </div>
                        </UFormField>
                        <CustomNeutralFields
                          v-if="form.theme_neutral === 'custom' && neutralCustom"
                          :model-value="neutralCustom"
                          :dark-base="form.theme_dark_base ?? ''"
                          :show-dark-base="colorMode"
                          :family="resetFamily"
                          @update:model-value="setNeutralCustom"
                          @update:dark-base="(v: string) => (form.theme_dark_base = v)"
                          @reset="resetNeutral"
                        />
                        <UFormField
                          label="Brand colours"
                          description="Named colours every block's colour picker offers. Up to three."
                        >
                          <BrandColorsField
                            :slots="brandDrafts"
                            :configured="configured"
                            :palette="schemaSlots"
                            :jobs="jobs"
                            @update:slots="setBrandDrafts"
                            @clear="onClearBrand"
                          />
                        </UFormField>
                        <UFormField label="Contrast">
                          <ContrastChecks :look="contrastLook" />
                        </UFormField>
                        <ClearBrandDialog
                          v-if="clearing !== null"
                          :open="clearing !== null"
                          :slot="clearing"
                          :name="clearingName"
                          :palette="styleSchema?.palette"
                          :colours="styleSchema?.vocabulary?.domains.color ?? []"
                          :look="contrastLook"
                          @update:open="(v: boolean) => (v ? null : (clearing = null))"
                          @done="onPaletteChanged"
                        />
                      </div>
                    </div>
                  </UCard>
                </div>
              </template>

              <template #design>
                <div class="space-y-6" data-test="appearance-panel-design">
                  <UCard data-test="theme-design-card">
                    <template #header>
                      <h2 class="font-semibold text-default">Design</h2>
                    </template>
                    <div class="space-y-6">
                      <p class="text-sm text-muted">
                        Site-wide shape and ground. Each choice re-maps theme tokens only; a button
                        can still pick its own shape.
                      </p>
                      <!-- Stacked: this column is narrow, and each option reads as a sentence. -->
                      <div class="grid gap-6">
                        <UFormField label="Corners" description="Radius of panels and buttons.">
                          <USelect
                            v-model="form.theme_radius"
                            :items="RADIUS_ITEMS"
                            value-key="value"
                            class="w-full"
                            data-test="theme-radius"
                          />
                        </UFormField>
                        <UFormField label="Page ground" description="What the page sits on.">
                          <USelect
                            v-model="form.theme_background"
                            :items="BACKGROUND_ITEMS"
                            value-key="value"
                            class="w-full"
                            data-test="theme-background"
                          />
                        </UFormField>
                      </div>
                    </div>
                  </UCard>
                </div>
              </template>

              <template #typefaces>
                <div class="space-y-6" data-test="appearance-panel-typefaces">
                  <UCard data-test="theme-typefaces-card">
                    <template #header>
                      <h2 class="font-semibold text-default">Typefaces</h2>
                    </template>
                    <div class="grid gap-6">
                      <UFormField label="Pairing" description="Headings and body text.">
                        <USelect
                          v-model="form.theme_font"
                          :items="FONT_ITEMS"
                          value-key="value"
                          class="w-full"
                          data-test="theme-font"
                        />
                      </UFormField>
                      <div
                        v-if="form.theme_font === 'custom'"
                        class="space-y-4"
                        data-test="custom-fonts"
                      >
                        <p class="text-xs text-muted">
                          With only a text font, headings use it too; with neither, the theme's own
                          font stays.
                        </p>
                        <!-- Custom's Text and Headings come from the font library (block typeface
                               spec §2.8); Not set leaves the role to its fallback. -->
                        <UFormField label="Text">
                          <FontFamilyPicker
                            v-model="form.theme_font_text_family"
                            label="Text"
                            data-test="font-family-text"
                          />
                        </UFormField>
                        <UFormField label="Headings">
                          <FontFamilyPicker
                            v-model="form.theme_font_headings_family"
                            label="Headings"
                            data-test="font-family-headings"
                          />
                        </UFormField>
                      </div>
                    </div>
                  </UCard>
                  <TypefacesCard />
                </div>
              </template>

              <template #logos>
                <div class="space-y-6" data-test="appearance-panel-logos">
                  <UCard data-test="logos-card">
                    <template #header>
                      <h2 class="font-semibold text-default">Logos &amp; site icon</h2>
                    </template>
                    <div class="space-y-6">
                      <!-- One under the other: side by side in this column their descriptions wrapped to
                           different heights and pushed the two upload boxes out of line. A short line
                           says what each is for; the fallback rule sits under the box as help. -->
                      <div class="space-y-6">
                        <UFormField
                          label="Site logo"
                          description="Shown by the Logo block and by themes."
                          help="When unset, the site name is shown instead."
                        >
                          <div data-test="site-logo-picker">
                            <AssetField
                              v-model="form.site_logo"
                              :field="logoField"
                              empty-value=""
                              :library-button="false"
                            />
                          </div>
                        </UFormField>
                        <UFormField
                          label="Site logo (dark)"
                          description="For visitors using a dark colour scheme."
                          help="Falls back to the main logo. Themes without a dark scheme ignore it."
                        >
                          <div data-test="site-logo-dark-picker">
                            <AssetField
                              v-model="form.site_logo_dark"
                              :field="logoDarkField"
                              empty-value=""
                              :library-button="false"
                            />
                          </div>
                        </UFormField>
                      </div>
                      <!-- Site icon (below) -->
                      <div class="space-y-4">
                        <UFormField
                          label="Favicon"
                          description="The site’s icon in browser tabs and bookmarks."
                          help="PNG or SVG, square, 512×512 or larger."
                        >
                          <div data-test="site-favicon-picker">
                            <AssetField
                              v-model="form.site_favicon"
                              :field="faviconField"
                              empty-value=""
                              :library-button="false"
                              :preview="false"
                            />
                          </div>
                        </UFormField>
                        <FaviconPreview
                          v-if="form.site_favicon"
                          :src="blobDisplayUrl(form.site_favicon)"
                          :site-name="siteName"
                        />
                      </div>
                    </div>
                  </UCard>
                </div>
              </template>
            </UTabs>
          </div>
          <div class="order-1 xl:sticky xl:top-0 xl:order-2 xl:self-start">
            <AppearancePreview
              :entry="data?.homepage_entry ?? ''"
              :locale="data?.default_locale ?? 'en'"
              :look="pendingLook"
            />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
