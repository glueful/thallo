import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import TokenField from '@/fields/components/TokenField.vue'
import { fieldComponent } from '@/fields/registry'
import { toFieldDef } from '@/fields/normalize'

vi.mock('@/queries/styleSchema', () => ({
  useStyleSchema: () => ({
    data: ref({
      version: 1,
      breakpoints: {},
      properties: [],
      advanced: [],
      vocabulary: {
        version: 1,
        domains: { color: ['text', 'accent'] },
        values: { 'color.accent': 'var(--accent)' },
      },
    }),
  }),
}))

describe('TokenField', () => {
  it('is the registry component for `token` and normalises the domain', () => {
    expect(fieldComponent('token')).toBe(TokenField)
    expect(
      toFieldDef({
        name: 'prefix_color',
        type: 'token',
        domain: 'color',
        required: false,
        localized: false,
        filterable: false,
      }),
    ).toMatchObject({ type: 'token', domain: 'color' })
  })

  it('offers the domain scale with the theme value previewed and writes the typed token', async () => {
    const w = mount(TokenField, {
      props: {
        field: { name: 'prefix_color', type: 'token', domain: 'color' },
        modelValue: undefined,
      },
    })
    expect(w.find('[data-test="token-color.accent"]').attributes('title')).toContain(
      'var(--accent)',
    )
    await w.find('[data-test="token-color.accent"]').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([{ type: 'token', value: 'color.accent' }])
  })

  it('shows the stored token as selected and clears to unset', async () => {
    const w = mount(TokenField, {
      props: {
        field: { name: 'prefix_color', type: 'token', domain: 'color' },
        modelValue: { type: 'token', value: 'color.text' },
      },
    })
    expect(w.find('[data-test="token-color.text"]').attributes('aria-pressed')).toBe('true')
    await w.find('[data-test="token-clear-prefix_color"]').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([undefined])
  })
})
