import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { createRouter, createMemoryHistory } from 'vue-router'
import { ApiError } from '@/api/errors'

const authFetch = vi.hoisted(() => vi.fn())
const refreshUntilChanged = vi.hoisted(() => vi.fn())
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ refreshUntilChanged }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

type Json = Record<string, unknown>

function commerce(over: Json = {}): Json {
  return {
    id: 'thallo.commerce',
    label: 'Commerce',
    description: 'A shop.',
    requires: [],
    owning_package: 'glueful/commerce',
    requested: false,
    available: true,
    reason: null,
    remedy: null,
    effective: false,
    management: 'activation',
    activation: null,
    application_files_writable: true,
    engine_enabled: true,
    destination: null,
    misconfigured: null,
    copy: {
      turn_on:
        'This prepares your store and adds products, orders, shop blocks and templates. Your existing content is kept.',
      turn_off: "Commerce's pages, blocks and menu are hidden.",
      links: [{ label: 'Products', to: '/commerce/products' }],
    },
    ...over,
  }
}

function record(over: Json = {}): Json {
  return {
    capability: 'thallo.commerce',
    generation: 1,
    status: 'preparing',
    steps_done: ['mark_preparing'],
    next_step: 'enable_engine',
    failed_step: null,
    error: null,
    remedy: null,
    workspaces: {},
    result: {},
    actor: 'user00000001',
    updated_at: '2026-10-02 12:00:00',
    ...over,
  }
}

function conflict(reason: string): ApiError {
  return new ApiError('Conflict', 409, {}, { error: { details: { reason } } })
}

/** Routes authFetch by method and path; records every call. */
function serve(handlers: Record<string, (body: Json) => unknown>) {
  const calls: { key: string; body: Json }[] = []
  authFetch.mockImplementation(async (url: string, init: RequestInit = {}) => {
    const key = `${init.method ?? 'GET'} ${url}`
    const body = typeof init.body === 'string' ? (JSON.parse(init.body) as Json) : {}
    calls.push({ key, body })
    const handler = handlers[key]
    if (!handler) return { data: {} }
    return handler(body)
  })
  return calls
}

const manage =
  (...caps: Json[]) =>
  () => ({ data: { capabilities: caps } })

function router() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:p(.*)*', component: { template: '<div />' } }],
  })
}

async function mountExtensions() {
  const { default: ExtensionsPage } = await import('@/pages/extensions/index.vue')
  const w = mount(ExtensionsPage, {
    global: { plugins: [createPinia(), PiniaColada, router()] },
    attachTo: document.body,
  })
  await flushPromises()
  return w
}

const card = () =>
  document.body.querySelector('[data-test="capability-thallo.commerce"]') as HTMLElement
const inCard = (selector: string) => card().querySelector(selector) as HTMLElement | null
const click = async (el: Element | null) => {
  expect(el).not.toBeNull()
  ;(el as HTMLElement).click()
  await flushPromises()
}

const START = 'POST /v1/admin/capabilities/thallo.commerce/activation'
const CONTINUE = 'POST /v1/admin/capabilities/thallo.commerce/activation/continue'
const MANAGE = 'GET /v1/admin/capabilities/manage'

describe('the Extensions page', () => {
  beforeEach(() => {
    authFetch.mockReset()
    refreshUntilChanged.mockReset()
  })

  it('confirm then turn on calls start then continue until continue is false', async () => {
    let continues = 0
    const calls = serve({
      [MANAGE]: manage(commerce()),
      [START]: () => ({ data: { activation: record(), continue: true } }),
      [CONTINUE]: () => {
        continues++
        return continues === 1
          ? { data: { activation: record({ next_step: 'verify_boot' }), continue: true } }
          : {
              data: {
                activation: record({ status: 'succeeded', result: { blocks_created: 18 } }),
                continue: false,
              },
            }
      },
    })
    await mountExtensions()

    await click(inCard('[role="switch"]'))
    expect(document.body.querySelector('[data-test="capability-confirm"]')).not.toBeNull()
    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('Turn on Commerce?')
    expect(dialog?.textContent).toContain('Your existing content is kept.')
    await click(document.body.querySelector('[data-test="capability-confirm-turn-on"]'))

    const keys = calls.map((c) => c.key).filter((k) => k !== MANAGE)
    expect(keys).toEqual([START, CONTINUE, CONTINUE])
    expect(calls.filter((c) => c.key === CONTINUE).map((c) => c.body.generation)).toEqual([1, 1])
    expect(card().textContent).toContain('Commerce is on.')
    expect(refreshUntilChanged).toHaveBeenCalled()
  })

  it('a failed step shows its message and Retry continues the same generation', async () => {
    const calls = serve({
      [MANAGE]: manage(commerce()),
      [START]: () => ({
        data: {
          activation: record({
            status: 'failed',
            generation: 4,
            failed_step: 'seed_blocks',
            error: "The blocks couldn't be added in these workspaces: a.",
          }),
          continue: false,
        },
      }),
      [CONTINUE]: () => ({
        data: { activation: record({ generation: 4, status: 'succeeded' }), continue: false },
      }),
    })
    await mountExtensions()
    await click(inCard('[role="switch"]'))
    await click(document.body.querySelector('[data-test="capability-confirm-turn-on"]'))

    expect(card().textContent).toContain("The blocks couldn't be added")
    await click(inCard('[data-test="capability-retry"]'))
    expect(calls.find((c) => c.key === CONTINUE)?.body.generation).toBe(4)
    expect(card().textContent).toContain('Commerce is on.')
  })

  it('superseded continue shows off', async () => {
    serve({
      [MANAGE]: manage(commerce({ activation: record({ generation: 2 }) })),
      [CONTINUE]: () => {
        throw conflict('superseded')
      },
    })
    await mountExtensions()
    await click(inCard('[data-test="capability-continue"]'))

    expect(card().textContent).toContain('A newer decision')
    expect(inCard('[role="switch"]')).not.toBeNull()
    expect(inCard('[data-test="capability-continue"]')).toBeNull()
  })

  it('read-only host offers the prepare command, no button', async () => {
    serve({
      [MANAGE]: manage(commerce({ application_files_writable: false, engine_enabled: false })),
    })
    await mountExtensions()
    expect(card().textContent).toContain(
      'php glueful thallo:capabilities:enable thallo.commerce --prepare',
    )
    expect(inCard('[role="switch"]')).toBeNull()
    expect(card().querySelector('button')).toBeNull()
  })

  it('an open operation on load offers Continue', async () => {
    serve({ [MANAGE]: manage(commerce({ activation: record({ next_step: 'verify_boot' }) })) })
    await mountExtensions()
    expect(inCard('[data-test="capability-continue"]')).not.toBeNull()
    expect(inCard('[data-test="capability-cancel"]')).not.toBeNull()
  })

  it('the summary reads the result count', async () => {
    serve({
      [MANAGE]: manage(
        commerce({
          effective: true,
          requested: true,
          activation: record({
            status: 'succeeded',
            result: { blocks_created: 7, grants: { superuser: 2, administrator: 1 } },
          }),
        }),
      ),
    })
    await mountExtensions()
    expect(card().textContent).toContain('Added 7 blocks')
    expect(card().textContent).toContain('3 new permissions')
  })

  it('an engine disabled outside Thallo shows unavailable with the remedy, and turning on activates', async () => {
    const calls = serve({
      [MANAGE]: manage(
        commerce({
          requested: true,
          available: false,
          effective: false,
          engine_enabled: false,
          reason: 'glueful/commerce is installed but not enabled.',
          remedy: 'Managed by Commerce: turn it on in Extensions.',
          activation: record({ status: 'succeeded', result: { blocks_created: 18 } }),
        }),
      ),
      [START]: () => ({ data: { activation: record({ generation: 2 }), continue: false } }),
    })
    await mountExtensions()

    expect(card().textContent).toContain('glueful/commerce is installed but not enabled.')
    expect(card().textContent).toContain('Managed by Commerce: turn it on in Extensions.')
    expect(card().textContent).not.toContain('Commerce is on.')
    expect(inCard('[role="switch"]')?.getAttribute('aria-checked')).toBe('false')
    await click(inCard('[role="switch"]'))
    await click(document.body.querySelector('[data-test="capability-confirm-turn-on"]'))
    expect(calls.some((c) => c.key === START)).toBe(true)
  })

  it('an adopted capability says only that it is on', async () => {
    serve({
      [MANAGE]: manage(
        commerce({
          requested: true,
          effective: true,
          activation: record({ status: 'succeeded', result: { adopted: true } }),
        }),
      ),
    })
    await mountExtensions()
    expect(inCard('[data-test="capability-summary"]')?.textContent?.trim()).toBe('Commerce is on.')
  })

  it('a feature already on without an activation record says only that it is on', async () => {
    serve({ [MANAGE]: manage(commerce({ requested: true, effective: true, activation: null })) })
    await mountExtensions()
    expect(inCard('[data-test="capability-summary"]')?.textContent?.trim()).toBe('Commerce is on.')
  })

  it('a misconfigured capability says why and offers nothing', async () => {
    serve({
      [MANAGE]: manage(
        commerce({
          available: false,
          reason: 'Misconfigured: thallo.commerce is declared differently by a and b.',
          misconfigured: 'Misconfigured: thallo.commerce is declared differently by a and b.',
        }),
      ),
    })
    await mountExtensions()
    expect(card().textContent).toContain('declared differently')
    expect(card().querySelector('button')).toBeNull()
    expect(inCard('[role="switch"]')).toBeNull()
  })

  it('the confirmation copy comes from the capability', async () => {
    serve({
      [MANAGE]: manage(
        commerce({ copy: { turn_on: 'Bespoke words from the pack.', turn_off: null, links: [] } }),
      ),
    })
    await mountExtensions()
    await click(inCard('[role="switch"]'))
    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('Bespoke words from the pack.')
  })

  it('an external flow links to its destination', async () => {
    serve({
      [MANAGE]: manage({
        ...commerce({
          id: 'thallo.tenancy',
          label: 'Multi-tenancy',
          management: 'external_flow',
          destination: { path: '/settings/workspaces', label: 'Settings › Workspaces' },
          copy: null,
        }),
      }),
    })
    await mountExtensions()
    const tenancy = document.body.querySelector(
      '[data-test="capability-thallo.tenancy"]',
    ) as HTMLElement
    expect(tenancy.querySelector('a[href="/settings/workspaces"]')).not.toBeNull()
    expect(tenancy.querySelector('[role="switch"]')).toBeNull()
  })

  it('required package shows Required by Thallo and no switch', async () => {
    serve({
      'GET /v1/admin/extensions': () => ({
        data: {
          extensions: [
            {
              name: 'glueful/aegis',
              provider: 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
              version: '1.0.0',
              requires_extensions: [],
              enabled: true,
              schema_state: 'ready',
              schema_reasons: [],
              cli_command: null,
              management: {
                class: 'required',
                capability: null,
                reason: 'Required by Thallo.',
                link: null,
              },
            },
          ],
        },
      }),
    })
    const { default: InstalledPackages } =
      await import('@/pages/extensions/components/InstalledPackages.vue')
    const w = mount(InstalledPackages, {
      global: { plugins: [createPinia(), PiniaColada, router()] },
      attachTo: document.body,
    })
    await flushPromises()
    await w.find('[data-test="package-glueful/aegis"]').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('Required by Thallo')
    expect(w.find('[data-test="package-toggle"]').exists()).toBe(false)
  })

  it('misconfigured package shows Misconfigured, its reason, and no switch or command', async () => {
    const reason =
      "Misconfigured: acme.contested is one of several capabilities that claim glueful/media (acme.a, acme.b). Fix the declarations; until then it can't be switched."
    serve({
      'GET /v1/admin/extensions': () => ({
        data: {
          extensions: [
            {
              name: 'glueful/media',
              provider: 'Glueful\\Extensions\\Media\\MediaServiceProvider',
              version: '1.0.0',
              requires_extensions: [],
              enabled: true,
              schema_state: 'ready',
              schema_reasons: [],
              cli_command: null,
              management: { class: 'misconfigured', capability: 'acme.a', reason, link: null },
            },
          ],
        },
      }),
    })
    const { default: InstalledPackages } =
      await import('@/pages/extensions/components/InstalledPackages.vue')
    const w = mount(InstalledPackages, {
      global: { plugins: [createPinia(), PiniaColada, router()] },
      attachTo: document.body,
    })
    await flushPromises()
    const row = w.find('[data-test="package-glueful/media"]')
    expect(row.text()).toContain('Misconfigured')
    await row.trigger('click')
    await flushPromises()

    expect(w.text()).toContain('one of several capabilities that claim glueful/media')
    expect(w.find('[data-test="package-toggle"]').exists()).toBe(false)
    expect(w.text()).not.toContain('extensions:disable')
  })

  it('the Extensions page opens on Capabilities', async () => {
    serve({ [MANAGE]: manage(commerce()) })
    await mountExtensions()
    expect(document.body.querySelector('h1')?.textContent).toBe('Extensions')
    expect(card()).not.toBeNull()
    expect(document.body.textContent).toContain('Installed')
  })
})
