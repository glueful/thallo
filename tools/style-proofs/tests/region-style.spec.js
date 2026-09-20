// The chrome regions — the header and the footer — have a Style tab of their own. Untouched, a
// bar is exactly what the theme draws; every setting moves one thing and can be given back.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, token, at } = require('./helpers');

// The theme's header: 82% of the page background, over a 12px blur.
const TRANSLUCENT = /^color\(srgb 1 1 1 \/ 0\.82\)$/;

test.beforeEach(async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
  await at(page, 'lg');
});

test('an untouched sticky header is still the translucent, blurred bar', async ({ page }) => {
  // Sticky used to repaint the bar solid: its rule set a background after the translucent one.
  const colour = await computed(page, 'header-sticky-untouched', 'root', 'background-color');
  expect(colour.theme).toMatch(TRANSLUCENT);
  expect(colour.styled).toBe(colour.theme);
  const blur = await computed(page, 'header-sticky-untouched', 'root', 'backdrop-filter');
  expect(blur.styled).toBe('blur(12px)');
  const position = await computed(page, 'header-sticky-untouched', 'root', 'position');
  expect(position.styled).toBe('sticky');
});

test('an opacity alone mixes the colour the theme paints the bar with', async ({ page }) => {
  const header = await computed(page, 'header-opacity-only', 'root', 'background-color');
  expect(header.styled).toMatch(/^color\(srgb 1 1 1 \/ 0\.6\)$/);
  // The blur is untouched: still the theme's.
  const blur = await computed(page, 'header-opacity-only', 'root', 'backdrop-filter');
  expect(blur.styled).toBe(blur.theme);

  // The footer is painted with the surface colour, not the page background.
  const footer = await computed(page, 'footer-opacity-only', 'root', 'background-color');
  expect(footer.theme).toMatch(/^rgb\(/);
  expect(footer.styled).toMatch(/^color\(srgb [\d.]+ [\d.]+ [\d.]+ \/ 0\.5\)$/);
  expect(footer.styled).not.toMatch(/^color\(srgb 1 1 1 /);
});

test('a floating bar: its own colour, no blur, round, lifted, off the edge, bordered all round', async ({
  page,
}) => {
  const name = 'header-floating';
  const colour = await computed(page, name, 'root', 'background-color');
  expect(colour.styled).toMatch(/^color\(srgb 0\.14\d+ 0\.38\d+ 0\.92\d+ \/ 0\.8\)$/);
  expect((await computed(page, name, 'root', 'backdrop-filter')).styled).toBe('none');
  expect((await computed(page, name, 'root', 'border-top-left-radius')).styled).toBe(
    await token(page, 'radius.full', 'border-top-left-radius'),
  );
  const shadow = await computed(page, name, 'root', 'box-shadow');
  expect(shadow.theme).toBe('none');
  expect(shadow.styled).not.toBe('none');
  expect((await computed(page, name, 'root', 'margin-top')).styled).toBe(
    await token(page, 'spacing.lg', 'padding-top'),
  );
  // The theme draws the bottom edge alone; all four are drawn now.
  const top = await computed(page, name, 'root', 'border-top-width');
  expect(top.theme).toBe('0px');
  expect(top.styled).toBe('1px');
});

test('padding lands inside the bar, and a border moves to one side', async ({ page }) => {
  const inner = await computed(page, 'header-padding', 'inner', 'padding-top');
  expect(inner.styled).toBe(await token(page, 'spacing.xl', 'padding-top'));
  expect(inner.styled).not.toBe(inner.theme);
  const root = await computed(page, 'header-padding', 'root', 'padding-top');
  expect(root.styled).toBe(root.theme);

  const left = await computed(page, 'footer-border-left', 'root', 'border-left-width');
  expect(left.styled).toBe('2px');
  // The footer's own top edge is one of the three sides taken away.
  const topEdge = await computed(page, 'footer-border-left', 'root', 'border-top-width');
  expect(topEdge.theme).toBe('1px');
  expect(topEdge.styled).toBe('0px');
});
