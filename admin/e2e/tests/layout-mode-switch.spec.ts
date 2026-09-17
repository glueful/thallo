import { test, expect } from '@playwright/test'
import { applyNow, hooks, openDesignPage, selectOnStage, stage } from '../helpers'

// Container-layout spec §5: the Layout tab reads the mode a container is really in, and a mode
// switch writes settings without discarding the ones the other mode used.
//
// What this harness can and cannot show: the stage is the captured fixture page and an apply is
// answered with no fragments, so the rendering never changes here. The geometry of a mode change
// is proven where the page is really re-rendered, in `tools/runtime-browser/tests/layout.spec.js`
// (`dormancy-flex-then-grid-md`). What this proof adds is the editor's half — that the tab's state
// comes from the document, that the writes are the operations the contract expects at the
// breakpoint being edited, and that switching mode keeps what the other mode was using.
const CHILDREN = ['gridhead0001', 'gridhead0002', 'gridhead0003']

test('the Layout tab drives a container mode switch and keeps what the other mode used', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)

  // The fixture's container really is a grid of three tracks: one row, three left edges. The tab's
  // state below is read from the same settings that produced this.
  const boxes = []
  for (const id of CHILDREN) {
    const box = await stage(page).locator(`[data-thallo-block="${id}"] > *`).first().boundingBox()
    if (!box) throw new Error(`${id} has no box on the stage`)
    boxes.push({ left: Math.round(box.x), top: Math.round(box.y) })
  }
  expect(new Set(boxes.map((b) => b.top)).size).toBe(1)
  expect(new Set(boxes.map((b) => b.left)).size).toBe(3)

  // Selected the way an author would: a stage click, which brings up the Block inspector.
  await selectOnStage(page, 'grid00000001')
  await page.locator('[data-test="block-inspector"]').waitFor()
  await page.locator('[data-test="block-inspector-tabs"] button', { hasText: 'Layout' }).click()
  await page.locator('[data-test="layout-tab"]').waitFor()

  // The tracks are offered, and the one in force is the one the document carries.
  await expect(page.locator('[data-test="track-3"]')).toHaveAttribute('aria-pressed', 'true')
  await expect(page.locator('[data-test="layout-field-layout.direction"]')).toHaveCount(0)

  // Switch to a flex column: two settings, each written at the breakpoint being edited — the page
  // opens on the widest one, and the operation says so rather than assuming base.
  await page.locator('[data-test="style-field-layout.display"] [data-test="choice-flex"]').click()
  await page
    .locator('[data-test="layout-field-layout.direction"] [data-test="choice-column"]')
    .click()

  // The controls followed the new mode: direction appeared, the tracks went away.
  await expect(page.locator('[data-test="track-3"]')).toHaveCount(0)

  const apply = await applyNow(page, recorded)
  expect(apply.operations.map((op) => op.type)).toEqual(['SetSetting', 'SetSetting'])
  expect(apply.operations[0]).toMatchObject({
    type: 'SetSetting',
    block: 'grid00000001',
    path: 'layout.display',
    breakpoint: 'lg',
    to: { present: true, value: { type: 'choice', value: 'flex' } },
  })
  expect(apply.operations[1]).toMatchObject({
    type: 'SetSetting',
    block: 'grid00000001',
    path: 'layout.direction',
    breakpoint: 'lg',
    to: { present: true, value: { type: 'choice', value: 'column' } },
  })

  // The track count is kept, not deleted — and the tab says so while the mode ignores it.
  await expect(page.locator('[data-test="layout-dormant-parent"]')).toContainText('Columns')
  const snapshot = await hooks(page)
  const body = snapshot.document.body as Record<string, unknown>[]
  const container = body.find((b) => b.id === 'grid00000001')!
  const layout = (container.settings as { style: { layout: Record<string, unknown> } }).style.layout
  expect(layout.columns).toBeDefined()
  expect(layout.display).toMatchObject({ lg: { type: 'choice', value: 'flex' } })

  // Back to grid: the kept tracks are in force again with nothing re-entered, and the disclosure
  // turns around — the direction just set is now the setting being kept and ignored.
  await page.locator('[data-test="style-field-layout.display"] [data-test="choice-grid"]').click()
  await expect(page.locator('[data-test="track-3"]')).toHaveAttribute('aria-pressed', 'true')
  await expect(page.locator('[data-test="layout-dormant-parent"]')).toContainText('Direction')
})
