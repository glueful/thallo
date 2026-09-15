import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

const envelope = {
  success: true,
  message: 'Block instance.',
  data: {
    block: { type: 'section', data: { background: 'none', content: [], links: [] }, settings: {} },
    starter: {
      title: 'Welcome',
      content: [
        { type: 'heading', data: { text: 'Hi' } },
        { type: 'columns', data: { col_1: [{ type: 'button', data: { label: 'Go' } }] } },
      ],
    },
  },
}

// The api client captures globalThis.fetch at creation: stub first, import after (the
// collections.spec.ts convention).
describe('block factory query layer (visual builder spec §5.5)', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('posts to the instance endpoint and caches the result per slug for the session', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockImplementation(() => Promise.resolve(jsonResponse(envelope)))
    const { useBlockFactory } = await import('./blockFactory')
    const factory = useBlockFactory()

    const first = await factory.make('section')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as [string | Request, RequestInit | undefined]
    expect(String(url instanceof Request ? url.url : url)).toContain(
      '/v1/admin/block-types/section/instance',
    )
    expect((init?.method ?? (url as Request).method).toUpperCase()).toBe('POST')
    expect(first.block.data).toEqual({ background: 'none', content: [], links: [] })
    expect(first.starter.title).toBe('Welcome')

    await useBlockFactory().make('section') // another caller, same session cache
    expect(fetchMock).toHaveBeenCalledTimes(1)
    await factory.make('heading')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('a failed fetch is not cached: the next make asks again', async () => {
    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
    fetchMock.mockResolvedValueOnce(jsonResponse({ success: false, message: 'Nope' }, 404))
    fetchMock.mockResolvedValueOnce(jsonResponse(envelope))
    const { useBlockFactory } = await import('./blockFactory')
    await expect(useBlockFactory().make('section')).rejects.toBeTruthy()
    const made = await useBlockFactory().make('section')
    expect(made.block.type).toBe('section')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('instance() merges the starter over the defaults and mints ids for every nested starter block', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockImplementation(() =>
      Promise.resolve(jsonResponse(envelope)),
    )
    const { useBlockFactory } = await import('./blockFactory')
    const block = await useBlockFactory().instance('section')

    expect(block.type).toBe('section')
    expect(block.id).toMatch(/^[a-z0-9]{12}$/)
    expect(block.settings).toEqual({})
    // Defaults the starter did not name survive; the starter wins where it speaks.
    expect(block.data.background).toBe('none')
    expect(block.data.links).toEqual([])
    expect(block.data.title).toBe('Welcome')
    const content = block.data.content as {
      id: string
      type: string
      data: Record<string, unknown>
    }[]
    expect(content.map((b) => b.type)).toEqual(['heading', 'columns'])
    const nested = (content[1]!.data.col_1 as { id: string; type: string; settings: unknown }[])[0]!
    expect(nested.type).toBe('button')
    expect(nested.settings).toEqual({})
    const ids = [block.id, content[0]!.id, content[1]!.id, nested.id]
    ids.forEach((id) => expect(id).toMatch(/^[a-z0-9]{12}$/))
    expect(new Set(ids).size).toBe(4)

    // Every instance is a fresh block: ids never repeat across calls.
    const again = await useBlockFactory().instance('section')
    expect(again.id).not.toBe(block.id)
    expect((again.data.content as { id: string }[])[0]!.id).not.toBe(content[0]!.id)
  })
})
