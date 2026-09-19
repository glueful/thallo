<script setup lang="ts">
// Provides a root blocks field's context somewhere that field is not an ancestor. BlockList and
// BlockCard take everything from that context — the ops, the single writer, the picker rules, the
// drag group — so provided here they ARE the field's own lists, not a copy of them. `provide` is
// read once, so a host keys this by field: another field's block is another provider.
import { provide } from 'vue'
import { BlocksContextKey, type BlocksContext } from './context'

const props = defineProps<{ context: BlocksContext }>()
provide(BlocksContextKey, props.context)
</script>

<template>
  <slot />
</template>
