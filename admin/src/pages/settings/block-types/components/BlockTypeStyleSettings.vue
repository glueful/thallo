<script setup lang="ts">
// A block type's style settings: which SETTING GROUPS its Style and Layout tabs offer. A block
// type made here has ONE style target — its outermost element — so the choice is of groups, not of
// which element each lands on; the list is the server's (`style_capability_options`), so nothing
// is offered that a save would refuse. A code-declared type's groups are set in code and re-synced
// on every upgrade: shown here, never editable.
import { computed } from 'vue'

const props = defineProps<{
  slug: string
  /** The groups a block type made in the admin may be given, in the server's order. */
  options: string[]
  modelValue: string[]
  codeDeclared?: boolean
  /** The server's refusal of the last save, shown where the choice was made. */
  error?: string | null
  /** The type is being created: it has no template yet, so there is nothing for a save to refuse. */
  creating?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [groups: string[]] }>()

/** What a group is, for someone choosing it. A group the server offers and this page has not
 *  heard of is listed by its own name. */
const LABELS: Record<string, { label: string; hint: string }> = {
  spacing: { label: 'Spacing', hint: 'Padding and margins.' },
  width: { label: 'Width', hint: 'How wide the block may grow.' },
  'alignment.self': { label: 'Placement', hint: 'Left, centre or right within its parent.' },
  typography: { label: 'Typography', hint: 'Text size, weight and line height.' },
  colors: { label: 'Colours', hint: 'Background, text and border colour.' },
  backdrop: { label: 'Backdrop', hint: 'Background opacity and backdrop blur.' },
  radius: { label: 'Corners', hint: 'Corner radius.' },
  border: { label: 'Border', hint: 'Width, style and sides.' },
  shadow: { label: 'Shadow', hint: 'Drop shadow.' },
  visibility: { label: 'Visibility', hint: 'Show or hide per screen size.' },
  motion: { label: 'Motion', hint: 'An entrance animation as the block scrolls into view.' },
  'layout.min_height': { label: 'Minimum height', hint: 'Half or full screen height.' },
  'layout.overflow': { label: 'Overflow', hint: 'Clip or scroll what does not fit.' },
  'layout.item': {
    label: 'Sizing in a parent layout',
    hint: 'Span, basis and grow inside a container’s grid or row.',
  },
}

const rows = computed(() => {
  // A code-declared type may declare groups outside the offer: list what it HAS.
  const names = props.codeDeclared
    ? [
        ...new Set([
          ...props.modelValue,
          ...props.options.filter((o) => props.modelValue.includes(o)),
        ]),
      ]
    : props.options
  return names.map((name) => ({
    name,
    label: LABELS[name]?.label ?? name,
    hint: LABELS[name]?.hint ?? '',
    checked: props.modelValue.includes(name),
  }))
})

/** What the template adds. A plain string: the braces are Twig's, not this template's. */
const SNIPPET = [
  `<div class="my-block{{ style_classes('root') }}"{{ style_attrs('root') }}>`,
  '  …',
  '</div>',
].join('\n')

function toggle(name: string, on: boolean): void {
  const chosen = new Set(props.modelValue)
  if (on) chosen.add(name)
  else chosen.delete(name)
  // The server's order, whatever order they were ticked in: the stored list is stable.
  emit(
    'update:modelValue',
    props.options.filter((o) => chosen.has(o)),
  )
}
</script>

<template>
  <div class="space-y-4" data-test="block-type-style-settings">
    <p class="text-sm text-muted">
      The settings this block offers in the designer’s Style and Layout tabs. They land on the
      block’s outermost element.
    </p>

    <UAlert
      v-if="codeDeclared"
      color="neutral"
      variant="subtle"
      icon="i-lucide-lock"
      data-test="style-code-declared"
      description="This block type is declared by Thallo. Its style settings are set in code and re-synced on every upgrade, so they are shown here but cannot be changed."
    />

    <p v-if="rows.length === 0" class="text-sm text-muted" data-test="style-settings-none">None.</p>
    <ul v-else class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
      <li v-for="row in rows" :key="row.name" :data-test="`style-group-option-${row.name}`">
        <label class="flex items-start gap-2" :class="{ 'cursor-pointer': !codeDeclared }">
          <input
            type="checkbox"
            class="mt-1 size-4 rounded border-default accent-(--ui-primary)"
            :checked="row.checked"
            :disabled="codeDeclared"
            @change="(e) => toggle(row.name, (e.target as HTMLInputElement).checked)"
          />
          <span class="min-w-0">
            <span class="block text-sm font-medium text-default">{{ row.label }}</span>
            <span v-if="row.hint" class="block text-xs text-muted">{{ row.hint }}</span>
          </span>
        </label>
      </li>
    </ul>

    <p v-if="error" class="text-sm text-error" data-test="style-settings-error">{{ error }}</p>

    <div
      v-if="!creating && !codeDeclared"
      class="flex flex-wrap items-center gap-2 text-xs text-muted"
      data-test="block-template-row"
    >
      <span
        >Template: <code class="text-default">blocks/{{ slug }}.twig</code></span
      >
      <UButton
        :to="`/templates?path=blocks/${slug}.twig`"
        label="Open in the Theme editor"
        icon="i-lucide-file-code-2"
        color="neutral"
        variant="link"
        size="xs"
        data-test="block-template-open"
      />
    </div>

    <div
      v-if="!codeDeclared && modelValue.length > 0"
      class="space-y-2 rounded-md bg-elevated p-3 text-xs text-muted"
      data-test="style-template-hint"
    >
      <p v-if="creating">
        When you write the block’s template,
        <code class="text-default">blocks/{{ slug }}.twig</code> in your theme’s templates folder,
        it has to emit them on its outermost element. The Theme editor will not save a template that
        leaves them out.
      </p>
      <p v-else>
        The block’s template,
        <code class="text-default">blocks/{{ slug }}.twig</code>, has to emit them on its outermost
        element. Saving is refused until it does, because a template that does not would stop
        rendering.
      </p>
      <pre
        class="overflow-x-auto rounded bg-default p-2 text-default"
      ><code>{{ SNIPPET }}</code></pre>
    </div>
  </div>
</template>
