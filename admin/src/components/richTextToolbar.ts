import type { EditorToolbarItem } from '@nuxt/ui'

// Shared rich-text toolbar items — ONE source for RichText.vue (the full-chrome
// field editor) and ProseBlockEditor.vue (the chromeless prose block). Moved
// verbatim from RichText.vue; `satisfies` keeps the string literals narrow.

// "Turn into" block-type menu, shared by the fixed and bubble toolbars.
export const turnInto = {
  label: 'Turn into',
  trailingIcon: 'i-lucide-chevron-down',
  color: 'neutral',
  variant: 'ghost',
  content: { align: 'start' },
  ui: { label: 'text-xs' },
  items: [
    { type: 'label', label: 'Turn into' },
    { kind: 'paragraph', label: 'Paragraph', icon: 'i-lucide-type' },
    { kind: 'heading', level: 1, label: 'Heading 1', icon: 'i-lucide-heading-1' },
    { kind: 'heading', level: 2, label: 'Heading 2', icon: 'i-lucide-heading-2' },
    { kind: 'heading', level: 3, label: 'Heading 3', icon: 'i-lucide-heading-3' },
    { kind: 'bulletList', label: 'Bullet list', icon: 'i-lucide-list' },
    { kind: 'orderedList', label: 'Ordered list', icon: 'i-lucide-list-ordered' },
    { kind: 'taskList', label: 'Task list', icon: 'i-lucide-list-checks' },
    { kind: 'blockquote', label: 'Blockquote', icon: 'i-lucide-text-quote' },
    { kind: 'codeBlock', label: 'Code block', icon: 'i-lucide-square-code' },
  ],
} satisfies EditorToolbarItem

// Bubble toolbar — appears over a non-empty text selection (Nuxt UI's default shouldShow).
export const bubbleItems = [
  [turnInto],
  [
    { kind: 'mark', mark: 'bold', icon: 'i-lucide-bold' },
    { kind: 'mark', mark: 'italic', icon: 'i-lucide-italic' },
    { kind: 'mark', mark: 'underline', icon: 'i-lucide-underline' },
    { kind: 'mark', mark: 'strike', icon: 'i-lucide-strikethrough' },
    { kind: 'mark', mark: 'code', icon: 'i-lucide-code' },
  ],
  [{ slot: 'link', icon: 'i-lucide-link' }],
] satisfies EditorToolbarItem[][]
