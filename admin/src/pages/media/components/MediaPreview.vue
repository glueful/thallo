<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { isImage, type MediaDetail } from '@/queries/media'

const props = defineProps<{ item?: MediaDetail | null }>()

const variant = ref<'full' | 'thumbnail'>('full')
// Reset to Full whenever a different item is selected.
watch(
  () => props.item?.uuid,
  () => {
    variant.value = 'full'
  },
)

const showTabs = computed(() => !!props.item && isImage(props.item.mime_type))
const src = computed(() => {
  if (!props.item) return ''
  return variant.value === 'thumbnail' ? props.item.thumb_url : props.item.display_url
})

const tabs = [
  { value: 'full', label: 'Full' },
  { value: 'thumbnail', label: 'Thumbnail' },
] as const
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <div v-if="showTabs" class="flex shrink-0 items-center gap-1 p-3">
      <UButton
        v-for="t in tabs"
        :key="t.value"
        :label="t.label"
        size="xs"
        class="rounded-lg"
        :color="variant === t.value ? 'primary' : 'neutral'"
        :variant="variant === t.value ? 'solid' : 'ghost'"
        @click="
          () => {
            variant = t.value
          }
        "
      />
    </div>

    <div class="flex min-h-0 flex-1 items-center justify-center p-6">
      <div v-if="!item" class="text-center text-sm text-muted">
        <UIcon name="i-lucide-image" class="mx-auto mb-2 size-8" />
        Select a media item
      </div>

      <template v-else>
        <img
          v-if="isImage(item.mime_type)"
          :src="src"
          :alt="item.alt_text ?? item.name"
          class="max-h-full max-w-full rounded-lg object-contain shadow-sm"
        />
        <video
          v-else-if="item.mime_type.startsWith('video/')"
          :src="item.display_url"
          controls
          class="max-h-full max-w-full rounded-lg"
        />
        <audio
          v-else-if="item.mime_type.startsWith('audio/')"
          :src="item.display_url"
          controls
          class="w-full max-w-md"
        />
        <div v-else class="text-center">
          <UIcon name="i-lucide-file" class="mx-auto mb-3 size-16 text-muted" />
          <p class="text-sm text-default">{{ item.name }}</p>
          <UButton
            class="mt-3"
            :to="item.display_url"
            target="_blank"
            icon="i-lucide-external-link"
            label="Open file"
            color="neutral"
            variant="outline"
            size="sm"
          />
        </div>
      </template>
    </div>
  </div>
</template>
