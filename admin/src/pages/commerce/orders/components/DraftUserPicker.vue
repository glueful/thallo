<script setup lang="ts">
// Task 14 (admin-order-creation): the "attach an existing account" picker inside the draft
// customer card — mounted ONLY when `can_attach_user` is true (the parent gates it with `v-if`,
// never a prop-driven internal branch), so `useUsers()` — and therefore any `GET /v1/users`
// request — is genuinely never invoked while the capability is off. Vue never runs a `v-if=false`
// component's `setup()`, so this is a real zero-requests guarantee, not just a hidden UI.
import { ref, computed } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useUsers, userDisplayName, type UserRow } from '@/queries/users'

defineProps<{
  /** The currently attached user's uuid, or null — display-only here (the parent owns the actual
   * form field/save call); this component only needs it to highlight the current selection. */
  modelValue: string | null
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

const search = ref('')
const debouncedSearch = refDebounced(search, 300)

const { data, status } = useUsers(1, 10, debouncedSearch)
const rows = computed<UserRow[]>(() => data.value?.users ?? [])

function select(user: UserRow) {
  emit('update:modelValue', user.uuid)
}
function clear() {
  emit('update:modelValue', null)
}

defineExpose({ userDisplayName })
</script>

<template>
  <div class="flex flex-col gap-2" data-test="draft-user-picker">
    <UInput
      v-model="search"
      placeholder="Search users by name, username, or email…"
      icon="i-lucide-search"
      data-test="draft-user-search"
    />

    <UButton
      v-if="modelValue"
      size="xs"
      color="neutral"
      variant="ghost"
      data-test="draft-user-clear"
      @click="clear"
    >
      Clear attached account
    </UButton>

    <div v-if="status === 'pending'" class="py-2 text-sm text-muted">Searching…</div>
    <ul v-else class="flex flex-col divide-y divide-default">
      <li
        v-for="user in rows"
        :key="user.uuid"
        data-test="draft-user-row"
        class="flex items-center justify-between gap-2 py-1.5 text-sm"
      >
        <span>{{ userDisplayName(user) }}</span>
        <UButton
          size="xs"
          :color="modelValue === user.uuid ? 'primary' : 'neutral'"
          :variant="modelValue === user.uuid ? 'solid' : 'outline'"
          data-test="draft-user-select"
          @click="select(user)"
        >
          {{ modelValue === user.uuid ? 'Selected' : 'Select' }}
        </UButton>
      </li>
    </ul>
  </div>
</template>
