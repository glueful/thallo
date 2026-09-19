import { test, expect } from '@playwright/test'
import { hooks, openDesignPage, selectOnStage, selectViaOutline, stage } from '../helpers'

// The Block → Content tab edits what the main Content tab edits. A field that holds blocks is its
// list of cards and a prose body is its editor — the same components, in the root blocks field's
// own context — so the panel is no longer a lesser form of the block it shows. Proven here with
// the real editor and a real caret, which jsdom does not have.
type Page = Parameters<typeof openDesignPage>[0]
interface Block {
  id: string
  type: string
  data: Record<string, unknown>
}
const inspector = (page: Page) => page.locator('[data-test="block-inspector"]')

/** A block anywhere in the document snapshot. */
async function blockIn(page: Page, id: string): Promise<Block | null> {
  const h = await hooks(page)
  const walk = (list: unknown): Block | null => {
    if (!Array.isArray(list)) return null
    for (const block of list as Block[]) {
      if (block.id === id) return block
      for (const value of Object.values(block.data ?? {})) {
        const hit = walk(value)
        if (hit) return hit
      }
    }
    return null
  }
  return walk(h.document.body)
}

test('a container shows its children as cards, and one is edited and removed from the panel', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectOnStage(page, 'grid00000001')
  await inspector(page).waitFor()

  // The three headings, as cards — where there used to be the line "content: 3 blocks".
  for (const id of ['gridhead0001', 'gridhead0002', 'gridhead0003']) {
    await expect(inspector(page).locator(`[data-test="block-card-${id}"]`)).toBeVisible()
  }
  await expect(inspector(page).locator('[data-test="region-summary-content"]')).toHaveCount(0)

  await inspector(page).locator('[data-test="block-toggle-gridhead0002"]').click()
  const text = inspector(page).locator('[data-test="block-card-gridhead0002"] input[name="text"]')
  await text.fill('Two, from the panel')
  await expect
    .poll(async () => (await blockIn(page, 'gridhead0002'))?.data.text)
    .toBe('Two, from the panel')

  await inspector(page).locator('[data-test="block-delete-gridhead0003"]').click()
  await inspector(page).locator('[data-test="block-delete-confirm"]').click()
  await expect.poll(async () => blockIn(page, 'gridhead0003')).toBeNull()

  // The container is still what is selected: its children were worked on without leaving it.
  const h = await hooks(page)
  expect(h.selection.ids).toEqual(['grid00000001'])
  expect(h.history.flatMap((entry) => entry.ops.map((op) => op.type))).toEqual([
    'SetField',
    'RemoveBlock',
  ])
})

test('a rich text body is written in the panel, and the stage and the panel never hold it at once', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectViaOutline(page, 'prose0000001')
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Block', exact: true })
    .click()
  await inspector(page).waitFor()

  const editor = inspector(page).locator('.ProseMirror')
  await expect(editor).toHaveAttribute('contenteditable', 'true')
  await expect(editor).toContainText('Written once.')
  await expect(inspector(page).locator('[data-test="prose-on-stage"]')).toContainText('Write here')

  // Written in the panel.
  await editor.click()
  await page.keyboard.press('ControlOrMeta+End')
  await page.keyboard.type(' And again.')
  await expect
    .poll(async () => String((await blockIn(page, 'prose0000001'))?.data.body))
    .toContain('Written once. And again.')

  // The stage takes over: a double-click on the text there starts a session, and the panel lets go.
  const region = stage(page).locator('[data-thallo-edit-block="prose0000001"]')
  await region.dblclick()
  await expect(region).toHaveAttribute('contenteditable', 'true')
  await expect(editor).toHaveAttribute('contenteditable', 'false')
  await expect(inspector(page).locator('[data-test="prose-locked"]')).toContainText('Esc')
  expect(
    await page.evaluate(() => !!document.activeElement?.closest('[data-test="block-inspector"]')),
    'the panel no longer holds the caret',
  ).toBe(false)

  // Esc on the stage ends the session: the panel is writable again.
  await region.press('Escape')
  await expect(editor).toHaveAttribute('contenteditable', 'true')
  await expect(inspector(page).locator('[data-test="prose-locked"]')).toHaveCount(0)
})
