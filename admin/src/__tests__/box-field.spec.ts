import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BoxField from '@/editor/inspector/controls/BoxField.vue'
import type { StylePropertyRow } from '@/queries/styleSchema'

// One row for a four-sided property (visual builder spec §3.4, the box presentation): a cell per
// side showing its token and state, a link toggle that writes all sides at once, and the token
// pills for the cell that is open. Same writes as four separate fields — one place to make them.
const side = (s: string): StylePropertyRow => ({
  path: `spacing.padding.${s}`,
  group: 'spacing',
  kinds: ['token', 'reset'],
  responsive: true,
  token_domain: 'spacing',
  choices: null,
})
const sides = ['top', 'right', 'bottom', 'left'].map((s) => ({ key: s, def: side(s) }))
const vocabulary = {
  domains: { spacing: ['none', 'sm', 'lg'] },
  values: { 'spacing.lg': 'var(--space-4)', 'spacing.sm': '0.5rem' },
}
function mountBox(style: Record<string, unknown>, bp: 'base' | 'md' | 'lg' = 'md') {
  return mount(BoxField, {
    props: { label: 'Padding', sides, style, classes: [], activeBreakpoint: bp, vocabulary },
  })
}

describe('BoxField', () => {
  it('shows a cell per side with its effective token and state', () => {
    const w = mountBox({
      spacing: {
        padding: {
          top: { base: { type: 'token', value: 'spacing.lg' } },
          left: { md: { type: 'token', value: 'spacing.sm' } },
        },
      },
    })
    const top = w.find('[data-test="style-field-spacing.padding.top"]')
    expect(top.find('[data-test="box-cell-value"]').text()).toBe('lg')
    expect(top.find('[data-test="style-state"]').text()).toBe('inherited') // md inherits base
    const left = w.find('[data-test="style-field-spacing.padding.left"]')
    expect(left.find('[data-test="box-cell-value"]').text()).toBe('sm')
    expect(left.find('[data-test="style-state"]').text()).toBe('set')
    expect(
      w.find('[data-test="style-field-spacing.padding.right"] [data-test="style-state"]').text(),
    ).toBe('theme')
    // Sides differ, so the link starts open.
    expect(w.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('false')
  })

  it('a cell opens its pills; linked, a pick writes every side; unlinked, only that side', async () => {
    const w = mountBox({})
    expect(w.find('[data-test="box-link"]').attributes('aria-pressed')).toBe('true') // all theme: linked
    expect(w.find('[data-test="token-spacing.lg"]').exists()).toBe(false) // nothing open yet
    await w.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await w.find('[data-test="token-spacing.lg"]').trigger('click')
    expect(w.emitted('set')).toHaveLength(4)
    expect(w.emitted('set')![0]).toEqual([
      'spacing.padding.top',
      'md',
      { type: 'token', value: 'spacing.lg' },
    ])
    expect(w.emitted('set')![3]).toEqual([
      'spacing.padding.left',
      'md',
      { type: 'token', value: 'spacing.lg' },
    ])

    await w.find('[data-test="box-link"]').trigger('click')
    await w.find('[data-test="box-cell-spacing.padding.right"]').trigger('click')
    await w.find('[data-test="token-spacing.sm"]').trigger('click')
    expect(w.emitted('set')).toHaveLength(5)
    expect(w.emitted('set')![4]).toEqual([
      'spacing.padding.right',
      'md',
      { type: 'token', value: 'spacing.sm' },
    ])
  })

  it('reset, clear and apply-to-all act on the open cell, or every side when linked', async () => {
    const w = mountBox({
      spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } },
    })
    await w.find('[data-test="box-cell-spacing.padding.top"]').trigger('click')
    await w.find('[data-test="style-reset"]').trigger('click')
    expect(w.emitted('set')![0]).toEqual(['spacing.padding.top', 'md', { type: 'reset' }])
    await w.find('[data-test="style-clear"]').trigger('click')
    expect(w.emitted('set')![1]).toEqual(['spacing.padding.top', 'md', null])
    await w.find('[data-test="style-apply-all"]').trigger('click')
    expect(w.emitted('set-all')![0]).toEqual([
      'spacing.padding.top',
      { type: 'token', value: 'spacing.lg' },
    ])

    // Linked: the same three act on every side (pressed link, then reset).
    await w.find('[data-test="box-link"]').trigger('click')
    await w.find('[data-test="style-reset"]').trigger('click')
    expect(
      w
        .emitted('set')!
        .slice(-4)
        .map((e) => e[0]),
    ).toEqual([
      'spacing.padding.top',
      'spacing.padding.right',
      'spacing.padding.bottom',
      'spacing.padding.left',
    ])
  })
})
