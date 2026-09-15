import { test, expect } from '@playwright/test'
import {
  applyNow,
  dragGripTo,
  gripOf,
  historyLength,
  hooks,
  idsIn,
  openDesignPage,
  selectViaOutline,
  slotOf,
} from '../helpers'

// §5.6: a stage drag across containers is one MoveBlock from the real source position to the
// zone the slot's geometry named; the tree, history and the apply body all agree.
test("a stage drag moves a depth-five heading into another section's empty column", async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  await selectViaOutline(page, 'head00000001')
  await dragGripTo(page, gripOf(page, 'head00000001'), slotOf(page, 'cols00000002', 'col_2'))

  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops).toEqual([
    expect.objectContaining({
      type: 'MoveBlock',
      block: 'head00000001',
      from: { parent: 'cont00000001', slot: 'content', index: 0 },
      to: { parent: 'cols00000002', slot: 'col_2', index: 0 },
    }),
  ])
  expect(idsIn(h.document, ['body', 1, 'data', 'content', 0, 'data', 'col_2'])).toEqual([
    'head00000001',
  ])
  expect(
    idsIn(h.document, [
      'body',
      0,
      'data',
      'content',
      0,
      'data',
      'col_1',
      0,
      'data',
      'body',
      0,
      'data',
      'content',
    ]),
  ).toEqual([])

  const sent = await applyNow(page, recorded)
  expect(sent.operations.map((o) => o.type)).toEqual(['MoveBlock'])
  expect((await hooks(page)).accepted).toEqual({ epoch: 'proof', revision: 2 })
})
