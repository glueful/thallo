import { describe, it, expect } from 'vitest'
import { responsiveWrites } from '@/editor/ops/responsiveWrite'

// Where an edit made at the active breakpoint lands. Styles are mobile first (base, then md, then
// lg), but the editor opens on Desktop: an edit saved for lg alone left phones and tablets with
// none of the design (a whole home page with no gutter and no columns below 1024px).
const pad = (value: string) => ({ type: 'token' as const, value })
const style = (top: Record<string, unknown>) => ({ style: { spacing: { padding: { top } } } })
const PATH = 'spacing.padding.top'

describe('responsiveWrites', () => {
  it('saves a first value set at Desktop or Tablet for every size, and keeps that size’s own copy', () => {
    for (const bp of ['lg', 'md'] as const) {
      expect(responsiveWrites({}, PATH, bp, pad('space.lg'))).toEqual([
        ['base', pad('space.lg')],
        [bp, pad('space.lg')],
      ])
    }
  })

  it('saves at the size being edited once the block has a value at any size', () => {
    for (const settings of [style({ base: pad('space.sm') }), style({ md: pad('space.sm') })]) {
      expect(responsiveWrites(settings, PATH, 'lg', pad('space.xl'))).toEqual([
        ['lg', pad('space.xl')],
      ])
    }
  })

  it('saves at base as before: base is what the larger sizes inherit', () => {
    expect(responsiveWrites({}, PATH, 'base', pad('space.sm'))).toEqual([['base', pad('space.sm')]])
    expect(
      responsiveWrites(style({ base: pad('space.lg') }), PATH, 'base', pad('space.sm')),
    ).toEqual([['base', pad('space.sm')]])
  })

  it('leaves removing a value and non-responsive settings as they were', () => {
    expect(responsiveWrites(style({ lg: pad('space.sm') }), PATH, 'lg', null)).toEqual([
      ['lg', null],
    ])
    expect(responsiveWrites({}, PATH, 'md', null)).toEqual([['md', null]])
    expect(responsiveWrites({}, 'menu_button.size', null, { type: 'choice', value: 'lg' })).toEqual(
      [[null, { type: 'choice', value: 'lg' }]],
    )
  })

  describe('Order and Push, set for one screen size only', () => {
    // A header is arranged per device: the menu button may lead on a phone while the logo leads on
    // Desktop. So these two never reach another size: no copy to base, and a size above the one
    // edited keeps what it showed by being pinned to it.
    const choice = (value: string) => ({ type: 'choice' as const, value })
    const order = (o: Record<string, unknown>) => ({ style: { layout: { order: o } } })

    it('saves a value set at Desktop for Desktop alone', () => {
      expect(responsiveWrites({}, 'layout.order', 'lg', choice('first'))).toEqual([
        ['lg', choice('first')],
      ])
      expect(responsiveWrites({}, 'layout.push', 'lg', choice('after'))).toEqual([
        ['lg', choice('after')],
      ])
    })

    it('pins the larger sizes to the neutral value when nothing is set', () => {
      expect(responsiveWrites({}, 'layout.order', 'base', choice('first'))).toEqual([
        ['base', choice('first')],
        ['md', choice('default')],
        ['lg', choice('default')],
      ])
      expect(responsiveWrites({}, 'layout.push', 'md', choice('both'))).toEqual([
        ['md', choice('both')],
        ['lg', choice('none')],
      ])
    })

    it('pins a larger size to what it inherited, and leaves a size with its own value alone', () => {
      // Desktop inherited "last" from Mobile; a Tablet edit must not change Desktop.
      expect(
        responsiveWrites(order({ base: choice('last') }), 'layout.order', 'md', choice('first')),
      ).toEqual([
        ['md', choice('first')],
        ['lg', choice('last')],
      ])
      expect(
        responsiveWrites(
          order({ md: choice('default'), lg: choice('last') }),
          'layout.order',
          'base',
          choice('first'),
        ),
      ).toEqual([['base', choice('first')]])
      // Desktop's own value; Tablet inherits Mobile's "last".
      expect(
        responsiveWrites(
          order({ base: choice('last'), lg: choice('first') }),
          'layout.order',
          'base',
          choice('default'),
        ),
      ).toEqual([
        ['base', choice('default')],
        ['md', choice('last')],
      ])
    })

    it('removes a value at that size only', () => {
      expect(
        responsiveWrites(order({ base: choice('first') }), 'layout.order', 'base', null),
      ).toEqual([['base', null]])
    })

    it('reads a part’s own record', () => {
      const settings = { parts: { links: { layout: { order: { base: choice('last') } } } } }
      expect(responsiveWrites(settings, 'layout.order', 'md', choice('first'), 'links')).toEqual([
        ['md', choice('first')],
        ['lg', choice('last')],
      ])
    })
  })

  it('reads a part’s own record', () => {
    const settings = {
      parts: { links: { spacing: { padding: { top: { base: pad('space.lg') } } } } },
    }

    expect(responsiveWrites(settings, PATH, 'lg', pad('space.xl'), 'links')).toEqual([
      ['lg', pad('space.xl')],
    ])
    expect(responsiveWrites(settings, PATH, 'lg', pad('space.xl'))).toEqual([
      ['base', pad('space.xl')],
      ['lg', pad('space.xl')],
    ])
  })
})
