<script setup lang="ts">
// One of the site's own typefaces (the Custom pairing): a woff2 in the media library, named by its
// uuid — exactly what the settings store. woff2 only: it is the one format every browser a theme
// supports reads, and the only one the uploader takes. The field shows the face ITSELF, a specimen
// set in it, because a file name says nothing about what a font looks like.
import { computed, ref } from 'vue'
import { blobDisplayUrl, useUploadMedia } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  /** Which role the face plays; also keeps two fields' specimen fonts apart. */
  role: 'body' | 'display'
  label: string
}>()
const model = defineModel<string>({ required: true })

const upload = useUploadMedia()
const { error: notifyError } = useNotify()
const busy = ref(false)

// The uuid is written into a stylesheet, so it is held to a uuid's shape; anything else is no face.
const uuid = computed(() => (/^[A-Za-z0-9_-]{8,40}$/.test(model.value) ? model.value : ''))
const family = computed(() => `thallo-admin-face-${props.role}`)
const faceCss = computed(
  () =>
    `@font-face{font-family:"${family.value}";src:url("${blobDisplayUrl(uuid.value)}") format("woff2");font-weight:100 900;font-display:swap}`,
)

async function onPick(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  const isWoff2 = file.type === 'font/woff2' || /\.woff2$/i.test(file.name)
  if (!isWoff2 || (file.type !== '' && file.type !== 'font/woff2')) {
    notifyError(
      new Error(`“${file.name}” is not a .woff2 font. Convert it to woff2 and upload that.`),
      'Unsupported font file',
    )
    return
  }
  busy.value = true
  try {
    // Public, like a logo: the site serves it to every visitor.
    const asset = await upload.mutateAsync({ file, visibility: 'public' })
    if (asset.blob_uuid) model.value = asset.blob_uuid
  } catch (e) {
    notifyError(e, 'Upload failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-2" :data-test="`font-face-${role}`">
    <p class="text-sm font-medium text-default">{{ label }}</p>
    <template v-if="uuid">
      <!-- The face, declared for this specimen only, from the URL the media library serves. -->
      <component :is="'style'">{{ faceCss }}</component>
      <div
        class="rounded-md border border-default bg-default p-3"
        :style="{ fontFamily: `'${family}', system-ui, sans-serif` }"
        :data-test="`font-specimen-${role}`"
      >
        <p class="text-2xl leading-tight text-highlighted">Aa Bb Cc 123</p>
        <p class="mt-1 text-sm text-toned">The quick brown fox jumps over the lazy dog.</p>
      </div>
    </template>
    <div class="flex items-center gap-2">
      <label
        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-default px-2.5 py-1.5 text-xs font-medium text-default hover:bg-elevated focus-within:ring-2 focus-within:ring-primary"
      >
        <UIcon name="i-lucide-upload" class="size-3.5" />
        {{ busy ? 'Uploading…' : uuid ? 'Replace' : 'Upload a .woff2' }}
        <input
          type="file"
          accept=".woff2,font/woff2"
          class="sr-only"
          :disabled="busy"
          :data-test="`font-upload-${role}`"
          @change="onPick"
        />
      </label>
      <button
        v-if="uuid"
        type="button"
        class="rounded-md px-2 py-1.5 text-xs text-muted hover:text-default"
        :data-test="`font-remove-${role}`"
        @click="model = ''"
      >
        Remove
      </button>
    </div>
  </div>
</template>
