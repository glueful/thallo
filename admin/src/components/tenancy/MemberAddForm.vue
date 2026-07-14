<script setup lang="ts">
import { computed, ref } from 'vue'
import type { AssignableTenantRole, TenantRole } from '@/queries/tenantMembers'

defineProps<{ busy?: boolean; error?: string | null; roles?: AssignableTenantRole[] }>()
const emit = defineEmits<{ submit: [value: { email: string; role: TenantRole }] }>()
const email = ref('')
const role = ref<TenantRole>('member')
const valid = computed(() => email.value.trim() !== '')
</script>

<template>
  <form
    class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end"
    @submit.prevent="valid && emit('submit', { email: email.trim(), role })"
  >
    <UFormField label="Email" name="email" :error="error || undefined">
      <UInput
        v-model="email"
        name="email"
        type="email"
        placeholder="person@example.com"
        autocomplete="off"
        required
        class="w-full"
      />
    </UFormField>
    <UFormField label="Role">
      <RolePicker v-model="role" :roles="roles" />
    </UFormField>
    <UButton
      type="submit"
      icon="i-lucide-user-plus"
      :loading="busy"
      :disabled="!valid"
      data-testid="member-add"
    >
      Add member
    </UButton>
  </form>
</template>
