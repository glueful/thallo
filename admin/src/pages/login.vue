<script setup lang="ts">
import { reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useSessionStore } from '@/stores/session'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

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

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await session.login(event.data.email, event.data.password)
    // Honour ?redirect= from the auth guard; default to Home.
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.push(redirect)
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
</script>

<template>
  <UForm ref="loginForm" :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
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
