// Proves the CSS-only no-JS floor computes the right `display` for all four
// elements with NO runtime.js involved at all (the fixture omits the script
// entirely — this doubles as "before the runtime loads" and "with it
// absent"). A Node hand-stubbed DOM never runs real CSS computation, so this
// is the one thing only a real browser can check.
'use strict';

const { test, expect } = require('@playwright/test');

test('carousel, tabs and navigation compute display: block with no runtime present', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/no-runtime.html');

  // Confirm the premise: nothing enhanced these (no script tag at all).
  const enhancedAny = await page.evaluate(() =>
    !!document.querySelector('[data-thallo-enhanced]')
  );
  expect(enhancedAny).toBe(false);

  const displays = await page.evaluate(() => ({
    carousel: getComputedStyle(document.querySelector('thallo-carousel')).display,
    tabs: getComputedStyle(document.querySelector('thallo-tabs')).display,
    navigation: getComputedStyle(document.querySelector('thallo-navigation')).display
  }));
  expect(displays).toEqual({ carousel: 'block', tabs: 'block', navigation: 'block' });
});

test('color-mode toggle computes display: inline-flex via the alias when the feature is on', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/no-runtime.html');

  // Fixture ships html[data-color-mode-enabled="true"] — the same stamp
  // layout.twig emits when color_mode_enabled() is true.
  const enabled = await page.evaluate(() => document.documentElement.getAttribute('data-color-mode-enabled'));
  expect(enabled).toBe('true');

  const display = await page.evaluate(() =>
    getComputedStyle(document.querySelector('thallo-color-mode-toggle')).display
  );
  expect(display).toBe('inline-flex');
});

test('color-mode toggle computes display: none when html[data-color-mode-enabled] is unset', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/no-runtime.html');

  // Simulate the feature being off the way the server would express it —
  // by not stamping the attribute — via direct DOM manipulation. This is
  // fixture setup, not the runtime: no script from runtime.js ever runs on
  // this page.
  await page.evaluate(() => document.documentElement.removeAttribute('data-color-mode-enabled'));

  const display = await page.evaluate(() =>
    getComputedStyle(document.querySelector('thallo-color-mode-toggle')).display
  );
  expect(display).toBe('none');
});
