<script setup lang="ts">
import { ref } from 'vue'
import {
  beginTwoFactor,
  changePassword,
  confirmTwoFactor,
  disableTwoFactor,
  useMe,
} from '@/queries/account'
import { useNotify } from '@/composables/useNotify'
import { ApiError } from '@/api/errors'

// Your own sign-in security (the user menu's Security): your password, and email two-factor
// authentication. A new password signs out every other session of the account; this one stays.
definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data: me, status, refetch } = useMe()

// ── Password ──────────────────────────────────────────────────────────────────
const MIN_PASSWORD = 8
const password = ref({ current: '', next: '', confirm: '' })
const passwordErrors = ref<Record<string, string>>({})
const changing = ref(false)
async function onChangePassword(): Promise<void> {
  passwordErrors.value = {}
  if (password.value.next.length < MIN_PASSWORD) {
    passwordErrors.value = { password: `Use at least ${MIN_PASSWORD} characters.` }
    return
  }
  if (password.value.next !== password.value.confirm) {
    passwordErrors.value = { confirm: 'The new passwords do not match.' }
    return
  }
  changing.value = true
  try {
    const result = await changePassword({
      current_password: password.value.current,
      password: password.value.next,
    })
    password.value = { current: '', next: '', confirm: '' }
    const others = result.other_sessions_signed_out
    success(
      'Password changed',
      others > 0
        ? `${others} other ${others === 1 ? 'session was' : 'sessions were'} signed out.`
        : 'You stay signed in here.',
    )
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) passwordErrors.value = e.fieldErrors
    else notifyError(e, 'Couldn’t change your password')
  } finally {
    changing.value = false
  }
}

// ── Two-factor authentication ─────────────────────────────────────────────────
const challenge = ref<string | null>(null)
const code = ref('')
const twoFactorBusy = ref(false)
const reelevate = ref(false)
async function onEnable(): Promise<void> {
  twoFactorBusy.value = true
  reelevate.value = false
  try {
    challenge.value = (await beginTwoFactor()).challenge_token
    code.value = ''
  } catch (e) {
    notifyError(e, 'Couldn’t send a code')
  } finally {
    twoFactorBusy.value = false
  }
}
async function onVerify(): Promise<void> {
  if (challenge.value === null) return
  twoFactorBusy.value = true
  try {
    await confirmTwoFactor(challenge.value, code.value.trim())
    challenge.value = null
    await refetch()
    success('Two-factor authentication is on', 'Signing in now asks for a code sent to your email.')
  } catch (e) {
    notifyError(e, 'That code didn’t work')
  } finally {
    twoFactorBusy.value = false
  }
}
async function onDisable(): Promise<void> {
  twoFactorBusy.value = true
  reelevate.value = false
  try {
    await disableTwoFactor()
    await refetch()
    success('Two-factor authentication is off')
  } catch (e) {
    // The API only lets a session that signed in with a code in the last few minutes turn it off.
    if (e instanceof ApiError && e.status === 403) reelevate.value = true
    else notifyError(e, 'Couldn’t turn two-factor authentication off')
  } finally {
    twoFactorBusy.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="account-security">
    <template #header>
      <UDashboardNavbar title="Security" />
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-2xl space-y-6">
        <UCard>
          <template #header><h2 class="font-semibold text-default">Password</h2></template>
          <form class="space-y-4" data-test="password-form" @submit.prevent="onChangePassword">
            <UFormField label="Current password" :error="passwordErrors.current_password">
              <UInput
                v-model="password.current"
                type="password"
                autocomplete="current-password"
                class="w-full"
                data-test="password-current"
              />
            </UFormField>
            <UFormField
              label="New password"
              :description="`At least ${MIN_PASSWORD} characters.`"
              :error="passwordErrors.password"
            >
              <UInput
                v-model="password.next"
                type="password"
                autocomplete="new-password"
                class="w-full"
                data-test="password-new"
              />
            </UFormField>
            <UFormField label="Confirm new password" :error="passwordErrors.confirm">
              <UInput
                v-model="password.confirm"
                type="password"
                autocomplete="new-password"
                class="w-full"
                data-test="password-confirm"
              />
            </UFormField>
            <div class="flex items-center justify-between gap-3">
              <p class="text-sm text-muted">Your other sessions are signed out; this one stays.</p>
              <UButton type="submit" :loading="changing" data-test="password-save">
                Change password
              </UButton>
            </div>
          </form>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex items-center justify-between gap-2">
              <h2 class="font-semibold text-default">Two-factor authentication</h2>
              <UBadge
                :color="me?.two_factor_enabled ? 'success' : 'neutral'"
                variant="subtle"
                data-test="twofactor-status"
              >
                {{ me?.two_factor_enabled ? 'On' : 'Off' }}
              </UBadge>
            </div>
          </template>
          <USkeleton v-if="status === 'pending' && !me" class="h-16" />
          <UAlert
            v-else-if="me && !me.two_factor_available"
            color="neutral"
            variant="subtle"
            icon="i-lucide-info"
            title="Not switched on for this site"
            description="Email two-factor authentication is off for this install. A site operator turns it on with TWO_FACTOR_ENABLED=true in the server's .env; then you can turn it on for your account here."
            data-test="twofactor-unavailable"
          />
          <div v-else class="space-y-4">
            <p class="text-sm text-muted">
              When it is on, signing in asks for a code sent to
              <span class="text-default">{{ me?.email }}</span> as well as your password.
            </p>

            <template v-if="!me?.two_factor_enabled">
              <form
                v-if="challenge !== null"
                class="flex items-end gap-2"
                @submit.prevent="onVerify"
              >
                <UFormField label="Code from your email" class="flex-1">
                  <UInput
                    v-model="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="w-full"
                    data-test="twofactor-code"
                  />
                </UFormField>
                <UButton type="submit" :loading="twoFactorBusy" data-test="twofactor-verify">
                  Turn on
                </UButton>
                <UButton
                  variant="ghost"
                  color="neutral"
                  :disabled="twoFactorBusy"
                  @click="onEnable"
                >
                  Send again
                </UButton>
              </form>
              <UButton
                v-else
                :loading="twoFactorBusy"
                data-test="twofactor-enable"
                @click="onEnable"
              >
                Turn on
              </UButton>
            </template>

            <template v-else>
              <UButton
                variant="outline"
                color="error"
                :loading="twoFactorBusy"
                data-test="twofactor-disable"
                @click="onDisable"
              >
                Turn off
              </UButton>
              <UAlert
                v-if="reelevate"
                color="warning"
                variant="subtle"
                icon="i-lucide-shield-alert"
                title="Confirm it’s you first"
                description="Turning two-factor off needs a sign-in with a code from the last five minutes. Log out, sign in again with your code, then turn it off here."
                data-test="twofactor-reelevate"
              />
            </template>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
