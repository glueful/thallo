import { test, expect } from '@playwright/test'
import { applyNow, historyLength, idsIn, openDesignPage, selectViaOutline, stage } from '../helpers'

// §5.6: two selected siblings move down in place as ONE transaction of MoveBlocks whose
// indices are counted against the tree with the group removed.
test('Alt+ArrowDown moves the selected sections past the heading that follows them', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  await selectViaOutline(page, 'sect00000001')
  await selectViaOutline(page, 'sect00000002', ['Shift'])
  await expect(stage(page).locator('.thallo-canvas-selected')).toHaveCount(2)
  await page.keyboard.press('Alt+ArrowDown')

  const h = await historyLength(page, 1)
  const ops = h.history[0]!.ops
  expect(ops.map((o) => o.type)).toEqual(['MoveBlock', 'MoveBlock'])
  expect(new Set(ops.map((o) => o.transaction_id)).size).toBe(1)
  // Each op's `to.index` counts against the working tree at the moment it applies (spec §3.1):
  // the first section lands after the heading with its sibling still ahead of it, the second
  // lands right behind it once it has moved.
  expect(ops[0]).toMatchObject({
    block: 'sect00000001',
    from: { index: 0 },
    to: { parent: null, slot: 'body', index: 2 },
  })
  expect(ops[1]).toMatchObject({
    block: 'sect00000002',
    from: { index: 0 },
    to: { parent: null, slot: 'body', index: 2 },
  })
  expect(idsIn(h.document, ['body'])).toEqual([
    'head0000000a',
    'sect00000001',
    'sect00000002',
    'head0000000b',
  ])
  expect(h.selection.ids).toEqual(['sect00000001', 'sect00000002'])

  const sent = await applyNow(page, recorded)
  expect(sent.operations.map((o) => o.type)).toEqual(['MoveBlock', 'MoveBlock'])
})
