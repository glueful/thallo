import { test, expect } from '@playwright/test'
import {
  applyNow,
  centerOf,
  dragTileTo,
  historyLength,
  hooks,
  idsIn,
  indicator,
  openBlocksTab,
  openDesignPage,
  slotOf,
  stage,
} from '../helpers'

// Phase C.1: a new block dragged from the Blocks tab onto the stage. The drop is the zone under
// the released pointer, judged against the current document; the tree, history and the apply
// body agree — and a refused or abandoned drop changes nothing at all.
test('a heading dragged into an empty column inserts one block with its starter, selected', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  await openBlocksTab(page)
  await dragTileTo(page, 'heading', slotOf(page, 'colb00000002', 'content'))

  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops).toHaveLength(1)
  expect(h.history[0]!.ops[0]).toMatchObject({
    type: 'InsertBlock',
    position: { parent: 'colb00000002', slot: 'content', index: 0 },
    block: { type: 'heading', data: { text: 'Heading' } },
  })
  const inserted = (h.history[0]!.ops[0] as { block: { id: string } }).block.id
  expect(
    idsIn(h.document, ['body', 1, 'data', 'content', 0, 'data', 'content', 1, 'data', 'content']),
  ).toEqual([inserted])
  expect(h.selection.ids).toEqual([inserted])

  const sent = await applyNow(page, recorded)
  expect(sent.operations.map((o) => o.type)).toEqual(['InsertBlock'])
})

test('a heading released over a button-only slot is refused: byte-identical tree, no history, no apply', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  const before = await hooks(page)
  await openBlocksTab(page)
  // A call to action's links slot admits buttons only; a fresh heading is a leaf, so the
  // allow-list is the rule that refuses it (a fresh card would fit even at depth five).
  await dragTileTo(page, 'heading', slotOf(page, 'ctaa00000001', 'links'), false)
  await expect(indicator(page)).toHaveClass(/thallo-canvas-drop-line--refused/)
  await page.mouse.up()
  await expect(indicator(page)).toHaveCount(0)
  await expect(page.locator('.thallo-palette-ghost')).toHaveCount(0)

  const after = await hooks(page)
  expect(JSON.stringify(after.document)).toBe(JSON.stringify(before.document))
  expect(after.history).toEqual(before.history)
  expect(after.currentSequence).toBe(before.currentSequence)
  expect(after.accepted).toEqual(before.accepted)
  expect(recorded.applies).toHaveLength(1) // only the page's first apply
})

test('a drag that hovers a column and is released over blank canvas inserts nothing', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  const before = await hooks(page)
  await openBlocksTab(page)
  await dragTileTo(page, 'heading', slotOf(page, 'colb00000002', 'content'), false)
  await expect(indicator(page)).toHaveCount(1)
  // Leave every slot: the site header holds none. Released there, the stage answers a cancel.
  const header = stage(page).locator('header.site-header')
  await header.scrollIntoViewIfNeeded()
  const to = await centerOf(header)
  await page.mouse.move(to.x, to.y, { steps: 6 })
  await expect(indicator(page)).toHaveCount(0)
  await page.mouse.up()
  await expect(page.locator('.thallo-palette-ghost')).toHaveCount(0)

  const after = await hooks(page)
  expect(JSON.stringify(after.document)).toBe(JSON.stringify(before.document))
  expect(after.history).toEqual(before.history)
  expect(after.currentSequence).toBe(before.currentSequence)
  expect(after.accepted).toEqual(before.accepted)
  expect(recorded.applies).toHaveLength(1)
})
