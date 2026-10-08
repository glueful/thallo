<script setup lang="ts">
// A stored choice the server no longer offers as available (search block spec §3.9). Unlike the
// style editor's InvalidChoiceNotice, the value is kept and can be saved: a block whose scope needs a
// feature that is off simply shows nothing on the site until it is on again. It says why: a disabled
// choice, one no installed feature provides any more, one that is no longer among the choices at all
// (a deleted category — the multi-select offers to remove it), or choices that did not load.
const props = defineProps<{
  state:
    | { kind: 'disabled'; label: string; reason: string | null }
    | { kind: 'removed' }
    | { kind: 'gone' }
    | { kind: 'loading' }
  value: string
}>()
</script>

<template>
  <div class="rounded border border-warning/40 bg-warning/5 px-2 py-1.5 text-[11px] text-muted">
    <template v-if="props.state.kind === 'disabled'">
      <span class="font-medium text-default">{{ props.state.label }}</span>
      ({{ (props.state.reason ?? '').toLowerCase() }}): the block keeps this choice and shows
      nothing on the site until it is available again.
    </template>
    <template v-else-if="props.state.kind === 'removed'">
      <code class="text-default">{{ props.value }}</code> is no longer provided by any installed
      feature. The block keeps this choice.
    </template>
    <template v-else-if="props.state.kind === 'gone'">
      <code class="text-default">{{ props.value }}</code> is no longer one of the choices. The block
      keeps it until it is removed.
    </template>
    <template v-else>
      Couldn’t load the choices. The stored choice is
      <span class="font-medium text-default">{{
        props.value === '' ? 'All results' : props.value
      }}</span
      >.
    </template>
  </div>
</template>
