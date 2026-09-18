// Preservation is the editor's; persistence is the server's (container-layout spec §12.5). A class
// holding an unsupported value or an unknown path cannot be saved until it is repaired — the
// validator is not relaxed — so what the page owes the author is the draft, intact, and an error
// that names the field. The payloads are the ones the PHP suite runs through the real controller.
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { StyleClass, StyleClassList } from '@/queries/styleClasses'
import { toApiError } from '@/api/errors'
import { classEditorSchema } from './helpers/classEditorSchema'

const list = ref<StyleClassList>({ generation: 1, classes: [] })
const mutate = vi.fn()
const notify = { success: vi.fn(), error: vi.fn() }

vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({ data: list, status: ref('success'), refetch: vi.fn() }),
  useStyleClassUsage: () => ({ data: ref(null), refetch: vi.fn() }),
  useStyleClassJob: () => ({ data: ref(null), refetch: vi.fn() }),
  useStyleClassMutations: () => ({
    create: { mutateAsync: mutate, isLoading: ref(false) },
    update: { mutateAsync: mutate, isLoading: ref(false) },
    archive: { mutateAsync: vi.fn(), isLoading: ref(false) },
    queueJob: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(classEditorSchema()) }),
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import EditPage from '@/pages/settings/style-classes/[id].vue'
import NewPage from '@/pages/settings/style-classes/new.vue'
import { describeSaveErrors } from '@/pages/settings/style-classes/components/saveErrors'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'

type Style = Record<string, unknown>
const PAYLOADS = JSON.parse(
  readFileSync(
    join(__dirname, '../../../tests/fixtures/style-classes/editor-payloads.json'),
    'utf8',
  ),
) as Record<'valid' | 'invalid_value' | 'unknown_path', Style>
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T
const token = (value: string) => ({ type: 'token', value })
const choice = (value: string) => ({ type: 'choice', value })

/** A refusal exactly as the server sends it (`Response::validation`), through the real normaliser. */
const refusal = (details: Record<string, string>) =>
  toApiError({ success: false, message: 'Validation failed', error: { code: 422, details } }, {
    status: 422,
  } as Response)

const klass = (style: Style): StyleClass => ({
  id: 'klass0000001',
  version: 4,
  name: 'Card band',
  description: null,
  style,
  archived: false,
  archived_at: null,
  locked_by_job: null,
  created_at: null,
  updated_at: null,
})

async function mountEdit(style: Style) {
  list.value = { generation: 1, classes: [klass(style)] }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings/style-classes/:id', component: EditPage },
      { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
    ],
  })
  await router.push('/settings/style-classes/klass0000001')
  await router.isReady()
  const w = mount(EditPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return w
}
type Page = Awaited<ReturnType<typeof mountEdit>>

async function save(w: Page) {
  await w.find('[data-test="style-class-save"]').trigger('click')
  await flushPromises()
  ;(document.body.querySelector('[data-test="style-class-save-confirm"]') as HTMLElement).click()
  await flushPromises()
}
async function openLayout(w: Page) {
  const tab = w
    .find('[data-test="style-class-tabs"]')
    .findAll('button[role="tab"]')
    .find((b) => b.text() === 'Layout')!
  await tab.trigger('mousedown', { button: 0 })
  await tab.trigger('click')
  await flushPromises()
}
/** Set padding-top, alone, to xl at base: an edit that has nothing to do with layout. */
async function editPadding(w: Page) {
  const padding = w.find('[data-test="box-padding"]')
  if (padding.find('[data-test="box-link"]').attributes('aria-pressed') === 'true')
    await padding.find('[data-test="box-link"]').trigger('click')
  await padding.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
  await padding.find('[data-test="box-panel"] [data-test="token-spacing.xl"]').trigger('click')
  await flushPromises()
}
const sentStyle = () =>
  (mutate.mock.calls[mutate.mock.calls.length - 1]![0] as { style: Style }).style
const errors = (w: Page) => w.find('[data-test="style-class-save-errors"]')

beforeEach(() => {
  mutate.mockReset()
  notify.success.mockReset()
  notify.error.mockReset()
  localStorage.clear()
  resetFolds()
  document.body.innerHTML = ''
})

describe('what a refusal is turned into', () => {
  it("names the property and the breakpoint the key carries, and keeps the server's words", () => {
    expect(
      describeSaveErrors({
        'style.layout.display.md': 'must be one of flex, grid',
        'style.layout.overflow': 'is not responsive',
        'style.spacing.padding.top.base': 'unknown token "spacing.huge"',
      }),
    ).toEqual([
      {
        key: 'style.layout.display.md',
        label: 'Layout',
        breakpoint: 'md',
        message: 'must be one of flex, grid',
      },
      {
        key: 'style.layout.overflow',
        label: 'Overflow',
        breakpoint: null,
        message: 'is not responsive',
      },
      {
        key: 'style.spacing.padding.top.base',
        label: 'Spacing padding top',
        breakpoint: 'base',
        message: 'unknown token "spacing.huge"',
      },
    ])
  })

  it('shows a key it cannot map by the key itself: an unknown path, a field that is not a style', () => {
    expect(
      describeSaveErrors({
        'style.layout.nonesuch': 'unknown style property',
        name: 'is already in use',
      }),
    ).toEqual([
      {
        key: 'style.layout.nonesuch',
        label: 'style.layout.nonesuch',
        breakpoint: null,
        message: 'unknown style property',
      },
      { key: 'name', label: 'name', breakpoint: null, message: 'is already in use' },
    ])
  })
})

describe('save and reload, valid declarations', () => {
  it('sends the edited style and, reloaded, shows each declaration in the state it was stored in', async () => {
    mutate.mockImplementation(async (input: { style: Style }) => ({
      ...klass(input.style),
      version: 5,
    }))
    const w = await mountEdit(clone(PAYLOADS.valid))
    await editPadding(w)
    await save(w)

    const expected = {
      ...clone(PAYLOADS.valid),
      spacing: { padding: { top: { base: token('spacing.xl') } } },
    }
    expect(sentStyle()).toEqual(expected)
    expect(errors(w).exists()).toBe(false)
    expect(notify.success).toHaveBeenCalled()
    w.unmount()

    // Reloaded from what was sent.
    const reloaded = await mountEdit(clone(sentStyle()))
    await openLayout(reloaded)
    const layout = reloaded.find('[data-test="class-layout-tab"]')
    await reloaded.find('[data-test="class-layout-breakpoint-md"]').trigger('click')
    const state = (path: string) =>
      layout.find(`[data-test="style-field-${path}"] [data-test="style-state"]`).text()
    expect(state('layout.columns')).toBe('Theme default, set here') // the md reset is still a reset
    expect(state('layout.display')).toBe('Inherited from base')
    expect(state('width')).toBe('Set')
    expect(state('layout.overflow')).toBe('Set')
    expect(
      layout
        .find('[data-test="style-field-layout.overflow"] [data-test="style-all-sizes"]')
        .exists(),
    ).toBe(true)
    reloaded.unmount()
  })
})

describe('rejected save, repair, save', () => {
  it('keeps the draft, names the field, and the repaired draft then saves with the earlier edit', async () => {
    mutate.mockRejectedValueOnce(
      refusal({ 'style.layout.display.md': 'must be one of flex, grid' }),
    )
    const w = await mountEdit(clone(PAYLOADS.invalid_value))
    await w.find('[data-test="style-class-name"]').setValue('Card band, renamed')
    await editPadding(w)
    await save(w)

    // Refused: said where the author is looking, with the field, its breakpoint and the reason.
    const line = errors(w).find('[data-test="style-class-save-error-style.layout.display.md"]')
    expect(line.text()).toContain('Layout')
    expect(line.text()).toContain('md')
    expect(line.text()).toContain('must be one of flex, grid')
    expect(notify.error).toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="style-class-save-confirm"]')).toBeNull() // dialog closed

    // The draft is intact: what was sent is still what the form holds — the edit AND the block.
    const draft = {
      layout: { display: { md: choice('block') } },
      spacing: { padding: { top: { base: token('spacing.xl') } } },
    }
    expect(sentStyle()).toEqual(draft)
    expect((w.find('[data-test="style-class-name"]').element as HTMLInputElement).value).toBe(
      'Card band, renamed',
    )
    expect(
      w.find('[data-test="box-cell-spacing.padding.top"] [data-test="box-cell-value"]').text(),
    ).toBe('xl')
    // …and the way out is on the same page.
    const attention = w.find('[data-test="style-class-needs-attention"]')
    expect(attention.find('[data-test="invalid-choice-layout.display"]').text()).toContain('block')

    await attention.find('[data-test="invalid-choice-replace"]').trigger('click')
    await flushPromises()
    mutate.mockImplementation(async (input: { style: Style }) => ({
      ...klass(input.style),
      version: 5,
    }))
    await save(w)

    expect(sentStyle()).toEqual({
      layout: { display: { md: choice('flex') } },
      spacing: { padding: { top: { base: token('spacing.xl') } } },
    })
    expect(mutate.mock.calls[mutate.mock.calls.length - 1]![0]).toMatchObject({
      version: 4, // the refusal moved no version
      name: 'Card band, renamed',
    })
    expect(errors(w).exists()).toBe(false)
    expect(notify.success).toHaveBeenCalled()
    w.unmount()
  })

  it('an unknown path is refused by its raw key, and the draft still carries it', async () => {
    mutate.mockRejectedValue(refusal({ 'style.layout.nonesuch': 'unknown style property' }))
    const w = await mountEdit(clone(PAYLOADS.unknown_path))
    await editPadding(w)
    await save(w)
    expect(errors(w).text()).toContain('style.layout.nonesuch')
    expect(errors(w).text()).toContain('unknown style property')
    expect((sentStyle().layout as Style).nonesuch).toEqual(
      (PAYLOADS.unknown_path.layout as Style).nonesuch,
    )
    w.unmount()
  })

  it('the errors go when the next attempt starts, not only when it succeeds', async () => {
    mutate.mockRejectedValueOnce(
      refusal({ 'style.layout.display.md': 'must be one of flex, grid' }),
    )
    const w = await mountEdit(clone(PAYLOADS.invalid_value))
    await save(w)
    expect(errors(w).exists()).toBe(true)

    let finish: (value: unknown) => void = () => {}
    mutate.mockImplementation(() => new Promise((resolve) => (finish = resolve)))
    await save(w)
    expect(errors(w).exists()).toBe(false) // the old refusal is not shown over a save in flight
    finish(klass({}))
    await flushPromises()
    w.unmount()
  })

  it('a version conflict is still what it was: no field errors, the form kept', async () => {
    mutate.mockRejectedValue(
      toApiError(
        { error: { details: { code: 'STYLE_CLASS_VERSION_CONFLICT', current_version: 9 } } },
        { status: 409 } as Response,
      ),
    )
    const w = await mountEdit(clone(PAYLOADS.valid))
    await save(w)
    expect(errors(w).exists()).toBe(false)
    expect(w.text()).toContain('Version 9')
    w.unmount()
  })
})

describe('a new class', () => {
  it('a refused create keeps what was typed and names the field', async () => {
    mutate.mockRejectedValue(refusal({ name: 'is already in use' }))
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/settings/style-classes/new', component: NewPage },
        { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
      ],
    })
    await router.push('/settings/style-classes/new')
    await router.isReady()
    const w = mount(NewPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    await w.find('[data-test="style-class-name"]').setValue('Card band')
    await w.find('[data-test="style-class-create"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="style-class-save-errors"]').text()).toContain('is already in use')
    expect((w.find('[data-test="style-class-name"]').element as HTMLInputElement).value).toBe(
      'Card band',
    )
    w.unmount()
  })
})
