<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useUsers, type UserRow } from '@/queries/users'
import { useCapabilitiesStore } from '@/stores/capabilities'
import UserListItem from './UserListItem.vue'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{ select: [user: UserRow]; create: []; bulkImport: [] }>()

const caps = useCapabilitiesStore()
caps.ensureLoaded()

const page = ref(1)
const perPage = ref(25)
const search = ref('')
const debounced = refDebounced(search, 300)
const { data, status } = useUsers(
  page,
  perPage,
  computed(() => debounced.value || undefined),
)

const total = computed(() => data.value?.total ?? 0)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">Users</h2>
      <div class="flex items-center gap-1">
        <UButton
          v-if="caps.isEnabled('thallo.importers')"
          data-test="users-bulk-import"
          icon="i-lucide-upload"
          color="neutral"
          variant="ghost"
          size="sm"
          aria-label="Bulk import users from CSV"
          @click="emit('bulkImport')"
        />
        <UButton icon="i-lucide-plus" class="px-3 rounded-xl" size="sm" @click="emit('create')" />
      </div>
    </div>

    <UInput v-model="search" icon="i-lucide-search" placeholder="Search users…" class="w-full" />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!(data?.users ?? []).length"
        icon="i-lucide-users"
        title="No users"
        description="The list is empty, or it's disabled (set USERS_USER_LIST_ENABLED=true)."
      />
      <div v-else class="flex flex-col gap-0.5">
        <UserListItem
          v-for="u in data?.users ?? []"
          :key="u.uuid"
          :user="u"
          :selected="u.uuid === props.selectedUuid"
          @select="emit('select', u)"
        />
      </div>
    </div>

    <div
      v-if="total > 0"
      class="flex items-center justify-between gap-2 border-t border-default py-3 text-muted"
    >
      <span class="text-xs font-medium uppercase tracking-wide">{{ total }} users</span>
      <div class="flex items-center gap-1">
        <span class="text-sm">Page {{ page }} / {{ totalPages }}</span>
        <UButton
          icon="i-lucide-chevron-left"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page <= 1"
          aria-label="Previous page"
          @click="() => { page-- }"
        />
        <UButton
          icon="i-lucide-chevron-right"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page >= totalPages"
          aria-label="Next page"
          @click="() => { page++ }"
        />
      </div>
    </div>
  </div>
</template>
