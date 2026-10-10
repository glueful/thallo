<script setup lang="ts">
// Link popover for the rich-text toolbars: pick text → set/edit/open/remove its link, instead of
// the editor's built-in native prompt(). Rendered in the `#link` slot of UEditorToolbar.
import { computed, ref } from 'vue'
import type { EditorCustomHandlers } from '@nuxt/ui'
import { normalizeLinkUrl } from '@/components/linkUrl'

// Derive the Tiptap Editor type from @nuxt/ui's handler signature — @tiptap/* isn't a hoisted
// dependency, so we can't import its types directly.
type Editor = Parameters<EditorCustomHandlers[string]['execute']>[0]

const props = defineProps<{ editor: Editor }>()

const open = ref(false)
const url = ref('')

const active = computed(() => props.editor.isActive('link'))
const disabled = computed(() => {
  if (!props.editor.isEditable) return true
  const { selection } = props.editor.state
  return selection.empty && !props.editor.isActive('link')
})

function onToggle(value: boolean) {
  open.value = value
  // Seed the input with the current link when opening.
  if (value) url.value = props.editor.getAttributes('link').href ?? ''
}

function apply() {
  // What was typed, as the link meant: `www.example.com` alone is a page on this site.
  const href = normalizeLinkUrl(url.value)
  if (!href) return
  const { selection } = props.editor.state
  let chain = props.editor.chain().focus().extendMarkRange('link').setLink({ href })
  // With no selection, drop the URL itself in as the link text.
  if (selection.empty) chain = chain.insertContent({ type: 'text', text: href })
  chain.run()
  open.value = false
}

function remove() {
  props.editor.chain().focus().extendMarkRange('link').unsetLink().run()
  url.value = ''
  open.value = false
}

function openExternal() {
  if (url.value) window.open(url.value, '_blank', 'noopener,noreferrer')
}
</script>

<template>
  <UPopover :open="open" :ui="{ content: 'p-1' }" @update:open="onToggle">
    <UTooltip text="Link">
      <UButton
        icon="i-lucide-link"
        color="neutral"
        active-color="primary"
        variant="ghost"
        active-variant="soft"
        size="sm"
        :active="active"
        :disabled="disabled"
        aria-label="Link"
      />
    </UTooltip>

    <template #content>
      <div class="flex items-center gap-0.5">
        <UInput
          v-model="url"
          autofocus
          type="url"
          variant="none"
          placeholder="Paste a link…"
          class="w-56"
          @keydown.enter.prevent="apply"
        />
        <UButton
          icon="i-lucide-corner-down-left"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="!url"
          aria-label="Apply link"
          @click="apply"
        />
        <UButton
          icon="i-lucide-external-link"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="!url"
          aria-label="Open link in new tab"
          @click="openExternal"
        />
        <UButton
          icon="i-lucide-unlink"
          color="error"
          variant="ghost"
          size="sm"
          :disabled="!active"
          aria-label="Remove link"
          @click="remove"
        />
      </div>
    </template>
  </UPopover>
</template>
