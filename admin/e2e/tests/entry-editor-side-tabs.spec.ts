import { test, expect } from '@playwright/test'
import { entry, openDesignPage } from '../helpers'

// The entry editor's sidebar tabs, in a real browser: in the sidebar's width every label reads
// whole — Publishing, SEO, Versions and Commerce side by side, none clipped to an ellipsis.
test('the editor sidebar tabs show their whole labels', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await openDesignPage(page) // signs in
  // The caller can see commerce, so its panel joins the built-ins.
  await page.route('**/v1/admin/commerce/meta', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { can_view: true } }),
    }),
  )
  await page.goto(`/admin/content/${entry.type}/${entry.uuid}`)
  const tabs = page.locator('[data-test="editor-side-tabs"]').getByRole('tab')
  await expect(tabs.first()).toBeVisible({ timeout: 20_000 })
  await expect(tabs).toHaveText(['Publishing', 'SEO', 'Versions', 'Commerce'])
  const clipped = await tabs.evaluateAll((els) =>
    els
      .map((el) => el.querySelector('[data-slot="label"]') as HTMLElement | null)
      .filter((l) => l && l.scrollWidth > l.clientWidth)
      .map((l) => l!.textContent),
  )
  expect(clipped).toEqual([])
})
