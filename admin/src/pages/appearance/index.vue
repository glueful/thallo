<script setup lang="ts">
// Site › Appearance: how the site LOOKS — the live theme, its colours, the design settings, the
// logos and site icon. These are general settings and share that endpoint with Settings › General,
// which owns how the site behaves; each page edits and saves only its own keys (useSettingsForm).
import { computed, onMounted, ref } from 'vue'
import { useGeneralSettings, useGeneralSettingsMutations } from '@/queries/generalSettings'
import { useSettingsForm } from '@/composables/useSettingsForm'
import AssetField from '@/fields/components/AssetField.vue'
import FaviconPreview from './components/FaviconPreview.vue'
import { blobDisplayUrl } from '@/queries/media'
import { fetchRenderThemes } from '@/queries/templates'
import { useNotify } from '@/composables/useNotify'
import { client } from '@/api/client'

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
  theme_background: 'plain',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
})
/** Read, never written here: the favicon preview's tab title, and what a preview opens through. */
const siteName = computed(() => data.value?.site_name ?? '')

// Theme color config (theme-color-config spec §8): closed Tailwind-family enums,
// each shown with its 500-stop swatch. The default blue/slate reproduces today's look.
const ACCENT_FAMILIES: Array<{ value: string; swatch: string }> = [
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
const FONT_ITEMS = [
  { value: 'sans', label: 'Sans — Figtree throughout (default)' },
  { value: 'editorial', label: 'Editorial — serif headings, sans body' },
  { value: 'serif', label: 'Serif — serif throughout' },
]
const BACKGROUND_ITEMS = [
  { value: 'plain', label: 'Plain — white page, tinted panels (default)' },
  { value: 'tinted', label: 'Tinted — tinted page, white panels' },
]
const accentItems = ACCENT_FAMILIES.map((f) => f.value)
const neutralItems = NEUTRAL_FAMILIES.map((f) => f.value)
const accentSwatch = computed(
  () => ACCENT_FAMILIES.find((f) => f.value === form.theme_accent)?.swatch ?? '#3b82f6',
)
const neutralSwatch = computed(
  () => NEUTRAL_FAMILIES.find((f) => f.value === form.theme_neutral)?.swatch ?? '#64748b',
)

// "Preview on site" mints a preview session carrying the PENDING (unsaved) pair and
// opens the live-rendered site — token-only, never a write (spec §6). It needs an
// entry to preview through; we use the homepage entry when one is configured.
const previewingColors = ref(false)
async function previewColorsOnSite(): Promise<void> {
  // Read before the request below declares its own `data`.
  const entry = data.value?.homepage_entry ?? ''
  const locale = data.value?.default_locale ?? 'en'
  if (!entry) {
    notifyError(
      new Error('Set a homepage in Settings › General first: a preview opens through it.'),
      'Nothing to preview',
    )
    return
  }
  previewingColors.value = true
  try {
    const { data: minted, error: mintError } = await client.POST(
      '/entries/{uuid}/preview/{locale}',
      {
        params: { path: { uuid: entry, locale } },
        body: { accent: form.theme_accent, neutral: form.theme_neutral },
      },
    )
    if (mintError || !minted?.data?.theme_url) {
      throw mintError ?? new Error('Preview unavailable')
    }
    window.open(minted.data.theme_url, '_blank', 'noopener')
  } catch (e) {
    notifyError(e, 'Couldn’t open the color preview')
  } finally {
    previewingColors.value = false
  }
}

// Live theme options (theme-setting spec §4): fetched from the render pack;
// a fetch failure (pack absent, no permission) just hides the card.
const availableThemes = ref<string[]>([])
onMounted(async () => {
  try {
    // A failed fetch hides the card; so does a body without the list, which must not be assigned —
    // the template reads its length.
    const themes: unknown = (await fetchRenderThemes()).themes
    availableThemes.value = Array.isArray(themes) ? (themes as string[]) : []
  } catch {
    availableThemes.value = []
  }
})

// AssetField drives the same picker the block editor uses; single asset.
const logoField = { name: 'site_logo', label: '', type: 'asset' } as const
const logoDarkField = { name: 'site_logo_dark', label: '', type: 'asset' } as const
const faviconField = { name: 'site_favicon', label: '', type: 'asset' } as const

async function onSave() {
  try {
    await save.mutateAsync(payload())
    saved()
    success('Appearance saved', 'Changes apply on the next page view.')
  } catch (e) {
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
      <div class="mx-auto w-full max-w-4xl space-y-6 pb-5">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-28" />
          <USkeleton class="h-40" />
          <USkeleton class="h-40" />
        </div>
        <template v-else>
          <UCard v-if="availableThemes.length > 0" data-test="theme-card">
            <template #header><h2 class="font-semibold text-default">Theme</h2></template>
            <UFormField
              label="Live theme"
              description="Applies on the next page view — no restart. Preview a theme first via a preview session; duplicate one from the Theme editor."
            >
              <USelect
                v-model="form.theme"
                :items="availableThemes"
                class="w-full"
                data-test="theme-setting-select"
              />
            </UFormField>
          </UCard>

          <UCard data-test="theme-colors-card">
            <template #header>
              <h2 class="font-semibold text-default">Theme colors</h2>
            </template>
            <div class="space-y-6">
              <p class="text-sm text-muted">
                Re-skins the theme's tokens only — never changes templates. The default blue / slate
                reproduces the current look.
              </p>
              <div class="grid gap-6 sm:grid-cols-2">
                <UFormField label="Accent" description="Your brand color.">
                  <div class="flex items-center gap-2">
                    <span
                      class="inline-block size-4 rounded-full ring-1 ring-default"
                      :style="{ background: accentSwatch }"
                      data-test="theme-accent-swatch"
                    />
                    <USelect
                      v-model="form.theme_accent"
                      :items="accentItems"
                      class="w-full"
                      data-test="theme-accent"
                    />
                  </div>
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
                      class="w-full"
                      data-test="theme-neutral"
                    />
                  </div>
                </UFormField>
              </div>
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-eye"
                :loading="previewingColors"
                data-test="theme-colors-preview"
                @click="previewColorsOnSite"
              >
                Preview on site
              </UButton>
            </div>
          </UCard>

          <UCard data-test="theme-design-card">
            <template #header>
              <h2 class="font-semibold text-default">Design</h2>
            </template>
            <div class="space-y-6">
              <p class="text-sm text-muted">
                Site-wide shape, type and ground. Each choice re-maps theme tokens only; a button
                can still pick its own shape.
              </p>
              <div class="grid gap-6 sm:grid-cols-3">
                <UFormField label="Corners" description="Radius of panels and buttons.">
                  <USelect
                    v-model="form.theme_radius"
                    :items="RADIUS_ITEMS"
                    value-key="value"
                    class="w-full"
                    data-test="theme-radius"
                  />
                </UFormField>
                <UFormField label="Typefaces" description="Headings and body text.">
                  <USelect
                    v-model="form.theme_font"
                    :items="FONT_ITEMS"
                    value-key="value"
                    class="w-full"
                    data-test="theme-font"
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

          <UCard data-test="logos-card">
            <template #header>
              <h2 class="font-semibold text-default">Logos &amp; site icon</h2>
            </template>
            <div class="space-y-6">
              <!-- Logos (top): light and dark side by side -->
              <div class="grid gap-6 sm:grid-cols-2">
                <UFormField
                  label="Site logo"
                  description="Used by the Logo block (and themes). When unset, the site name renders instead."
                >
                  <div data-test="site-logo-picker">
                    <AssetField
                      v-model="form.site_logo"
                      :field="logoField"
                      :library-button="false"
                    />
                  </div>
                </UFormField>
                <UFormField
                  label="Site logo (dark)"
                  description="Shown when visitors use a dark color scheme; themes without a dark scheme ignore it. Falls back to the main logo."
                >
                  <div data-test="site-logo-dark-picker">
                    <AssetField
                      v-model="form.site_logo_dark"
                      :field="logoDarkField"
                      :library-button="false"
                    />
                  </div>
                </UFormField>
              </div>
              <!-- Site icon (below) -->
              <div class="space-y-4">
                <UFormField
                  label="Favicon"
                  description="PNG or SVG, square, ≥ 512×512 recommended."
                >
                  <div data-test="site-favicon-picker">
                    <AssetField
                      v-model="form.site_favicon"
                      :field="faviconField"
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
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
