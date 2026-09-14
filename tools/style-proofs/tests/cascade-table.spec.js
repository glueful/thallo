// The §1.6 cascade table and the reset rule, proven on real heading theme CSS: the classes
// the emitter wrote for each row compute to the expected token at each breakpoint, and a
// reset hands the breakpoint back to the theme (the control twin's own padding).
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, token, at } = require('./helpers');

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
});

test('class base lg, md xl under instance base sm gives sm, then xl from md up', async ({ page }) => {
  const sm = await token(page, 'spacing.sm', 'padding-top');
  const xl = await token(page, 'spacing.xl', 'padding-top');
  expect(sm).not.toBe(xl);
  await at(page, 'base');
  expect((await computed(page, 'table-row-1', 'root', 'padding-top')).styled).toBe(sm);
  await at(page, 'md');
  expect((await computed(page, 'table-row-1', 'root', 'padding-top')).styled).toBe(xl);
  await at(page, 'lg');
  expect((await computed(page, 'table-row-1', 'root', 'padding-top')).styled).toBe(xl);
});

test('class base lg, md xl under instance md sm gives lg, then sm from md up', async ({ page }) => {
  const lg = await token(page, 'spacing.lg', 'padding-top');
  const sm = await token(page, 'spacing.sm', 'padding-top');
  await at(page, 'base');
  expect((await computed(page, 'table-row-2', 'root', 'padding-top')).styled).toBe(lg);
  await at(page, 'md');
  expect((await computed(page, 'table-row-2', 'root', 'padding-top')).styled).toBe(sm);
  await at(page, 'lg');
  expect((await computed(page, 'table-row-2', 'root', 'padding-top')).styled).toBe(sm);
});

test('an instance reset at md terminates resolution there; a class lg declaration still applies', async ({ page }) => {
  const sm = await token(page, 'spacing.sm', 'padding-top');
  await at(page, 'base');
  let c = await computed(page, 'reset-terminates', 'root', 'padding-top');
  expect(c.styled).toBe(c.theme);
  await at(page, 'md');
  c = await computed(page, 'reset-terminates', 'root', 'padding-top');
  expect(c.styled).toBe(c.theme);
  await at(page, 'lg');
  expect((await computed(page, 'reset-terminates', 'root', 'padding-top')).styled).toBe(sm);
});
