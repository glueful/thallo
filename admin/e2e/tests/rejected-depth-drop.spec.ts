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

// §5.6: a drop the depth cap refuses is refused before it lands — the indicator turns red with
// the reason, release cancels, and nothing changes: tree, history, accepted pair, apply bodies.
test('a card dropped into a depth-four container is refused: byte-identical tree, no history', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  const before = await hooks(page)
  await selectViaOutline(page, 'card00000001')
  await dragGripTo(
    page,
    gripOf(page, 'card00000001'),
    slotOf(page, 'cont00000003', 'content'),
    false,
  )
  await expect(indicator(page)).toHaveClass(/thallo-canvas-drop-line--refused/)
  await expect(indicator(page)).toHaveAttribute('title', /deep/)
  await page.mouse.up()
  await expect(indicator(page)).toHaveCount(0)

  const after = await hooks(page)
  expect(JSON.stringify(after.document)).toBe(JSON.stringify(before.document))
  expect(after.history).toEqual(before.history)
  expect(after.currentSequence).toBe(before.currentSequence)
  expect(after.accepted).toEqual(before.accepted)
  expect(recorded.applies).toHaveLength(1) // only the page's first apply
})
