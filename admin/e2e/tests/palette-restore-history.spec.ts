// Restore to draft through the server, against a completed replacement (custom palette spec §4.5,
// §5.3): the restored colour is never mapped through a record it postdates — not by a schema
// refresh, not by undo and redo — and saving it again is an ordinary save the server accepts.
import { expect, test, type Page } from '@playwright/test'
import { fixture, openDesignPage, paletteHooks, type World } from '../helpers'

const record = {
  id: 'jobA',
  slot: 1,
  map: { 'color.brand-1': 'color.accent', 'color.brand-1-contrast': 'color.accent-contrast' },
  completed_generation: 3,
}

/** The fixture draft with the CTA's first button coloured Brand 1. */
function fixtureWithBrandOne(): Record<string, unknown> {
  const fields = (
    JSON.parse(fixture('api/draft.json')) as {
      data: { draft: { fields: Record<string, unknown> } }
    }
  ).data.draft.fields
  const walk = (node: unknown): void => {
    if (Array.isArray(node)) return node.forEach(walk)
    if (typeof node !== 'object' || node === null) return
    const block = node as Record<string, unknown>
    if (block.id === 'ctabutn0001') {
      block.settings = { style: { colors: { text: { type: 'token', value: 'color.brand-1' } } } }
      return
    }
    Object.values(block).forEach(walk)
  }
  walk(fields)
  return fields
}

async function restoreFirstVersion(page: Page): Promise<void> {
  await page.getByRole('tab', { name: 'Versions' }).click()
  await page.locator('[data-test^="version-restore-draft-"]').first().click()
}

test('complete replacement → restore → refresh schema → undo → redo → save keeps the restored unavailable colour', async ({
  page,
}) => {
  const restored = fixtureWithBrandOne()
  const world: World = {
    // the replacement already completed
    schemaPalette: { generation: 3, replacements: { after: 0, through: 3, records: [record] } },
    restore: {
      fields: restored,
      lock_version: 3,
      palette_generation: 4,
      palette_replacements: { after: 1, through: 4, records: [record] },
    },
  }
  const recorded = await openDesignPage(page, world)
  const hooks = paletteHooks(page, world)
  await restoreFirstVersion(page)
  await expect.poll(() => hooks.blockToken('ctabutn0001')).toBe('color.brand-1')
  expect(recorded.restores[0]).toMatchObject({ version_uuid: 'version00001' })
  await hooks.refreshSchema() // brings the completed record again
  expect(await hooks.blockToken('ctabutn0001')).toBe('color.brand-1') // not mapped to Accent
  await page.keyboard.press('ControlOrMeta+z')
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await expect.poll(() => hooks.blockToken('ctabutn0001')).toBe('color.brand-1')
  await hooks.saveNow()
  const saved = JSON.stringify(recorded.saves.at(-1)!.fields)
  expect(saved).toContain('color.brand-1')
  expect(saved).not.toContain('"version_uuid') // still an ordinary save
})

test('restore → undo → redo sends the restore once, then ordinary saves', async ({ page }) => {
  const restored = fixtureWithBrandOne()
  const world: World = {
    restore: {
      fields: restored,
      lock_version: 3,
      palette_generation: 1,
      palette_replacements: { after: 1, through: 1, records: [] },
    },
  }
  const recorded = await openDesignPage(page, world)
  const hooks = paletteHooks(page, world)
  await restoreFirstVersion(page)
  await expect.poll(() => recorded.restores.length).toBe(1)
  // the version by id, with the boundary the draft GET's generation set
  expect(recorded.restores[0]).toMatchObject({ version_uuid: 'version00001', palette_through: 1 })
  await expect.poll(() => hooks.blockToken('ctabutn0001')).toBe('color.brand-1')
  await page.keyboard.press('ControlOrMeta+z')
  await hooks.saveNow()
  await expect.poll(() => recorded.saves.length).toBe(1)
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await hooks.saveNow()
  await expect.poll(() => recorded.saves.length).toBe(2)
  expect(recorded.saves[1]!.fields).toEqual(restored)
  expect(recorded.restores).toHaveLength(1)
})
