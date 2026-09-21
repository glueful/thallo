<script setup lang="ts">
// The choices of a Markdown folder import (Settings › Import / Export), and "Set up
// documentation": on a site with no docs section, one click makes the content type the import
// needs and lets the site list it — what `thallo:docs:setup` does from a shell.
import { computed, ref, watchEffect } from 'vue'
import { useSetupDocs } from '@/queries/docs'
import { useNotify } from '@/composables/useNotify'
import { pageTypes, type TypeLike } from './markdownFolder'

const props = defineProps<{ contentTypes: TypeLike[] }>()
const type = defineModel<string>('type', { required: true })
const publish = defineModel<boolean>('publish', { required: true })
const editBase = defineModel<string>('editBase', { required: true })
const exclude = defineModel<string>('exclude', { required: true })

const { success, error: notifyError } = useNotify()
const setup = useSetupDocs()

const typeItems = computed(() => pageTypes(props.contentTypes))
const hasDocs = computed(() => props.contentTypes.some((t) => t.slug === 'docs'))
/** What an existing `docs` type lacks, as the server reported it. */
const missing = ref<string[]>([])

// A docs section is what this import is for: it is the type chosen unless another was.
watchEffect(() => {
  if (type.value === '' && typeItems.value.some((t) => t.value === 'docs')) type.value = 'docs'
})

async function onSetup() {
  try {
    const result = await setup.mutateAsync({})
    missing.value = result.missing
    if (result.missing.length > 0) return
    type.value = result.type
    success(
      'Documentation is set up',
      `Pages you import will be at ${result.url}/{page}, and ${result.url} lists them.`,
    )
  } catch (e) {
    notifyError(e, 'Could not set up documentation')
  }
}
</script>

<template>
  <div class="space-y-4" data-test="markdown-folder-fields">
    <UAlert
      v-if="!hasDocs"
      color="neutral"
      variant="subtle"
      icon="i-lucide-book-open"
      title="This site has no documentation section yet"
      description="Setting one up makes a “docs” content type with the fields a docs page needs, and lets the site list it at /docs. You can change its sections later under Settings › Content types."
    >
      <template #actions>
        <UButton
          size="sm"
          icon="i-lucide-wand-sparkles"
          :loading="setup.isLoading.value"
          data-test="setup-docs"
          @click="onSetup"
        >
          Set up documentation
        </UButton>
      </template>
    </UAlert>

    <UAlert
      v-if="missing.length"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="The “docs” content type cannot hold docs pages yet"
      :description="`It has no ${missing.join(', ')} field. Add ${missing.length === 1 ? 'it' : 'them'} under Settings › Content types, then set up again.`"
      data-test="setup-docs-missing"
    />

    <UFormField label="Content type" help="Each Markdown file becomes a page of this type.">
      <USelect
        v-model="type"
        :items="typeItems"
        placeholder="Choose a content type"
        class="w-full"
        data-test="folder-type"
      />
    </UFormField>

    <UFormField
      label="Folders to leave out"
      help="Optional. Separate with commas: internal, drafts"
    >
      <UInput v-model="exclude" class="w-full" data-test="folder-exclude" />
    </UFormField>

    <UFormField
      label="“Edit this page” links"
      help="Optional. The folder's edit address, for example https://github.com/you/site/edit/main/docs"
    >
      <UInput v-model="editBase" type="url" class="w-full" data-test="folder-edit-base" />
    </UFormField>

    <UFormField label="On commit">
      <USwitch v-model="publish" label="Publish the pages" />
    </UFormField>

    <p class="text-xs text-muted">
      Upload the folder again whenever the files change: a page is found by its address or its file,
      only what changed is written, and nothing is ever deleted.
    </p>
  </div>
</template>
