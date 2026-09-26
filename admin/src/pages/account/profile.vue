<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { updateAccount, useMe } from '@/queries/account'
import { blobDisplayUrl } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'
import { ApiError } from '@/api/errors'
import MediaPickerModal from '@/fields/components/MediaPickerModal.vue'

// Your own profile (the user menu's Profile): the name and photo you are shown with. Email and
// username are an administrator's to change, under Users, so they are shown and not edited here.
definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data: me, status, refetch } = useMe()

const form = ref({ first_name: '', last_name: '', photo_url: '' })
const loaded = ref('')
watch(
  me,
  (value) => {
    if (!value) return
    form.value = {
      first_name: value.profile.first_name ?? '',
      last_name: value.profile.last_name ?? '',
      photo_url: value.profile.photo_url ?? '',
    }
    loaded.value = JSON.stringify(form.value)
  },
  { immediate: true },
)
const dirty = computed(() => loaded.value !== '' && JSON.stringify(form.value) !== loaded.value)
const initial = computed(() =>
  (form.value.first_name || me.value?.email || 'A').charAt(0).toUpperCase(),
)

const pickerOpen = ref(false)
function onPicked(blobUuid: string): void {
  form.value = { ...form.value, photo_url: blobDisplayUrl(blobUuid) }
  pickerOpen.value = false
}

const saving = ref(false)
const errors = ref<Record<string, string>>({})
async function onSave(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    await updateAccount({ ...form.value })
    await refetch()
    success('Profile saved')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors
    else notifyError(e, 'Couldn’t save your profile')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="account-profile">
    <template #header>
      <UDashboardNavbar title="Profile">
        <template #right>
          <UChip :show="dirty" color="warning" size="sm">
            <UButton
              icon="i-lucide-save"
              :loading="saving"
              data-test="profile-save"
              @click="onSave"
            >
              Save
            </UButton>
          </UChip>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-2xl space-y-6">
        <USkeleton v-if="status === 'pending' && !me" class="h-64" />
        <template v-else>
          <UCard>
            <template #header><h2 class="font-semibold text-default">Photo</h2></template>
            <div class="flex items-center gap-4">
              <img
                v-if="form.photo_url"
                :src="form.photo_url"
                alt=""
                class="size-16 rounded-full object-cover"
                data-test="profile-photo"
              />
              <div
                v-else
                class="flex size-16 items-center justify-center rounded-full bg-elevated text-xl font-semibold text-muted"
                aria-hidden="true"
              >
                {{ initial }}
              </div>
              <div class="flex gap-2">
                <UButton
                  variant="outline"
                  color="neutral"
                  size="sm"
                  data-test="profile-photo-choose"
                  @click="pickerOpen = true"
                >
                  {{ form.photo_url ? 'Change' : 'Choose a photo' }}
                </UButton>
                <UButton
                  v-if="form.photo_url"
                  variant="ghost"
                  color="neutral"
                  size="sm"
                  data-test="profile-photo-remove"
                  @click="form = { ...form, photo_url: '' }"
                >
                  Remove
                </UButton>
              </div>
            </div>
            <p v-if="errors.photo_url" class="mt-2 text-sm text-error">{{ errors.photo_url }}</p>
          </UCard>

          <UCard>
            <template #header><h2 class="font-semibold text-default">Name</h2></template>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField label="First name">
                <UInput v-model="form.first_name" class="w-full" data-test="profile-first-name" />
              </UFormField>
              <UFormField label="Last name">
                <UInput v-model="form.last_name" class="w-full" data-test="profile-last-name" />
              </UFormField>
            </div>
          </UCard>

          <UCard>
            <template #header><h2 class="font-semibold text-default">Sign-in</h2></template>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField label="Email">
                <UInput
                  :model-value="me?.email ?? ''"
                  disabled
                  class="w-full"
                  data-test="profile-email"
                />
              </UFormField>
              <UFormField label="Username">
                <UInput
                  :model-value="me?.username ?? ''"
                  disabled
                  class="w-full"
                  data-test="profile-username"
                />
              </UFormField>
            </div>
            <p class="mt-3 text-sm text-muted">
              An administrator changes these under <span class="text-default">Users</span>. Your
              password and two-factor authentication are under
              <ULink to="/account/security" class="text-primary">Security</ULink>.
            </p>
          </UCard>
        </template>
      </div>
      <MediaPickerModal v-model:open="pickerOpen" @select="onPicked" />
    </template>
  </UDashboardPanel>
</template>
