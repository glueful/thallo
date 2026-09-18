import { test, expect } from '@playwright/test'
import { historyLength, idsIn, openDesignPage, openOutline } from '../helpers'

// §5.6: the outline reparents through Move to… — the dialog names a parent, a slot and a
// position and the coordinator judges the move exactly as a drag would.
test("Move to… from the outline reparents a button into the second section's empty column", async ({
  page,
}) => {
  await openDesignPage(page)
  await openOutline(page)
  await page.locator('[data-test="canvas-outline-item-butn00000001"]').click()
  await page.keyboard.press('m') // the row keeps focus; the outline's keyboard scheme acts on the selection
  const dialog = page.getByRole('dialog', { name: 'Move to…' })
  await dialog.waitFor()
  await dialog.locator('[data-test="move-to-destination"]').click()
  // Every container's slot is labelled the same, so the destination is named by its place in the
  // document order the dialog lists: sect1, cols1, cont1, cont2, sect2, cols2, cont3, then the
  // empty column — the eighth — then the root grid, and the outline proof's two grids. The count
  // is asserted first, so a fixture that gains or loses a container fails here instead of quietly
  // moving the block elsewhere.
  const containers = page.getByRole('option', { name: 'Container › content' })
  await expect(containers).toHaveCount(11)
  await containers.nth(7).click()
  await dialog.locator('[data-test="move-to-confirm"]').click()

  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops).toEqual([
    expect.objectContaining({
      type: 'MoveBlock',
      block: 'butn00000001',
      from: { parent: 'cont00000002', slot: 'content', index: 0 },
      to: { parent: 'colb00000002', slot: 'content', index: 0 },
    }),
  ])
  expect(
    idsIn(h.document, ['body', 1, 'data', 'content', 0, 'data', 'content', 1, 'data', 'content']),
  ).toEqual(['butn00000001'])
})
