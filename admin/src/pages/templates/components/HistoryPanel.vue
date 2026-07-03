<script setup lang="ts">
import { ref, watch } from 'vue'
import { useNotify } from '@/composables/useNotify'
import {
  fetchVersion,
  fetchVersions,
  restoreVersion,
  type TemplateVersion,
} from '@/queries/templates'

const open = defineModel<boolean>('open', { required: true })
const props = defineProps<{ theme: string; path: string }>()
const emit = defineEmits<{ restored: [] }>()

const notify = useNotify()
const versions = ref<TemplateVersion[]>([])
const preview = ref<{ uuid: string; source: string } | null>(null)

watch(open, async (isOpen) => {
  if (!isOpen) return
  preview.value = null
  try {
    versions.value = await fetchVersions(props.path, props.theme)
  } catch (err) {
    notify.error(err, "Couldn't load history")
  }
})

async function view(uuid: string) {
  try {
    const version = await fetchVersion(props.path, uuid, props.theme)
    preview.value = { uuid, source: version.source }
  } catch (err) {
    notify.error(err, "Couldn't load version")
  }
}

async function restore(uuid: string) {
  try {
    await restoreVersion(props.path, uuid, props.theme)
    notify.success('Version restored — live now')
    open.value = false
    emit('restored')
  } catch (err) {
    // A 422 here means the stored version fails TODAY'S policy (spec §5).
    notify.error(err, "Couldn't restore this version")
  }
}
</script>

<template>
  <USlideover v-model:open="open" title="Version history">
    <template #body>
      <ul class="space-y-2" data-test="history-list">
        <li
          v-for="v in versions"
          :key="v.uuid"
          class="flex items-center gap-2 text-sm"
          :data-test="`version-${v.uuid}`"
        >
          <span class="flex-1 truncate">
            {{ v.created_at }}
            <span v-if="v.created_by" class="text-muted">by {{ v.created_by }}</span>
            <UBadge v-if="v.current" size="xs" color="primary" class="ml-1">current</UBadge>
          </span>
          <UButton size="xs" variant="ghost" color="neutral" label="View" @click="view(v.uuid)" />
          <UButton
            v-if="!v.current"
            size="xs"
            variant="ghost"
            color="warning"
            label="Restore"
            :data-test="`restore-${v.uuid}`"
            @click="restore(v.uuid)"
          />
        </li>
      </ul>
      <pre
        v-if="preview"
        class="mt-4 p-3 bg-elevated rounded text-xs overflow-x-auto"
        data-test="version-preview"
        >{{ preview.source }}</pre
      >
    </template>
  </USlideover>
</template>
