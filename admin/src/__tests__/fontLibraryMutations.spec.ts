import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

// Changing the font library (block typeface plan Task 11): each change goes to its endpoint, and a
// change that succeeds tells the other tabs — their open stages reload — while one that fails does not.
const send = vi.hoisted(() => ({
  POST: vi.fn(),
  PATCH: vi.fn(),
  DELETE: vi.fn(),
  GET: vi.fn(),
}))
vi.mock('@/api/client', () => ({ client: send }))
const notify = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useAppearanceChanges', () => ({
  useAppearanceChanges: () => ({ notify, onChange: () => () => {}, dispose: () => {} }),
}))

const ok = (data: unknown) => ({ data: { data }, error: undefined, response: new Response() })

async function mutations() {
  const { useFontLibraryMutations } = await import('@/queries/fontLibrary')
  let m!: ReturnType<typeof useFontLibraryMutations>
  const pinia = createPinia()
  setActivePinia(pinia)
  mount(
    defineComponent({
      setup() {
        m = useFontLibraryMutations()
        return () => null
      },
    }),
    { global: { plugins: [pinia, PiniaColada] } },
  )
  return m
}

beforeEach(() => notify.mockReset())

describe('useFontLibraryMutations', () => {
  it('adds a family and tells the other tabs', async () => {
    send.POST.mockResolvedValue(ok({ family: { id: 'Ab3dE5fG7hJ9' } }))
    const m = await mutations()
    const id = await m.create.mutateAsync({
      name: 'Brand',
      fallback: 'serif',
      blob_uuids: ['blob00000001'],
    })
    expect(id).toBe('Ab3dE5fG7hJ9')
    expect(send.POST).toHaveBeenCalledWith('/fonts', {
      body: { name: 'Brand', fallback: 'serif', blob_uuids: ['blob00000001'] },
    })
    expect(notify).toHaveBeenCalledWith('fonts')
  })

  it('removes, restores, deletes permanently, renames and reads again at their endpoints', async () => {
    send.DELETE.mockResolvedValue(ok({}))
    send.POST.mockResolvedValue(ok({ family: { id: 'Ab3dE5fG7hJ9' } }))
    send.PATCH.mockResolvedValue(ok({ family: { id: 'Ab3dE5fG7hJ9' } }))
    const m = await mutations()
    await m.remove.mutateAsync('Ab3dE5fG7hJ9')
    await m.restore.mutateAsync('Ab3dE5fG7hJ9')
    await m.purge.mutateAsync('Ab3dE5fG7hJ9')
    await m.readAgain.mutateAsync('Ab3dE5fG7hJ9')
    await m.update.mutateAsync({ id: 'Ab3dE5fG7hJ9', name: 'Brand 2', fallback: 'serif' })
    expect(send.DELETE.mock.calls.map((c) => c[0])).toEqual([
      '/fonts/Ab3dE5fG7hJ9',
      '/fonts/Ab3dE5fG7hJ9/permanent',
    ])
    expect(send.POST.mock.calls.map((c) => c[0])).toEqual([
      '/fonts/Ab3dE5fG7hJ9/restore',
      '/fonts/Ab3dE5fG7hJ9/read-again',
    ])
    expect(send.PATCH).toHaveBeenCalledWith('/fonts/Ab3dE5fG7hJ9', {
      body: { name: 'Brand 2', fallback: 'serif' },
    })
    expect(notify).toHaveBeenCalledTimes(5)
  })

  it('a refused change tells no one', async () => {
    send.POST.mockResolvedValue({
      data: undefined,
      error: { message: 'a.woff2: Not a WOFF2 file', errors: { blob_uuids: 'a.woff2: Not a WOFF2 file' } },
      response: new Response(null, { status: 422 }),
    })
    const m = await mutations()
    await expect(
      m.create.mutateAsync({ name: 'Bad', fallback: 'serif', blob_uuids: ['blob00000001'] }),
    ).rejects.toThrow()
    expect(notify).not.toHaveBeenCalled()
  })
})
