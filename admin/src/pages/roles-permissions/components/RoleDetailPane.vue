<script setup lang="ts">
import { ref } from 'vue'
import type { Role } from '@/queries/rbac'
import RolePermissionsEditor from './RolePermissionsEditor.vue'
import MenuSettings from '@/components/access/MenuSettings.vue'

defineProps<{ role: Role }>()

// What the role may do, and what its members see in the sidebar and land on after signing in.
const tab = ref<'permissions' | 'menus'>('permissions')
const tabItems = [
  { label: 'Permissions', value: 'permissions' },
  { label: 'Menus', value: 'menus' },
]
defineEmits<{ edit: []; delete: [] }>()
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <header
      class="mb-4 flex items-start justify-between gap-3 rounded-xl border border-default p-4"
    >
      <div class="flex items-center gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="text-lg font-semibold text-highlighted">{{ role.name }}</h1>
            <UBadge
              v-if="role.level != null"
              :label="`Level ${role.level}`"
              color="neutral"
              variant="subtle"
              size="xs"
            />
          </div>
          <code class="text-sm text-muted">{{ role.slug }}</code>
          <p v-if="role.description" class="mt-1 text-sm text-muted">{{ role.description }}</p>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-1">
        <UButton
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-pencil"
          aria-label="Edit role"
          @click="$emit('edit')"
        />
        <UButton
          color="error"
          variant="ghost"
          size="xs"
          icon="i-lucide-trash-2"
          aria-label="Delete role"
          @click="$emit('delete')"
        />
      </div>
    </header>

    <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" />

    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
      <RolePermissionsEditor v-if="tab === 'permissions'" :role="role" />
      <MenuSettings v-else :key="`menus-${role.uuid}`" subject="roles" :uuid="role.uuid" />
    </div>
  </div>
</template>
