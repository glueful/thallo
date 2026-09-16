import { test, expect } from '@playwright/test'
import { applyNow, historyLength, idsIn, openDesignPage, slotOf } from '../helpers'

// An empty slot on the stage carries a + that arms the Blocks tab into that slot; the next tile
// click inserts there.
test('the + in an empty column arms the Blocks tab into it and a tile click inserts there', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  const slot = slotOf(page, 'cols00000002', 'col_2')
  await expect(slot).toContainText('Drag a block here')
  await slot.locator('[data-slot-add]').click()
  const strip = page.locator('[data-test="palette-target"]')
  await expect(strip).toContainText('Inserting into Columns › col_2')
  await page.locator('[data-test="palette-card-heading"]').click()

  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops[0]).toMatchObject({
    type: 'InsertBlock',
    position: { parent: 'cols00000002', slot: 'col_2', index: 0 },
    block: { type: 'heading' },
  })
  const inserted = (h.history[0]!.ops[0] as { block: { id: string } }).block.id
  expect(idsIn(h.document, ['body', 1, 'data', 'content', 0, 'data', 'col_2'])).toEqual([inserted])
  // The target is consumed and the new block is selected; the apply carries the one insert.
  await expect(strip).toHaveCount(0)
  expect(h.selection.ids).toEqual([inserted])
  const sent = await applyNow(page, recorded)
  expect(sent.operations.map((o) => o.type)).toEqual(['InsertBlock'])
})
