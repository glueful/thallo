<script setup lang="ts">
import { reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useAssignableRoles, useUserAdminMutations } from '@/queries/users'
import { generatePassword } from '@/lib/password'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ created: [uuid: string] }>()

const { success, error: notifyError } = useNotify()
const { create } = useUserAdminMutations()
const { data: assignableRoles } = useAssignableRoles()
const roleOptions = () =>
  (assignableRoles.value ?? []).map((role) => ({ label: role.name, value: role.slug }))

const schema = z.object({
  username: z.string().min(1, 'Username is required.'),
  email: z.string().email('Enter a valid email.'),
  password: z.string().min(8, 'At least 8 characters.'),
  first_name: z.string().optional(),
  last_name: z.string().optional(),
})
type Schema = z.output<typeof schema>
const form = reactive({ username: '', email: '', password: '', first_name: '', last_name: '' })
const roles = ref<string[]>([])
const reveal = ref(false)
const formRef = useTemplateRef<Form<Schema>>('formRef')

function reset() {
  Object.assign(form, { username: '', email: '', password: '', first_name: '', last_name: '' })
  roles.value = []
  reveal.value = false
}
// Clear the form whenever the modal closes (cancel, ESC, backdrop, or submit) so a reopen
// starts blank instead of showing stale input.
watch(open, (isOpen) => {
  if (!isOpen) reset()
})
function regenerate() {
  form.password = generatePassword()
  reveal.value = true
}

async function onSubmit(e: FormSubmitEvent<Schema>) {
  try {
    const res = await create.mutateAsync({
      username: e.data.username,
      email: e.data.email,
      password: e.data.password,
      first_name: e.data.first_name || undefined,
      last_name: e.data.last_name || undefined,
      role_slugs: roles.value,
    })
    const uuid = (res as { data?: { uuid?: string } })?.data?.uuid ?? ''
    success('User created', `“${e.data.username}” can now sign in.`)
    open.value = false
    reset()
    if (uuid) emit('created', uuid)
  } catch (err) {
    const apiErr = toApiError(err)
    const fieldErrors = Object.entries(apiErr.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) formRef.value?.setErrors(fieldErrors)
    notifyError(apiErr, 'Couldn’t create user')
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Create New User">
    <template #body>
      <UForm
        id="user-create"
        ref="formRef"
        :schema="schema"
        :state="form"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField label="Username" name="username" required>
          <UInput v-model="form.username" placeholder="jdoe" class="w-full" />
        </UFormField>
        <UFormField label="Email" name="email" required>
          <UInput v-model="form.email" type="email" placeholder="user@example.com" class="w-full" />
        </UFormField>
        <UFormField label="Password" name="password" required>
          <UInput
            v-model="form.password"
            :type="reveal ? 'text' : 'password'"
            placeholder="Password"
            class="w-full"
          >
            <template #trailing>
              <UButton
                :icon="reveal ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                color="neutral"
                variant="link"
                size="xs"
                aria-label="Toggle password visibility"
                @click="
                  () => {
                    reveal = !reveal
                  }
                "
              />
              <UButton
                icon="i-lucide-refresh-cw"
                color="neutral"
                variant="link"
                size="xs"
                aria-label="Generate password"
                @click="regenerate"
              />
            </template>
          </UInput>
        </UFormField>
        <div class="grid grid-cols-2 gap-3">
          <UFormField label="First name" name="first_name">
            <UInput v-model="form.first_name" class="w-full" />
          </UFormField>
          <UFormField label="Last name" name="last_name">
            <UInput v-model="form.last_name" class="w-full" />
          </UFormField>
        </div>
        <UFormField label="Roles">
          <USelectMenu
            v-model="roles"
            :items="roleOptions()"
            value-key="value"
            multiple
            placeholder="Add roles…"
            class="w-full"
          />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          @click="
            () => {
              open = false
            }
          "
          >Cancel</UButton
        >
        <UButton type="submit" form="user-create" :loading="create.isLoading.value">
          Create User
        </UButton>
      </div>
    </template>
  </UModal>
</template>
