// A save the palette refused (custom palette spec §4.5): the block named and selected, the palette
// read again, and a message that says what to do — never the generic "Couldn't save".
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { handlePaletteRefusal, paletteRefusalOf } from '@/editor/paletteRefusal'

const refusal = (palette: Record<string, string>) =>
  new ApiError('Validation failed', 422, {}, { error: { details: { palette } } })

describe('palette refusals', () => {
  it('reads each refused location and its block', () => {
    expect(
      paletteRefusalOf(
        refusal({ 'head00000001:settings.style.colors.text': "Brand 2 isn't in the palette" }),
      ),
    ).toEqual([
      {
        location: 'head00000001:settings.style.colors.text',
        block: 'head00000001',
        message: "Brand 2 isn't in the palette",
      },
    ])
    expect(
      paletteRefusalOf(refusal({ '_presentation.style.colors.surface': 'x' }))?.[0]?.block,
    ).toBeNull()
    expect(
      paletteRefusalOf(new ApiError('Conflict', 409, {}, { error: { details: { code: 'X' } } })),
    ).toBeNull()
  })

  it('reads the palette again, selects the block and says what to do', () => {
    const warning = vi.fn()
    const refresh = vi.fn()
    const select = vi.fn()
    const handled = handlePaletteRefusal(
      refusal({
        'head00000001:settings.style.colors.text': "Brand 2 isn't in the palette",
        'head00000002:settings.style.colors.text': "Brand 2 isn't in the palette",
      }),
      { warning, refresh, select },
    )
    expect(handled).toBe(true)
    expect(refresh).toHaveBeenCalled()
    expect(select).toHaveBeenCalledWith('head00000001')
    expect(warning).toHaveBeenCalledWith(
      "A colour isn't in the palette",
      "Brand 2 isn't in the palette (and 1 more). Choose another colour where the picker shows it as unavailable.",
    )
    expect(handlePaletteRefusal(new Error('network'), { warning })).toBe(false)
  })
})
