// Responsive padding: a base value, an md reset (revert-layer to the theme) and an lg override;
// visibility hidden at md only, revert-layer back to the theme's display from lg.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, token, at } = require('./helpers');

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
});

test('base sm, md reset, lg xl', async ({ page }) => {
  const sm = await token(page, 'spacing.sm', 'padding-top');
  const xl = await token(page, 'spacing.xl', 'padding-top');
  await at(page, 'base');
  let c = await computed(page, 'responsive-padding-reset', 'root', 'padding-top');
  expect(c.styled).toBe(sm);
  expect(c.styled).not.toBe(c.theme);
  await at(page, 'md');
  c = await computed(page, 'responsive-padding-reset', 'root', 'padding-top');
  expect(c.styled).toBe(c.theme);
  await at(page, 'lg');
  expect((await computed(page, 'responsive-padding-reset', 'root', 'padding-top')).styled).toBe(xl);
});

test('hidden at md only, the theme display again from lg', async ({ page }) => {
  await at(page, 'base');
  let c = await computed(page, 'visibility', 'root', 'display');
  expect(c.styled).toBe(c.theme);
  await at(page, 'md');
  expect((await computed(page, 'visibility', 'root', 'display')).styled).toBe('none');
  await at(page, 'lg');
  c = await computed(page, 'visibility', 'root', 'display');
  expect(c.styled).toBe(c.theme);
  expect(c.styled).not.toBe('none');
});
