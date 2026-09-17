import { test, expect } from '@playwright/test'
import { historyLength, idsIn, openDesignPage, selectViaOutline, stage } from '../helpers'

// Phase C.1: the stage + arms the Blocks tab after the block; Enter in its search inserts the
// first match there and clears the target.
test('the stage + arms the tab after the section and Enter inserts the first match there', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectViaOutline(page, 'sect00000002')
  await stage(page).locator('[data-thallo-block="sect00000002"] [data-action="add-after"]').click()
  const strip = page.locator('[data-test="palette-target"]')
  await expect(strip).toContainText('Inserting after Section')
  const search = page.locator('[data-test="palette-search"]')
  await expect(search).toBeFocused()
  await search.fill('heading')
  await search.press('Enter')

  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops[0]).toMatchObject({
    type: 'InsertBlock',
    position: { parent: null, slot: 'body', index: 2 },
    block: { type: 'heading' },
  })
  const inserted = (h.history[0]!.ops[0] as { block: { id: string } }).block.id
  expect(idsIn(h.document, ['body'])).toEqual([
    'sect00000001',
    'sect00000002',
    inserted,
    'head0000000a',
    'head0000000b',
    'grid00000001',
  ])
  await expect(strip).toHaveCount(0) // consumed
})
