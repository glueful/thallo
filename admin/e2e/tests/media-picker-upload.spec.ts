import { test, expect } from '@playwright/test'
import { historyLength, openDesignPage, selectViaOutline, stage } from '../helpers'

// The asset picker's Upload button stays reachable once a file is chosen. The drop area grows with
// the previews it shows; a fixed-height one let a tall preview spill over the button, and since
// the area is positioned the spill painted above it and took its clicks. Proven on a Logos
// block's Images (a multiple asset field) in a real browser, which jsdom's no-layout DOM is not.

// A 1×1 PNG: the preview's size comes from the theme, not the file.
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
)

test("a chosen logo leaves the picker's Upload button clickable", async ({ page }) => {
  await openDesignPage(page)
  await selectViaOutline(page, 'sect00000002')
  await stage(page).locator('[data-thallo-block="sect00000002"] [data-action="add-after"]').click()
  const search = page.locator('[data-test="palette-search"]')
  await search.fill('logos')
  await search.press('Enter')
  const h = await historyLength(page, 1)
  expect(h.history[0]!.ops[0]).toMatchObject({ type: 'InsertBlock', block: { type: 'logos' } })

  const inspector = page.locator('[data-test="block-inspector"]')
  await inspector.locator('[data-test="asset-dropzone-open"]').first().click()
  const dialog = page.getByRole('dialog')
  await dialog
    .locator('input[type="file"]')
    .setInputFiles([{ name: 'logo-one.png', mimeType: 'image/png', buffer: PNG }])

  const upload = dialog.locator('[data-test="media-picker-upload"]')
  await expect(upload).toBeEnabled()
  // A trial click runs every actionability check — including that nothing covers the button —
  // without clicking.
  await upload.click({ trial: true, timeout: 3000 })
})
