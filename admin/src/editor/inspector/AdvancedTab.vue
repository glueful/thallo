<script setup lang="ts">
// The Advanced tab (visual builder spec §1.4, §3.4): anchor, the ordered Style classes
// (read-only here — the class UI is Phase B), CSS classes, `data-*` attributes and the
// accessibility label. The two class lists are never both called "Classes".
import { computed, ref, watch } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import IdentifierControl from './controls/IdentifierControl.vue'

const props = defineProps<{
  block: BlockInstance
  /** Class id => name; an applied id absent here is missing from the site (spec §4.1). */
  classNames?: Record<string, string>
  /** The site's classes the picker offers: unarchived, unlocked, not yet applied. */
  classOptions?: { id: string; name: string; archived: boolean; locked: boolean }[]
}>()
const emit = defineEmits<{
  /** Set (or clear with null) one advanced path. */
  set: [path: 'anchor' | 'css_classes' | 'attributes' | 'accessibility.label', value: unknown]
  /** Append a style class reference (spec §3.1 ApplyStyleClass). */
  'apply-class': [id: string]
  'remove-class': [id: string]
  /** The whole list in its new order (ReorderStyleClasses). */
  'reorder-classes': [ids: string[]]
  /** Detach: materialise what the class contributed, then remove the reference (spec §4.4). */
  'detach-class': [id: string]
  'detach-all': []
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
/** A local mirror for the drag list; re-derived whenever the block's list changes. */
const classOrder = ref<string[]>([...styleClasses.value])
watch(styleClasses, (ids) => (classOrder.value = [...ids]))
function onClassesReordered(): void {
  const ids = [...classOrder.value]
  if (JSON.stringify(ids) !== JSON.stringify(styleClasses.value)) emit('reorder-classes', ids)
}
/** The classes the picker offers: the site's, unarchived, unlocked, not yet applied. */
const pickable = computed(() =>
  (props.classOptions ?? [])
    .filter((c) => !c.archived && !c.locked && !styleClasses.value.includes(c.id))
    .map((c) => ({ label: c.name, value: c.id })),
)
// The picker is an action, not a field: it applies what is chosen and stays empty. Its value is
// held at '' — CONTROLLED, never undefined. Given undefined the select keeps its own value, so
// after one pick it still held that class, and choosing the same class on the next block was no
// change to it: nothing was emitted, and one class could not be put on a second block.
function onPick(id: unknown): void {
  if (typeof id === 'string' && id !== '') emit('apply-class', id)
}
function classState(id: string): 'missing' | 'archived' | 'locked' | null {
  if (props.classNames !== undefined && !(id in props.classNames)) return 'missing'
  const option = props.classOptions?.find((c) => c.id === id)
  if (option?.locked) return 'locked'
  if (option?.archived) return 'archived'
  return null
}
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
      hint="Reusable site styles applied to this block, in cascade order — later ones win."
    >
      <VueDraggable
        v-if="classOrder.length > 0"
        v-model="classOrder"
        handle="[data-test^='style-class-grip-']"
        :animation="150"
        tag="ul"
        class="space-y-1"
        data-test="style-classes"
        @end="onClassesReordered"
      >
        <li
          v-for="id in classOrder"
          :key="id"
          class="flex items-center gap-1 rounded bg-elevated px-2 py-1 text-xs"
          :data-test="`style-class-${id}`"
        >
          <UIcon
            name="i-lucide-grip-vertical"
            class="size-3 cursor-grab text-muted"
            :data-test="`style-class-grip-${id}`"
          />
          <span class="min-w-0 flex-1 truncate">{{ props.classNames?.[id] ?? id }}</span>
          <UBadge
            v-if="classState(id) !== null"
            size="xs"
            :color="classState(id) === 'locked' ? 'info' : 'warning'"
            variant="subtle"
            :data-test="`style-class-${classState(id)}`"
          >
            {{ classState(id) === 'locked' ? 'job running' : classState(id) }}
          </UBadge>
          <UButton
            size="xs"
            variant="ghost"
            color="neutral"
            :disabled="classState(id) === 'locked' || classState(id) === 'missing'"
            :data-test="`style-class-detach-${id}`"
            title="Materialise what this class contributes, then remove it"
            @click="emit('detach-class', id)"
          >
            Detach
          </UButton>
          <UButton
            size="xs"
            variant="ghost"
            color="neutral"
            icon="i-lucide-x"
            :disabled="classState(id) === 'locked'"
            :aria-label="`Remove ${props.classNames?.[id] ?? id}`"
            :data-test="`style-class-remove-${id}`"
            @click="emit('remove-class', id)"
          />
        </li>
      </VueDraggable>
      <p v-else class="text-xs text-muted" data-test="style-classes-empty">None applied.</p>
      <div class="mt-2 flex items-center gap-2">
        <USelectMenu
          model-value=""
          :items="pickable"
          value-key="value"
          placeholder="Apply a style class…"
          class="flex-1"
          :disabled="pickable.length === 0"
          data-test="style-class-picker"
          @update:model-value="onPick"
        />
        <UButton
          v-if="classOrder.length > 1"
          size="xs"
          variant="ghost"
          color="neutral"
          data-test="style-classes-detach-all"
          title="Materialised values stop following the classes"
          @click="emit('detach-all')"
        >
          Detach all
        </UButton>
      </div>
      <p
        v-if="classOrder.some((id) => classState(id) === null)"
        class="mt-1 text-[11px] text-muted"
      >
        Detaching keeps how the block looks: the class’s values are written to the block and stop
        following the class.
      </p>
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
