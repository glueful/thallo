<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  useContentType,
  useContentTypeMutations,
  validateContentTypeFields,
  type ContentTypeField,
} from '@/queries/contentTypes'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const { success, error: notifyError } = useNotify()

const slug = computed(() => String(route.params.slug))
const { data, status, error } = useContentType(slug)
const { updateMeta, updateSchema, remove } = useContentTypeMutations()

// A local, editable copy of the schema. Re-seeded whenever the fetched type changes (load/refetch);
// PATCH /content-types/{slug}/schema replaces the schema wholesale, so we send the whole array.
const localSchema = ref<ContentTypeField[]>([])
watch(
  data,
  (ct) => {
    if (ct) localSchema.value = ct.schema.map((f) => ({ ...f, enum: [...(f.enum ?? [])] }))
  },
  { immediate: true },
)

async function onSaveSchema() {
  const fieldError = validateContentTypeFields(localSchema.value)
  if (fieldError !== null) {
    notifyError(new Error(fieldError), 'Check the fields')
    return
  }
  try {
    await updateSchema.mutateAsync({
      slug: slug.value,
      schema: localSchema.value.map((f) => ({ ...f, name: f.name.trim() })),
    })
    success('Schema saved', 'Field changes were applied.')
  } catch (e) {
    notifyError(e, 'Couldn’t save schema')
  }
}

// The switch reflects the server value; on toggle we PATCH and let the query
// refetch settle the final state (revert locally on failure).
const publicDelivery = ref(false)
watch(
  data,
  (ct) => {
    if (ct) publicDelivery.value = ct.public_delivery
  },
  { immediate: true },
)

async function onTogglePublicDelivery(value: boolean) {
  publicDelivery.value = value
  try {
    await updateMeta.mutateAsync({ slug: slug.value, meta: { public_delivery: value } })
    success(
      value ? 'Public delivery enabled' : 'Public delivery disabled',
      value
        ? 'Entries of this type can now be delivered on the live site.'
        : 'Entries of this type are no longer publicly delivered.',
    )
  } catch (e) {
    publicDelivery.value = !value
    notifyError(e, 'Couldn’t update public delivery')
  }
}

const mountAtRoot = ref(false)
watch(
  data,
  (ct) => {
    if (ct) mountAtRoot.value = ct.mount_at_root
  },
  { immediate: true },
)

async function onToggleMountAtRoot(value: boolean) {
  mountAtRoot.value = value
  try {
    await updateMeta.mutateAsync({ slug: slug.value, meta: { mount_at_root: value } })
    success(
      value ? 'Mounted at root' : 'Unmounted from root',
      value
        ? 'Entries now serve at /slug instead of /' + slug.value + '/slug.'
        : 'Entries are back at /' + slug.value + '/slug.',
    )
  } catch (e) {
    // A 409 lists every slug that collides with the root namespace — the
    // flag never flips partially, so revert the switch and show the list.
    mountAtRoot.value = !value
    notifyError(e, 'Couldn’t mount at root')
  }
}

const previewOpen = ref(false)

const showDeleteConfirm = ref(false)
async function confirmDelete() {
  try {
    await remove.mutateAsync(slug.value)
    success('Content type deleted', `“${data.value?.name ?? slug.value}” was removed.`)
    await router.push('/settings/content-types')
  } catch (e) {
    notifyError(e, 'Couldn’t delete content type')
  }
}
</script>

<template>
  <UDashboardPanel id="content-type-detail">
    <template #header>
      <UDashboardNavbar :title="data?.name ?? slug">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/settings/content-types"
            aria-label="Back to content types"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-6xl">
        <div v-if="status === 'pending'" class="mx-auto max-w-2xl space-y-3">
          <USkeleton class="h-24" />
          <USkeleton class="h-40" />
        </div>

        <UEmpty
          v-else-if="error"
          class="mx-auto max-w-2xl"
          icon="i-lucide-triangle-alert"
          title="Couldn’t load content type"
          :description="error.message"
        >
          <template #actions>
            <UButton variant="subtle" to="/settings/content-types">Back to list</UButton>
          </template>
        </UEmpty>

        <!-- Details = the slim left rail (sticky); Fields = the wide working
             column that grows with the schema. -->
        <div v-else-if="data" class="grid gap-6 lg:grid-cols-3">
          <div class="space-y-6 lg:sticky lg:top-6 lg:self-start">
            <UCard>
              <template #header><h2 class="font-semibold text-default">Details</h2></template>
              <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm lg:grid-cols-1">
                <div>
                  <dt class="text-muted">Slug</dt>
                  <dd class="text-default">
                    <code class="text-xs">{{ slug }}</code>
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">Status</dt>
                  <dd class="text-default">{{ data.status ?? 'active' }}</dd>
                </div>
                <div>
                  <dt class="text-muted">Schema version</dt>
                  <dd class="text-default">{{ data.schema_version ?? '—' }}</dd>
                </div>
                <div class="col-span-2 flex items-center justify-between gap-3 lg:col-span-1">
                  <dt class="text-muted">Public delivery</dt>
                  <dd class="text-default">
                    <USwitch
                      :model-value="publicDelivery"
                      :disabled="updateMeta.isLoading.value"
                      data-test="public-delivery-toggle"
                      :label="publicDelivery ? 'Yes' : 'No'"
                      @update:model-value="onTogglePublicDelivery"
                    />
                  </dd>
                </div>
                <div class="col-span-2 flex items-center justify-between gap-3 lg:col-span-1">
                  <dt class="text-muted">Mount at root</dt>
                  <dd class="text-default">
                    <USwitch
                      :model-value="mountAtRoot"
                      :disabled="updateMeta.isLoading.value"
                      data-test="mount-at-root-toggle"
                      :label="mountAtRoot ? `/slug` : `/${slug}/slug`"
                      @update:model-value="onToggleMountAtRoot"
                    />
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">Cache TTL</dt>
                  <dd class="text-default">
                    {{ data.cache_ttl !== null ? `${data.cache_ttl}s` : '—' }}
                  </dd>
                </div>
                <div class="col-span-2 lg:col-span-1">
                  <dt class="text-muted">Description</dt>
                  <dd class="text-default">{{ data.description || '—' }}</dd>
                </div>
              </dl>
            </UCard>

            <UCard>
              <template #header><h2 class="font-semibold text-error">Danger zone</h2></template>
              <div class="space-y-3">
                <p class="text-sm text-muted">
                  Delete this content type. Existing entries stay in storage but are hidden from
                  listing and delivery.
                </p>
                <UButton
                  color="error"
                  variant="subtle"
                  icon="i-lucide-trash-2"
                  @click="() => { showDeleteConfirm = true }"
                >
                  Delete
                </UButton>
              </div>
            </UCard>
          </div>

          <div class="lg:col-span-2">
            <UCard>
              <template #header>
                <div class="flex items-center justify-between gap-2">
                  <h2 class="font-semibold text-default">Fields</h2>
                  <div class="flex items-center gap-1.5">
                    <UButton
                      size="sm"
                      variant="subtle"
                      color="neutral"
                      icon="i-lucide-eye"
                      square
                      aria-label="Preview the entry form"
                      data-test="open-preview"
                      @click="() => { previewOpen = true }"
                    />
                    <UButton
                      size="sm"
                      icon="i-lucide-save"
                      :loading="updateSchema.isLoading.value"
                      @click="onSaveSchema"
                    >
                      Save schema
                    </UButton>
                  </div>
                </div>
              </template>

              <UAlert
                class="mb-4"
                color="warning"
                variant="subtle"
                icon="i-lucide-info"
                title="Renaming or removing a field is destructive"
                description="Saving replaces the schema wholesale. Removing or renaming a field may require a data migration on existing entries."
              />

              <ContentTypeFields v-model="localSchema" />
            </UCard>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <USlideover
    v-model:open="previewOpen"
    :title="data?.name ?? slug"
    :ui="{ content: 'sm:max-w-2xl' }"
    description="Entry form preview — inputs are throwaway."
  >
    <template #body>
      <ContentTypePreview v-if="data" :fields="localSchema" />
    </template>
  </USlideover>

  <UModal v-model:open="showDeleteConfirm" title="Delete content type">
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ data?.name ?? slug }}”</span>? This hides the type and
        its entries from listing and delivery.
      </p>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="() => { showDeleteConfirm = false }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
