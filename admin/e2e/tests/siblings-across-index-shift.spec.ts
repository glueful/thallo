import { test, expect } from '@playwright/test'
import {
  dragGripTo,
  gripOf,
  historyLength,
  idsIn,
  openDesignPage,
  selectViaOutline,
  slotOf,
} from '../helpers'

// §5.6: two root siblings dragged into another slot: the second block's `from` index is read
// after the first has left (the index shift), and both `to` indices count against the
// reduced tree — replaying the transaction in order reproduces the candidate tree exactly.
test('two selected headings drag together into an empty column with shifted indices', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectViaOutline(page, 'head0000000a')
  await selectViaOutline(page, 'head0000000b', ['Shift'])
  await dragGripTo(page, gripOf(page, 'head0000000a'), slotOf(page, 'colb00000002', 'content'))

  const h = await historyLength(page, 1)
  const ops = h.history[0]!.ops
  expect(ops).toEqual([
    expect.objectContaining({
      type: 'MoveBlock',
      block: 'head0000000a',
      from: { parent: null, slot: 'body', index: 2 },
      to: { parent: 'colb00000002', slot: 'content', index: 0 },
    }),
    expect.objectContaining({
      type: 'MoveBlock',
      block: 'head0000000b',
      from: { parent: null, slot: 'body', index: 2 },
      to: { parent: 'colb00000002', slot: 'content', index: 1 },
    }),
  ])
  expect(idsIn(h.document, ['body'])).toEqual([
    'sect00000001',
    'sect00000002',
    'grid00000001',
    'ctaa00000001',
  ])
  expect(
    idsIn(h.document, ['body', 1, 'data', 'content', 0, 'data', 'content', 1, 'data', 'content']),
  ).toEqual(['head0000000a', 'head0000000b'])
})
