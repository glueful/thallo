<script setup lang="ts">
import { reactive, watch } from 'vue'
import { useSeoMeta, useSaveSeoMeta, type SeoMeta } from '@/queries/seo'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string; locale: string; enabled: boolean }>()
const { success, error: notifyError } = useNotify()

const ROBOTS_OPTIONS = ['index', 'noindex', 'noindex,nofollow']
// reka-ui reserves the empty string for "clear selection", so "no card" uses a 'none' sentinel that
// normalizes back to null on save (an empty-string SelectItem value throws).
const TWITTER_NONE = 'none'
const TWITTER_OPTIONS = [
  { label: 'Not set', value: TWITTER_NONE },
  { label: 'Summary', value: 'summary' },
  { label: 'Summary large image', value: 'summary_large_image' },
]

// Local editable form. Empty string means "no override" for the nullable text fields; robots always
// carries one of the three valid enum strings (default 'index').
const form = reactive({
  title: '',
  description: '',
  og_title: '',
  og_description: '',
  og_image: '',
  twitter_card: TWITTER_NONE,
  robots: 'index',
})

const { data } = useSeoMeta(
  () => props.uuid,
  () => props.locale,
  () => props.enabled,
)
const save = useSaveSeoMeta(props.uuid, props.locale)

// Hydrate ONCE per mount (the parent keys us by `${uuid}-${locale}`, so a locale switch remounts and
// resets this guard). A later background refetch must not overwrite in-progress edits.
let hydrated = false
watch(
  data,
  (d) => {
    if (hydrated || !d) return
    form.title = d.title ?? ''
    form.description = d.description ?? ''
    form.og_title = d.og_title ?? ''
    form.og_description = d.og_description ?? ''
    form.og_image = d.og_image ?? ''
    form.twitter_card = d.twitter_card || TWITTER_NONE
    form.robots = d.robots ?? 'index'
    hydrated = true
  },
  { immediate: true },
)

// '' → null so a blank field clears the override rather than storing empty meta.
const nn = (v: string): string | null => (v.trim() === '' ? null : v)

async function onSave() {
  const payload: SeoMeta = {
    title: nn(form.title),
    description: nn(form.description),
    og_title: nn(form.og_title),
    og_description: nn(form.og_description),
    og_image: nn(form.og_image),
    twitter_card: form.twitter_card === TWITTER_NONE ? null : form.twitter_card,
    robots: form.robots,
  }
  try {
    await save.mutateAsync(payload)
    success('SEO saved')
  } catch (e) {
    notifyError(e, 'Couldn’t save SEO')
  }
}
</script>

<template>
  <!-- A tab SECTION, not a card: the editor sidebar's tabbed card provides the chrome
       and the SEO tab replaces the old collapsible header. -->
  <div data-test="seo-panel">
    <div class="space-y-4">
      <UFormField label="Title" :hint="`${form.title.length}/60`">
        <UInput v-model="form.title" data-test="seo-title" class="w-full" />
      </UFormField>

      <UFormField label="Description" :hint="`${form.description.length}/160`">
        <UTextarea
          v-model="form.description"
          :rows="2"
          data-test="seo-description"
          class="w-full"
        />
      </UFormField>

      <UFormField label="Robots">
        <USelect
          v-model="form.robots"
          :items="ROBOTS_OPTIONS"
          data-test="seo-robots"
          class="w-full"
        />
      </UFormField>

      <UFormField label="Twitter card">
        <USelect
          v-model="form.twitter_card"
          :items="TWITTER_OPTIONS"
          data-test="seo-twitter-card"
          class="w-full"
        />
      </UFormField>

      <UCollapsible :default-open="false">
        <UButton
          class="w-full justify-between"
          color="neutral"
          variant="ghost"
          size="sm"
          label="Open Graph"
          trailing-icon="i-lucide-chevron-down"
          :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform' }"
        />
        <template #content>
          <div class="space-y-3 pt-3">
            <UFormField label="OG title">
              <UInput v-model="form.og_title" data-test="seo-og-title" class="w-full" />
            </UFormField>
            <UFormField label="OG description">
              <UTextarea
                v-model="form.og_description"
                :rows="2"
                data-test="seo-og-description"
                class="w-full"
              />
            </UFormField>
            <UFormField label="OG image URL">
              <UInput v-model="form.og_image" type="url" data-test="seo-og-image" class="w-full" />
            </UFormField>
          </div>
        </template>
      </UCollapsible>

      <div class="flex justify-end">
        <UButton :loading="save.isLoading.value" data-test="seo-save" @click="onSave">
          Save SEO
        </UButton>
      </div>
    </div>
  </div>
</template>
