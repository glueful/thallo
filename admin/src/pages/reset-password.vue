<script setup lang="ts">
import { computed, onMounted, reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { resetPassword } from '@/api/auth'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()
const route = useRoute()
const { success: notifySuccess, error: notifyError } = useNotify()

// The single-use reset token comes from the verify-otp step. Without it there's no reset to make.
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
onMounted(() => {
  if (token.value === '') router.replace('/forgot-password')
})

const showPassword = ref(false)
const passwordFocused = ref(false)

// Same policy the setup screen enforces, so an admin can't reset to a weaker password than they
// were required to create.
const schema = z
  .object({
    password: z
      .string('Password is required')
      .min(8, 'Must be at least 8 characters')
      .regex(/\d/, 'At least 1 number')
      .regex(/[a-z]/, 'At least 1 lowercase letter')
      .regex(/[A-Z]/, 'At least 1 uppercase letter')
      .regex(/[!@#$%^&*(),.?":{}|<>]/, 'At least 1 special character')
      .regex(/^(?!.*1234).*$/, 'Cannot contain "1234"')
      .regex(/^\S*$/, 'No whitespace allowed'),
    confirm: z.string().min(1, 'Confirm your new password.'),
  })
  .refine((data) => data.password === data.confirm, {
    message: 'Passwords do not match.',
    path: ['confirm'],
  })
type Schema = z.output<typeof schema>

const state = reactive({ password: '', confirm: '' })
const loading = ref(false)
const resetForm = useTemplateRef<Form<Schema>>('resetForm')

function checkStrength(str: string) {
  const requirements = [
    { regex: /.{8,}/, text: 'At least 8 characters' },
    { regex: /\d/, text: 'At least 1 number' },
    { regex: /[a-z]/, text: 'At least 1 lowercase letter' },
    { regex: /[A-Z]/, text: 'At least 1 uppercase letter' },
    { regex: /[!@#$%^&*(),.?":{}|<>]/, text: 'At least 1 special character' },
    { regex: /^(?!.*1234).*$/, text: 'Cannot contain "1234"' },
    { regex: /^\S*$/, text: 'No whitespace allowed' },
  ]

  return requirements.map((req) => ({ met: req.regex.test(str), text: req.text }))
}

const strength = computed(() => checkStrength(state.password.trim()))
const score = computed(() => strength.value.filter((req) => req.met).length)

const color = computed(() => {
  if (score.value === 0) return 'neutral'
  if (score.value <= 2) return 'error'
  if (score.value <= 4) return 'warning'
  return 'success'
})

const text = computed(() => {
  if (score.value === 0) return ''
  if (score.value <= 2) return 'Weak password'
  if (score.value <= 4) return 'Medium password'
  if (score.value <= 6) return 'Good password'
  return 'Strong password'
})

// Show the strength popover only while the password field is focused and has input.
const passwordPopoverOpen = computed(() => passwordFocused.value && state.password.length > 0)

// Keep the popover open while focus stays within the field (e.g. clicking the show/hide toggle);
// only close when focus leaves the field entirely.
function onPasswordFocusOut(e: FocusEvent) {
  const root = e.currentTarget as HTMLElement
  if (e.relatedTarget instanceof Node && root.contains(e.relatedTarget)) return
  passwordFocused.value = false
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await resetPassword(token.value, event.data.password)
    notifySuccess('Password reset', 'You can now sign in with your new password.')
    await router.push('/login')
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) resetForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Could not reset password')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UForm ref="resetForm" :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
    <h1 class="text-lg font-semibold text-highlighted">Reset password</h1>
    <p class="text-sm text-muted">Choose a new password for your account.</p>

    <UPopover
      :open="passwordPopoverOpen"
      :ui="{ content: 'w-(--reka-popper-anchor-width) p-3 space-y-2 z-50' }"
    >
      <div @focusin="passwordFocused = true" @focusout="onPasswordFocusOut">
        <UFormField label="New password" name="password">
          <UInput
            id="password"
            v-model="state.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            class="w-full"
            :ui="{ base: 'bg-white/35' }"
            placeholder="Use at least 8 characters"
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
      </div>

      <template #content>
        <UProgress :color="color" :indicator="text" :model-value="score" :max="7" size="sm" />

        <p id="password-strength" class="text-sm font-medium">{{ text }}. Must contain:</p>

        <ul class="space-y-1" aria-label="Password requirements">
          <li
            v-for="(req, index) in strength"
            :key="index"
            class="flex items-center gap-0.5"
            :class="req.met ? 'text-success' : 'text-muted'"
          >
            <UIcon
              :name="req.met ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
              class="size-4 shrink-0"
            />

            <span class="text-xs font-light">
              {{ req.text }}
              <span class="sr-only">
                {{ req.met ? ' - Requirement met' : ' - Requirement not met' }}
              </span>
            </span>
          </li>
        </ul>
      </template>
    </UPopover>

    <UFormField label="Confirm new password" name="confirm">
      <UInput
        v-model="state.confirm"
        :type="showPassword ? 'text' : 'password'"
        autocomplete="new-password"
        class="w-full"
        :ui="{ base: 'bg-white/35' }"
        placeholder="Re-enter your new password"
      />
    </UFormField>

    <UButton type="submit" size="lg" block :loading="loading">Reset password</UButton>

    <div class="text-center">
      <ULink to="/login" class="text-sm text-muted hover:text-default transition-colors">
        Back to sign in
      </ULink>
    </div>
  </UForm>
</template>
