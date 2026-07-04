<script setup lang="ts">
import { reactive, ref, watch, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useContentTypeMutations,
  validateContentTypeFields,
  type ContentTypeField,
} from '@/queries/contentTypes'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useContentTypeMutations()

const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z
    .string()
    .min(1, 'Slug is required.')
    .regex(/^[a-z0-9-]+$/, 'Lowercase letters, numbers and hyphens only.'),
  description: z.string().optional(),
})
type Schema = z.output<typeof schema>

const state = reactive({
  name: '',
  slug: '',
  description: '',
  cache_ttl: '',
  public_delivery: false,
  mount_at_root: false,
})
const fields = ref<ContentTypeField[]>([])
const createForm = useTemplateRef<Form<Schema>>('createForm')

// Auto-derive the slug from the name until the user edits the slug directly.
const slugTouched = ref(false)
function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
watch(
  () => state.name,
  (name) => {
    if (!slugTouched.value) state.slug = slugify(name)
  },
)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  const fieldError = validateContentTypeFields(fields.value)
  if (fieldError !== null) {
    notifyError(new Error(fieldError), 'Check the fields')
    return
  }
  try {
    await create.mutateAsync({
      slug: event.data.slug,
      name: event.data.name,
      description: event.data.description?.trim() || null,
      cache_ttl: state.cache_ttl.trim() === '' ? null : Number(state.cache_ttl),
      public_delivery: state.public_delivery,
      mount_at_root: state.mount_at_root,
      schema: fields.value.map((f) => ({ ...f, name: f.name.trim() })),
    })
    success('Content type created', `“${event.data.name}” is ready.`)
    await router.push(`/settings/content-types/${event.data.slug}`)
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) createForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t create content type')
  }
}
</script>

<template>
  <UDashboardPanel id="content-types-new">
    <template #header>
      <UDashboardNavbar title="New content type">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/settings/content-types"
            aria-label="Back to content types"
          />
        </template>
        <template #right>
          <UButton variant="ghost" color="neutral" to="/settings/content-types">Cancel</UButton>
          <UButton type="submit" form="new-content-type" :loading="create.isLoading.value">
            Create content type
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UForm
        id="new-content-type"
        ref="createForm"
        :schema="schema"
        :state="state"
        class="mx-auto w-full max-w-6xl"
        @submit="onSubmit"
      >
        <div class="grid gap-6 lg:grid-cols-5">
          <!-- Editor column -->
          <div class="space-y-6 lg:col-span-3">
            <UCard>
              <template #header><h2 class="font-semibold text-default">Details</h2></template>

              <div class="space-y-4">
                <UFormField label="Name" name="name">
                  <UInput v-model="state.name" class="w-full" placeholder="Blog post" />
                </UFormField>

                <UFormField
                  label="Slug"
                  name="slug"
                  hint="Used in API routes — lowercase, hyphenated"
                >
                  <UInput
                    v-model="state.slug"
                    class="w-full"
                    placeholder="blog-post"
                    @update:model-value="slugTouched = true"
                  />
                </UFormField>

                <UFormField label="Description" name="description">
                  <UTextarea
                    v-model="state.description"
                    class="w-full"
                    :rows="2"
                    placeholder="What is this content type for?"
                  />
                </UFormField>

                <div class="flex flex-wrap items-end gap-4">
                  <UFormField label="Cache TTL (seconds)" hint="Optional">
                    <UInput
                      v-model="state.cache_ttl"
                      type="number"
                      min="0"
                      placeholder="—"
                      class="w-40"
                    />
                  </UFormField>
                  <USwitch v-model="state.public_delivery" label="Public delivery" class="pb-2" />
                  <UFormField
                    class="pb-2"
                    help="Entries serve at /slug instead of /type/slug. Slugs must not collide with type names or reserved paths."
                  >
                    <USwitch
                      v-model="state.mount_at_root"
                      label="Mount at root"
                      data-test="mount-at-root-create"
                    />
                  </UFormField>
                </div>
              </div>
            </UCard>

            <UCard>
              <template #header><h2 class="font-semibold text-default">Fields</h2></template>
              <ContentTypeFields v-model="fields" />
            </UCard>
          </div>

          <!-- Live preview column -->
          <div class="lg:col-span-2">
            <div class="lg:sticky lg:top-6">
              <ContentTypePreview :name="state.name" :fields="fields" />
            </div>
          </div>
        </div>
      </UForm>
    </template>
  </UDashboardPanel>
</template>
