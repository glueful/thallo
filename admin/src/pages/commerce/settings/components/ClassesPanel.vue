<script setup lang="ts">
// Task 15b (admin-commerce-area plan, slice 3): shipping class CRUD
// (`AdminShippingClassController` / `Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController`,
// backed by `ShippingClassService`). Mirrors ZonesPanel.vue's zone create/edit/delete section
// (slideover form + confirm-modal delete) since a shipping class is an even simpler flat resource
// (slug + name, no nested sub-resources) — the task's own file list names only ClassesPanel.vue.
//
// No `description` field: verified against `CreateShippingClassData`/`UpdateShippingClassData`
// and migration 009 (`commerce_shipping_classes` has `slug`/`name`/`revision` only) — despite the
// task brief listing one, the backend contract has none, so this panel omits it rather than
// inventing a field the API doesn't accept.
//
// `slug` is immutable after creation (`ShippingClassService`'s own docblock: `per_class_table`
// method config references classes BY SLUG, so a rename would silently change live shipping
// charges) — the slug input is locked once editing an existing row, mirroring the method form's
// `kind` field being locked on edit in ZonesPanel.vue.
//
// Deferred: the brief suggests upgrading ZonesPanel's per-class-table method config's free-text
// class-slug input to a select fed by this list, now that classes are fetchable. Deliberately NOT
// done here — `per_class_table` config slugs are an explicit OPEN VOCABULARY server-side
// (`OpenVocabularySlug`'s own docblock: "a syntactically valid value with no matching counterpart
// elsewhere is still accepted... classes may be created later"), WARN-but-allow on an unknown
// slug rather than rejected. A hard `USelect` bound to the current classes list would make it
// impossible to reference a class slug that doesn't exist yet, changing that documented behavior.
// A creatable combobox (type-or-pick) would preserve it, but there's no established
// creatable-combobox convention yet in this codebase to build on safely in this task's scope.
import { computed, ref } from 'vue'
import {
  useCommerceShippingClasses,
  useCommerceShippingClassMutations,
  type CommerceShippingClass,
} from '@/queries/commerceSettings'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'

const props = defineProps<{ canManage: boolean }>()

const { success, error: notifyError } = useNotify()

// ── Classes list ──────────────────────────────────────────────────────────────

const page = ref(1)
const perPage = ref(24)
const filters = computed(() => ({ page: page.value, perPage: perPage.value }))
const { data, status } = useCommerceShippingClasses(filters)
const classes = computed<CommerceShippingClass[]>(() => data.value?.classes ?? [])

const { createClass, updateClass, deleteClass } = useCommerceShippingClassMutations()

// ── Create/edit (shared slideover) ────────────────────────────────────────────

const formOpen = ref(false)
const editingClass = ref<CommerceShippingClass | null>(null)
const slugInput = ref('')
const nameInput = ref('')
const formError = ref<string | null>(null)

function openCreate() {
  editingClass.value = null
  slugInput.value = ''
  nameInput.value = ''
  formError.value = null
  formOpen.value = true
}

function openEdit(cls: CommerceShippingClass) {
  editingClass.value = cls
  slugInput.value = cls.slug
  nameInput.value = cls.name
  formError.value = null
  formOpen.value = true
}

function closeForm() {
  formOpen.value = false
}

const mutationLoading = computed(() => createClass.isLoading.value || updateClass.isLoading.value)

async function submitForm() {
  formError.value = null
  const name = nameInput.value.trim()
  if (name === '') {
    formError.value = 'Name is required.'
    return
  }

  try {
    if (editingClass.value) {
      await updateClass.mutateAsync({ uuid: editingClass.value.uuid, input: { name } })
      success('Class saved', `“${name}” was updated.`)
    } else {
      const slug = slugInput.value.trim()
      if (slug === '') {
        formError.value = 'Slug is required.'
        return
      }
      await createClass.mutateAsync({ slug, name })
      success('Class created', `“${name}” is ready.`)
    }
    formOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    formError.value = err.fieldErrors.slug ?? err.fieldErrors.name ?? err.message
    notifyError(err, editingClass.value ? 'Couldn’t save class' : 'Couldn’t create class')
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────────

const pendingDelete = ref<CommerceShippingClass | null>(null)
async function confirmDelete() {
  const cls = pendingDelete.value
  if (!cls) return
  try {
    await deleteClass.mutateAsync(cls.uuid)
    success('Class deleted', `“${cls.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    // A 409 refusal (still assigned to a variant) surfaces here verbatim via the toast — never
    // silently treated as a successful delete (the row stays, since the list is re-fetched from
    // the mutation's own invalidation, not removed optimistically).
    notifyError(e, 'Couldn’t delete class')
    pendingDelete.value = null
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-medium text-default">Shipping classes</h2>
      <UButton
        v-if="props.canManage"
        icon="i-lucide-plus"
        data-test="new-class"
        @click="openCreate"
      >
        New class
      </UButton>
    </div>

    <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="classes-loading">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <UAlert
      v-else-if="status === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load shipping classes"
      description="Something went wrong loading shipping classes. Try again."
      data-test="classes-error"
    />

    <UEmpty
      v-else-if="classes.length === 0"
      icon="i-lucide-package"
      title="No shipping classes"
      description="Create a class to start assigning per-class shipping rates."
      data-test="classes-empty"
    />

    <div v-else class="space-y-2">
      <div
        v-for="cls in classes"
        :key="cls.uuid"
        data-test="class-row"
        :data-uuid="cls.uuid"
        class="flex flex-wrap items-center gap-3 rounded-md border border-default p-3"
      >
        <span data-test="class-name" class="font-medium text-default">{{ cls.name }}</span>
        <UBadge color="neutral" variant="subtle" size="sm" data-test="class-slug">{{ cls.slug }}</UBadge>

        <div v-if="props.canManage" class="ml-auto flex gap-1">
          <UButton
            color="neutral"
            variant="ghost"
            size="xs"
            icon="i-lucide-pencil"
            aria-label="Edit class"
            data-test="class-edit"
            @click="openEdit(cls)"
          />
          <UButton
            color="error"
            variant="ghost"
            size="xs"
            icon="i-lucide-trash-2"
            aria-label="Delete class"
            data-test="class-delete"
            @click="() => { pendingDelete = cls }"
          />
        </div>
      </div>
    </div>

    <TablePagination
      v-if="(data?.total ?? 0) > 0"
      v-model:page="page"
      v-model:per-page="perPage"
      :total="data?.total ?? 0"
      label="classes"
    />
  </div>

  <!-- Create/edit slideover -->
  <USlideover
    :open="formOpen"
    :title="editingClass ? 'Edit shipping class' : 'Create shipping class'"
    :ui="{ content: 'sm:max-w-md' }"
    @update:open="(v: boolean) => { if (!v) closeForm() }"
  >
    <template #body>
      <form id="class-form" class="space-y-4" @submit.prevent="submitForm">
        <UFormField
          label="Slug"
          name="slug"
          required
          help="Lowercase letters, numbers, - or _ (max 16 chars). Can’t be changed after creation."
        >
          <UInput
            v-model="slugInput"
            :disabled="editingClass !== null"
            class="w-full"
            data-test="class-slug-input"
          />
        </UFormField>
        <UFormField label="Name" name="name" required>
          <UInput v-model="nameInput" class="w-full" data-test="class-name-input" />
        </UFormField>

        <UAlert
          v-if="formError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="formError"
          data-test="class-form-error"
        />
      </form>
    </template>
    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-test="class-dismiss"
          @click="closeForm"
        />
        <UButton
          type="submit"
          form="class-form"
          data-test="class-form-submit"
          :loading="mutationLoading"
          :label="editingClass ? 'Save' : 'Create'"
        />
      </div>
    </template>
  </USlideover>

  <!-- Delete confirm -->
  <UModal
    :open="pendingDelete !== null"
    title="Delete shipping class"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="deleteClass.isLoading.value"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="class-delete-confirm"
          :loading="deleteClass.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
