// A Rich text block's Line height as a browser draws it, published and on the canvas stage
// (scripts/build-text-style-fixtures): every value changes the spacing of the text's paragraphs and
// list items — none of them is the theme's own line height, so no choice leaves the text as it was —
// and the values rise from Tight to Loose. Headings in the text keep the theme's own heading spacing.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGES = {
  public: '/tools/runtime-browser/fixtures/text-style/public.html',
  stage: '/tools/runtime-browser/fixtures/text-style/stage.html',
};
const VALUES = ['tight', 'snug', 'normal', 'relaxed', 'loose'];

/** Computed line heights (px) of the Rich text whose class names `cls`, or the untouched one. */
const measure = (page, cls) =>
  page.evaluate((c) => {
    const all = [...document.querySelectorAll('main .thallo-block-rich_text')].filter((el) => el.querySelector('h2'));
    const el = c ? all.find((e) => e.classList.contains(c)) : all.find((e) => ![...e.classList].some((k) => k.startsWith('t-leading-')));
    const lh = (s) => parseFloat(getComputedStyle(el.querySelector(s)).lineHeight);
    return { p: lh('p'), li: lh('li'), h2: lh('h2') };
  }, cls);

for (const [where, url] of Object.entries(PAGES)) {
  test(`${where}: every Line height changes the text, and they rise from Tight to Loose`, async ({ page }) => {
    await page.goto(url);
    const untouched = await measure(page, null);
    const set = [];
    for (const value of VALUES) {
      const m = await measure(page, `t-leading-${value}`);
      expect(m.p, `${value} changes the paragraphs`).not.toBe(untouched.p);
      expect(m.li, `${value} reaches the list items`).toBe(m.p);
      expect(m.h2, 'a heading keeps its own spacing').toBe(untouched.h2);
      set.push(m.p);
    }
    expect([...set].sort((a, b) => a - b)).toEqual(set);
  });
}
