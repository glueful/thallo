<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStyleClassMutations } from '@/queries/styleClasses'
import { useNotify } from '@/composables/useNotify'
import StyleClassEditor from './components/StyleClassEditor.vue'
import StyleClassSaveErrors from './components/StyleClassSaveErrors.vue'
import { ApiError } from '@/api/errors'

definePage({ meta: { requiresAuth: true } })

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useStyleClassMutations()

const name = ref('')
const description = ref('')
const style = ref<Record<string, unknown>>({})

/** The fields the server refused (spec §12.5): what was typed stays; this says what to fix. */
const saveErrors = ref<Record<string, string>>({})

async function onCreate() {
  saveErrors.value = {}
  try {
    const created = await create.mutateAsync({
      name: name.value.trim(),
      description: description.value.trim() || null,
      style: style.value,
    })
    success('Style class created', 'Apply it from a block’s Advanced tab.')
    await router.push(`/settings/style-classes/${created.id}`)
  } catch (e) {
    if (e instanceof ApiError) saveErrors.value = e.fieldErrors
    notifyError(e, 'Couldn’t create the style class')
  }
}
</script>

<template>
  <UDashboardPanel id="style-class-new">
    <template #header>
      <UDashboardNavbar title="New style class">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/settings/style-classes"
            aria-label="Back to style classes"
          />
        </template>
        <template #right>
          <UButton
            :loading="create.isLoading.value"
            :disabled="name.trim() === ''"
            data-test="style-class-create"
            @click="onCreate"
          >
            Create
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto grid w-full max-w-6xl gap-6 pb-5 lg:grid-cols-3">
        <UCard class="lg:self-start">
          <template #header><h2 class="font-semibold text-default">Details</h2></template>
          <div class="space-y-4">
            <UFormField label="Name" description="Unique on this site, in any letter case">
              <UInput v-model="name" class="w-full" data-test="style-class-name" />
            </UFormField>
            <UFormField label="Description">
              <UTextarea v-model="description" class="w-full" :rows="2" />
            </UFormField>
          </div>
        </UCard>
        <UCard class="lg:col-span-2">
          <template #header><h2 class="font-semibold text-default">Style</h2></template>
          <StyleClassSaveErrors :errors="saveErrors" class="mb-4" />
          <StyleClassEditor v-model="style" />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
