<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Extension } from '@codemirror/state'
import { EditorView, keymap, lineNumbers } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { StreamLanguage } from '@codemirror/language'
import { autocompletion, completionKeymap } from '@codemirror/autocomplete'
import { css } from '@codemirror/lang-css'
import { jinja2 } from '@codemirror/legacy-modes/mode/jinja2'
import { javascript, json } from '@codemirror/legacy-modes/mode/javascript'
import { twigCompletions } from './twigCompletions'

const props = withDefaults(
  defineProps<{ language?: 'twig' | 'css' | 'json' | 'javascript'; readonly?: boolean }>(),
  { language: 'twig', readonly: false },
)

const model = defineModel<string>({ required: true })

// CSS uses the real LR language (property/value completion built in); twig
// keeps jinja2 highlighting plus a completion source seeded from the
// TemplatePolicy allowlists; json/js get highlighting only. Read-only
// viewers keep highlighting but never complete.
function languageExtensions(): Extension[] {
  const complete = (ext: Extension): Extension[] => (props.readonly ? [] : [ext])
  switch (props.language) {
    case 'css':
      return [css(), ...complete(autocompletion())]
    case 'json':
      return [StreamLanguage.define(json)]
    case 'javascript':
      return [StreamLanguage.define(javascript)]
    default:
      return [
        StreamLanguage.define(jinja2),
        ...complete(autocompletion({ override: [twigCompletions] })),
      ]
  }
}

const host = ref<HTMLElement | null>(null)
let view: EditorView | null = null

onMounted(() => {
  view = new EditorView({
    parent: host.value!,
    state: EditorState.create({
      doc: model.value,
      extensions: [
        lineNumbers(),
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap, ...completionKeymap]),
        ...languageExtensions(),
        EditorState.readOnly.of(props.readonly),
        EditorView.editable.of(!props.readonly),
        EditorView.updateListener.of((u) => {
          if (u.docChanged) model.value = u.state.doc.toString()
        }),
      ],
    }),
  })
})

watch(model, (next) => {
  if (view && next !== view.state.doc.toString()) {
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: next } })
  }
})

onBeforeUnmount(() => view?.destroy())
</script>

<template>
  <div
    ref="host"
    class="border border-default rounded-md min-h-96 text-sm font-mono"
    data-test="template-editor"
  />
</template>
