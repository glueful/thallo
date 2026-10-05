import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, type Ref } from 'vue'
import { useCanvasBridge } from '@/composables/useCanvasBridge'
import { fontLibrary } from './helpers/fontLibraryFixture'
import type { FontLibraryResult } from '@/queries/fontLibrary'

// What a target renders in (block typeface plan Task 10): the parent asks the stage for a block's
// target or part and takes only the latest answer for it; and the Typeface control says when the
// browser is choosing a face the family does not supply.
const library = ref<FontLibraryResult | undefined>(fontLibrary())
vi.mock('@/queries/fontLibrary', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/fontLibrary')>()),
  useFontLibrary: () => ({ data: library }),
}))
vi.mock('@/fonts/loadFamilyFaces', () => ({ loadFamilyFaces: vi.fn(() => Promise.resolve()) }))

const { default: FontFamilyControl } =
  await import('@/editor/inspector/controls/FontFamilyControl.vue')

function bridgeWithFrame() {
  const postMessage = vi.fn()
  const iframe = ref({
    src: 'https://site.test/_preview/tok',
    contentWindow: { postMessage },
  } as unknown as HTMLIFrameElement)
  const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)
  return { bridge, postMessage }
}

function reply(nonce: string, data: Record<string, unknown>): void {
  window.dispatchEvent(
    new MessageEvent('message', { data: { type: 'thallo:typography-state', nonce, ...data } }),
  )
}

describe('requestTypography', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('asks the stage for a target and resolves its reply, keyed by block, target and seq', async () => {
    const { bridge, postMessage } = bridgeWithFrame()
    const answer = bridge.requestTypography('blk1', 'title')
    const sent = postMessage.mock.calls[0]![0] as Record<string, unknown>
    expect(sent).toMatchObject({ type: 'thallo:typography-request', id: 'blk1', target: 'title' })
    expect(typeof sent.seq).toBe('number')
    // Another block's, another target's, another seq's and a foreign nonce's replies are not it.
    reply(bridge.nonce, {
      id: 'blk2',
      target: 'title',
      seq: sent.seq,
      weight: 900,
      style: 'normal',
    })
    reply(bridge.nonce, { id: 'blk1', target: 'link', seq: sent.seq, weight: 900, style: 'normal' })
    reply(bridge.nonce, { id: 'blk1', target: 'title', seq: 999, weight: 900, style: 'normal' })
    reply('WRONG', { id: 'blk1', target: 'title', seq: sent.seq, weight: 900, style: 'normal' })
    reply(bridge.nonce, {
      id: 'blk1',
      target: 'title',
      seq: sent.seq,
      weight: 700,
      style: 'italic',
    })
    await expect(answer).resolves.toEqual({ weight: 700, style: 'italic' })
    bridge.dispose()
  })

  it('takes only the latest request for a target: an older reply is dropped', async () => {
    const { bridge, postMessage } = bridgeWithFrame()
    const first = bridge.requestTypography('blk1', 'title')
    const second = bridge.requestTypography('blk1', 'title')
    const [a, b] = postMessage.mock.calls.map((c) => (c[0] as { seq: number }).seq)
    expect(b).not.toBe(a)
    reply(bridge.nonce, { id: 'blk1', target: 'title', seq: a, weight: 300, style: 'normal' })
    await expect(first).resolves.toBeNull()
    reply(bridge.nonce, { id: 'blk1', target: 'title', seq: b, weight: 600, style: 'normal' })
    await expect(second).resolves.toEqual({ weight: 600, style: 'normal' })
    bridge.dispose()
  })

  it('a selection change drops every pending reply', async () => {
    const { bridge, postMessage } = bridgeWithFrame()
    const answer = bridge.requestTypography('blk1', 'title')
    const { seq } = postMessage.mock.calls[0]![0] as { seq: number }
    bridge.dropTypography()
    await expect(answer).resolves.toBeNull()
    // A late reply meets no resolver.
    reply(bridge.nonce, { id: 'blk1', target: 'title', seq, weight: 700, style: 'normal' })
    bridge.dispose()
  })

  it('resolves null after a second with no reply (an absent optional target)', async () => {
    const { bridge } = bridgeWithFrame()
    const answer = bridge.requestTypography('blk1', 'title')
    let settled: unknown = 'pending'
    void answer.then((v) => (settled = v))
    await vi.advanceTimersByTimeAsync(999)
    expect(settled).toBe('pending')
    await vi.advanceTimersByTimeAsync(1)
    expect(settled).toBeNull()
    bridge.dispose()
  })

  it('refuses a malformed reply', async () => {
    const { bridge, postMessage } = bridgeWithFrame()
    const answer = bridge.requestTypography('blk1', 'title')
    const { seq } = postMessage.mock.calls[0]![0] as { seq: number }
    reply(bridge.nonce, { id: 'blk1', target: 'title', seq, weight: 'bold', style: 'normal' })
    reply(bridge.nonce, { id: 'blk1', target: 'title', seq, weight: 400, style: 'slanted' })
    await vi.advanceTimersByTimeAsync(1000)
    await expect(answer).resolves.toBeNull()
    bridge.dispose()
  })
})

describe('the not-supplied notice', () => {
  function mountControl(
    value: string,
    computed: { weight: number; style: string } | null,
    context: 'block' | 'part' | 'class' = 'block',
  ) {
    return mount(FontFamilyControl, {
      props: { value: { type: 'font', value }, context, computed },
      global: { stubs: { RouterLink: true } },
    })
  }
  const notice = (w: ReturnType<typeof mountControl>) => w.find('[data-test="typeface-notice"]')

  beforeEach(() => {
    library.value = fontLibrary()
  })

  it('names a weight the family does not supply', () => {
    const w = mountControl('Ab3dE5fG7hJ9', { weight: 600, style: 'normal' })
    expect(notice(w).text()).toBe(
      "600 isn't supplied by Brand; the browser will select an available face.",
    )
  })

  it('says when italic is asked for and there is no italic face', () => {
    const w = mountControl('Vr3dE5fG7hJ9', { weight: 500, style: 'italic' })
    expect(notice(w).text()).toBe("There's no italic face; the browser may slant the text.")
  })

  it('says both, weight first, in a part too', () => {
    const w = mountControl('Vr3dE5fG7hJ9', { weight: 950, style: 'oblique' }, 'part')
    expect(
      notice(w)
        .findAll('p')
        .map((p) => p.text()),
    ).toEqual([
      "950 isn't supplied by Vary; the browser will select an available face.",
      "There's no italic face; the browser may slant the text.",
    ])
  })

  it('says nothing for a supplied weight and style', () => {
    expect(notice(mountControl('Ab3dE5fG7hJ9', { weight: 700, style: 'normal' })).exists()).toBe(
      false,
    )
    expect(notice(mountControl('Ab3dE5fG7hJ9', { weight: 400, style: 'italic' })).exists()).toBe(
      false,
    )
  })

  it('says nothing for a built-in, unknown faces, a class, or nothing measured', () => {
    expect(notice(mountControl('serif', { weight: 600, style: 'italic' })).exists()).toBe(false)
    expect(notice(mountControl('Uk3dE5fG7hJ9', { weight: 600, style: 'italic' })).exists()).toBe(
      false,
    )
    expect(
      notice(mountControl('Ab3dE5fG7hJ9', { weight: 600, style: 'italic' }, 'class')).exists(),
    ).toBe(false)
    expect(notice(mountControl('Ab3dE5fG7hJ9', null)).exists()).toBe(false)
  })
})
