<script setup lang="ts">
// The Appearance page's live preview: the site's homepage, framed, wearing the PENDING look — the
// theme, colours and design settings as they stand in the form, saved or not. Nothing is written:
// each change mints a preview session whose signed token carries the look, and the frame loads
// that session's URL. A logo or site icon is a saved setting the token does not carry, so those
// show once saved.
import { computed, ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { client } from '@/api/client'

export interface PendingLook {
  /**
   * Another theme than the live one, or '' for the live one. Naming a theme makes the preview a
   * THEMED session, which serves that theme's assets from its own token-scoped URLs: needed to
   * preview a different theme, and needless for the one the site already serves.
   */
  theme: string
  accent: string
  neutral: string
  radius: string
  font: string
  background: string
  /** The site's own faces, pending (Custom pairing only): a media uuid, or `none`. */
  font_body?: string
  font_display?: string
}

const props = defineProps<{
  /** The homepage entry the preview opens through; '' when the site has none. */
  entry: string
  locale: string
  look: PendingLook
}>()

const VIEWPORT_WIDTHS = { desktop: '100%', tablet: '768px', mobile: '390px' } as const
type Viewport = keyof typeof VIEWPORT_WIDTHS
const viewport = ref<Viewport>('desktop')
const VIEWPORTS: { value: Viewport; icon: string; label: string }[] = [
  { value: 'desktop', icon: 'i-lucide-monitor', label: 'Desktop viewport' },
  { value: 'tablet', icon: 'i-lucide-tablet', label: 'Tablet viewport' },
  { value: 'mobile', icon: 'i-lucide-smartphone', label: 'Mobile viewport' },
]

const url = ref('') // the last GOOD session: a failed mint never blanks the frame
const stale = ref(false)
const loading = ref(false)
let requested = 0

function previewBody(): Record<string, string> {
  const { theme, ...look } = props.look
  return theme === '' ? look : { theme, ...look }
}

async function mint(): Promise<void> {
  if (props.entry === '') return
  const mine = ++requested
  loading.value = true
  try {
    const { data, error } = await client.POST('/entries/{uuid}/preview/{locale}', {
      params: { path: { uuid: props.entry, locale: props.locale } },
      // The design fields are newer than the generated request type.
      body: previewBody() as never,
    })
    if (mine !== requested) return // a later choice has already asked
    const minted = data?.data?.theme_url
    if (error || !minted) throw error ?? new Error('Preview unavailable')
    url.value = minted
    stale.value = false
  } catch {
    if (mine === requested) stale.value = true
  } finally {
    if (mine === requested) loading.value = false
  }
}

// Choosing is quick and a render is not: ask once the choosing has settled.
const mintSoon = useDebounceFn(() => void mint(), 600)
const fingerprint = computed(() => JSON.stringify([props.entry, props.locale, props.look]))
watch(fingerprint, () => void mintSoon(), { immediate: true })

function openInTab(): void {
  if (url.value) window.open(url.value, '_blank', 'noopener')
}
</script>

<template>
  <UCard data-test="appearance-preview" :ui="{ body: 'p-3 sm:p-3' }">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold text-default">Preview</h2>
          <p class="text-xs text-muted">
            Your homepage with these settings. Nothing is saved until you press Save.
          </p>
        </div>
        <div v-if="entry !== ''" class="flex items-center gap-2">
          <UBadge
            v-if="stale"
            color="warning"
            variant="subtle"
            data-test="appearance-preview-stale"
          >
            Preview not updated
          </UBadge>
          <UFieldGroup>
            <UButton
              v-for="v in VIEWPORTS"
              :key="v.value"
              size="xs"
              variant="outline"
              color="neutral"
              :icon="v.icon"
              :aria-label="v.label"
              :class="{ 'bg-elevated': viewport === v.value }"
              :data-test="`appearance-preview-${v.value}`"
              @click="viewport = v.value"
            />
          </UFieldGroup>
          <UButton
            size="xs"
            variant="subtle"
            color="neutral"
            icon="i-lucide-external-link"
            :disabled="url === ''"
            data-test="appearance-preview-open"
            @click="openInTab"
          >
            Open
          </UButton>
        </div>
      </div>
    </template>

    <p
      v-if="entry === ''"
      class="py-12 text-center text-sm text-muted"
      data-test="appearance-preview-empty"
    >
      A preview opens through the site’s homepage, and none is set. Choose one in
      <RouterLink to="/settings/general" class="font-medium text-primary underline">
        Settings › General</RouterLink
      >.
    </p>
    <div v-else class="overflow-auto rounded bg-elevated/40 p-2">
      <div class="mx-auto transition-[width]" :style="{ width: VIEWPORT_WIDTHS[viewport] }">
        <iframe
          v-if="url"
          :src="url"
          title="Appearance preview"
          class="h-[70vh] w-full rounded border border-default bg-white"
          :class="{ 'opacity-60': loading }"
          data-test="appearance-preview-frame"
        />
        <p v-else class="py-16 text-center text-sm text-muted">
          {{ stale ? 'The preview could not be loaded.' : 'Starting preview…' }}
        </p>
      </div>
    </div>
    <p class="mt-2 text-[11px] text-muted">
      Logos and the site icon show here once saved: a preview carries the look, not uploads.
    </p>
  </UCard>
</template>
