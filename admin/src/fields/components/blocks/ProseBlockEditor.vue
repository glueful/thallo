<script setup lang="ts">
import { computed } from 'vue'
import type { EditorCustomHandlers, EditorSuggestionMenuItem } from '@nuxt/ui'
import RichTextLink from '@/components/RichTextLink.vue'
import { bubbleItems } from '@/components/richTextToolbar'
import type { BlockType } from '@/queries/blockTypes'

// Chromeless prose (spec §3): a rich_text-shaped block renders as flowing text —
// bubble toolbar on selection, no fixed toolbar, and a `/` suggestion menu with
// TWO groups: text constructs (Nuxt UI's own, staying INSIDE this block's HTML)
// and Thallo block types. TipTap stays BOUNDED: its only structural output is the
// insert-block event ({slug, beforeHtml, afterHtml}); block order/ids/tree are
// the Vue tree's alone.
// `pickerTypes` is the CONTAINING LIST's options (stage-toolbar spec §5): the
// `/` menu's widgets insert as split-siblings into the same list, so the
// owning BlockCard resolves them via the one pickerTypesForList resolver.
const props = defineProps<{ modelValue?: string; placeholder?: string; pickerTypes: BlockType[] }>()

const emit = defineEmits<{
  'update:modelValue': [html: string]
  'insert-block': [payload: { slug: string; beforeHtml: string; afterHtml: string }]
}>()

// Derive the Tiptap Editor type from @nuxt/ui's handler signature — @tiptap/*
// core types aren't a direct/hoisted dependency (same derivation as RichText.vue).
type Editor = Parameters<EditorCustomHandlers[string]['execute']>[0]

/**
 * Cursor split with PUBLIC commands only (no @tiptap/pm import — it is not a
 * direct dependency). Nuxt UI's EditorSuggestionMenu deletes the `/` query range
 * BEFORE executing the handler, so the selection already sits at the split
 * point. Strategy: snapshot the doc, cut the tail (before-half HTML), restore,
 * cut the head (after-half HTML), leave the editor showing the before-half, and
 * hand the tree ONE event. Document positions are PM positions — both cuts use
 * the same selection anchor captured AFTER the cleanup, and setContent() resets
 * between them, so each deleteRange sees the document it was measured against.
 */
function emitSplit(editor: Editor, slug: string): void {
  const fullHtml = editor.getHTML()
  const pos = editor.state.selection.from
  const end = editor.state.doc.content.size
  editor.chain().deleteRange({ from: Math.min(pos, end), to: end }).run()
  const beforeHtml = editor.getHTML()
  editor.commands.setContent(fullHtml)
  editor.chain().deleteRange({ from: 0, to: Math.min(pos, editor.state.doc.content.size) }).run()
  const afterHtml = editor.getHTML()
  editor.commands.setContent(beforeHtml)
  emit('insert-block', { slug, beforeHtml, afterHtml })
}

const handlers = {
  thalloBlock: {
    canExecute: () => true,
    isActive: () => false,
    execute: (editor: Editor, item?: { slug?: string }) => {
      emitSplit(editor, item?.slug ?? '')
      return editor.chain()
    },
  },
} satisfies EditorCustomHandlers

// `/` menu: text constructs stay inside this block's HTML; Thallo block types go
// through the custom handler -> split -> tree insertion.
const suggestionItems = computed(
  () =>
    [
      [
        { type: 'label', label: 'Text' },
        { kind: 'heading', level: 1, label: 'Heading 1', icon: 'i-lucide-heading-1' },
        { kind: 'heading', level: 2, label: 'Heading 2', icon: 'i-lucide-heading-2' },
        { kind: 'heading', level: 3, label: 'Heading 3', icon: 'i-lucide-heading-3' },
        { kind: 'bulletList', label: 'Bullet list', icon: 'i-lucide-list' },
        { kind: 'orderedList', label: 'Ordered list', icon: 'i-lucide-list-ordered' },
        { kind: 'blockquote', label: 'Blockquote', icon: 'i-lucide-text-quote' },
      ],
      [
        { type: 'label', label: 'Blocks' },
        ...props.pickerTypes.map((t) => ({
          kind: 'thalloBlock' as const,
          slug: t.slug,
          label: t.label,
          icon: t.icon || 'i-lucide-box',
        })),
      ],
    ] satisfies EditorSuggestionMenuItem<typeof handlers>[][],
)
</script>

<template>
  <UEditor
    v-slot="{ editor }"
    :model-value="modelValue"
    content-type="html"
    :mention="false"
    :handlers="handlers"
    :placeholder="placeholder ?? 'Type “/” for blocks…'"
    :ui="{ base: 'py-1 outline-none' }"
    class="w-full"
    @update:model-value="(v: string | undefined) => emit('update:modelValue', v ?? '')"
  >
    <UEditorToolbar :editor="editor" :items="bubbleItems" layout="bubble">
      <template #link>
        <RichTextLink :editor="editor" />
      </template>
    </UEditorToolbar>

    <UEditorSuggestionMenu :editor="editor" :items="suggestionItems" />
  </UEditor>
</template>
