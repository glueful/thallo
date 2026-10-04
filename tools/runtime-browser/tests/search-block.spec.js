// The Search block's suggestions and icon panel (search block spec §3.1): an accessible combobox
// whose list opens with nothing active (so Enter submits the typed query), arrow keys that move the
// active option while focus stays in the input, Escape that closes the list and then the panel,
// and suggestions that are race-safe — a stale response never shows, and nothing reopens a list the
// visitor dismissed.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/search-block.html';
const ITEMS = (q) => [
  { kind: 'entries', kind_label: 'Pages & posts', title: `Rose ${q} one`, href: '/rose-one', image: null, price: null, snippet: '<mark>Rose</mark> one' },
  { kind: 'products', kind_label: 'Products', title: `Rose ${q} two`, href: '/shop/products/rose-two', image: null, price: '$89.00', snippet: '<mark>Rose</mark> two' },
];

/**
 * The page, with a suggestions endpoint that answers per query. `answer(q)` returns
 * {state, items, delay?, fail?}.
 */
async function ready(page, answer = (q) => ({ state: 'results', items: ITEMS(q) })) {
  const asked = [];
  const pending = [];
  await page.route('**/_search/suggest*', async (route) => {
    const url = new URL(route.request().url());
    const q = url.searchParams.get('q') || '';
    asked.push(Object.fromEntries(url.searchParams));
    const a = answer(q);
    const respond = () => (a.fail
      ? route.fulfill({ status: 500, body: 'boom' })
      : route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { state: a.state, items: a.items || [], see_all: `/search?q=${encodeURIComponent(q)}&scope=&locale=en` } }),
      }));
    if (a.hold) {
      pending.push(respond);
      return;
    }
    if (a.delay) {
      await new Promise((r) => setTimeout(r, a.delay));
    }
    return respond();
  });
  await page.route('**/search?*', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>results page</p>' }));
  await page.goto(PAGE);
  return { asked, release: async () => { while (pending.length) { await pending.shift()(); } } };
}

const field = (page) => page.locator('#thallo-search-input-blk1');
const list = (page) => page.locator('#thallo-search-list-blk1');

test('the list opens with no active option, and Enter submits the typed query', async ({ page }) => {
  await ready(page);
  await field(page).fill('ros');
  await expect(list(page)).toBeVisible();
  await expect(field(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(field(page)).not.toHaveAttribute('aria-activedescendant', /.+/);
  const nav = page.waitForURL(/\/search\?q=ros&scope=&locale=en/);
  await field(page).press('Enter');
  await nav;
});

test('arrows move the active option while focus stays in the input, and Enter opens it', async ({ page }) => {
  await ready(page);
  await field(page).fill('ros');
  await expect(list(page).getByRole('option').first()).toBeVisible();
  await field(page).press('ArrowDown');
  await field(page).press('ArrowDown');
  await expect(field(page)).toBeFocused();
  const second = list(page).getByRole('option').nth(1);
  await expect(field(page)).toHaveAttribute('aria-activedescendant', await second.getAttribute('id'));
  await expect(second).toHaveAttribute('aria-selected', 'true');
  const nav = page.waitForURL(/\/shop\/products\/rose-two/);
  await field(page).press('Enter');
  await nav;
});

test('See all is the last selectable action; No results is status text, not an option', async ({ page }) => {
  await ready(page, () => ({ state: 'no_matches', items: [] }));
  await field(page).fill('zz');
  const options = list(page).getByRole('option');
  await expect(options).toHaveCount(1);
  await expect(options.first()).toHaveText('See all results for “zz”');
  await expect(page.locator('#thallo-search-list-blk1 [role="status"], #thallo-search-list-blk1 + p[role="status"]').first()).toHaveText('No results');
});

test('Escape closes the list, then the panel, and focus returns to the icon', async ({ page }) => {
  await ready(page);
  const trigger = page.locator('[data-search-trigger]');
  await trigger.click();
  const panel = page.locator('#thallo-search-panel-blk2');
  await expect(panel).toBeVisible();
  const input = page.locator('#thallo-search-input-blk2');
  await expect(input).toBeFocused();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await input.fill('ros');
  await expect(page.locator('#thallo-search-list-blk2')).toBeVisible();
  await input.press('Escape');
  await expect(page.locator('#thallo-search-list-blk2')).toBeHidden();
  await expect(panel).toBeVisible();
  await input.press('Escape');
  await expect(panel).toBeHidden();
  await expect(trigger).toBeFocused();
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
});

test('a late response never replaces a newer query’s results', async ({ page }) => {
  await ready(page, (q) => (q === 'r' ? { state: 'results', items: ITEMS('first'), delay: 600 } : { state: 'results', items: ITEMS('second') }));
  await field(page).fill('r');
  await page.waitForTimeout(250);
  await field(page).fill('ro');
  await page.waitForTimeout(1000);
  await expect(list(page).getByRole('option').first()).toHaveText(/second/);
});

test('an old response arriving inside the debounce window is not shown', async ({ page }) => {
  const { release } = await ready(page, (q) => (q === 'r' ? { state: 'results', items: ITEMS('stale'), hold: true } : { state: 'results', items: ITEMS('fresh'), delay: 400 }));
  await field(page).fill('r');
  await page.waitForTimeout(250); // "r" has been asked
  await field(page).press('ArrowDown');
  await field(page).fill('ro'); // invalidates at once
  await release(); // "r" answers within the debounce of "ro"
  await page.waitForTimeout(80);
  await expect(list(page).getByText(/stale/)).toHaveCount(0);
  await expect(field(page)).not.toHaveAttribute('aria-activedescendant', /.+/);
});

test('Escape before dispatch cancels the pending request', async ({ page }) => {
  const { asked } = await ready(page);
  await field(page).fill('ros');
  await field(page).press('Escape');
  await page.waitForTimeout(400);
  expect(asked).toEqual([]);
  await expect(list(page)).toBeHidden();
});

test('a response arriving after Escape does not reopen the list', async ({ page }) => {
  const { release } = await ready(page, () => ({ state: 'results', items: ITEMS('late'), hold: true }));
  await field(page).fill('ros');
  await page.waitForTimeout(250);
  await field(page).press('Escape');
  await release();
  await page.waitForTimeout(100);
  await expect(list(page)).toBeHidden();
});

test('live results off sends no request', async ({ page }) => {
  const { asked } = await ready(page);
  await page.locator('#thallo-search-input-blk3').fill('ros');
  await page.waitForTimeout(400);
  expect(asked).toEqual([]);
});

test('a failed request says suggestions are unavailable, and Enter still submits', async ({ page }) => {
  await ready(page, () => ({ fail: true }));
  await field(page).fill('ros');
  await expect(page.locator('#thallo-search-input-blk1 ~ [data-search-status]')).toHaveText('Suggestions are unavailable');
  const nav = page.waitForURL(/\/search\?q=ros/);
  await field(page).press('Enter');
  await nav;
});

test('rebuilding shows its message', async ({ page }) => {
  await ready(page, () => ({ state: 'rebuilding', items: [] }));
  await field(page).fill('ros');
  await expect(page.locator('#thallo-search-input-blk1 ~ [data-search-status]')).toHaveText('Search is being rebuilt. Please try again later.');
});

test('Enter during an input-method composition does nothing', async ({ page }) => {
  await ready(page);
  await field(page).fill('ros');
  await expect(list(page).getByRole('option').first()).toBeVisible();
  const before = page.url();
  await field(page).evaluate((el) => {
    el.dispatchEvent(new CompositionEvent('compositionstart'));
    el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true, isComposing: true }));
  });
  await page.waitForTimeout(200);
  expect(page.url()).toBe(before);
});

test('Tab moves on and closes the list', async ({ page }) => {
  await ready(page);
  await field(page).fill('ros');
  await expect(list(page)).toBeVisible();
  await field(page).press('Tab');
  await expect(list(page)).toBeHidden();
  await expect(page.locator('.thallo-search-form__submit').first()).toBeFocused();
});

test('the active option clears when the query changes', async ({ page }) => {
  await ready(page);
  await field(page).fill('ros');
  await expect(list(page).getByRole('option').first()).toBeVisible();
  await field(page).press('ArrowDown');
  await expect(field(page)).toHaveAttribute('aria-activedescendant', /.+/);
  await field(page).press('e');
  await expect(field(page)).not.toHaveAttribute('aria-activedescendant', /.+/);
});

test('snippets render as given and titles cannot inject markup', async ({ page }) => {
  await ready(page, () => ({ state: 'results', items: [{ kind: 'entries', kind_label: 'Pages & posts', title: '<img src=x onerror="window.__pwned=1">', href: '/x', image: null, price: null, snippet: '&lt;img src=x&gt; <mark>ok</mark>' }] }));
  await field(page).fill('ok');
  await expect(list(page).getByRole('option').first()).toBeVisible();
  expect(await list(page).locator('img').count()).toBe(0);
  expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
  await expect(list(page).locator('mark')).toHaveText('ok');
});
