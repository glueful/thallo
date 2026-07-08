<script setup lang="ts">
import { reactive, ref, watch, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useBlockTypeMutations } from '@/queries/blockTypes'
import { validateContentTypeFields, type ContentTypeField } from '@/queries/contentTypes'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useBlockTypeMutations()

const schema = z.object({
  label: z.string().min(1, 'Label is required.'),
  slug: z
    .string()
    .min(1, 'Slug is required.')
    .regex(/^[a-z][a-z0-9_-]*$/, 'Lowercase letters, numbers, hyphens and underscores only.'),
  icon: z.string().optional(),
  category: z.string().optional(),
  description: z.string().optional(),
})
type Schema = z.output<typeof schema>

const state = reactive({ label: '', slug: '', icon: '', category: '', description: '' })
const fields = ref<ContentTypeField[]>([])
const createForm = useTemplateRef<Form<Schema>>('createForm')

// Auto-derive the slug from the label until the user edits the slug directly.
const slugTouched = ref(false)
function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
}
watch(
  () => state.label,
  (label) => {
    if (!slugTouched.value) state.slug = slugify(label)
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
      label: event.data.label,
      icon: event.data.icon?.trim() || null,
      category: event.data.category?.trim() || null,
      description: event.data.description?.trim() || null,
      schema: fields.value.map((f) => ({ ...f, name: f.name.trim() })),
    })
    success('Block type created', `“${event.data.label}” is available in block pickers.`)
    await router.push('/settings/block-types')
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) createForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t create block type')
  }
}
</script>

<template>
  <UDashboardPanel id="block-types-new">
    <template #header>
      <UDashboardNavbar title="New block type">
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
          <UButton variant="ghost" color="neutral" to="/settings/block-types">Cancel</UButton>
          <UButton type="submit" form="new-block-type" :loading="create.isLoading.value">
            Create block type
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UForm
        id="new-block-type"
        ref="createForm"
        :schema="schema"
        :state="state"
        class="mx-auto w-full max-w-6xl"
        @submit="onSubmit"
      >
        <!-- Same shape as the content-type editor: Details = slim sticky left
             rail, Fields = the wide working column. -->
        <div class="grid gap-6 lg:grid-cols-3 pb-5">
          <div class="lg:sticky lg:top-6 lg:self-start">
            <UCard>
              <template #header><h2 class="font-semibold text-default">Details</h2></template>

              <div class="space-y-4">
                <UFormField label="Label" name="label">
                  <UInput v-model="state.label" class="w-full" placeholder="Hero" />
                </UFormField>

                <UFormField
                  label="Slug"
                  name="slug"
                  description="Immutable — it names the blocks/{slug}.twig template"
                >
                  <UInput
                    v-model="state.slug"
                    class="w-full"
                    placeholder="hero"
                    @update:model-value="slugTouched = true"
                  />
                </UFormField>

                <UFormField
                  label="Icon"
                  name="icon"
                  description="Lucide icon name shown in the block picker"
                >
                  <UInput v-model="state.icon" class="w-full" placeholder="i-lucide-star" />
                </UFormField>

                <UFormField
                  label="Category"
                  name="category"
                  description="Groups the block picker — e.g. Layout, Content, Media; empty = Other"
                >
                  <UInput v-model="state.category" class="w-full" placeholder="Content" />
                </UFormField>

                <UFormField label="Description" name="description">
                  <UTextarea
                    v-model="state.description"
                    class="w-full"
                    :rows="2"
                    placeholder="What does this block show?"
                  />
                </UFormField>
              </div>
            </UCard>
          </div>

          <div class="lg:col-span-2">
            <UCard>
              <template #header><h2 class="font-semibold text-default">Fields</h2></template>
              <!-- Block schemas reject nested blocks/localized/filterable (spec §2). -->
              <ContentTypeFields v-model="fields" context="block-type" />
            </UCard>
          </div>
        </div>
      </UForm>
    </template>
  </UDashboardPanel>
</template>
