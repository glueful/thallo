<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { EditorView, keymap, lineNumbers } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { StreamLanguage } from '@codemirror/language'
import { jinja2 } from '@codemirror/legacy-modes/mode/jinja2'

const model = defineModel<string>({ required: true })

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
        keymap.of([...defaultKeymap, ...historyKeymap]),
        StreamLanguage.define(jinja2),
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
