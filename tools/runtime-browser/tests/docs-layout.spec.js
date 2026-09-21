// A documentation page, as the application serves it (scripts/build-docs-proof-fixtures): three
// columns on a wide screen, two on a medium one, one on a phone — where nothing, not a nine-column
// table and not a long command, may make the page scroll sideways. The side columns stick and
// scroll on their own, so a thirty-page sidebar never makes a page long.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/docs/page.html';
const box = (page, selector) => page.locator(selector).first().boundingBox();
const sidewaysScroll = (page) =>
  page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test('wide: the sidebar, the page and "On this page" stand side by side', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(PAGE);
  const [nav, article, toc] = await Promise.all([
    box(page, '.docs__sidebar'),
    box(page, '.docs__page'),
    box(page, '.docs__toc'),
  ]);
  expect(nav.x + nav.width).toBeLessThanOrEqual(article.x);
  expect(article.x + article.width).toBeLessThanOrEqual(toc.x);
  expect(Math.abs(nav.y - article.y)).toBeLessThan(4);
  // A readable measure, however wide the screen.
  expect(article.width).toBeLessThanOrEqual(46 * 16 + 1);
  await expect(page.locator('.docs__jump')).toBeHidden();
  expect(await sidewaysScroll(page)).toBe(0);
});

test('wide: the side columns stay in view as the page scrolls, and scroll on their own', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 800 });
  await page.goto(PAGE);
  const before = await box(page, '.docs__sidebar');
  await page.evaluate(() => window.scrollTo(0, 1500));
  const after = await box(page, '.docs__sidebar');
  expect(after.y).toBeGreaterThanOrEqual(0);
  expect(after.y).toBeLessThan(before.y + 1);
  expect(after.height).toBeLessThanOrEqual(800);
  // Thirty-two links do not fit: the sidebar scrolls inside itself.
  const overflow = await page
    .locator('.docs__sidebar')
    .evaluate((el) => el.scrollHeight > el.clientHeight && getComputedStyle(el).overflowY);
  expect(overflow).toBe('auto');
  const toc = await box(page, '.docs__toc');
  expect(toc.y).toBeGreaterThanOrEqual(0);
});

test('medium: two columns, and "On this page" steps aside', async ({ page }) => {
  await page.setViewportSize({ width: 1100, height: 900 });
  await page.goto(PAGE);
  const [nav, article] = await Promise.all([box(page, '.docs__sidebar'), box(page, '.docs__page')]);
  expect(nav.x + nav.width).toBeLessThanOrEqual(article.x);
  await expect(page.locator('.docs__toc')).toBeHidden();
  expect(await sidewaysScroll(page)).toBe(0);
});

test('phone: one column, the page first, and nothing scrolls the page sideways', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto(PAGE);
  const [article, nav] = await Promise.all([box(page, '.docs__page'), box(page, '.docs__sidebar')]);
  expect(nav.y).toBeGreaterThan(article.y + article.height - 1);
  expect(article.width).toBeLessThanOrEqual(390);
  expect(await sidewaysScroll(page)).toBe(0);
  // The nine-column table scrolls inside itself instead.
  const table = await page
    .locator('.prose table')
    .evaluate((el) => ({ scrolls: el.scrollWidth > el.clientWidth, width: el.getBoundingClientRect().width }));
  expect(table.scrolls).toBe(true);
  expect(table.width).toBeLessThanOrEqual(390);
  // The jump link leads to the sidebar.
  const jump = page.locator('.docs__jump');
  await expect(jump).toBeVisible();
  await jump.click();
  await expect.poll(async () => (await box(page, '.docs__sidebar')).y).toBeLessThan(200);
});

test('a code listing fills the prose column, and a heading’s link appears when it is wanted', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(PAGE);
  const [prose, code] = await Promise.all([box(page, '.docs__body'), box(page, '.prose .thallo-block-code')]);
  expect(Math.abs(code.x - prose.x)).toBeLessThan(1);
  expect(Math.abs(code.width - prose.width)).toBeLessThan(1);

  const heading = page.locator('.prose h2').first();
  const anchor = heading.locator('.heading-anchor');
  expect(await anchor.evaluate((el) => getComputedStyle(el).opacity)).toBe('0');
  await heading.hover();
  await expect.poll(() => anchor.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');
  // Under the sticky header a linked heading still shows: it stops short of the top.
  expect(parseFloat(await heading.evaluate((el) => getComputedStyle(el).scrollMarginTop))).toBeGreaterThan(40);
  // The current page is marked in the sidebar.
  await expect(page.locator('.docs__link[aria-current="page"]')).toHaveText('Installing');
});

test('the index lays the sections out as cards', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/tools/runtime-browser/fixtures/docs/index.html');
  const cards = page.locator('.docs-index__group').nth(1).locator('.docs-index__item');
  expect(await cards.count()).toBe(30);
  const [a, b] = await Promise.all([cards.nth(0).boundingBox(), cards.nth(1).boundingBox()]);
  expect(Math.abs(a.y - b.y)).toBeLessThan(1);
  expect(b.x).toBeGreaterThan(a.x);
  expect(await sidewaysScroll(page)).toBe(0);
});
