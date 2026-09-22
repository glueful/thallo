<script setup lang="ts">
// Public workspace signup (self-serve checkout): a visitor from the pricing page creates a
// workspace and its owner account, confirms the emailed code, is signed in with the password they
// just chose, and lands on billing to pay for the plan they picked. A visitor already signed in
// goes straight to billing.
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  beginWorkspaceSignup,
  continueWorkspaceSignup,
  resendWorkspaceSignupCode,
  verifyWorkspaceSignup,
  type SignupOutcome,
} from '@/api/signup'
import { toApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { useTenantStore } from '@/stores/tenant'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { layout: 'auth' } })

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const tenant = useTenantStore()
const { success, error: notifyError } = useNotify()

const plan = computed(() => (typeof route.query.plan === 'string' ? route.query.plan : ''))
const destination = computed(() =>
  plan.value !== '' ? `/billing?plan=${encodeURIComponent(plan.value)}` : '/',
)

type Step = 'details' | 'code' | 'conflict' | 'existing' | 'provisioning'
const step = ref<Step>('details')
const busy = ref(false)
const fieldErrors = ref<Record<string, string>>({})

const form = reactive({
  workspace_name: '',
  slug: '',
  first_name: '',
  last_name: '',
  email: '',
  username: '',
  password: '',
})
// The address and username follow the workspace name and email until the visitor edits them.
const slugTouched = ref(false)
const usernameTouched = ref(false)
const slugify = (v: string) =>
  v
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 63)
const suggestedSlug = computed(() => slugify(form.workspace_name))
const suggestedUsername = computed(() =>
  (form.email.split('@')[0] ?? '')
    .toLowerCase()
    .replace(/[^a-z0-9_.-]/g, '')
    .slice(0, 30),
)
const slug = computed({
  get: () => (slugTouched.value ? form.slug : suggestedSlug.value),
  set: (v: string) => {
    slugTouched.value = true
    form.slug = v
  },
})
const username = computed({
  get: () => (usernameTouched.value ? form.username : suggestedUsername.value),
  set: (v: string) => {
    usernameTouched.value = true
    form.username = v
  },
})

const intentUuid = ref('')
const code = ref('')
const continuation = ref('')
const conflictField = ref<'slug' | 'username'>('slug')
const conflictValue = ref('')
const conflictMessage = ref('')

onMounted(async () => {
  if (session.isAuthenticated) await router.replace(destination.value)
})

async function run(task: () => Promise<void>): Promise<void> {
  busy.value = true
  fieldErrors.value = {}
  try {
    await task()
  } catch (e) {
    const err = toApiError(e)
    fieldErrors.value = err.fieldErrors
    notifyError(err, 'Something went wrong')
  } finally {
    busy.value = false
  }
}

function submitDetails(): Promise<void> {
  return run(async () => {
    intentUuid.value = await beginWorkspaceSignup({
      workspace_name: form.workspace_name.trim(),
      slug: slug.value,
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      email: form.email.trim(),
      username: username.value,
      password: form.password,
    })
    step.value = 'code'
  })
}

function submitCode(): Promise<void> {
  return run(async () => settle(await verifyWorkspaceSignup(intentUuid.value, code.value.trim())))
}

function submitConflict(): Promise<void> {
  return run(async () => {
    const payload: Record<string, string> =
      conflictField.value === 'slug'
        ? { slug: conflictValue.value.trim(), name: form.workspace_name.trim() }
        : { username: conflictValue.value.trim() }
    const changed = await continueWorkspaceSignup(
      intentUuid.value,
      continuation.value,
      conflictField.value === 'slug' ? 'change_slug' : 'change_username',
      payload,
    )
    continuation.value = changed.continuation_token ?? continuation.value
    await settle(await continueWorkspaceSignup(intentUuid.value, continuation.value, 'resume'))
  })
}

function retrySetup(): Promise<void> {
  return run(async () =>
    settle(await continueWorkspaceSignup(intentUuid.value, continuation.value, 'resume')),
  )
}

function resend(): Promise<void> {
  return run(async () => {
    await resendWorkspaceSignupCode(intentUuid.value)
    success('Code sent', `Check ${form.email}.`)
  })
}

async function settle(outcome: SignupOutcome): Promise<void> {
  if (outcome.status === 'active') {
    await session.login(form.email.trim(), form.password)
    tenant.select(outcome.tenant_uuid)
    await router.replace(destination.value)
    return
  }
  if (outcome.status === 'consumed') {
    step.value = 'existing'
    return
  }
  continuation.value = outcome.continuation_token
  if (outcome.status === 'conflict') {
    conflictField.value = outcome.code === 'USERNAME_CONFLICT' ? 'username' : 'slug'
    conflictValue.value = conflictField.value === 'slug' ? slug.value : username.value
    conflictMessage.value =
      conflictField.value === 'slug'
        ? 'That workspace address was taken while you signed up. Choose another.'
        : 'That username was taken while you signed up. Choose another.'
    step.value = 'conflict'
    return
  }
  step.value = 'provisioning'
}

const signInLink = computed(() => `/login?redirect=${encodeURIComponent(destination.value)}`)
</script>

<template>
  <div class="w-full max-w-md space-y-6">
    <div class="space-y-1">
      <h1 class="text-xl font-semibold text-highlighted">Create your workspace</h1>
      <p class="text-sm text-muted">
        <template v-if="plan"
          >You picked the <strong>{{ plan }}</strong> plan.
        </template>
        You'll confirm your email, then pay for your plan.
      </p>
    </div>

    <form
      v-if="step === 'details'"
      class="space-y-4"
      data-test="signup-details"
      @submit.prevent="submitDetails"
    >
      <UFormField label="Workspace name" :error="fieldErrors.name" required>
        <UInput v-model="form.workspace_name" name="workspace_name" class="w-full" required />
      </UFormField>
      <UFormField
        label="Workspace address"
        hint="Lowercase letters, numbers and hyphens"
        :error="fieldErrors.slug"
        required
      >
        <UInput v-model="slug" name="slug" class="w-full" required />
      </UFormField>
      <div class="grid grid-cols-2 gap-3">
        <UFormField label="First name" :error="fieldErrors.first_name" required>
          <UInput v-model="form.first_name" name="first_name" class="w-full" required />
        </UFormField>
        <UFormField label="Last name" :error="fieldErrors.last_name" required>
          <UInput v-model="form.last_name" name="last_name" class="w-full" required />
        </UFormField>
      </div>
      <UFormField label="Email" :error="fieldErrors.email" required>
        <UInput
          v-model="form.email"
          name="email"
          type="email"
          autocomplete="email"
          class="w-full"
          required
        />
      </UFormField>
      <UFormField label="Username" :error="fieldErrors.username" required>
        <UInput
          v-model="username"
          name="username"
          autocomplete="username"
          class="w-full"
          required
        />
      </UFormField>
      <UFormField
        label="Password"
        hint="At least 8 characters"
        :error="fieldErrors.password"
        required
      >
        <UInput
          v-model="form.password"
          name="password"
          type="password"
          autocomplete="new-password"
          class="w-full"
          required
        />
      </UFormField>
      <UButton type="submit" block :loading="busy">Create workspace</UButton>
      <p class="text-center text-sm text-muted">
        Already have an account?
        <RouterLink :to="signInLink" class="text-primary">Sign in</RouterLink>
      </p>
    </form>

    <form
      v-else-if="step === 'code'"
      class="space-y-4"
      data-test="signup-code"
      @submit.prevent="submitCode"
    >
      <p class="text-sm">
        We sent a 6-digit code to <strong>{{ form.email }}</strong
        >. Enter it to create the workspace.
      </p>
      <UFormField label="Code" :error="fieldErrors.code" required>
        <UInput
          v-model="code"
          name="code"
          inputmode="numeric"
          autocomplete="one-time-code"
          class="w-full"
          required
        />
      </UFormField>
      <UButton type="submit" block :loading="busy">Confirm</UButton>
      <UButton color="neutral" variant="link" block :disabled="busy" @click="resend">
        Send a new code
      </UButton>
    </form>

    <form
      v-else-if="step === 'conflict'"
      class="space-y-4"
      data-test="signup-conflict"
      @submit.prevent="submitConflict"
    >
      <UAlert color="warning" variant="subtle" :title="conflictMessage" />
      <UFormField
        :label="conflictField === 'slug' ? 'Workspace address' : 'Username'"
        :error="fieldErrors[conflictField]"
        required
      >
        <UInput v-model="conflictValue" name="conflict_value" class="w-full" required />
      </UFormField>
      <UButton type="submit" block :loading="busy">Continue</UButton>
    </form>

    <div v-else-if="step === 'existing'" class="space-y-4" data-test="signup-existing-account">
      <UAlert
        color="info"
        variant="subtle"
        title="This email already has an account"
        description="Sign in with it to choose a plan for your workspace."
      />
      <UButton :to="signInLink" block>Sign in</UButton>
    </div>

    <div v-else class="space-y-4" data-test="signup-provisioning">
      <UAlert
        color="warning"
        variant="subtle"
        title="Your workspace is still being set up"
        description="Try again in a moment. Nothing you entered is lost."
      />
      <UButton block :loading="busy" @click="retrySetup">Try again</UButton>
    </div>
  </div>
</template>
