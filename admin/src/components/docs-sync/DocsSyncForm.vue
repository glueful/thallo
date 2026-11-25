<script setup lang="ts">
// The docs source (docs sync design §4): a public GitHub repo, a folder in it, what to follow, and
// the Markdown import's own choices. Saving checks it against GitHub; a refusal is shown on the
// field it is about.
import { computed, reactive, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useSaveDocsSource, type DocsSource } from '@/queries/docsSync'
import { useSetupDocs } from '@/queries/docs'
import { useNotify } from '@/composables/useNotify'
import { pageTypes, type TypeLike } from '@/pages/settings/import-export/markdownFolder'

const props = defineProps<{ source: DocsSource | null; contentTypes: TypeLike[] }>()
const emit = defineEmits<{ saved: []; cancel: [] }>()

const save = useSaveDocsSource()
const setup = useSetupDocs()
const { success, error: notifyError } = useNotify()

const form = reactive({
  repo: props.source?.repo ?? '',
  folder: props.source?.folder ?? 'docs',
  follow: props.source?.follow ?? ('release' as 'release' | 'branch'),
  branch: props.source?.branch ?? 'main',
  prereleases: props.source?.prereleases ?? true,
  content_type: props.source?.content_type ?? 'docs',
  publish: props.source?.publish ?? true,
  exclude: (props.source?.exclude ?? []).join(', '),
})
const errors = ref<Record<string, string>>({})
const hasDocs = computed(() => props.contentTypes.some((t) => t.slug === 'docs'))
const typeItems = computed(() => {
  const items = pageTypes(props.contentTypes)
  return items.length ? items : [{ label: 'Docs', value: 'docs' }]
})
const followItems = [
  { label: 'The latest release tag', value: 'release' },
  { label: 'A branch', value: 'branch' },
]

async function onSubmit() {
  errors.value = {}
  try {
    await save.mutateAsync({
      repo: form.repo.trim(),
      folder: form.folder.trim(),
      follow: form.follow,
      branch: form.follow === 'branch' ? form.branch.trim() : null,
      prereleases: form.prereleases,
      content_type: form.content_type,
      publish: form.publish,
      exclude: form.exclude
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean),
    })
    success('Docs source saved', 'Press Sync now to bring the pages in.')
    emit('saved')
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fieldErrors).length) {
      errors.value = e.fieldErrors
      return
    }
    notifyError(e, 'Could not save the docs source')
  }
}

async function onSetup() {
  try {
    await setup.mutateAsync({})
    form.content_type = 'docs'
  } catch (e) {
    notifyError(e, 'Could not set up documentation')
  }
}
</script>

<template>
  <form class="space-y-4" data-test="docs-sync-form" @submit.prevent="onSubmit">
    <UAlert
      v-if="!hasDocs"
      color="neutral"
      variant="subtle"
      icon="i-lucide-book-open"
      title="This site has no documentation section yet"
      description="Setting one up makes a “docs” content type for the pages, listed at /docs."
    >
      <template #actions>
        <UButton size="sm" :loading="setup.isLoading.value" @click="onSetup">
          Set up documentation
        </UButton>
      </template>
    </UAlert>

    <UFormField
      label="Repository"
      help="A public GitHub repository, as owner/name."
      :error="errors.repo"
    >
      <UInput
        v-model="form.repo"
        placeholder="glueful/thallo"
        class="w-full"
        data-test="docs-sync-repo"
      />
    </UFormField>
    <UFormField
      label="Folder"
      help="The folder in the repository that holds the pages."
      :error="errors.folder"
    >
      <UInput
        v-model="form.folder"
        placeholder="docs"
        class="w-full"
        data-test="docs-sync-folder"
      />
    </UFormField>
    <UFormField label="Follow" :error="errors.follow">
      <USelect v-model="form.follow" :items="followItems" class="w-full" />
    </UFormField>
    <UFormField v-if="form.follow === 'branch'" label="Branch" :error="errors.branch">
      <UInput v-model="form.branch" placeholder="main" class="w-full" />
    </UFormField>
    <UCheckbox v-else v-model="form.prereleases" label="Include pre-releases (1.0.0-beta.N)" />
    <UFormField label="Content type" :error="errors.content_type">
      <USelect v-model="form.content_type" :items="typeItems" class="w-full" />
    </UFormField>
    <UFormField
      label="Folders to leave out"
      help="Optional. Separate with commas: internal, drafts"
    >
      <UInput v-model="form.exclude" class="w-full" />
    </UFormField>
    <UCheckbox v-model="form.publish" label="Publish the pages (otherwise they are drafts)" />

    <p v-if="errors.source" class="text-sm text-error">{{ errors.source }}</p>
    <div class="flex gap-2">
      <UButton type="submit" :loading="save.isLoading.value">Save source</UButton>
      <UButton v-if="source" color="neutral" variant="ghost" @click="emit('cancel')"
        >Cancel</UButton
      >
    </div>
  </form>
</template>
