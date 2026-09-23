// The Image block's exact sizes are its <img>'s width and height attributes. The promises are
// geometry, so they are proven in a browser against the theme's own CSS: one value keeps the
// picture's proportions; two make a box it fills, cropped rather than stretched; Fill is the
// column's width; and nothing is ever wider than its column.
'use strict';

const { test, expect } = require('@playwright/test');

test('exact sizes keep proportions, crop to a box, fill, and stay inside the column', async ({ page }) => {
  await page.goto('/tools/style-proofs/pages/image-size.html');
  await page.waitForFunction(() => [...document.images].every((i) => i.complete && i.naturalWidth > 0));

  const box = (id) => page.evaluate((id) => {
    const img = document.querySelector('#' + id + ' img');
    const r = img.getBoundingClientRect();
    return { w: Math.round(r.width), h: Math.round(r.height), fit: getComputedStyle(img).objectFit };
  }, id);
  const column = await page.evaluate(() => {
    const f = document.querySelector('#fill');
    const cs = getComputedStyle(f);
    return Math.round(f.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight));
  });

  expect(await box('w')).toMatchObject({ w: 300, h: 150 });             // width alone: 2:1 kept
  expect(await box('h')).toMatchObject({ w: 200, h: 100 });             // height alone: 2:1 kept
  expect(await box('wh')).toEqual({ w: 300, h: 300, fit: 'cover' });    // both: a box, cropped
  const fill = await box('fill');
  expect(fill.w).toBe(column);                                          // Fill: the column's width
  expect(fill.h).toBe(Math.round(column / 2));
  const wide = await box('wide');
  expect(wide.w).toBe(column);                                          // never wider than its column
  expect(wide.h).toBe(Math.round(column / 2));                          // …and still 2:1
  expect(await box('natural')).toMatchObject({ w: 400, h: 200 });       // unset: as before
});
