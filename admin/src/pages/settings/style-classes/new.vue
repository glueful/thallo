<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStyleClassMutations } from '@/queries/styleClasses'
import { useNotify } from '@/composables/useNotify'
import StyleClassEditor from './components/StyleClassEditor.vue'

definePage({ meta: { requiresAuth: true } })

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useStyleClassMutations()

const name = ref('')
const description = ref('')
const style = ref<Record<string, unknown>>({})

async function onCreate() {
  try {
    const created = await create.mutateAsync({
      name: name.value.trim(),
      description: description.value.trim() || null,
      style: style.value,
    })
    success('Style class created', 'Apply it from a block’s Advanced tab.')
    await router.push(`/settings/style-classes/${created.id}`)
  } catch (e) {
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
          <StyleClassEditor v-model="style" />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
