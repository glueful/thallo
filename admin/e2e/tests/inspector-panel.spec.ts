import { test, expect } from '@playwright/test'
import { openDesignPage, selectOnStage } from '../helpers'

// The inspector is a scrolling column, and its content must not be laid out flush against the edge
// it scrolls at. Two faults came from that, both only visible in a real browser:
//
//  - the panel scrolled SIDEWAYS. `overflow-y: auto` makes `overflow-x` compute to `auto` too, so
//    anything past the edge scrolls the whole panel — and the "declared at this breakpoint" dot sits
//    2px outside its chip. On the last chip of a flush-right row that is 2px past the panel. It
//    needs a declaration at `lg`, the breakpoint the page opens on, which is why it was everywhere
//    in use and absent from a fixture that declares at `base`;
//  - macOS paints its overlay scrollbar ON TOP of the content, over the state badges, the
//    breakpoint chips and the link toggle, because nothing kept them clear of it.
const GUTTER = 16 // px: an expanded overlay scrollbar is 15

test('the inspector never scrolls sideways, and keeps its content clear of the scrollbar', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectOnStage(page, 'grid00000001')
  await page.locator('[data-test="block-inspector-tabs"] button', { hasText: 'Layout' }).click()
  await page.locator('[data-test="layout-tab"]').waitFor()
  // The page opens on lg, so this is a declaration AT lg: the dot lands on the last chip.
  await page.locator('[data-test="style-field-layout.display"] [data-test="choice-flex"]').click()
  await expect(
    page.locator('[data-test="layout-breakpoint-container-lg"] span.bg-warning'),
  ).toBeVisible()

  for (const tab of ['Layout', 'Style', 'Advanced', 'Content']) {
    await page
      .locator('[data-test="block-inspector-tabs"]')
      .getByRole('tab', { name: tab, exact: true })
      .click()
    const panel = await page.evaluate(() => {
      const aside = document.querySelector('[data-test="canvas-inspector"]') as HTMLElement
      const edge = aside.getBoundingClientRect().right
      let rightmost = 0
      let who = ''
      for (const el of aside.querySelectorAll<HTMLElement>('*')) {
        const r = el.getBoundingClientRect()
        if (r.width === 0 || r.height === 0) continue
        if (r.right > rightmost) {
          rightmost = r.right
          who = `${el.tagName.toLowerCase()}.${el.className.toString().split(' ').slice(0, 4).join('.')}`
        }
      }
      return {
        scrollWidth: aside.scrollWidth,
        clientWidth: aside.clientWidth,
        clearance: Math.round(edge - rightmost),
        who,
      }
    })
    expect(panel.scrollWidth, `${tab}: scrolls sideways`).toBe(panel.clientWidth)
    expect(panel.clearance, `${tab}: ${panel.who} reaches the scrollbar`).toBeGreaterThanOrEqual(
      GUTTER - 2, // the dot may use 2px of it
    )
  }
})

// Every top-level tab shares the one scrolling column, so every one of them is held to it — the
// Blocks tab's tiles sat under the scrollbar exactly as the Layout tab's badges did. The Outline
// had a fault of its own: an empty slot's row was full width AND indented by a margin, so it was
// wider than the panel by exactly its indent, more with every level of nesting.
test('every tab of the side panel fits it: no sideways scroll, nothing under the scrollbar', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectOnStage(page, 'grid00000001') // so the Block tab is there too
  await page.locator('[data-test="block-inspector"]').waitFor()
  const list = page.locator('[data-test="inspector-tabs"] > [role="tablist"]')
  const names = (await list.getByRole('tab').allTextContents()).map((name) => name.trim())
  // Outline, SEO and Versions are icon tabs: their names are screen-reader text, still read here.
  expect(names).toEqual(['Block', 'Content', 'Blocks', 'Page', 'Outline', 'SEO', 'Versions'])
  // Seven labels did not fit the row, and each was cut short. Here they fitted by 3% — a slightly
  // wider font, as on an editor's own machine, clipped them — so the row is held to headroom, not
  // to fitting in this browser's font: its tabs at their natural widths take at most 90% of it.
  const row = await list.evaluate((el) => {
    const tabs = [...el.querySelectorAll<HTMLElement>('[role="tab"]')]
    const natural = tabs.reduce((sum, tab) => {
      tab.style.flexShrink = '0'
      const width = tab.getBoundingClientRect().width
      tab.style.flexShrink = ''
      return sum + width
    }, 0)
    return { natural, available: el.clientWidth }
  })
  expect(row.natural, 'the tab row has no room to spare').toBeLessThanOrEqual(row.available * 0.9)

  for (const name of names) {
    await list.getByRole('tab', { name, exact: true }).click()
    const panel = await page.evaluate(() => {
      const aside = document.querySelector('[data-test="canvas-inspector"]') as HTMLElement
      const edge = aside.getBoundingClientRect().right
      let rightmost = 0
      let who = ''
      for (const el of aside.querySelectorAll<HTMLElement>('*')) {
        const r = el.getBoundingClientRect()
        if (r.width === 0 || r.height === 0 || r.right <= rightmost) continue
        rightmost = r.right
        who = el.getAttribute('data-test') ?? el.tagName.toLowerCase()
      }
      return {
        scrollWidth: aside.scrollWidth,
        clientWidth: aside.clientWidth,
        clearance: Math.round(edge - rightmost),
        who,
      }
    })
    expect(panel.scrollWidth, `${name}: scrolls sideways (${panel.who})`).toBe(panel.clientWidth)
    expect(panel.clearance, `${name}: ${panel.who} reaches the scrollbar`).toBeGreaterThanOrEqual(
      GUTTER - 2,
    )
  }
})
