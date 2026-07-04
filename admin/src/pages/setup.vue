<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { runtimeConfig } from '@/runtime/config'
import { toApiError, type ApiErrorBody } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()
const showPassword = ref(false)
const passwordFocused = ref(false)

const schema = z.object({
  site_name: z.string().min(1, 'Site name is required.'),
  admin_email: z.email('Enter a valid email.'),
  admin_password: z
    .string('Password is required')
    .min(8, 'Must be at least 8 characters')
    .regex(/\d/, 'At least 1 number')
    .regex(/[a-z]/, 'At least 1 lowercase letter')
    .regex(/[A-Z]/, 'At least 1 uppercase letter')
    .regex(/[!@#$%^&*(),.?":{}|<>]/, 'At least 1 special character')
    .regex(/^(?!.*1234).*$/, 'Cannot contain "1234"')
    .regex(/^\S*$/, 'No whitespace allowed'),
  locale: z.string().min(2),
})
type Schema = z.output<typeof schema>

// Phase 1 is en-only in the UI; locale is seeded from runtime config, not prompted.
const state = reactive({
  site_name: '',
  admin_email: '',
  admin_password: '',
  locale: runtimeConfig.defaultLocale,
})
const loading = ref(false)
const { error: notifyError } = useNotify()
const setupForm = useTemplateRef<Form<Schema>>('setupForm')

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

const strength = computed(() => checkStrength(state.admin_password.trim()))
const score = computed(() => strength.value.filter((req) => req.met).length)
// const showHint = computed(
//   () => props.showStrength && focused.value && !!props.modelValue && strength.value < 100,
// );

const color = computed(() => {
  if (score.value === 0) return 'neutral'
  if (score.value <= 2) return 'error'
  if (score.value <= 3) return 'warning'
  if (score.value === 4) return 'warning'
  return 'success'
})

const text = computed(() => {
  if (score.value === 0) return ''
  if (score.value <= 2) return 'Weak password'
  if (score.value <= 3) return 'Medium password'
  if (score.value === 4) return 'Good password'
  return 'Strong password'
})

// Show the strength popover only while the password field is focused and has input.
const passwordPopoverOpen = computed(() => passwordFocused.value && state.admin_password.length > 0)

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
    // /admin/setup is UNAUTHENTICATED and OUTSIDE the /v1/admin client surface, so use raw fetch.
    const res = await fetch('/admin/setup', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      // admin_url: the SPA's own origin — powers the preview bar's
      // Edit/Design deep links with zero configuration.
      body: JSON.stringify({ ...event.data, admin_url: window.location.origin }),
    })
    const body = (await res.json().catch(() => null)) as ApiErrorBody | null

    // Follow the standard envelope: success is a 2xx carrying { success: true }, not just any 2xx.
    if (res.status >= 200 && res.status < 300 && body?.success === true) {
      // The guard gates on `installed`; flip the stale boot-time `false` so /login isn't bounced
      // back to /setup. (`/login` is the route — vue-router adds the /admin/ base automatically.)
      runtimeConfig.installed = true
      await router.push('/login')
      return
    }

    const err = toApiError(body, res, 'Setup failed. Check your details and try again.')
    if (res.status === 409) {
      notifyError(err, 'This instance has already been set up.')
      return
    }
    // Surface per-field validation messages inline on the form (the backend field names match the
    // form field names), and toast the overall message.
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) setupForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Setup failed')
  } catch (e) {
    notifyError(e, 'Setup failed')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UForm ref="setupForm" :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
    <span class="text-sm font-semibold text-highlighted">Welcome</span>
    <p class="text-xl text-muted mb-5">Create your first admin to get started.</p>

    <UFormField label="Site name" name="site_name">
      <UInput
        v-model="state.site_name"
        class="w-full"
        :ui="{ base: 'bg-white/35' }"
        placeholder="My Site"
      />
    </UFormField>

    <UFormField label="Admin email" name="admin_email">
      <UInput
        v-model="state.admin_email"
        type="email"
        autocomplete="username"
        class="w-full"
        :ui="{ base: 'bg-white/35' }"
        placeholder="admin@example.com"
      />
    </UFormField>

    <UPopover
      :open="passwordPopoverOpen"
      :ui="{ content: 'w-(--reka-popper-anchor-width) p-3 space-y-2 z-50' }"
    >
      <div @focusin="passwordFocused = true" @focusout="onPasswordFocusOut">
        <UFormField label="Admin password" name="admin_password">
          <UInput
            id="password"
            v-model="state.admin_password"
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
                @click="() => { showPassword = !showPassword }"
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

    <UButton type="submit" size="lg" block :loading="loading">Create admin</UButton>
  </UForm>
</template>
