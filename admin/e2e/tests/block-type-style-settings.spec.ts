import { test, expect } from '@playwright/test'
import { openDesignPage } from '../helpers'

// Settings › Block types, in a real browser: a block type made in the admin is given the setting
// groups it supports, from the list the server offers; the card says what its template must emit;
// the create request carries the choice in the server's order. A code-declared type's card is
// read-only.
test('a new block type is created with the setting groups chosen for it', async ({ page }) => {
  await openDesignPage(page) // signs in
  const created: Record<string, unknown>[] = []
  await page.route('**/v1/admin/block-types', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    created.push(route.request().postDataJSON() as Record<string, unknown>)
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { block_type: {} } }),
    })
  })

  await page.goto('/admin/settings/block-types/new')
  const card = page.locator('[data-test="block-type-style-card"]')
  await expect(card).toBeVisible({ timeout: 20_000 })
  await expect(card.locator('[data-test="style-template-hint"]')).toHaveCount(0)

  await page.getByLabel('Label').fill('Promo banner')
  // Ticked out of order: the request carries the server's order.
  await card.locator('[data-test="style-group-option-radius"] input').check()
  await card.locator('[data-test="style-group-option-spacing"] input').check()
  const hint = card.locator('[data-test="style-template-hint"]')
  await expect(hint).toContainText('blocks/promo_banner.twig')
  await expect(hint).toContainText("{{ style_classes('root') }}")
  if (process.env.SHOT) await page.screenshot({ path: process.env.SHOT, fullPage: true })

  await page.getByRole('button', { name: 'Create', exact: false }).first().click()
  await expect.poll(() => created.length).toBe(1)
  expect(created[0]).toMatchObject({
    slug: 'promo_banner',
    label: 'Promo banner',
    style_capabilities: ['spacing', 'radius'],
  })
})

test('a code-declared block type shows its style settings read-only', async ({ page }) => {
  await openDesignPage(page)
  // The edit page's usage card reads a real body; the world answers unrouted requests empty.
  await page.route('**/v1/admin/block-types/button/usage', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { total: 0, per_type: [], allowlists: [] } }),
    }),
  )
  await page.goto('/admin/settings/block-types/button')
  const card = page.locator('[data-test="block-type-style-card"]')
  await expect(card.locator('[data-test="style-code-declared"]')).toBeVisible({ timeout: 20_000 })
  const boxes = card.locator('input[type="checkbox"]')
  expect(await boxes.count()).toBeGreaterThan(0)
  for (const box of await boxes.all()) await expect(box).toBeDisabled()
})
