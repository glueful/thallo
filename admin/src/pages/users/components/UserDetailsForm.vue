<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useAssignableRoles, useUserAdminMutations, type UserRow } from '@/queries/users'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ user: UserRow }>()

const { success, error: notifyError } = useNotify()
const { update } = useUserAdminMutations()
const { data: assignableRoles } = useAssignableRoles(() => props.user.uuid)
const lockedRoleSlugs = computed(() =>
  (assignableRoles.value ?? [])
    .filter((role) => role.assigned && !role.removable)
    .map((role) => role.slug),
)
const roleOptions = computed(() =>
  (assignableRoles.value ?? []).map((role) => ({
    label: role.name,
    value: role.slug,
    disabled: role.assigned && !role.removable,
  })),
)

const schema = z.object({
  username: z.string().min(1, 'Username is required.'),
  email: z.string().email('Enter a valid email.'),
  status: z.string(),
  first_name: z.string().optional(),
  last_name: z.string().optional(),
})
type Schema = z.output<typeof schema>
const form = reactive({ username: '', email: '', status: 'active', first_name: '', last_name: '' })
const roles = ref<string[]>([])
const selectedRoles = computed<string[]>({
  get: () => roles.value,
  set: (next) => {
    roles.value = [...new Set([...next, ...lockedRoleSlugs.value])]
  },
})
const originalRoles = ref<string[]>([])
const formRef = useTemplateRef<Form<Schema>>('formRef')
const statusItems = ['active', 'inactive']

// Prefill from the selected user; re-runs whenever the master list selects a different row.
watch(
  () => props.user,
  (u) => {
    Object.assign(form, {
      username: u.username ?? '',
      email: u.email ?? '',
      status: u.status ?? 'active',
      first_name: u.profile?.first_name ?? '',
      last_name: u.profile?.last_name ?? '',
    })
    roles.value = (u.roles ?? []).map((r) => r.slug)
    originalRoles.value = [...roles.value]
  },
  { immediate: true },
)

// role_slugs is owned by aegis and untouched when omitted — only send it when the selection differs.
function rolesChanged(): boolean {
  const a = [...roles.value].sort()
  const b = [...originalRoles.value].sort()
  return a.length !== b.length || a.some((s, i) => s !== b[i])
}

async function onSubmit(e: FormSubmitEvent<Schema>) {
  try {
    await update.mutateAsync({
      uuid: props.user.uuid,
      input: {
        username: e.data.username,
        email: e.data.email,
        status: e.data.status,
        first_name: e.data.first_name ?? '',
        last_name: e.data.last_name ?? '',
        ...(rolesChanged() ? { role_slugs: roles.value } : {}),
      },
    })
    originalRoles.value = [...roles.value]
    success('User updated')
  } catch (err) {
    const apiErr = toApiError(err)
    const fieldErrors = Object.entries(apiErr.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) formRef.value?.setErrors(fieldErrors)
    notifyError(apiErr, 'Couldn’t update user')
  }
}
</script>

<template>
  <UForm
    id="user-edit"
    ref="formRef"
    :schema="schema"
    :state="form"
    class="max-w-2xl space-y-4"
    @submit="onSubmit"
  >
    <div class="flex flex-wrap gap-2">
      <UBadge
        v-if="user.email_verified_at"
        color="success"
        variant="subtle"
        icon="i-lucide-circle-check"
      >
        Email verified
      </UBadge>
      <UBadge v-else color="neutral" variant="subtle">Email not verified</UBadge>
      <UBadge :color="user.two_factor_enabled ? 'success' : 'neutral'" variant="subtle">
        2FA {{ user.two_factor_enabled ? 'on' : 'off' }}
      </UBadge>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <UFormField label="First name" name="first_name">
        <UInput v-model="form.first_name" class="w-full" />
      </UFormField>
      <UFormField label="Last name" name="last_name">
        <UInput v-model="form.last_name" class="w-full" />
      </UFormField>
    </div>
    <UFormField label="Username" name="username" required>
      <UInput v-model="form.username" class="w-full" />
    </UFormField>
    <UFormField label="Email" name="email" required>
      <UInput v-model="form.email" type="email" class="w-full" />
    </UFormField>
    <UFormField label="Status" name="status">
      <USelect v-model="form.status" :items="statusItems" class="w-full" />
    </UFormField>
    <UFormField label="Roles">
      <USelectMenu
        v-model="selectedRoles"
        :items="roleOptions"
        value-key="value"
        multiple
        placeholder="Select roles"
        class="w-full"
      />
      <div v-if="lockedRoleSlugs.length" class="mt-2 flex flex-wrap gap-1.5">
        <UBadge
          v-for="slug in lockedRoleSlugs"
          :key="slug"
          color="neutral"
          variant="subtle"
          icon="i-lucide-lock-keyhole"
          data-testid="locked-role"
        >
          {{ assignableRoles?.find((role) => role.slug === slug)?.name ?? slug }}
        </UBadge>
      </div>
    </UFormField>

    <div class="pt-2">
      <UButton type="submit" form="user-edit" :loading="update.isLoading.value">
        Save changes
      </UButton>
    </div>
  </UForm>
</template>
