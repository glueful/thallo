// The admin's editor writes a list item's text as a paragraph (<li><p>…</p></li>). A list in a
// rich_text still reads as one list: its items close together, not a paragraph's gap apart, and
// indented by a bullet's width rather than the browser's 40px.
'use strict';

const { test, expect } = require('@playwright/test');

test('a rich_text list is set as a list, not as a run of paragraphs', async ({ page }) => {
  await page.goto('/tools/style-proofs/pages/rich-text-list.html');
  const m = await page.evaluate(() => {
    const items = [...document.querySelectorAll('#list li')].map((li) => li.getBoundingClientRect());
    const lineHeight = parseFloat(getComputedStyle(document.querySelector('#list li p')).lineHeight);
    const text = document.querySelector('#list li p').getBoundingClientRect().left;
    const para = document.querySelector('#para').getBoundingClientRect().left;
    return { gap: items[1].top - items[0].bottom, lineHeight, indent: text - para };
  });
  expect(m.gap).toBeLessThan(m.lineHeight * 0.5);  // items a short step apart…
  expect(m.gap).toBeGreaterThan(0);                // …but not touching
  expect(m.indent).toBeLessThan(32);               // a bullet's width in, not 40px
  expect(m.indent).toBeGreaterThan(12);
});
