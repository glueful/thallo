<script setup lang="ts">
import { computed, ref } from 'vue'
import type { TenantRole } from '@/queries/tenantMembers'

defineProps<{ busy?: boolean; error?: string | null }>()
const emit = defineEmits<{ submit: [value: { user_uuid: string; role: TenantRole }] }>()
const userUuid = ref('')
const role = ref<TenantRole>('member')
const valid = computed(() => userUuid.value.trim() !== '')
</script>

<template>
  <form
    class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end"
    @submit.prevent="valid && emit('submit', { user_uuid: userUuid.trim(), role })"
  >
    <UFormField label="User UUID" name="user_uuid" :error="error || undefined">
      <UInput v-model="userUuid" name="user_uuid" required class="w-full" />
    </UFormField>
    <UFormField label="Role">
      <RolePicker v-model="role" />
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
