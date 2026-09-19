// The hero gradient has a colour and a strength of its own. Emerald at "strong" is 32% of
// emerald-700 (#047857) over the white page: 0.32 × (4, 120, 87)/255 + 0.68 — and in dark mode
// it is the dark palette's emerald over the dark page, so the same hero is a different gradient.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, at } = require('./helpers');

const NAME = 'hero-gradient-emerald-strong';

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
  await at(page, 'lg');
});

test('emerald, strong: the mixed colour is the palette’s emerald at 32%', async ({ page }) => {
  const image = await computed(page, NAME, 'root', 'background-image');
  expect(image.styled).toMatch(/^linear-gradient\(.*color\(srgb 0\.68\d* 0\.83\d* 0\.78\d*\)/);
  // The twin is the site accent, faint — what every hero was.
  expect(image.theme).toMatch(/^linear-gradient\(/);
  expect(image.theme).not.toBe(image.styled);
});

test('dark mode takes the dark palette’s emerald over the dark page', async ({ page }) => {
  const light = await computed(page, NAME, 'root', 'background-image');
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  const dark = await computed(page, NAME, 'root', 'background-image');
  expect(dark.styled).not.toBe(light.styled);
  expect(dark.styled).not.toBe(dark.theme);
  // 0.32 × (16, 185, 129)/255 + 0.68 × (11, 17, 32)/255
  expect(dark.styled).toMatch(/color\(srgb 0\.04\d* 0\.27\d* 0\.24\d*\)/);
});
