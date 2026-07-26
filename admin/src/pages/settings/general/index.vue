<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import {
  useGeneralSettings,
  useGeneralSettingsMutations,
  type GeneralSettings,
} from '@/queries/generalSettings'
import { useLocales } from '@/queries/locales'
import { useContentTypes } from '@/queries/contentTypes'
import AssetField from '@/fields/components/AssetField.vue'
import ReferencePicker from '@/fields/components/ReferencePicker.vue'
import FaviconPreview from './components/FaviconPreview.vue'
import { blobDisplayUrl } from '@/queries/media'
import { fetchRenderThemes } from '@/queries/templates'
import { useNotify } from '@/composables/useNotify'
import { client } from '@/api/client'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useGeneralSettings()
const { save } = useGeneralSettingsMutations()
const { data: locales } = useLocales()

const form = reactive<GeneralSettings>({
  site_name: '',
  site_preview_url: '',
  default_locale: 'en',
  default_per_page: 20,
  max_per_page: 100,
  cache_ttl: 60,
  scheduler_enabled: true,
  webhooks_enabled: true,
  search_enabled: false,
  homepage_entry: '',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
  theme: '',
  theme_accent: 'blue',
  theme_neutral: 'slate',
  admin_url: '',
  listing_types: [],
})

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
  const entry = form.homepage_entry
  if (!entry) {
    notifyError(
      new Error('Set a homepage entry first to preview colors on the live site.'),
      'Nothing to preview',
    )
    return
  }
  previewingColors.value = true
  try {
    const { data, error: mintError } = await client.POST(
      '/entries/{uuid}/preview/{locale}',
      {
        params: { path: { uuid: entry, locale: form.default_locale } },
        body: { accent: form.theme_accent, neutral: form.theme_neutral },
      },
    )
    if (mintError || !data?.data?.theme_url) {
      throw mintError ?? new Error('Preview unavailable')
    }
    window.open(data.data.theme_url, '_blank', 'noopener')
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
    availableThemes.value = (await fetchRenderThemes()).themes
  } catch {
    availableThemes.value = []
  }
})

// AssetField drives the same picker the block editor uses; single asset.
const logoField = { name: 'site_logo', label: '', type: 'asset' } as const
const logoDarkField = { name: 'site_logo_dark', label: '', type: 'asset' } as const
const faviconField = { name: 'site_favicon', label: '', type: 'asset' } as const

// Homepage picker (homepage-setting spec §1): the entries query is
// type-scoped, so picking = choose a type, then search its entries.
const { data: contentTypes } = useContentTypes()
const homepageType = ref('')
const homepageTypeOptions = computed(() =>
  // Non-public types stay VISIBLE but disabled with the reason — the server
  // 422s them at write time; an empty dropdown would just look broken.
  (contentTypes.value ?? []).map((t) => ({
    label: (t.name ?? t.slug ?? '') + (t.public_delivery ? '' : ' — not publicly delivered'),
    value: t.slug ?? '',
    disabled: !t.public_delivery,
  })),
)
// Listing-types multi-select: every type is selectable (a listed non-public
// type is dormant until its flag flips — the server only rejects unknown
// slugs); non-public ones carry the reason as a hint.
const listingTypeOptions = computed(() =>
  (contentTypes.value ?? []).map((t) => ({
    label: (t.name ?? t.slug ?? '') + (t.public_delivery ? '' : ' — not publicly delivered'),
    value: t.slug ?? '',
  })),
)

// Resolve the stored homepage uuid into HUMAN context (title + type) — the
// uuid alone is not a UI. Also pre-selects the type so the entry picker is
// immediately usable; a manual type choice is never overridden.
const homepageEntry = ref<{ title: string; type: string | null } | null>(null)
watch(
  () => form.homepage_entry,
  async (uuid) => {
    homepageEntry.value = null
    if (!uuid) return
    try {
      const { data } = await client.GET('/entries/{uuid}', { params: { path: { uuid } } })
      const entry = data?.data?.entry as
        | { display_title?: string; content_type?: string | null }
        | undefined
      if (!entry) return
      homepageEntry.value = { title: entry.display_title ?? uuid, type: entry.content_type ?? null }
      if (!homepageType.value && entry.content_type) homepageType.value = entry.content_type
    } catch {
      // Resolution is cosmetic — the uuid still renders as the fallback.
    }
  },
  { immediate: true },
)

function clearHomepage(): void {
  form.homepage_entry = '' // saved as '' -> the server DELETES the override row
}

// Server → form sync, but NEVER over unsaved edits: the query refetches on
// window refocus once stale, and an unconditional Object.assign would wipe
// in-progress changes (e.g. a freshly uploaded logo uuid) before Save.
const dirty = ref(false)
let syncing = false
watch(
  data,
  (s) => {
    if (s && !dirty.value) {
      syncing = true
      Object.assign(form, s)
      void nextTick(() => {
        syncing = false
      })
    }
  },
  { immediate: true },
)
watch(
  form,
  () => {
    if (!syncing) dirty.value = true
  },
  { deep: true },
)

// Enabled locales for the default-locale select; keep the current value selectable even if disabled.
const localeOptions = computed(() => {
  const items = (locales.value ?? [])
    .filter((l) => l.enabled)
    .map((l) => ({ label: `${l.name} (${l.code})`, value: l.code }))
  if (form.default_locale && !items.some((i) => i.value === form.default_locale)) {
    items.unshift({ label: form.default_locale, value: form.default_locale })
  }
  return items
})

async function onSave() {
  try {
    await save.mutateAsync({ ...form })
    // Saved: the form matches the server again, so the post-save refetch may sync.
    dirty.value = false
    success('General settings saved', 'Changes apply on the next request.')
  } catch (e) {
    notifyError(e, 'Couldn’t save general settings')
  }
}
</script>

<template>
  <UDashboardPanel id="settings-general">
    <template #header>
      <UDashboardNavbar title="General">
        <template #right>
          <UChip :show="dirty" color="warning" size="sm">
            <UButton icon="i-lucide-save" :loading="save.isLoading.value" @click="onSave">
              Save
            </UButton>
          </UChip>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-6xl space-y-6">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-40" />
          <USkeleton class="h-28" />
          <USkeleton class="h-40" />
        </div>

        <template v-else>
          <!-- Two-column split (same shape as the content-type editor): Site
               identity in the LEFT rail; logos/site icon, homepage + operational
               settings own the wide RIGHT column. -->
          <div class="grid gap-6 lg:grid-cols-3 pb-5">
            <!-- Sticky left rail: Site identity stays in view while the tall
                 right column scrolls (its top drifting from the right cards
                 during scroll is the pinning, not a layout bug). -->
            <div class="space-y-6 lg:sticky lg:top-0 lg:self-start">
              <UCard>
                <template #header><h2 class="font-semibold text-default">Site identity</h2></template>
                <div class="space-y-4">
                  <UFormField label="Site name" description="Shown to admins; the instance display name.">
                    <UInput v-model="form.site_name" placeholder="Thallo" class="w-full" />
                  </UFormField>
                  <UFormField
                    label="Site preview URL"
                    description="Base URL of the live site, used for preview / “view live” links."
                  >
                    <UInput
                      v-model="form.site_preview_url"
                      type="url"
                      placeholder="https://example.com"
                      class="w-full"
                    />
                  </UFormField>
                  <UFormField
                    label="Admin URL"
                    description="This admin's base URL — powers the live preview bar's Edit/Design links."
                  >
                    <UInput
                      v-model="form.admin_url"
                      type="url"
                      placeholder="https://admin.example.com"
                      class="w-full"
                      data-test="admin-url-input"
                    />
                  </UFormField>
                </div>
              </UCard>

            </div>

            <div class="space-y-6 lg:col-span-2">
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
                    Re-skins the theme's tokens only — never changes templates. The
                    default blue / slate reproduces the current look.
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

              <UCard>
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
                      :site-name="form.site_name"
                    />
                  </div>
                </div>
              </UCard>

              <UCard>
                <template #header><h2 class="font-semibold text-default">Homepage</h2></template>
                <div class="space-y-4">
                  <p class="text-sm text-muted">
                    The entry rendered at <code>/</code>. Cleared = the deploy default
                    (<code>RENDER_HOMEPAGE_ENTRY</code>), or the standalone index when
                    that is empty too. Must be a published entry of a publicly
                    delivered type.
                  </p>
                  <div v-if="form.homepage_entry" class="flex items-center gap-2" data-test="homepage-current">
                    <UBadge color="primary" variant="subtle" icon="i-lucide-house">Home</UBadge>
                    <span class="text-sm font-medium text-default" :title="form.homepage_entry">
                      {{ homepageEntry?.title ?? form.homepage_entry }}
                    </span>
                    <UBadge v-if="homepageEntry?.type" size="sm" color="neutral" variant="outline">
                      {{ homepageEntry.type }}
                    </UBadge>
                    <UButton
                      size="xs"
                      variant="ghost"
                      color="neutral"
                      icon="i-lucide-x"
                      aria-label="Clear homepage"
                      data-test="homepage-clear"
                      @click="clearHomepage()"
                    />
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <UFormField label="Content type">
                      <USelect
                        v-model="homepageType"
                        :items="homepageTypeOptions"
                        placeholder="Pick a type…"
                        class="w-full"
                        data-test="homepage-type"
                      />
                    </UFormField>
                    <UFormField label="Entry">
                      <ReferencePicker
                        v-if="homepageType"
                        v-model="form.homepage_entry"
                        :target="homepageType"
                      />
                      <p v-else class="pt-1.5 text-sm text-muted">Pick a type first.</p>
                    </UFormField>
                  </div>
                </div>
              </UCard>

              <UCard>
                <template #header><h2 class="font-semibold text-default">Public listings</h2></template>
                <div class="space-y-4">
                  <p class="text-sm text-muted">
                    Content types that expose index pages (<code>/post</code>) and
                    taxonomy archives (<code>/post/categories/news</code>) on the
                    live site. Types not listed here only serve their entry pages.
                  </p>
                  <UFormField label="Listing types">
                    <USelectMenu
                      v-model="form.listing_types"
                      :items="listingTypeOptions"
                      value-key="value"
                      multiple
                      placeholder="No listings"
                      class="w-full"
                      data-test="listing-types-select"
                    />
                  </UFormField>
                </div>
              </UCard>

              <UCard>
                <template #header><h2 class="font-semibold text-default">Localization</h2></template>
                <UFormField
                  label="Default locale"
                  description="The default content locale. Manage the enabled list under Languages."
                >
                  <USelect v-model="form.default_locale" :items="localeOptions" class="w-full" />
                </UFormField>
              </UCard>

              <UCard>
                <template #header
                  ><h2 class="font-semibold text-default">Content delivery</h2></template
                >
                <div class="space-y-4">
                  <div class="grid gap-4 sm:grid-cols-2">
                    <UFormField label="Default items per page" description="Default page size for delivery.">
                      <UInput
                        v-model.number="form.default_per_page"
                        type="number"
                        :min="1"
                        class="w-full"
                      />
                    </UFormField>
                    <UFormField label="Max items per page" description="Hard cap a client can request.">
                      <UInput
                        v-model.number="form.max_per_page"
                        type="number"
                        :min="1"
                        class="w-full"
                      />
                    </UFormField>
                  </div>
                  <UFormField
                    label="Cache TTL (seconds)"
                    description="Cache-Control max-age for delivery responses. 0 disables caching."
                  >
                    <UInput v-model.number="form.cache_ttl" type="number" :min="0" class="w-full" />
                  </UFormField>
                </div>
              </UCard>

              <UCard>
                <template #header><h2 class="font-semibold text-default">Feature toggles</h2></template>
                <div class="space-y-4">
                  <USwitch
                    v-model="form.scheduler_enabled"
                    label="Publish scheduler"
                    description="Run scheduled publish/unpublish jobs."
                  />
                  <USwitch
                    v-model="form.webhooks_enabled"
                    label="Content webhooks"
                    description="Dispatch content events to webhook subscriptions (master switch)."
                  />
                  <USwitch
                    v-model="form.search_enabled"
                    data-test="search-enabled"
                    label="Content search"
                    description="Public search API (/v1/search) and content reindexing. Needs the
                      Meilisearch extension with a reachable server; after enabling, run the
                      thallo:search:reindex command to index existing content."
                  />
                </div>
              </UCard>
            </div>
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
