import { test, expect, type Page } from '@playwright/test'
import { hooks, openDesignPage, selectOnStage, stage } from '../helpers'

// Container-layout spec §11.2: the stage draws a grid's tracks. Two things are proven here, in a
// real browser with the real bridge, that jsdom cannot show: the outline is INERT — it moves and
// resizes nothing — and it stays ALIGNED with the grid it outlines when the page changes under it.

interface Box {
  left: number
  top: number
  width: number
  height: number
}
const round = (b: Box): Box => ({
  left: Math.round(b.left * 10) / 10,
  top: Math.round(b.top * 10) / 10,
  width: Math.round(b.width * 10) / 10,
  height: Math.round(b.height * 10) / 10,
})

/** The boxes of a container's children, as the browser laid them out. */
async function childBoxes(page: Page, id: string): Promise<Box[]> {
  return stage(page)
    .locator('body')
    .evaluate((_, id) => {
      const slot = document.querySelector(
        `[data-thallo-block="${id}"] [data-thallo-slot="content"]`,
      )!
      return [...slot.querySelectorAll(':scope > [data-thallo-block]')].map((w) => {
        const r = w.firstElementChild!.getBoundingClientRect()
        return { left: r.left, top: r.top, width: r.width, height: r.height }
      })
    }, id)
    .then((boxes) => boxes.map(round))
}

/** The outlined cells, and — computed independently, from the slot's resolved tracks — every track cell. */
async function outlineAndTracks(page: Page, id: string) {
  return stage(page)
    .locator('body')
    .evaluate((_, id) => {
      const box = (r: DOMRect) => ({ left: r.left, top: r.top, width: r.width, height: r.height })
      const cells = [...document.querySelectorAll('.thallo-grid-outline__cell')].map((c) => ({
        ...box(c.getBoundingClientRect()),
        free: c.classList.contains('thallo-grid-outline__cell--free'),
      }))
      const slot = document.querySelector(
        `[data-thallo-block="${id}"] [data-thallo-slot="content"]`,
      )!
      const cs = getComputedStyle(slot)
      const px = (v: string) => parseFloat(v) || 0
      const list = (v: string) =>
        v
          .split(/\s+/)
          .filter((p) => p.endsWith('px'))
          .map(px)
      const r = slot.getBoundingClientRect()
      const cols = list(cs.gridTemplateColumns)
      const rows = list(cs.gridTemplateRows)
      const tracks: { left: number; top: number; width: number; height: number }[] = []
      let y = r.top + px(cs.borderTopWidth) + px(cs.paddingTop)
      for (const row of rows) {
        let x = r.left + px(cs.borderLeftWidth) + px(cs.paddingLeft)
        for (const col of cols) {
          tracks.push({ left: x, top: y, width: col, height: row })
          x += col + px(cs.columnGap)
        }
        y += row + px(cs.rowGap)
      }
      return { cells, tracks, columnGap: px(cs.columnGap) }
    }, id)
}

const near = (a: number, b: number) => Math.abs(a - b) <= 1

/** gridspan0001: a heading across tracks one and two, and track three free. */
async function expectSpanGridAligned(page: Page, when: string) {
  await expect
    .poll(async () => (await outlineAndTracks(page, 'gridspan0001')).cells.length, {
      message: when,
    })
    .toBe(2)
  const { cells, tracks, columnGap } = await outlineAndTracks(page, 'gridspan0001')
  expect(tracks, when).toHaveLength(3)
  const [spanned, free] = cells
  expect(spanned!.free, when).toBe(false)
  expect(free!.free, when).toBe(true)
  const wanted = [
    { ...tracks[0]!, width: tracks[0]!.width + columnGap + tracks[1]!.width },
    tracks[2]!,
  ]
  for (const [i, cell] of [spanned!, free!].entries()) {
    for (const edge of ['left', 'top', 'width', 'height'] as const) {
      expect(
        near(cell[edge], wanted[i]![edge]),
        `${when}: cell ${i} ${edge} ${cell[edge]} vs ${wanted[i]![edge]}`,
      ).toBe(true)
    }
  }
}

test('an empty grid is outlined from the start, and its placeholder takes one cell', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1900, height: 2000 }) // a stage wide enough for md: three tracks
  await openDesignPage(page)
  await expect.poll(async () => (await outlineAndTracks(page, 'gridempty001')).cells.length).toBe(3)
  const { cells, tracks } = await outlineAndTracks(page, 'gridempty001')
  expect(cells.every((c) => c.free)).toBe(true)
  for (const [i, cell] of cells.entries()) {
    expect(near(cell.left, tracks[i]!.left) && near(cell.width, tracks[i]!.width)).toBe(true)
  }
  // The placeholder sits in the first track instead of spanning the row.
  const placeholder = await stage(page)
    .locator('[data-thallo-block="gridempty001"] .thallo-slot-placeholder')
    .boundingBox()
  const frame = await page.locator('[data-test="canvas-iframe"]').boundingBox()
  expect(near(placeholder!.x - frame!.x, tracks[0]!.left)).toBe(true)
  expect(near(placeholder!.width, tracks[0]!.width)).toBe(true)
})

test('the outline is inert: every child box is identical with it shown and hidden', async ({
  page,
}) => {
  await openDesignPage(page)
  for (const id of ['gridspan0001', 'grid00000001']) {
    const before = await childBoxes(page, id)
    expect(before.length).toBeGreaterThan(0)
    await selectOnStage(page, id)
    await expect
      .poll(async () => (await outlineAndTracks(page, id)).cells.length)
      .toBeGreaterThan(0)
    expect(await childBoxes(page, id), `${id} with its outline shown`).toEqual(before)
  }
})

test('the outline takes no pointer events: a click through it selects the block beneath', async ({
  page,
}) => {
  await openDesignPage(page)
  await selectOnStage(page, 'grid00000001')
  await expect.poll(async () => (await outlineAndTracks(page, 'grid00000001')).cells.length).toBe(3)
  // The second heading lies under an outlined cell.
  await stage(page).locator('[data-thallo-block="gridhead0002"] h2').click()
  await expect.poll(async () => (await hooks(page)).selection.ids).toEqual(['gridhead0002'])
  // Still outlined: the selection is a block inside the grid.
  expect((await outlineAndTracks(page, 'grid00000001')).cells.length).toBe(3)
})

test('the outline stays aligned: as drawn, after a resize, a scroll, and late content', async ({
  page,
}) => {
  // A response the test holds back, so the image changes the layout well after the outline is drawn.
  let release: () => void = () => undefined
  const held = new Promise<void>((r) => (release = r))
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
  )
  await page.route('**/late-image.png', async (route) => {
    await held
    await route.fulfill({ status: 200, contentType: 'image/png', body: png })
  })

  await openDesignPage(page)
  await selectOnStage(page, 'gridspan0001')
  await expectSpanGridAligned(page, 'as first drawn')

  // A resize moves and rescales every track.
  await page.setViewportSize({ width: 1500, height: 2000 })
  await expectSpanGridAligned(page, 'after a resize')

  // Across md the empty grid goes from one track to three: the cell COUNT follows, not only the
  // geometry. (Deselect, so the empty grid is the one outlined.)
  await page.setViewportSize({ width: 1280, height: 2000 })
  await stage(page)
    .locator('body')
    .evaluate(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    })
  await expect.poll(async () => (await outlineAndTracks(page, 'gridempty001')).cells.length).toBe(1)
  await page.setViewportSize({ width: 1900, height: 2000 })
  await expect.poll(async () => (await outlineAndTracks(page, 'gridempty001')).cells.length).toBe(3)

  // A scroll inside the stage.
  await page.setViewportSize({ width: 1280, height: 900 })
  await selectOnStage(page, 'gridspan0001')
  await stage(page)
    .locator('body')
    .evaluate(() => window.scrollTo(0, 60))
  await expectSpanGridAligned(page, 'after a scroll')

  // Late content: an image above the grid finishes loading and pushes the grid down.
  const topBefore = (await outlineAndTracks(page, 'gridspan0001')).tracks[0]!.top
  await stage(page)
    .locator('body')
    .evaluate(() => {
      const img = document.createElement('img')
      img.id = 'late-image'
      img.style.width = '300px' // no height until it loads: then 300px, from its 1:1 ratio
      img.style.display = 'block'
      img.src = '/late-image.png'
      const grid = document.querySelector('[data-thallo-block="gridspan0001"]')!
      grid.parentElement!.insertBefore(img, grid)
    })
  release()
  await stage(page)
    .locator('#late-image')
    .evaluate(
      (img: HTMLImageElement) =>
        img.complete || new Promise((r) => img.addEventListener('load', r)),
    )
  await expect
    .poll(async () => (await outlineAndTracks(page, 'gridspan0001')).tracks[0]!.top - topBefore)
    .toBeGreaterThan(250)
  await expectSpanGridAligned(page, 'after an image loaded above it')
})
