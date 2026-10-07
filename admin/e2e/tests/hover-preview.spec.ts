import { test, expect, type Page } from '@playwright/test'
import { applyNow, hooks, openDesignPage, selectViaOutline, stage } from '../helpers'

// The stage's Hover preview (hover state spec §6.3), in a real browser against the stage the real
// renderer made: while a Style tab's Hover is on, every element the selected block owns for the
// target or part carries [data-thallo-hover] — every link of a Links block, none of a nested one's,
// each link of a Social links row — and keeps it across a parent's fragment swap and a full reload;
// Normal, a new selection or leaving the Style tab clears it.

const forcedIn = (page: Page, selector: string) =>
  stage(page).locator(`${selector}[data-thallo-hover]`)
const anyForced = (page: Page) => stage(page).locator('[data-thallo-hover]')

async function select(page: Page, id: string): Promise<void> {
  await selectViaOutline(page, id)
  await page
    .locator('[data-test="inspector-tabs"]')
    .getByRole('tab', { name: 'Block', exact: true })
    .click()
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Style', exact: true })
    .click()
}

/** The Hover switch of a section: the block's own, or a part's. */
const hoverSwitch = (page: Page, group: string, part?: string) =>
  page
    .locator(
      `${part ? `[data-test="style-part-${part}"] ` : ''}[data-test="style-state-hover-${group}"]`,
    )
    .first()

test("forces the selected Button's look, and Normal clears it", async ({ page }) => {
  await openDesignPage(page)
  await select(page, 'ctabutn0001')
  await hoverSwitch(page, 'colors').click()
  await expect(
    forcedIn(page, '[data-thallo-block="ctabutn0001"] .thallo-block-button__link'),
  ).toHaveCount(1)
  await page.locator('[data-test="style-state-normal-colors"]').first().click()
  await expect(anyForced(page)).toHaveCount(0)
})

test('reaches every link of a Links block and none of a nested one', async ({ page }) => {
  await openDesignPage(page)
  await select(page, 'hovlinks0001')
  await hoverSwitch(page, 'colors', 'link').click()
  await expect(
    forcedIn(page, '[data-thallo-block="hovlinks0001"] .thallo-block-links__link'),
  ).toHaveCount(3)
  await expect(
    forcedIn(page, '[data-thallo-block="hovlinks0002"] .thallo-block-links__link'),
  ).toHaveCount(0)
})

test('reaches every link of a Social links row', async ({ page }) => {
  await openDesignPage(page)
  await select(page, 'hovsocial001')
  await hoverSwitch(page, 'colors', 'icon').click()
  await expect(
    forcedIn(page, '[data-thallo-block="hovsocial001"] .thallo-block-social_link__link'),
  ).toHaveCount(2)
})

test("the force survives a parent's fragment patch, with the Button still selected", async ({
  page,
}) => {
  let next: Record<string, string> | null = null
  const recorded = await openDesignPage(page, { fragments: () => next })
  await select(page, 'ctabutn0001')
  await hoverSwitch(page, 'colors').click()
  const link = stage(page).locator('[data-thallo-block="ctabutn0001"] .thallo-block-button__link')
  await expect(link).toHaveAttribute('data-thallo-hover', '')

  // The parent's fragment, from a clean clone of the stage's own CTA: no forced attribute in it, so
  // only the bridge can put it back; a marker proves the child's DOM was replaced.
  const fragment = await stage(page)
    .locator('[data-thallo-block="ctaa00000001"]')
    .evaluate((el) => {
      const clone = el.cloneNode(true) as HTMLElement
      clone.removeAttribute('data-thallo-hover')
      clone
        .querySelectorAll('[data-thallo-hover]')
        .forEach((n) => n.removeAttribute('data-thallo-hover'))
      clone.querySelector('.thallo-block-button__link')!.setAttribute('data-proof-swap', '1')
      return clone.outerHTML
    })
  expect(fragment).toContain('data-proof-swap="1"')
  expect(fragment).not.toContain('data-thallo-hover')
  next = { ctaa00000001: fragment }

  // An edit to the Button in its own open Hover panel: the selection does not move.
  await page
    .locator('[data-test="style-field-hover.colors.text"] [data-test="token-color.accent"]')
    .first()
    .click()
  await applyNow(page, recorded)
  expect((await hooks(page)).selection.ids).toEqual(['ctabutn0001'])
  await expect(link).toHaveAttribute('data-proof-swap', '1')
  await expect(link).toHaveAttribute('data-thallo-hover', '')
  expect((await hooks(page)).selection.ids).toEqual(['ctabutn0001'])
})

test('the force survives a full stage reload', async ({ page }) => {
  await openDesignPage(page)
  await select(page, 'hovlinks0001')
  await hoverSwitch(page, 'colors', 'link').click()
  const links = forcedIn(page, '[data-thallo-block="hovlinks0001"] .thallo-block-links__link')
  await expect(links).toHaveCount(3)
  await page
    .locator('[data-test="canvas-iframe"]')
    .evaluate((frame: HTMLIFrameElement) => frame.contentWindow!.location.reload())
  // The reloaded stage forgot it; the admin sends it again when the stage reports ready.
  await expect(links).toHaveCount(3)
})

test('clears when the panel holding the switch closes', async ({ page }) => {
  await openDesignPage(page)
  await select(page, 'ctabutn0001')
  await hoverSwitch(page, 'colors').click()
  await expect(anyForced(page)).toHaveCount(1)
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Layout', exact: true })
    .click()
  await expect(anyForced(page)).toHaveCount(0)
  // Back on Style (it opens in Normal): Hover again, then fold both hoverable sections.
  await page
    .locator('[data-test="block-inspector-tabs"]')
    .getByRole('tab', { name: 'Style', exact: true })
    .click()
  await hoverSwitch(page, 'colors').click()
  await expect(anyForced(page)).toHaveCount(1)
  await page.locator('[data-test="style-group-toggle-colors"]').first().click()
  await page.locator('[data-test="style-group-toggle-effects"]').first().click()
  await expect(anyForced(page)).toHaveCount(0)
})

test('clears on a new selection', async ({ page }) => {
  await openDesignPage(page)
  await select(page, 'ctabutn0001')
  await hoverSwitch(page, 'colors').click()
  await expect(anyForced(page)).toHaveCount(1)
  await selectViaOutline(page, 'head0000000a')
  await expect(anyForced(page)).toHaveCount(0)
})
