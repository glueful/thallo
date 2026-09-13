<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import {
  useAllRedirects,
  useAllRedirectMutations,
  type AggregatedRedirectRow,
} from '@/queries/redirects'
import { useContentTypes } from '@/queries/contentTypes'
import { runtimeConfig } from '@/runtime/config'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useAllRedirects()
const { create, remove } = useAllRedirectMutations()
const { data: contentTypes } = useContentTypes()

const contentTypeOptions = computed(() =>
  (contentTypes.value ?? [])
    .filter((c) => typeof c.slug === 'string' && c.slug !== '')
    .map((c) => ({ label: c.name ?? c.slug, value: c.slug as string })),
)

const columns: TableColumn<AggregatedRedirectRow>[] = [
  { accessorKey: 'type_name', header: 'Content type' },
  { accessorKey: 'source_slug', header: 'Source' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'target_state', header: 'State' },
  { id: 'actions', header: '' },
]

async function onDelete(row: AggregatedRedirectRow) {
  try {
    await remove.mutateAsync(row.uuid)
    success('Redirect removed')
  } catch (e) {
    notifyError(e, 'Couldn’t remove redirect')
  }
}

// ── Add redirect (per content type) ──
const statusOptions = ['301', '302', '308']
const showAdd = ref(false)
const form = reactive({ type: '', source_slug: '', url: '', status: '301' })

function resetForm() {
  Object.assign(form, { type: '', source_slug: '', url: '', status: '301' })
}

async function onCreate() {
  if (form.type === '' || form.source_slug === '' || form.url === '') return
  try {
    await create.mutateAsync({
      type: form.type,
      input: {
        locale: runtimeConfig.defaultLocale,
        source_slug: form.source_slug,
        status: Number(form.status),
        url: form.url,
      },
    })
    success('Redirect created')
    showAdd.value = false
    resetForm()
  } catch (e) {
    notifyError(e, 'Couldn’t create redirect')
  }
}
</script>

<template>
  <UDashboardPanel id="settings-redirects">
    <template #header>
      <UDashboardNavbar title="Redirects">
        <template #right>
          <UButton
            icon="i-lucide-plus"
            @click="
              () => {
                showAdd = true
              }
            "
            >Add redirect</UButton
          >
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="data ?? []" :columns="columns" :loading="status === 'pending'">
        <template #type_name-cell="{ row }">
          <UBadge color="neutral" variant="subtle" size="sm">{{ row.original.type_name }}</UBadge>
        </template>

        <template #source_slug-cell="{ row }">
          <code class="text-xs text-default">{{ row.original.source_slug }}</code>
        </template>

        <template #status-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.status }}</span>
        </template>

        <template #target_state-cell="{ row }">
          <UBadge
            v-if="row.original.target_state"
            size="sm"
            variant="subtle"
            :color="row.original.target_state === 'live' ? 'success' : 'error'"
          >
            {{ row.original.target_state }}
          </UBadge>
          <span v-else class="text-muted">—</span>
        </template>

        <template #actions-cell="{ row }">
          <div class="flex justify-end">
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-trash-2"
              aria-label="Remove redirect"
              :loading="remove.isLoading.value"
              @click="onDelete(row.original)"
            />
          </div>
        </template>

        <template #empty>
          <UEmpty
            variant="naked"
            icon="i-lucide-signpost"
            title="No redirects"
            description="Add a redirect to forward an old slug to a new target."
          >
            <template #actions>
              <UButton
                icon="i-lucide-plus"
                @click="
                  () => {
                    showAdd = true
                  }
                "
                >Add redirect</UButton
              >
            </template>
          </UEmpty>
        </template>
      </UTable>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="showAdd" title="Add redirect">
    <template #body>
      <div class="space-y-4">
        <UFormField label="Content type" hint="The type whose routes this redirect belongs to">
          <USelect
            v-model="form.type"
            :items="contentTypeOptions"
            placeholder="Select a content type"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Source slug">
          <UInput v-model="form.source_slug" placeholder="old-path" class="w-full" />
        </UFormField>
        <UFormField label="Target URL">
          <UInput v-model="form.url" placeholder="/new-path or https://…" class="w-full" />
        </UFormField>
        <UFormField label="Status">
          <USelect v-model="form.status" :items="statusOptions" class="w-32" />
        </UFormField>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          @click="
            () => {
              showAdd = false
            }
          "
          >Cancel</UButton
        >
        <UButton
          :loading="create.isLoading.value"
          :disabled="form.type === '' || form.source_slug === '' || form.url === ''"
          @click="onCreate"
        >
          Add redirect
        </UButton>
      </div>
    </template>
  </UModal>
</template>
