import { test, expect } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { openLayoutsPage } from '../helpers'

// A layout kept while its pages are off the site (review of aa2801ec), in a real browser against the
// list and the session the real controllers answered: the post type taken off the listing types, its
// listing row is turned off, says why, offers Remove instead of Edit, and removing it asks first, then
// removes at the version the list showed and reads the list again.

interface Row {
  surface: string
  target: string
  lock_version: number
  reason: string
}

function keptRow(): Row {
  const list = JSON.parse(
    readFileSync(join(__dirname, '..', 'fixtures', 'layouts', 'layouts-kept.json'), 'utf8'),
  ) as { data: { layouts: Row[] } }
  return list.data.layouts.find((r) => r.surface === 'listing' && r.target === 'post')!
}

test('a kept listing layout is removed from Site › Layouts', async ({ page }) => {
  const recorded = await openLayoutsPage(page)
  const row = page.locator('[data-test="layouts-row-listing-post"]')
  await expect(row.locator('[data-test="layouts-reason"]')).toHaveText(keptRow().reason)
  await expect(row.locator('[data-test="layouts-link"]')).toHaveText('Turn on listing pages')
  await expect(page.locator('[data-test="layouts-edit-listing-post"]')).toHaveCount(0)

  await page.locator('[data-test="layouts-remove-listing-post"]').click()
  const dialog = page.locator('[data-test="layouts-remove-dialog"]')
  await expect(dialog).toContainText('Posts — listing pages')
  const lists = recorded.lists
  await page.locator('[data-test="layouts-remove-confirm"]').click()

  await expect.poll(() => recorded.removes.length).toBe(1)
  expect(recorded.sessions).toEqual([{ surface: 'listing', target: 'post' }])
  expect(recorded.removes[0]!.body.expected_lock_version).toBe(keptRow().lock_version)
  expect(recorded.removes[0]!.body.token).toBeTruthy()
  await expect(dialog).toHaveCount(0)
  await expect.poll(() => recorded.lists).toBeGreaterThan(lists)
})
