// The stage's forced hover, admin side (hover state spec §6.3): what a force names — every target an
// effective hover path lands on, or a part with its declared scope — who may clear it, and that the
// stage editor holds it to send again when the stage reloads.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds, toggleFold } from '@/editor/inspector/styleGroupFolds'
import {
  StageHoverKey,
  createStageHover,
  hoverTargets,
  partScope,
  type ForceHoverRequest,
  type StageHover,
} from '@/editor/stage/stageHover'
import { partBlockType } from '@/style/capabilities'
import type { BlockStylePaths, BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

const FIXTURES = resolve(process.cwd(), '../packages/thallo-contracts/style-capability-fixtures/v1')
const starters = JSON.parse(readFileSync(`${FIXTURES}/starters.json`, 'utf8')) as Record<
  string,
  BlockStylePaths
>
const schema = classEditorSchema()

function blockType(slug: string, decl: Partial<BlockType> = {}): BlockType {
  return {
    uuid: slug,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [],
    style_capabilities: null,
    style_targets: null,
    flags: null,
    starter_content: null,
    ...decl,
  } as BlockType
}
const paths = (block: string[]): BlockStylePaths => ({ block, parts: {} })

describe('hoverTargets', () => {
  it('a Button: the control', () => {
    const type = blockType('button', {
      style_paths: starters.button,
      style_targets: { map: { colors: 'control', opacity: 'control', hover: 'control' } },
    })
    expect(hoverTargets(type)).toEqual(['control'])
  })
  it('explicit mappings without the shorthand, on two targets', () => {
    const type = blockType('x', {
      style_paths: paths(['colors.border', 'opacity', 'hover.colors.border', 'hover.opacity']),
      style_targets: {
        map: {
          'colors.border': 'root',
          'hover.colors.border': 'root',
          opacity: 'media',
          'hover.opacity': 'media',
        },
      },
    })
    expect(hoverTargets(type)).toEqual(['root', 'media'])
  })
  it('border only', () => {
    const type = blockType('x', {
      style_paths: paths(['colors.border', 'hover.colors.border']),
      style_targets: { map: { 'colors.border': 'frame', 'hover.colors.border': 'frame' } },
    })
    expect(hoverTargets(type)).toEqual(['frame'])
  })
  it('two hover targets, through the group and an explicit path', () => {
    const type = blockType('x', {
      style_paths: paths([
        'colors.surface',
        'colors.text',
        'hover.colors.text',
        'hover.colors.surface',
      ]),
      style_targets: {
        map: {
          'colors.surface': 'root',
          'colors.text': 'title',
          hover: 'root',
          'hover.colors.text': 'title',
        },
      },
    })
    expect(hoverTargets(type)).toEqual(['title', 'root'])
  })
  it('no targets declaration: root', () => {
    expect(
      hoverTargets(blockType('x', { style_paths: paths(['colors.text', 'hover.colors.text']) })),
    ).toEqual(['root'])
  })
  it('no effective hover path: none', () => {
    expect(hoverTargets(blockType('x', { style_paths: paths(['colors.text']) }))).toEqual([])
  })
})

describe('partScope', () => {
  it('a part drawn by child blocks is `children`; any other, `own`', () => {
    const type = blockType('x', {
      style_targets: { parts: { icon: { children: true }, link: { label: 'Link' } } },
    })
    expect(partScope(type, 'icon')).toBe('children')
    expect(partScope(type, 'link')).toBe('own')
  })
})

describe('createStageHover', () => {
  const r1: ForceHoverRequest = { id: 'b1', targets: ['control'], part: null, scope: 'own' }
  const r2: ForceHoverRequest = { id: 'b2', targets: [], part: 'link', scope: 'own' }
  it('only the owner clears; a newer force replaces an older one', () => {
    const send = vi.fn()
    const hover = createStageHover(send)
    const a = Symbol('a')
    const b = Symbol('b')
    hover.force(a, r1)
    expect(send).toHaveBeenLastCalledWith(r1)
    hover.clear(b)
    expect(send).toHaveBeenCalledTimes(1)
    hover.clear(a)
    expect(send).toHaveBeenLastCalledWith(null)
    hover.force(a, r1)
    hover.force(b, r2)
    send.mockClear()
    hover.clear(a)
    expect(send).not.toHaveBeenCalled()
  })
  it('two tabs in Hover: clearing the later shows the earlier again, not nothing', () => {
    const send = vi.fn()
    const hover = createStageHover(send)
    const block = Symbol('block tab')
    const part = Symbol('part tab')
    hover.force(block, r1)
    hover.force(part, r2)
    expect(send).toHaveBeenLastCalledWith(r2)
    hover.clear(part)
    expect(send).toHaveBeenLastCalledWith(r1)
    hover.clear(block)
    expect(send).toHaveBeenLastCalledWith(null)
  })

  it('clearing the earlier of two leaves the later showing', () => {
    const send = vi.fn()
    const hover = createStageHover(send)
    const block = Symbol('block tab')
    const part = Symbol('part tab')
    hover.force(block, r1)
    hover.force(part, r2)
    send.mockClear()
    hover.clear(block)
    expect(send).not.toHaveBeenCalled()
    hover.resend()
    expect(send).toHaveBeenLastCalledWith(r2)
  })

  it('an owner forcing again moves to the front', () => {
    const send = vi.fn()
    const hover = createStageHover(send)
    const a = Symbol('a')
    const b = Symbol('b')
    hover.force(a, r1)
    hover.force(b, r2)
    hover.force(a, r1)
    expect(send).toHaveBeenLastCalledWith(r1)
    hover.clear(a)
    expect(send).toHaveBeenLastCalledWith(r2)
  })

  it('resends the active force (a reloaded stage forgets it), and nothing once cleared', () => {
    const send = vi.fn()
    const hover = createStageHover(send)
    const a = Symbol('a')
    hover.force(a, r2)
    send.mockClear()
    hover.resend()
    expect(send).toHaveBeenLastCalledWith(r2)
    hover.clearAny()
    send.mockClear()
    hover.resend()
    expect(send).not.toHaveBeenCalled()
  })
})

describe('the Style tab forces while Hover is on', () => {
  const button = blockType('button', {
    style_paths: starters.button,
    style_targets: { map: { colors: 'control', opacity: 'control', hover: 'control' } },
  })
  const socials = blockType('social_links', {
    style_paths: starters.social_links,
    style_targets: { parts: { icon: { label: 'Icon', children: true, capabilities: [] } } },
  })
  function mountWith(
    stage: StageHover | null,
    type: BlockType,
    extra: Record<string, unknown> = {},
    blockId = 'b1',
  ) {
    return mount(StyleTab, {
      props: {
        block: { id: blockId, type: type.slug, data: {}, settings: { style: {} } },
        blockType: type,
        schema,
        classes: [],
        activeBreakpoint: 'base',
        ...extra,
      } as never,
      global: stage === null ? {} : { provide: { [StageHoverKey as symbol]: stage } },
    })
  }
  const fake = () => ({ force: vi.fn(), clear: vi.fn() })

  beforeEach(() => {
    localStorage.clear()
    resetFolds()
  })

  it('a block forces every hover target; Normal clears', async () => {
    const stage = fake()
    const w = mountWith(stage, button)
    expect(stage.force).not.toHaveBeenCalled()
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    expect(stage.force).toHaveBeenLastCalledWith(expect.any(Symbol), {
      id: 'b1',
      targets: ['control'],
      part: null,
      scope: 'own',
    })
    await w.find('[data-test="style-state-normal-colors"]').trigger('click')
    expect(stage.clear).toHaveBeenCalledWith(stage.force.mock.calls[0]![0])
  })

  it('a part forces itself with its declared scope', async () => {
    const stage = fake()
    const w = mountWith(stage, partBlockType(socials, 'icon'), {
      context: 'part',
      part: 'icon',
      partScope: partScope(socials, 'icon'),
    })
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    expect(stage.force).toHaveBeenLastCalledWith(expect.any(Symbol), {
      id: 'b1',
      targets: [],
      part: 'icon',
      scope: 'children',
    })
  })

  it('folding every hoverable section clears; unfolding one forces again', async () => {
    const stage = fake()
    const w = mountWith(stage, button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    toggleFold('colors')
    toggleFold('effects')
    await w.vm.$nextTick()
    expect(stage.clear).toHaveBeenCalled()
    stage.force.mockClear()
    toggleFold('effects')
    await w.vm.$nextTick()
    expect(stage.force).toHaveBeenCalledTimes(1)
  })

  it('a hidden Style tab (another inspector tab showing) clears, and forces again when shown', async () => {
    const stage = fake()
    const w = mountWith(stage, button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    const owner = stage.force.mock.calls[0]![0]
    await w.setProps({ hidden: true } as never)
    expect(stage.clear).toHaveBeenCalledWith(owner)
    stage.force.mockClear()
    await w.setProps({ hidden: false } as never)
    expect(stage.force).toHaveBeenCalledTimes(1)
  })

  it('unmounting clears', async () => {
    const stage = fake()
    const w = mountWith(stage, button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    const owner = stage.force.mock.calls[0]![0]
    w.unmount()
    expect(stage.clear).toHaveBeenCalledWith(owner)
  })

  it('without a stage (the class editor) nothing throws', async () => {
    const w = mountWith(null, button, { context: 'class' })
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    expect(w.find('[data-test="style-field-hover.colors.text"]').exists()).toBe(true)
  })

  it('extending the selection to a sibling clears the force and returns to Normal', async () => {
    const stage = fake()
    const w = mountWith(stage, button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    const owner = stage.force.mock.calls[0]![0]
    stage.clear.mockClear() // the mount's own clear (Normal) is not the one under test
    const two = [
      { id: 'b1', type: 'button', data: {}, settings: {} },
      { id: 'b2', type: 'button', data: {}, settings: {} },
    ]
    // The anchor stays b1 (shift-click keeps it): only the multi-selection changes.
    await w.setProps({ blocks: two, blockTypes: [button, button] } as never)
    expect(stage.clear).toHaveBeenCalledWith(owner)
    expect(w.find('[data-test="style-state-hover-colors"]').attributes('aria-pressed')).toBe(
      'false',
    )
    // Back to the single block: still Normal, so nothing is forced until Hover is chosen again.
    stage.force.mockClear()
    await w.setProps({ blocks: undefined, blockTypes: undefined } as never)
    expect(stage.force).not.toHaveBeenCalled()
  })

  it('extending to a block without hover rows clears the force too', async () => {
    const stage = fake()
    const links = blockType('links', { style_paths: starters.links })
    const w = mountWith(stage, button)
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    const owner = stage.force.mock.calls[0]![0]
    stage.clear.mockClear() // the mount's own clear (Normal) is not the one under test
    await w.setProps({
      blocks: [
        { id: 'b1', type: 'button', data: {}, settings: {} },
        { id: 'b3', type: 'links', data: {}, settings: {} },
      ],
      blockTypes: [button, links],
    } as never)
    expect(stage.clear).toHaveBeenCalledWith(owner)
  })

  it('a multi-selection forces nothing', async () => {
    const stage = fake()
    const blocks = [
      { id: 'b1', type: 'button', data: {}, settings: {} },
      { id: 'b2', type: 'button', data: {}, settings: {} },
    ]
    const w = mountWith(stage, button, { blocks, blockTypes: [button, button] })
    await w.find('[data-test="style-state-hover-colors"]').trigger('click')
    expect(stage.force).not.toHaveBeenCalled()
  })
})
