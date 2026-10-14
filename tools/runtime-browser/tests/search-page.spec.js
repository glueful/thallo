// @ts-check
const { test, expect } = require('@playwright/test');

// The /search page keeps a side gutter on a phone, as the shop's pages do: the layout's <main> has
// none of its own. Against fixtures/search-page.html and the real search.css.
const PAGE = '/tools/runtime-browser/fixtures/search-page.html';

test('on a phone the results page keeps a gutter on both sides', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto(PAGE);
  const box = await page.locator('.thallo-search-page__title').boundingBox();
  expect(box).not.toBeNull();
  expect(box.x).toBeGreaterThanOrEqual(16);
  expect(390 - (box.x + box.width)).toBeGreaterThanOrEqual(16);
});
