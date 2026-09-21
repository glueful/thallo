// The docs search box, on a page the application served with search on. The form ships hidden and
// the script shows it; typing asks the public search API for this docs type; the hits are a
// combobox a keyboard can walk; and a snippet can never carry markup of its own, whatever the
// API says.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const RUNTIME = path.resolve(__dirname, '..', '..', '..', 'packages/thallo-render/runtime');
const PAGE = '/tools/runtime-browser/fixtures/docs/page.html';

const HITS = [
  { uuid: 'a', type: 'docs', locale: 'en', href: '/docs/install', title: 'Installing', snippet: 'Run <mark>composer</mark> to create a project', score: 0.9 },
  { uuid: 'b', type: 'docs', locale: 'en', href: '/docs/upgrading', title: 'Upgrading', snippet: '<img src=x onerror="window.__pwned=1"> &lt;b&gt;bold&lt;/b&gt; <mark>composer</mark> install', score: 0.5 },
];

/** The page's scripts, and a search API that records what it was asked. */
async function ready(page, answer = () => ({ status: 200, hits: HITS })) {
  const asked = [];
  await page.addInitScript({ path: path.join(RUNTIME, 'runtime.js') });
  await page.route('**/_thallo/runtime/block-*.js', (route) => {
    const file = path.join(RUNTIME, path.basename(new URL(route.request().url()).pathname));
    return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(file) });
  });
  await page.route('**/v1/search*', (route) => {
    const url = new URL(route.request().url());
    asked.push(Object.fromEntries(url.searchParams));
    const { status, hits } = answer(url.searchParams.get('q'));
    return route.fulfill({
      status,
      contentType: 'application/json',
      body: JSON.stringify({ success: status === 200, data: { hits, total: hits.length, limit: 8, offset: 0 } }),
    });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(PAGE);
  return asked;
}

test('without the script there is no search box; with it, the box appears', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(PAGE); // no runtime, no block script
  await expect(page.locator('[data-docs-search]')).toBeHidden();

  await ready(page);
  await expect(page.locator('[data-docs-search]')).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Search the documentation' })).toBeVisible();
});

test('typing asks the search API for this docs type and lists the hits', async ({ page }) => {
  const asked = await ready(page);
  const input = page.getByRole('combobox');
  await input.fill('c'); // one letter asks nothing
  await page.waitForTimeout(300);
  expect(asked).toEqual([]);

  await input.pressSequentially('omposer', { delay: 20 });
  const options = page.getByRole('option');
  await expect(options).toHaveCount(2);
  // Debounced: a word typed quickly is one question, not seven.
  expect(asked.length).toBeLessThanOrEqual(2);
  expect(asked.at(-1)).toMatchObject({ q: 'composer', type: 'docs', locale: 'en', limit: '8' });
  await expect(input).toHaveAttribute('aria-expanded', 'true');
  await expect(options.first()).toContainText('Installing');
  await expect(options.first().locator('mark')).toHaveText('composer');
});

test('a snippet is text and highlights, never markup', async ({ page }) => {
  await ready(page);
  await page.getByRole('combobox').fill('composer');
  const second = page.getByRole('option').nth(1);
  await expect(second).toContainText('<b>bold</b>'); // shown as the characters it is
  expect(await second.locator('img, b').count()).toBe(0);
  expect(await second.locator('mark').count()).toBe(1);
  expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
});

test('the keyboard walks the hits, Enter opens one, Escape closes, "/" focuses', async ({ page }) => {
  await ready(page);
  const input = page.getByRole('combobox');
  await page.locator('h1').click();
  await page.keyboard.press('/');
  await expect(input).toBeFocused();
  await input.fill('composer');
  await expect(page.getByRole('option')).toHaveCount(2);

  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await expect(page.getByRole('option').nth(1)).toHaveAttribute('aria-selected', 'true');
  await expect(input).toHaveAttribute('aria-activedescendant', await page.getByRole('option').nth(1).getAttribute('id'));
  await page.keyboard.press('ArrowDown'); // wraps
  await expect(page.getByRole('option').first()).toHaveAttribute('aria-selected', 'true');

  await page.keyboard.press('Escape');
  await expect(page.getByRole('listbox')).toBeHidden();
  await expect(input).toHaveAttribute('aria-expanded', 'false');

  await input.fill('composer ');
  await expect(page.getByRole('option')).toHaveCount(2);
  await page.route('**/docs/install', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>arrived</h1>' }));
  await page.keyboard.press('Enter'); // nothing chosen: the first hit
  await expect(page).toHaveURL(/\/docs\/install$/);
});

test('no hits and a failing search both say so', async ({ page }) => {
  await ready(page, (q) => (q === 'broken' ? { status: 503, hits: [] } : { status: 200, hits: [] }));
  const input = page.getByRole('combobox');
  await input.fill('nothing');
  await expect(page.getByRole('status')).toHaveText('No results for “nothing”.');
  await input.fill('broken');
  await expect(page.getByRole('status')).toHaveText('Search is unavailable right now.');
  await expect(page.getByRole('listbox')).toBeHidden();
});

test('the results float over the page and do not push it', async ({ page }) => {
  await ready(page);
  const nav = page.locator('.docs__nav');
  const before = await nav.boundingBox();
  await page.getByRole('combobox').fill('composer');
  await expect(page.getByRole('option')).toHaveCount(2);
  const after = await nav.boundingBox();
  expect(after.y).toBe(before.y);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);
});
