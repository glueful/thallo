import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ResponsiveField from '@/editor/inspector/controls/ResponsiveField.vue'
import type { StylePropertyRow } from '@/queries/styleSchema'

const padding: StylePropertyRow = {
  path: 'spacing.padding.top',
  group: 'spacing',
  kinds: ['token', 'reset'],
  responsive: true,
  token_domain: 'spacing',
  choices: null,
}
const radius: StylePropertyRow = {
  path: 'radius',
  group: 'radius',
  kinds: ['token', 'reset'],
  responsive: false,
  token_domain: 'radius',
  choices: null,
}
const vocabulary = {
  domains: { spacing: ['none', 'sm', 'lg'], radius: ['none', 'full'] },
  values: { 'spacing.lg': 'var(--space-4)', 'spacing.sm': '0.5rem', 'radius.full': '999px' },
}

function mountField(
  def: StylePropertyRow,
  style: Record<string, unknown>,
  bp: 'base' | 'md' | 'lg' = 'md',
) {
  return mount(ResponsiveField, {
    props: { def, label: 'Padding top', style, classes: [], activeBreakpoint: bp, vocabulary },
  })
}

describe('ResponsiveField', () => {
  it('shows the effective value, its state and the breakpoint indicator bound to the active breakpoint', () => {
    const w = mountField(padding, {
      spacing: { padding: { top: { base: { type: 'token', value: 'spacing.sm' } } } },
    })
    expect(w.find('[data-test="breakpoint-md"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-test="style-state"]').text()).toBe('inherited') // md inherits base
    expect(w.find('[data-test="token-spacing.sm"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-test="token-spacing.lg"]').attributes('title')).toContain('var(--space-4)')
  })

  it('editing writes at the ACTIVE breakpoint; Reset writes a reset; Apply to all emits set-all', async () => {
    const w = mountField(padding, {})
    expect(w.find('[data-test="style-state"]').text()).toBe('theme')
    await w.find('[data-test="token-spacing.lg"]').trigger('click')
    expect(w.emitted('set')?.[0]).toEqual([
      'spacing.padding.top',
      'md',
      { type: 'token', value: 'spacing.lg' },
    ])
    await w.find('[data-test="style-reset"]').trigger('click')
    expect(w.emitted('set')?.[1]).toEqual(['spacing.padding.top', 'md', { type: 'reset' }])

    const set = mountField(padding, {
      spacing: { padding: { top: { md: { type: 'token', value: 'spacing.lg' } } } },
    })
    expect(set.find('[data-test="style-state"]').text()).toBe('set')
    await set.find('[data-test="style-apply-all"]').trigger('click')
    expect(set.emitted('set-all')?.[0]).toEqual([
      'spacing.padding.top',
      { type: 'token', value: 'spacing.lg' },
    ])
    await set.find('[data-test="style-clear"]').trigger('click')
    expect(set.emitted('set')?.[0]).toEqual(['spacing.padding.top', 'md', null])
  })

  it('clicking a breakpoint asks the page to switch the active breakpoint (never inferred)', async () => {
    const w = mountField(padding, {})
    await w.find('[data-test="breakpoint-lg"]').trigger('click')
    expect(w.emitted('update:activeBreakpoint')?.[0]).toEqual(['lg'])
  })

  it('a non-responsive property has no indicator and writes with a null breakpoint', async () => {
    const w = mountField(radius, { radius: { type: 'reset' } })
    expect(w.find('[data-test="breakpoint-md"]').exists()).toBe(false)
    expect(w.find('[data-test="style-state"]').text()).toBe('reset')
    await w.find('[data-test="token-radius.full"]').trigger('click')
    expect(w.emitted('set')?.[0]).toEqual(['radius', null, { type: 'token', value: 'radius.full' }])
  })
})
