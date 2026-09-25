// A logo block is exactly as tall as its image. Its link used to be an inline box on a line of
// text, so the block kept the room a line reserves below the letters — a gap under the logo that
// no setting could reach. The logo is its own box: its padding and margin are the only space.
'use strict';

const { test, expect } = require('@playwright/test');

test('a logo block is as tall as its image, with no line space below it', async ({ page }) => {
  await page.goto('/tools/style-proofs/pages/logo-box.html');
  const m = await page.evaluate(() => {
    const box = (id) => document.getElementById(id).getBoundingClientRect();
    return { logo: box('logo'), link: box('link'), image: box('image') };
  });
  expect(m.image.height).toBeGreaterThan(0);
  expect(Math.round(m.logo.height)).toBe(Math.round(m.image.height));
  expect(Math.round(m.logo.top)).toBe(Math.round(m.image.top));
  // The link is the logo's own size: it is not stretched across the bar.
  expect(Math.round(m.link.width)).toBe(Math.round(m.image.width));
});
