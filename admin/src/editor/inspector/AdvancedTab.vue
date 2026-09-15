<script setup lang="ts">
// The Advanced tab (visual builder spec §1.4, §3.4): anchor, the ordered Style classes
// (read-only here — the class UI is Phase B), CSS classes, `data-*` attributes and the
// accessibility label. The two class lists are never both called "Classes".
import { computed, ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import IdentifierControl from './controls/IdentifierControl.vue'

const props = defineProps<{
  block: BlockInstance
  /** Class id => name; an applied id absent here is missing from the site (spec §4.1). */
  classNames?: Record<string, string>
}>()
const emit = defineEmits<{
  /** Set (or clear with null) one advanced path. */
  set: [path: 'anchor' | 'css_classes' | 'attributes' | 'accessibility.label', value: unknown]
}>()

const SLUG = /^[a-z][a-z0-9-]*$/
const CLASS_NAME = /^-?[A-Za-z_][A-Za-z0-9_-]*$/
const DATA_ATTRIBUTE = /^data-[a-z0-9-]+$/

const advanced = computed<Record<string, unknown>>(() => {
  const a = props.block.settings?.advanced
  return typeof a === 'object' && a !== null ? (a as Record<string, unknown>) : {}
})
const anchor = computed(() =>
  typeof advanced.value.anchor === 'string' ? advanced.value.anchor : '',
)
const styleClasses = computed<string[]>(() =>
  Array.isArray(props.block.settings?.classes) ? (props.block.settings.classes as string[]) : [],
)
const cssClasses = computed<string[]>(() =>
  Array.isArray(advanced.value.css_classes) ? (advanced.value.css_classes as string[]) : [],
)
const attributes = computed<Record<string, string>>(() => {
  const a = advanced.value.attributes
  return typeof a === 'object' && a !== null ? (a as Record<string, string>) : {}
})
const label = computed(() => {
  const acc = advanced.value.accessibility
  const l = typeof acc === 'object' && acc !== null ? (acc as { label?: unknown }).label : null
  return typeof l === 'string' ? l : ''
})

const classesError = ref<string | null>(null)
function onCssClasses(raw: string): void {
  const names = raw.split(/[\s,]+/).filter((n) => n !== '')
  const bad = names.find((n) => !CLASS_NAME.test(n))
  classesError.value = bad ? `"${bad}" is not a class name.` : null
  if (bad) return
  emit('set', 'css_classes', names.length === 0 ? null : names)
}

const newAttrName = ref('')
const newAttrValue = ref('')
const attrError = ref<string | null>(null)
function addAttribute(): void {
  const name = newAttrName.value.trim()
  if (!DATA_ATTRIBUTE.test(name)) {
    attrError.value = 'Attribute names are data-* (lowercase letters, digits, hyphens).'
    return
  }
  if (name.startsWith('data-thallo-')) {
    attrError.value = 'data-thallo-* is reserved.'
    return
  }
  attrError.value = null
  emit('set', 'attributes', { ...attributes.value, [name]: newAttrValue.value })
  newAttrName.value = ''
  newAttrValue.value = ''
}
function removeAttribute(name: string): void {
  const next = { ...attributes.value }
  delete next[name]
  emit('set', 'attributes', Object.keys(next).length === 0 ? null : next)
}
</script>

<template>
  <div class="space-y-4" data-test="advanced-tab">
    <UFormField label="Anchor" name="anchor" hint="Links to this block: #anchor">
      <IdentifierControl
        :model-value="anchor"
        :pattern="SLUG"
        invalid-message="Lowercase letters, digits and hyphens, starting with a letter."
        placeholder="section-name"
        name="anchor"
        @update:model-value="(v) => emit('set', 'anchor', v === '' ? null : v)"
      />
    </UFormField>

    <UFormField
      label="Style classes"
      name="style_classes"
      hint="Reusable site styles applied to this block."
    >
      <ul v-if="styleClasses.length > 0" class="flex flex-wrap gap-1" data-test="style-classes">
        <li
          v-for="id in styleClasses"
          :key="id"
          class="flex items-center gap-1 rounded bg-elevated px-2 py-0.5 text-xs"
          :data-test="`style-class-${id}`"
        >
          {{ props.classNames?.[id] ?? id }}
          <UBadge
            v-if="props.classNames !== undefined && !(id in props.classNames)"
            size="xs"
            color="warning"
            variant="subtle"
            data-test="style-class-missing"
          >
            missing
          </UBadge>
        </li>
      </ul>
      <p v-else class="text-xs text-muted" data-test="style-classes-empty">None applied.</p>
    </UFormField>

    <UFormField
      label="CSS classes"
      name="css_classes"
      hint="Your own class names, space-separated."
    >
      <UInput
        :model-value="cssClasses.join(' ')"
        class="w-full"
        data-test="css-classes"
        @update:model-value="(v: string | number) => onCssClasses(String(v))"
      />
      <p v-if="classesError" class="mt-1 text-xs text-error" data-test="css-classes-error">
        {{ classesError }}
      </p>
    </UFormField>

    <UFormField label="Attributes" name="attributes" hint="data-* attributes on the block's root.">
      <ul v-if="Object.keys(attributes).length > 0" class="mb-2 space-y-1" data-test="attributes">
        <li
          v-for="(value, name) in attributes"
          :key="name"
          class="flex items-center gap-2 text-xs"
          :data-test="`attribute-${name}`"
        >
          <code class="rounded bg-elevated px-1">{{ name }}</code>
          <span class="truncate text-muted">{{ value }}</span>
          <button
            type="button"
            class="ml-auto text-muted hover:text-error"
            :aria-label="`Remove ${name}`"
            @click="removeAttribute(String(name))"
          >
            <UIcon name="i-lucide-x" class="size-3" />
          </button>
        </li>
      </ul>
      <div class="flex gap-1">
        <UInput
          v-model="newAttrName"
          placeholder="data-name"
          size="xs"
          class="flex-1"
          data-test="attribute-name"
        />
        <UInput
          v-model="newAttrValue"
          placeholder="value"
          size="xs"
          class="flex-1"
          data-test="attribute-value"
        />
        <UButton
          size="xs"
          variant="outline"
          color="neutral"
          data-test="attribute-add"
          @click="addAttribute()"
          >Add</UButton
        >
      </div>
      <p v-if="attrError" class="mt-1 text-xs text-error" data-test="attribute-error">
        {{ attrError }}
      </p>
    </UFormField>

    <UFormField
      label="Accessibility label"
      name="accessibility_label"
      hint="Read by assistive technology instead of the visible text."
    >
      <UInput
        :model-value="label"
        class="w-full"
        data-test="accessibility-label"
        @update:model-value="
          (v: string | number) =>
            emit('set', 'accessibility.label', String(v) === '' ? null : String(v))
        "
      />
    </UFormField>
  </div>
</template>
