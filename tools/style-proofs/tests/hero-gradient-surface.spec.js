// The hero's theme band is a gradient background-image. The Background setting owns the whole
// background (the compiler emits the shorthand): transparent clears the gradient, a token paints
// over it, and a reset gives the theme's gradient back.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, token, at } = require('./helpers');

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
  await at(page, 'lg');
});

test('transparent clears the theme gradient', async ({ page }) => {
  const image = await computed(page, 'hero-surface-transparent', 'root', 'background-image');
  expect(image.theme).toMatch(/gradient/);
  expect(image.styled).toBe('none');
  const color = await computed(page, 'hero-surface-transparent', 'root', 'background-color');
  expect(color.styled).toMatch(/rgba\(0, 0, 0, 0\)|transparent/);
});

test('a surface token paints over the gradient', async ({ page }) => {
  const accent = await token(page, 'color.accent', 'background-color');
  const image = await computed(page, 'hero-surface-accent', 'root', 'background-image');
  expect(image.styled).toBe('none');
  const color = await computed(page, 'hero-surface-accent', 'root', 'background-color');
  expect(color.styled).toBe(accent);
});

test('a reset restores the theme gradient', async ({ page }) => {
  const image = await computed(page, 'hero-surface-reset', 'root', 'background-image');
  expect(image.styled).toBe(image.theme);
  expect(image.styled).toMatch(/gradient/);
});
