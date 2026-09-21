import { test, expect } from '@playwright/test'
import { openDesignPage } from '../helpers'

// Documentation from the admin (Settings › Import / Export): a site with no docs section is
// offered "Set up documentation", and one click makes the type and chooses it; then a .zip of
// the docs folder is uploaded and imported with the choices made. The browser proves what a unit
// test cannot: the real select shows the new type, and the real file input takes the zip.
test('set up documentation, then import a .zip of Markdown', async ({ page }) => {
  await openDesignPage(page) // signs in; the routes below are registered after the world's
  const types: Record<string, unknown>[] = [
    { uuid: 't1', slug: 'page', name: 'Pages', schema: [{ name: 'title', type: 'string' }] },
  ]
  const calls: { setup: unknown[]; imports: Record<string, unknown>[]; uploads: number } = {
    setup: [],
    imports: [],
    uploads: 0,
  }
  const ok = (data: unknown) => ({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, message: 'ok', data }),
  })
  await page.route('**/v1/admin/content-types', (route) =>
    route.fulfill(ok({ content_types: types })),
  )
  await page.route('**/import-export/adapters', (route) =>
    route.fulfill(
      ok({
        importers: [{ key: 'markdown.folder', label: 'Markdown folder (.zip)' }],
        exporters: [],
      }),
    ),
  )
  await page.route('**/import-export/jobs', (route) => route.fulfill(ok({ jobs: [] })))
  await page.route('**/v1/admin/docs/setup', (route) => {
    calls.setup.push(route.request().postDataJSON())
    types.push({
      uuid: 't2',
      slug: 'docs',
      name: 'Docs',
      schema: [
        { name: 'title', type: 'string' },
        { name: 'body', type: 'text' },
      ],
    })
    return route.fulfill({
      status: 201,
      ...ok({ type: 'docs', created: true, missing: [], listed: true, url: '/docs' }),
    })
  })
  await page.route('**/v1/admin/import-export/upload', (route) => {
    calls.uploads++
    return route.fulfill({
      status: 201,
      ...ok({ disk: 'uploads', path: 'import-export/abc.zip', name: 'docs.zip' }),
    })
  })
  await page.route('**/import-export/imports', (route) => {
    calls.imports.push(route.request().postDataJSON() as Record<string, unknown>)
    return route.fulfill({ status: 201, ...ok({ job: null }) })
  })

  await page.goto('/admin/settings/import-export')
  const fields = page.locator('[data-test="markdown-folder-fields"]')
  await expect(fields).toBeVisible({ timeout: 20_000 })
  await expect(page.locator('[data-test="run-import"]')).toBeDisabled()

  await page.locator('[data-test="setup-docs"]').click()
  await expect.poll(() => calls.setup.length).toBe(1)
  // The offer goes away, and the new type is the one chosen: the select shows its name.
  await expect(page.locator('[data-test="setup-docs"]')).toHaveCount(0)
  await expect(page.locator('[data-test="folder-type"]')).toContainText('Docs')

  await page.locator('[data-test="folder-exclude"]').fill('internal')
  await page.locator('input[type="file"]').setInputFiles({
    name: 'docs.zip',
    mimeType: 'application/zip',
    buffer: Buffer.from('PK'),
  })
  await expect(page.getByText('docs.zip')).toBeVisible()
  await page.locator('[data-test="run-import"]').click()

  await expect.poll(() => calls.imports.length).toBe(1)
  expect(calls.uploads).toBe(1)
  expect(calls.imports[0]).toMatchObject({
    adapter: 'markdown.folder',
    path: 'import-export/abc.zip',
    mode: 'dry_run',
    options: { content_type: 'docs', publish: false, exclude: ['internal'] },
  })
})
