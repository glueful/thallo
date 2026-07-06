<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { UserRow } from '@/queries/users'
import { useCapabilitiesStore } from '@/stores/capabilities'
import UsersListPane from './components/UsersListPane.vue'
import UserDetailPane from './components/UserDetailPane.vue'
import UserCreateModal from './components/UserCreateModal.vue'
import UserBulkImportModal from './components/UserBulkImportModal.vue'

definePage({ meta: { requiresAuth: true } })

const caps = useCapabilitiesStore()
caps.ensureLoaded()

const route = useRoute()
const router = useRouter()
const selectedUuid = computed(() => (route.query.user as string | undefined) || undefined)
const showCreate = ref(false)
const showImport = ref(false)

function select(user: UserRow) {
  router.replace({ query: { ...route.query, user: user.uuid } })
}
function clearSelection() {
  const q = { ...route.query }
  delete q.user
  router.replace({ query: q })
}
function onCreated(uuid: string) {
  router.replace({ query: { ...route.query, user: uuid } })
}
</script>

<template>
  <UDashboardPanel id="users" :ui="{ body: 'overflow-hidden' }">
    <!-- <template #header>
      <UDashboardNavbar title="Users" />
    </template> -->

    <template #body>
      <div class="flex h-full min-h-0 p-1">
        <!-- List pane wrapper: visible always on lg+; on mobile only when nothing is selected. -->
        <div
          class="min-h-0 lg:shrink-0 lg:border-e lg:border-default lg:pe-4"
          :class="selectedUuid ? 'hidden lg:block' : 'block'"
        >
          <UsersListPane
            class="h-full"
            :selected-uuid="selectedUuid"
            @select="select"
            @create="showCreate = true"
            @bulk-import="showImport = true"
          />
        </div>

        <!-- Detail pane wrapper: visible always on lg+; on mobile only when a user is selected. -->
        <div
          class="min-w-0 flex-1 flex-col lg:ps-6"
          :class="selectedUuid ? 'flex' : 'hidden lg:flex'"
        >
          <div v-if="!selectedUuid" class="m-auto text-center text-sm text-muted">
            <UIcon name="i-lucide-mouse-pointer-click" class="mx-auto mb-2 size-6" />
            Select a user to view details
          </div>
          <template v-else>
            <UButton
              class="mb-2 self-start lg:hidden"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-arrow-left"
              label="Back"
              @click="clearSelection"
            />
            <UserDetailPane :key="selectedUuid" :uuid="selectedUuid" class="min-h-0 flex-1" />
          </template>
        </div>
      </div>

      <UserCreateModal v-model:open="showCreate" @created="onCreated" />
      <UserBulkImportModal v-if="caps.isEnabled('thallo.importers')" v-model:open="showImport" />
    </template>
  </UDashboardPanel>
</template>
