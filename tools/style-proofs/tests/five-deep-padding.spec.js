// Depth five (visual builder spec §5.2): a padding token on a heading five levels down —
// section > columns > card > container > heading — lands on it in every engine, and the
// nested theme rules around it leave the control's own padding to the theme.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, token, at } = require('./helpers');

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
});

test('md padding lands five levels down; base stays the theme', async ({ page }) => {
  const lg = await token(page, 'spacing.lg', 'padding-top');
  await at(page, 'base');
  let c = await computed(page, 'five-deep-padding', 'root', 'padding-top');
  expect(c.styled).toBe(c.theme);
  await at(page, 'md');
  c = await computed(page, 'five-deep-padding', 'root', 'padding-top');
  expect(c.styled).toBe(lg);
  expect(c.styled).not.toBe(c.theme);
});
