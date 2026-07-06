<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { Collection } from '@/queries/collections'
import CollectionsListPane from './components/CollectionsListPane.vue'
import CollectionDataPane from './components/CollectionDataPane.vue'
import CollectionCreateSlideover from './components/CollectionCreateSlideover.vue'
import CollectionEditSlideover from './components/CollectionEditSlideover.vue'

const route = useRoute()
const router = useRouter()

// The selected collection lives in the URL (?collection=name) so the view is shareable/refreshable.
const selectedName = computed(() => (route.query.collection as string | undefined) || undefined)

function select(collection: Collection) {
  router.replace({ query: { ...route.query, collection: collection.name } })
}
function clearSelection() {
  const q = { ...route.query }
  delete q.collection
  router.replace({ query: q })
}

const showCreate = ref(false)
// When set, the create slideover opens pre-filled from this collection (the Duplicate action).
const duplicateSource = ref<Collection | null>(null)
function onNewCollection() {
  duplicateSource.value = null
  showCreate.value = true
}
function onDuplicate(collection: Collection) {
  duplicateSource.value = collection
  showCreate.value = true
}
function onCreated(name: string) {
  router.replace({ query: { ...route.query, collection: name } })
}

const showEdit = ref(false)
function onDropped() {
  clearSelection()
}
</script>

<template>
  <UDashboardPanel id="collections" :ui="{ body: 'overflow-hidden' }">
    <template #body>
      <div class="flex h-full min-h-0 p-1">
        <!-- List pane: always on lg+; on mobile only when nothing is selected. -->
        <div
          class="min-h-0 lg:shrink-0 lg:border-e lg:border-default lg:pe-4"
          :class="selectedName ? 'hidden lg:block' : 'block'"
        >
          <CollectionsListPane
            class="h-full"
            :selected-name="selectedName"
            @select="select"
            @create="onNewCollection"
          />
        </div>

        <!-- Data pane: always on lg+; on mobile only when a collection is selected. -->
        <div
          class="min-w-0 flex-1 flex-col lg:ps-6"
          :class="selectedName ? 'flex' : 'hidden lg:flex'"
        >
          <div v-if="!selectedName" class="m-auto text-center text-sm text-muted">
            <UIcon name="i-lucide-mouse-pointer-click" class="mx-auto mb-2 size-6" />
            Select a collection to view its data
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
            <CollectionDataPane
              :key="selectedName"
              :collection-name="selectedName"
              class="min-h-0 flex-1"
              @edit-schema="showEdit = true"
            />
          </template>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <CollectionCreateSlideover
    v-model:open="showCreate"
    :prefill="duplicateSource"
    @created="onCreated"
  />
  <CollectionEditSlideover
    v-if="selectedName"
    v-model:open="showEdit"
    :name="selectedName"
    @dropped="onDropped"
    @duplicate="onDuplicate"
  />
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.collections
</route>
