<script setup lang="ts">
import { reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useSessionStore, type TwoFactorChallenge } from '@/stores/session'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { fetchMe } from '@/queries/account'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()
const route = useRoute()
const session = useSessionStore()
const showPassword = ref(false)

const schema = z.object({
  email: z.email('Enter a valid email.'),
  password: z.string().min(1, 'Password is required.'),
})
type Schema = z.output<typeof schema>

const state = reactive({ email: '', password: '' })
const loading = ref(false)
const { error: notifyError } = useNotify()
const loginForm = useTemplateRef<Form<Schema>>('loginForm')

// Two-factor accounts: login returns a challenge and the page asks for the emailed code.
const challenge = ref<TwoFactorChallenge | null>(null)
const codeSchema = z.object({
  code: z.string().regex(/^\d{6}$/, 'Enter the 6-digit code from your email.'),
})
type CodeSchema = z.output<typeof codeSchema>
const codeState = reactive({ code: '' })

async function goOn() {
  // Honour ?redirect= from the auth guard; then the landing page set for this user or their role
  // (Users & Access); then Home.
  if (typeof route.query.redirect === 'string') {
    await router.push(route.query.redirect)
    return
  }
  let landing: string | null = null
  try {
    landing = (await fetchMe()).ui.landing
  } catch {
    // The account could not be read: Home still works.
  }
  await router.push(landing ?? '/')
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    const pending = await session.login(event.data.email, event.data.password)
    if (pending !== null) {
      challenge.value = pending
      return
    }
    await goOn()
  } catch (e) {
    const err = toApiError(e)
    // Map any per-field validation messages onto the inputs; toast the overall reason.
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) loginForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Sign in failed')
  } finally {
    loading.value = false
  }
}

async function onCode(event: FormSubmitEvent<CodeSchema>) {
  if (challenge.value === null) return
  loading.value = true
  try {
    await session.completeTwoFactor(challenge.value.token, event.data.code)
    await goOn()
  } catch (e) {
    notifyError(toApiError(e), 'Verification failed')
  } finally {
    loading.value = false
  }
}

function startOver() {
  challenge.value = null
  codeState.code = ''
}
</script>

<template>
  <UForm
    v-if="challenge"
    :schema="codeSchema"
    :state="codeState"
    class="space-y-4"
    @submit="onCode"
  >
    <h1 class="text-lg font-semibold text-highlighted">Enter your code</h1>
    <p class="text-sm text-muted">
      We sent a 6-digit code to {{ challenge.deliveredTo }}. It expires in
      {{ Math.max(1, Math.round(challenge.expiresIn / 60)) }} minutes.
    </p>

    <UFormField label="Code" name="code">
      <UInput
        v-model="codeState.code"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="6"
        class="w-full"
        :ui="{ base: 'bg-white/35' }"
      />
    </UFormField>

    <UButton type="submit" block :loading="loading">Verify and sign in</UButton>
    <div class="text-center">
      <UButton variant="link" color="neutral" size="sm" @click="startOver">
        Use a different account, or send a new code
      </UButton>
    </div>
  </UForm>

  <UForm
    v-else
    ref="loginForm"
    :schema="schema"
    :state="state"
    class="space-y-4"
    @submit="onSubmit"
  >
    <h1 class="text-lg font-semibold text-highlighted">Sign in</h1>

    <UFormField label="Email" name="email">
      <UInput v-model="state.email" type="email" class="w-full" :ui="{ base: 'bg-white/35' }" />
    </UFormField>

    <UFormField label="Password" name="password">
      <UInput
        v-model="state.password"
        :type="showPassword ? 'text' : 'password'"
        autocomplete="current-password"
        class="w-full"
        :ui="{ base: 'bg-white/35' }"
      >
        <template #trailing>
          <UButton
            color="neutral"
            variant="link"
            size="sm"
            :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            :aria-pressed="showPassword"
            aria-controls="password"
            @click="
              () => {
                showPassword = !showPassword
              }
            "
          />
        </template>
      </UInput>
    </UFormField>

    <div class="text-right">
      <ULink to="/forgot-password" class="text-sm text-muted hover:text-default transition-colors">
        Forgot password?
      </ULink>
    </div>

    <UButton type="submit" block :loading="loading">Sign in</UButton>
  </UForm>
</template>
