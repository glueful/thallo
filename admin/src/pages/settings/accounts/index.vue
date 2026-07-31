<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import {
  fetchAccountSettings,
  saveAccountRedirects,
  isSafeReturnPath,
  type AccountSettings,
} from '@/queries/accountSettings'
import PathCombobox from './components/PathCombobox.vue'

// Verified-capability guard: direct navigation to this page requires thallo.accounts to be
// ENABLED (not merely visible in the sidebar). The router guard reads isEnabled().
definePage({ meta: { requiresAuth: true, requiresCapability: 'thallo.accounts' } })

const settings = ref<AccountSettings | null>(null)
const afterLogin = ref('')
const afterLogout = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const saved = ref(false)

const loginInvalid = computed(() => !isSafeReturnPath(afterLogin.value))
const logoutInvalid = computed(() => !isSafeReturnPath(afterLogout.value))
const canSave = computed(() => !loading.value && !saving.value && !loginInvalid.value && !logoutInvalid.value)

async function load(): Promise<void> {
  loading.value = true
  try {
    const value = await fetchAccountSettings()
    settings.value = value
    afterLogin.value = value.after_login ?? ''
    afterLogout.value = value.after_logout ?? ''
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to load account settings.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  // Client mirror of the server validator; the server remains the authority (it re-validates).
  if (loginInvalid.value || logoutInvalid.value) return
  saving.value = true
  error.value = null
  saved.value = false
  try {
    const value = await saveAccountRedirects(
      afterLogin.value.trim() === '' ? null : afterLogin.value.trim(),
      afterLogout.value.trim() === '' ? null : afterLogout.value.trim(),
    )
    settings.value = value
    afterLogin.value = value.after_login ?? ''
    afterLogout.value = value.after_logout ?? ''
    saved.value = true
  } catch (caught) {
    error.value =
      caught instanceof ApiError || caught instanceof Error ? caught.message : 'Unable to save.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <UDashboardPanel id="settings-accounts">
    <template #header>
      <UDashboardNavbar title="Accounts" />
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
        <p class="text-sm text-muted">
          The account pages, and where visitors land after signing in or out. Redirects must be
          site-relative paths (a single leading <code>/</code>); leave one blank to use the default.
        </p>

        <div v-if="loading" class="flex flex-col gap-3">
          <USkeleton class="h-4 w-64" />
          <USkeleton class="h-9 w-full" />
          <USkeleton class="h-9 w-full" />
        </div>

        <template v-else>
          <UAlert
            v-if="error"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="error"
            data-testid="account-settings-error"
          />

          <!-- Read-only inventory of the themed account pages. -->
          <section class="rounded-lg border border-default" data-testid="account-pages">
            <div class="border-b border-default px-5 py-3">
              <h2 class="text-sm font-semibold text-highlighted">Account pages</h2>
            </div>
            <ul class="divide-y divide-default">
              <li
                v-for="page in settings?.pages ?? []"
                :key="page.path"
                class="flex items-center justify-between gap-3 px-5 py-3"
              >
                <span class="text-sm text-highlighted">{{ page.label }}</span>
                <a
                  :href="page.path"
                  target="_blank"
                  rel="noopener"
                  class="text-sm text-primary hover:underline"
                  data-testid="account-page-link"
                  >{{ page.path }}</a
                >
              </li>
            </ul>
          </section>

          <!-- Redirect overrides. -->
          <section class="flex flex-col gap-4 rounded-lg border border-default px-5 py-4">
            <h2 class="text-sm font-semibold text-highlighted">Redirects</h2>

            <UFormField
              label="After sign in"
              help="Where a visitor lands after signing in. Blank uses /account."
              :error="loginInvalid ? 'Enter a site-relative path beginning with a single /.' : undefined"
            >
              <PathCombobox
                v-model="afterLogin"
                :suggestions="settings?.suggestions.after_login ?? []"
                placeholder="/account"
                testid="after-login-input"
              />
            </UFormField>

            <UFormField
              label="After sign out"
              help="Where a visitor lands after signing out. Blank uses /account/login."
              :error="logoutInvalid ? 'Enter a site-relative path beginning with a single /.' : undefined"
            >
              <PathCombobox
                v-model="afterLogout"
                :suggestions="settings?.suggestions.after_logout ?? []"
                placeholder="/account/login"
                testid="after-logout-input"
              />
            </UFormField>

            <div class="flex items-center gap-3">
              <UButton
                :loading="saving"
                :disabled="!canSave"
                icon="i-lucide-save"
                data-testid="save-account-redirects"
                @click="save"
                >Save</UButton
              >
              <span v-if="saved" class="text-sm text-success" data-testid="account-settings-saved"
                >Saved.</span
              >
            </div>
          </section>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
