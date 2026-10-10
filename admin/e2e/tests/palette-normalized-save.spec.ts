// A save's palette result adopted into the editor (custom palette spec §4.5, §5.3): the stored
// colours a normalisation changed land on their own blocks, under any edit made during the save,
// without cancelling it; a completed replacement's record reaches history, so undo and redo replay
// its colour; a stage reload keeps the document, the history and the ledger.
import { expect, test } from '@playwright/test'
import { openDesignPage, paletteHooks, stage, type World } from '../helpers'

const rewrite = {
  location: 'ctabutn0001:settings.style.colors.text',
  from: 'color.brand-1',
  to: 'color.accent',
}
const record = {
  id: 'jobA',
  slot: 1,
  map: { 'color.brand-1': 'color.accent', 'color.brand-1-contrast': 'color.accent-contrast' },
  completed_generation: 3,
}

test('a save response arriving while a text edit is still in its debounce keeps that edit, its undo and its dirty state', async ({
  page,
}) => {
  const world: World = {
    saveResponses: [{ palette_rewrites: [rewrite], palette_generation: 2 }, {}],
    saveDelayMs: 300,
    reloadDraftFrom: 'last-save',
  }
  const recorded = await openDesignPage(page, world)
  const hooks = paletteHooks(page, world)
  await hooks.setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  const saving = hooks.saveNow()
  await hooks.typeInInspector('ctabutn0001', 'label', 'Order now') // stays ACTIVE (500 ms debounce)
  await saving // the response lands inside the debounce
  expect(await hooks.hasActiveTransaction()).toBe(true)
  expect(await hooks.blockToken('ctabutn0001')).toBe('color.accent')
  await expect.poll(() => hooks.hasActiveTransaction()).toBe(false) // the debounce commits it
  expect(await hooks.isDirty()).toBe(true)
  expect(recorded.saves[0]!.palette_through).toBe(1)
  await page.keyboard.press('ControlOrMeta+z')
  expect(await hooks.fieldText('ctabutn0001', 'label')).not.toBe('Order now') // its undo record exists
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await expect.poll(() => hooks.fieldText('ctabutn0001', 'label')).toBe('Order now')
  await hooks.saveNow()
  const second = JSON.stringify(recorded.saves[1]!.fields)
  expect(second).toContain('Order now')
  expect(second).toContain('color.accent')
  await page.reload()
  await stage(page).locator('[data-thallo-block]').first().waitFor()
  await expect.poll(() => hooks.fieldText('ctabutn0001', 'label')).toBe('Order now')
  expect(await hooks.blockToken('ctabutn0001')).toBe('color.accent')
})

test('a stage reload between reconciliation and undo/redo keeps the history, the ledger and the adopted colour', async ({
  page,
}) => {
  const world: World = {
    saveResponses: [{ palette_rewrites: [rewrite], palette_generation: 2 }, {}],
  }
  const recorded = await openDesignPage(page, world)
  const hooks = paletteHooks(page, world)
  await hooks.setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  await hooks.saveNow()
  await hooks.setSchemaPalette({
    generation: 3,
    replacements: { after: 1, through: 3, records: [record] },
  })
  await expect.poll(() => hooks.paletteThrough()).toBe(3)
  await hooks.reloadStage() // the iframe remounts; document and history stay
  await stage(page).locator('[data-thallo-block]').first().waitFor()
  expect(await hooks.paletteThrough()).toBe(3) // not reset
  await hooks.setSchemaPalette({
    generation: 3,
    replacements: { after: 1, through: 3, records: [record] },
  })
  await page.keyboard.press('ControlOrMeta+z')
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await expect.poll(() => hooks.blockToken('ctabutn0001')).toBe('color.accent')
  await hooks.saveNow()
  expect(JSON.stringify(recorded.saves.at(-1)!.fields)).not.toContain('color.brand-1')
})

test('a rewrite never lands on a different block after a reorder during the save', async ({
  page,
}) => {
  const world: World = { saveResponses: [{ palette_rewrites: [rewrite] }], saveDelayMs: 300 }
  await openDesignPage(page, world)
  const hooks = paletteHooks(page, world)
  await hooks.setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  await hooks.setStyleToken('ctabutn0002', 'colors.text', 'color.brand-1')
  const saving = hooks.saveNow()
  await hooks.moveBlock('ctabutn0002', 'before', 'ctabutn0001')
  await saving
  await expect.poll(() => hooks.blockToken('ctabutn0001')).toBe('color.accent')
  expect(await hooks.blockToken('ctabutn0002')).toBe('color.brand-1') // only the named block
})

test('after the replacement completes, undo then redo replays Accent, not Brand 1', async ({
  page,
}) => {
  const world: World = {
    saveResponses: [{ palette_rewrites: [rewrite], palette_generation: 2 }, {}],
  }
  const recorded = await openDesignPage(page, world)
  const hooks = paletteHooks(page, world)
  await hooks.setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  await hooks.saveNow()
  // the job finished
  await hooks.setSchemaPalette({
    generation: 3,
    replacements: { after: 1, through: 3, records: [record] },
  })
  await page.keyboard.press('ControlOrMeta+z')
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await expect.poll(() => hooks.blockToken('ctabutn0001')).toBe('color.accent')
  await hooks.saveNow()
  expect(JSON.stringify(recorded.saves.at(-1)!.fields)).not.toContain('color.brand-1')
})
