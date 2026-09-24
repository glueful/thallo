// A footer block placed in the footer region is the footer: it spans the region's width, whatever
// else the region holds, so its bar spreads the copyright left and the social links right. The
// region's inner row is a flex row, and a footer block left to size itself there shrank to its
// content — pushed to one side, its copyright squeezed onto two lines.
'use strict';

const { test, expect } = require('@playwright/test');

test('a footer block in the footer region spans the region, its copyright on one line', async ({ page }) => {
  await page.setViewportSize({ width: 1400, height: 800 });
  await page.goto('/tools/style-proofs/pages/footer-region.html');
  const m = await page.evaluate(() => {
    const inner = document.getElementById('inner');
    const cs = getComputedStyle(inner);
    const content = inner.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    const copy = document.getElementById('copy');
    return {
      footer: Math.round(document.getElementById('footer').getBoundingClientRect().width),
      content: Math.round(content),
      copyLines: Math.round(copy.getBoundingClientRect().height / parseFloat(getComputedStyle(copy).lineHeight)),
    };
  });
  expect(m.footer).toBe(m.content);
  expect(m.copyLines).toBe(1);
});
