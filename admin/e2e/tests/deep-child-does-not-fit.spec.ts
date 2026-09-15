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

// §5.6: the root of the moved subtree would fit, but its deepest child would not — the drop is
// judged on the whole candidate tree, so it is refused all the same.
test('a columns block whose deepest child would land at depth seven is refused', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  const before = await hooks(page)
  await selectViaOutline(page, 'cols00000002')
  // A card's body accepts a columns block at depth four; its heading would sit at depth seven.
  await dragGripTo(page, gripOf(page, 'cols00000002'), slotOf(page, 'card00000002', 'body'), false)
  await expect(indicator(page)).toHaveClass(/thallo-canvas-drop-line--refused/)
  await page.mouse.up()

  const after = await hooks(page)
  expect(JSON.stringify(after.document)).toBe(JSON.stringify(before.document))
  expect(after.history).toEqual([])
  expect(recorded.applies).toHaveLength(1)
})
