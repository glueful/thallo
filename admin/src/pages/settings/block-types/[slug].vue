<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useBlockTypes, useBlockTypeMutations, useBlockTypeMigrations } from '@/queries/blockTypes'
import { validateContentTypeFields, type ContentTypeField } from '@/queries/contentTypes'
import { useNotify } from '@/composables/useNotify'
import BlockTypeLifecycle from './components/BlockTypeLifecycle.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const { success, error: notifyError } = useNotify()
const slug = computed(() => String(route.params.slug))

const { data: blockTypes, status } = useBlockTypes()
const blockType = computed(() => (blockTypes.value ?? []).find((t) => t.slug === slug.value))

const { update, setActive } = useBlockTypeMutations()

// While a migration is ACTIVE (running or failed), the schema is owned by the
// migration: block the editor's save (block-migrations spec §7).
const { data: migrations } = useBlockTypeMigrations(() => slug.value)
const migrationActive = computed(() =>
  (migrations.value ?? []).some((m) => m.status === 'running' || m.status === 'failed'),
)

const label = ref('')
const icon = ref('')
const category = ref('')
const description = ref('')
const fields = ref<ContentTypeField[]>([])

// Hydrate ONCE per load (background refetches must not clobber in-progress edits).
let hydrated = false
watch(
  blockType,
  (t) => {
    if (hydrated || !t) return
    label.value = t.label
    icon.value = t.icon ?? ''
    category.value = t.category ?? ''
    description.value = t.description ?? ''
    fields.value = t.schema.map((f) => ({ ...f }))
    hydrated = true
  },
  { immediate: true },
)

async function onSave() {
  const fieldError = validateContentTypeFields(fields.value)
  if (fieldError !== null) {
    notifyError(new Error(fieldError), 'Check the fields')
    return
  }
  try {
    await update.mutateAsync({
      slug: slug.value,
      label: label.value.trim(),
      icon: icon.value.trim() || null,
      category: category.value.trim() || null,
      description: description.value.trim() || null,
      schema: fields.value.map((f) => ({ ...f, name: f.name.trim() })),
    })
    success('Block type updated', 'Future saves validate against the new schema.')
  } catch (e) {
    notifyError(e, 'Couldn’t update block type')
  }
}

async function onToggleActive() {
  const target = !(blockType.value?.active ?? true)
  try {
    await setActive.mutateAsync({ slug: slug.value, active: target })
    success(target ? 'Block type activated' : 'Block type deactivated')
    if (!target) await router.push('/settings/block-types')
  } catch (e) {
    notifyError(e, 'Couldn’t update the block type')
  }
}
</script>

<template>
  <UDashboardPanel id="block-type-edit">
    <template #header>
      <UDashboardNavbar :title="blockType ? blockType.label : 'Block type'">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/settings/block-types"
            aria-label="Back to block types"
          />
        </template>
        <template #right>
          <UButton
            v-if="blockType"
            variant="ghost"
            :color="blockType.active ? 'warning' : 'success'"
            :loading="setActive.isLoading.value"
            data-test="block-type-toggle-active"
            @click="onToggleActive"
          >
            {{ blockType.active ? 'Deactivate' : 'Activate' }}
          </UButton>
          <UButton
            :loading="update.isLoading.value"
            :disabled="migrationActive"
            data-test="block-type-save"
            @click="onSave"
          >
            Save
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="space-y-2">
        <USkeleton v-for="n in 4" :key="n" class="h-12" />
      </div>
      <UEmpty
        v-else-if="!blockType"
        icon="i-lucide-blocks"
        title="Unknown block type"
        :description="`No block type has the slug “${slug}”.`"
      />
      <div v-else class="mx-auto w-full max-w-3xl space-y-6">
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <h2 class="flex-1 font-semibold text-default">Details</h2>
              <UBadge v-if="!blockType.active" size="xs" color="warning" variant="subtle">
                inactive
              </UBadge>
            </div>
          </template>

          <div class="space-y-4">
            <UFormField label="Slug" hint="Immutable — it names the blocks/{slug}.twig template">
              <UInput :model-value="blockType.slug" disabled class="w-full" />
            </UFormField>

            <UFormField label="Label">
              <UInput v-model="label" class="w-full" />
            </UFormField>

            <UFormField label="Icon" hint="Lucide icon name shown in the block picker">
              <UInput v-model="icon" class="w-full" placeholder="i-lucide-star" />
            </UFormField>

            <UFormField
              label="Category"
              hint="Groups the block picker — e.g. Layout, Content, Media; empty = Other"
            >
              <UInput v-model="category" class="w-full" placeholder="Content" />
            </UFormField>

            <UFormField label="Description">
              <UTextarea v-model="description" class="w-full" :rows="2" />
            </UFormField>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <h2 class="flex-1 font-semibold text-default">Fields</h2>
              <UBadge v-if="migrationActive" size="xs" color="warning" variant="subtle">
                migration active — schema locked
              </UBadge>
            </div>
          </template>
          <!-- Schema edits are ADDITIVE-ONLY (block-migrations spec §1): removing or
               renaming a field 422s here — declare a migration instead (below). -->
          <ContentTypeFields v-model="fields" context="block-type" />
        </UCard>

        <BlockTypeLifecycle :slug="slug" :schema="fields" />
      </div>
    </template>
  </UDashboardPanel>
</template>
