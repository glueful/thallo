<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { Collection } from '@/queries/collections'
import CollectionDataPane from '../../components/CollectionDataPane.vue'
import CollectionEditSlideover from '../../components/CollectionEditSlideover.vue'
import CollectionCreateSlideover from '../../components/CollectionCreateSlideover.vue'

const route = useRoute()
const router = useRouter()
const name = computed(() => String(route.params.name))

const showEdit = ref(false)
function onDropped() {
  router.push('/collections')
}

const showCreate = ref(false)
const duplicateSource = ref<Collection | null>(null)
function onDuplicate(collection: Collection) {
  duplicateSource.value = collection
  showCreate.value = true
}
function onCreated(newName: string) {
  router.push({ path: '/collections', query: { collection: newName } })
}
</script>

<template>
  <UDashboardPanel id="collection-data">
    <template #header>
      <UDashboardNavbar :title="`${name} · data`">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/collections"
            aria-label="Back to collections"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <CollectionDataPane :collection-name="name" class="h-full" @edit-schema="showEdit = true" />
    </template>
  </UDashboardPanel>

  <CollectionEditSlideover
    v-model:open="showEdit"
    :name="name"
    @dropped="onDropped"
    @duplicate="onDuplicate"
  />
  <CollectionCreateSlideover
    v-model:open="showCreate"
    :prefill="duplicateSource"
    @created="onCreated"
  />
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: thallo.collections
</route>
