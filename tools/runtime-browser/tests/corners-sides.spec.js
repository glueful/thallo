// Which corners the Corners size rounds and which sides a border is drawn on, as a browser draws them
// (scripts/build-text-style-fixtures): untouched, every corner is rounded and the border goes all
// round; with the left corners and the top and bottom sides chosen, only those are.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/text-style/public.html';
const card = (page, i) => page.locator('main .thallo-block-card').nth(i);

const edges = (loc) =>
  loc.evaluate((el) => {
    const cs = getComputedStyle(el);
    return {
      radius: [cs.borderTopLeftRadius, cs.borderTopRightRadius, cs.borderBottomRightRadius, cs.borderBottomLeftRadius],
      width: [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth],
    };
  });

test('untouched, every corner is rounded and the border goes all round', async ({ page }) => {
  await page.goto(PAGE);
  const { radius, width } = await edges(card(page, 0));
  for (const r of radius) expect(parseFloat(r)).toBeGreaterThan(0);
  for (const w of width) expect(parseFloat(w)).toBeGreaterThan(0);
});

test('only the chosen corners are rounded, and only the chosen sides have a border', async ({ page }) => {
  await page.goto(PAGE);
  const all = await edges(card(page, 0));
  const { radius, width } = await edges(card(page, 1));
  // Top left and bottom left keep the Corners size; top right and bottom right are square.
  expect([radius[0], radius[3]]).toEqual([all.radius[0], all.radius[3]]);
  expect([radius[1], radius[2]]).toEqual(['0px', '0px']);
  // Top and bottom keep the width; right and left have none.
  expect([width[0], width[2]]).toEqual([all.width[0], all.width[2]]);
  expect([width[1], width[3]]).toEqual(['0px', '0px']);
});
