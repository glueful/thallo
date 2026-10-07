// The Search block's look and conveniences (search redesign): the icon centred beside the header's
// other icons and sized by its Size setting; suggestions grouped by kind with a picture and price;
// a message in the list when nothing matches; Clear; the `/` shortcut; and the panel as a sheet with
// Cancel on a phone. Against the block's markup (fixtures/search-block.html) and the real script and
// stylesheet.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/search-block.html';
const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
const ITEMS = [
  { kind: 'products', kind_label: 'Products', title: 'Oud Mood', href: '/shop/products/oud-mood', image: PIXEL, price: '$42.00', snippet: 'A smoky <mark>oud</mark>' },
  { kind: 'entries', kind_label: 'Pages & posts', title: 'Making oud last', href: '/oud-last', image: null, price: null, snippet: 'Layer an <mark>oud</mark> oil' },
  { kind: 'products', kind_label: 'Products', title: 'Oud Glory', href: '/shop/products/oud-glory', image: null, price: '$29.00', snippet: 'Dry <mark>oud</mark>' },
];

async function ready(page, items = ITEMS, state = 'results') {
  await page.route('**/_search/suggest*', (route) => {
    const q = new URL(route.request().url()).searchParams.get('q') || '';
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { state, items, see_all: `/search?q=${encodeURIComponent(q)}` } }),
    });
  });
  await page.goto(PAGE);
}

const field = (page) => page.locator('#thallo-search-input-blk1');
const list = (page) => page.locator('#thallo-search-list-blk1');
const centreY = (locator) => locator.evaluate((el) => { const r = el.getBoundingClientRect(); return r.top + r.height / 2; });

test('the icon is a round button centred beside a cart-sized neighbour', async ({ page }) => {
  await ready(page);
  const trigger = page.locator('[data-search-trigger]');
  const box = await trigger.boundingBox();
  expect(Math.round(box.width)).toBe(44);
  expect(Math.round(box.height)).toBe(44);
  const glyph = trigger.locator('svg');
  expect(Math.round((await glyph.boundingBox()).width)).toBe(20);
  // The glyph sits at the bar's middle, level with the cart beside it — no stray space below it.
  const [g, cart] = [await centreY(glyph), await centreY(page.locator('[data-cart]'))];
  expect(Math.abs(g - cart)).toBeLessThan(1);
});

test("the icon's Size scales the magnifier and its button", async ({ page }) => {
  await ready(page);
  // What the Icon part's Size utility sets: the trigger's font-size.
  await page.locator('[data-search-trigger]').evaluate((el) => { el.style.fontSize = '1.5rem'; });
  expect(Math.round((await page.locator('[data-search-trigger] svg').boundingBox()).width)).toBe(30);
  expect(Math.round((await page.locator('[data-search-trigger]').boundingBox()).width)).toBe(66);
});

test('suggestions are grouped by kind, in the order kinds first appear, with pictures and prices', async ({ page }) => {
  await ready(page);
  await field(page).fill('oud');
  await expect(list(page)).toBeVisible();
  await expect(list(page).locator('.thallo-search-form__group')).toHaveText(['Products', 'Pages & posts']);
  const options = list(page).getByRole('option');
  // Products first (the first kind seen), then the page, then See all.
  await expect(options).toHaveText([/Oud Mood/, /Oud Glory/, /Making oud last/, 'See all results for “oud”']);
  await expect(options.nth(0).locator('.thallo-search-form__thumb img')).toHaveAttribute('src', PIXEL);
  await expect(options.nth(1).locator('.thallo-search-form__thumb svg')).toHaveCount(1);
  await expect(options.nth(0).locator('.thallo-search-form__price')).toHaveText('$42.00');
  // The group heading is decoration; each option still names its kind for a screen reader.
  await expect(list(page).locator('.thallo-search-form__group').first()).toHaveAttribute('aria-hidden', 'true');
  await expect(options.nth(0).locator('.thallo-search-form__kind')).toHaveText('Products');
});

test('nothing matching says so inside the list, above See all', async ({ page }) => {
  await ready(page, [], 'no_matches');
  await field(page).fill('zz');
  await expect(list(page).locator('.thallo-search-form__empty')).toHaveText('No matches for “zz”.');
  await expect(list(page).getByRole('option')).toHaveText(['See all results for “zz”']);
});

test('Clear appears once there is text, and empties the field', async ({ page }) => {
  await ready(page);
  const clear = page.locator('[data-key="blk1"] [data-search-clear]');
  await expect(clear).toBeHidden();
  await field(page).fill('oud');
  await expect(clear).toBeVisible();
  await clear.click();
  await expect(field(page)).toHaveValue('');
  await expect(field(page)).toBeFocused();
  await expect(clear).toBeHidden();
  await expect(list(page)).toBeHidden();
});

test('/ focuses the field from anywhere on the page, but not while typing elsewhere', async ({ page }) => {
  await ready(page);
  await expect(page.locator('[data-key="blk1"] [data-search-kbd]')).toBeVisible();
  await page.locator('body').click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('/');
  await expect(field(page)).toBeFocused();
  await expect(field(page)).toHaveValue('');
  // Typing "/" into a field is just typing.
  await field(page).fill('a/b');
  await expect(field(page)).toHaveValue('a/b');
});

test('on a phone the panel is a sheet across the top, and Cancel closes it', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await ready(page);
  await page.locator('[data-search-trigger]').click();
  const panel = page.locator('#thallo-search-panel-blk2');
  await expect(panel).toBeVisible();
  const box = await panel.boundingBox();
  expect(Math.round(box.y)).toBe(0);
  expect(Math.round(box.width)).toBe(390);
  await expect(page.locator('#thallo-search-input-blk2')).toBeFocused();
  await page.locator('[data-search-cancel]').click();
  await expect(panel).toBeHidden();
  await expect(page.locator('[data-search-trigger]')).toBeFocused();
});

test('on a wide screen Cancel is not shown; the panel is a card under the icon', async ({ page }) => {
  await ready(page);
  await page.locator('[data-search-trigger]').click();
  await expect(page.locator('[data-search-cancel]')).toBeHidden();
  const [trigger, panel] = [await page.locator('[data-search-trigger]').boundingBox(), await page.locator('#thallo-search-panel-blk2').boundingBox()];
  expect(panel.y).toBeGreaterThan(trigger.y + trigger.height);
});

test("in the icon's panel, suggestions open under the field, across the panel, not inside its row", async ({ page }) => {
  await ready(page);
  await page.locator('[data-search-trigger]').click();
  await page.locator('#thallo-search-input-blk2').fill('oud');
  const panelList = page.locator('#thallo-search-list-blk2');
  await expect(panelList).toBeVisible();
  const form = await page.locator('[data-key="blk2"] .thallo-search-form').boundingBox();
  const box = await panelList.boundingBox();
  expect(box.y).toBeGreaterThanOrEqual(form.y + form.height);
  expect(box.width).toBeGreaterThanOrEqual(form.width - 1);
});

test("in the icon's panel, a message such as rebuilding shows under the field, not beside it", async ({ page }) => {
  await ready(page, [], 'rebuilding');
  await page.locator('[data-search-trigger]').click();
  await page.locator('#thallo-search-input-blk2').fill('gt');
  const status = page.locator('[data-key="blk2"] [data-search-status]');
  await expect(status).toHaveText('Search is being rebuilt. Please try again later.');
  const form = await page.locator('[data-key="blk2"] .thallo-search-form').boundingBox();
  const box = await status.boundingBox();
  expect(box.y).toBeGreaterThanOrEqual(form.y + form.height);
  // The field keeps its full width: the input is not squeezed by the message.
  expect((await page.locator('#thallo-search-input-blk2').boundingBox()).width).toBeGreaterThan(200);
});

test("the panel's arrow keeps its size when the Button section adds padding", async ({ page }) => {
  await ready(page);
  await page.locator('[data-search-trigger]').click();
  // What the Button part's Padding utilities set (lg:t-pl-md lg:t-pr-md on a live site).
  const button = page.locator('[data-key="blk2"] .thallo-search-form__submit');
  await button.evaluate((el) => { el.style.paddingLeft = '1rem'; el.style.paddingRight = '1rem'; });
  const arrow = await button.locator('svg').boundingBox();
  expect(Math.round(arrow.width)).toBeGreaterThanOrEqual(16);
  const b = await button.boundingBox();
  expect(b.width).toBeGreaterThanOrEqual(arrow.width + 32 - 1);
});
