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
  // Two columns blocks offer a col_2; the second in document order is the empty one.
  await page.getByRole('option', { name: 'Columns › col_2' }).last().click()
  await dialog.locator('[data-test="move-to-confirm"]').click()

  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops).toEqual([
    expect.objectContaining({
      type: 'MoveBlock',
      block: 'butn00000001',
      from: { parent: 'cont00000002', slot: 'content', index: 0 },
      to: { parent: 'cols00000002', slot: 'col_2', index: 0 },
    }),
  ])
  expect(idsIn(h.document, ['body', 1, 'data', 'content', 0, 'data', 'col_2'])).toEqual([
    'butn00000001',
  ])
})
