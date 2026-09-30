// The thumbnail capture's crop (sections and templates design §6): a picture of one element —
// a layout template's frame, or a section on the layout stage, whose annotation wrapper is
// `display: contents` and so has no box of its own: the crop is its rendered descendants' area.
'use strict';

const { test, expect } = require('@playwright/test');
const { renderedBox } = require('../crop-box.js');

test.beforeEach(({ browserName }) => {
  test.skip(browserName !== 'chromium', 'the thumbnail capture runs in Chromium');
});

test('a boxed element is cropped to its own box', async ({ page }) => {
  await page.goto('/tools/style-proofs/pages/capture-selector.html');
  const box = await renderedBox(page, '#target');
  expect(box).toEqual({ x: 0, y: 500, width: 1280, height: 300 });
});

test('an annotated section is cropped to the section inside its box-less wrapper', async ({ page }) => {
  await page.goto('/tools/style-proofs/pages/capture-annotated.html');
  const wrapper = page.locator('[data-thallo-block="thumbsection1"]');
  // The problem: the real preview stylesheet leaves the wrapper without a box.
  expect(await wrapper.evaluate((el) => getComputedStyle(el).display)).toBe('contents');
  expect(await wrapper.boundingBox()).toBeNull();
  // The fix: the crop is the section's own area.
  const inner = await page.locator('#inner').boundingBox();
  const box = await renderedBox(page, '[data-thallo-block="thumbsection1"]');
  expect(box).toEqual({
    x: Math.floor(inner.x),
    y: Math.floor(inner.y),
    width: Math.ceil(inner.x + inner.width) - Math.floor(inner.x),
    height: Math.ceil(inner.y + inner.height) - Math.floor(inner.y),
  });
});

test('nothing rendered is refused', async ({ page }) => {
  await page.goto('/tools/style-proofs/pages/capture-annotated.html');
  await expect(renderedBox(page, '[data-thallo-block="emptywrapper"]')).rejects.toThrow(
    'no rendered box for [data-thallo-block="emptywrapper"]',
  );
  await expect(renderedBox(page, '#nothing-here')).rejects.toThrow('no rendered box for #nothing-here');
});
