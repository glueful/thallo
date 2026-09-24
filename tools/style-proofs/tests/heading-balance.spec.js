// A heading that wraps shares its words between its lines, rather than filling the first line and
// leaving one or two words alone on the last. The promise is geometry, so it is measured in a
// browser against the theme's own CSS: each line's width, first as the browser would wrap it
// greedily (the case this guards against), then as the theme wraps it.
'use strict';

const { test, expect } = require('@playwright/test');

// The width of each rendered line, from the boxes of the heading's words.
const lines = (page, id, greedy) => page.evaluate(({ id, greedy }) => {
  const el = document.getElementById(id);
  el.style.textWrap = greedy ? 'wrap' : '';
  const text = el.firstChild;
  const rows = new Map();
  const range = document.createRange();
  let at = 0;
  for (const word of text.data.split(' ')) {
    range.setStart(text, at);
    range.setEnd(text, at + word.length);
    const r = range.getBoundingClientRect();
    const top = Math.round(r.top);
    const row = rows.get(top) ?? { left: r.left, right: r.right };
    rows.set(top, { left: Math.min(row.left, r.left), right: Math.max(row.right, r.right) });
    at += word.length + 1;
  }
  el.style.textWrap = '';
  return [...rows.values()].map((row) => Math.round(row.right - row.left));
}, { id, greedy });

for (const id of ['h1', 'h2']) {
  test(`a wrapped ${id} shares its words between its lines`, async ({ page }) => {
    await page.goto('/tools/style-proofs/pages/heading-balance.html');

    const greedy = await lines(page, id, true);
    expect(greedy.length).toBeGreaterThan(1);
    // The case being guarded: greedily, the last line is much shorter than the first.
    expect(greedy.at(-1)).toBeLessThan(greedy[0] * 0.6);

    const themed = await lines(page, id, false);
    expect(themed.length).toBe(greedy.length);
    expect(themed.at(-1)).toBeGreaterThan(themed[0] * 0.6);
  });
}
