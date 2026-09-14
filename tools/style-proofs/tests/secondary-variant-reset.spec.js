// The secondary (outline) button variant: a surface token paints the control's background
// over the theme; a reset returns the variant's own transparent background, not the primary
// fill. Radius: a token overrides the theme's pill and a reset restores it.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, token, at } = require('./helpers');

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
  await at(page, 'lg');
});

test('a surface token paints the outline control; a reset gives the theme transparent back', async ({ page }) => {
  const accent = await token(page, 'color.accent', 'background-color');
  const painted = await computed(page, 'secondary-variant-reset', 'control', 'background-color');
  expect(painted.styled).toBe(accent);
  expect(painted.theme).not.toBe(accent);
  const reset = await computed(page, 'secondary-variant-reset-md', 'control', 'background-color');
  expect(reset.styled).toBe(reset.theme);
  expect(reset.styled).toMatch(/rgba\(0, 0, 0, 0\)|transparent/);
});

test('radius none squares the control; a reset restores the theme pill', async ({ page }) => {
  const none = await computed(page, 'radius-none', 'control', 'border-radius');
  expect(none.styled).toBe('0px');
  expect(none.theme).not.toBe('0px');
  const reset = await computed(page, 'radius-reset', 'control', 'border-radius');
  expect(reset.styled).toBe(reset.theme);
});

test('the root and the control are styled independently', async ({ page }) => {
  const root = await computed(page, 'radius-none', 'root', 'border-radius');
  expect(root.styled).toBe(root.theme);
});
