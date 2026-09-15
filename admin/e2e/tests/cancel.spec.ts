import { test, expect } from '@playwright/test'
import {
  dragGripTo,
  gripOf,
  hooks,
  indicator,
  openDesignPage,
  selectViaOutline,
  slotOf,
} from '../helpers'

// §5.6: Escape mid-drag discards the session with nothing changed — the tree is byte-identical,
// history and the accepted pair are unchanged, and no apply was sent.
test('Escape cancels a stage drag over a legal zone', async ({ page }) => {
  const recorded = await openDesignPage(page)
  const before = await hooks(page)
  await selectViaOutline(page, 'head00000001')
  await dragGripTo(page, gripOf(page, 'head00000001'), slotOf(page, 'cols00000002', 'col_2'), false)
  await expect(indicator(page)).toHaveCount(1)
  await expect(indicator(page)).not.toHaveClass(/thallo-canvas-drop-line--refused/)
  await page.keyboard.press('Escape')
  await expect(indicator(page)).toHaveCount(0)
  await page.mouse.up()

  const after = await hooks(page)
  expect(JSON.stringify(after.document)).toBe(JSON.stringify(before.document))
  expect(after.history).toEqual(before.history)
  expect(after.currentSequence).toBe(before.currentSequence)
  expect(after.accepted).toEqual(before.accepted)
  expect(recorded.applies).toHaveLength(1)
})
