<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  useGeneralSettings,
  useGeneralSettingsMutations,
  type GeneralSettings,
} from '@/queries/generalSettings'
import { useLocales } from '@/queries/locales'
import { useContentTypes } from '@/queries/contentTypes'
import ReferencePicker from '@/fields/components/ReferencePicker.vue'
import { useNotify } from '@/composables/useNotify'

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
  homepage_entry: '',
})

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
function clearHomepage(): void {
  form.homepage_entry = '' // saved as '' -> the server DELETES the override row
}

watch(
  data,
  (s) => {
    if (s) Object.assign(form, s)
  },
  { immediate: true },
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
          <UButton icon="i-lucide-save" :loading="save.isLoading.value" @click="onSave">
            Save
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-2xl space-y-6">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-40" />
          <USkeleton class="h-28" />
          <USkeleton class="h-40" />
        </div>

        <template v-else>
          <UCard>
            <template #header><h2 class="font-semibold text-default">Site identity</h2></template>
            <div class="space-y-4">
              <UFormField label="Site name" hint="Shown to admins; the instance display name.">
                <UInput v-model="form.site_name" placeholder="Lemma" class="w-full" />
              </UFormField>
              <UFormField
                label="Site preview URL"
                hint="Base URL of the live site, used for preview / “view live” links."
              >
                <UInput
                  v-model="form.site_preview_url"
                  type="url"
                  placeholder="https://example.com"
                  class="w-full"
                />
              </UFormField>
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
                <code class="text-xs">{{ form.homepage_entry }}</code>
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
            <template #header><h2 class="font-semibold text-default">Localization</h2></template>
            <UFormField
              label="Default locale"
              hint="The default content locale. Manage the enabled list under Languages."
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
                <UFormField label="Default items per page" hint="Default page size for delivery.">
                  <UInput
                    v-model.number="form.default_per_page"
                    type="number"
                    :min="1"
                    class="w-full"
                  />
                </UFormField>
                <UFormField label="Max items per page" hint="Hard cap a client can request.">
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
                hint="Cache-Control max-age for delivery responses. 0 disables caching."
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
            </div>
          </UCard>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
