# lemma-seo Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-entry, per-locale SEO overrides editor (`SeoPanel`) to the content entry editor, backed by the shipped `lemma-seo` admin endpoints, gated by the `lemma.seo` capability.

**Architecture:** A Pinia-Colada query layer (`queries/seo.ts`) talks to `GET`/`PUT /v1/admin/seo/meta/{entryUuid}?locale=` via `authFetch` (the codebase's raw-fetch helper for endpoints the OpenAPI spec under-types). A collapsible `SeoPanel.vue` renders in the entry editor's right column below `PublishPanel`, hydrates a local form from the override row once per `uuid+locale`, normalizes empty text to `null` on save, and sends only the 7 writable fields. The parent (`index.vue`) render-gates the panel on the `lemma.seo` capability and feeds the same flag into the query's `enabled` gate (fail-closed, two layers).

**Tech Stack:** Vue 3.5 `<script setup>`, Pinia Colada (`@pinia/colada`), Nuxt UI 4.9 (`UCard`, `UCollapsible`, `UInput`, `UTextarea`, `USelect`, `UButton`, `UFormField`), vitest + `@vue/test-utils`, pnpm.

## Global Constraints

- **Frontend-only.** No backend changes. The `lemma-seo` pack, its routes, the `seo_meta` table, and the `seo.manage` grant already ship.
- **Transport = `authFetch`, not the typed client.** *(Deliberate deviation from spec §3.1/§3.2.)* The admin SEO operation is in `openapi.json` but under-typed (`query?: never`, `requestBody?: never`, `content?: never`), so the typed `client` would need casts on locale-query, body, and response. `authFetch` is the established pattern for exactly this (see its docstring; `queries/analytics.ts` uses it). Therefore **no `pnpm gen:api` / `schema.d.ts` regeneration is needed.**
- **Endpoint:** `GET`/`PUT /v1/admin/seo/meta/{entryUuid}?locale=<locale>`. With `authFetch`, build the URL as `` `${runtimeConfig.apiBase}/seo/meta/${uuid}?locale=${locale}` ``.
- **Two-layer, fail-closed gating.** Render gate: `<SeoPanel v-if="seoEnabled" …>` where `seoEnabled = caps.isEnabled('lemma.seo')`. Query gate: `useSeoMeta(uuid, locale, enabled)` never fetches when `enabled` resolves false. The capability store fails closed (empty set on error → hidden/disabled).
- **Payload normalization.** On save, empty (`''`, after `.trim()`) → `null` for the 6 nullable text fields: `title, description, og_title, og_description, og_image, twitter_card`. `robots` is always one of exactly `index` | `noindex` | `noindex,nofollow` (default `index`) — never null/empty.
- **PUT sends only the 7 writable fields** — `title, description, og_title, og_description, og_image, twitter_card, robots` — and never `entry_uuid`, `locale`, `id`, `created_at`, `updated_at`, or server metadata.
- **Hydrate once per key.** `SeoPanel` is keyed `` `${uuid}-${locale}` `` by the parent (remounts on locale switch). A `hydrated` flag guards a `watch(data, …, { immediate: true })` so background refetches don't clobber unsaved edits.
- **Testing:** vitest; assert `data-test` hooks. Nuxt UI `USelect` renders options in a portal — never assert dropdown DOM; assert the `data-test` hooks and the mutation payload instead.
- **Git:** commit per task on `dev`. No Claude/Anthropic attribution anywhere (no `Co-Authored-By`, no "Generated with"). Never stage/commit `CLAUDE.md`. Do **not** commit this plan or the spec.
- **Checks:** `pnpm test` (vitest run) must pass; `pnpm run type-check` must stay exit 0.

---

### Task 1: SEO query layer (`queries/seo.ts` + `qk.seoMeta`)

**Files:**
- Modify: `admin/src/queries/keys.ts` (add `seoMeta` key)
- Create: `admin/src/queries/seo.ts`
- Test: `admin/src/__tests__/seoQueries.spec.ts`

**Interfaces:**
- Consumes: `authFetch(path, init?)` from `@/api/authFetch`; `runtimeConfig.apiBase` from `@/runtime/config`; `qk` from `./keys`; `useQuery`, `useMutation`, `useQueryCache` from `@pinia/colada`; `toValue`, `MaybeRefOrGetter` from `vue`.
- Produces:
  - `interface SeoMeta { title?: string | null; description?: string | null; og_title?: string | null; og_description?: string | null; og_image?: string | null; twitter_card?: string | null; robots?: string | null }`
  - `fetchSeoMeta(uuid: string, locale: string): Promise<SeoMeta>`
  - `useSeoMeta(uuid: MaybeRefOrGetter<string>, locale: MaybeRefOrGetter<string>, enabled?: MaybeRefOrGetter<boolean>)` → Colada query; `data` is `Ref<SeoMeta | undefined>`
  - `saveSeoMeta(uuid: string, locale: string, payload: SeoMeta): Promise<void>`
  - `useSaveSeoMeta(uuid: string, locale: string)` → Colada mutation; `mutateAsync(payload: SeoMeta)`, `isLoading`
  - `qk.seoMeta(uuid: string, locale: string)` → `['seo-meta', uuid, locale]`

- [ ] **Step 1: Write the failing test**

Create `admin/src/__tests__/seoQueries.spec.ts` (mirrors `analyticsQueries.spec.ts`):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchSeoMeta, saveSeoMeta } from '@/queries/seo'

describe('seo query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetchSeoMeta GETs /seo/meta/{uuid}?locale= and unwraps data.data', async () => {
    authFetch.mockResolvedValue({ data: { title: 'Hi', robots: 'index' } })
    const meta = await fetchSeoMeta('e-1', 'en')
    expect(meta).toEqual({ title: 'Hi', robots: 'index' })
    const url = authFetch.mock.calls[0][0] as string
    expect(url).toContain('/v1/admin/seo/meta/e-1?')
    expect(url).toContain('locale=en')
  })

  it('fetchSeoMeta returns {} when the override is unset (empty body)', async () => {
    authFetch.mockResolvedValue({})
    expect(await fetchSeoMeta('e-1', 'en')).toEqual({})
  })

  it('saveSeoMeta PUTs the payload as JSON to the same path+locale', async () => {
    authFetch.mockResolvedValue({})
    await saveSeoMeta('e-1', 'en', { title: 'T', robots: 'noindex' })
    const [url, init] = authFetch.mock.calls[0] as [string, RequestInit]
    expect(url).toContain('/v1/admin/seo/meta/e-1?')
    expect(url).toContain('locale=en')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(init.body as string)).toEqual({ title: 'T', robots: 'noindex' })
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && pnpm exec vitest run src/__tests__/seoQueries.spec.ts`
Expected: FAIL — cannot resolve `@/queries/seo` (module doesn't exist yet).

- [ ] **Step 3: Add the `seoMeta` key**

In `admin/src/queries/keys.ts`, add this line inside the `qk` object (e.g. after the `routes` line):

```ts
  seoMeta: (uuid: string, locale: string) => ['seo-meta', uuid, locale] as const,
```

- [ ] **Step 4: Write `admin/src/queries/seo.ts`**

```ts
import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

// The per-entry, per-locale SEO override row. Every field is optional/nullable: an unset override
// is the empty object {}. `robots` is one of 'index' | 'noindex' | 'noindex,nofollow'.
export interface SeoMeta {
  title?: string | null
  description?: string | null
  og_title?: string | null
  og_description?: string | null
  og_image?: string | null
  twitter_card?: string | null
  robots?: string | null
}

// The admin SEO endpoint is under-typed in the OpenAPI spec (query/body/response are `never`), so it
// rides on authFetch (same pattern as queries/analytics.ts) rather than the typed client.
const url = (uuid: string, locale: string) =>
  `${runtimeConfig.apiBase}/seo/meta/${uuid}?${new URLSearchParams({ locale }).toString()}`

export async function fetchSeoMeta(uuid: string, locale: string): Promise<SeoMeta> {
  const json = await authFetch(url(uuid, locale))
  // The backend wraps the row in `data`; an unset override returns an empty object.
  return ((json.data ?? json) ?? {}) as SeoMeta
}

export function useSeoMeta(
  uuid: MaybeRefOrGetter<string>,
  locale: MaybeRefOrGetter<string>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.seoMeta(toValue(uuid), toValue(locale)),
    query: () => fetchSeoMeta(toValue(uuid), toValue(locale)),
    // When `enabled` resolves false the query never runs — a disabled pack must not hit the 404'd
    // route. The panel's parent passes its `lemma.seo` capability flag through here.
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export async function saveSeoMeta(uuid: string, locale: string, payload: SeoMeta): Promise<void> {
  await authFetch(url(uuid, locale), { method: 'PUT', body: JSON.stringify(payload) })
}

export function useSaveSeoMeta(uuid: string, locale: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (payload: SeoMeta) => saveSeoMeta(uuid, locale, payload),
    onSettled() {
      cache.invalidateQueries({ key: qk.seoMeta(uuid, locale) })
    },
  })
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd admin && pnpm exec vitest run src/__tests__/seoQueries.spec.ts`
Expected: PASS (3 tests).

- [ ] **Step 6: Type-check**

Run: `cd admin && pnpm run type-check`
Expected: exit 0, no errors.

- [ ] **Step 7: Commit**

```bash
git add admin/src/queries/keys.ts admin/src/queries/seo.ts admin/src/__tests__/seoQueries.spec.ts
git commit -m "feat(admin): SEO meta query layer (useSeoMeta/useSaveSeoMeta)"
```

---

### Task 2: `SeoPanel.vue` component

**Files:**
- Create: `admin/src/pages/content/[type]/[uuid]/components/SeoPanel.vue`
- Test: `admin/src/__tests__/seoPanel.spec.ts`

**Interfaces:**
- Consumes: `useSeoMeta`, `useSaveSeoMeta`, `SeoMeta` from `@/queries/seo`; `useNotify` from `@/composables/useNotify`; `reactive`, `watch` from `vue`.
- Produces: default-exported SFC. Props `{ uuid: string; locale: string; enabled: boolean }`. `data-test` hooks: `seo-panel` (root), `seo-title`, `seo-description`, `seo-og-title`, `seo-og-description`, `seo-og-image`, `seo-twitter-card`, `seo-robots`, `seo-save`.

- [ ] **Step 1: Write the failing test**

Create `admin/src/__tests__/seoPanel.spec.ts` (mount pattern from `collectionsScopes.spec.ts`; `enabled`-capture from `homeAnalyticsStrip.spec.ts`):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'

const saveMock = vi.fn().mockResolvedValue(undefined)
const seoData = ref<Record<string, unknown> | undefined>(undefined)
// Capture the `enabled` arg the panel passes into useSeoMeta (proves the query gate is wired).
const h = vi.hoisted(() => ({ enabledArg: null as unknown }))

vi.mock('@/queries/seo', () => ({
  useSeoMeta: (_uuid: unknown, _locale: unknown, enabled?: unknown) => {
    h.enabledArg = enabled
    return { data: seoData }
  },
  useSaveSeoMeta: () => ({ mutateAsync: saveMock, isLoading: ref(false) }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

import SeoPanel from '@/pages/content/[type]/[uuid]/components/SeoPanel.vue'

const val = (wrapper: ReturnType<typeof mount>, hook: string) =>
  (wrapper.find(`[data-test="${hook}"]`).element as HTMLInputElement).value

describe('SeoPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    saveMock.mockClear()
    seoData.value = undefined
    h.enabledArg = null
  })

  it('hydrates from the override and saves only the 7 writable fields, empties → null', async () => {
    seoData.value = { title: 'Existing', description: '', robots: 'noindex' }
    const wrapper = mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })
    expect(val(wrapper, 'seo-title')).toBe('Existing')

    await wrapper.find('[data-test="seo-save"]').trigger('click')

    expect(saveMock).toHaveBeenCalledTimes(1)
    expect(saveMock).toHaveBeenCalledWith({
      title: 'Existing',
      description: null,
      og_title: null,
      og_description: null,
      og_image: null,
      twitter_card: null,
      robots: 'noindex',
    })
  })

  it('clearing a previously-set field sends null, not empty string', async () => {
    seoData.value = { title: 'Set', robots: 'index' }
    const wrapper = mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })
    await wrapper.find('[data-test="seo-title"]').setValue('')
    await wrapper.find('[data-test="seo-save"]').trigger('click')
    expect((saveMock.mock.calls[0][0] as { title: unknown }).title).toBeNull()
  })

  it('passes the enabled gate through to useSeoMeta', () => {
    mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: false } })
    expect(toValue(h.enabledArg)).toBe(false)
  })

  it('a background refetch does not clobber unsaved edits (hydrate once per key)', async () => {
    seoData.value = { title: 'First', robots: 'index' }
    const wrapper = mount(SeoPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })
    await wrapper.find('[data-test="seo-title"]').setValue('My edit')
    seoData.value = { title: 'Server changed', robots: 'index' } // simulate background refetch
    await wrapper.vm.$nextTick()
    expect(val(wrapper, 'seo-title')).toBe('My edit')
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && pnpm exec vitest run src/__tests__/seoPanel.spec.ts`
Expected: FAIL — cannot resolve `@/pages/content/[type]/[uuid]/components/SeoPanel.vue`.

- [ ] **Step 3: Write `SeoPanel.vue`**

Create `admin/src/pages/content/[type]/[uuid]/components/SeoPanel.vue`:

```vue
<script setup lang="ts">
import { reactive, watch } from 'vue'
import { useSeoMeta, useSaveSeoMeta, type SeoMeta } from '@/queries/seo'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string; locale: string; enabled: boolean }>()
const { success, error: notifyError } = useNotify()

const ROBOTS_OPTIONS = ['index', 'noindex', 'noindex,nofollow'] as const
const TWITTER_OPTIONS = [
  { label: '— none —', value: '' },
  { label: 'Summary', value: 'summary' },
  { label: 'Summary large image', value: 'summary_large_image' },
]

// Local editable form. Empty string means "no override" for the nullable text fields; robots always
// carries one of the three valid enum strings (default 'index').
const form = reactive({
  title: '',
  description: '',
  og_title: '',
  og_description: '',
  og_image: '',
  twitter_card: '',
  robots: 'index',
})

const { data } = useSeoMeta(
  () => props.uuid,
  () => props.locale,
  () => props.enabled,
)
const save = useSaveSeoMeta(props.uuid, props.locale)

// Hydrate ONCE per mount (the parent keys us by `${uuid}-${locale}`, so a locale switch remounts and
// resets this guard). A later background refetch must not overwrite in-progress edits.
let hydrated = false
watch(
  data,
  (d) => {
    if (hydrated || !d) return
    form.title = d.title ?? ''
    form.description = d.description ?? ''
    form.og_title = d.og_title ?? ''
    form.og_description = d.og_description ?? ''
    form.og_image = d.og_image ?? ''
    form.twitter_card = d.twitter_card ?? ''
    form.robots = d.robots ?? 'index'
    hydrated = true
  },
  { immediate: true },
)

// '' → null so a blank field clears the override rather than storing empty meta.
const nn = (v: string): string | null => (v.trim() === '' ? null : v)

async function onSave() {
  const payload: SeoMeta = {
    title: nn(form.title),
    description: nn(form.description),
    og_title: nn(form.og_title),
    og_description: nn(form.og_description),
    og_image: nn(form.og_image),
    twitter_card: form.twitter_card === '' ? null : form.twitter_card,
    robots: form.robots,
  }
  try {
    await save.mutateAsync(payload)
    success('SEO saved')
  } catch (e) {
    notifyError(e, 'Couldn’t save SEO')
  }
}
</script>

<template>
  <UCard :ui="{ root: 'ring-0' }" data-test="seo-panel">
    <UCollapsible :default-open="true" class="w-full">
      <UButton
        class="w-full justify-between"
        color="neutral"
        variant="ghost"
        label="SEO"
        trailing-icon="i-lucide-chevron-down"
        :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform' }"
      />
      <template #content>
        <div class="space-y-4 pt-4">
          <UFormField label="Title">
            <UInput v-model="form.title" data-test="seo-title" class="w-full" />
          </UFormField>

          <UFormField label="Description">
            <UTextarea v-model="form.description" :rows="2" data-test="seo-description" class="w-full" />
          </UFormField>

          <UFormField label="Robots">
            <USelect v-model="form.robots" :items="ROBOTS_OPTIONS" data-test="seo-robots" class="w-full" />
          </UFormField>

          <UFormField label="Twitter card">
            <USelect
              v-model="form.twitter_card"
              :items="TWITTER_OPTIONS"
              data-test="seo-twitter-card"
              class="w-full"
            />
          </UFormField>

          <UCollapsible :default-open="false">
            <UButton
              class="w-full justify-between"
              color="neutral"
              variant="ghost"
              size="sm"
              label="Open Graph"
              trailing-icon="i-lucide-chevron-down"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform' }"
            />
            <template #content>
              <div class="space-y-3 pt-3">
                <UFormField label="OG title">
                  <UInput v-model="form.og_title" data-test="seo-og-title" class="w-full" />
                </UFormField>
                <UFormField label="OG description">
                  <UTextarea
                    v-model="form.og_description"
                    :rows="2"
                    data-test="seo-og-description"
                    class="w-full"
                  />
                </UFormField>
                <UFormField label="OG image URL">
                  <UInput v-model="form.og_image" type="url" data-test="seo-og-image" class="w-full" />
                </UFormField>
              </div>
            </template>
          </UCollapsible>

          <div class="flex justify-end">
            <UButton :loading="save.isLoading.value" data-test="seo-save" @click="onSave">
              Save
            </UButton>
          </div>
        </div>
      </template>
    </UCollapsible>
  </UCard>
</template>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin && pnpm exec vitest run src/__tests__/seoPanel.spec.ts`
Expected: PASS (4 tests).

Note (Nuxt UI): `UInput`/`UTextarea` forward `data-test` onto the inner `<input>`/`<textarea>`, so `find('[data-test="seo-title"]').element` is the input and `.setValue` works. Do **not** try to drive `USelect` options in tests (portal-rendered) — the payload assertions cover `robots`/`twitter_card` via their hydrated/default values.

- [ ] **Step 5: Type-check**

Run: `cd admin && pnpm run type-check`
Expected: exit 0, no errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/content/[type]/[uuid]/components/SeoPanel.vue admin/src/__tests__/seoPanel.spec.ts
git commit -m "feat(admin): SeoPanel — collapsible per-entry SEO overrides editor"
```

---

### Task 3: Entry-editor wiring + render gate

**Files:**
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue` (import `SeoPanel` + capability store; render below `PublishPanel`)
- Test: `admin/src/__tests__/seoPanelGating.spec.ts`

**Interfaces:**
- Consumes: `SeoPanel.vue` (Task 2); `useCapabilitiesStore` from `@/stores/capabilities` (`isEnabled(id: string): boolean`); `computed` from `vue`.
- Produces: `<SeoPanel v-if="seoEnabled" :key="\`${uuid}-${locale}\`" :uuid="uuid" :locale="locale" :enabled="seoEnabled" />` in the right column.

- [ ] **Step 1: Write the failing test**

Create `admin/src/__tests__/seoPanelGating.spec.ts` (full-page mount with children stubbed + queries mocked, mirroring `homeAnalyticsStrip.spec.ts`):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const seoEnabled = ref(true)

vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({
    isEnabled: (id: string) => (id === 'lemma.seo' ? seoEnabled.value : true),
  }),
}))
// SeoPanel's own queries (it still mounts when the render gate is open).
vi.mock('@/queries/seo', () => ({
  useSeoMeta: () => ({ data: ref({}) }),
  useSaveSeoMeta: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
// The queries index.vue itself calls.
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([{ slug: 'blog', schema: [] }]) }),
}))
vi.mock('@/queries/drafts', () => ({
  useDraft: () => ({ data: ref({ fields: {}, lock_version: 0 }), status: ref('success') }),
  useSaveDraft: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/entries', () => ({
  useEntryLocales: () => ({ data: ref([]) }),
  useCreateLocaleDraft: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/queries/locales', () => ({ useLocales: () => ({ data: ref([]) }) }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/runtime/config', () => ({
  runtimeConfig: { defaultLocale: 'en', apiBase: '/v1/admin' },
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { type: 'blog', uuid: 'e-1' }, query: {} }),
}))

import EntryEditor from '@/pages/content/[type]/[uuid]/index.vue'

// Stub the heavy sibling children so the mount stays focused on the SEO render gate.
const stubs = {
  PublishPanel: true,
  LocaleSwitcher: true,
  LocaleRoutesModal: true,
  BulkLocaleMenu: true,
  FieldEditor: true,
}

describe('SeoPanel render gate (lemma.seo capability)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    seoEnabled.value = true
  })

  it('renders SeoPanel when lemma.seo is enabled', () => {
    const wrapper = mount(EntryEditor, { global: { stubs } })
    expect(wrapper.find('[data-test="seo-panel"]').exists()).toBe(true)
  })

  it('omits SeoPanel when lemma.seo is disabled', () => {
    seoEnabled.value = false
    const wrapper = mount(EntryEditor, { global: { stubs } })
    expect(wrapper.find('[data-test="seo-panel"]').exists()).toBe(false)
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && pnpm exec vitest run src/__tests__/seoPanelGating.spec.ts`
Expected: FAIL — `[data-test="seo-panel"]` not found even when enabled (SeoPanel not yet wired into the page).

- [ ] **Step 3: Wire `SeoPanel` into `index.vue`**

In `admin/src/pages/content/[type]/[uuid]/index.vue`:

**(a)** Add imports. After the existing `import PublishPanel from './components/PublishPanel.vue'` line, add:

```ts
import SeoPanel from './components/SeoPanel.vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
```

Ensure `computed` is imported from `vue` (it already is — line 1).

**(b)** Add the capability flag. After `const uuid = computed(() => String(route.params.uuid))` (line 22), add:

```ts
const caps = useCapabilitiesStore()
const seoEnabled = computed(() => caps.isEnabled('lemma.seo'))
```

**(c)** Render the panel. In the right-column `<div class="lg:min-h-0 lg:w-96 …">`, directly after the `<PublishPanel … />` line, add:

```vue
        <SeoPanel
          v-if="seoEnabled"
          :key="`${uuid}-${locale}`"
          class="mt-6"
          :uuid="uuid"
          :locale="locale"
          :enabled="seoEnabled"
        />
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin && pnpm exec vitest run src/__tests__/seoPanelGating.spec.ts`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full admin test suite + type-check**

Run: `cd admin && pnpm test && pnpm run type-check`
Expected: all specs pass; type-check exit 0.

- [ ] **Step 6: Commit**

```bash
git add "admin/src/pages/content/[type]/[uuid]/index.vue" admin/src/__tests__/seoPanelGating.spec.ts
git commit -m "feat(admin): render capability-gated SeoPanel in the entry editor"
```

---

## Self-Review

**1. Spec coverage:**
- §1 Scope (SeoPanel editing the override row, all writable fields) → Tasks 1–3. Out-of-scope items (resolved-value preview, bulk, JSON-LD, standalone page) correctly excluded.
- §2 Placement (right column below PublishPanel) → Task 3(c). Collapsible panel + nested OG collapsed-by-default → Task 2 (`UCollapsible :default-open="true"` outer, `:default-open="false"` OG). Two-layer fail-closed gating → render gate Task 3, query gate Tasks 1–2 (`enabled`).
- §3.1 typed-client/schema regen → **deliberately replaced** by `authFetch` (see Global Constraints; flagged for user). §3.2 query layer (fetch/use/save + `qk.seoMeta`) → Task 1. §3.3 SeoPanel props `{ uuid, locale, enabled }`, controls, save → Task 2. §3.4 index.vue wiring → Task 3.
- §4 empty→null for the 6 text fields, robots enum always sent, PUT writable-only → Task 2 `onSave`.
- §5 remount-per-key + hydrate-once guard → Task 3 `:key`, Task 2 `hydrated` flag.
- §6 error handling (notify on save failure; GET `{}` → empty form + robots `index`) → Task 2.
- §7 tests (query shape; renders+saves; empty normalization; query gate; render gate; no dirty overwrite) → Task 1 spec + Task 2 spec (4 cases) + Task 3 spec.
- §8 deliverables → all covered **except** `schema.ts` regen (intentionally dropped — `authFetch`).

**2. Placeholder scan:** No TBD/TODO; every code step has complete file contents or exact edits; commands have expected output. Clean.

**3. Type consistency:** `SeoMeta` shape and the 7 field names are identical across `seo.ts`, `SeoPanel.vue`, and both specs. `useSeoMeta(uuid, locale, enabled?)` / `useSaveSeoMeta(uuid, locale)` / `qk.seoMeta(uuid, locale)` signatures match between Task 1 (produces) and Tasks 2–3 (consume). `data-test` hook names match between `SeoPanel.vue` and `seoPanel.spec.ts` (`seo-panel`, `seo-title`, `seo-save`, …).

**Note for the user (spec deviation):** §3.1/§3.2 specify the typed `client` + a `pnpm gen:api` regen of `schema.ts`. During planning I found the admin SEO operation is under-typed in `openapi.json` (`query?: never`, `requestBody?: never`, `content?: never`), so the typed client would need casts on locale-query, body, and response. This plan uses `authFetch` instead — the codebase's documented pattern for exactly this situation (`queries/analytics.ts`), which also drops the schema-regen step. The user-facing behavior and the component contract are unchanged. **If the SEO backend OpenAPI is corrected later, `queries/seo.ts` can be migrated to the typed client without changing `SeoPanel`.** Flag at handoff.
