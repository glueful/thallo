// One slice of the general settings on its own page (useSettingsForm): a value the page adopts from
// the server never counts as an edit, and a save marks the form clean only if nothing was edited
// while it was in flight.
import { describe, it, expect } from 'vitest'
import { nextTick, ref } from 'vue'
import { useSettingsForm } from '@/composables/useSettingsForm'
import type { GeneralSettings } from '@/queries/generalSettings'

const settings = (over: Partial<GeneralSettings> = {}) =>
  ({ site_logo: '', theme_radius: 'round', ...over }) as GeneralSettings

describe('useSettingsForm', () => {
  it('adopting a value is not an edit', async () => {
    const data = ref<GeneralSettings | undefined>(settings())
    const { form, dirty, adopt } = useSettingsForm(data, { site_logo: '', theme_radius: 'round' })
    await nextTick()
    adopt({ site_logo: 'blob00000001' })
    await nextTick()
    expect(form.site_logo).toBe('blob00000001')
    expect(dirty.value).toBe(false)
  })

  it('a save marks the form clean only if nothing changed since it was sent', async () => {
    const data = ref<GeneralSettings | undefined>(settings())
    const { form, dirty, payload, saved } = useSettingsForm(data, {
      site_logo: '',
      theme_radius: 'round',
    })
    await nextTick()
    form.theme_radius = 'sharp'
    await nextTick()
    const sent = payload()
    form.site_logo = 'blob00000002' // edited while the save was in flight
    await nextTick()
    saved(sent)
    expect(dirty.value).toBe(true)
    saved(payload())
    expect(dirty.value).toBe(false)
  })

  it('leaves a manual key to the page: the server→form sync never writes it', async () => {
    const data = ref<GeneralSettings | undefined>(undefined)
    const { form, dirty } = useSettingsForm(
      data,
      { site_logo: '', theme_radius: 'round' },
      { manual: ['site_logo'] },
    )
    data.value = settings({ site_logo: 'blob00000003', theme_radius: 'sharp' })
    await nextTick()
    expect(form.theme_radius).toBe('sharp')
    expect(form.site_logo).toBe('')
    expect(dirty.value).toBe(false)
  })

  it('saved() with nothing sent behaves as before', async () => {
    const data = ref<GeneralSettings | undefined>(settings())
    const { form, dirty, saved } = useSettingsForm(data, { site_logo: '', theme_radius: 'round' })
    await nextTick()
    form.theme_radius = 'sharp'
    await nextTick()
    saved()
    expect(dirty.value).toBe(false)
  })
})
