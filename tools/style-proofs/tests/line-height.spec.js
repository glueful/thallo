// Line height is unitless, so the computed value is the ratio times the heading's own font size —
// which is why it is proven as a ratio, at each breakpoint: tight at base, the theme's at md
// (a reset), loose from lg.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, at } = require('./helpers');

async function ratio(page) {
  const height = await computed(page, 'line-height', 'root', 'line-height');
  const size = await computed(page, 'line-height', 'root', 'font-size');
  const of = (h, s) => Math.round((parseFloat(h) / parseFloat(s)) * 100) / 100;
  return { styled: of(height.styled, size.styled), theme: of(height.theme, size.theme) };
}

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
});

test('tight at base, the theme’s at md, loose from lg', async ({ page }) => {
  await at(page, 'base');
  let r = await ratio(page);
  expect(r.theme).toBe(1.15); // the theme's headings
  expect(r.styled).toBe(1.1);

  await at(page, 'md');
  r = await ratio(page);
  expect(r.styled).toBe(r.theme);

  await at(page, 'lg');
  r = await ratio(page);
  expect(r.styled).toBe(1.9);
});
