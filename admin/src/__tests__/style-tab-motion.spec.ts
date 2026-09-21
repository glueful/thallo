// Motion in the Style tab: how a block enters as it scrolls into view, how a container spaces out
// its children's entrances, and Ken Burns for a block with a picture in a frame. Each is its own
// capability group, so a block sees only what it can do; the choices read as words for people.
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StyleTab from '@/editor/inspector/StyleTab.vue'
import { resetFolds } from '@/editor/inspector/styleGroupFolds'
import { styleProperties } from '@/style/schema'
import { tabOf } from '@/editor/inspector/tabMap'
import type { BlockType } from '@/queries/blockTypes'
import { classEditorSchema } from './helpers/classEditorSchema'

const schema = classEditorSchema()
const type = (slug: string, caps: string[]): BlockType =>
  ({
    uuid: slug,
    slug,
    label: slug,
    icon: null,
    category: null,
    description: null,
    active: true,
    schema: [],
    style_capabilities: caps,
    style_targets: null,
    flags: null,
    starter_content: null,
  }) as BlockType
const heading = type('heading', ['typography', 'motion'])
const container = type('container', ['spacing', 'motion', 'motion.children', 'motion.media'])
const spacer = type('spacer', ['spacing'])

function mountTab(
  blockType: BlockType,
  style: Record<string, unknown> = {},
  bp = 'lg',
  more: Record<string, unknown> = { canPlayMotion: true },
) {
  return mount(StyleTab, {
    props: {
      ...more,
      block: { id: 'b1', type: blockType.slug, data: {}, settings: { style } },
      blockType,
      schema,
      classes: [],
      activeBreakpoint: bp,
    } as never,
  })
}
const choices = (w: ReturnType<typeof mountTab>, path: string) =>
  w.findAll(`[data-test="style-field-${path}"] [data-test^="choice-"]`).map((b) => b.text())

beforeEach(() => {
  localStorage.clear()
  resetFolds()
})

describe('the contract the admin mirrors', () => {
  it('has the six motion paths, none responsive, all on the Style tab', () => {
    const by = new Map(styleProperties().map((p) => [p.path, p]))
    const expected: Record<string, { group: string; choices: string[] }> = {
      'motion.entrance': {
        group: 'motion',
        choices: ['none', 'fade', 'fade-up', 'fade-down', 'slide-left', 'slide-right', 'zoom-in'],
      },
      'motion.duration': { group: 'motion', choices: ['fast', 'normal', 'slow'] },
      'motion.delay': { group: 'motion', choices: ['none', 'short', 'medium', 'long'] },
      'motion.repeat': { group: 'motion', choices: ['once', 'always'] },
      'motion.stagger': { group: 'motion.children', choices: ['none', 'short', 'medium', 'long'] },
      'motion.ken_burns': {
        group: 'motion.media',
        choices: ['none', 'zoom-in', 'zoom-out', 'pan-left', 'pan-right'],
      },
    }
    for (const [path, want] of Object.entries(expected)) {
      expect(by.get(path), path).toMatchObject({ ...want, responsive: false })
      expect(tabOf(path), path).toBe('style')
    }
  })
})

describe('the Style tab’s Motion group', () => {
  it('a block that can enter gets Entrance, Duration, Delay and Repeat — in words', () => {
    const w = mountTab(heading)
    const group = w.find('[data-test="style-group-motion"]')
    expect(group.text()).toContain('Motion')
    expect(choices(w, 'motion.entrance')).toEqual([
      'None',
      'Fade',
      'Fade up',
      'Fade down',
      'Slide left',
      'Slide right',
      'Zoom in',
    ])
    expect(choices(w, 'motion.repeat')).toEqual(['Once', 'Every time'])
    expect(group.find('[data-test="style-field-motion.duration"]').text()).toContain('Duration')
    expect(group.find('[data-test="style-field-motion.delay"]').text()).toContain('Delay')
    // Not the container's or the picture's settings.
    expect(w.find('[data-test="style-field-motion.stagger"]').exists()).toBe(false)
    expect(w.find('[data-test="style-field-motion.ken_burns"]').exists()).toBe(false)
  })

  it('a container also staggers its children and drifts its background', () => {
    const w = mountTab(container)
    const group = w.find('[data-test="style-group-motion"]')
    expect(group.find('[data-test="style-field-motion.stagger"]').text()).toContain(
      'Stagger children',
    )
    expect(group.find('[data-test="style-field-motion.ken_burns"]').text()).toContain('Ken Burns')
    expect(choices(w, 'motion.ken_burns')).toEqual([
      'None',
      'Zoom in',
      'Zoom out',
      'Pan left',
      'Pan right',
    ])
  })

  it('a block that cannot move has no Motion group at all', () => {
    expect(mountTab(spacer).find('[data-test="style-group-motion"]').exists()).toBe(false)
  })

  it('writes the stored value, bare — an entrance does not vary by screen', async () => {
    const w = mountTab(heading, {}, 'md')
    await w
      .find('[data-test="style-field-motion.entrance"] [data-test="choice-fade-up"]')
      .trigger('click')
    expect(w.emitted('set')).toEqual([
      ['motion.entrance', null, { type: 'choice', value: 'fade-up' }],
    ])
  })

  it('offers to play the motion once something is set, and asks its host to do it', async () => {
    const still = mountTab(heading)
    expect(still.find('[data-test="motion-play"]').exists()).toBe(false)

    const w = mountTab(heading, { motion: { entrance: { type: 'choice', value: 'fade' } } })
    await w.find('[data-test="motion-play"]').trigger('click')
    expect(w.emitted('play-motion')).toHaveLength(1)
  })

  it('offers no Play where there is no stage to play on', () => {
    // The regions page and the style class editor show a block's settings without a canvas.
    const w = mountTab(
      heading,
      { motion: { entrance: { type: 'choice', value: 'fade' } } },
      'lg',
      {},
    )
    expect(w.find('[data-test="style-field-motion.entrance"]').exists()).toBe(true)
    expect(w.find('[data-test="motion-play"]').exists()).toBe(false)
  })
})
