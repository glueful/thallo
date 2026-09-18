import { test, expect } from '@playwright/test'
import { hooks, openDesignPage, selectOnStage, stage, type Hooks } from '../helpers'

// Container-layout spec §11.3: Fill empty cells, end to end in a real browser with the real bridge.
// A grid's last row is completed with column containers as ONE change, from either surface — the
// button on an empty grid's placeholder on the stage, and the one under the Grid controls in the
// inspector — and the two never disagree, because the stage draws only what the page publishes.
type Page = Parameters<typeof openDesignPage>[0]
interface Block {
  id: string
  type: string
  data: { content?: Block[] }
  settings?: Record<string, unknown>
}

const rootBlock = (h: Hooks, id: string): Block => {
  const found = (h.document.body as Block[]).find((b) => b.id === id)
  if (!found) throw new Error(`the fixture has no root block ${id}`)
  return found
}
const contentLength = (page: Page, id: string, n: number) =>
  page.waitForFunction(
    ([blockId, want]) => {
      const snapshot = (
        window as unknown as { __thalloBuilder: { snapshot: () => Hooks } }
      ).__thalloBuilder.snapshot()
      const block = (snapshot.document.body as Block[]).find((b) => b.id === blockId)
      return (block?.data.content ?? []).length === want
    },
    [id, n] as const,
  )
const stageFill = (page: Page, id: string) =>
  stage(page).locator(`[data-thallo-block="${id}"] [data-grid-fill]`)

test('the stage button fills the empty grid as one change; undo empties it and redo restores the same cells', async ({
  page,
}) => {
  await openDesignPage(page)
  const before = await hooks(page)
  expect(rootBlock(before, 'gridempty001').data.content).toEqual([])

  // Three tracks at the width the page opens on, none taken: the button is there and enabled.
  const fill = stageFill(page, 'gridempty001')
  await expect(fill).toBeVisible()
  await expect(fill).toBeEnabled()
  // A populated grid has no placeholder, so no button — whatever room its last row has.
  await expect(stageFill(page, 'gridspan0001')).toHaveCount(0)

  await fill.click()
  await contentLength(page, 'gridempty001', 3)

  const after = await hooks(page)
  // One history entry, one transaction, three inserts — and the click selected nothing.
  expect(after.history).toHaveLength(before.history.length + 1)
  const entry = after.history[after.history.length - 1]!
  expect(entry.ops.map((op) => op.type)).toEqual(['InsertBlock', 'InsertBlock', 'InsertBlock'])
  expect(new Set(entry.ops.map((op) => op.transaction_id)).size).toBe(1)
  expect(after.selection.ids).toEqual([])
  const cells = rootBlock(after, 'gridempty001').data.content!
  expect(cells.map((c) => c.type)).toEqual(['container', 'container', 'container'])
  expect(new Set(cells.map((c) => c.id)).size).toBe(3)
  expect(cells.every((c) => (c.data.content ?? []).length === 0)).toBe(true)
  const ids = cells.map((c) => c.id)

  // The page no longer names this grid, so the stage takes the button off — even here, where the
  // captured stage never re-renders and the placeholder is still standing.
  await expect(fill).toHaveCount(0)

  // Undone from the keyboard with focus still in the stage, where the click left it: the stage
  // forwards ⌘Z to the editor, whose history this is.
  await page.keyboard.press('ControlOrMeta+z')
  await contentLength(page, 'gridempty001', 0)
  expect((await hooks(page)).currentSequence).toBe(before.currentSequence)
  // Empty again: the offer is back, from the same availability.
  await expect(stageFill(page, 'gridempty001')).toBeEnabled()

  await page.keyboard.press('ControlOrMeta+Shift+z')
  await contentLength(page, 'gridempty001', 3)
  const redone = await hooks(page)
  expect(rootBlock(redone, 'gridempty001').data.content!.map((c) => c.id)).toEqual(ids)
})

test("the inspector's button completes a part-filled last row, then says the row is full", async ({
  page,
}) => {
  await openDesignPage(page)
  await selectOnStage(page, 'gridspan0001')
  await page.locator('[data-test="block-inspector"]').waitFor()
  await page.locator('[data-test="block-inspector-tabs"] button', { hasText: 'Layout' }).click()
  await page.locator('[data-test="layout-tab"]').waitFor()

  // A heading across two of three tracks: one cell an appended block can reach.
  const button = page.locator('[data-test="layout-fill-cells"]')
  await expect(button).toBeEnabled()
  await expect(page.locator('[data-test="layout-fill-note"]')).toContainText('1 column container ')

  const before = await hooks(page)
  await button.click()
  await contentLength(page, 'gridspan0001', 2)

  const after = await hooks(page)
  expect(after.history).toHaveLength(before.history.length + 1)
  const entry = after.history[after.history.length - 1]!
  expect(entry.ops).toEqual([
    expect.objectContaining({
      type: 'InsertBlock',
      position: { parent: 'gridspan0001', slot: 'content', index: 1 },
    }),
  ])
  // What was there is untouched, and the new cell comes after it.
  expect(rootBlock(after, 'gridspan0001').data.content!.map((c) => c.type)).toEqual([
    'heading',
    'container',
  ])

  await expect(button).toBeDisabled()
  await expect(page.locator('[data-test="layout-fill-note"]')).toHaveText(
    'No empty cells in the last row',
  )
})

test('both surfaces follow the breakpoint: one track on a phone is one cell', async ({ page }) => {
  await openDesignPage(page)
  await selectOnStage(page, 'gridempty001')
  await page.locator('[data-test="block-inspector-tabs"] button', { hasText: 'Layout' }).click()
  await page.locator('[data-test="layout-tab"]').waitFor()
  await expect(page.locator('[data-test="layout-fill-note"]')).toContainText('3 column containers')

  await page.locator('[data-test="canvas-viewport-mobile"]').click()
  await expect(page.locator('[data-test="layout-fill-note"]')).toContainText('1 column container ')
  await expect(stageFill(page, 'gridempty001')).toBeEnabled()
})
