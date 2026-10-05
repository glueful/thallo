import { describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import type { AppearanceChange } from '@/composables/useAppearanceChanges'
import { fontLibrary } from './helpers/fontLibraryFixture'

// Another tab changed the font library (final review): this tab's Typeface lists read it again at
// once, rather than offering a removed family, or missing a new one, for up to a minute.
const GET = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ client: { GET } }))
const changes = vi.hoisted(() => ({ listeners: [] as ((c: AppearanceChange) => void)[] }))
// Like the real one, it stops when the scope it was made in is disposed.
vi.mock('@/composables/useAppearanceChanges', async () => {
  const { getCurrentScope, onScopeDispose } = await import('vue')
  return {
    useAppearanceChanges: () => {
      const mine: ((c: AppearanceChange) => void)[] = []
      if (getCurrentScope()) {
        onScopeDispose(() => {
          changes.listeners = changes.listeners.filter((l) => !mine.includes(l))
        })
      }
      return {
        notify: () => {},
        onChange: (cb: (c: AppearanceChange) => void) => {
          mine.push(cb)
          changes.listeners.push(cb)
          return () => {}
        },
        dispose: () => {},
      }
    },
  }
})

describe('the font library across tabs', () => {
  it('reads again when another tab changes the library, and not for an appearance save', async () => {
    GET.mockResolvedValue({
      data: { data: fontLibrary() },
      error: undefined,
      response: new Response(),
    })
    const { useFontLibrary } = await import('@/queries/fontLibrary')
    const pinia = createPinia()
    setActivePinia(pinia)
    const reader = defineComponent({
      setup() {
        useFontLibrary()
        return () => null
      },
    })
    const options = { global: { plugins: [pinia, PiniaColada] } }
    const first = mount(reader, options)
    mount(reader, options) // a second reader on the page: still one listener
    await flushPromises()
    expect(GET).toHaveBeenCalledTimes(1)
    expect(changes.listeners).toHaveLength(1)
    changes.listeners[0]!({ kind: 'appearance', at: 1 })
    await flushPromises()
    expect(GET).toHaveBeenCalledTimes(1)
    // The component that first read it goes away; the page keeps following other tabs.
    first.unmount()
    expect(changes.listeners).toHaveLength(1)
    changes.listeners[0]!({ kind: 'fonts', at: 2 })
    await flushPromises()
    expect(GET).toHaveBeenCalledTimes(2)
  })
})
